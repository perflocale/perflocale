<?php
/**
 * Auto-translate on publish: one background job per published post.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Admin\MtCreateNotice;
use PerfLocale\Background\AbstractJob;
use PerfLocale\Background\Dispatcher;
use PerfLocale\Background\JobResume;
use PerfLocale\Background\JobState;
use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\MachineTranslation\CallDeadline;
use PerfLocale\MachineTranslation\DeadlineReachedException;
use PerfLocale\MachineTranslation\NotSavedException;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Settings;
use PerfLocale\Translation\BlockTemplateSupport;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates a published default-language post into each due language, one language per unit, in slices that resume
 * after a kill. Args: `post_id`. Runs as the publishing user; a publish without one is a system job (user 0). When a run
 * ends, the next queued one starts.
 *
 * @phpstan-type Checkpoint array{languages: array<int, string>|null, next: int, translated: int, skipped: int, failed: int, first_error: string, failures: array<int, array<string, mixed>>, recipient: int}
 */
final class AutoTranslateJob extends AbstractJob {

	/**
	 * Job type; also a string literal at boot, so reading it there would load this class.
	 */
	public const TYPE = 'auto_translate';

	/**
	 * Seconds of one web slice (BulkTranslateJob's value).
	 */
	private const SLICE_SECONDS = 20;

	/**
	 * Times a 1.0.7.3 event is tried again when its job cannot be queued.
	 */
	private const LEGACY_RETRIES = 3;

	/**
	 * Posts published in this request, queued once their save is complete: blog id => post id => true.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private static array $pending = [];

	/**
	 * Translates one language (tests); null for TranslationService::translate_post().
	 *
	 * @var callable|null
	 */
	private $translate;

	/**
	 * A job; $translate replaces the provider call (tests).
	 *
	 * @param callable|null $translate `function( int $post_id, string $slug ): array`.
	 */
	public function __construct( ?callable $translate = null ) {
		$this->translate = $translate;
	}

	/** {@inheritDoc} */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every user and none (a scheduled publish, WP-CLI): the publish itself was the permission.
	 */
	public function get_required_capability(): string {
		return 'exist';
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		return 0;
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function resumes_after_kill(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function starts_next_when_done(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function runs_without_user(): bool {
		return true;
	}

	/**
	 * Note a newly published post (transition_post_status, via Bootstrap::auto_translate_on_publish()); it is queued
	 * once its save is complete, as save_post sets its language after this transition.
	 *
	 * @param \WP_Post $post Post that moved to publish from another status.
	 * @return void
	 */
	public static function on_publish( \WP_Post $post ): void {
		// A translation published while PerfLocale creates it is no source.
		if ( null !== PostTranslationManager::$creating_translation_lang_slug ) {
			return;
		}

		if ( [] === self::$pending ) {
			add_action( 'wp_after_insert_post', [ self::class, 'after_insert' ], 110, 1 );
			add_action( 'shutdown', [ self::class, 'queue_pending' ], 1 );
		}

		self::$pending[ get_current_blog_id() ][ (int) $post->ID ] = true;
	}

	/**
	 * wp_after_insert_post: queue a post noted at its publish, its language and meta saved.
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public static function after_insert( $post_id ): void {
		$post_id = (int) $post_id;
		$blog    = get_current_blog_id();

		if ( isset( self::$pending[ $blog ][ $post_id ] ) ) {
			unset( self::$pending[ $blog ][ $post_id ] );
			self::queue_saved( $post_id );
		}
	}

	/**
	 * Shutdown: queue the noted posts whose save fired no wp_after_insert_post (a status change without a save).
	 *
	 * @return void
	 */
	public static function queue_pending(): void {
		foreach ( self::$pending as $blog => $posts ) {
			$switch = is_multisite() && $blog !== get_current_blog_id();

			if ( $switch ) {
				switch_to_blog( $blog );
			}

			try {
				foreach ( array_keys( $posts ) as $post_id ) {
					self::queue_saved( $post_id );
				}
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}

		self::$pending = [];
	}

	/**
	 * Queue a noted post that is still published; a failure is logged, never thrown into the save.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	private static function queue_saved( int $post_id ): void {
		try {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				self::queue_published( $post );
			}
		} catch ( \Throwable $e ) {
			self::log_not_queued( $post_id, $e );
		}
	}

	/**
	 * Queue a published post when it is due: as the publishing user, else as a system job.
	 *
	 * @param \WP_Post $post Published post.
	 * @return void
	 */
	private static function queue_published( \WP_Post $post ): void {
		// Template block markup is never machine-translated: it risks the delimiters and every Site Editor save pays.
		if ( BlockTemplateSupport::is_template_type( $post->post_type ) || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$services = self::services();

		if ( null === $services ) {
			return;
		}

		[ $settings, $cache, $langs ] = $services;

		if ( ! in_array( $post->post_type, $settings->get_translatable_post_types(), true ) ) {
			return;
		}

		$default = $langs->get_default();

		if ( ! $default ) {
			return;
		}

		$post_lang = ( new PostTranslationManager( $cache, $settings ) )->detect_post_language( $post->ID );

		if ( $post_lang && self::slug_of( $post_lang ) !== self::slug_of( $default ) ) {
			return;
		}

		$error = self::queue( (int) $post->ID );

		if ( null !== $error ) {
			self::not_queued( $post, $error );
		}
	}

	/**
	 * Queue a post whose perflocale_auto_translate_post event an earlier version scheduled.
	 *
	 * @param int $post_id Post.
	 * @param int $attempt Handoffs tried before.
	 * @return void
	 */
	public static function from_legacy_event( int $post_id, int $attempt = 0 ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		try {
			$error = self::queue( $post_id );
		} catch ( \Throwable $e ) {
			self::log_not_queued( $post_id, $e );
			$error = $e->getMessage();
		}

		if ( null === $error ) {
			return;
		}

		// The event is consumed: the handoff is tried again later, a few times, then its user is told.
		if ( $attempt < self::LEGACY_RETRIES ) {
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, \PerfLocale\Bootstrap::AUTO_TRANSLATE_CRON, [ $post_id, $attempt + 1 ] );
			return;
		}

		self::not_queued( $post, $error );
	}

	/**
	 * A post that could not be queued: one log line, as the publish must go on.
	 *
	 * @param int        $post_id Post.
	 * @param \Throwable $e       Failure.
	 * @return void
	 */
	private static function log_not_queued( int $post_id, \Throwable $e ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The publish goes on; nothing else reports it.
		error_log( sprintf( '[PerfLocale] Post %d was not queued for auto-translation: %s', $post_id, \PerfLocale\Util\PathRedactor::redact( $e->getMessage() ) ) );
	}

	/**
	 * Dispatch the post's job as the current user (0: a system job).
	 *
	 * @param int $post_id Post.
	 * @return string|null Null when queued, else the dispatch's error.
	 */
	private static function queue( int $post_id ): ?string {
		$queued = Dispatcher::enqueue( new self(), [ 'post_id' => $post_id ] );

		if ( is_string( $queued['job_id'] ?? null ) && '' !== $queued['job_id'] ) {
			return null;
		}

		return is_string( $queued['error'] ?? null ) ? $queued['error'] : '';
	}

	/**
	 * Show on the post that its job could not be queued.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $error The dispatch's error.
	 * @return void
	 */
	private static function not_queued( \WP_Post $post, string $error ): void {
		MtCreateNotice::remember_publish(
			self::recipient( get_current_user_id(), $post, true ),
			(int) $post->ID,
			[
				[
					'reason'   => $error,
					'own'      => false,
					'language' => '',
				],
			]
		);
	}

	/**
	 * One slice: the languages from the checkpoint on, each re-checked just before it is sent.
	 *
	 * @param array<string, mixed> $args     `post_id`; `__perflocale_slice` from the worker.
	 * @param callable             $progress Progress reporter.
	 * @return array<string, mixed> The checkpoint: languages, next, translated, skipped, failed, first_error, failures, recipient.
	 */
	public function execute( array $args, callable $progress ): array {
		$post_id = is_numeric( $args['post_id'] ?? null ) ? (int) $args['post_id'] : 0;
		$slice   = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : null;
		$state   = self::state( is_array( $slice['resume'] ?? null ) ? $slice['resume'] : [] );
		$source  = $post_id > 0 ? get_post( $post_id ) : null;

		// The source may have been deleted since the publish.
		if ( ! $source instanceof \WP_Post ) {
			return $state;
		}

		// A password-protected post is sent only when the perflocale/mt/send_password_protected filter allows it.
		if ( ! TranslationService::may_send_post( $source, 'auto_publish' ) ) {
			$state['failures'] = [
				[
					'reason'   => __( 'The post is password-protected, and machine translation does not send password-protected posts when they are published.', 'perflocale' ),
					'own'      => false,
					'language' => '',
				],
			];

			return self::finish( $source, $state );
		}

		$services = self::services();

		if ( null === $services ) {
			return self::stop( $source, $state );
		}

		[ $settings, $cache, $langs ] = $services;
		$default                      = $langs->get_default();

		if ( 'publish' !== $source->post_status || ! $default || ! $settings->mt_enabled() ) {
			return self::stop( $source, $state );
		}

		// The job can run before an importer assigns the language: translate only from a default-language source.
		$manager   = new PostTranslationManager( $cache, $settings );
		$post_lang = $manager->detect_post_language( $post_id );

		if ( $post_lang && self::slug_of( $post_lang ) !== self::slug_of( $default ) ) {
			return self::stop( $source, $state );
		}

		$active = [];

		foreach ( $langs->get_active() as $lang ) {
			if ( self::slug_of( $lang ) !== self::slug_of( $default ) ) {
				$active[ self::slug_of( $lang ) ] = $lang;
			}
		}

		// Fixed at the first slice: an empty mt_auto_translate_languages means every active language.
		if ( null === $state['languages'] ) {
			$scope              = (array) $settings->get( 'mt_auto_translate_languages', [] );
			$state['languages'] = array_values( array_filter( array_keys( $active ), static fn( $slug ): bool => [] === $scope || in_array( $slug, $scope, true ) ) );

			// Who is told about the run, kept when another user retries it.
			$state['recipient'] = self::recipient( get_current_user_id(), $source, true );
		}

		$languages = $state['languages'];
		$total     = count( $languages );
		$web       = null !== $slice && 'cli' !== PHP_SAPI;
		$seconds   = 0.0;
		$deadline  = null;

		if ( $web ) {
			[ $seconds, $deadline ] = CallDeadline::for_slice( self::SLICE_SECONDS );
		}

		$began        = microtime( true );
		$first        = $state['next'];
		$stopped      = false;
		$unit_stop    = false;
		$continue_at  = 0;
		$translations = $manager->get_translations( $post_id );
		$service      = null;
		$previous     = null !== $deadline ? CallDeadline::set( $deadline ) : null;

		try {
			for ( $i = $first; $i < $total; $i++ ) {
				// A slice always runs its first unit.
				if ( $web && $i > $first && microtime( true ) - $began >= $seconds ) {
					$stopped = true;
					break;
				}

				$slug          = $languages[ $i ];
				$state['next'] = $i;

				if ( null !== $slice ) {
					$progress(
						$i,
						$total,
						[
							'checkpoint' => $state,
							/* translators: 1: post id, 2: target language slug. Names the item a background job was on. */
							'unit'       => sprintf( __( 'post %1$d into %2$s', 'perflocale' ), $post_id, $slug ),
						]
					);
				}

				$lang        = $active[ $slug ] ?? null;
				$existing_id = (int) ( $translations[ $slug ] ?? 0 );

				// A language deactivated since the first slice, or the post's own slot.
				if ( null === $lang || $existing_id === $post_id ) {
					++$state['skipped'];
					$state['next'] = $i + 1;
					continue;
				}

				// Never overwrite a human-touched translation: fill only a missing one or an empty one (auto stubs are
				// recorded as Manual, so source_type cannot tell). A pending mark proves nothing: a person may have saved since.
				$should = true;

				if ( $existing_id > 0 ) {
					$existing = get_post( $existing_id );
					$should   = ! $existing instanceof \WP_Post || '' === (string) $existing->post_content;
				}

				/**
				 * Whether the auto-translate flow may (re)translate this language for this post.
				 *
				 * @hook perflocale/mt/should_auto_translate
				 * @param bool   $should      Computed decision (skip non-empty translations).
				 * @param int    $post_id     Source post ID.
				 * @param string $lang_slug   Target language slug.
				 * @param int    $existing_id Existing translation post ID (0 = none).
				 */
				if ( ! (bool) apply_filters( 'perflocale/mt/should_auto_translate', $should, $post_id, $slug, $existing_id ) ) {
					++$state['skipped'];
					$state['next'] = $i + 1;
					continue;
				}

				// The source can be unpublished mid-run: read the status from the table, not the cache.
				$db = $GLOBALS['wpdb'] ?? null;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One primary-key read per language.
				if ( ! $db instanceof \wpdb || 'publish' !== $db->get_var( $db->prepare( 'SELECT post_status FROM %i WHERE ID = %d', $db->posts, $post_id ) ) ) {
					$state['next'] = $total;
					break;
				}

				try {
					$service ??= new TranslationService( $settings, $cache );
					$done      = null !== $this->translate ? ( $this->translate )( $post_id, $slug ) : $service->translate_post( $post_id, $slug );

					++$state['translated'];
					$state['failures'] = array_merge( $state['failures'], MtCreateNotice::publish_warnings( is_array( $done ) ? JobResume::keyed( $done ) : [], $lang ) );
				} catch ( DeadlineReachedException $e ) {
					if ( null === $slice ) {
						throw $e;
					}

					// A provider's own wait (Retry-After) is not counted; other stops count with the language's kills.
					$stops = $e->paced() ? 0 : 1 + self::stops_at( $slice, $i );
					$limit = JobResume::max_stops();

					if ( $stops < $limit ) {
						$continue_at = $e->retry_at();
						$unit_stop   = $stops > 0;
						$stopped     = true;
						break;
					}

					self::failed(
						$state,
						new \RuntimeException(
							sprintf(
								/* translators: %d: number of background runs in a row whose time limit the provider's answer did not fit in. */
								_n( 'The translation provider did not answer in time in %d run in a row, so this language was not translated.', 'The translation provider did not answer in time in %d runs in a row, so this language was not translated.', $limit, 'perflocale' ),
								$limit
							)
						),
						$lang
					);
				} catch ( \Throwable $e ) {
					self::failed( $state, $e, $lang );
				}

				$state['next'] = $i + 1;
			}
		} finally {
			if ( null !== $deadline ) {
				CallDeadline::set( $previous );
			}
		}

		if ( $stopped ) {
			$state['__perflocale_continue'] = true;

			if ( $continue_at > 0 ) {
				$state['__perflocale_continue_at'] = $continue_at;
			}

			// The worker counts the stop at this language (its position), with the kills there.
			if ( $unit_stop ) {
				$state['__perflocale_unit_stop'] = true;
				$state['processed']              = $state['next'];
			}

			return $state;
		}

		$state['next'] = $total;

		return self::finish( $source, $state );
	}

	/**
	 * Stops the worker counted at language $i before this slice: only at the slice's first language.
	 *
	 * @param array<mixed> $slice The slice args.
	 * @param int          $i     Language position.
	 * @return int
	 */
	private static function stops_at( array $slice, int $i ): int {
		$stops = is_array( $slice['stops'] ?? null ) ? $slice['stops'] : [];

		return ( $stops['at'] ?? null ) === $i && is_int( $stops['count'] ?? null ) ? $stops['count'] : 0;
	}

	/**
	 * Count a language that failed; benign cases (the post deleted mid-run, a stub reused) are neither logged nor shown.
	 *
	 * @param Checkpoint $state Checkpoint.
	 * @param \Throwable $e     Failure.
	 * @param object     $lang  Language.
	 * @param-out Checkpoint $state
	 * @return void
	 */
	private static function failed( array &$state, \Throwable $e, object $lang ): void {
		$msg = $e->getMessage();

		if ( str_contains( $msg, 'Source post not found' ) || str_contains( $msg, 'Invalid post ID' ) || str_contains( $msg, 'Невалидно ID' ) ) {
			++$state['skipped'];
			return;
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-log diagnostic.
			error_log( 'PerfLocale auto-translate failed for ' . self::slug_of( $lang ) . ': ' . $msg );
		}

		++$state['failed'];
		$state['first_error'] = '' === $state['first_error'] ? self::cut( $msg ) : $state['first_error'];
		$state['failures'][]  = [
			'reason'   => self::cut( $msg ),
			'own'      => $e instanceof NotSavedException,
			'language' => self::name_of( $lang ),
		];
	}

	/**
	 * End before any language: as finish() once the run has started, else without touching the post's notice.
	 *
	 * @param \WP_Post   $source The published post.
	 * @param Checkpoint $state  Checkpoint.
	 * @return Checkpoint
	 */
	private static function stop( \WP_Post $source, array $state ): array {
		return null === $state['languages'] ? $state : self::finish( $source, $state );
	}

	/**
	 * Record what the run could not save for its user, and return the final checkpoint.
	 *
	 * @param \WP_Post   $source The published post.
	 * @param Checkpoint $state  Checkpoint.
	 * @return Checkpoint
	 */
	private static function finish( \WP_Post $source, array $state ): array {
		$user = self::recipient( get_current_user_id(), $source, false );

		MtCreateNotice::remember_publish( $state['recipient'] > 0 ? $state['recipient'] : $user, (int) $source->ID, $state['failures'] );

		return $state;
	}

	/**
	 * Who is told about a run: its user; for a system job the publisher 1.0.7.3 noted for its event, else the author.
	 *
	 * @param int      $user Job user (0: system job).
	 * @param \WP_Post $post The published post.
	 * @param bool     $keep Leave the note: the run may still be retried.
	 * @return int
	 */
	private static function recipient( int $user, \WP_Post $post, bool $keep ): int {
		if ( $user > 0 ) {
			return $user;
		}

		$noted = MtCreateNotice::take_publisher( (int) $post->ID, $keep );

		// A noted publisher who has left the site is no recipient.
		return $noted > 0 && user_can( $noted, 'read' ) ? $noted : (int) $post->post_author;
	}

	/**
	 * A checkpoint read from a stored result; anything else starts from the first language.
	 *
	 * @param array<mixed> $resume Stored result.
	 * @return Checkpoint
	 */
	private static function state( array $resume ): array {
		$count     = static fn( $v ): int => is_int( $v ) ? max( 0, $v ) : 0;
		$languages = is_array( $resume['languages'] ?? null ) ? array_values( array_filter( $resume['languages'], 'is_string' ) ) : null;
		$failures  = [];

		foreach ( is_array( $resume['failures'] ?? null ) ? $resume['failures'] : [] as $failure ) {
			if ( is_array( $failure ) ) {
				$failures[] = JobResume::keyed( $failure );
			}
		}
		$next      = $count( $resume['next'] ?? 0 );

		if ( null === $languages || $next > count( $languages ) ) {
			$languages = null;
			$next      = 0;
		}

		return [
			'languages'   => $languages,
			'next'        => $next,
			'translated'  => $count( $resume['translated'] ?? 0 ),
			'skipped'     => $count( $resume['skipped'] ?? 0 ),
			'failed'      => $count( $resume['failed'] ?? 0 ),
			'first_error' => is_string( $resume['first_error'] ?? null ) ? $resume['first_error'] : '',
			'failures'    => $failures,
			'recipient'   => $count( $resume['recipient'] ?? 0 ),
		];
	}

	/**
	 * End of a run that failed for good ({@see JobState} run_end): its user is told which languages were not done.
	 *
	 * @param array<string, mixed> $row Job row.
	 * @return void
	 */
	public static function run_ended( array $row ): void {
		if ( 'failed' !== ( $row['status'] ?? null ) ) {
			return;
		}

		$args = is_array( $row['args'] ?? null ) ? $row['args'] : [];
		$post = is_numeric( $args['post_id'] ?? null ) ? get_post( (int) $args['post_id'] ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$result = is_array( $row['result'] ?? null ) ? $row['result'] : [];
		unset( $result[ JobState::RESUME_KEY ] );

		$state  = self::state( $result );
		$reason = is_string( $row['error'] ?? null ) && '' !== $row['error'] ? $row['error'] : __( 'The worker stopped before finishing.', 'perflocale' );
		$left   = null === $state['languages'] ? [ '' ] : array_slice( $state['languages'], $state['next'] );
		$langs  = self::services()[2] ?? null;

		foreach ( $left as $slug ) {
			$lang                = '' !== $slug && null !== $langs ? $langs->find_by_slug( $slug ) : null;
			$state['failures'][] = [
				'reason'   => self::cut( $reason ),
				'own'      => false,
				'language' => null !== $lang ? self::name_of( $lang ) : $slug,
			];
		}

		$user = is_int( $row['created_by'] ?? null ) ? $row['created_by'] : 0;

		MtCreateNotice::remember_publish( $state['recipient'] > 0 ? $state['recipient'] : self::recipient( $user, $post, true ), (int) $post->ID, $state['failures'] );
	}

	/**
	 * The services a run needs; null before they are registered.
	 *
	 * @return array{0: Settings, 1: CacheManager, 2: LanguageRepository}|null
	 */
	private static function services(): ?array {
		$plugin   = Plugin::get_instance();
		$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;
		$cache    = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;
		$langs    = $plugin->has( 'lang_repo' ) ? $plugin->get( 'lang_repo' ) : null;

		if ( ! $settings instanceof Settings || ! $cache instanceof CacheManager || ! $langs instanceof LanguageRepository ) {
			return null;
		}

		return [ $settings, $cache, $langs ];
	}

	/**
	 * A language's name, else its slug.
	 *
	 * @param object $lang Language.
	 * @return string
	 */
	private static function name_of( object $lang ): string {
		$vars = get_object_vars( $lang );
		$name = $vars['name'] ?? '';

		return is_string( $name ) && '' !== $name ? $name : self::slug_of( $lang );
	}

	/**
	 * A language's slug.
	 *
	 * @param object $lang Language.
	 * @return string
	 */
	private static function slug_of( object $lang ): string {
		$slug = get_object_vars( $lang )['slug'] ?? '';

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * A reason kept in the checkpoint, at most 500 characters (MtCreateNotice keeps no more).
	 *
	 * @param string $text Reason.
	 * @return string
	 */
	private static function cut( string $text ): string {
		return mb_substr( $text, 0, 500 );
	}
}
