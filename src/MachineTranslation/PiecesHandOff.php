<?php
/**
 * A post too long for one interactive request, handed to a background job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by translate_post() for an interactive caller ({@see TranslationService::hand_off_above()}) before any provider
 * call when content needs more pieces than it sends at once, or when the provider asked to wait or a call would not fit
 * the request; nothing was written. A literal require, as only interactive callers meet it.
 *
 * @since 1.0.8
 */
final class PiecesHandOff extends \RuntimeException {

	/**
	 * The pair has a translation.
	 *
	 * @var bool
	 */
	public readonly bool $exists;

	/**
	 * Constructor.
	 *
	 * @param int               $post_id      Source post.
	 * @param string            $target_lang  Target language slug.
	 * @param bool              $include_meta Translate the registered meta fields too.
	 * @param int               $translation  The pair's translation post (0: none).
	 * @param int               $pieces       Pieces the content needs.
	 * @param string            $reason       'parts', 'wait' (a rate-limit wait) or 'slow' (a call would not fit the request).
	 * @param int               $wait         Seconds until the next request ('wait').
	 * @param int               $latency      The request's slowest call in seconds, negative when cut; the job starts from it.
	 * @param int|null          $asked        Seconds the provider asked for ({@see DeadlineReachedException::asked()}).
	 * @param PieceAnswers|null $answers      Answers the request was paid for; the queued job starts from them.
	 */
	public function __construct( public readonly int $post_id, public readonly string $target_lang, public readonly bool $include_meta, public readonly int $translation, public readonly int $pieces, public readonly string $reason = 'parts', public readonly int $wait = 0, public readonly int $latency = 0, public readonly ?int $asked = null, public readonly ?PieceAnswers $answers = null ) {
		$this->exists = $translation > 0;
		parent::__construct( esc_html( $this->notice() ) );
	}

	/**
	 * The sentence a user sees once the post is queued.
	 *
	 * @return string
	 */
	public function notice(): string {
		return match ( $this->reason ) {
			'wait'  => $this->wait_notice(),
			'slow'  => __( 'The provider answers too slowly for this request, so this post is machine-translated in a background job. The translation appears when the job is done.', 'perflocale' ),
			default => self::queued_message( $this->pieces ),
		};
	}

	/**
	 * Why the post was not translated when it could not be queued and a stop, not its length, sent it here.
	 *
	 * @return string '' for 'parts' (the caller translates it in the request).
	 */
	public function unqueued(): string {
		return match ( $this->reason ) {
			'wait'  => DeadlineReachedException::wait_text( $this->wait, $this->asked ),
			'slow'  => __( 'The provider did not answer within the time this request has, so nothing was saved. Translate the post with the bulk action (a background job) or with WP-CLI.', 'perflocale' ),
			default => '',
		};
	}

	/**
	 * The queued notice of a rate-limit wait, worded as {@see DeadlineReachedException::wait_text()}.
	 *
	 * @return string
	 */
	private function wait_notice(): string {
		$wait  = max( 1, $this->wait );
		$asked = $this->asked;

		if ( DeadlineReachedException::asked_kept( $wait, $asked ) ) {
			$wait = $asked ?? $wait;

			/* translators: %d: seconds */
			return sprintf( _n( 'The provider asked to wait %d second, so this post is machine-translated in a background job. The translation appears when the job is done.', 'The provider asked to wait %d seconds, so this post is machine-translated in a background job. The translation appears when the job is done.', $wait, 'perflocale' ), $wait );
		}

		if ( $asked > 0 ) {
			/* translators: 1: seconds PerfLocale waits, 2: seconds the provider asked for */
			return sprintf( _n( 'Rate limit: waiting %1$d second before the next request (the provider asked for %2$d), so this post is machine-translated in a background job. The translation appears when the job is done.', 'Rate limit: waiting %1$d seconds before the next request (the provider asked for %2$d), so this post is machine-translated in a background job. The translation appears when the job is done.', $wait, 'perflocale' ), $wait, $asked );
		}

		/* translators: %d: seconds */
		return sprintf( _n( 'Rate limit: waiting %d second before the next request, so this post is machine-translated in a background job. The translation appears when the job is done.', 'Rate limit: waiting %d seconds before the next request, so this post is machine-translated in a background job. The translation appears when the job is done.', $wait, 'perflocale' ), $wait );
	}

	/**
	 * The sentence a user sees for a queued post.
	 *
	 * @param int $pieces Pieces.
	 * @return string
	 */
	public static function queued_message( int $pieces ): string {
		return sprintf(
			/* translators: %d: number of parts the post is sent in */
			_n( 'This post is sent in %d part, so it is machine-translated in a background job. The translation appears when the job is done.', 'This post is sent in %d parts, so it is machine-translated in a background job. The translation appears when the job is done.', $pieces, 'perflocale' ),
			$pieces
		);
	}

	/**
	 * Queue the pair as a bulk translation job, or join the user's own one in flight for it; '' when it cannot be (the
	 * user may not run it or edit the translation, or the dispatch failed), and the caller translates it in the request
	 * instead. An existing translation is overwritten by that job only while it stays as it is now (overwrite_args()).
	 * A new job starts from this request's answers and provider wait (seed()).
	 *
	 * @param string $trigger BulkTranslateJob trigger: 'rest', or 'auto_create' for + Create (its password policy).
	 * @return string Job id, or ''.
	 */
	public function queue( string $trigger ): string {
		$repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang = $repo instanceof \PerfLocale\Database\Repository\LanguageRepository ? $repo->find_by_slug( $this->target_lang ) : null;

		if ( null === $lang || ! is_numeric( $lang->id ?? null ) ) {
			return '';
		}

		$args = [
			'source_ids'      => [ $this->post_id ],
			'target_lang_ids' => [ (int) $lang->id ],
			'include_meta'    => $this->include_meta,
			'trigger'         => $trigger,
			'handoff'         => true,
		];

		if ( 0 !== $this->latency ) {
			$args['latency'] = max( -3600, min( 3600, $this->latency ) );
		}

		// The job's capability for these args first: without it the caller answers in the request, never joins a job.
		if ( ! current_user_can( ( new \PerfLocale\Background\Jobs\BulkTranslateJob() )->required_capability_for( $args ) ) ) {
			return '';
		}

		if ( $this->exists ) {
			$overwrite = self::overwrite_args( $this->post_id, (int) $lang->id, $this->translation );

			if ( null === $overwrite ) {
				return '';
			}

			$args['overwrite'] = $overwrite;
		}

		$twin = self::in_flight( $args );

		if ( '' !== $twin ) {
			return $twin;
		}

		$queued = \PerfLocale\Background\Dispatcher::enqueue( new \PerfLocale\Background\Jobs\BulkTranslateJob(), $args );
		$job    = is_string( $queued['job_id'] ?? null ) ? $queued['job_id'] : '';

		if ( '' !== $job && empty( $queued['duplicate'] ) ) {
			$this->seed( $job );
		}

		return $job;
	}

	/**
	 * Write the new job's first checkpoint: the answers paid for in this request and the provider's wait (not_before),
	 * in its result column, never its args. Only while the row was never claimed: else the job just sends them again.
	 *
	 * @param string $job Job id.
	 * @return void
	 */
	private function seed( string $job ): void {
		global $wpdb;

		$answers = $this->answers;
		$wait    = 'wait' === $this->reason ? max( 1, $this->wait ) : 0;
		$n       = null === $answers ? 0 : $answers->count();

		if ( ( 0 === $wait && 0 === $n ) || ! $wpdb instanceof \wpdb ) {
			return;
		}

		// BulkTranslateJob's checkpoint before its first pair.
		$point = [
			'created'            => 0,
			'skipped'            => 0,
			'skipped_permission' => 0,
			'failed'             => 0,
			'first_error'        => '',
			'kept'               => 0,
			'first_warning'      => '',
			'cursor'             => 0,
			'processed'          => 0,
		];

		if ( $wait > 0 ) {
			$point[ \PerfLocale\Background\JobState::RESUME_KEY ] = [
				'open'       => false,
				'not_before' => time() + $wait,
			];
		}

		$data = null === $answers || 0 === $n ? '' : $answers->pack();
		$with = $point + [
			'pieces'                                      => [
				'post' => $this->post_id,
				'lang' => $this->target_lang,
				'data' => $data,
				'n'    => $n,
			],
			\PerfLocale\Background\JobResume::PARTIAL_KEY => $n,
		];
		$json = wp_json_encode( $with );

		// As BulkTranslateJob::with_pieces(): room for the resume state, else the wait alone.
		if ( '' === $data || ! is_string( $json ) || strlen( $json ) + 2048 > \PerfLocale\Background\JobState::MAX_RESULT_BYTES ) {
			$json = 0 === $wait ? false : wp_json_encode( $point );
		}

		if ( ! is_string( $json ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The queued row this request created; never cached.
		$wpdb->query(
			(string) $wpdb->prepare(
				"UPDATE %i SET result = %s, version = version + 1 WHERE uuid = %s AND status = 'queued' AND attempts = 0 AND created_by = %d",
				\PerfLocale\Database\Schema::table( 'jobs' ),
				$json,
				$job,
				get_current_user_id()
			)
		);
	}

	/**
	 * Whether any user's queued or running job translates exactly this pair into a new translation (no overwrite), its
	 * worker alive: a Create then pays nothing. A yes or no only, so no job, owner or content is disclosed.
	 *
	 * @param int $post_id Source post.
	 * @param int $lang_id Target language id.
	 * @return bool|null Null when the jobs could not be read (the caller must not go on as if there were none).
	 */
	public static function pair_in_flight( int $post_id, int $lang_id ): ?bool {
		global $wpdb;

		// The prefix in_flight() matches: one source, one language.
		$pair   = [
			'source_ids'      => [ $post_id ],
			'target_lang_ids' => [ $lang_id ],
		];
		$prefix = substr( (string) wp_json_encode( $pair ), 0, -1 ) . ',';

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- In-flight rows of one type (type_status key); never cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uuid, args FROM %i WHERE type = 'bulk_translate' AND status IN ('queued', 'running') AND blog_id = %d AND args LIKE %s ORDER BY id ASC LIMIT 20",
				\PerfLocale\Database\Schema::table( 'jobs' ),
				get_current_blog_id(),
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);

		// get_results() answers an empty list for a failed query too: its error tells them apart.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}

		foreach ( $rows as $row ) {
			$stored = is_string( $row['args'] ?? null ) ? json_decode( $row['args'], true ) : null;

			if ( ! is_array( $stored ) || ! is_string( $row['uuid'] ?? null ) || isset( $stored['overwrite'] ) ) {
				continue;
			}

			// As in_flight(): a job whose worker died is failed (or requeued), not counted.
			if ( ! \PerfLocale\Background\JobState::fail_if_worker_gone( $row['uuid'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The job args that let it overwrite one translation: the pair, the translation and its state now (modified time and
	 * a hash of its texts); null when the current user may not edit it or use machine translation.
	 *
	 * @param int $post_id     Source post.
	 * @param int $lang_id     Target language id.
	 * @param int $translation Translation post.
	 * @return array{post: int, lang: int, translation: int, modified: string, sum: string}|null
	 */
	public static function overwrite_args( int $post_id, int $lang_id, int $translation ): ?array {
		if ( ! current_user_can( 'edit_post', $translation ) || ! current_user_can( 'perflocale_use_mt' ) ) {
			return null;
		}

		$state = self::state( $translation );

		if ( null === $state ) {
			return null;
		}

		return [
			'post'        => $post_id,
			'lang'        => $lang_id,
			'translation' => $translation,
		] + $state;
	}

	/**
	 * A translation's state as overwrite_args() records it, read fresh; null when it is gone.
	 *
	 * @param int $translation Translation post.
	 * @return array{modified: string, sum: string}|null
	 */
	public static function state( int $translation ): ?array {
		clean_post_cache( $translation );
		$post = get_post( $translation );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return [
			'modified' => (string) $post->post_modified_gmt,
			'sum'      => hash( 'sha256', (string) wp_json_encode( [ $post->post_title, $post->post_content, $post->post_excerpt ] ) ),
		];
	}

	/**
	 * The current user's in-flight bulk translation job of this one pair, with the same overwrite (or none) as $args and
	 * with meta when $args asks for it, whose worker is not gone: its id, or ''. Two paths (+ Create, either REST route)
	 * queue different args for the same pair.
	 *
	 * @param array<string, mixed> $args The job args queue() builds.
	 * @return string
	 */
	private static function in_flight( array $args ): string {
		global $wpdb;

		$prefix = substr( (string) wp_json_encode( array_slice( $args, 0, 2 ) ), 0, -1 ) . ',';

		if ( ! $wpdb instanceof \wpdb ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- In-flight rows of one type (type_status key); never cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uuid, args FROM %i WHERE type = 'bulk_translate' AND status IN ('queued', 'running') AND blog_id = %d AND created_by = %d AND args LIKE %s ORDER BY id ASC LIMIT 20",
				\PerfLocale\Database\Schema::table( 'jobs' ),
				get_current_blog_id(),
				get_current_user_id(),
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$stored = is_string( $row['args'] ?? null ) ? json_decode( $row['args'], true ) : null;

			if ( ! is_array( $stored ) || ! is_string( $row['uuid'] ?? null ) || ( $stored['overwrite'] ?? null ) !== ( $args['overwrite'] ?? null ) || ( ! empty( $args['include_meta'] ) && empty( $stored['include_meta'] ) ) ) {
				continue;
			}

			// As Dispatcher::enqueue() does: a twin whose worker died is failed, not joined.
			if ( ! \PerfLocale\Background\JobState::fail_if_worker_gone( $row['uuid'] ) ) {
				return $row['uuid'];
			}
		}

		return '';
	}
}
