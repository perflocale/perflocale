<?php
/**
 * WP-CLI commands for PerfLocale.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cli;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\TranslationLinkRepository;
use PerfLocale\Database\Schema;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Plugin;
use PerfLocale\Strings\StringScanner;
use PerfLocale\Translation\PostTranslationManager;
use PerfLocale\Translation\TermTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PerfLocale multilingual plugin commands.
 *
 * ## EXAMPLES
 *
 * # List all languages
 * wp perflocale languages list
 *
 * # Translate a post (single or bulk)
 * wp perflocale translate 42 --to=fr
 * wp perflocale translate --all --to=de --post-type=post --skip-existing
 *
 * # Manage translations
 * wp perflocale translations list 42
 * wp perflocale translations set-language 42 --lang=en
 *
 * # Scan for translatable strings
 * wp perflocale strings scan
 *
 * # Slug management
 * wp perflocale slugs backfill
 *
 * # Health check
 * wp perflocale health-check
 *
 * # Flush caches
 * wp perflocale cache flush
 */
final class PerfLocaleCommand {

	/**
	 * @var Plugin
	 */
	private readonly Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'perflocale', $this );
		}
	}

	// The service-container's `register_hooks()` duck-typing skips classes
	// that don't define the method — and exposing a public no-op here would
	// register it as a `wp perflocale register_hooks` subcommand, since
	// WP-CLI auto-exposes every public method. So we deliberately omit it.

	// =========================================================================
	// Languages
	// =========================================================================

	/**
	 * Manage languages.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Subcommand to run.
	 * ---
	 * options:
	 *   - list
	 *   - add
	 *   - delete
	 * ---
	 *
	 * [<locale_or_slug>]
	 * : Locale (for add, e.g. en_US) or slug (for delete, e.g. bg). Ignored by list.
	 *
	 * [--default]
	 * : When adding, mark the new language as default.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt when deleting.
	 *
	 * [--format=<format>]
	 * : Output format for `list`. Accepted: table, json, csv, yaml, count, ids.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp perflocale languages list
	 *     wp perflocale languages list --format=json
	 *     wp perflocale languages add en_US
	 *     wp perflocale languages add de_DE --default
	 *     wp perflocale languages delete bg
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function languages( array $args, array $assoc_args ): void {
		$subcommand = $args[0] ?? 'list';

		switch ( $subcommand ) {
			case 'list':
				$this->languages_list( $assoc_args );
				break;

			case 'add':
				$this->languages_add( $args[1] ?? '', $assoc_args );
				break;

			case 'delete':
				$this->languages_delete( $args[1] ?? '', $assoc_args );
				break;

			default:
				\WP_CLI::error( "Unknown subcommand: {$subcommand}. Available: list, add, delete" );
		}
	}

	/**
	 * List languages.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function languages_list( array $assoc_args ): void {
		$cache = $this->plugin->get( 'cache' );
		$repo  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$langs = $repo->find_all();

		$items = array_map(
			fn( $l ) => [
				'ID'        => $l->id,
				'Slug'      => $l->slug,
				'Locale'    => $l->locale,
				'Name'      => $l->name,
				'Default'   => $l->is_default ? 'Yes' : 'No',
				'Active'    => $l->is_active ? 'Yes' : 'No',
				'Direction' => $l->text_direction,
			],
			$langs
		);

		$format = $assoc_args['format'] ?? 'table';

		if ( 'ids' === $format ) {
			// format_items() can't render 'ids' from associative rows — it
			// stringifies each row ("Array to string conversion"). Emit the
			// ID column directly so `--format=ids` stays scriptable.
			\WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'ID' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, [ 'ID', 'Slug', 'Locale', 'Name', 'Default', 'Active', 'Direction' ] );
	}

	/**
	 * Add a language.
	 *
	 * @param string                $locale Locale code.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function languages_add( string $locale, array $assoc_args ): void {
		if ( $locale === '' ) {
			\WP_CLI::error( 'Locale is required. Usage: wp perflocale languages add en_US' );
		}

		// Accept only recognised locale shapes: a 2–3 letter primary tag,
		// optionally followed by _XX (region) or _Variant. Rejects the long
		// tail of typos that would otherwise be silently inserted.
		if ( ! preg_match( '/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8})*$/', $locale ) ) {
			\WP_CLI::error( "Invalid locale format: {$locale}. Expected something like en_US, fr_FR, or de_DE_formal." );
		}

		$cache = $this->plugin->get( 'cache' );
		$repo  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );

		$existing = $repo->find_by_locale( $locale );

		if ( $existing ) {
			\WP_CLI::error( "Language with locale {$locale} already exists." );
		}

		// Resolve display data from the bundled language table — the same
		// source the admin UI's quick-select uses. Without this, the raw
		// locale became the display name ('de_DE' instead of 'Deutsch') and
		// leaked into the public front-end switcher on CLI-provisioned sites.
		// The bundled slug also disambiguates regional variants (en_GB →
		// 'en-gb') exactly like the admin path, instead of colliding on the
		// first two letters.
		$preset = null;
		if ( defined( 'PERFLOCALE_DIR' ) && file_exists( PERFLOCALE_DIR . 'data/languages.php' ) ) {
			foreach ( (array) require PERFLOCALE_DIR . 'data/languages.php' as $candidate ) {
				if ( is_array( $candidate ) && strcasecmp( (string) ( $candidate['locale'] ?? '' ), $locale ) === 0 ) {
					$preset = $candidate;
					break;
				}
			}
		}

		$slug      = (string) ( $preset['slug'] ?? strtolower( substr( $locale, 0, 2 ) ) );
		$direction = (string) ( $preset['text_direction'] ?? \PerfLocale\Helper::default_text_direction( $locale ) );

		// Pre-flight the slug collision. The slug is derived from the first two
		// letters of the locale and is UNIQUE across ALL language rows (active
		// and inactive), so adding e.g. en_GB while an 'en' language exists
		// would otherwise blow up on the INSERT with a raw DB-error splat. The
		// bootstrap slug map only covers active languages, so check raw.
		global $wpdb;
		$lang_table = \PerfLocale\Database\Schema::table( 'languages' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $lang_table is bound through the %i identifier placeholder; slug is a bound %s placeholder.
		$slug_in_use = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE slug = %s', $lang_table, $slug )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $slug_in_use > 0 ) {
			\WP_CLI::error(
				"Slug '{$slug}' (the first two letters of {$locale}) is already in use by another language. Slugs must be unique; remove the conflicting language first, or add this one through the admin UI where the slug can be customised."
			);
		}

		$id = $repo->insert(
			[
				'slug'           => $slug,
				'locale'         => $locale,
				'name'           => (string) ( $preset['name'] ?? $locale ),
				'native_name'    => (string) ( $preset['native_name'] ?? $locale ),
				'flag'           => (string) ( $preset['flag'] ?? $slug ),
				// Always insert as non-default; promote via set_default() below
				// so the previous default is demoted in the same transaction.
				// Inserting is_default=1 directly would leave TWO defaults.
				'is_default'     => 0,
				'is_active'      => 1,
				'text_direction' => $direction,
			]
		);

		if ( $id === false ) {
			\WP_CLI::error( 'Failed to add language.' );
		}

		if ( isset( $assoc_args['default'] ) ) {
			$repo->set_default( (int) $id );
		}

		\WP_CLI::success( "Language {$locale} added with ID {$id} (direction: {$direction})." );
	}

	/**
	 * Delete a language with safety checks.
	 *
	 * @param string                $slug Language slug.
	 * @param array<string, string> $assoc_args Passed through so `--yes` reaches WP_CLI::confirm().
	 * @return void
	 */
	private function languages_delete( string $slug, array $assoc_args = [] ): void {
		if ( $slug === '' ) {
			\WP_CLI::error( 'Slug is required. Usage: wp perflocale languages delete bg' );
		}

		$cache = $this->plugin->get( 'cache' );
		$repo  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$lang  = $repo->find_by_slug( $slug );

		// Redirect-map fallback: when a user types the OLD slug after the
		// admin has renamed it (e.g. `wp perflocale languages delete en`
		// after `en` was renamed to `en-us`), surface the new slug so the
		// admin doesn't think their language vanished. Same UX pattern as
		// the LanguageRouter cookie fallback.
		if ( ! $lang ) {
			$redirects = LanguageRepository::get_slug_redirects();

			if ( isset( $redirects[ $slug ] ) ) {
				$resolved = (string) $redirects[ $slug ];
				$lang     = $repo->find_by_slug( $resolved );

				if ( $lang ) {
					\WP_CLI::warning(
						"Slug '{$slug}' was renamed to '{$resolved}'. " .
						"Operating on '{$resolved}' instead. " .
						'Update your scripts to use the new slug.'
					);
				}
			}
		}

		if ( ! $lang ) {
			\WP_CLI::error( "Language '{$slug}' not found." );
		}

		if ( (int) $lang->is_default === 1 ) {
			\WP_CLI::error( 'Cannot delete the default language.' );
		}

		// Count translations using this language.
		global $wpdb;

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name bound through the %i identifier placeholder.

		$links_table = Schema::table( 'translation_links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE language_id = %d',
				$links_table,
				(int) $lang->id
			)
		);

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $count > 0 ) {
			\WP_CLI::warning( "{$count} translation links use this language. They will be orphaned." );
		}

		\WP_CLI::confirm( "Delete language '{$slug}' ({$lang->name})?", $assoc_args );

		$repo->delete( (int) $lang->id );
		\WP_CLI::success( "Language '{$slug}' deleted." );
	}

	// =========================================================================
	// Translate (single + bulk)
	// =========================================================================

	/**
	 * Translate posts using machine translation.
	 *
	 * ## OPTIONS
	 *
	 * [<post_id>]
	 * : Single post ID to translate.
	 *
	 * --to=<lang>
	 * : Target language slug.
	 *
	 * [--provider=<provider>]
	 * : Translation provider (deepl, google, microsoft, libretranslate).
	 *
	 * [--post-type=<type>]
	 * : Post type for bulk translation. Default: post.
	 *
	 * [--all]
	 * : Translate all posts of the given post type.
	 *
	 * [--post-ids=<ids>]
	 * : Comma-separated list of post IDs.
	 *
	 * [--skip-existing]
	 * : Skip posts that already have a translation in the target language.
	 *
	 * [--dry-run]
	 * : Estimate characters/cost for the selection; nothing is translated.
	 *
	 * [--async]
	 * : With --all, dispatch the chunked background chain instead of looping
	 * in-process. Track it under PerfLocale → Jobs.
	 *
	 * [--include-meta]
	 * : Also machine-translate registered meta fields (SEO titles etc.).
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale translate 42 --to=fr
	 * wp perflocale translate --all --to=de --post-type=post --skip-existing
	 * wp perflocale translate --post-ids=1,2,3 --to=fr
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function translate( array $args, array $assoc_args ): void {
		$target_lang = $assoc_args['to'] ?? '';
		$provider    = $assoc_args['provider'] ?? '';

		if ( $target_lang === '' ) {
			\WP_CLI::error( 'Target language is required: --to=<lang>' );
		}

		$settings = $this->plugin->get( 'settings' );
		$cache    = $this->plugin->get( 'cache' );

		if ( ! $settings->mt_enabled() ) {
			\WP_CLI::error( 'Machine translation is not enabled. Enable it in Settings → Addons → Machine Translation.' );
		}

		// --dry-run: character/cost estimate only, nothing dispatched, no spend.
		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->translate_dry_run( $args, $assoc_args, $target_lang, $settings );
			return;
		}

		// --async --all: hand the whole selection to the chunked background
		// chain (SiteTranslateJob) instead of looping in-process. Scales to
		// any site size and survives shell disconnects.
		if ( isset( $assoc_args['async'] ) && isset( $assoc_args['all'] ) ) {
			$lang_repo = $this->plugin->get( 'lang_repo' );
			$lang      = $lang_repo->find_by_slug( sanitize_key( $target_lang ) );

			if ( ! $lang ) {
				\WP_CLI::error( "Unknown target language: {$target_lang}" );
			}

			$outcome = \PerfLocale\Background\Dispatcher::dispatch(
				new \PerfLocale\Background\Jobs\SiteTranslateJob(),
				[
					'post_types'      => [ sanitize_key( $assoc_args['post-type'] ?? 'post' ) ],
					'target_lang_ids' => [ (int) $lang->id ],
					'include_meta'    => isset( $assoc_args['include-meta'] ),
					'after_id'        => 0,
				]
			);

			if ( ( $outcome['mode'] ?? '' ) === 'denied' || ( $outcome['mode'] ?? '' ) === 'error' ) {
				\WP_CLI::error( (string) ( $outcome['error'] ?? 'Dispatch failed.' ) );
			}

			\WP_CLI::success( sprintf( 'Site-wide translation chain started (job %s). Track it under PerfLocale → Jobs or `wp perflocale jobs list`.', (string) ( $outcome['job_id'] ?? 'inline' ) ) );
			return;
		}

		// Single post mode.
		if ( ! empty( $args[0] ) ) {
			$this->translate_single( absint( $args[0] ), $target_lang, $provider, $settings, $cache );
			return;
		}

		// Bulk mode.
		$post_ids = [];

		if ( isset( $assoc_args['post-ids'] ) ) {
			$post_ids = array_map( 'absint', explode( ',', $assoc_args['post-ids'] ) );
			$post_ids = array_filter( $post_ids );
		} elseif ( isset( $assoc_args['all'] ) ) {
			$post_type = $assoc_args['post-type'] ?? 'post';
			$paged     = 1;

			do {
				$batch = get_posts(
					[
						'post_type'      => $post_type,
						// Bounded WP-CLI batch loop (paged, id-only) — not a
						// front-end query; a large page keeps the run efficient.
						'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
						'paged'          => $paged++,
						'fields'         => 'ids',
						'post_status'    => 'publish',
						'orderby'        => 'ID',
						'order'          => 'ASC',
					]
				);

				$post_ids       = array_merge( $post_ids, $batch );
				$got_full_batch = ( count( $batch ) === 500 );
			} while ( $got_full_batch );
		}

		if ( empty( $post_ids ) ) {
			\WP_CLI::error( 'No posts to translate. Use <post_id>, --post-ids=1,2,3, or --all --post-type=post.' );
		}

		wp_raise_memory_limit( 'admin' );

		$skip_existing = isset( $assoc_args['skip-existing'] );
		$manager       = new PostTranslationManager( $cache, $settings );
		$service       = new TranslationService( $settings, $cache );
		$progress      = \WP_CLI\Utils\make_progress_bar( "Translating to {$target_lang}", count( $post_ids ) );
		$translated    = 0;
		$skipped       = 0;
		$failed        = 0;

		// Bulk-prime the L1 translation cache for every source post in
		// one SELECT. Without this, get_translation_id() inside the loop
		// pays the cold-path cost (~1-2 ms) for each first-time lookup —
		// linear waste on 200+ post bulk translates. Mirrors the same
		// prime done in BulkTranslateJob and PostListColumns.
		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$repo->prime_translations(
			\PerfLocale\Enum\ObjectType::Post,
			array_map( 'intval', $post_ids )
		);

		// SIGINT (Ctrl-C) trap so a bulk run can be cancelled cleanly with
		// a real summary instead of being killed mid-translate-post leaving
		// the operator wondering how far the import got. The flag is checked
		// at the top of each loop iteration so an interrupt during the call
		// to translate_post() finishes the current post first (no torn
		// writes), then exits with the partial-progress summary. Guarded by
		// function_exists() because some hardened CLI builds compile PHP
		// without pcntl — in that environment Ctrl-C still terminates the
		// process, just without the graceful summary.
		$interrupted = false;

		if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal' ) ) {
			pcntl_async_signals( true );
			pcntl_signal(
				SIGINT,
				static function () use ( &$interrupted ): void {
					$interrupted = true;
				}
			);
		}

		foreach ( $post_ids as $pid ) {
			if ( $interrupted ) {
				break;
			}

			if ( $skip_existing ) {
				$existing = $manager->get_translation_id( $pid, $target_lang );

				if ( $existing !== null ) {
					++$skipped;
					$progress->tick();
					continue;
				}
			}

			try {
				$service->translate_post( $pid, $target_lang, $provider );
				++$translated;
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( "Post {$pid}: " . $e->getMessage() );
				++$failed;
			}

			$progress->tick();
		}

		$progress->finish();

		// CI scripts (and wp.org's own automated tests) rely on the exit
		// code to detect partial-failure: green-pipeline-on-failed-posts
		// silently rots data. Emit the same summary either way so the
		// operator sees the breakdown, then exit non-zero when any post
		// failed OR when the operator interrupted via Ctrl-C (so wrappers
		// don't mistake a cancelled run for a complete one).
		// Skipped (via --skip-existing) does NOT count as failure.
		$summary = "Done. Translated: {$translated}, Skipped: {$skipped}, Failed: {$failed}.";

		if ( $interrupted ) {
			$remaining = count( $post_ids ) - ( $translated + $skipped + $failed );
			\WP_CLI::warning( 'Interrupted by SIGINT — ' . $summary . " Remaining: {$remaining}." );
			\WP_CLI::halt( 130 ); // 128 + SIGINT (2) — conventional shell exit code for Ctrl-C.
		}

		if ( $failed > 0 ) {
			\WP_CLI::warning( $summary );
			\WP_CLI::halt( 1 );
		}

		\WP_CLI::success( $summary );
	}

	/**
	 * Translate a single post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $target_lang Target language.
	 * @param string               $provider Provider ID.
	 * @param \PerfLocale\Settings $settings Settings.
	 * @param CacheManager         $cache Cache.
	 * @return void
	 */
	/**
	 * `wp perflocale translate --dry-run` — estimate characters/cost for the
	 * selection without dispatching anything or spending provider quota.
	 *
	 * @param array<int, string>    $args        Positional args.
	 * @param array<string, mixed>  $assoc_args  Assoc args.
	 * @param string                $target_lang Target language slug.
	 * @param \PerfLocale\Settings $settings    Settings.
	 * @return void
	 */
	private function translate_dry_run( array $args, array $assoc_args, string $target_lang, \PerfLocale\Settings $settings ): void {
		$lang_repo = $this->plugin->get( 'lang_repo' );
		$lang      = $lang_repo->find_by_slug( sanitize_key( $target_lang ) );

		if ( ! $lang ) {
			\WP_CLI::error( "Unknown target language: {$target_lang}" );
		}

		$post_ids = [];

		if ( ! empty( $args[0] ) ) {
			$post_ids = [ absint( $args[0] ) ];
		} elseif ( isset( $assoc_args['post-ids'] ) ) {
			$post_ids = array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['post-ids'] ) ) );
		} elseif ( isset( $assoc_args['all'] ) ) {
			global $wpdb;
			$post_type = sanitize_key( $assoc_args['post-type'] ?? 'post' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $post_type ) ) );
		}

		if ( $post_ids === [] ) {
			\WP_CLI::error( 'No posts selected. Use <post_id>, --post-ids=1,2,3, or --all --post-type=post.' );
		}

		$estimator = new \PerfLocale\MachineTranslation\CostEstimator( $settings );
		$estimate  = $estimator->estimate_posts( $post_ids, [ (int) $lang->id ], isset( $assoc_args['include-meta'] ) );

		\WP_CLI::log( sprintf( 'Items to translate:   %d', (int) $estimate['items'] ) );
		\WP_CLI::log( sprintf( 'Skipped (existing):   %d', (int) $estimate['skipped_existing'] ) );
		\WP_CLI::log( sprintf( 'Estimated characters: %d', (int) $estimate['chars'] ) );
		\WP_CLI::log( sprintf( 'Monthly usage:        %d / %s', (int) $estimate['monthly_used'], $estimate['monthly_limit'] === 0 ? 'unlimited' : (string) $estimate['monthly_limit'] ) );

		if ( ! empty( $estimate['would_exceed'] ) ) {
			\WP_CLI::warning( 'This run would exceed the monthly character limit.' );
		}

		\WP_CLI::success( 'Dry run complete — nothing was translated.' );
	}

	private function translate_single( int $post_id, string $target_lang, string $provider, $settings, CacheManager $cache ): void {
		if ( $post_id === 0 ) {
			\WP_CLI::error( 'Invalid post ID.' );
		}

		$service = new TranslationService( $settings, $cache );

		try {
			\WP_CLI::log( "Translating post {$post_id} to {$target_lang}..." );

			$result = $service->translate_post( $post_id, $target_lang, $provider );

			\WP_CLI::success( sprintf( 'Translated! New post ID: %d', $result['post_id'] ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'Translation failed: ' . $e->getMessage() );
		}
	}

	// =========================================================================
	// Translations management
	// =========================================================================

	/**
	 * Manage post translations.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Subcommand (list, create, delete, set-language).
	 *
	 * <post_id>
	 * : Source post ID the subcommand operates on.
	 *
	 * [--to=<lang>]
	 * : Target language (create).
	 *
	 * [--lang=<lang>]
	 * : Language slug (delete, set-language).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt when deleting.
	 *
	 * [--format=<format>]
	 * : Output format for `list`. Accepted: table, json, csv, yaml, count, ids.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp perflocale translations list 42
	 *     wp perflocale translations list 42 --format=json
	 *     wp perflocale translations create 42 --to=de
	 *     wp perflocale translations delete 42 --lang=de --yes
	 *     wp perflocale translations set-language 42 --lang=en
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function translations( array $args, array $assoc_args ): void {
		$subcommand = $args[0] ?? '';
		$post_id    = absint( $args[1] ?? 0 );

		if ( $post_id === 0 && $subcommand !== '' ) {
			\WP_CLI::error( 'Post ID is required. Usage: wp perflocale translations <subcommand> <post_id>' );
		}

		$cache    = $this->plugin->get( 'cache' );
		$settings = $this->plugin->get( 'settings' );
		$manager  = new PostTranslationManager( $cache, $settings );

		switch ( $subcommand ) {
			case 'list':
				$translations = $manager->get_translations( $post_id );
				$post_lang    = $manager->detect_post_language( $post_id );

				// Prime the WP per-post cache in ONE round-trip so the
				// get_post() inside the loop is an in-memory lookup
				// rather than a SELECT per translation. Same pattern
				// XliffExporter / PostListColumns already use.
				if ( ! empty( $translations ) && function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( array_map( 'intval', array_values( $translations ) ), false, false );
				}

				$items = [];

				foreach ( $translations as $lang_slug => $tid ) {
					$post    = get_post( $tid );
					$items[] = [
						'Language' => \PerfLocale\Helper::format_locale_as_bcp47( $lang_slug ) . ( $post_lang && $post_lang->slug === $lang_slug ? ' *' : '' ),
						'Post ID'  => $tid,
						'Title'    => $post ? $post->post_title : '(deleted)',
						'Status'   => $post ? $post->post_status : '-',
					];
				}

				$format = $assoc_args['format'] ?? 'table';

				if ( empty( $items ) && in_array( $format, [ 'table' ], true ) ) {
					\WP_CLI::log( 'No translations found for this post.' );
					return;
				}

				if ( 'ids' === $format ) {
					// format_items() can't render 'ids' from associative rows
					// (it implode()s each row → "Array"). Emit the Post ID
					// column directly so `--format=ids` stays scriptable.
					\WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'Post ID' ) ) );
					break;
				}

				\WP_CLI\Utils\format_items( $format, $items, [ 'Language', 'Post ID', 'Title', 'Status' ] );
				break;

			case 'create':
				$to = $assoc_args['to'] ?? '';

				if ( $to === '' ) {
					\WP_CLI::error( 'Target language required: --to=<lang>' );
				}

				$new_id = $manager->create_translation( $post_id, $to, true );

				if ( $new_id === false ) {
					\WP_CLI::error( 'Failed to create translation.' );
				}

				\WP_CLI::success( "Translation created. New post ID: {$new_id}" );
				break;

			case 'delete':
				$lang = $assoc_args['lang'] ?? '';

				if ( $lang === '' ) {
					\WP_CLI::error( 'Language required: --lang=<slug>' );
				}

				$translated_id = $manager->get_translation_id( $post_id, $lang );

				if ( ! $translated_id ) {
					\WP_CLI::error( "No {$lang} translation found for post {$post_id}." );
				}

				\WP_CLI::confirm( "Delete {$lang} translation (post {$translated_id})?", $assoc_args );

				wp_delete_post( $translated_id, true );
				\WP_CLI::success( "Translation deleted (post {$translated_id})." );
				break;

			case 'set-language':
				$lang = $assoc_args['lang'] ?? '';

				if ( $lang === '' ) {
					\WP_CLI::error( 'Language required: --lang=<slug>' );
				}

				$result = $manager->set_post_language( $post_id, $lang );

				if ( ! $result ) {
					\WP_CLI::error( 'Failed to set language. The language may already be used by a sibling translation.' );
				}

				\WP_CLI::success( "Post {$post_id} language set to {$lang}." );
				break;

			default:
				\WP_CLI::error( 'Unknown subcommand. Available: list, create, delete, set-language' );
		}
	}

	// =========================================================================
	// Strings
	// =========================================================================

	/**
	 * Manage translatable strings.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Subcommand (scan).
	 *
	 * [--domain=<domain>]
	 * : Filter by text domain.
	 *
	 * [--dir=<dir>]
	 * : Directory to scan (default: active theme). Named --dir, not --path:
	 * wp-cli extracts its GLOBAL --path (the WordPress root) from anywhere
	 * on the command line, so a subcommand --path flag can never reach the
	 * command — wp-cli consumes it and tries to bootstrap WordPress from
	 * the scan directory instead.
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function strings( array $args, array $assoc_args ): void {
		$subcommand = $args[0] ?? 'scan';

		if ( $subcommand !== 'scan' ) {
			\WP_CLI::error( "Unknown subcommand: {$subcommand}. Available: scan" );
		}

		$cache   = $this->plugin->get( 'cache' );
		$scanner = new StringScanner( $cache );
		$domain  = $assoc_args['domain'] ?? '';
		$path    = $assoc_args['dir'] ?? get_stylesheet_directory();

		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( "Path is not a readable directory: {$path}" );
		}

		\WP_CLI::log( "Scanning {$path}..." );

		$result = $scanner->scan( $path, $domain );

		\WP_CLI::success(
			sprintf(
				'Found %d strings, inserted %d new.',
				$result['found'],
				$result['inserted']
			)
		);
	}

	// =========================================================================
	// Slugs
	// =========================================================================

	/**
	 * Manage slug translations.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Subcommand (backfill, verify).
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale slugs backfill
	 * wp perflocale slugs verify
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function slugs( array $args, array $assoc_args ): void {
		$subcommand = $args[0] ?? '';
		$cache      = $this->plugin->get( 'cache' );

		switch ( $subcommand ) {
			case 'backfill':
				$manager = new TermTranslationManager( $cache );
				$count   = $manager->backfill_slug_translations();
				\WP_CLI::success( "Backfilled {$count} slug translation entries." );
				break;

			case 'verify':
				$this->slugs_verify( $cache );
				break;

			default:
				\WP_CLI::error( 'Unknown subcommand. Available: backfill, verify' );
		}
	}

	/**
	 * Verify slug translations and report missing entries.
	 *
	 * @param CacheManager $cache Cache manager — accepted for signature parity
	 *                           with sibling slugs_* subcommands; verification
	 *                           reads directly from the DB.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature parity with sibling slugs_* subcommands.
	private function slugs_verify( CacheManager $cache ): void {
		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$slugs_table  = Schema::table( 'slug_translations' );

		// Find terms in translation groups that lack slug translations.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table() are safe constants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$missing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT l.object_id)
				FROM %i l
				INNER JOIN %i g ON g.id = l.group_id AND g.type = 'term'
				LEFT JOIN %i st ON st.object_id = l.object_id AND st.object_type = 'term' AND st.language_id = l.language_id
				WHERE st.id IS NULL",
				$links_table,
				$groups_table,
				$slugs_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$total = (int) $missing;

		if ( $total === 0 ) {
			\WP_CLI::success( 'All term translations have slug entries.' );
		} else {
			\WP_CLI::warning( "{$total} terms are missing slug translations. Run 'wp perflocale slugs backfill' to fix." );
		}
	}

	// =========================================================================
	// Cache
	// =========================================================================

	/**
	 * Manage PerfLocale caches.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Subcommand (flush).
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function cache( array $args, array $assoc_args ): void {
		$subcommand = $args[0] ?? 'flush';

		if ( $subcommand === 'flush' ) {
			$cache = $this->plugin->get( 'cache' );
			$cache->flush_all();

			// Also invalidate the AddonRegistry bootable-set transient so
			// next request re-runs every compat check. Cheap (one option
			// row delete) and keeps `cache flush` doing what operators
			// expect: a full reset of every cached decision the plugin
			// makes.
			\PerfLocale\Addon\AddonRegistry::flush_bootable_cache();

			\WP_CLI::success( 'All PerfLocale caches flushed.' );
		} else {
			\WP_CLI::error( "Unknown subcommand: {$subcommand}. Available: flush" );
		}
	}

	// =========================================================================
	// Export / Import
	// =========================================================================

	/**
	 * Export PerfLocale data to a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Output file path (e.g. backup.json).
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale export /tmp/perflocale-backup.json
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		$file = $args[0] ?? '';

		if ( $file === '' ) {
			\WP_CLI::error( 'Output file path required. Usage: wp perflocale export <file>' );
		}

		$dir = dirname( $file );

		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			\WP_CLI::error( "Directory is not writable: {$dir}" );
		}

		\WP_CLI::log( 'Exporting PerfLocale data...' );

		$exporter = new \PerfLocale\Admin\DataExporter();

		// Stream directly to disk. write_to_file() preserves the streaming
		// memory profile and returns without exiting - the old `ob_start`
		// approach silently failed because DataExporter::download() calls
		// exit; which means ob_get_clean() never ran and no file was written.
		// Empty array = every section the current build knows about, so new
		// sections (e.g. `roles` added in v1.0) ship automatically without
		// touching this command.
		$bytes = $exporter->write_to_file( $file, [] );

		if ( $bytes === false || $bytes === 0 ) {
			\WP_CLI::error( "Failed to write to: {$file}" );
		}

		$size_kb = round( $bytes / 1024, 1 );
		\WP_CLI::success( "Exported to {$file} ({$size_kb} KB)." );
	}

	/**
	 * Import PerfLocale data from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Input file path (PerfLocale export JSON).
	 *
	 * [--mode=<mode>]
	 * : Import mode: merge (default) or replace.
	 * ---
	 * default: merge
	 * options:
	 *   - merge
	 *   - replace
	 * ---
	 *
	 * [--yes]
	 * : Skip the confirmation prompt shown in replace mode.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale import /tmp/perflocale-backup.json
	 * wp perflocale import /tmp/backup.json --mode=replace --yes
	 *
	 * @subcommand import
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function import_data( array $args, array $assoc_args ): void {
		$file = $args[0] ?? '';
		$mode = $assoc_args['mode'] ?? 'merge';

		if ( $file === '' ) {
			\WP_CLI::error( 'Input file path required. Usage: wp perflocale import <file>' );
		}

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			\WP_CLI::error( "File not found or not readable: {$file}" );
		}

		$replace = ( $mode === 'replace' );

		if ( $replace ) {
			\WP_CLI::confirm( 'Replace mode will DELETE all existing PerfLocale data. Continue?', $assoc_args );
		}

		\WP_CLI::log( "Importing from {$file} (mode: {$mode})..." );

		$importer = new \PerfLocale\Admin\DataImporter();
		$result   = $importer->import( $file, $replace );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				\WP_CLI::warning( $error );
			}

			// Exit non-zero. `errors` only ever carries REAL failures - a
			// rejected file, a data-quality refusal, or a row the database
			// returned an error for; ordinary duplicate-key merge skips
			// never land here. A restore script that reads only the exit
			// code must not read "Success:" after an import that changed
			// nothing, or it will tick the restore off as done.
			\WP_CLI::error(
				sprintf(
					'Import finished with %d error(s). Imported: %d, Skipped: %d.',
					count( $result['errors'] ),
					$result['imported'],
					$result['skipped']
				)
			);
		}

		\WP_CLI::success(
			sprintf(
				'Import complete. Imported: %d, Skipped: %d.',
				$result['imported'] ?? 0,
				$result['skipped'] ?? 0
			)
		);
	}



	/**
	 * Export every site's PerfLocale data into a single network-wide JSON.
	 *
	 * Iterates each site in the network with switch_to_blog(), runs the
	 * regular DataExporter against it, and concatenates the results under
	 * one envelope. Handy for backing up a translation network to one file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Output file path.
	 *
	 * [--include-inactive]
	 * : Also export sites where the plugin isn't active. Default: skip them.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale network-export /tmp/network.json
	 *
	 * @subcommand network-export
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 * @return void
	 */
	public function network_export( array $args, array $assoc_args ): void {
		if ( ! is_multisite() ) {
			\WP_CLI::error( 'network-export requires a multisite install. Use `wp perflocale export` for single sites.' );
		}

		$file = $args[0] ?? '';

		if ( $file === '' ) {
			\WP_CLI::error( 'Usage: wp perflocale network-export <file> [--include-inactive]' );
		}

		$dir = dirname( $file );
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			\WP_CLI::error( "Directory is not writable: {$dir}" );
		}

		$include_inactive = isset( $assoc_args['include-inactive'] );
		$plugin_basename  = plugin_basename( PERFLOCALE_FILE );

		$site_ids       = get_sites(
			[
				'fields'     => 'ids',
				// Every site, in one go. The ID list is ints and is dwarfed by
				// the `$envelope` this command builds below, which holds every
				// site's decoded export in memory at once - chunking the ID
				// query would not bound the command's footprint, so it would
				// buy nothing here.
				'number'     => 0,
				// The network WP-CLI is pointed at (via --url), not every
				// network on the installation: WP_Site_Query defaults
				// `network_id` to 0, which core documents as "all networks".
				// Unscoped, sibling networks' sites land inside an envelope
				// stamped with THIS network's `network_url`, and the
				// is_plugin_active_for_network() check below reads this
				// network's option, so they'd also be included or skipped on
				// the wrong network's plugin state.
				'network_id' => get_current_network_id(),
				// Core's default; stated explicitly so the export order can't
				// change under a later edit.
				'orderby'    => 'id',
			]
		);
		$exported_count = 0;

		// Build the envelope as one document. We don't use DataExporter's
		// streaming API here because we need the per-site sections nested,
		// not concatenated; the streaming approach gains us nothing on a
		// network-wide JSON that's the sum of per-site dumps anyway.
		$envelope = [
			'perflocale_export' => true,
			'network'           => true,
			'format_version'    => \PerfLocale\Admin\DataExporter::FORMAT_VERSION,
			'version'           => PERFLOCALE_VERSION,
			'exported_at'       => gmdate( 'c' ),
			'network_url'       => network_home_url(),
			'sites'             => [],
		];

		foreach ( $site_ids as $site_id ) {
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );

			try {
				if ( ! $include_inactive && ! is_plugin_active( $plugin_basename )
					&& ! is_plugin_active_for_network( $plugin_basename ) ) {
					\WP_CLI::log( "Skipping site {$site_id}: PerfLocale not active." );
					continue;
				}

				// Stream per-site export to a temp file, then read it back
				// as JSON to nest under sites[]. This keeps the existing
				// DataExporter contract (single-site only) untouched.
				$tmp      = wp_tempnam( "pl-net-{$site_id}-" );
				$exporter = new \PerfLocale\Admin\DataExporter();
				$bytes    = $exporter->write_to_file( $tmp, [] );

				if ( $bytes === false || $bytes <= 0 ) {
					\WP_CLI::warning( "Failed to export site {$site_id}; skipping." );
					wp_delete_file( $tmp );
					continue;
				}

				$fs       = \PerfLocale\Helper::filesystem();
				$contents = $fs ? $fs->get_contents( $tmp ) : false;
				wp_delete_file( $tmp );

				if ( $contents === false ) {
					\WP_CLI::warning( "Failed to read site {$site_id} export; skipping." );
					continue;
				}

				$decoded = json_decode( (string) $contents, true );

				if ( ! is_array( $decoded ) ) {
					\WP_CLI::warning( "Site {$site_id} export decoded to non-array; skipping." );
					continue;
				}

				$envelope['sites'][ (string) $site_id ] = [
					'site_url' => home_url(),
					'export'   => $decoded,
				];
				++$exported_count;
			} finally {
				restore_current_blog();
			}
		}

		$json = wp_json_encode( $envelope, JSON_PRETTY_PRINT );

		if ( $json === false ) {
			\WP_CLI::error( 'Failed to encode network envelope.' );
		}

		$fs = \PerfLocale\Helper::filesystem();

		if ( ! $fs || ! $fs->put_contents( $file, $json, FS_CHMOD_FILE ) ) {
			\WP_CLI::error( "Failed to write to: {$file}" );
		}

		// WP_Filesystem doesn't return the byte count from put_contents, so
		// stat the freshly-written file to report size.
		$bytes = (int) $fs->size( $file );
		$kb    = round( $bytes / 1024, 1 );
		\WP_CLI::success( "Network export: {$exported_count} site(s) → {$file} ({$kb} KB)." );
	}

	/**
	 * Import a network-export JSON across the matching sites.
	 *
	 * For each site_id in the envelope, switches to that site and runs the
	 * DataImporter against its slice. Sites in the envelope that no longer
	 * exist on the receiving network are skipped with a warning.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Input file path (network-export JSON).
	 *
	 * [--mode=<mode>]
	 * : merge or replace. Default: merge.
	 * ---
	 * default: merge
	 * options:
	 *   - merge
	 *   - replace
	 * ---
	 *
	 * [--site=<id>]
	 * : Restore only one source site_id (e.g. when migrating one site to a
	 *   smaller network). The CURRENT blog receives the data.
	 *
	 * [--force]
	 * : Import a slice even when its recorded site_url does not match the
	 *   target blog. Use only when the blog IDs are known-correct (e.g. after
	 *   a domain migration); by default a mismatched slice is skipped because
	 *   its translation links reference site-specific post/term IDs.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale network-import /tmp/network.json
	 * wp perflocale network-import /tmp/network.json --mode=replace
	 * wp perflocale network-import /tmp/network.json --site=42  # into current blog
	 *
	 * @subcommand network-import
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 * @return void
	 */
	public function network_import( array $args, array $assoc_args ): void {
		if ( ! is_multisite() && ! isset( $assoc_args['site'] ) ) {
			\WP_CLI::error( 'network-import on a single-site install requires --site=<id> to pick which slice to use.' );
		}

		$file = $args[0] ?? '';

		if ( $file === '' || ! file_exists( $file ) || ! is_readable( $file ) ) {
			\WP_CLI::error( 'File not readable. Usage: wp perflocale network-import <file> [--mode=replace] [--site=<id>]' );
		}

		$fs       = \PerfLocale\Helper::filesystem();
		$contents = $fs ? $fs->get_contents( $file ) : false;

		if ( $contents === false ) {
			\WP_CLI::error( "Failed to read: {$file}" );
		}

		$decoded = json_decode( (string) $contents, true );

		if ( ! is_array( $decoded ) || empty( $decoded['network'] ) || empty( $decoded['sites'] ) ) {
			\WP_CLI::error( 'File is not a network-export envelope (missing `network: true` or `sites`).' );
		}

		$mode    = $assoc_args['mode'] ?? 'merge';
		$replace = ( $mode === 'replace' );

		if ( $replace ) {
			// Pass the full assoc_args through so WP-CLI's global `--yes`
			// flag bypasses the prompt in scripted contexts (CI, tests,
			// headless pipelines). Without this propagation, automation
			// would hang indefinitely on the prompt.
			\WP_CLI::confirm( 'Replace mode will WIPE matching data on every targeted site. Continue?', $assoc_args );
		}

		$only_site      = isset( $assoc_args['site'] ) ? (string) (int) $assoc_args['site'] : '';
		$total_imported = 0;
		$total_errors   = 0;
		$importer       = new \PerfLocale\Admin\DataImporter();

		foreach ( $decoded['sites'] as $source_site_id => $slice ) {
			if ( $only_site !== '' && (string) $source_site_id !== $only_site ) {
				continue;
			}

			if ( ! is_array( $slice ) || empty( $slice['export'] ) || ! is_array( $slice['export'] ) ) {
				\WP_CLI::warning( "Site {$source_site_id} slice missing 'export'; skipping." );
				continue;
			}

			// Single-site mode with --site=<id>: import into current blog,
			// don't switch_to_blog. Multisite without --site: target the
			// matching site_id on this network.
			$switched = false;

			if ( $only_site === '' ) {
				if ( ! get_blog_details( (int) $source_site_id ) ) {
					\WP_CLI::warning( "Site {$source_site_id} doesn't exist on this network; skipping." );
					continue;
				}
				switch_to_blog( (int) $source_site_id );
				$switched = true;
			}

			// Blog IDs are matched positionally, but they are NOT stable across
			// networks — importing a backup taken on a DIFFERENT network can land
			// a slice on the wrong site, whose translation_links/slug_translations
			// then reference alien post/term IDs (corruption). Compare the slice's
			// recorded site_url (host + path, scheme-agnostic) against the target
			// blog's home_url(); on mismatch skip unless --force. A domain-migrated
			// network mismatches uniformly while IDs stay valid, so --force is the
			// operator's explicit "IDs are still correct" override.
			$slice_url = isset( $slice['export']['site_url'] ) ? (string) $slice['export']['site_url'] : '';

			if ( $slice_url !== '' && empty( $assoc_args['force'] ) ) {
				$want = wp_parse_url( $slice_url );
				$have = wp_parse_url( home_url() );
				$norm = static function ( $u ): string {
					return strtolower( (string) ( $u['host'] ?? '' ) ) . untrailingslashit( (string) ( $u['path'] ?? '' ) );
				};

				if ( $norm( $want ) !== $norm( $have ) ) {
					\WP_CLI::warning(
						sprintf(
							'Site %1$s: export site_url (%2$s) does not match target (%3$s); skipping. Pass --force if the blog IDs are correct (e.g. after a domain migration).',
							$source_site_id,
							$slice_url,
							home_url()
						)
					);

					if ( $switched ) {
						restore_current_blog();
					}

					++$total_errors;
					continue;
				}
			}

			try {
				// Write the slice to a temp file because DataImporter's
				// public API takes a file path (it streams large JSON via
				// the WP_Filesystem layer, not in-memory parse).
				$tmp      = wp_tempnam( "pl-netimp-{$source_site_id}-" );
				$slice_fs = \PerfLocale\Helper::filesystem();
				$slice_ok = $slice_fs && $slice_fs->put_contents(
					$tmp,
					(string) wp_json_encode( $slice['export'] ),
					FS_CHMOD_FILE
				);

				if ( ! $slice_ok ) {
					wp_delete_file( $tmp );
					\WP_CLI::warning( "Failed to stage slice for site {$source_site_id}; skipping." );
					continue;
				}

				$result = $importer->import( $tmp, $replace );
				wp_delete_file( $tmp );

				$total_imported += (int) ( $result['imported'] ?? 0 );
				$total_errors   += count( $result['errors'] ?? [] );

				foreach ( $result['errors'] ?? [] as $err ) {
					\WP_CLI::warning( "Site {$source_site_id}: {$err}" );
				}

				\WP_CLI::log(
					sprintf(
						'Site %s: %d imported, %d skipped, %d errors.',
						$source_site_id,
						(int) ( $result['imported'] ?? 0 ),
						(int) ( $result['skipped'] ?? 0 ),
						count( $result['errors'] ?? [] )
					)
				);
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		if ( $total_errors > 0 ) {
			// Per-site slices that were deliberately skipped (missing blog,
			// absent `export` key) are logged as warnings and are NOT counted
			// in $total_errors - only real per-row import failures are, so a
			// non-zero count always means data did not land.
			\WP_CLI::error( "Network import finished with {$total_errors} error(s). Imported across sites: {$total_imported}." );
		}

		\WP_CLI::success( "Network import done. Imported across sites: {$total_imported}; total errors: {$total_errors}." );
	}

	/**
	 * Export string translations as a gettext PO file (one language).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Output PO path.
	 *
	 * --lang=<slug>
	 * : Target language slug (matches the language's slug column, e.g. `fr`).
	 *
	 * [--domain=<domain>]
	 * : Restrict export to one translation domain. Default: all domains.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale po-export /tmp/fr.po --lang=fr
	 * wp perflocale po-export /tmp/fr-myplugin.po --lang=fr --domain=my-plugin
	 *
	 * @subcommand po-export
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 * @return void
	 */
	public function po_export( array $args, array $assoc_args ): void {
		$file   = $args[0] ?? '';
		$lang   = $assoc_args['lang'] ?? '';
		$domain = $assoc_args['domain'] ?? '';

		if ( $file === '' || $lang === '' ) {
			\WP_CLI::error( 'Usage: wp perflocale po-export <file> --lang=<slug> [--domain=<domain>]' );
		}

		$dir = dirname( $file );
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			\WP_CLI::error( "Directory is not writable: {$dir}" );
		}

		$bytes = \PerfLocale\Admin\PoSync::export_to_file( $file, $lang, $domain );

		if ( $bytes === false ) {
			\WP_CLI::error( "PO export failed (unknown lang slug or write error): {$file}" );
		}

		$kb = round( ( (int) $bytes ) / 1024, 1 );
		\WP_CLI::success( "PO exported to {$file} ({$kb} KB)." );
	}

	/**
	 * Import string translations from a gettext PO file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Input PO path.
	 *
	 * --lang=<slug>
	 * : Target language slug.
	 *
	 * [--replace]
	 * : Wipe every existing translation for this language before importing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt shown in --replace mode.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale po-import /tmp/fr.po --lang=fr
	 * wp perflocale po-import /tmp/fr.po --lang=fr --replace --yes
	 *
	 * @subcommand po-import
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 * @return void
	 */
	public function po_import( array $args, array $assoc_args ): void {
		$file = $args[0] ?? '';
		$lang = $assoc_args['lang'] ?? '';

		if ( $file === '' || $lang === '' ) {
			\WP_CLI::error( 'Usage: wp perflocale po-import <file> --lang=<slug> [--replace]' );
		}

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			\WP_CLI::error( "File not found: {$file}" );
		}

		$replace = isset( $assoc_args['replace'] );

		if ( $replace ) {
			\WP_CLI::confirm( "Replace mode will delete every existing {$lang} translation. Continue?", $assoc_args );
		}

		$result = \PerfLocale\Admin\PoSync::import_from_file( $file, $lang, $replace );

		foreach ( $result['errors'] as $err ) {
			\WP_CLI::warning( $err );
		}

		// Same automation contract as `wp perflocale import`: a malformed or
		// unreadable PO that changed nothing must not exit 0.
		if ( ! empty( $result['errors'] ) ) {
			\WP_CLI::error(
				sprintf(
					'PO import finished with %d error(s). Imported: %d, Skipped: %d.',
					count( $result['errors'] ),
					$result['imported'],
					$result['skipped']
				)
			);
		}

		\WP_CLI::success(
			sprintf(
				'PO import complete. Imported: %d, Skipped: %d.',
				$result['imported'],
				$result['skipped']
			)
		);
	}

	// =========================================================================
	// Migrate
	// =========================================================================

	/**
	 * Migrate translation data from another plugin.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source plugin (wpml, polylang, translatepress).
	 *
	 * [--dry-run]
	 * : Show what would be imported without making changes.
	 *
	 * [--yes]
	 * : Skip the "this cannot be undone" confirmation prompt.
	 *
	 * [--force-restart]
	 * : Clear the migration source-map for <source> before importing. Use
	 * after a deliberate DB restore to a known-good pre-migration state
	 * when you want re-import to allocate fresh translation_groups rather
	 * than reuse mappings from the prior run. WITHOUT this flag, re-import
	 * looks up the existing (migration_type, source_key) → group_id
	 * mapping and reuses it (the disaster-recovery default) — running
	 * --force-restart on a database whose translation_groups already
	 * point at the source plugin's data WILL orphan those groups.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale migrate wpml
	 * wp perflocale migrate polylang
	 * wp perflocale migrate translatepress
	 * wp perflocale migrate translatepress --dry-run
	 * wp perflocale migrate polylang --yes
	 * wp perflocale migrate wpml --force-restart --yes
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$source        = strtolower( $args[0] ?? '' );
		$dry_run       = isset( $assoc_args['dry-run'] );
		$force_restart = isset( $assoc_args['force-restart'] );
		$cache         = $this->plugin->get( 'cache' );

		$importer = match ( $source ) {
			'wpml' => new \PerfLocale\Migration\WpmlImporter( $cache ),
			'polylang' => new \PerfLocale\Migration\PolylangImporter( $cache ),
			'translatepress' => new \PerfLocale\Migration\TranslatePressImporter( $cache ),
			default => null,
		};

		if ( $importer === null ) {
			\WP_CLI::error( "Unknown source: {$source}. Available: wpml, polylang, translatepress" );
		}

		if ( ! $importer->can_import() ) {
			\WP_CLI::error( ucfirst( $source ) . ' data not detected. Make sure the plugin was active and has translation data.' );
		}

		if ( $dry_run ) {
			\WP_CLI::log( "Dry run - checking {$source} data..." );
			if ( $force_restart ) {
				\WP_CLI::log( '(--force-restart would also clear the source-map for this importer.)' );
			}
			\WP_CLI::success( ucfirst( $source ) . ' data detected and ready to import. Run without --dry-run to proceed.' );
			return;
		}

		\WP_CLI::confirm( "Migrate translation data from {$source}? This cannot be undone.", $assoc_args );

		// --force-restart: wipe the source-map for this importer so the
		// next pass allocates fresh translation_groups rather than
		// reusing the mapping from a prior (now-restored-over) run. The
		// CLI source string for TranslatePress is 'translatepress' but
		// the migration_type stored in the source_map is 'trp' — see
		// TranslatePressImporter::import().
		if ( $force_restart ) {
			$migration_type = $source === 'translatepress' ? 'trp' : $source;
			$source_map     = new \PerfLocale\Database\Repository\MigrationSourceMapRepository();
			$cleared        = $source_map->delete_for_type( $migration_type );
			\WP_CLI::log( sprintf( '--force-restart: cleared %d source-map row(s) for %s.', $cleared, $source ) );

			// TranslatePress also keeps a per-post resume checkpoint; without
			// clearing it, a post-restore re-import silently skips every source
			// post at/below the stale cursor (the source-map clear is not enough).
			if ( $source === 'translatepress' ) {
				delete_option( \PerfLocale\Migration\TranslatePressImporter::POST_CHECKPOINT_OPTION );
				\WP_CLI::log( '--force-restart: cleared TranslatePress post checkpoint.' );
			}
		}

		\WP_CLI::log( "Migrating from {$source}..." );

		$result = $importer->import();

		// Same post-import flush the admin migration jobs run: purges every
		// stale cache/eager-map AND — in files mode (the default) —
		// regenerates the .l10n.php bundles. Without it, CLI-migrated
		// string translations sat in the DB while the frontend kept serving
		// the pre-migration files until an unrelated regeneration.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				\WP_CLI::warning( $error );
			}
		}

		$parts = [];

		if ( isset( $result['posts'] ) && $result['posts'] > 0 ) {
			$parts[] = "{$result['posts']} posts";
		}

		if ( isset( $result['terms'] ) && $result['terms'] > 0 ) {
			$parts[] = "{$result['terms']} terms";
		}

		if ( isset( $result['strings'] ) && $result['strings'] > 0 ) {
			$parts[] = "{$result['strings']} strings";
		}

		if ( isset( $result['slugs'] ) && $result['slugs'] > 0 ) {
			$parts[] = "{$result['slugs']} slugs";
		}

		$summary = ! empty( $parts ) ? implode( ', ', $parts ) : '0 items';

		\WP_CLI::success( "Migration complete. Imported: {$summary}." );
	}

	// =========================================================================
	// Health Check
	// =========================================================================

	/**
	 * Run database integrity checks.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Automatically fix found issues.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale health-check
	 * wp perflocale health-check --fix
	 *
	 * @subcommand health-check
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function health_check( array $args, array $assoc_args ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table() are safe constants.
		global $wpdb;

		$fix = isset( $assoc_args['fix'] );

		$links_table   = Schema::table( 'translation_links' );
		$groups_table  = Schema::table( 'translation_groups' );
		$lang_table    = Schema::table( 'languages' );
		$strings_table = Schema::table( 'strings' );
		$issues        = 0;

		\WP_CLI::log( 'Running PerfLocale health checks...' );
		\WP_CLI::log( '' );

		// 1. Orphaned post translation links (post deleted but link remains).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphaned_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i l
				INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post'
				LEFT JOIN %i p ON p.ID = l.object_id
				WHERE p.ID IS NULL",
				$links_table,
				$groups_table,
				$wpdb->posts
			)
		);

		if ( $orphaned_posts > 0 ) {
			\WP_CLI::warning( " Orphaned post links: {$orphaned_posts}" );
			$issues += $orphaned_posts;

			if ( $fix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hardcoded cleanup query with no user input; table names from Schema::table().
				$wpdb->query(
					$wpdb->prepare(
						"DELETE l FROM %i l
						INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post'
						LEFT JOIN %i p ON p.ID = l.object_id
						WHERE p.ID IS NULL",
						$links_table,
						$groups_table,
						$wpdb->posts
					)
				);
				\WP_CLI::log( " Fixed: removed {$orphaned_posts} orphaned post links." );
			}
		} else {
			\WP_CLI::log( ' Orphaned post links: 0 ✓' );
		}

		// 2. Orphaned term translation links (term deleted but link remains).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphaned_terms = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i l
				INNER JOIN %i g ON g.id = l.group_id AND g.type = 'term'
				LEFT JOIN %i t ON t.term_id = l.object_id
				WHERE t.term_id IS NULL",
				$links_table,
				$groups_table,
				$wpdb->terms
			)
		);

		if ( $orphaned_terms > 0 ) {
			\WP_CLI::warning( " Orphaned term links: {$orphaned_terms}" );
			$issues += $orphaned_terms;

			if ( $fix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hardcoded cleanup query with no user input; table names from Schema::table().
				$wpdb->query(
					$wpdb->prepare(
						"DELETE l FROM %i l
						INNER JOIN %i g ON g.id = l.group_id AND g.type = 'term'
						LEFT JOIN %i t ON t.term_id = l.object_id
						WHERE t.term_id IS NULL",
						$links_table,
						$groups_table,
						$wpdb->terms
					)
				);
				\WP_CLI::log( " Fixed: removed {$orphaned_terms} orphaned term links." );
			}
		} else {
			\WP_CLI::log( ' Orphaned term links: 0 ✓' );
		}

		// 3. Links referencing non-existent languages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bad_langs = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i l
				LEFT JOIN %i lang ON lang.id = l.language_id
				WHERE lang.id IS NULL',
				$links_table,
				$lang_table
			)
		);

		if ( $bad_langs > 0 ) {
			\WP_CLI::warning( " Links with invalid language: {$bad_langs}" );
			$issues += $bad_langs;

			if ( $fix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hardcoded cleanup query with no user input; table names from Schema::table().
				$wpdb->query(
					$wpdb->prepare(
						'DELETE l FROM %i l
						LEFT JOIN %i lang ON lang.id = l.language_id
						WHERE lang.id IS NULL',
						$links_table,
						$lang_table
					)
				);
				\WP_CLI::log( " Fixed: removed {$bad_langs} links with invalid languages." );
			}
		} else {
			\WP_CLI::log( ' Invalid language references: 0 ✓' );
		}

		// 4. Widow translation groups — non-string groups with zero links
		// (from manual SQL or 3rd-party code moving an object between groups
		// without deleting the source). String-type groups are excluded:
		// StringRepository manages their lifecycle and a momentarily-empty
		// string group is a valid transitional state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$empty_groups = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i g
				LEFT JOIN %i l ON l.group_id = g.id
				WHERE l.id IS NULL AND g.type != 'string'",
				$groups_table,
				$links_table
			)
		);

		if ( $empty_groups > 0 ) {
			\WP_CLI::warning( " Widow translation groups (non-string): {$empty_groups}" );
			$issues += $empty_groups;

			if ( $fix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hardcoded cleanup query with no user input; table names from Schema::table().
				$wpdb->query(
					$wpdb->prepare(
						"DELETE g FROM %i g
						LEFT JOIN %i l ON l.group_id = g.id
						WHERE l.id IS NULL AND g.type != 'string'",
						$groups_table,
						$links_table
					)
				);
				\WP_CLI::log( " Fixed: removed {$empty_groups} widow groups." );
			}
		} else {
			\WP_CLI::log( ' Widow translation groups: 0 ✓' );
		}

		// 4b. Orphan string-type groups - a 'string' group whose owning row in
		// the strings table is gone. These never carry translation_links (their
		// translations live in string_translations), so the widow sweep above
		// deliberately skips them - but a string deleted without cascading its
		// group leaves the group behind forever. Sweep groups with no string.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$orphan_string_groups = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i g
				WHERE g.type = 'string'
				AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )",
				$groups_table,
				$strings_table
			)
		);

		if ( $orphan_string_groups > 0 ) {
			\WP_CLI::warning( " Orphan string groups (no owning string): {$orphan_string_groups}" );
			$issues += $orphan_string_groups;

			if ( $fix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query(
					$wpdb->prepare(
						"DELETE g FROM %i g
						WHERE g.type = 'string'
						AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )",
						$groups_table,
						$strings_table
					)
				);
				\WP_CLI::log( " Fixed: removed {$orphan_string_groups} orphan string groups." );
			}
		} else {
			\WP_CLI::log( ' Orphan string groups: 0 ✓' );
		}

		// 5. Orphan translation_links - links pointing at a group_id that
		// no longer exists in translation_groups. Different from checks 1/2/3
		// which catch links whose OBJECT (post/term) or LANGUAGE was deleted.
		// This one catches the case where the whole GROUP vanished via a
		// direct DELETE that forgot to cascade to its links - historically
		// common in test cleanup + some older migration paths.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$groupless_links = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i l
				LEFT JOIN %i g ON g.id = l.group_id
				WHERE g.id IS NULL',
				$links_table,
				$groups_table
			)
		);

		if ( $groupless_links > 0 ) {
			\WP_CLI::warning( " Orphan links (missing group): {$groupless_links}" );
			$issues += $groupless_links;

			if ( $fix ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hardcoded cleanup query with no user input; table names from Schema::table().
				$wpdb->query(
					$wpdb->prepare(
						'DELETE l FROM %i l
						LEFT JOIN %i g ON g.id = l.group_id
						WHERE g.id IS NULL',
						$links_table,
						$groups_table
					)
				);
				\WP_CLI::log( " Fixed: removed {$groupless_links} groupless orphan links." );
			}
		} else {
			\WP_CLI::log( ' Orphan links (missing group): 0 ✓' );
		}

		\WP_CLI::log( '' );

		if ( $issues === 0 ) {
			// Name what was checked rather than certifying the database. These
			// are six specific orphan/duplicate invariants; other semantic
			// relationships (a link whose stored type disagrees with its
			// group's, say) are enforced by the repositories on write and are
			// not re-verified here, so "the database is healthy" claims more
			// than the command actually established.
			\WP_CLI::success( 'All six orphan and duplicate checks passed.' );
		} elseif ( $fix ) {
			// The repair steps above issue direct $wpdb DELETEs that bypass the
			// repository's invalidation, so without an explicit flush the
			// in-memory + persistent caches would return the deleted rows for
			// up to the transient TTL. flush_all clears every PerfLocale cache
			// group, the eager-link-map, and the per-key translations cache.
			$cache = $this->plugin->get( 'cache' );

			if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
				$cache->flush_all();
			}

			\WP_CLI::success( "Fixed {$issues} issues." );
		} else {
			\WP_CLI::warning( "Found {$issues} issues. Run with --fix to repair." );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	// =========================================================================
	// Status
	// =========================================================================

	/**
	 * Show translation completeness overview.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		$cache     = $this->plugin->get( 'cache' );
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$link_repo = new TranslationLinkRepository( $cache );
		$languages = $lang_repo->get_active();

		$items = [];

		foreach ( $languages as $lang ) {
			$counts = $link_repo->count_by_status( (int) $lang->id );

			$total = array_sum( $counts );

			// Show a column for EVERY status count_by_status can return (the
			// no-type call spans post/term/string links — string links carry
			// 'translated'), so the per-language row reconciles: the buckets
			// sum to Total. Previously only Published/Draft/Empty were shown,
			// so any 'translated'/'pending'/'needs_update' link inflated Total
			// above the visible columns with no explanation.
			$items[] = [
				'Language'     => $lang->name . ' (' . \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ) . ')',
				'Published'    => $counts['published'] ?? 0,
				'Translated'   => $counts['translated'] ?? 0,
				'Draft'        => $counts['draft'] ?? 0,
				'Pending'      => $counts['pending'] ?? 0,
				'Needs Update' => $counts['needs_update'] ?? 0,
				'Empty'        => $counts['empty'] ?? 0,
				'Total'        => $total,
			];
		}

		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI\Utils\format_items( $format, $items, [ 'Language', 'Published', 'Translated', 'Draft', 'Pending', 'Needs Update', 'Empty', 'Total' ] );
	}

	/**
	 * Run any pending PerfLocale database schema migrations.
	 *
	 * Idempotent - safe to run multiple times. Useful for CI/CD pipelines
	 * or headless environments where wp-admin is never visited.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale db-migrate
	 *
	 * @subcommand db-migrate
	 * @when after_wp_load
	 */
	public function db_migrate(): void {
		$migrator = new \PerfLocale\Database\Migrator();

		try {
			$migrator->maybe_migrate();
			$migrator->maybe_update();
			\WP_CLI::success( 'PerfLocale database migrations completed successfully.' );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'Migration failed: ' . $e->getMessage() );
		}
	}

	// =========================================================================
	// Background Jobs
	// =========================================================================

	/**
	 * Inspect and control background jobs.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Subcommand (list, get, cancel, retry, delete, pause, unpause, gc, resume).
	 *
	 * [<id>]
	 * : Job ID (required for get, cancel, retry, delete).
	 *
	 * [--status=<status>]
	 * : Filter list by status (queued, running, complete, failed, canceled).
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv, yaml). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all active jobs (queued or running)
	 *     wp perflocale jobs list
	 *
	 *     # List only failed jobs as JSON
	 *     wp perflocale jobs list --status=failed --format=json
	 *
	 *     # Show full state of one job
	 *     wp perflocale jobs get 5fc94964-29f6-4697-8c4e-64a8ffb5c72d
	 *
	 *     # Cancel / retry / delete
	 *     wp perflocale jobs cancel 5fc94964-29f6-4697-8c4e-64a8ffb5c72d
	 *     wp perflocale jobs retry  5fc94964-29f6-4697-8c4e-64a8ffb5c72d
	 *     wp perflocale jobs delete 5fc94964-29f6-4697-8c4e-64a8ffb5c72d
	 *
	 *     # Operator brake — stop the worker fleet without deactivating the plugin
	 *     wp perflocale jobs pause
	 *     wp perflocale jobs unpause
	 *
	 *     # Manual maintenance
	 *     wp perflocale jobs gc        # Run garbage collection now
	 *     wp perflocale jobs resume    # Re-enqueue queued/running jobs (post-reactivation recovery)
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 * @return void
	 */
	public function jobs( array $args, array $assoc_args ): void {
		// CLI doesn't fire `admin_init`, so the lazy `ensure_recurring_schedules`
		// path doesn't run when an operator dispatches jobs purely via CLI on
		// a blog that's never been visited via wp-admin. Call it defensively
		// here; it's idempotent and cheap (early-returns when the events are
		// already scheduled). Without this, jobs dispatched via CLI on
		// admin-never-visited blogs could accumulate without GC/watchdog cleanup.
		if ( class_exists( '\\PerfLocale\\Bootstrap' )
			&& method_exists( '\\PerfLocale\\Bootstrap', 'ensure_recurring_schedules' )
		) {
			try {
				\PerfLocale\Bootstrap::ensure_recurring_schedules();
			} catch ( \Throwable $e ) {
				// Don't block the CLI op if schedule-seeding fails; log and
				// continue so the user can still inspect / cancel jobs.
				\WP_CLI::warning( 'ensure_recurring_schedules failed: ' . $e->getMessage() );
			}
		}

		$subcommand = $args[0] ?? 'list';

		switch ( $subcommand ) {
			case 'list':
				$this->jobs_list( $assoc_args );
				break;
			case 'get':
				$this->jobs_get( $args[1] ?? '', $assoc_args );
				break;
			case 'cancel':
				$this->jobs_cancel( $args[1] ?? '' );
				break;
			case 'retry':
				$this->jobs_retry( $args[1] ?? '' );
				break;
			case 'delete':
				$this->jobs_delete( $args[1] ?? '' );
				break;
			case 'pause':
				$this->jobs_pause( true );
				break;
			case 'unpause':
				$this->jobs_pause( false );
				break;
			case 'gc':
				$this->jobs_gc();
				break;
			case 'resume':
				$this->jobs_resume();
				break;
			default:
				\WP_CLI::error( "Unknown subcommand: {$subcommand}. Available: list, get, cancel, retry, delete, pause, unpause, gc, resume" );
		}
	}

	/**
	 * Render the active-jobs index as a table / JSON / CSV.
	 *
	 * @param array<string, string> $assoc_args
	 * @return void
	 */
	private function jobs_list( array $assoc_args ): void {
		$status_filter = isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : '';
		$format        = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		// Push the status filter into SQL so a status that only appears beyond
		// the active-index cap is still found (not dropped by a post-cap PHP
		// filter). The in-loop check below then just builds rows.
		$idx = \PerfLocale\Background\JobState::list_active( $status_filter );

		// Human-formatted values ("0%", "3 hours ago") are for the table view
		// ONLY. Machine formats (json/csv/yaml) must stay scriptable: a bare int
		// progress and an ISO-8601 timestamp, never a localized ever-changing
		// relative string. Mirrors the plugin's machine-format standard.
		$is_table = ( 'table' === $format );

		$rows = [];
		foreach ( $idx as $job_id => $row ) {
			$status = (string) ( $row['status'] ?? 'unknown' );
			if ( $status_filter !== '' && $status !== $status_filter ) {
				continue;
			}
			$progress_raw = (int) ( $row['progress'] ?? 0 );
			$updated_raw  = (int) ( $row['updated_at'] ?? 0 );
			$rows[]       = [
				'id'         => (string) $job_id,
				'type'       => (string) ( $row['type'] ?? '' ),
				'status'     => $status,
				'progress'   => $is_table ? $progress_raw . '%' : $progress_raw,
				'updated_at' => $is_table
					? $this->format_time( $updated_raw )
					: ( $updated_raw > 0 ? gmdate( 'c', $updated_raw ) : '' ),
			];
		}

		if ( empty( $rows ) && in_array( $format, [ 'table' ], true ) ) {
			\WP_CLI::log( 'No jobs match.' );
			return;
		}

		if ( 'ids' === $format ) {
			// format_items() can't render 'ids' from associative rows (it
			// implode()s each row array → "Array to string conversion"). Emit
			// the id column directly so `--format=ids` stays scriptable.
			\WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'type', 'status', 'progress', 'updated_at' ] );
	}

	/**
	 * Print the full state of one job.
	 *
	 * @param string                $job_id
	 * @param array<string, string> $assoc_args
	 * @return void
	 */
	private function jobs_get( string $job_id, array $assoc_args ): void {
		if ( $job_id === '' ) {
			\WP_CLI::error( 'Job ID required. Usage: wp perflocale jobs get <id>' );
		}

		$state = \PerfLocale\Background\JobState::get( $job_id );

		if ( ! $state ) {
			\WP_CLI::error( "Job not found: {$job_id}" );
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'yaml';

		if ( $format === 'json' ) {
			\WP_CLI::log( (string) wp_json_encode( $state, JSON_PRETTY_PRINT ) );
			return;
		}

		// Pretty-print the row as a flat key:value list. Nested structures
		// (args, log, result) are JSON-encoded for one-line display.
		foreach ( $state as $k => $v ) {
			if ( is_array( $v ) ) {
				\WP_CLI::log( sprintf( '%-12s : %s', $k, wp_json_encode( $v ) ) );
			} else {
				\WP_CLI::log( sprintf( '%-12s : %s', $k, (string) $v ) );
			}
		}
	}

	/**
	 * Cancel a queued or running job. Also unschedules its worker event.
	 *
	 * @param string $job_id
	 * @return void
	 */
	private function jobs_cancel( string $job_id ): void {
		if ( $job_id === '' ) {
			\WP_CLI::error( 'Job ID required.' );
		}

		$state = \PerfLocale\Background\JobState::get( $job_id );

		if ( ! $state ) {
			\WP_CLI::error( "Job not found: {$job_id}" );
		}

		if ( ! in_array( (string) $state['status'], [ 'queued', 'running' ], true ) ) {
			\WP_CLI::warning( "Job is in status '{$state['status']}' — only queued/running jobs can be canceled." );
			return;
		}

		\PerfLocale\Background\JobRunnerFactory::for_engine( (string) ( $state['engine'] ?? '' ) )->cancel( $job_id );
		\PerfLocale\Background\JobState::cancel( $job_id );

		// Release the per-job + per-type locks immediately. Otherwise the
		// per-type lock (TTL 30 min) blocks every same-type dispatch with
		// exponential backoff until the TTL elapses — same fix as the REST
		// cancel endpoint.
		\PerfLocale\Background\JobLock::release( $job_id );
		\PerfLocale\Background\JobLock::release_type( (string) $state['type'] );

		\WP_CLI::success( "Canceled job {$job_id}" );
	}

	/**
	 * Re-enqueue a failed or canceled job (resets state, schedules a new
	 * worker event). Refuses non-terminal jobs since they're already in
	 * flight.
	 *
	 * @param string $job_id
	 * @return void
	 */
	private function jobs_retry( string $job_id ): void {
		if ( $job_id === '' ) {
			\WP_CLI::error( 'Job ID required.' );
		}

		$state = \PerfLocale\Background\JobState::get( $job_id );

		if ( ! $state ) {
			\WP_CLI::error( "Job not found: {$job_id}" );
		}

		if ( ! in_array( (string) $state['status'], [ 'failed', 'canceled' ], true ) ) {
			\WP_CLI::warning( "Job is in status '{$state['status']}' — only failed/canceled jobs can be retried." );
			return;
		}

		// Re-inject the blog-id sentinel so the worker can switch_to_blog
		// before reading the per-blog JobState row. Without this, retries
		// of jobs dispatched from a non-main multisite site orphan — same
		// fix as the REST retry endpoint.
		$blog_id     = (int) ( $state['blog_id'] ?? 0 );
		$worker_args = \PerfLocale\Background\WorkerRegistry::with_blog_sentinel(
			(array) ( $state['args'] ?? [] ),
			$blog_id
		);

		if ( ! \PerfLocale\Background\JobState::reset_for_retry( $job_id, true ) ) {
			\WP_CLI::error( "Could not reset job {$job_id} for retry (its state changed concurrently)." );
		}

		// Record the engine that re-queued (mirrors WorkerRegistry::
		// schedule_recording_engine) — after an engine switch, cancel/
		// is_scheduled probes would otherwise target the wrong store.
		$runner = \PerfLocale\Background\JobRunnerFactory::pick();
		$runner->enqueue(
			(string) $state['hook'],
			$worker_args,
			$job_id
		);
		\PerfLocale\Background\JobState::set_engine( $job_id, $runner->get_engine_name() );

		\WP_CLI::success( "Retried job {$job_id}" );
	}

	/**
	 * Permanently delete a job from the index AND its per-job state row.
	 *
	 * Matches the REST endpoint's terminal-state requirement: refuses
	 * non-terminal jobs (queued / running). Cancel them first so the
	 * runner has a chance to unschedule cleanly; otherwise the worker
	 * event would still fire against a deleted JobState row (handled
	 * gracefully by WorkerRegistry::run via the orphan-event check, but
	 * the asymmetry between CLI and REST behaviour was a footgun).
	 *
	 * @param string $job_id
	 * @return void
	 */
	private function jobs_delete( string $job_id ): void {
		if ( $job_id === '' ) {
			\WP_CLI::error( 'Job ID required.' );
		}

		$state = \PerfLocale\Background\JobState::get( $job_id );

		if ( ! $state ) {
			\WP_CLI::error( "Job not found: {$job_id}" );
		}

		if ( ! in_array( (string) $state['status'], [ 'complete', 'failed', 'canceled' ], true ) ) {
			\WP_CLI::warning(
				"Job is in status '{$state['status']}' — cancel it first ('wp perflocale jobs cancel {$job_id}') before deleting."
			);
			return;
		}

		\PerfLocale\Background\JobState::delete( $job_id );
		\WP_CLI::success( "Deleted job {$job_id}" );
	}

	/**
	 * Toggle the background_paused setting.
	 *
	 * @param bool $paused True = pause, false = unpause.
	 * @return void
	 */
	private function jobs_pause( bool $paused ): void {
		$this->plugin->get( 'settings' )->update( [ 'background_paused' => $paused ] );

		\WP_CLI::success(
			$paused
			? 'Queue paused. Workers will re-queue jobs every 5 minutes instead of running them.'
			: 'Queue unpaused. Workers will resume processing.'
		);
	}

	/**
	 * Run the daily GC sweep right now (no need to wait for the cron tick).
	 *
	 * @return void
	 */
	private function jobs_gc(): void {
		$removed = \PerfLocale\Background\JobState::gc();
		\WP_CLI::success( sprintf( 'GC complete: %d job(s) pruned.', $removed ) );
	}

	/**
	 * Manually trigger the post-reactivation resume sweep. Useful when the
	 * `perflocale_resume_jobs` cron event was lost (e.g. WP-Cron disabled
	 * + no external trigger between activate and the operator noticing).
	 *
	 * @return void
	 */
	private function jobs_resume(): void {
		$resumed = \PerfLocale\Background\Resumer::resume();
		\WP_CLI::success( sprintf( 'Resume complete: %d job(s) re-enqueued.', $resumed ) );
	}

	/**
	 * Format a unix timestamp as a "5 mins ago" style relative string for
	 * CLI display. Returns "—" for zero/empty timestamps.
	 *
	 * @param int $ts
	 * @return string
	 */
	private function format_time( int $ts ): string {
		if ( $ts <= 0 ) {
			return '—';
		}
		// `max( 1, ... )` guarantees $diff >= 1, so the trailing " ago" is
		// always appended — no need for the redundant `> 0` ternary.
		return human_time_diff( $ts, time() ) . ' ago';
	}

	// =========================================================================
	// Circuit breakers
	// =========================================================================

	/**
	 * Inspect and manage PerfLocale circuit breakers.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Subcommand to run.
	 * ---
	 * options:
	 *   - list
	 *   - status
	 *   - reset
	 * ---
	 *
	 * [<key>]
	 * : Breaker key (required for `status`; required for `reset` unless `--all`).
	 *   Examples: `mt_deepl`, `mt_wp_ai_client`, `webhook_<uuid>`, `fx_sync`,
	 *   `geo_<provider>`. Use `wp perflocale breakers list`
	 *   to discover live keys.
	 *
	 * [--all]
	 * : With `reset`: close every currently-tracked breaker.
	 *
	 * [--format=<fmt>]
	 * : Output format for `list` / `status` (table, json, csv, yaml). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show every tracked breaker + its state
	 *     wp perflocale breakers list
	 *
	 *     # Detailed status of one breaker
	 *     wp perflocale breakers status mt_deepl
	 *
	 *     # Force-close one breaker (after verifying the upstream is healthy)
	 *     wp perflocale breakers reset mt_deepl
	 *
	 *     # Force-close ALL breakers
	 *     wp perflocale breakers reset --all
	 *
	 *     # JSON output for dashboards / monitoring
	 *     wp perflocale breakers list --format=json
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Named args.
	 * @return void
	 */
	public function breakers( array $args, array $assoc_args ): void {
		if ( ! class_exists( '\\PerfLocale\\Concurrency\\Breaker' ) ) {
			\WP_CLI::error( 'Breaker subsystem not loaded.' );
		}

		$subcommand = $args[0] ?? '';
		$key        = $args[1] ?? '';
		$format     = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$all_flag   = isset( $assoc_args['all'] );

		switch ( $subcommand ) {
			case 'list':
				$this->breakers_list( $format );
				return;

			case 'status':
				if ( $key === '' ) {
					\WP_CLI::error( 'Missing <key>. Example: wp perflocale breakers status mt_deepl' );
				}
				$this->breakers_status( $key, $format );
				return;

			case 'reset':
				if ( $all_flag ) {
					$this->breakers_reset_all();
					return;
				}
				if ( $key === '' ) {
					\WP_CLI::error( 'Missing <key>. Example: wp perflocale breakers reset mt_deepl  (or use --all)' );
				}
				$this->breakers_reset_one( $key );
				return;

			default:
				\WP_CLI::error( "Unknown subcommand: '{$subcommand}'. Available: list, status, reset" );
		}
	}

	/**
	 * `wp perflocale breakers list` body.
	 *
	 * @param string $format
	 * @return void
	 */
	private function breakers_list( string $format ): void {
		$all = \PerfLocale\Concurrency\Breaker::list_all();

		$rows = [];
		foreach ( $all as $key => $status ) {
			$rows[] = [
				'key'                => $key,
				'state'              => (string) ( $status['state'] ?? 'closed' ),
				'failures'           => (int) ( $status['failures'] ?? 0 ),
				'reason'             => (string) ( $status['reason'] ?? '' ),
				'opened_at'          => (int) ( $status['opened_at'] ?? 0 ) > 0
					? gmdate( 'Y-m-d H:i:s', (int) $status['opened_at'] )
					: '',
				'cooldown_remaining' => (int) ( $status['cooldown_remaining'] ?? 0 ),
			];
		}

		// Machine-readable formats (json/csv/yaml/count/ids) must always
		// emit a parseable representation — even when the list is empty.
		// A human "Success: ..." line would break callers piping to jq/awk.
		// Only print the friendly empty-state line for the default table view.
		if ( $rows === [] && in_array( $format, [ 'table' ], true ) ) {
			\WP_CLI::success( 'No active breakers — system healthy.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			[ 'key', 'state', 'failures', 'reason', 'opened_at', 'cooldown_remaining' ]
		);
	}

	/**
	 * `wp perflocale breakers status <key>` body.
	 *
	 * @param string $key
	 * @param string $format
	 * @return void
	 */
	private function breakers_status( string $key, string $format ): void {
		$status = \PerfLocale\Concurrency\Breaker::status( $key );

		$rows = [
			[
				'field' => 'key',
				'value' => $key,
			],
			[
				'field' => 'state',
				'value' => (string) ( $status['state'] ?? 'closed' ),
			],
			[
				'field' => 'failures',
				'value' => (string) ( (int) ( $status['failures'] ?? 0 ) ),
			],
			[
				'field' => 'reason',
				'value' => (string) ( $status['reason'] ?? '' ),
			],
			[
				'field' => 'first_failure',
				'value' => (int) ( $status['first_failure'] ?? 0 ) > 0 ? gmdate( 'Y-m-d H:i:s', (int) $status['first_failure'] ) : '—',
			],
			[
				'field' => 'last_failure',
				'value' => (int) ( $status['last_failure'] ?? 0 ) > 0 ? gmdate( 'Y-m-d H:i:s', (int) $status['last_failure'] ) : '—',
			],
			[
				'field' => 'opened_at',
				'value' => (int) ( $status['opened_at'] ?? 0 ) > 0 ? gmdate( 'Y-m-d H:i:s', (int) $status['opened_at'] ) : '—',
			],
			[
				'field' => 'cooldown_remaining',
				'value' => (string) ( (int) ( $status['cooldown_remaining'] ?? 0 ) ) . 's',
			],
		];

		\WP_CLI\Utils\format_items( $format, $rows, [ 'field', 'value' ] );
	}

	/**
	 * `wp perflocale breakers reset <key>` body.
	 *
	 * @param string $key
	 * @return void
	 */
	private function breakers_reset_one( string $key ): void {
		$was_open = \PerfLocale\Concurrency\Breaker::is_open( $key );
		\PerfLocale\Concurrency\Breaker::reset( $key );

		if ( $was_open ) {
			\WP_CLI::success( "Breaker '{$key}' was OPEN and is now closed." );
		} else {
			\WP_CLI::success( "Breaker '{$key}' was already closed (no-op)." );
		}
	}

	/**
	 * `wp perflocale breakers reset --all` body.
	 *
	 * @return void
	 */
	private function breakers_reset_all(): void {
		$all   = \PerfLocale\Concurrency\Breaker::list_all();
		$count = count( $all );

		if ( $count === 0 ) {
			\WP_CLI::success( 'No active breakers to reset.' );
			return;
		}

		foreach ( array_keys( $all ) as $key ) {
			\PerfLocale\Concurrency\Breaker::reset( (string) $key );
		}

		\WP_CLI::success( "Reset {$count} breaker(s)." );
	}
}
