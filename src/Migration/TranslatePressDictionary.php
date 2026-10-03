<?php
/**
 * TranslatePress dictionary strings that belong to no post.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Enum\SourceType;
use PerfLocale\Translation\PostTranslationManager;
use PerfLocale\Translation\TermTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts TranslatePress translations of text outside post content where
 * PerfLocale keeps that text.
 *
 * TranslatePress translates rendered text and attributes a string to a post
 * only when it was found in that post's content. Everything else is matched
 * here by its exact original text:
 * - term names and descriptions: the term's translation (created when the
 *   term has none in that language);
 * - menu labels: a copy of each menu in a theme location for that language,
 *   linked to the menu it copies;
 * - the site title and tagline: PerfLocale's option strings;
 * - excerpts and WooCommerce short descriptions: the created translation's
 *   excerpt ({@see self::excerpt()}).
 *
 * What matches none of them is counted ({@see self::unmatched_count()}).
 *
 * A dictionary or term read that fails throws a
 * {@see TranslatePressSourceReadException}: a failed read is never taken as
 * "no translation".
 */
final class TranslatePressDictionary {

	/**
	 * Originals per dictionary query.
	 */
	private const LOOKUP_CHUNK = 100;

	/**
	 * Terms read per batch.
	 */
	private const TERM_BATCH = 500;

	/**
	 * Dictionary original ids used, per dictionary table.
	 *
	 * @var array<string, array<int, true>>
	 */
	private array $matched = [];

	/**
	 * What this run wrote so far: term translations created, menu copies
	 * created, option-string translations stored.
	 *
	 * @var array{terms: int, menus: int, strings: int}
	 */
	private array $written = [
		'terms'   => 0,
		'menus'   => 0,
		'strings' => 0,
	];

	/**
	 * Constructor.
	 *
	 * @param \wpdb         $wpdb       Database.
	 * @param CacheManager  $cache      Cache manager.
	 * @param int           $min_status Lowest TranslatePress status imported.
	 * @param \Closure|null $beat       The importer's heartbeat, called after each batch of terms and each menu so a long step keeps the import lock and its job alive ({@see ImportHeartbeat}).
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly CacheManager $cache,
		private readonly int $min_status,
		private readonly ?\Closure $beat = null
	) {}

	/**
	 * Run the importer's heartbeat, if one is set.
	 *
	 * @return void
	 */
	private function keep_alive(): void {
		if ( $this->beat !== null ) {
			( $this->beat )();
		}
	}

	/**
	 * What this run wrote so far, also when a step stopped at a failed read.
	 *
	 * @return array{terms: int, menus: int, strings: int}
	 */
	public function written(): array {
		return $this->written;
	}

	/**
	 * Throw when the read that just ran failed.
	 *
	 * @param string $where What was being read.
	 * @return void
	 * @throws TranslatePressSourceReadException When the read failed.
	 */
	private function check_read( string $where ): void {
		if ( $this->wpdb->last_error !== '' ) {
			throw new TranslatePressSourceReadException( $where, $this->wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by the importer; the message is never printed.
		}
	}

	/**
	 * The forms a text can have in TranslatePress's dictionary.
	 *
	 * TranslatePress stores what the page showed: entities as printed
	 * (an ampersand as &amp;, &#38; or &#038;), and titles, labels, term
	 * names and excerpts after wptexturize() (curly quotes, dashes,
	 * ellipses).
	 *
	 * @param string $text Text as stored in WordPress.
	 * @return list<string>
	 */
	public static function forms( string $text ): array {
		$text = trim( $text );

		if ( $text === '' ) {
			return [];
		}

		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$forms   = [ $text, wptexturize( $text ), $decoded, wptexturize( $decoded ) ];

		foreach ( $forms as $form ) {
			$forms[] = str_replace( [ '&amp;', '&#038;' ], '&#38;', $form );
			$forms[] = str_replace( [ '&#38;', '&#038;' ], '&amp;', $form );
		}

		return array_values( array_unique( array_map( 'trim', $forms ) ) );
	}

	/**
	 * Plain text as HTML text: &, < and > escaped, quotes left as typed.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function as_text_html( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8', false );
	}

	/**
	 * A translation from the dictionary (entities decoded) as a menu item
	 * label.
	 *
	 * Markup in it is filtered the way core filters the title of a menu item
	 * saved by a user without unfiltered_html (wp_kses() with the
	 * `title_save_pre` allowlist), whoever runs the import: the importer
	 * usually holds unfiltered_html, so core filters nothing on its writes,
	 * and the decoded text would otherwise reach every visitor as markup. A
	 * label without `<` holds no markup and is kept as it is.
	 *
	 * @param string $translated Decoded translation.
	 * @return string The label, or '' when nothing is left of it.
	 */
	private static function as_menu_label( string $translated ): string {
		if ( ! str_contains( $translated, '<' ) ) {
			return $translated;
		}

		return trim( wp_kses( $translated, 'title_save_pre' ) );
	}

	/**
	 * Exact dictionary matches for a list of originals.
	 *
	 * The column's collation compares without case or accents, so every row
	 * is checked again for an exact match here.
	 *
	 * @param string             $table Dictionary table.
	 * @param array<int, string> $texts Originals.
	 * @return array<string, array{0: string, 1: int}> Original => [ translation, original id ].
	 * @throws TranslatePressSourceReadException When a dictionary read fails.
	 */
	private function lookup( string $table, array $texts ): array {
		$texts = array_values( array_unique( array_filter( $texts, static fn( string $t ): bool => $t !== '' ) ) );
		$found = [];

		foreach ( array_chunk( $texts, self::LOOKUP_CHUNK ) as $chunk ) {
			$wanted       = array_fill_keys( $chunk, true );
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is one %s per value; the table name goes through %i.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT original, translated, original_id FROM %i WHERE original IN ({$placeholders}) AND status >= %d AND translated != '' ORDER BY id ASC",
					$table,
					...array_merge( $chunk, [ $this->min_status ] )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$this->check_read( 'reading the dictionary' );

			foreach ( (array) $rows as $row ) {
				$original = (string) $row->original;

				if ( isset( $wanted[ $original ] ) && ! isset( $found[ $original ] ) ) {
					$found[ $original ] = [ (string) $row->translated, (int) $row->original_id ];
				}
			}
		}

		return $found;
	}

	/**
	 * The translation of one text from a lookup result, marked as used.
	 *
	 * @param string                                  $table Dictionary table.
	 * @param array<string, array{0: string, 1: int}> $found lookup() result.
	 * @param string                                  $text  Text as stored in WordPress.
	 * @return string|null Plain text (entities decoded), or null.
	 */
	private function pick( string $table, array $found, string $text ): ?string {
		foreach ( self::forms( $text ) as $form ) {
			if ( isset( $found[ $form ] ) ) {
				$this->matched[ $table ][ $found[ $form ][1] ] = true;

				$translated = trim( html_entity_decode( $found[ $form ][0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

				return $translated !== '' ? $translated : null;
			}
		}

		return null;
	}

	/**
	 * The translation of one text, or null.
	 *
	 * @param string $table Dictionary table.
	 * @param string $text  Text as stored in WordPress.
	 * @return string|null
	 */
	private function translate( string $table, string $text ): ?string {
		$forms = self::forms( $text );

		return $forms === [] ? null : $this->pick( $table, $this->lookup( $table, $forms ), $text );
	}

	/**
	 * An excerpt translated from the dictionary: the whole text, or else
	 * each text between tags and line breaks that has a translation
	 * (a WooCommerce short description is HTML; TranslatePress stores the
	 * text of each element).
	 *
	 * @param string $table   Dictionary table.
	 * @param string $excerpt Source excerpt.
	 * @return string|null The translated excerpt, or null when nothing matched.
	 * @throws TranslatePressSourceReadException When a dictionary read fails.
	 */
	public function excerpt( string $table, string $excerpt ): ?string {
		if ( trim( $excerpt ) === '' ) {
			return null;
		}

		$whole = $this->translate( $table, $excerpt );

		if ( $whole !== null ) {
			return self::as_text_html( $whole );
		}

		$parts = preg_split( '/(<[^>]*>|\R+)/', $excerpt, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return null;
		}

		$forms = [];

		foreach ( $parts as $i => $part ) {
			if ( $i % 2 === 0 ) {
				$forms = array_merge( $forms, self::forms( $part ) );
			}
		}

		$found   = $this->lookup( $table, $forms );
		$changed = false;

		foreach ( $parts as $i => $part ) {
			$translated = ( $i % 2 === 1 || trim( $part ) === '' ) ? null : $this->pick( $table, $found, $part );

			if ( $translated !== null ) {
				// Keep the whitespace around the text; the translation is plain
				// text inside markup.
				preg_match( '/^(\s*).*?(\s*)$/s', $part, $space );
				$parts[ $i ] = ( $space[1] ?? '' ) . self::as_text_html( $translated ) . ( $space[2] ?? '' );
				$changed     = true;
			}
		}

		return $changed ? implode( '', $parts ) : null;
	}

	/**
	 * Translate term names (and descriptions) of the translatable taxonomies.
	 *
	 * A term in the source language that has no translation in the target
	 * language and whose name has a dictionary translation gets one, created
	 * the way PerfLocale creates term translations and then renamed. A term
	 * that already has a translation in that language keeps it.
	 *
	 * @param string             $table       Dictionary table.
	 * @param string             $source_slug Source language slug.
	 * @param string             $target_slug Target language slug.
	 * @param array<int, string> $taxonomies  Translatable taxonomies.
	 * @param array<int, string> $errors      Import errors, appended to.
	 * @return int Term translations created.
	 * @throws TranslatePressSourceReadException When a term or dictionary read fails.
	 */
	public function import_terms( string $table, string $source_slug, string $target_slug, array $taxonomies, array &$errors ): int {
		$taxonomies = array_values( array_filter( $taxonomies, 'taxonomy_exists' ) );

		if ( $taxonomies === [] ) {
			return 0;
		}

		$terms   = new TermTranslationManager( $this->cache );
		$created = 0;
		$failed  = 0;
		$last_id = 0;

		do {
			$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is one %s per taxonomy; table names go through %i.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, t.name
					FROM %i tt INNER JOIN %i t ON t.term_id = tt.term_id
					WHERE tt.taxonomy IN ({$placeholders}) AND tt.term_taxonomy_id > %d
					ORDER BY tt.term_taxonomy_id ASC LIMIT %d",
					...array_merge( [ $this->wpdb->term_taxonomy, $this->wpdb->terms ], $taxonomies, [ $last_id, self::TERM_BATCH ] )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$this->check_read( 'reading the terms' );

			if ( ! is_array( $rows ) || $rows === [] ) {
				break;
			}

			$last_id   = (int) end( $rows )->term_taxonomy_id;
			$row_count = count( $rows );
			$forms     = [];

			foreach ( $rows as $row ) {
				$forms = array_merge( $forms, self::forms( (string) $row->name ), self::forms( (string) $row->description ) );
			}

			$found = $this->lookup( $table, $forms );

			foreach ( $rows as $row ) {
				$term_id     = (int) $row->term_id;
				$name        = $this->pick( $table, $found, (string) $row->name );
				$description = $this->pick( $table, $found, (string) $row->description );

				if ( $name === null ) {
					continue;
				}

				$language = $terms->detect_term_language( $term_id );

				if ( ( $language !== null && (string) $language->slug !== $source_slug ) || $terms->get_translation_id( $term_id, $target_slug ) !== null ) {
					continue;
				}

				$was_created = false;
				$new_id      = $terms->create_translation( $term_id, (string) $row->taxonomy, $target_slug, true, SourceType::ImportedTrp, $was_created );

				if ( ! $new_id || ! $was_created ) {
					++$failed;
					continue;
				}

				$update = [ 'name' => $name ];

				if ( $description !== null ) {
					$update['description'] = $description;
				}

				$updated = wp_update_term( (int) $new_id, (string) $row->taxonomy, wp_slash( $update ) );

				if ( is_wp_error( $updated ) ) {
					++$failed;
					continue;
				}

				++$created;
				++$this->written['terms'];
			}

			$this->keep_alive();
		} while ( $row_count === self::TERM_BATCH );

		if ( $failed > 0 ) {
			$errors[] = sprintf(
				/* translators: 1: number of terms, 2: language slug */
				_n( '%1$d term name translated in TranslatePress could not be added as a %2$s term translation.', '%1$d term names translated in TranslatePress could not be added as %2$s term translations.', $failed, 'perflocale' ),
				$failed,
				$target_slug
			);
		}

		return $created;
	}

	/**
	 * Copy each menu in a theme location into the target language with its
	 * translated labels, and link the copy to the menu it copies.
	 *
	 * Only a menu with at least one translated label is copied; a menu that
	 * already points to a menu for that language, or carries another
	 * language, is left alone. Items that point to a post or term point to
	 * its translation in the target language when there is one (and then
	 * show that translation's title unless the item had a label of its own).
	 *
	 * @param string             $table       Dictionary table.
	 * @param string             $source_slug Source language slug.
	 * @param string             $target_slug Target language slug.
	 * @param array<int, string> $errors      Import errors, appended to.
	 * @return int Menus created.
	 * @throws TranslatePressSourceReadException When a dictionary read fails.
	 */
	public function import_menus( string $table, string $source_slug, string $target_slug, array &$errors ): int {
		$menu_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) ) ) );
		$created  = 0;

		if ( $menu_ids === [] ) {
			return 0;
		}

		$plugin = \PerfLocale\Plugin::get_instance();
		$posts  = new PostTranslationManager( $this->cache, $plugin->get( 'settings' ) );
		$terms  = new TermTranslationManager( $this->cache );

		foreach ( $menu_ids as $menu_id ) {
			$this->keep_alive();

			$menu = wp_get_nav_menu_object( $menu_id );

			if ( ! $menu instanceof \WP_Term ) {
				continue;
			}

			$label = (string) get_term_meta( $menu_id, '_perflocale_language', true );

			if ( $label !== '' && $label !== $source_slug ) {
				continue;
			}

			$items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );

			if ( ! is_array( $items ) || $items === [] ) {
				continue;
			}

			$forms = [];

			foreach ( $items as $item ) {
				$forms = array_merge( $forms, self::forms( (string) $item->title ) );
			}

			$found  = $this->lookup( $table, $forms );
			$labels = [];

			foreach ( $items as $item ) {
				$translated = $this->pick( $table, $found, (string) $item->title );
				$translated = $translated !== null ? self::as_menu_label( $translated ) : '';

				if ( $translated !== '' ) {
					$labels[ (int) $item->ID ] = $translated;
				}
			}

			// A menu already linked to a menu for this language (an earlier run,
			// or the site's own) is left alone; its labels still count as placed.
			$pointer = (int) get_term_meta( $menu_id, '_perflocale_menu_' . $target_slug, true );

			if ( $labels === [] || ( $pointer > 0 && is_nav_menu( $pointer ) ) ) {
				continue;
			}

			$copy_id = wp_create_nav_menu( wp_slash( $menu->name . ' (' . $target_slug . ')' ) );

			if ( is_wp_error( $copy_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: menu name, 2: language slug */
					__( 'The %2$s copy of the menu "%1$s" could not be created, so its TranslatePress labels were not imported.', 'perflocale' ),
					$menu->name,
					$target_slug
				);
				continue;
			}

			$new_ids = [];

			foreach ( $items as $item ) {
				$object_id = (int) $item->object_id;
				$mapped    = 0;

				if ( $item->type === 'post_type' ) {
					$mapped = (int) ( $posts->get_translations( $object_id )[ $target_slug ] ?? 0 );
				} elseif ( $item->type === 'taxonomy' ) {
					$mapped = (int) $terms->get_translation_id( $object_id, $target_slug );
				}

				$own_label = (string) get_post_field( 'post_title', (int) $item->ID, 'raw' );
				$title     = $labels[ (int) $item->ID ] ?? $own_label;

				// An item without a label of its own shows its object's title;
				// the translation's title is already in the target language.
				if ( $mapped > 0 && $own_label === '' ) {
					$title = '';
				}

				$new_item = wp_update_nav_menu_item(
					(int) $copy_id,
					0,
					wp_slash(
						[
							'menu-item-object-id'   => $mapped > 0 ? $mapped : $object_id,
							'menu-item-object'      => (string) $item->object,
							'menu-item-parent-id'   => $new_ids[ (int) $item->menu_item_parent ] ?? 0,
							'menu-item-position'    => (int) $item->menu_order,
							'menu-item-type'        => (string) $item->type,
							'menu-item-title'       => $title,
							'menu-item-url'         => $item->type === 'custom' ? (string) $item->url : '',
							'menu-item-description' => (string) $item->description,
							'menu-item-attr-title'  => (string) $item->attr_title,
							'menu-item-target'      => (string) $item->target,
							'menu-item-classes'     => implode( ' ', array_filter( (array) $item->classes ) ),
							'menu-item-xfn'         => (string) $item->xfn,
							'menu-item-status'      => 'publish',
						]
					)
				);

				if ( ! is_wp_error( $new_item ) ) {
					$new_ids[ (int) $item->ID ] = (int) $new_item;
				}
			}

			update_term_meta( (int) $copy_id, '_perflocale_language', $target_slug );
			update_term_meta( $menu_id, '_perflocale_menu_' . $target_slug, (int) $copy_id );

			++$created;
			++$this->written['menus'];
		}

		return $created;
	}

	/**
	 * Import the site title and tagline translations.
	 *
	 * An option that already has a translation in the language keeps it.
	 *
	 * @param string $table       Dictionary table.
	 * @param int    $language_id Target PerfLocale language ID.
	 * @return int Translations stored.
	 * @throws TranslatePressSourceReadException When a dictionary read fails.
	 */
	public function import_option_strings( string $table, int $language_id ): int {
		$stored = 0;

		foreach ( ImportedOptionStrings::sources() as $option => $raw ) {
			$translated = $this->translate( $table, $raw );

			if ( $translated !== null && ImportedOptionStrings::save( $option, $raw, $language_id, $translated, SourceType::ImportedTrp ) ) {
				++$stored;
				++$this->written['strings'];
			}
		}

		return $stored;
	}

	/**
	 * Translated dictionary strings of one language that belong to no post
	 * and matched nothing above.
	 *
	 * @param string $table      Dictionary table.
	 * @param string $meta_table TranslatePress original-meta table.
	 * @return int
	 */
	public function unmatched_count( string $table, string $meta_table ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Values are bound through prepare(); table names through %i.
		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(DISTINCT d.original_id) FROM %i d
				LEFT JOIN %i m ON m.original_id = d.original_id AND m.meta_key = 'post_parent_id'
				WHERE m.original_id IS NULL AND d.original_id IS NOT NULL AND d.status >= %d AND d.translated != ''",
				$table,
				$meta_table,
				$this->min_status
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$used     = array_keys( $this->matched[ $table ] ?? [] );
		$attached = 0;

		foreach ( array_chunk( $used, self::LOOKUP_CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is one %d per id; the table name goes through %i.
			$attached += (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(DISTINCT original_id) FROM %i WHERE meta_key = 'post_parent_id' AND original_id IN ({$placeholders})",
					$meta_table,
					...$chunk
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		return max( 0, $total - ( count( $used ) - $attached ) );
	}
}
