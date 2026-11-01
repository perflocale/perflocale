<?php
/**
 * Bulk language tools on Settings > Translation (admin-ajax handlers).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Helper;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Migration\MigrationLock;
use PerfLocale\Migration\MigrationState;
use PerfLocale\Plugin;
use PerfLocale\Translation\PostTranslationManager;
use PerfLocale\Translation\TermTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four bulk tools' AJAX handlers; Bootstrap hooks them by name, so this class loads only when one runs.
 */
final class BulkTools {

	/**
	 * Post meta on a WooCommerce page translation: md5 of the source title MT last answered
	 * for, so an unchanged title is not sent again.
	 */
	private const WC_PAGE_MT_ATTEMPT_META = '_perflocale_wc_page_mt_attempt';

	/**
	 * Hold back a bulk language tool while imported data is missing or an import runs.
	 *
	 * Run before a WPML, Polylang or TranslatePress import, these tools would give that data the
	 * default language. The screen confirms `perflocale_unimported_source` and resends with
	 * `continue_unimported=1`. Call after the handler's nonce and capability checks.
	 *
	 * @return bool True when the handler may run; false after a JSON error was sent.
	 */
	private static function guard_bulk_tool(): bool {
		if ( MigrationLock::seconds_remaining() > 0 ) {
			wp_send_json_error(
				[
					'code'    => 'perflocale_import_running',
					'message' => __( 'An import is running. Try again when it has finished.', 'perflocale' ),
				]
			);

			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The calling handler verified the nonce.
		if ( isset( $_POST['continue_unimported'] ) && '1' === sanitize_key( wp_unslash( $_POST['continue_unimported'] ) ) ) {
			return true;
		}

		$sources    = MigrationState::unimported_sources();
		$incomplete = $sources === [] ? MigrationState::incomplete_sources() : [];

		if ( $sources === [] && $incomplete === [] ) {
			return true;
		}

		wp_send_json_error(
			[
				'code'    => 'perflocale_unimported_source',
				'message' => $sources !== []
					? sprintf(
						/* translators: %s: source plugin name(s), e.g. "WPML" or "WPML and Polylang" */
						__( '%s translations have not been imported yet. Running this now gives them the default language.', 'perflocale' ),
						MigrationState::source_names( $sources )
					)
					: MigrationState::incomplete_message( $incomplete ),
				'confirm' => __( 'Continue anyway?', 'perflocale' ),
			]
		);

		return false;
	}

	/**
	 * AJAX handler: create Cart, Checkout, My Account and Shop translations in every language.
	 *
	 * Titles are machine-translated when MT is enabled and configured.
	 *
	 * @return void
	 */
	public static function ajax_create_wc_page_translations(): void {
		check_ajax_referer( 'perflocale_create_wc_pages', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ], 403 );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'perflocale' ) ] );
		}

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new LanguageRepository( $cache );
		$languages = $lang_repo->get_active();
		$default   = $lang_repo->get_default();

		if ( ! $default || count( $languages ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'At least two active languages are required.', 'perflocale' ) ] );
		}

		// Collect WooCommerce page IDs.
		$wc_page_keys = [ 'cart', 'checkout', 'myaccount', 'shop' ];
		$page_ids     = [];

		foreach ( $wc_page_keys as $key ) {
			$page_id = wc_get_page_id( $key );

			if ( $page_id > 0 && get_post( $page_id ) ) {
				$page_ids[ $key ] = $page_id;
			}
		}

		if ( empty( $page_ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No WooCommerce pages found.', 'perflocale' ) ] );
		}

		// MT through TranslationService, so the monthly limit and plain-text sanitiser apply and
		// languages travel as slugs (pt-br, not pt).
		$mt_provider = null;
		$mt_service  = null;

		if ( $settings->mt_enabled() ) {
			try {
				$mt_service  = new TranslationService( $settings, $cache );
				$mt_provider = $mt_service->get_provider();
			} catch ( \Throwable $e ) {
				// MT not configured: continue without translation.
				$mt_service  = null;
				$mt_provider = null;
			}
		}

		$manager = new PostTranslationManager( $cache, $settings );
		$created = 0;
		$skipped = 0;
		$details = [];
		// Whether MT produced any title: a configured provider may be skipped or fail.
		$mt_used = false;

		global $wpdb;

		foreach ( $page_ids as $key => $page_id ) {
			// Ensure the source page has a language assigned.
			if ( ! $manager->detect_post_language( $page_id ) && ! $manager->set_post_language( $page_id, $default->slug ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the page-creation batch continues with remaining pages.
				error_log( sprintf( 'PerfLocale: default-language assignment failed for page %d during page-creation batch.', $page_id ) );
			}

			$source_post = get_post( $page_id );

			if ( ! $source_post ) {
				continue;
			}

			// A created translation is published only when its source is and the user may publish.
			$source_type = get_post_type_object( $source_post->post_type );
			$may_publish = 'publish' === $source_post->post_status
				&& $source_type instanceof \WP_Post_Type
				&& is_string( $source_type->cap->publish_posts ?? null )
				&& current_user_can( $source_type->cap->publish_posts );

			foreach ( $languages as $lang ) {
				if ( $lang->slug === $default->slug ) {
					continue;
				}

				$label = ucfirst( $key ) . ' → ' . $lang->slug;

				// MT-tagged when a provider was resolved above; otherwise Manual.
				$link_source = $mt_provider !== null
					? \PerfLocale\Enum\SourceType::MachineTranslation
					: \PerfLocale\Enum\SourceType::Manual;
				$inserted    = false;
				$new_id      = $manager->create_translation( $page_id, $lang->slug, true, $link_source, $inserted );

				if ( ! $new_id || $new_id === $page_id ) {
					$details[] = $label . ': failed';
					continue;
				}

				$new_post = get_post( $new_id );

				if ( ! $new_post ) {
					$details[] = $label . ': post missing (ID ' . $new_id . ')';
					continue;
				}

				// Title: local translations first, then MT; defaults to the source title so the UPDATE
				// below never writes an empty string.
				$original_title   = $source_post->post_title;
				$translated_title = $original_title;

				// Resolve a title (maybe a paid MT call) only when the row still needs one:
				// create_translation() is idempotent, so a re-run reaches finished pages too.
				$needs_title = (
					$new_post->post_status !== 'publish'
					|| $new_post->post_name !== $source_post->post_name
					|| $new_post->post_title === $original_title
					|| trim( $new_post->post_title ) === ''
				);

				$mt_translated_this = false;

				if ( $needs_title ) {
					$local_translation = self::find_local_translation( $original_title, $lang->locale );
					$title_hash        = md5( $original_title );

					if ( $local_translation !== '' ) {
						$translated_title = $local_translation;
					} elseif ( $mt_service !== null && get_post_meta( $new_id, self::WC_PAGE_MT_ATTEMPT_META, true ) !== $title_hash ) {
						// A title the provider already answered for is not sent again (an unchanged
						// "Checkout" would be billed on every run).
						try {
							$mt_titles = $mt_service->translate_batch_texts( [ $original_title ], (string) $default->slug, (string) $lang->slug, '', false, 'text' );
							$mt_title  = sanitize_text_field( (string) ( $mt_titles[0] ?? '' ) );

							if ( $mt_title !== '' ) {
								update_post_meta( $new_id, self::WC_PAGE_MT_ATTEMPT_META, $title_hash );
								$translated_title   = $mt_title;
								$mt_translated_this = true;
							}
						} catch ( \Throwable $e ) {
							// Keep the original title on failure, including a monthly-limit refusal.
							$translated_title = $original_title;
						}
					}
				} else {
					// An existing translated title wins on re-runs.
					$translated_title = $new_post->post_title;
				}

				// Matching slug and translated title; a translation that existed before keeps its status.
				$target_status = $inserted && $may_publish ? 'publish' : $new_post->post_status;
				$needs_update  = (
					$new_post->post_status !== $target_status
					|| $new_post->post_name !== $source_post->post_name
					|| $new_post->post_title !== $translated_title
				);

				// The MT note reflects titles actually written, not discarded provider answers.
				if ( $needs_update && $mt_translated_this ) {
					$mt_used = true;
				}

				if ( $needs_update ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->posts,
						[
							'post_title'  => $translated_title,
							'post_name'   => $source_post->post_name,
							'post_status' => $target_status,
						],
						[ 'ID' => $new_id ],
						[ '%s', '%s', '%s' ],
						[ '%d' ]
					);
					clean_post_cache( $new_id );
					++$created;
					$details[] = $label . ': created (ID ' . $new_id . ')';
				} else {
					++$skipped;
					$details[] = $label . ': exists (ID ' . $new_id . ')';
				}
			}
		}

		if ( $created > 0 ) {
			update_option( 'perflocale_flush_rules', '1', false );
		}

		$message = sprintf(
			/* translators: %1$d: number of pages created, %2$d: number already existing */
			__( 'Done - %1$d page(s) created, %2$d already existed.', 'perflocale' ),
			$created,
			$skipped
		);

		if ( $mt_used ) {
			$message .= ' ' . __( 'Titles were machine-translated.', 'perflocale' );
		}

		wp_send_json_success(
			[
				'message' => $message,
				'created' => $created,
				'skipped' => $skipped,
				'details' => $details,
			]
		);
	}

	/**
	 * Release the debug query log and report whether a bulk admin run nears PHP's memory limit.
	 *
	 * Under SAVEQUERIES `$wpdb->queries` keeps SQL plus a backtrace per query, enough to fatal a
	 * bulk run, so it is dropped (`num_queries` stays). The caller yields its cursor past the
	 * ceiling. See docs-ai/11-code-rationale.md, "Bootstrap: bulk memory pressure".
	 *
	 * @param bool $release_query_log Whether to drop `$wpdb->queries` first.
	 * @return bool True when the caller should stop and hand back its cursor.
	 */
	private static function bulk_memory_pressure( bool $release_query_log = true ): bool {
		if ( $release_query_log && defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			global $wpdb;

			if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) && count( $wpdb->queries ) > 200 ) {
				$wpdb->queries = [];
			}
		}

		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		// -1 / 0 means no limit (typical under WP-CLI): nothing to yield for.
		if ( $limit <= 0 ) {
			return false;
		}

		/**
		 * Fraction of PHP's memory limit at which a bulk admin run stops and
		 * hands its cursor back instead of risking a fatal.
		 *
		 * @hook perflocale/admin/bulk_memory_ceiling
		 *
		 * @param float $fraction Default 0.8.
		 */
		$ceiling = (float) apply_filters( 'perflocale/admin/bulk_memory_ceiling', 0.8 );
		$ceiling = min( 0.95, max( 0.5, $ceiling ) );

		return memory_get_usage( true ) > (int) ( $limit * $ceiling );
	}

	/**
	 * AJAX handler: create translations for every term of the translatable taxonomies.
	 *
	 * Uses local WordPress translations first, then MT if available. Resumable: the client sends
	 * back the cursor.
	 *
	 * @return void
	 */
	public static function ajax_create_taxonomy_translations(): void {
		// Verify the caller before any side effect.
		check_ajax_referer( 'perflocale_create_taxonomy_translations', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ], 403 );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		// Caller verified: lift the time limit (filterable; default 0) and keep running on abort.
		// Inside this handler only, never on init.
		if ( function_exists( 'set_time_limit' ) ) {
			/**
			 * Time limit for the WC-pages / taxonomy-translation bulk admin handlers.
			 *
			 * @hook  perflocale/admin/bulk_time_limit
			 * @since 1.0.0
			 * @param int $seconds Default 0 (no limit). Set to a positive int (seconds) to cap.
			 * @return int
			 */
			$bulk_time_limit = (int) apply_filters( 'perflocale/admin/bulk_time_limit', 0 );
			// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
			set_time_limit( $bulk_time_limit );
		}
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new LanguageRepository( $cache );
		$languages = $lang_repo->get_active();
		$default   = $lang_repo->get_default();

		// Resumable cursor, not a re-scan: one request for every term would hit the gateway
		// timeout, and restarting each run at the first taxonomy would never finish.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$tax_index = isset( $_POST['tax_index'] ) ? max( 0, (int) $_POST['tax_index'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$term_offset = isset( $_POST['term_offset'] ) ? max( 0, (int) $_POST['term_offset'] ) : 0;

		// Concurrency guard: two runs would both create the same missing term (a WP term, which
		// the unique link key cannot stop). acquire()/release(), not Lock::with(): every exit is
		// wp_send_json_*(), whose die() skips `finally`. Release on each exit path.
		$lock_name = 'create_term_translations';

		if ( ! Lock::acquire( $lock_name, 120 ) ) {
			// A held lock may belong to a run that died (release() works only in its process):
			// say how long until it clears.
			$held_for = Lock::seconds_remaining( $lock_name );

			wp_send_json_error(
				[
					'message' => $held_for > 0
						? sprintf(
							/* translators: %d: seconds remaining. */
							__( 'Another translation run is in progress, or a previous one ended unexpectedly. This clears automatically in %d seconds - then try again.', 'perflocale' ),
							$held_for
						)
						: __( 'Another translation run is already in progress. Wait for it to finish, then try again.', 'perflocale' ),
				]
			);
		}

		$started_at = microtime( true );
		/** @hook perflocale/create_term_translations/budget_seconds Wall-clock budget for one run. */
		$budget_seconds = (float) apply_filters( 'perflocale/create_term_translations/budget_seconds', 15.0 );
		/** @hook perflocale/create_term_translations/batch_size Terms fetched per run. */
		$term_batch = max( 1, (int) apply_filters( 'perflocale/create_term_translations/batch_size', 200 ) );
		$hit_budget  = false;
		$more        = false;
		$next_index  = 0;
		$next_offset = 0;

		if ( ! $default || count( $languages ) < 2 ) {
			// Release first: wp_send_json_*() dies, and the lock would block retries until its TTL.
			Lock::release( $lock_name );
			wp_send_json_error( [ 'message' => __( 'At least two active languages are required.', 'perflocale' ) ] );
		}

		// MT through TranslationService, so the monthly limit and plain-text sanitiser apply and
		// languages travel as slugs (pt-br, not pt).
		$mt_provider = null;
		$mt_service  = null;

		if ( $settings->mt_enabled() ) {
			try {
				$mt_service  = new TranslationService( $settings, $cache );
				$mt_provider = $mt_service->get_provider();
			} catch ( \Throwable $e ) {
				$mt_service  = null;
				$mt_provider = null;
			}
		}

		// flush_all, not flush_group: a stale L3 transient would rehydrate L2 and disagree with
		// the direct-DB prefetch below, miscounting existing terms as new.
		$cache->flush_all();

		// One bulk term-to-language map query instead of N+1.
		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$langs_table  = Schema::table( 'languages' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table() are safe.
		$all_term_links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.group_id, l.object_id AS term_id, lang.slug AS lang_slug
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id AND g.type = 'term'
				INNER JOIN %i lang ON l.language_id = lang.id",
				$links_table,
				$groups_table,
				$langs_table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// term_lang_map: term_id to language slug; term_group_map: term_id to group_id;
		// group_lang_map: group_id to slug to term_id. The idempotency check uses the last,
		// since create_translation() returns the same int for an existing translation.
		$term_lang_map  = [];
		$term_group_map = [];
		$group_lang_map = [];

		foreach ( $all_term_links as $row ) {
			$tid                                       = (int) $row->term_id;
			$gid                                       = (int) $row->group_id;
			$term_lang_map[ $tid ]                     = $row->lang_slug;
			$term_group_map[ $tid ]                    = $gid;
			$group_lang_map[ $gid ][ $row->lang_slug ] = $tid;
		}

		// Free memory.
		unset( $all_term_links );

		$taxonomies       = $settings->get_translatable_taxonomies();
		$term_manager     = new TermTranslationManager( $cache );
		$created          = 0;
		$skipped          = 0;
		$taxonomy_details = [];
		$mt_used          = false;

		// Local translations per locale (locale to original to translated), to avoid repeated
		// switch_to_locale() calls.
		$local_cache = [];

		// MT rename jobs (lang slug to taxonomy to new term_id => source name), translated in a
		// batched second pass of 50 per call instead of one blocking call per term.
		$mt_pending = [];

		// Defer term counting until all inserts are done.
		wp_defer_term_counting( true );

		$taxonomies = array_values( $taxonomies );

		foreach ( $taxonomies as $tax_i => $taxonomy ) {
			// Resume: skip taxonomies the previous run finished.
			if ( $tax_i < $tax_index ) {
				continue;
			}

			$tax_obj = get_taxonomy( $taxonomy );

			if ( ! $tax_obj ) {
				continue;
			}

			$tax_label   = $tax_obj->labels->name;
			$tax_created = 0;
			$tax_skipped = 0;
			$in_tax      = 0;

			// Fetch only term IDs and names to minimize memory usage.
			$terms = get_terms(
				[
					'taxonomy'                 => $taxonomy,
					'hide_empty'               => false,
					'orderby'                  => 'parent',
					'order'                    => 'ASC',
					'number'                   => $term_batch,
					'offset'                   => ( $tax_i === $tax_index ) ? $term_offset : 0,
					'perflocale_all_languages' => true,
				]
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$tid = $term->term_id;

				// Use the bulk map instead of per-term DB queries.
				$lang_slug = $term_lang_map[ $tid ] ?? null;

				// Only create translations for default-language terms or unassigned terms.
				if ( $lang_slug !== null && $lang_slug !== $default->slug ) {
					continue;
				}

				// Assign the default language if unset; this creates a group, so re-read its id for
				// the idempotency check below.
				if ( $lang_slug === null ) {
					if ( ! $term_manager->set_term_language( $tid, $default->slug ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the term batch continues with remaining terms.
						error_log( sprintf( 'PerfLocale: default-language assignment failed for term %d during term-translation batch.', $tid ) );
					}
					$term_lang_map[ $tid ] = $default->slug;

					$new_group = $term_manager->get_group_id_for_term( $tid );
					if ( $new_group !== null ) {
						$term_group_map[ $tid ]                         = $new_group;
						$group_lang_map[ $new_group ][ $default->slug ] = $tid;
					}
				}

				$group_id = $term_group_map[ $tid ] ?? null;

				foreach ( $languages as $lang ) {
					if ( $lang->slug === $default->slug ) {
						continue;
					}

					// Idempotency: the group already has this language, so it "existed" (repeated
					// clicks report 0 created).
					if ( $group_id !== null && isset( $group_lang_map[ $group_id ][ $lang->slug ] ) ) {
						++$tax_skipped;
						continue;
					}

					// MT-tagged when a provider was resolved, as on the post path.
					$term_source = $mt_provider !== null
						? \PerfLocale\Enum\SourceType::MachineTranslation
						: \PerfLocale\Enum\SourceType::Manual;
					$inserted    = false;
					$new_id      = $term_manager->create_translation( $tid, $taxonomy, $lang->slug, true, $term_source, $inserted );

					if ( ! $new_id || $new_id === $tid ) {
						++$tax_skipped;
						continue;
					}

					// Safety net: a term create_translation() did not insert, or one already in the
					// start-of-run map, already existed (another request, stale cache, or a source in
					// several groups). Its name stays.
					if ( ! $inserted || isset( $term_lang_map[ $new_id ] ) ) {
						++$tax_skipped;
						continue;
					}

					// Track the new term in our map.
					$term_lang_map[ $new_id ] = $lang->slug;

					// Keep the group map in sync for sibling terms later in this run.
					if ( $group_id === null ) {
						$group_id = $term_manager->get_group_id_for_term( $tid );
						if ( $group_id !== null ) {
							$term_group_map[ $tid ] = $group_id;
						}
					}

					if ( $group_id !== null ) {
						$term_group_map[ $new_id ]                  = $group_id;
						$group_lang_map[ $group_id ][ $lang->slug ] = $new_id;
					}

					// Local translations first (no network): core's .mo files carry curated names such
					// as "Uncategorized", which MT must not replace.
					if ( ! isset( $local_cache[ $lang->locale ][ $term->name ] ) ) {
						$local_cache[ $lang->locale ][ $term->name ] = self::find_local_translation( $term->name, $lang->locale );
					}

					$translated_name = $local_cache[ $lang->locale ][ $term->name ];

					if ( $translated_name !== '' ) {
						$name_result = wp_update_term( $new_id, $taxonomy, [ 'name' => wp_slash( $translated_name ) ] );
						if ( is_wp_error( $name_result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; term keeps its source name when localization fails.
							error_log( sprintf( 'PerfLocale migration: wp_update_term name localization failed for term %d: %s', (int) $new_id, $name_result->get_error_message() ) );
						}
					} elseif ( $mt_service !== null ) {
						// Deferred to the batched MT pass (see $mt_pending).
						$mt_pending[ $lang->slug ][ $taxonomy ][ (int) $new_id ] = $term->name;
					}

					++$tax_created;
				}

				++$in_tax;

				// The cursor passes a term only after every language was attempted for it, or a
				// resumed run would skip languages. Time or memory (bulk_memory_pressure()) both
				// stop by handing the cursor back.
				if ( ( microtime( true ) - $started_at ) >= $budget_seconds || self::bulk_memory_pressure() ) {
					$hit_budget = true;
					break;
				}
			}

			$base_offset = ( $tax_i === $tax_index ) ? $term_offset : 0;
			$page_count  = is_array( $terms ) ? count( $terms ) : 0;

			if ( $hit_budget ) {
				$more        = true;
				$next_index  = $tax_i;
				$next_offset = $base_offset + $in_tax;
			} elseif ( $page_count >= $term_batch ) {
				// A full page came back, so this taxonomy probably has more.
				$more        = true;
				$next_index  = $tax_i;
				$next_offset = $base_offset + $page_count;
			} else {
				// Taxonomy exhausted: the next run starts at the next one.
				$next_index  = $tax_i + 1;
				$next_offset = 0;
			}

			// Free term objects after each taxonomy.
			unset( $terms );

			$created += $tax_created;
			$skipped += $tax_skipped;

			$taxonomy_details[] = [
				'taxonomy' => $tax_label,
				'created'  => $tax_created,
				'skipped'  => $tax_skipped,
			];
	
			if ( $more ) {
				break;
			}
		}

		// Flush the deferred term counts.
		wp_defer_term_counting( false );

		// Second pass: batched MT rename, one call per (language, taxonomy, 50-term chunk).
		if ( $mt_service !== null && ! empty( $mt_pending ) ) {
			foreach ( $mt_pending as $lang_slug => $by_taxonomy ) {
				foreach ( $by_taxonomy as $taxonomy => $pending_terms ) {
					$source_names = array_values( array_unique( array_map( 'strval', $pending_terms ) ) );
					$name_map     = [];

					foreach ( array_chunk( $source_names, 50 ) as $chunk ) {
						// Each chunk is checked against the monthly limit; a refused one keeps its source names.
						try {
							$translated_chunk = $mt_service->translate_batch_texts(
								$chunk,
								(string) $default->slug,
								(string) $lang_slug,
								'',
								false,
								'text'
							);
						} catch ( \Throwable $e ) {
							continue;
						}

						$n = min( count( $chunk ), count( $translated_chunk ) );

						for ( $i = 0; $i < $n; $i++ ) {
							$translated = trim( (string) ( $translated_chunk[ $i ] ?? '' ) );

							if ( $translated !== '' && $translated !== $chunk[ $i ] ) {
								$name_map[ $chunk[ $i ] ] = $translated;
							}
						}
					}

					if ( empty( $name_map ) ) {
						continue;
					}

					foreach ( $pending_terms as $new_id => $source_name ) {
						if ( isset( $name_map[ $source_name ] ) ) {
							$mt_result = wp_update_term( $new_id, $taxonomy, [ 'name' => wp_slash( $name_map[ $source_name ] ) ] );
							if ( is_wp_error( $mt_result ) ) {
								if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
									// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; MT name not applied, term keeps its source name.
									error_log( sprintf( 'PerfLocale migration: MT term-name update failed for term %d: %s', (int) $new_id, $mt_result->get_error_message() ) );
								}
							} else {
								$mt_used = true;
							}
						}
					}
				}
			}
		}

		$message = $more
			? sprintf(
				/* translators: %1$d: created so far, %2$d: already existed */
				__( '%1$d created, %2$d already existed - still working...', 'perflocale' ),
				$created,
				$skipped
			)
			: sprintf(
				/* translators: %1$d: number of translations created, %2$d: number already existing */
				__( 'Done - %1$d translation(s) created, %2$d already existed.', 'perflocale' ),
				$created,
				$skipped
			);

		if ( $mt_used ) {
			$message .= ' ' . __( 'Term names were machine-translated.', 'perflocale' );
		}

		// Release before the response: wp_send_json_success() dies.
		Lock::release( $lock_name );

		wp_send_json_success(
			[
				'message'          => $message,
				'created'          => $created,
				'skipped'          => $skipped,
				'taxonomy_details' => $taxonomy_details,
				// The caller sends these back, so the next run resumes here.
				'more'             => $more,
				'tax_index'        => $next_index,
				'term_offset'      => $next_offset,
			]
		);
	}

	/**
	 * AJAX handler: create the missing translation drafts for existing content.
	 *
	 * The bulk, non-MT way to make translations exist. A write path that creates posts, so:
	 * drafts only (the Default Translation Status), content not copied (an empty draft reads as
	 * unfinished), never MT, idempotent, authorized per source (refusals in `skipped_permission`),
	 * and bounded and resumable on the bulk cursor contract.
	 *
	 * @return void
	 */
	public static function ajax_generate_missing_translations(): void {
		check_ajax_referer( 'perflocale_generate_missing_translations', '_nonce' );

		if ( ! current_user_can( 'perflocale_manage_translations' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'perflocale' ) ], 403 );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new LanguageRepository( $cache );
		$default   = $lang_repo->get_default();
		$languages = $lang_repo->get_active();

		if ( ! $default || count( $languages ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'At least two active languages are required.', 'perflocale' ) ] );
		}

		global $wpdb;

		$targets = [];

		foreach ( $languages as $lang ) {
			if ( (int) $lang->id !== (int) $default->id ) {
				$targets[] = $lang;
			}
		}

		$lock_name = 'generate_missing_translations';

		// Manual acquire and release, not a with() wrapper: see the taxonomy translations handler.
		if ( ! Lock::acquire( $lock_name, 120 ) ) {
			// A lock left by a crashed run looks live: report the wait.
			$held_for = Lock::seconds_remaining( $lock_name );

			wp_send_json_error(
				[
					'message' => $held_for > 0
						? sprintf(
							/* translators: %d: seconds remaining. */
							__( 'Another run is in progress, or a previous one ended unexpectedly. This clears automatically in %d seconds - then try again.', 'perflocale' ),
							$held_for
						)
						: __( 'Another run is already in progress. Wait for it to finish, then try again.', 'perflocale' ),
				]
			);
		}

		$types = array_values( $settings->get_translatable_post_types() );

		// Optional scoping to some post types (also lets a test confine it to a fixture type).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$requested = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) ) )
			: [];

		if ( $requested !== [] ) {
			$types = array_values( array_intersect( $types, $requested ) );
		}

		// Count-first mode: report the scale before any work, so the confirmation can state a
		// number. Creates nothing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( isset( $_POST['mode'] ) && 'count' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ) {
			$counts = [];
			$total  = 0;

			foreach ( $types as $post_type ) {
				if ( ! post_type_exists( $post_type ) ) {
					continue;
				}

				// The same "source" definition as the run below.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$n = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} p
						 INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id = %d
						 WHERE p.post_type = %s
						   AND p.post_status IN ('publish','draft','pending','private','future')",
						Schema::table( 'translation_links' ),
						(int) $default->id,
						$post_type
					)
				);
				// phpcs:enable

				if ( $n > 0 ) {
					$counts[] = [
						'post_type' => $post_type,
						'sources'   => $n,
					];
					$total    += $n * count( $targets );
				}
			}

			Lock::release( $lock_name );

			wp_send_json_success(
				[
					'mode'    => 'count',
					// A ceiling, not a prediction: existing pairs are skipped.
					'max'     => $total,
					'types'   => $counts,
					'message' => sprintf(
						/* translators: %d: maximum number of drafts. */
						__( 'This will create up to %d draft translation(s). Existing translations are skipped.', 'perflocale' ),
						$total
					),
				]
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$type_index = isset( $_POST['type_index'] ) ? max( 0, (int) $_POST['type_index'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$post_offset = isset( $_POST['post_offset'] ) ? max( 0, (int) $_POST['post_offset'] ) : 0;

		$started_at = microtime( true );
		/** @hook perflocale/generate_translations/budget_seconds Wall-clock budget for one run. */
		$budget = (float) apply_filters( 'perflocale/generate_translations/budget_seconds', 15.0 );
		/** @hook perflocale/generate_translations/batch_size Source posts fetched per run. */
		$batch = max( 1, (int) apply_filters( 'perflocale/generate_translations/batch_size', 100 ) );

		$manager = new PostTranslationManager( $cache, $settings );

		$created      = 0;
		$skipped      = 0;
		$failed       = 0;
		$more         = false;
		$next_index   = 0;
		$next_offset  = 0;
		$per_type     = [];
		$hit_budget   = false;

		// Pairs refused by the per-source checks; $skipped counts existing translations.
		$skipped_permission = 0;

		foreach ( $types as $ti => $post_type ) {
			if ( $ti < $type_index ) {
				continue;
			}

			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			$base   = ( $ti === $type_index ) ? $post_offset : 0;
			$in_type = 0;
			$made    = 0;

			// Source-language posts only, via the link table: `perflocale_lang` is not a query arg
			// that filters here, so it would also return earlier runs' translations. ID order keeps
			// the offset stable across runs.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$source_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id = %d
					 WHERE p.post_type = %s
					   AND p.post_status IN ('publish','draft','pending','private','future')
					 ORDER BY p.ID ASC
					 LIMIT %d OFFSET %d",
					Schema::table( 'translation_links' ),
					(int) $default->id,
					$post_type,
					$batch,
					$base
				)
			);
			// phpcs:enable

			foreach ( (array) $source_ids as $source_id ) {
				$source_id = (int) $source_id;

				foreach ( $targets as $lang ) {
					if ( $manager->get_translation_id( $source_id, $lang->slug ) !== null ) {
						++$skipped;
						continue;
					}

					// The checks of every other create path: edit on the source, edit and read on the
					// copy-from post, and the create capability of its type.
					$copy_from = $manager->get_copy_source_id( $source_id, (string) $lang->slug );

					if ( ! Helper::user_can_edit_object( $source_id )
						|| ( $copy_from > 0 && ( ! Helper::user_can_copy_translation_source( $copy_from ) || ! Helper::user_can_create_like( $copy_from ) ) )
					) {
						++$skipped_permission;
						continue;
					}

					// copy_content = false: an empty draft reads as "not done".
					$inserted = false;
					$new_id   = $manager->create_translation( $source_id, $lang->slug, false, \PerfLocale\Enum\SourceType::Manual, $inserted );

					if ( ! is_int( $new_id ) || $new_id <= 0 ) {
						++$failed;
					} elseif ( ! $inserted ) {
						// Not inserted by this call: another request created it in between.
						++$skipped;
					} else {
						++$created;
						++$made;
					}
				}

				++$in_type;

				// The cursor passes a source only after every language was attempted for it. Time
				// or memory (bulk_memory_pressure()) both stop by handing the cursor back.
				if ( ( microtime( true ) - $started_at ) >= $budget || self::bulk_memory_pressure() ) {
					$hit_budget = true;
					break;
				}
			}

			$per_type[] = [
				'post_type' => $post_type,
				'created'   => $made,
			];

			$page = is_array( $source_ids ) ? count( $source_ids ) : 0;

			if ( $hit_budget ) {
				$more        = true;
				$next_index  = $ti;
				$next_offset = $base + $in_type;
			} elseif ( $page >= $batch ) {
				$more        = true;
				$next_index  = $ti;
				$next_offset = $base + $page;
			} else {
				$next_index  = $ti + 1;
				$next_offset = 0;
			}

			if ( $more ) {
				break;
			}
		}

		$message = $more
			? sprintf(
				/* translators: %1$d: created so far, %2$d: already existed */
				__( '%1$d created, %2$d already existed - still working...', 'perflocale' ),
				$created,
				$skipped
			)
			: sprintf(
				/* translators: 1: created, 2: already existing, 3: failed */
				__( 'Done - %1$d draft translation(s) created, %2$d already existed, %3$d failed.', 'perflocale' ),
				$created,
				$skipped,
				$failed
			);

		if ( $skipped_permission > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of translations not created. */
				_n(
					'%d translation was not created because you cannot edit the original or create content of its type.',
					'%d translations were not created because you cannot edit the original or create content of its type.',
					$skipped_permission,
					'perflocale'
				),
				$skipped_permission
			);
		}

		Lock::release( $lock_name );

		wp_send_json_success(
			[
				'message'            => $message,
				'created'            => $created,
				'skipped'            => $skipped,
				'skipped_permission' => $skipped_permission,
				'failed'             => $failed,
				'details'            => $per_type,
				'more'               => $more,
				'type_index'         => $next_index,
				'post_offset'        => $next_offset,
			]
		);
	}

	/**
	 * AJAX handler: assign the default language to every translatable post without a link row.
	 *
	 * Unlinked posts show in every language (PostQueryFilter's `OR object_id IS NULL`), so
	 * language-scoped pickers look leaky. Posts with a language stay untouched; nothing infers a
	 * language from content. Idempotent.
	 *
	 * @return void
	 */
	public static function ajax_assign_post_languages(): void {
		// Verify the caller before any side effect.
		check_ajax_referer( 'perflocale_assign_post_languages', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'perflocale' ) ], 403 );
		}

		if ( ! self::guard_bulk_tool() ) {
			return;
		}

		// Caller verified: lift the time limit (filterable; default 0) and keep running on abort.
		// Inside this handler only, never on init.
		if ( function_exists( 'set_time_limit' ) ) {
			/**
			 * Time limit for the WC-pages / taxonomy-translation bulk admin handlers.
			 *
			 * @hook  perflocale/admin/bulk_time_limit
			 * @since 1.0.0
			 * @param int $seconds Default 0 (no limit). Set to a positive int (seconds) to cap.
			 * @return int
			 */
			$bulk_time_limit = (int) apply_filters( 'perflocale/admin/bulk_time_limit', 0 );
			// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
			set_time_limit( $bulk_time_limit );
		}
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to capability-gated admin handler only.
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );

		$plugin    = Plugin::get_instance();
		$cache     = $plugin->get( 'cache' );
		$settings  = $plugin->get( 'settings' );
		$lang_repo = new LanguageRepository( $cache );
		$default   = $lang_repo->get_default();

		if ( ! $default ) {
			wp_send_json_error( [ 'message' => __( 'No default language configured.', 'perflocale' ) ] );
		}

		$types = array_values( $settings->get_translatable_post_types() );

		// Optional scoping, as in ajax_generate_missing_translations() (lets a test confine it to
		// a fixture type).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$requested = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) ) )
			: [];

		if ( $requested !== [] ) {
			$types = array_values( array_intersect( $types, $requested ) );
		}

		if ( empty( $types ) ) {
			wp_send_json_error( [ 'message' => __( 'No translatable post types configured.', 'perflocale' ) ] );
		}

		global $wpdb;
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// Unlinked posts, one query per post type: LEFT JOIN to the 'post' group; NULL means never linked.
		$manager        = new PostTranslationManager( $cache, $settings );
		$per_type       = [];
		$total_assigned = 0;
		$hit_budget     = false;
		$started_at     = microtime( true );

		/** @hook perflocale/assign_languages/budget_seconds Wall-clock budget for one assign run. */
		$budget_seconds = (float) apply_filters( 'perflocale/assign_languages/budget_seconds', 15.0 );
		/** @hook perflocale/assign_languages/batch_size Rows fetched per post type per run. */
		$batch_size     = max( 1, (int) apply_filters( 'perflocale/assign_languages/batch_size', 500 ) );

		foreach ( $types as $post_type ) {
			// Posts with no valid post-group link. NOT EXISTS, not a LEFT JOIN: a post with one good
			// and one orphan link would otherwise count as unlinked and never settle.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table(), post_type sanitized.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					WHERE p.post_status IN ('publish','draft','pending','private','future')
					  AND p.post_type = %s
					  AND NOT EXISTS (
					    SELECT 1 FROM %i l
					    INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post'
					    WHERE l.object_id = p.ID
					  )
					ORDER BY p.ID ASC
					LIMIT %d",
					$post_type,
					$links_table,
					$groups_table,
					$batch_size
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$assigned = 0;

			foreach ( $ids as $post_id ) {
				if ( $manager->set_post_language( (int) $post_id, $default->slug ) ) {
					++$assigned;
				}

				// Bounded: stop on a clock or memory pressure (bulk_memory_pressure()) and report what
				// is left, or a large site gets a gateway 504 while PHP keeps writing. The caller resumes.
				if ( ( microtime( true ) - $started_at ) >= $budget_seconds || self::bulk_memory_pressure() ) {
					$hit_budget = true;
					break;
				}
			}

			$per_type[] = [
				'post_type' => $post_type,
				'assigned'  => $assigned,
			];

			$total_assigned += $assigned;

			if ( $hit_budget ) {
				break;
			}
		}

		// What is still unassigned across every type: this makes the run resumable.
		$remaining = 0;

		foreach ( $types as $post_type ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$remaining += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					WHERE p.post_status IN ('publish','draft','pending','private','future')
					  AND p.post_type = %s
					  AND NOT EXISTS (
					    SELECT 1 FROM %i l
					    INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post'
					    WHERE l.object_id = p.ID
					  )",
					$post_type,
					$links_table,
					$groups_table
				)
			);
			// phpcs:enable
		}

		$message = $remaining > 0
			? sprintf(
				/* translators: 1: number assigned so far, 2: language, 3: number still to do */
				__( '%1$d assigned to %2$s - %3$d still to go, continuing...', 'perflocale' ),
				$total_assigned,
				Helper::format_locale_as_bcp47( (string) $default->slug ),
				$remaining
			)
			: sprintf(
				/* translators: 1: number of items assigned, 2: default language slug */
				__( 'Done - %1$d item(s) assigned to %2$s.', 'perflocale' ),
				$total_assigned,
				Helper::format_locale_as_bcp47( (string) $default->slug )
			);

		wp_send_json_success(
			[
				'message'           => $message,
				'assigned'          => $total_assigned,
				'post_type_details' => $per_type,
				'remaining'         => $remaining,
			]
		);
	}

	/**
	 * Find a local WordPress translation for a string across all loaded text domains.
	 *
	 * A locale WordPress cannot switch to counts as a miss.
	 *
	 * @param string $text Original text to translate.
	 * @param string $locale Target WordPress locale (e.g., 'de_DE', 'ar').
	 * @return string Translated text, or empty string if no local translation found.
	 */
	private static function find_local_translation( string $text, string $locale ): string {
		// switch_to_locale() returns false without pushing a frame both when the locale is already
		// current and when it has no language pack; in the second case $l10n answers in the current
		// locale, and callers would save that as the target's title or name.
		$switched = switch_to_locale( $locale );

		if ( ! $switched && determine_locale() !== $locale ) {
			return '';
		}

		$result = '';

		global $l10n;

		if ( is_array( $l10n ) ) {
			foreach ( $l10n as $translations ) {
				$translated = $translations->translate( $text );

				if ( $translated !== $text && $translated !== '' ) {
					$result = $translated;
					break;
				}
			}
		}

		// Pop only a frame this call pushed.
		if ( $switched ) {
			restore_previous_locale();
		}

		return $result;
	}
}
