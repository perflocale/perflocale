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

	public const STATIC_OPTIONS = [
		'perflocale_settings',
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
	];

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
		foreach ( self::STATIC_OPTIONS as $opt ) {
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
		self::strip_role_and_caps();
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like_pattern
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
	 * @return void
	 */
	private static function strip_role_and_caps(): void {
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
