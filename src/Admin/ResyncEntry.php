<?php
/**
 * Admin: the Content ReSync entry points.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Background\Dispatcher;
use PerfLocale\Background\Jobs\BulkStringTranslateJob;
use PerfLocale\Background\Jobs\ContentResyncJob;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Helper;
use PerfLocale\MachineTranslation\CostEstimator;
use PerfLocale\Resync\Estimator;
use PerfLocale\Resync\GroupLinks;
use PerfLocale\Resync\RunRequest;
use PerfLocale\Resync\Scope;
use PerfLocale\Resync\Store;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The handlers behind every Content ReSync button: per-post "Resync from source", the Translations page bulk
 * action and dialog, the Strings page run, and the estimate step's Start and Cancel.
 * Order in every handler: method, nonce, capability, objects, then act; a run acts as the person who pressed it.
 *
 * @phpstan-import-type Args from ContentResyncJob
 */
final class ResyncEntry {

	/** The redirect flag of a finished run (or a message). */
	public const DONE_FLAG = 'perflocale_resync_done';

	/** The redirect flag of an estimate step (its value is the token). */
	public const CONFIRM_FLAG = 'perflocale_resync_confirm';

	/** The nonce action of the per-post link. */
	public const NONCE = ResyncLinks::NONCE;

	/** The nonce action of the Strings page dialog. */
	public const STRINGS_NONCE = 'perflocale_strings_update';

	/**
	 * The admin-post handler `perflocale_resync`: "Resync from source" for one post.
	 *
	 * @return void
	 */
	public static function handle_resync(): void {
		self::require_post( self::NONCE, current_user_can( 'perflocale_translate' ), __( 'Resync from source', 'perflocale' ), [ self::class, 'resync_detail' ] );

		if ( ! current_user_can( 'perflocale_translate' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified above by check_admin_referer().
		$post_id = max( 0, Scalar::int( wp_unslash( $_GET['post'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A non-negative integer through Scalar::int(); never output or stored as text.
		$lang    = sanitize_key( Scalar::str( wp_unslash( $_GET['lang'] ?? 'all' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() is the sanitiser; Scalar::str() only types the value for it.
		$return  = sanitize_key( Scalar::str( wp_unslash( $_GET['return'] ?? 'referer' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() is the sanitiser; the value is then matched against a fixed list.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$return = in_array( $return, [ 'edit', 'translations', 'referer' ], true ) ? $return : 'referer';
		$back   = self::back_url( $return, $post_id );

		if ( ! Store::available() ) {
			self::done( $back, self::message( RunRequest::KIND_POST, Store::unavailable_text() ) );
		}

		$resolved = self::resolve_post( $post_id, '' === $lang ? 'all' : $lang );

		if ( '' !== $resolved['error'] ) {
			wp_die( esc_html( $resolved['error'] ), '', [ 'response' => 403 ] );
		}

		if ( [] === $resolved['language_ids'] ) {
			self::done(
				$back,
				self::message(
					RunRequest::KIND_POST,
					$resolved['skipped'] > 0
						? __( 'Nothing was resynced: you may not edit any translation of this post.', 'perflocale' )
						: __( 'There is no translation to resync yet. Create the translation first.', 'perflocale' )
				)
			);
		}

		$args = ContentResyncJob::normalize_args(
			[
				'mode'         => ContentResyncJob::MODE_SOURCES,
				'source_ids'   => [ $resolved['source_id'] ],
				'language_ids' => $resolved['language_ids'],
				'fill'         => '',
				'machine'      => self::machine(),
				'trigger'      => 'resync',
				'own_post'     => $post_id,
			]
		);

		$estimate = Estimator::estimate( $args );

		if ( '' !== Scalar::str( $estimate['first_error'] ?? '' ) ) {
			self::done( $back, self::message( RunRequest::KIND_POST, Scalar::str( $estimate['first_error'] ) ) );
		}

		$retry = ResyncLinks::resync_url( $post_id, '' === $lang ? 'all' : $lang, $return );

		if ( '' === self::machine_refusal( $args ) && RunRequest::starts_at_once( RunRequest::KIND_POST, $estimate ) ) {
			$args['mt_budget'] = 0;

			self::done( $back, self::dispatch_content( RunRequest::KIND_POST, ContentResyncJob::normalize_args( $args ), $retry ) );
		}

		$token = RunRequest::create( RunRequest::KIND_POST, $args, $estimate, $back, $retry );

		if ( '' === $token ) {
			self::done( $back, self::message( RunRequest::KIND_POST, __( 'The estimate could not be stored. Try again.', 'perflocale' ) ) );
		}

		self::confirm( $back, $token );
	}

	/**
	 * The admin-post handler `perflocale_resync_start`: run what an estimate step showed.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		$token = RunRequest::valid_token( Scalar::str( wp_unslash( $_GET['token'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only a 32-character hex token passes valid_token(); it scopes the nonce action verified next, and nothing is acted upon before.
		self::require_post( 'perflocale_resync_start_' . $token, RunRequest::any_capability(), __( 'Start', 'perflocale' ) );

		if ( ! RunRequest::any_capability() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$request = '' === $token ? null : RunRequest::get( $token );

		if ( null === $request ) {
			self::done( self::back_url( 'referer', 0 ), self::message( RunRequest::KIND_POST, __( 'This estimate has expired or the run was already started. Press the button again.', 'perflocale' ) ) );
		}

		$kind = $request['kind'];

		if ( ! current_user_can( RunRequest::capability( $kind ) ) || ( RunRequest::KIND_STRINGS === $kind && ! current_user_can( 'perflocale_use_mt' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$back = '' !== $request['return'] ? $request['return'] : self::back_url( 'referer', 0 );

		// A second click on Start finds the lock (or, after it, no request).
		if ( ! Lock::acquire( RunRequest::lock_name( $token ), RunRequest::START_LOCK_SECONDS ) ) {
			self::done( $back, self::message( $kind, __( 'This run was already started.', 'perflocale' ) ) );
		}

		$request = RunRequest::get( $token );

		if ( null === $request ) {
			Lock::release( RunRequest::lock_name( $token ) );
			self::done( $back, self::message( $kind, __( 'This run was already started.', 'perflocale' ) ) );
		}

		RunRequest::forget( $token );

		$summary = self::start_run( $kind, $request );

		// The request is gone: a later Start finds none, so the lock can go.
		Lock::release( RunRequest::lock_name( $token ) );

		self::done( $back, $summary );
	}

	/**
	 * What Start runs for a request it took (already deleted): a refusal, the Strings dispatch, or the
	 * revalidated content run.
	 *
	 * @param string               $kind    The kind.
	 * @param array<string, mixed> $request The request, as RunRequest::get() returns it.
	 * @phpstan-param array{user_id: int, blog_id: int, kind: string, args: array<string, mixed>, estimate: array<string, mixed>, return: string, retry: string, created: int} $request
	 * @return array<string, mixed> The result summary.
	 */
	private static function start_run( string $kind, array $request ): array {
		$refusal = RunRequest::KIND_STRINGS === $kind ? '' : self::machine_refusal( $request['args'] );
		$refusal = '' !== $refusal ? $refusal : self::limit_refusal( $request['estimate'] );

		if ( '' !== $refusal ) {
			return self::message( $kind, $refusal );
		}

		if ( RunRequest::KIND_STRINGS === $kind ) {
			return self::dispatch_strings( $request['args'] );
		}

		$args = self::revalidate( $kind, $request['args'] );

		if ( null === $args ) {
			return self::message( $kind, __( 'Nothing is left to run: the languages or content types of this estimate are no longer available.', 'perflocale' ) );
		}

		// An inline machine-mode run is one request to the hourly limiter.
		if ( $args['machine'] && Scalar::int( $request['estimate']['chars'] ?? 0 ) > 0
			&& ! ( new ContentResyncJob() )->should_run_async( $args, \PerfLocale\Plugin::get_instance()->settings() )
		) {
			$limited = \PerfLocale\Translation\MtRateLimiter::admit( get_current_user_id() );

			if ( $limited instanceof \WP_Error ) {
				return self::message( $kind, $limited->get_error_message() );
			}
		}

		return self::dispatch_content( $kind, $args, $request['retry'] );
	}

	/**
	 * The admin-post handler `perflocale_resync_cancel`: drop a run request.
	 *
	 * @return void
	 */
	public static function handle_cancel(): void {
		$token = RunRequest::valid_token( Scalar::str( wp_unslash( $_GET['token'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only a 32-character hex token passes valid_token(); it scopes the nonce action verified next, and nothing is acted upon before.
		self::require_post( 'perflocale_resync_cancel_' . $token, RunRequest::any_capability(), __( 'Cancel', 'perflocale' ) );

		if ( ! RunRequest::any_capability() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$request = '' === $token ? null : RunRequest::get( $token );
		$back    = null !== $request && '' !== $request['return'] ? $request['return'] : self::back_url( 'referer', 0 );

		if ( null !== $request && current_user_can( RunRequest::capability( $request['kind'] ) ) ) {
			RunRequest::forget( $token );
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The Translations page bulk action "Update translations", after process_translations_bulk() verified the
	 * nonce, capability and completeness marker: the estimate step, always.
	 *
	 * @param array<int, int>      $ids      The ticked source ids.
	 * @param array<int, int>      $lang_ids The resolved target languages.
	 * @param array<string, mixed> $preserve The list filters to return to.
	 * @return void
	 */
	public static function translations_bulk( array $ids, array $lang_ids, array $preserve ): void {
		$back = add_query_arg( array_merge( $preserve, [ 'page' => 'perflocale-translations' ] ), admin_url( 'admin.php' ) );

		if ( ! Store::available() ) {
			self::done( $back, self::message( RunRequest::KIND_BULK, Store::unavailable_text() ) );
		}

		$args = ContentResyncJob::normalize_args(
			[
				'mode'          => ContentResyncJob::MODE_SOURCES,
				'source_ids'    => $ids,
				'language_ids'  => self::target_languages( $lang_ids ),
				'fill'          => self::fill_choice( Scalar::str( wp_unslash( $_POST['resync_fill'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by process_translations_bulk(); the value is compared against a fixed list in fill_choice().
				'only_outdated' => false,
				'machine'       => self::machine(),
				'trigger'       => 'resync_bulk',
			]
		);

		self::estimate_step( RunRequest::KIND_BULK, $args, $back );
	}

	/**
	 * The Translations page dialog "Update translations", after process_translations_bulk() verified the request:
	 * types within scope, active non-default languages, then the estimate step, always.
	 *
	 * @return void
	 */
	public static function translations_dialog(): void {
		$back = add_query_arg(
			[
				'page'              => 'perflocale-translations',
				'perflocale_update' => 'open',
			],
			admin_url( 'admin.php' )
		);

		if ( ! Store::available() ) {
			self::done( $back, self::message( RunRequest::KIND_DIALOG, Store::unavailable_text() ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by process_translations_bulk().
		$langs    = isset( $_POST['resync_lang_ids'] ) && is_array( $_POST['resync_lang_ids'] ) ? array_map( Scalar::int( ... ), wp_unslash( $_POST['resync_lang_ids'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Integers through Scalar::int(), intersected with the active languages.
		$types    = isset( $_POST['resync_post_types'] ) && is_array( $_POST['resync_post_types'] ) ? array_map( static fn ( mixed $type ): string => sanitize_key( Scalar::str( $type ) ), wp_unslash( $_POST['resync_post_types'] ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry passes sanitize_key() and is intersected with the scope list.
		$outdated = ! empty( $_POST['resync_outdated'] );
		$missing  = ! empty( $_POST['resync_missing'] );
		$record   = ! empty( $_POST['resync_record'] );
		$fill     = $missing ? self::fill_choice( Scalar::str( wp_unslash( $_POST['resync_fill'] ?? '' ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against a fixed list in fill_choice().
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$langs = self::target_languages( $langs );
		$types = array_values( array_intersect( array_unique( $types ), ResyncLinks::scope_types() ) );

		if ( '' === $fill ) {
			$missing = false;
		}

		if ( [] === $langs || [] === $types || ( ! $outdated && ! $missing && ! $record ) ) {
			self::done( $back, self::message( RunRequest::KIND_DIALOG, __( 'Nothing to estimate: pick at least one language, one content type and what to update.', 'perflocale' ) ) );
		}

		$args = ContentResyncJob::normalize_args(
			[
				'mode'         => ContentResyncJob::MODE_QUERY,
				'language_ids' => $langs,
				'query'        => [
					'post_types'     => $types,
					'outdated'       => $outdated,
					'missing'        => $missing,
					'record_in_sync' => $record,
				],
				'fill'         => $fill,
				'machine'      => self::machine(),
				'trigger'      => 'resync_bulk',
			]
		);

		self::estimate_step( RunRequest::KIND_DIALOG, $args, $back );
	}

	/**
	 * The Strings page dialog "Translate outdated and missing strings": the estimate step, always.
	 *
	 * @return void
	 */
	public static function process_strings(): void {
		if ( ! wp_verify_nonce( sanitize_key( Scalar::str( wp_unslash( $_POST['_wpnonce'] ?? '' ) ) ), self::STRINGS_NONCE ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() is the sanitiser.
			wp_die( esc_html__( 'Security check failed.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'perflocale_manage_translations' ) || ! current_user_can( 'perflocale_use_mt' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		$back = admin_url( 'admin.php?page=perflocale-strings' );

		// The marker is the form's last field: PHP drops input past max_input_vars in order.
		$max_vars = (int) ini_get( 'max_input_vars' );

		if ( ! isset( $_POST['perflocale_form_end'] ) && $max_vars > 0 && count( $_POST, COUNT_RECURSIVE ) >= $max_vars ) {
			self::done( $back, self::message( RunRequest::KIND_STRINGS, __( 'Nothing was estimated: the form did not arrive complete. Try again with fewer languages.', 'perflocale' ) ) );
		}

		if ( ! self::strings_available() ) {
			self::done( $back, self::message( RunRequest::KIND_STRINGS, __( 'Machine translation of strings is not available: switch on machine translation and bulk string translation in the settings.', 'perflocale' ) ) );
		}

		$langs    = isset( $_POST['strings_lang_ids'] ) && is_array( $_POST['strings_lang_ids'] ) ? self::target_languages( array_map( Scalar::int( ... ), wp_unslash( $_POST['strings_lang_ids'] ) ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Integers through Scalar::int(), intersected with the active languages.
		$missing  = ! empty( $_POST['strings_missing'] );
		$outdated = ! empty( $_POST['strings_outdated'] );

		if ( [] === $langs || ( ! $missing && ! $outdated ) ) {
			self::done( $back, self::message( RunRequest::KIND_STRINGS, __( 'Nothing to estimate: pick at least one language and missing or outdated strings.', 'perflocale' ) ) );
		}

		$args = [
			'language_ids'    => $langs,
			'missing'         => $missing,
			'outdated'        => $outdated,
			'exclude_domains' => \PerfLocale\Strings\Domains::restricted_for_current_user(),
		];

		$token = RunRequest::create( RunRequest::KIND_STRINGS, $args, self::strings_estimate( $args ), $back );

		if ( '' === $token ) {
			self::done( $back, self::message( RunRequest::KIND_STRINGS, __( 'The estimate could not be stored. Try again.', 'perflocale' ) ) );
		}

		self::confirm( $back, $token );
	}

	/**
	 * The estimate of a Strings page run, the outdated pairs read and counted a page at a time. A string whose
	 * proposal a person rejected or undid is counted as `outdated_rejected` and not sent.
	 *
	 * @param array<string, mixed> $args language_ids, missing, outdated, exclude_domains.
	 * @return array<string, mixed>
	 */
	public static function strings_estimate( array $args ): array {
		$langs   = array_values( array_map( Scalar::int( ... ), (array) ( $args['language_ids'] ?? [] ) ) );
		$exclude = Scalar::strings( $args['exclude_domains'] ?? [] );
		$job     = new BulkStringTranslateJob();
		$ce      = new CostEstimator( \PerfLocale\Plugin::get_instance()->settings() );
		$m_chars = 0;
		$m_items = 0;
		$o_chars = 0;
		$o_items = 0;
		$o_left  = 0;

		if ( ! empty( $args['missing'] ) ) {
			$ids = $job->resolve_ids_for_estimate(
				[
					'mode'            => 'all',
					'target_lang_ids' => $langs,
					'skip_existing'   => true,
					'exclude_domains' => $exclude,
				]
			);

			$m       = $ce->estimate_strings( $ids, $langs, false );
			$m_chars = Scalar::int( $m['chars'] ?? 0 );
			$m_items = Scalar::int( $m['items'] ?? 0 );
		}

		if ( ! empty( $args['outdated'] ) ) {
			// One pass, a page at a time; no string is kept.
			$split   = $job->outdated_split(
				$langs,
				$exclude,
				'',
				0,
				static function ( array $send ) use ( $job, $ce, &$o_chars ): void {
					$o_chars += self::outdated_page_chars( $send, $job, $ce );
				}
			);
			$o_left  = BulkStringTranslateJob::rejected_count( $split );
			$o_items = $split['pairs'];
		}

		return $ce->summarize( $m_chars + $o_chars, $m_items + $o_items, 0 ) + [
			'bound'             => Estimator::BOUND_EXACT,
			'complete'          => true,
			'stopped'           => '',
			'first_error'       => '',
			'missing'           => ! empty( $args['missing'] ),
			'outdated'          => ! empty( $args['outdated'] ),
			'missing_strings'   => $m_items,
			'missing_chars'     => $m_chars,
			'outdated_strings'  => $o_items,
			'outdated_chars'    => $o_chars,
			'outdated_rejected' => $o_left,
		];
	}

	/**
	 * The characters one page of outdated pairs sends: each set of languages sharing the same strings sends them
	 * once, plus each language's context characters.
	 *
	 * @param array<int, list<int>>  $send Language id => string ids of the page.
	 * @param BulkStringTranslateJob $job  The job.
	 * @param CostEstimator          $ce   The estimator.
	 * @return int
	 */
	private static function outdated_page_chars( array $send, BulkStringTranslateJob $job, CostEstimator $ce ): int {
		$sets  = [];
		$chars = 0;

		foreach ( $send as $lang => $ids ) {
			$key = md5( implode( ',', $ids ) );

			$sets[ $key ]['ids']     = $ids;
			$sets[ $key ]['langs'][] = $lang;
			$chars                  += $job->outdated_context_chars( $ids, $lang );
		}

		foreach ( $sets as $set ) {
			$chars += Scalar::int( $ce->estimate_strings( $set['ids'], $set['langs'], true )['chars'] ?? 0 );
		}

		return $chars;
	}

	/**
	 * Whether the Strings page run is available: MT and bulk string translation on, and a default language.
	 *
	 * @return bool
	 */
	public static function strings_available(): bool {
		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->settings();

		return $settings->mt_enabled() && $settings->mt_bulk_strings_enabled() && null !== $plugin->lang_repo()->get_default();
	}

	/**
	 * Why MT is unavailable to the current user: `mt_disabled`, `no_capability` or `no_provider`; '' when available.
	 *
	 * @return string
	 */
	public static function mt_unavailable(): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		return \PerfLocale\Resync\PairRunner::mt_unavailable( new \PerfLocale\MachineTranslation\TranslationService( $plugin->settings(), $plugin->cache() ) );
	}

	/**
	 * The refusal text of a machine-mode content run while MT is unavailable to the user, '' when none.
	 * The estimate step then offers no Start, and Start refuses.
	 *
	 * @param array<string, mixed> $args The run's arguments.
	 * @return string
	 */
	public static function machine_refusal( array $args ): string {
		return empty( $args['machine'] ) ? '' : self::mt_refusal_text( self::mt_unavailable() );
	}

	/**
	 * The refusal of a machine-mode run for a cause of {@see self::mt_unavailable()}; '' for no known cause.
	 *
	 * @param string $code mt_disabled, no_capability or no_provider.
	 * @return string
	 */
	public static function mt_refusal_text( string $code ): string {
		$why = ResyncNotices::mt_reason( $code );

		/* translators: %s: why machine translation is unavailable, a full sentence. */
		return '' === $why ? '' : sprintf( __( '%s Changed and new parts are set to be machine translated, so nothing is run.', 'perflocale' ), $why );
	}

	/**
	 * Whether a stored estimate refuses Start: exact, over the monthly limit with characters to send, while the
	 * limit is enforced on bulk runs. '' when it does not.
	 *
	 * @param array<string, mixed> $estimate The estimate.
	 * @return string
	 */
	public static function limit_refusal( array $estimate ): string {
		$settings = \PerfLocale\Plugin::get_instance()->settings();

		if ( Estimator::BOUND_EXACT !== Scalar::str( $estimate['bound'] ?? '' )
			|| empty( $estimate['would_exceed'] )
			|| Scalar::int( $estimate['chars'] ?? 0 ) <= 0
			|| ! (bool) $settings->get( 'mt_enforce_cap_on_bulk', true )
		) {
			return '';
		}

		return sprintf(
			/* translators: 1: estimated characters, 2: remaining monthly characters, 3: monthly limit. */
			__( 'This run needs about %1$s characters but only %2$s of the monthly limit (%3$s) remain. Raise the limit under Settings, Machine Translation, or narrow the selection.', 'perflocale' ),
			number_format_i18n( Scalar::int( $estimate['chars'] ?? 0 ) ),
			number_format_i18n( max( 0, Scalar::int( $estimate['monthly_remaining'] ?? 0 ) ) ),
			number_format_i18n( Scalar::int( $estimate['monthly_limit'] ?? 0 ) )
		);
	}

	/**
	 * The source and languages of a per-post request: a readable in-scope default-language source; a translation's
	 * own language, or for the source the asked or every translated language; only editable ones (`skipped` counts the rest).
	 *
	 * @param int    $post_id The post the button was pressed on.
	 * @param string $lang    A language slug or `all`.
	 * @return array{source_id: int, language_ids: list<int>, skipped: int, error: string}
	 */
	public static function resolve_post( int $post_id, string $lang ): array {
		$fail = static fn ( string $error ): array => [
			'source_id'    => 0,
			'language_ids' => [],
			'skipped'      => 0,
			'error'        => $error,
		];
		// One refusal for every object check, so it tells nothing about posts the user may not read.
		$denied = __( 'This post cannot be resynced: it does not exist, it has no source, or you may not read its source.', 'perflocale' );
		$post   = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return $fail( $denied );
		}

		$plugin = \PerfLocale\Plugin::get_instance();
		$langs  = $plugin->lang_repo();
		$dslug  = Scalar::str( Scalar::prop( $langs->get_default(), 'slug' ) );
		$map    = GroupLinks::of( $post_id );
		$source = '' === $dslug ? 0 : Scalar::int( $map[ $dslug ] ?? 0 );
		$spost  = $source > 0 ? get_post( $source ) : null;

		if ( ! $spost instanceof \WP_Post || in_array( $spost->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return $fail( $denied );
		}

		if ( ! \PerfLocale\Util\ObjectPermission::read_text( $spost ) ) {
			return $fail( $denied );
		}

		if ( ! ResyncLinks::covers( $spost ) ) {
			return $fail( __( 'Content ReSync does not cover this content.', 'perflocale' ) );
		}

		$active = [];

		foreach ( $langs->get_active() as $language ) {
			$slug = Scalar::str( Scalar::prop( $language, 'slug' ) );

			if ( '' !== $slug && $slug !== $dslug ) {
				$active[ $slug ] = Scalar::int( Scalar::prop( $language, 'id' ) );
			}
		}

		// The asked language of the source, or the translation's own language.
		$asked = $post_id === $source ? ( 'all' === $lang ? '' : $lang ) : Scalar::str( array_search( $post_id, $map, true ) );

		if ( '' !== $asked && ! isset( $active[ $asked ] ) ) {
			return $fail( __( 'This language is not active.', 'perflocale' ) );
		}

		$ids     = [];
		$skipped = 0;

		foreach ( $active as $slug => $id ) {
			$translation = Scalar::int( $map[ $slug ] ?? 0 );

			// A translation: its own language only. The source: the asked language, or every one.
			$wanted = $post_id !== $source ? $translation === $post_id : ( 'all' === $lang || $slug === $lang );

			if ( ! $wanted || $translation <= 0 ) {
				continue;
			}

			if ( Helper::user_can_edit_object( $translation ) ) {
				$ids[] = $id;
			} else {
				++$skipped;
			}
		}

		sort( $ids );

		return [
			'source_id'    => $source,
			'language_ids' => $ids,
			'skipped'      => $skipped,
			'error'        => '',
		];
	}

	/**
	 * Why creating translations now may duplicate another plugin's (WPML, Polylang, TranslatePress data not
	 * imported, or an unfinished import); '' when neither.
	 *
	 * @return string
	 */
	public static function migration_warning(): string {
		$sources = \PerfLocale\Migration\MigrationState::unimported_sources();

		if ( [] !== $sources ) {
			return sprintf(
				/* translators: %s: source plugin name(s), e.g. "WPML" or "WPML and Polylang" */
				__( '%s translations have not been imported yet. Creating missing translations now gives those translations the default language and creates duplicate drafts beside them. Import them first, or press Start to continue anyway.', 'perflocale' ),
				\PerfLocale\Migration\MigrationState::source_names( $sources )
			);
		}

		$incomplete = \PerfLocale\Migration\MigrationState::incomplete_sources();

		return [] === $incomplete ? '' : \PerfLocale\Migration\MigrationState::incomplete_message( $incomplete );
	}

	/**
	 * Store the run request and show the estimate step.
	 *
	 * @param string               $kind The kind.
	 * @param array<string, mixed> $args Normalised job arguments.
	 * @param string               $back The screen to return to.
	 * @return void
	 */
	private static function estimate_step( string $kind, array $args, string $back ): void {
		if ( [] === $args['language_ids'] ) {
			self::done( $back, self::message( $kind, __( 'Pick a target language.', 'perflocale' ) ) );
		}

		$estimate = Estimator::estimate( $args );

		if ( '' !== Scalar::str( $estimate['first_error'] ?? '' ) ) {
			self::done( $back, self::message( $kind, Scalar::str( $estimate['first_error'] ) ) );
		}

		// A run that creates translations warns while another plugin's translations are not imported.
		if ( '' !== $args['fill'] ) {
			$estimate['migration_warning'] = self::migration_warning();
		}

		$token = RunRequest::create( $kind, $args, $estimate, $back );

		if ( '' === $token ) {
			self::done( $back, self::message( $kind, __( 'The estimate could not be stored. Try again.', 'perflocale' ) ) );
		}

		self::confirm( $back, $token );
	}

	/**
	 * The stored arguments of a run request, revalidated before Start (active languages, scope types).
	 * Null when nothing is left to run.
	 *
	 * @param string               $kind The kind.
	 * @param array<string, mixed> $args The stored arguments.
	 * @return array<string, mixed>|null
	 * @phpstan-return Args|null
	 */
	private static function revalidate( string $kind, array $args ): ?array {
		$args                 = ContentResyncJob::normalize_args( $args );
		$args['language_ids'] = self::target_languages( $args['language_ids'] );
		$args['mt_budget']    = null;
		$args['dry_run']      = false;

		if ( RunRequest::KIND_DIALOG === $kind ) {
			$args['query']['post_types'] = array_values( array_intersect( $args['query']['post_types'], ResyncLinks::scope_types() ) );

			if ( [] === $args['query']['post_types'] ) {
				return null;
			}
		}

		if ( [] === $args['language_ids'] || ( ContentResyncJob::MODE_SOURCES === $args['mode'] && [] === $args['source_ids'] ) ) {
			return null;
		}

		return ContentResyncJob::normalize_args( $args );
	}

	/**
	 * Dispatch a content run and summarise its outcome for the next screen.
	 *
	 * @param string               $kind  The kind.
	 * @param array<string, mixed> $args  Normalised arguments.
	 * @param string               $retry The per-post link (a pair that needs the estimate presses it again).
	 * @return array<string, mixed>
	 */
	private static function dispatch_content( string $kind, array $args, string $retry ): array {
		$r       = Dispatcher::dispatch( new ContentResyncJob(), $args );
		$mode    = $r['mode'];
		$summary = [
			'kind'    => $kind,
			'mode'    => $mode,
			'job_id'  => Scalar::str( $r['job_id'] ?? '' ),
			'message' => Scalar::str( $r['error'] ?? '' ),
			'retry'   => RunRequest::KIND_POST === $kind ? $retry : '',
		];

		if ( 'sync' !== $mode || ! is_array( $r['result'] ?? null ) ) {
			return $summary;
		}

		$result = $r['result'];
		$counts = [];

		foreach ( [ 'pairs', 'applied', 'held', 'unchanged', 'outdated', 'confirmed', 'filled', 'created', 'filled_existing', 'fills', 'empty', 'missing', 'skipped', 'skipped_permission', 'busy', 'deferred', 'too_slow', 'no_base', 'unsupported', 'failed', 'not_processed', 'needs_estimate' ] as $key ) {
			$counts[ $key ] = Scalar::int( $result[ $key ] ?? 0 );
		}

		$items = [];

		foreach ( is_array( $result['items'] ?? null ) ? $result['items'] : [] as $item ) {
			if ( is_array( $item ) ) {
				$items[] = [
					Scalar::int( $item[0] ?? 0 ),
					Scalar::int( $item[1] ?? 0 ),
					Scalar::int( $item[2] ?? 0 ),
					Scalar::str( $item[3] ?? '' ),
					Scalar::str( $item[4] ?? '' ),
					Scalar::int( $item[5] ?? 0 ),
				];
			}
		}

		return $summary + [
			'counts'         => $counts,
			'items'          => $items,
			'continued'      => Scalar::str( $result['continued'] ?? '' ),
			'first_error'    => Scalar::str( $result['first_error'] ?? '' ),
			'stopped'        => Scalar::str( $result['stopped'] ?? '' ),
			'mt_unavailable' => Scalar::str( $result['mt_unavailable'] ?? '' ),
		];
	}

	/**
	 * Dispatch a Strings page run: missing strings written, outdated ones held for review, with the user's
	 * languages and restricted domains at Start.
	 *
	 * @param array<string, mixed> $args The stored arguments.
	 * @return array<string, mixed>
	 */
	private static function dispatch_strings( array $args ): array {
		$langs   = self::target_languages( array_map( Scalar::int( ... ), (array) ( $args['language_ids'] ?? [] ) ) );
		$exclude = \PerfLocale\Strings\Domains::restricted_for_current_user();
		$out     = [
			'kind'    => RunRequest::KIND_STRINGS,
			'mode'    => 'sync',
			'message' => '',
			'strings' => [],
		];

		if ( ! self::strings_available() ) {
			return self::message( RunRequest::KIND_STRINGS, __( 'Machine translation of strings is not available: switch on machine translation and bulk string translation in the settings.', 'perflocale' ) );
		}

		if ( [] === $langs ) {
			return self::message( RunRequest::KIND_STRINGS, __( 'Nothing is left to run: the languages of this estimate are no longer active.', 'perflocale' ) );
		}

		foreach ( [ 'missing', 'outdated' ] as $part ) {
			if ( empty( $args[ $part ] ) ) {
				continue;
			}

			$r = Dispatcher::dispatch(
				new BulkStringTranslateJob(),
				[
					'mode'            => 'missing' === $part ? 'all' : 'outdated',
					'target_lang_ids' => $langs,
					'skip_existing'   => 'missing' === $part,
					'exclude_domains' => $exclude,
					'provider_id'     => '',
				]
			);

			$result = is_array( $r['result'] ?? null ) ? $r['result'] : [];

			$out['strings'][ $part ] = [
				'mode'             => $r['mode'],
				'job_id'           => Scalar::str( $r['job_id'] ?? '' ),
				'error'            => Scalar::str( $r['error'] ?? '' ),
				'translated'       => Scalar::int( $result['translated'] ?? 0 ),
				'held'             => Scalar::int( $result['held'] ?? 0 ),
				'skipped'          => Scalar::int( $result['skipped'] ?? 0 ),
				'failed'           => Scalar::int( $result['failed'] ?? 0 ),
				'first_error'      => Scalar::str( $result['first_error'] ?? '' ),
				'skipped_rejected' => Scalar::int( $result['skipped_rejected'] ?? 0 ),
			];
		}

		return $out;
	}

	/**
	 * A result that is only a message (a refusal or nothing to do).
	 *
	 * @param string $kind    The kind.
	 * @param string $message The message.
	 * @return array<string, mixed>
	 */
	private static function message( string $kind, string $message ): array {
		return [
			'kind'    => $kind,
			'mode'    => 'none',
			'message' => $message,
		];
	}

	/**
	 * Keep the result and go back with the done flag.
	 *
	 * @param string               $back    The screen.
	 * @param array<string, mixed> $summary The result.
	 * @return never
	 */
	private static function done( string $back, array $summary ): never {
		RunRequest::put_result( $summary );
		wp_safe_redirect( add_query_arg( self::DONE_FLAG, '1', $back ) );
		exit;
	}

	/**
	 * The admin-post handlers act only on a POST with the nonce in the body and not in the address.
	 * Any other method (reload, prefetch, new tab) changes nothing: a capable user gets a one-button page
	 * that posts with a fresh nonce (405), anyone else 403.
	 *
	 * @param string        $action The nonce action.
	 * @param bool          $can    Whether the current user holds the handler's capability.
	 * @param string        $label  The button's text on the page for another method.
	 * @param callable|null $detail fn(): string, what the button acts on, shown on that page (plain text).
	 * @return void
	 */
	private static function require_post( string $action, bool $can, string $label, ?callable $detail = null ): void {
		$method = strtoupper( sanitize_text_field( Scalar::str( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() is the sanitiser; the value is only compared.

		if ( 'POST' !== $method ) {
			if ( ! $can ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
			}

			self::post_page( $action, $label, null === $detail ? '' : Scalar::str( $detail() ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Where the nonce is, before check_admin_referer() verifies it.
		if ( ! isset( $_POST['_wpnonce'] ) || isset( $_GET['_wpnonce'] ) ) {
			wp_nonce_ays( $action );
		}

		check_admin_referer( $action );
	}

	/**
	 * The page a non-POST handler address gets: one button posting the same address with a fresh nonce.
	 *
	 * @param string $action The nonce action.
	 * @param string $label  The button's text.
	 * @param string $detail What the button acts on (plain text), or ''.
	 * @return never
	 */
	private static function post_page( string $action, string $label, string $detail = '' ): never {
		$uri     = remove_query_arg( '_wpnonce', Scalar::str( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Escaped by esc_url() where it is written; only the query of admin-post.php is kept.
		$query   = Scalar::str( wp_parse_url( $uri, PHP_URL_QUERY ) );
		$target  = admin_url( 'admin-post.php' ) . ( '' === $query ? '' : '?' . $query );
		$referer = wp_get_referer();

		$html  = '<p>' . esc_html__( 'This runs only when you press the button.', 'perflocale' ) . '</p>';
		$html .= '' === $detail ? '' : '<p>' . esc_html( $detail ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( $target ) . '">';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';

		if ( is_string( $referer ) && '' !== $referer ) {
			$html .= '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( $referer ) . '">';
		}

		$html .= '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p></form>';

		if ( ! headers_sent() ) {
			header( 'Allow: POST' );
		}

		wp_die( $html, esc_html( $label ), [ 'response' => 405 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part of $html is escaped where it is built.
	}

	/**
	 * What a "Resync from source" address names: the post (its title where the user may read it) and the languages.
	 *
	 * @return string
	 */
	public static function resync_detail(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Shown only, on the page that asks for the press.
		$post_id = max( 0, Scalar::int( wp_unslash( $_GET['post'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A non-negative integer through Scalar::int().
		$lang    = sanitize_key( Scalar::str( wp_unslash( $_GET['lang'] ?? 'all' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() is the sanitiser.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$title = $post_id > 0 && current_user_can( 'read_post', $post_id ) ? get_the_title( $post_id ) : '';
		/* translators: %d: post ID. */
		$post = sprintf( __( 'post %d', 'perflocale' ), $post_id ) . ( '' !== $title ? ' (' . $title . ')' : '' );
		$lang = '' === $lang || 'all' === $lang ? __( 'all languages', 'perflocale' ) : $lang;

		/* translators: 1: a post ("post 12 (About us)"), 2: a language slug or "all languages". */
		return sprintf( __( 'Resync %1$s from its source, for %2$s.', 'perflocale' ), $post, $lang );
	}

	/**
	 * Go back with the estimate step's token.
	 *
	 * @param string $back  The screen.
	 * @param string $token The token.
	 * @return never
	 */
	private static function confirm( string $back, string $token ): never {
		wp_safe_redirect( add_query_arg( self::CONFIRM_FLAG, $token, $back ) );
		exit;
	}

	/**
	 * The screen to return to: the post's editor, the Translations page,
	 * or the validated referer (without the flags of an earlier run).
	 *
	 * @param string $target  edit, translations or referer.
	 * @param int    $post_id The post the button was pressed on.
	 * @return string
	 */
	public static function back_url( string $target, int $post_id ): string {
		$translations = admin_url( 'admin.php?page=perflocale-translations' );

		if ( 'translations' === $target ) {
			return $translations;
		}

		if ( 'edit' === $target ) {
			$edit = ObjectLinks::edit_url( $post_id );

			return '' !== $edit ? $edit : $translations;
		}

		$referer = wp_get_referer();
		$back    = is_string( $referer ) && '' !== $referer ? wp_validate_redirect( $referer, $translations ) : $translations;

		return remove_query_arg( [ self::DONE_FLAG, self::CONFIRM_FLAG ], '' !== $back ? $back : $translations );
	}

	/**
	 * The active non-default languages among the given ids, sorted.
	 *
	 * @param array<int|string, mixed> $ids Language ids.
	 * @return list<int>
	 */
	public static function target_languages( array $ids ): array {
		$plugin  = \PerfLocale\Plugin::get_instance();
		$default = Scalar::int( Scalar::prop( $plugin->lang_repo()->get_default(), 'id' ) );
		$active  = [];

		foreach ( $plugin->lang_repo()->get_active() as $language ) {
			$id = Scalar::int( Scalar::prop( $language, 'id' ) );

			if ( $id > 0 && $id !== $default ) {
				$active[ $id ] = true;
			}
		}

		$out = [];

		foreach ( $ids as $id ) {
			$id = Scalar::int( $id );

			if ( isset( $active[ $id ] ) ) {
				$out[ $id ] = $id;
			}
		}

		ksort( $out );

		return array_values( $out );
	}

	/**
	 * Machine mode as the settings ask (MT availability is still checked at run time).
	 *
	 * @return bool
	 */
	public static function machine(): bool {
		return 'machine' === \PerfLocale\Resync\ResyncSettings::changed_parts( \PerfLocale\Plugin::get_instance()->settings() );
	}

	/**
	 * The default fill choice: machine translated drafts when the user may choose them, else empty drafts.
	 *
	 * @return string
	 */
	public static function default_fill(): string {
		return ResyncLinks::machine_fill_allowed() ? ContentResyncJob::FILL_MACHINE : ContentResyncJob::FILL_EMPTY;
	}

	/**
	 * A posted fill choice: `machine` only when allowed, `empty`, or '' (skip; also for anything else).
	 *
	 * @param string $value The posted value.
	 * @return string
	 */
	public static function fill_choice( string $value ): string {
		if ( ContentResyncJob::FILL_MACHINE === $value ) {
			return ResyncLinks::machine_fill_allowed() ? ContentResyncJob::FILL_MACHINE : '';
		}

		return ContentResyncJob::FILL_EMPTY === $value ? ContentResyncJob::FILL_EMPTY : '';
	}
}
