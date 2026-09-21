<?php
/**
 * Per-site PerfLocale data purge.
 *
 * Single source of truth for "remove every PerfLocale-owned row from this
 * site". Called from two places:
 *
 *   1. uninstall.php — once per blog when the plugin is deleted.
 *   2. perflocale.php's wp_uninitialize_site handler — when a network admin
 *      permanently deletes a subsite from the network.
 *
 * Both paths share the same option/transient/meta delete-list so they
 * can't drift apart silently.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-site cleanup orchestrator. Static-only — must be called inside the
 * blog context of the site being purged (after switch_to_blog when on
 * multisite).
 */
final class SiteCleanup {

	/**
	 * Blog IDs already purged during THIS request, keyed by blog ID.
	 *
	 * Request-local by design: the only consumer is the
	 * remove_user_from_blog handler that core fires later in the same
	 * wp_delete_site() call stack. See {@see purge_current_site()}.
	 *
	 * @var array<int, true>
	 */
	private static array $purged_blogs = [];

	/**
	 * Whether this request already purged the given blog's plugin data.
	 *
	 * @param int $blog_id Blog to check.
	 * @return bool
	 */
	public static function was_site_purged( int $blog_id ): bool {
		return isset( self::$purged_blogs[ $blog_id ] );
	}

	/**
	 * Static option keys this plugin writes that must be deleted on full
	 * uninstall. Wildcards (perflocale_addon_manifest_*, perflocale_str_*,
	 * perflocale_mt_usage_*, perflocale_css_*, _transient_perflocale_*) are
	 * handled separately below via SELECT … LIKE.
	 *
	 * Add a key here when introducing a new plugin-owned wp_option.
	 *
	 * @var string[]
	 */
	/**
	 * Action Scheduler group used by every PerfLocale-enqueued action.
	 *
	 * Duplicated as a local constant so `uninstall.php` can sweep AS rows
	 * without loading `ActionSchedulerRunner.php`. Keep in sync with
	 * `ActionSchedulerRunner::GROUP`.
	 *
	 * @var string
	 */
	private const AS_GROUP = 'perflocale';

	/**
	 * The settings row, which is also where each blog's
	 * `delete_data_on_uninstall` decision lives.
	 *
	 * Named as a constant because the purge has to treat it specially: it is
	 * in STATIC_OPTIONS like every other option, but it is deleted LAST (see
	 * purge_current_site()).
	 *
	 * @var string
	 */
	public const MODE_OPTION = 'perflocale_settings';

	public const STATIC_OPTIONS = [
		self::MODE_OPTION,
		'perflocale_tables_exist',
		'perflocale_db_version',
		'perflocale_version',
		'perflocale_flush_rules',
		'perflocale_caps_version',
		// Timestamp of the last completed string full-scan; gates the stale-
		// strings GC so it never deletes never-scanned (imported) strings.
		'perflocale_strings_last_full_scan',
		// GC-protected string domains (e.g. the Visual Editor's _pfl_dyn). Not
		// matched by any OPTION_PATTERNS LIKE, so it must be listed explicitly
		// or a full-data uninstall leaks it.
		'perflocale_gc_protected_domains',
		// Autoloaded l10n manifest (generated .l10n.php file map). Survives
		// uninstall without this entry, per the plugin's own orphan-data audit.
		'perflocale_l10n_manifest',
		'perflocale_webhooks',
		'perflocale_webhook_failures',
		'perflocale_webhook_queue',
		'perflocale_addon_failures',
		// Per-addon last-N migration / uninstall / boot error records, used
		// by the Addons admin page to surface "last error" inline on the
		// card. Bounded internally (newest 200 entries) but defensively
		// swept on full-data uninstall.
		'perflocale_addon_migration_errors',
		'perflocale_addon_schema_versions',
		// Operator-controlled per-addon disabled list (4 KiB capped,
		// autoloaded). Always present on a normally-operated site but
		// purged on full uninstall so leftover entries don't haunt a
		// reinstall.
		'perflocale_disabled_addons',
		// Single autoloaded option storing each addon's user-editable
		// settings entry, keyed by addon ID. Per-entry capped at 16 KiB.
		'perflocale_addon_settings',
		'perflocale_exchange_rates',
		'perflocale_exchange_rates_last_sync',
		'perflocale_exchange_rate_last_error',
		// Last-known active engine (used by Bootstrap's drift detector).
		'perflocale_active_engine',
		// Last-run timestamps for recurring handlers (Jobs admin panel).
		'perflocale_recurring_last_run',
		// Autoloaded perf flags. Each is a cheap "does this blog have any
		// X yet?" sentinel so warm requests skip a SELECT 1 LIMIT 1
		// against the relevant table.
		'perflocale_has_any_groups',
		'perflocale_has_any_slugs',
		'perflocale_rewrites_verified',
		// Old-slug → new-slug 301 redirect map written by
		// LanguageRepository::rename_slug() (autoloaded). Created on-demand
		// (only when a slug is renamed) so it matches no LIKE pattern — must
		// be listed explicitly or it lingers in alloptions on every request
		// after a full uninstall.
		'perflocale_slug_redirects',
		// WP-managed widget option for the Language Switcher widget. WP
		// stores widget instance data under `widget_<id_base>` and our
		// widget's id_base is `perflocale_switcher`. Not picked up by
		// `perflocale_%` patterns because the WP convention puts the
		// `widget_` prefix BEFORE our identifier.
		'widget_perflocale_switcher',
		// Defensive sweeps for options written by earlier builds. Not
		// created on fresh installs, but listed so a site upgraded from a
		// dev build doesn't leak them. delete_option no-ops on missing rows.
		'perflocale_active_jobs',
		'perflocale_currencies',
		'perflocale_bulk_string_translate_threshold',
		'perflocale_settings_autoload_migrated',
		// Timestamp guard written by Bootstrap::ensure_recurring_schedules_throttled
		// so the admin_init "are my recurring events registered?" check runs at
		// most once a day. Single fixed key.
		'perflocale_schedules_verified_at',
		// TranslatePress importer per-post checkpoint. Written by
		// TranslatePressImporter so an interrupted run can resume from the
		// last committed batch. Cleared on successful completion but
		// survives crash / watchdog kill / manual abort — must be in the
		// uninstall sweep so it doesn't haunt a reinstall.
		'perflocale_trp_import_post_checkpoint',
	];

	/**
	 * Network-global options (stored in `wp_sitemeta`, not any blog's
	 * `wp_options`). The per-site uninstall purge loop switch_to_blog()'s
	 * through every blog and only touches `wp_options`, so these are removed
	 * separately at network scope via delete_site_option() in uninstall.php.
	 *
	 * Every `perflocale_*` network option MUST be listed here — the orphan-data
	 * audit (concurrency scenario 26) scans `wp_sitemeta` against this list and
	 * fails on any uncovered key, closing the leak class rather than a single
	 * instance.
	 *
	 * @var string[]
	 */
	public const NETWORK_OPTIONS = [
		// Addon bootable-cache generation token, written on multisite by
		// AddonRegistry::flush_bootable_cache() (BOOTABLE_GEN_OPTION).
		'perflocale_bootable_gen',
		// Resume marker for an uninstall sweep that ran out of execution
		// budget. Listed here so the orphan-data audit covers it and so the
		// run that finishes the sweep removes it — uninstall.php deletes this
		// list ONLY on the completing pass, precisely so an interrupted sweep
		// keeps its own marker.
		self::RESUME_OPTION,
	];

	/**
	 * Network option holding the uninstall sweep's resume marker.
	 *
	 * Shape: `[ 'last_site_id' => int, 'updated' => int ]`, where
	 * `last_site_id` means "every blog whose id is <= this has been purged".
	 *
	 * @var string
	 */
	public const RESUME_OPTION = 'perflocale_uninstall_resume';

	/**
	 * How long a resume marker may be trusted.
	 *
	 * ⚠️ A marker that is honoured forever is worse than no marker at all.
	 * An interrupted uninstall that the operator never finishes leaves one
	 * behind in `wp_sitemeta`, where it would silently skip every blog at or
	 * below `last_site_id` on the NEXT uninstall — months later, after a
	 * re-install, on data that has nothing to do with the pass that wrote it.
	 * Past this age the marker is dropped and the sweep starts from the
	 * beginning.
	 *
	 * Every pass rewrites the marker, so a genuine multi-pass recovery keeps
	 * it fresh however long it takes. The failure mode of expiring too early
	 * is re-walking blogs that are already clean, which costs a little work
	 * and loses nothing; the failure mode of expiring too late is skipping
	 * blogs that still hold data. The asymmetry is the whole reason this
	 * constant exists.
	 *
	 * Spelled as a number rather than `DAY_IN_SECONDS`: this class is the one
	 * the uninstall path loads by hand, and it must not depend on a WordPress
	 * constant being defined wherever it is included — a bare-PHP harness
	 * without WP loaded fataled on exactly that.
	 *
	 * @var int
	 */
	private const RESUME_MAX_AGE = 86400;

	/**
	 * Share of PHP's `max_execution_time` the network purge may spend.
	 *
	 * Same number as Deactivator::NETWORK_SWEEP_TIME_SHARE, and deliberately
	 * so: an operator tuning one sweep is tuning the same kind of work in the
	 * other. The remaining share covers the blog that is mid-purge when the
	 * deadline lands, the network-option sweep after the loop, and core's own
	 * deletion of the plugin directory once uninstall.php returns.
	 *
	 * @var float
	 */
	private const NETWORK_PURGE_TIME_SHARE = 0.7;

	/**
	 * wp_options name patterns (LIKE) cleared on full uninstall.
	 *
	 * @var string[]
	 */
	public const OPTION_PATTERNS = [
		'perflocale_addon_manifest_%',
		'perflocale_str_%',
		'perflocale_mt_usage_%',
		// Background-jobs system: per-job locks + per-type locks live in
		// wp_options (the per-job state itself is in the wp_perflocale_jobs
		// table, which Schema::drop_tables removes). `perflocale_job_lock_*`
		// matches the per-job UUID lock pattern; `perflocale_type_lock_*`
		// the per-type concurrency lock.
		'perflocale_job_lock_%',
		'perflocale_type_lock_%',
		// Translation create-first-group lock keyed by source post ID;
		// 30s TTL so stale rows are rare but possible if a worker crashed
		// mid-create. Wildcard suffix covers every numeric post ID.
		'perflocale_link_lock_%',
		// Generic concurrency locks written by Concurrency\Lock (mt_usage_*,
		// etc). Lock::reap_expired() handles them daily
		// in a live install, but on uninstall the reaper is gone too — any
		// expired rows from the last run would persist forever otherwise.
		'perflocale_lock_%',
		// Per-type eager link map (autoloaded). Pattern-cleared because
		// the type suffix is open-ended ('post', 'term', etc).
		'perflocale_eager_links_%',
		// Gravity Forms addon per-form translation stores (one option per GF
		// form id — the numeric suffix is open-ended, so pattern-cleared).
		'perflocale_gf_translations_%',
		// Generational cache tokens: one autoloaded int per cache group
		// (CacheManager::bump_group_generation). The group-name suffix is
		// open-ended ('perflocale_trans', 'perflocale_hreflang', ...), so it
		// must be pattern-cleared or each group's token lingers in alloptions
		// after a full uninstall.
		'perflocale_cgen_%',
		// Circuit-breaker tracking index (autoload=no, single row).
		// Maintained by PerfLocale\Concurrency\Breaker so Site Health
		// can enumerate active breakers regardless of transient storage
		// backend. Not pattern-needed — single fixed key — but listed
		// alongside the other breaker artifacts for grep-discoverability.
		'perflocale_breakers_index',
		// Per-breaker atomic failure counters (autoload=no, one row per
		// breaker key). Written by Breaker::bump_streak_counter() as a real
		// option rather than a transient because the window-reset and the
		// increment have to happen in ONE statement. Breaker::record_success()
		// clears the row on recovery, but a breaker that never recovers before
		// uninstall would leave its counter behind forever without this.
		'perflocale_breaker_n_%',
		'_transient_perflocale_%',
		'_transient_timeout_perflocale_%',
		'_site_transient_perflocale_%',
		'_site_transient_timeout_perflocale_%',
	];

	/**
	 * User-meta keys cleared on full uninstall.
	 *
	 * Aliased directly to {@see \PerfLocale\Admin\PrivacyIntegration::USER_META_KEYS}
	 * so the uninstall sweep and the GDPR Erase Personal Data flow can't drift
	 * apart silently when a new admin-UI preference is added.
	 *
	 * @var string[]
	 */
	public const USER_META_KEYS = \PerfLocale\Admin\PrivacyIntegration::USER_META_KEYS;

	/**
	 * Post-meta key prefixes cleared on full uninstall. Each entry is the
	 * literal prefix (NOT a SQL LIKE pattern) — the cleanup loop runs them
	 * through `$wpdb->esc_like()` so the underscores are treated as literal
	 * characters, not single-char wildcards.
	 *
	 * Covers:
	 *   - `_perflocale_language`         (WC-order language tag from
	 *                                    EmailTranslation)
	 *   - `_perflocale_alt_<lang>`       per-language attachment alt text
	 *   - `_perflocale_caption_<lang>`   per-language attachment captions
	 *   - `_perflocale_description_<lang>` per-language attachment descriptions
	 *
	 * @var string[]
	 */
	public const POST_META_PREFIXES = [
		'_perflocale_',
	];

	/**
	 * Purge every blog of the installation, each one per its own
	 * `delete_data_on_uninstall` setting.
	 *
	 * `uninstall.php` is the only caller. It passes a deadline, because a
	 * plugin deletion is an ordinary admin request; under WP-CLI
	 * (`wp plugin uninstall perflocale`) there is no execution limit, the
	 * deadline is null, and one pass sweeps the whole network.
	 *
	 * ⚠️ This work cannot be deferred to a background job. `uninstall.php`
	 * runs while the plugin is being deleted and WordPress removes the files
	 * immediately afterwards, so a queued job would fire against a callback
	 * that no longer exists. Whatever does not finish in this request never
	 * happens — which is why an interrupted sweep leaves a marker instead.
	 *
	 * Scope, chunking and the deadline mirror
	 * {@see \PerfLocale\Deactivator::deactivate_for_network()}, with one
	 * deliberate difference: `network_id` is NOT scoped here. Deactivation
	 * acts on the one network whose admin pressed the button; uninstall
	 * removes the plugin's FILES from the whole installation, so every blog
	 * of every network loses the code that owns these rows. Narrowing this
	 * query would strand sibling networks' tables, options and schedules
	 * forever with nothing left on disk to clean them up.
	 *
	 * The resume marker holds the highest blog id for which every blog at or
	 * below it has been purged. It stops advancing at the first blog that
	 * throws, so a resumed sweep re-runs that blog rather than stepping over
	 * a half-cleaned one; every purge step is idempotent, so re-running costs
	 * work, never data.
	 *
	 * @param float|null $deadline Wall-clock time (microtime(true)) after
	 *     which the loop stops between blogs, or null for no limit.
	 * @return array{complete: bool, entered: int, purged: int, failed: int, skipped: int, last_site_id: int, total: int}
	 */
	public static function purge_network( ?float $deadline = null ): array {
		/** This filter is documented in src/Deactivator.php. */
		$filtered_chunk = apply_filters( 'perflocale/activation/chunk_size', 100 );
		// A non-numeric return falls back to the default rather than casting
		// to 0 and clamping to a chunk of 1, which would turn one site query
		// into one site query per blog.
		$chunk = is_numeric( $filtered_chunk ) ? max( 1, (int) $filtered_chunk ) : 100;

		// Everything about the marker is checked before it is trusted: it is
		// whatever happens to be in wp_sitemeta. A hand-edited row, a
		// half-written one, or one left by an uninstall nobody ever finished
		// must all degrade to "start from the beginning" — the safe
		// direction, because it re-walks clean blogs instead of skipping
		// dirty ones.
		$marker     = get_site_option( self::RESUME_OPTION, [] );
		$done_up_to = 0;

		if ( is_array( $marker )
			&& isset( $marker['last_site_id'], $marker['updated'] )
			&& is_numeric( $marker['last_site_id'] )
			&& is_numeric( $marker['updated'] )
		) {
			$age = time() - (int) $marker['updated'];

			if ( $age >= 0 && $age <= self::RESUME_MAX_AGE ) {
				$done_up_to = max( 0, (int) $marker['last_site_id'] );
			} else {
				// Stale, or stamped in the future by a clock change. Drop it
				// rather than carry it: leaving it would let the next
				// uninstall inherit a skip nobody asked for.
				delete_site_option( self::RESUME_OPTION );
			}
		}

		$offset         = 0;
		$entered        = 0;
		$purged         = 0;
		$failed         = 0;
		$skipped        = 0;
		$high_water     = $done_up_to;
		$had_failure    = false;
		$out_of_time    = false;
		$got_full_chunk = false;

		do {
			$site_ids = (array) get_sites(
				[
					'fields'  => 'ids',
					'number'  => $chunk,
					'offset'  => $offset,
					// `offset` paging is only stable against a fixed sort,
					// and ascending id is also what the resume marker means.
					'orderby' => 'id',
					'order'   => 'ASC',
				]
			);

			foreach ( $site_ids as $site_id ) {
				$site_id = (int) $site_id;
				if ( $site_id <= 0 ) {
					continue;
				}

				// Already purged by an earlier pass. Paging from offset 0 and
				// skipping is deliberate: WP_Site_Query has no "id greater
				// than" argument, and a blog deleted between passes would
				// shift every stored offset by one. Id-only chunks are ~10
				// bytes each, so re-walking them costs a query per chunk.
				if ( $site_id <= $done_up_to ) {
					++$skipped;
					continue;
				}

				// `$entered > 0` guarantees forward progress: a request that
				// arrives having already spent its budget must still purge one
				// blog rather than turning the whole sweep into a no-op. The
				// test sits BEFORE switch_to_blog(), so no blog is ever left
				// half-purged by the deadline itself.
				if ( $entered > 0 && null !== $deadline && microtime( true ) >= $deadline ) {
					$out_of_time = true;
					break 2;
				}

				++$entered;

				// switch_to_blog() is INSIDE the try, unlike the deactivation
				// sweep's otherwise identical loop. Core pushes onto the
				// switched stack before it fires `switch_blog`, so a listener
				// that throws would leave the wrong blog current for the rest
				// of the request - and, with the switch outside the try, abort
				// the sweep for every remaining blog on the installation. The
				// finally below restores either way.
				try {
					switch_to_blog( $site_id );

					self::purge_current_site( self::blog_wants_data_deleted() );
					++$purged;

					if ( ! $had_failure ) {
						$high_water = $site_id;
					}
				} catch ( \Throwable $e ) {
					// One blog's failure must not abort the sweep — every
					// other blog still loses the code that owns its rows.
					++$failed;
					$had_failure = true;

					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path; there is no UI left to surface it in.
					error_log( '[PerfLocale] uninstall purge failed on site ' . $site_id . ': ' . $e->getMessage() );
				} finally {
					restore_current_blog();
				}
			}

			$offset        += $chunk;
			$got_full_chunk = ( count( $site_ids ) === $chunk );
		} while ( $got_full_chunk );

		$complete = ! $out_of_time;
		$total    = 0;

		if ( $complete ) {
			// Nothing left to resume. On the uninstall path the marker is in
			// NETWORK_OPTIONS as well, so this is a harmless second delete;
			// on the CLI path it is the only one.
			delete_site_option( self::RESUME_OPTION );
		} else {
			$total = (int) get_sites( [ 'count' => true ] );

			update_site_option(
				self::RESUME_OPTION,
				[
					'last_site_id' => $high_water,
					'updated'      => time(),
				]
			);
		}

		return [
			'complete'     => $complete,
			'entered'      => $entered,
			'purged'       => $purged,
			'failed'       => $failed,
			'skipped'      => $skipped,
			// Blogs this pass did not reach at all. `purged` counts only this
			// pass and `skipped` only what earlier passes did, so neither on
			// its own tells an operator how much is left — and an earlier
			// draft's log line said "purged 12 of 40" when 8 remained.
			'remaining'    => max( 0, $total - $skipped - $purged ),
			'last_site_id' => $high_water,
			'total'        => $total,
		];
	}

	/**
	 * Wall-clock deadline for the network purge, or null when PHP imposes no
	 * execution limit (WP-CLI, most cron runners) and the loop may run to
	 * completion.
	 *
	 * Why bound it at all: a kill lands in the middle of one blog's purge —
	 * tables dropped, options half-swept — and WordPress then deletes the
	 * plugin directory anyway, leaving nothing on disk that knows how to
	 * finish. Stopping between blogs instead leaves every unswept blog
	 * internally consistent and records where to pick up.
	 *
	 * ⚠️ `max_execution_time` is a CPU-time limit on most builds, and a purge
	 * is mostly waiting on the database, so PHP's own timer may never fire.
	 * It is used here as the best available PROXY for the wall-clock budget
	 * that actually kills plugin-deletion requests — the web server's or
	 * proxy's own timeout, which PHP cannot see. Being early costs one extra
	 * pass; being late costs a half-purged blog.
	 *
	 * Measured from the request start rather than from the loop, because
	 * bootstrap and core's own pre-delete work have already spent part of the
	 * limit by the time uninstall.php runs. `$timestart` is set by
	 * timer_start() in wp-settings.php; it is read via $GLOBALS so a missing
	 * or odd value falls back instead of fatalling.
	 *
	 * @return float|null
	 */
	public static function uninstall_deadline(): ?float {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return null;
		}

		$started = isset( $GLOBALS['timestart'] ) && is_numeric( $GLOBALS['timestart'] )
			? (float) $GLOBALS['timestart']
			: microtime( true );

		return $started + max( 1.0, $limit * self::NETWORK_PURGE_TIME_SHARE );
	}

	/**
	 * Read the current blog's "delete data on uninstall" decision.
	 *
	 * Read INSIDE the per-blog loop so every subsite's own preference is
	 * respected: reading it once from whatever blog happened to be current
	 * when uninstall.php loaded (usually the network's main site) would
	 * silently purge data on subsites whose admin chose to preserve it.
	 *
	 * @return bool True if this blog opted in to full data deletion.
	 */
	public static function blog_wants_data_deleted(): bool {
		$settings = get_option( 'perflocale_settings', [] );

		return is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
	}

	/**
	 * Run the chosen cleanup path for the current blog. Caller is
	 * responsible for switch_to_blog / restore_current_blog on multisite.
	 *
	 * @param bool $delete_data When true, runs the full data-deleting
	 *     purge. When false, only role/cap removal (preserves all data so
	 *     a re-install picks up where the user left off).
	 * @param bool $single_site_teardown True when this runs for a SINGLE
	 *     subsite being permanently deleted (wp_uninitialize_site), as opposed
	 *     to a full plugin uninstall across the whole network. On the
	 *     single-subsite path, network-GLOBAL stores (wp_usermeta) must NOT be
	 *     swept, or one deleted subsite would wipe every user's PerfLocale UI
	 *     preferences on the SURVIVING sites where the plugin is still active.
	 * @return void
	 */
	public static function purge_current_site( bool $delete_data, bool $single_site_teardown = false ): void {
		// Remember which blogs this request has already purged. Core's
		// wp_uninitialize_site() runs AFTER this (priority 10 vs our 5) and
		// calls remove_user_from_blog for every member of the dying blog —
		// which would send TranslatorRole's handler into
		// JobState::anonymize_for_user against tables we just dropped,
		// logging a spurious "Table doesn't exist" per deleted subsite.
		// TranslatorRole consults was_site_purged() and skips those blogs.
		self::$purged_blogs[ get_current_blog_id() ] = true;

		if ( $delete_data ) {
			self::full_purge( $single_site_teardown );
		} else {
			self::preserve_purge();
		}

		// Scheduled-event cleanup ALWAYS runs, even when the operator preserves
		// data on uninstall: the plugin code is about to leave disk, so a
		// pending cron/AS event whose callback lived here would log
		// "no callback" / missing-class errors. Cleaning loses nothing (no user
		// data in schedules; AS actions restart on the next install).
		self::clear_all_scheduled_events();

		// LAST, and only on the deleting path: the settings row is what
		// `blog_wants_data_deleted()` reads. While it is still there, an
		// interrupted purge is a purge that will be finished in the same mode
		// when the operator resumes or deletes again. Once it is gone the
		// blog looks like one that chose to preserve its data, so it must not
		// go until there is nothing left to get wrong.
		if ( $delete_data ) {
			delete_option( self::MODE_OPTION );
		}
	}

	/**
	 * Drop tables, options, transients, meta, role+caps, and translation
	 * files for the current blog.
	 *
	 * @param bool $single_site_teardown See {@see purge_current_site()}: skip
	 *     network-global stores when only one subsite is being deleted.
	 * @return void
	 */
	private static function full_purge( bool $single_site_teardown = false ): void {
		global $wpdb;

		// 1. Addon-driven cleanup FIRST. Manifests describe each addon's
		// tables/options/meta independent of whether the addon plugin is
		// still on disk, so this works on partial-uninstall scenarios.
		self::purge_addons();

		// 2. Drop core tables (canonical list lives in Schema::drop_tables).
		Schema::drop_tables();

		// 3. Plugin options - static list + LIKE-patterns.
		//
		// `perflocale_settings` is held back to the END of the whole purge
		// (see purge_current_site()). It is the option this blog's
		// delete-or-preserve decision is read from, so deleting it here left
		// a purge that died part-way - a max_execution_time kill, a fatal on
		// one blog - coming back as PRESERVE mode on the retry, stranding
		// that blog's meta, translation files and role permanently. Nothing
		// else in the purge reads it, so holding it back costs nothing.
		foreach ( self::STATIC_OPTIONS as $opt ) {
			if ( self::MODE_OPTION === $opt ) {
				continue;
			}

			delete_option( $opt );
		}

		foreach ( self::OPTION_PATTERNS as $pattern ) {
			self::delete_options_like( $pattern );
		}

		// 4. User meta — namespace LIKE sweep. We own `perflocale_*`
		// exclusively, so a prefix DELETE is safe and sweeps any new key a
		// dev forgot to register in USER_META_KEYS. That constant stays the
		// canonical fixed list for the GDPR erase flow (no wildcard there).
		//
		// SKIP on a single-subsite teardown: wp_usermeta is NETWORK-GLOBAL
		// (switch_to_blog does NOT re-scope it, unlike post/term meta), and
		// PerfLocale's user-meta keys (screen options, hidden-language column
		// prefs) are stored unprefixed/network-wide. Sweeping here when just
		// ONE subsite is deleted would wipe every user's UI preferences across
		// all SURVIVING sites where the plugin is still active. These keys have
		// no correct per-blog deletion, so they are only swept on a full,
		// network-wide plugin uninstall.
		if ( ! $single_site_teardown ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( 'perflocale_' ) . '%'
				)
			);
		}

		// 5. Term meta — language tags on menus.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_perflocale_' ) . '%'
			)
		);

		// 6. Post meta — prefix LIKE deletes cover the WC-order language tag
		// plus per-language attachment caption/description/alt-text
		// variants. See POST_META_PREFIXES for the exact coverage. Each
		// prefix runs through esc_like() so the underscores stay literal
		// rather than being treated as single-char wildcards.
		foreach ( self::POST_META_PREFIXES as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}

		// 7. Role + caps.
		self::strip_role_and_caps();

		// 8. Translation files in uploads/perflocale/translations.
		self::delete_translation_files();

		// 9. Drop the cached rewrite rules so language-prefix rules don't 404
		// after the plugin is gone. Deleting the option rather than calling
		// flush_rewrite_rules() is deliberate on both counts:
		//   - This runs inside switch_to_blog() (uninstall.php loops the
		//     network). Core does not re-initialise $wp_rewrite on a blog
		//     switch, so a hard flush would write the ORIGINAL blog's
		//     permalink structure and post types into this subsite's option
		//     and, because WP_Rewrite short-circuits on a non-empty option,
		//     they would stick — every subsite with a different structure
		//     404s until someone re-saves its permalinks.
		//   - Regenerating with the plugin still loaded walks home_url →
		//     UrlConverter → our repositories, whose tables step 2 just
		//     dropped, logging spurious "Table doesn't exist" errors.
		// Each blog regenerates its own correct rules on its next request.
		// Skipped on a single-subsite teardown: core drops the blog's whole
		// options table right after this hook, so the write is wasted.
		if ( ! $single_site_teardown ) {
			delete_option( 'rewrite_rules' );
		}

		// 10. Persistent object-cache (L2) flush. The wp_options transient
		// rows are cleared by step 3 above, but on a Redis/Memcached
		// backend every key written via wp_cache_set( …, 'perflocale_*' )
		// lives in the cache server and persists until its TTL — up to
		// ~12 h, which can show up as ghost reads on the next install.
		// Flush every plugin-owned group explicitly.
		self::flush_cache_groups();

		// Scheduled-event cleanup is run by `purge_current_site()` after
		// this method returns, so a leaked event has no chance to fire
		// against now-dropped tables.
	}

	/**
	 * Flush every plugin-owned persistent object-cache group.
	 *
	 * Static so it runs from the uninstall path without instantiating
	 * CacheManager (which needs Settings + the plugin container, both of
	 * which are gone during uninstall). Reads the canonical group list
	 * from CacheManager::GROUPS so the two sweeps can't drift.
	 *
	 * @return void
	 */
	private static function flush_cache_groups(): void {
		if ( ! class_exists( \PerfLocale\Cache\CacheManager::class ) ) {
			return;
		}

		// Bump each group's generation — reliable on every object-cache
		// backend, including those whose wp_cache_flush_group() is missing
		// (WP < 6.1) or a silent no-op (e.g. Redis Object Cache + Predis).
		foreach ( \PerfLocale\Cache\CacheManager::GROUPS as $group ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( $group );
		}

		// On a persistent object cache, PerfLocale's transients (breaker state,
		// MT rate-limit counters, etc.) live in WP's 'transient'/'site-transient'
		// cache groups, NOT wp_options — so deleting the _transient_perflocale_%
		// option rows is a no-op for them. Flush those groups too so a full
		// uninstall leaves no ghost keys for a quick reinstall to read back.
		// Gated on an external cache (on the DB backend the rows already went)
		// and the WP 6.1+ helper. Teardown-only; transients are disposable.
		if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'transient' );
			wp_cache_flush_group( 'site-transient' );

			// Physically drop every plugin-owned group too. The generation
			// bump above only orphans the current keys; the cgen tokens that
			// gate them are deleted below, which returns a quick reinstall to
			// generation 0 and the identical key space, so those orphaned keys
			// would be read back as ghosts until their TTL. Flushing the groups
			// removes them outright on backends that support it (phpredis /
			// memcached); a no-op backend (e.g. Predis) still relies on TTL.
			foreach ( \PerfLocale\Cache\CacheManager::GROUPS as $group ) {
				wp_cache_flush_group( $group );
			}
		}

		// bump_group_generation() above RE-WRITES each `perflocale_cgen_<group>`
		// option (it persists the incremented generation), which the
		// OPTION_PATTERNS sweep in full_purge() already deleted a few steps
		// earlier — so without this, a full-data uninstall leaves ~10 orphaned
		// generation tokens in wp_options forever. This method is the LAST cache
		// step and runs only during uninstall, where nothing reads the cache
		// afterwards, so removing the tokens now is safe: the L2 keys they gated
		// are orphaned by their TTL regardless (a bump never deleted them). Run
		// last so it undoes the bump's option writes.
		self::delete_options_like( 'perflocale_cgen_%' );
	}

	/**
	 * Network-scope Action Scheduler cleanup. Run once at the end of
	 * `uninstall.php` on multisite installs so we explicitly drain the
	 * (network-wide) AS queue at network scope rather than relying on
	 * the per-site loop to have done it for us. Idempotent.
	 *
	 * @return void
	 */
	public static function network_clear_action_scheduler(): void {
		self::clear_action_scheduler_orphans();
	}

	/**
	 * Defensive sweep: cancel every pending scheduled event the plugin
	 * could possibly own, regardless of engine or hook name. Used at
	 * full uninstall to defeat orphans that survived a misordered
	 * deactivation, mid-life engine flip, or third-party addon that
	 * registered a `perflocale_*` hook we don't know about.
	 *
	 * Action Scheduler side: enumerate every pending/in-progress action
	 * in the `perflocale` group via `as_get_scheduled_actions()`, collect
	 * the distinct hook names, then call `as_unschedule_all_actions(hook,
	 * [], group)` for each. This is the documented public-API path -
	 * preferable to passing an empty hook ("match all in group") which
	 * happens to work today but isn't a contracted behaviour.
	 *
	 * WP-Cron side: walks `_get_cron_array()` (private but stable since
	 * WP 3.5) and unschedules every `perflocale_*` hook. Includes an
	 * option-table fallback so a future WP refactor that renames or
	 * removes `_get_cron_array()` doesn't silently break the sweep.
	 *
	 * @return void
	 */
	private static function clear_all_scheduled_events(): void {
		self::clear_action_scheduler_orphans();
		self::clear_wp_cron_orphans();
	}

	/**
	 * Cancel every pending action in the `perflocale` group via the
	 * documented public AS API. Iterates through all pages because
	 * `as_get_scheduled_actions` paginates at ~100 by default.
	 *
	 * @return void
	 */
	private static function clear_action_scheduler_orphans(): void {
		if ( did_action( 'action_scheduler_init' ) === 0 ) {
			return;
		}

		// Preferred path: cancel every action in our group regardless of
		// hook/args. AS exposes `cancel_actions_by_group()` as a public
		// store method; `as_unschedule_all_actions('', [], $group)`
		// dispatches to it explicitly (see action-scheduler/functions.php
		// `as_unschedule_all_actions()`). This catches recurring events
		// that were scheduled with `[$blog_id]` args — which the older
		// per-hook + empty-args sweep silently missed.
		if ( class_exists( '\\ActionScheduler_Store' )
			&& method_exists( '\\ActionScheduler_Store', 'instance' )
		) {
			try {
				$store = \ActionScheduler_Store::instance();
				if ( method_exists( $store, 'cancel_actions_by_group' ) ) {
					$store->cancel_actions_by_group( self::AS_GROUP );
					return;
				}
			} catch ( \Throwable $e ) {
				// Fall through to the per-hook iteration below.
				unset( $e );
			}
		}

		// Fallback: enumerate hooks (with their actual args) and cancel
		// per-hook. Used only if the store API is missing — kept so
		// future AS API changes don't leave us silently unable to clean
		// up.
		if ( ! function_exists( 'as_get_scheduled_actions' )
			|| ! function_exists( 'as_unschedule_action' )
		) {
			return;
		}

		$page      = 1;
		$per_page  = 100;
		$max_pages = 100;

		while ( $page <= $max_pages ) {
			$ids = as_get_scheduled_actions(
				[
					'group'    => self::AS_GROUP,
					'status'   => [ 'pending', 'in-progress' ],
					'per_page' => $per_page,
					'paged'    => $page,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				],
				'ids'
			);

			if ( ! is_array( $ids ) || $ids === [] ) {
				break;
			}

			// Cancel by id — guarantees we catch every action regardless of
			// hook/args/group-membership shape.
			foreach ( $ids as $id ) {
				try {
					\ActionScheduler::store()->cancel_action( (int) $id );
				} catch ( \Throwable $e ) {
					// best-effort
					unset( $e );
				}
			}

			++$page;
			if ( count( $ids ) < $per_page ) {
				break;
			}
		}
	}

	/**
	 * Walk WP-Cron and remove every event whose hook name starts with
	 * `perflocale_`. Catches:
	 *   - Hooks in our Deactivator list but with unexpected args.
	 *   - Hooks we never knew about (registered by an addon).
	 *   - Hooks left orphaned after an engine-setting flip.
	 *
	 * @return void
	 */
	private static function clear_wp_cron_orphans(): void {
		$cron = self::read_cron_array();

		if ( ! is_array( $cron ) ) {
			return;
		}

		$perflocale_hooks = [];

		foreach ( $cron as $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook_name => $unused_events ) {
				if ( is_string( $hook_name )
					&& strpos( $hook_name, 'perflocale_' ) === 0 ) {
					$perflocale_hooks[ $hook_name ] = true;
				}
			}
		}

		foreach ( array_keys( $perflocale_hooks ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Read the cron array, preferring the public-API
	 * `_get_cron_array()` (private but stable since WP 3.5) and falling
	 * back to a direct `get_option('cron')` read if WP ever removes it.
	 *
	 * The option payload format - `[ timestamp => [ hook => [ key => event ] ] ]` -
	 * has been stable since at least WP 2.1 and is what `_get_cron_array()`
	 * decodes internally, so the fallback yields the same structure.
	 *
	 * @return array<int|string, mixed>|null
	 */
	private static function read_cron_array(): ?array {
		if ( function_exists( '_get_cron_array' ) ) {
			$cron = _get_cron_array();
			if ( is_array( $cron ) ) {
				return $cron;
			}
		}

		$cron = get_option( 'cron' );

		if ( ! is_array( $cron ) ) {
			return null;
		}

		// Newer WPs may store a `version` key on the cron option; drop
		// it so callers don't have to filter.
		unset( $cron['version'] );

		return $cron;
	}

	/**
	 * Preserve-mode cleanup. Strips role + caps (those live in roles/users
	 * tables and must always go on uninstall) but leaves all plugin data
	 * intact so a re-install resumes where the user left off.
	 *
	 * @return void
	 */
	private static function preserve_purge(): void {
		// `true`: this path may skip blogs that never had our caps. The full
		// purge may NOT - by the time it calls this, its own option sweep has
		// already deleted the flag the skip reads.
		self::strip_role_and_caps( true );
	}

	/**
	 * Run each addon's manifest-driven uninstaller. Errors are isolated -
	 * one bad addon does not block the rest of cleanup.
	 *
	 * @return void
	 */
	private static function purge_addons(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$manifest_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'perflocale_addon_manifest_' ) . '%'
			)
		);

		if ( ! class_exists( \PerfLocale\Addon\AddonUninstaller::class ) ) {
			return;
		}

		foreach ( (array) $manifest_keys as $key ) {
			$addon_id = substr( (string) $key, strlen( 'perflocale_addon_manifest_' ) );
			if ( '' === $addon_id ) {
				continue;
			}

			$live_addon = null;
			if (
				function_exists( 'perflocale' )
				&& class_exists( \PerfLocale\Plugin::class )
				&& \PerfLocale\Plugin::get_instance()->has( 'addon_registry' )
			) {
				$registry = \PerfLocale\Plugin::get_instance()->get( 'addon_registry' );
				if ( method_exists( $registry, 'get_addons' ) ) {
					$all        = (array) $registry->get_addons();
					$live_addon = $all[ $addon_id ] ?? null;
				}
			}

			try {
				\PerfLocale\Addon\AddonUninstaller::purge(
					$addon_id,
					$live_addon instanceof \PerfLocale\Addon\AddonInterface ? $live_addon : null
				);
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( 'PerfLocale addon uninstall ' . $addon_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}

		if ( class_exists( \PerfLocale\Addon\AddonMigrationErrors::class ) ) {
			delete_option( \PerfLocale\Addon\AddonMigrationErrors::OPTION );
		}
	}

	/**
	 * Delete every wp_options row matching a LIKE pattern, going through
	 * delete_option/delete_transient so the alloptions / transient caches
	 * stay coherent.
	 *
	 * @param string $like_pattern e.g. 'perflocale_addon_manifest_%'.
	 * @return void
	 */
	private static function delete_options_like( string $like_pattern ): void {
		global $wpdb;

		// ⚠️ `_` IS A SINGLE-CHARACTER WILDCARD IN LIKE. Every pattern in
		// OPTION_PATTERNS contains underscores — `perflocale_lock_%` also
		// matches `perflocaleXlockY...`, so the DELETE reached further than the
		// pattern says. Nothing is known to have been lost (no other plugin
		// ships an option that close to ours), but a cleanup routine must
		// delete exactly what it claims to.
		//
		// Escape the literal underscores while PRESERVING the intended `%`:
		// split on `%`, escape each piece, rejoin. `esc_like()` on the whole
		// string would escape the trailing `%` too and match nothing. No
		// branch, and it matches the semantics the gate's orphan-data audit
		// has always modelled.
		$escaped = implode( '%', array_map( [ $wpdb, 'esc_like' ], explode( '%', $like_pattern ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$escaped
			)
		);

		foreach ( (array) $names as $name ) {
			$name = (string) $name;

			if ( str_starts_with( $name, '_transient_timeout_' ) || str_starts_with( $name, '_site_transient_timeout_' ) ) {
				// Timeout rows are auto-cleared by delete_transient on the
				// matching value row; skip to avoid a redundant write.
				continue;
			}

			if ( str_starts_with( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
				continue;
			}

			if ( str_starts_with( $name, '_site_transient_' ) ) {
				delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );
				continue;
			}

			delete_option( $name );
		}
	}

	/**
	 * Remove the perflocale_translator role and strip every PerfLocale cap
	 * from the standard caps roles. Honors the perflocale/roles/cap_roles
	 * filter so operators can keep custom roles untouched.
	 *
	 * @param bool $allow_skip When true (preserve path only), a blog that has
	 *     neither `perflocale_caps_version` nor the Translator role is left
	 *     alone. The full-data path must pass false: by the time it calls
	 *     this, its own option sweep has already deleted that flag.
	 * @return void
	 */
	private static function strip_role_and_caps( bool $allow_skip = false ): void {
		// A blog that never had our caps installed has nothing to strip. On a
		// network uninstall in preserve mode this is the entire per-blog
		// cost: without it every blog of the installation pays a
		// remove_role() write, a remove_cap() write per cap per cap-role, and
		// a usermeta scan - on a large network, tens of thousands of writes
		// for blogs the plugin was never activated on.
		//
		// Both markers have to be absent. `perflocale_caps_version` is
		// written whenever caps are installed, and the role's presence is
		// checked directly, so a blog that has either one takes the full
		// path.
		//
		// Only the preserve path may take this shortcut. On the full-data
		// path the caller has already deleted `perflocale_caps_version` in
		// its option sweep, so the flag would read as absent on every blog
		// and a role-less blog would keep its editor/administrator caps.
		if ( $allow_skip
			&& false === get_option( 'perflocale_caps_version', false )
			&& null === get_role( \PerfLocale\Admin\TranslatorRole::ROLE_SLUG )
		) {
			return;
		}

		remove_role( \PerfLocale\Admin\TranslatorRole::ROLE_SLUG );

		$caps = self::canonical_caps();

		/** This filter is documented in src/Admin/TranslatorRole.php. */
		$cap_roles = (array) apply_filters( 'perflocale/roles/cap_roles', [ 'editor', 'administrator' ] );

		foreach ( $cap_roles as $role_slug ) {
			$role = get_role( sanitize_key( (string) $role_slug ) );

			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				// WP_Roles::remove_cap() calls update_option() on the whole
				// `wp_user_roles` array every time, including for a cap the
				// role never had — serialising the array and comparing it
				// against the stored copy on each call. update_option()
				// no-ops when the value is unchanged, so those calls cost
				// work rather than DB writes; skipping them is the saving,
				// and it is not a row-count saving. An earlier draft of this
				// patch claimed "14 writes to 4" because the bench measuring
				// it counted calls as writes.
				if ( ! isset( $role->capabilities[ $cap ] ) ) {
					continue;
				}

				$role->remove_cap( $cap );
			}
		}

		// Sweep users for orphaned `perflocale_*` capability grants. Same
		// helper is called by TranslatorRole::remove_roles() during plugin
		// deactivation so users with directly-granted caps don't keep
		// dead serialized refs across deactivation/reactivation cycles.
		self::sweep_orphan_user_caps();

		// Drop the install version flag in lock-step with the caps we just
		// removed. The flag and the caps are two halves of the same state —
		// if the caps go and the flag stays, install_caps() short-circuits
		// on the next reinstall and the caps never come back. full_purge()
		// already deletes this via STATIC_OPTIONS so the duplicate is a
		// harmless no-op there; the load-bearing call is from preserve_purge().
		delete_option( 'perflocale_caps_version' );
	}

	/**
	 * Strip every `perflocale_*` key from per-user `wp_capabilities` meta.
	 *
	 * Called from BOTH `strip_role_and_caps()` (on full uninstall) and
	 * `TranslatorRole::remove_roles()` (on plugin deactivation). Without
	 * the shared helper the two paths drifted: only the uninstall path
	 * stripped direct add_cap() grants, leaving orphan serialized refs in
	 * user_meta across deactivation/reactivation cycles.
	 *
	 * Safe on multisite: the call site has already entered the correct
	 * blog via switch_to_blog, so `$wpdb->usermeta` resolves to the
	 * current blog's table.
	 *
	 * @return void
	 */
	public static function sweep_orphan_user_caps(): void {
		global $wpdb;

		$cap_meta_key = $wpdb->prefix . 'capabilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dirty_user_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$cap_meta_key,
				'%perflocale_%'
			)
		);

		// Strip CAPABILITIES only, never the role assignment. WordPress stores a
		// role assignment as a KEY in wp_capabilities, and ROLE_SLUG is
		// 'perflocale_translator' — which matches a naive 'perflocale_' prefix
		// test. Stripping it leaves a translator-only user with a:0:{}: no role,
		// no `read` cap, locked out of wp-admin, with nothing recording who had
		// it. That would fire on a plain deactivation (which WordPress
		// guarantees is reversible) and on preserve-mode uninstall. Core's own
		// remove_role() deliberately leaves assignments in place so a
		// reactivation restores them; only direct add_cap() grants are orphans.
		$strip_caps = array_flip(
			array_merge(
				self::canonical_caps(),
				// Retired capabilities that earlier builds granted directly.
				[ 'perflocale_manage_glossary' ]
			)
		);

		foreach ( $dirty_user_ids as $uid ) {
			$uid    = (int) $uid;
			$stored = get_user_meta( $uid, $cap_meta_key, true );

			if ( ! is_array( $stored ) ) {
				continue;
			}

			$cleaned = array_filter(
				$stored,
				static fn( $key ): bool => ! isset( $strip_caps[ (string) $key ] ),
				ARRAY_FILTER_USE_KEY
			);

			if ( $cleaned !== $stored ) {
				update_user_meta( $uid, $cap_meta_key, $cleaned );
			}
		}
	}

	/**
	 * Caps to strip on uninstall: current TranslatorRole::CAPABILITIES, with
	 * a static fallback if the class can't be loaded (e.g. during early
	 * uninstall ordering).
	 *
	 * @return string[]
	 */
	private static function canonical_caps(): array {
		return class_exists( \PerfLocale\Admin\TranslatorRole::class )
			? \PerfLocale\Admin\TranslatorRole::CAPABILITIES
			: [
				'perflocale_translate',
				'perflocale_manage_translations',
				'perflocale_approve_translations',
				'perflocale_manage_languages',
				'perflocale_manage_addons',
				'perflocale_use_mt',
				'perflocale_import_export',
			];
	}

	/**
	 * Purge the plugin's uploads tree on uninstall:
	 *   - `uploads/perflocale/translations/` (generated .l10n.php files)
	 *   - `uploads/perflocale/temp/`         (in-flight import scratch)
	 *   - `uploads/perflocale/exports/`      (export bundles)
	 *   - `uploads/perflocale/`              (the consolidated parent)
	 *
	 * @return void
	 */
	private static function delete_translation_files(): void {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return;
		}
		$basedir = trailingslashit( (string) $upload_dir['basedir'] );

		self::delete_dir_recursive( $basedir . 'perflocale' );
	}

	/**
	 * Recursive directory removal. Refuses to descend into anything
	 * outside `uploads/` so a buggy caller can't traverse beyond.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function delete_dir_recursive( string $dir ): void {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return;
		}
		$uploads_real = realpath( (string) $upload_dir['basedir'] );
		$dir_real     = realpath( $dir );

		if ( $uploads_real === false || $dir_real === false
			|| ! str_starts_with( $dir_real, rtrim( $uploads_real, '/' ) . DIRECTORY_SEPARATOR )
		) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @ suppresses the unreadable-dir warning; is_array() check on the next line short-circuits the cleanup if scandir() actually fails.
		$entries = @scandir( $dir_real );
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir_real . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_dir_recursive( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// Raw rmdir to match the credential-free wp_delete_file() (unlink)
		// calls that just emptied this directory: WP_Filesystem here would
		// need FS credentials that aren't guaranteed during uninstall, which
		// would leave the now-empty dir behind on FTP/SSH hosts.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Credential-free cleanup; see note above.
		@rmdir( $dir_real );
	}
}
