<?php
/**
 * Polylang data kept outside its translation groups.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\SourceType;
use PerfLocale\Translation\MenuManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports what a Polylang site keeps beside its translation groups:
 * - the menu each theme location shows per language (option `polylang`,
 *   `nav_menus`): the menus are labelled and linked the way PerfLocale's
 *   per-language menus are;
 * - string translations (`_pll_strings_translations` on each language term):
 *   the site title and tagline become option-string translations, and the
 *   date and time formats become the language's own formats when it has
 *   none; the rest has no place in PerfLocale and is counted;
 * - post types and taxonomies Polylang translated that PerfLocale does not
 *   treat as translatable yet;
 * - prices that differ between the language versions of a product, which
 *   PerfLocale's price sync would overwrite on the first edit.
 */
final class PolylangSiteData {

	/**
	 * MigrationState flag: this blog's Polylang import switched price sync off.
	 */
	private const PRICE_SYNC_FLAG = 'price_sync_off';

	/**
	 * Constructor.
	 *
	 * @param \wpdb              $wpdb         Database.
	 * @param LanguageRepository $languages    PerfLocale languages.
	 * @param array<string, int> $language_map Polylang slug => PerfLocale language ID.
	 * @param \Closure|null      $beat         The importer's heartbeat, called after each language's strings and each batch of products so a long step keeps the import lock and its job alive ({@see ImportHeartbeat}).
	 */
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly LanguageRepository $languages,
		private readonly array $language_map,
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
	 * Polylang's settings option.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		$settings = get_option( 'polylang', [] );

		if ( ! is_array( $settings ) ) {
			return [];
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
		/** @var array<string, mixed> $settings */
		return $settings;
	}

	/**
	 * PerfLocale language slug per Polylang slug, default language first.
	 *
	 * @return array<string, string>
	 */
	private function slugs(): array {
		$default = $this->languages->get_default();
		$slugs   = [];

		foreach ( $this->language_map as $pll_slug => $lang_id ) {
			$lang = $this->languages->find( (int) $lang_id );

			if ( $lang !== null ) {
				$slugs[ (string) $pll_slug ] = (string) $lang->slug;
			}
		}

		uasort(
			$slugs,
			static fn( string $a, string $b ): int => (int) ( $default !== null && $b === $default->slug ) - (int) ( $default !== null && $a === $default->slug )
		);

		return $slugs;
	}

	/**
	 * Label and link the menus each theme location shows per language.
	 *
	 * A set whose menus already carry another language or link is left
	 * alone (reported); a menu Polylang showed for two languages stays with
	 * the first of them.
	 *
	 * @param array<int, string> $errors Import errors, appended to.
	 * @return int Menu sets linked.
	 */
	public function import_menus( array &$errors ): int {
		$nav_menus = self::settings()['nav_menus'][ get_stylesheet() ] ?? null;

		if ( ! is_array( $nav_menus ) ) {
			return 0;
		}

		$slugs  = $this->slugs();
		$linked = 0;

		foreach ( $nav_menus as $location => $per_language ) {
			if ( ! is_array( $per_language ) ) {
				continue;
			}

			$set  = [];
			$used = [];

			foreach ( $slugs as $pll_slug => $slug ) {
				$menu_id = (int) ( $per_language[ $pll_slug ] ?? 0 );

				if ( $menu_id > 0 && ! isset( $used[ $menu_id ] ) ) {
					$set[ $slug ]     = $menu_id;
					$used[ $menu_id ] = true;
				}
			}

			if ( count( $set ) < 2 ) {
				continue;
			}

			$result = MenuManager::link_imported_menus( $set );

			if ( $result['conflict'] !== null ) {
				$errors[] = sprintf(
					/* translators: 1: theme menu location, 2: comma-separated menu IDs */
					__( 'The Polylang menus of the "%1$s" location (menus %2$s) were not linked: one of them already has a different language or link in PerfLocale.', 'perflocale' ),
					(string) $location,
					implode( ', ', array_values( $set ) )
				);
				continue;
			}

			if ( $result['written'] > 0 ) {
				++$linked;
			}
		}

		return $linked;
	}

	/**
	 * Import Polylang's string translations.
	 *
	 * @param array<int, string> $errors Import errors, appended to.
	 * @return int Translations stored (option strings and language formats).
	 */
	public function import_strings( array &$errors ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Values are bound through prepare(); table names through %i.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT t.slug, tm.meta_value FROM %i t
				INNER JOIN %i tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
				INNER JOIN %i tm ON tm.term_id = t.term_id AND tm.meta_key = %s',
				$this->wpdb->terms,
				$this->wpdb->term_taxonomy,
				'language',
				$this->wpdb->termmeta,
				'_pll_strings_translations'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) || $rows === [] ) {
			return 0;
		}

		$options = ImportedOptionStrings::sources();
		$formats = [
			'date_format' => (string) get_option( 'date_format', '' ),
			'time_format' => (string) get_option( 'time_format', '' ),
		];
		$stored  = 0;

		foreach ( $rows as $row ) {
			$this->keep_alive();

			$lang_id = $this->language_map[ (string) $row->slug ] ?? null;

			if ( $lang_id === null ) {
				continue;
			}

			// allowed_classes=false: term meta is user-written data, so no
			// object is created from it.
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false is the mitigation the rule asks for; @ hides the notice for a class it refuses.
			$pairs = is_serialized( (string) $row->meta_value )
				? @unserialize( (string) $row->meta_value, [ 'allowed_classes' => false ] )
				: null;
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! is_array( $pairs ) ) {
				continue;
			}

			$language  = $this->languages->find( (int) $lang_id );
			$unplaced  = 0;
			$lang_data = [];

			foreach ( $pairs as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || ! is_string( $pair[0] ) || ! is_string( $pair[1] ) ) {
					continue;
				}

				[ $original, $translation ] = $pair;

				if ( $original === '' || $translation === '' || $translation === $original ) {
					continue;
				}

				$option = array_search( $original, $options, true );

				if ( $option !== false ) {
					if ( ImportedOptionStrings::save( (string) $option, $original, (int) $lang_id, html_entity_decode( $translation, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), SourceType::ImportedPolylang ) ) {
						++$stored;
					}
					continue;
				}

				$format = array_search( $original, $formats, true );

				if ( $format !== false ) {
					if ( $language !== null && (string) ( $language->{$format} ?? '' ) === '' ) {
						$lang_data[ $format ] = $translation;
					}
					continue;
				}

				++$unplaced;
			}

			if ( $lang_data !== [] && $this->languages->update( (int) $lang_id, $lang_data ) ) {
				$stored += count( $lang_data );
			}

			if ( $unplaced > 0 ) {
				$errors[] = sprintf(
					/* translators: 1: number of strings, 2: language slug */
					_n( '%1$d Polylang string translation in %2$s was not imported: PerfLocale has no place for it (widget titles and texts, and strings a theme or plugin registered with Polylang).', '%1$d Polylang string translations in %2$s were not imported: PerfLocale has no place for them (widget titles and texts, and strings a theme or plugin registered with Polylang).', $unplaced, 'perflocale' ),
					$unplaced,
					$language !== null ? (string) $language->slug : (string) $row->slug
				);
			}
		}

		return $stored;
	}

	/**
	 * Make the post types and taxonomies Polylang translated translatable.
	 *
	 * Those are the types Polylang's settings list and the types of the
	 * posts and terms this import linked, when they are registered.
	 * Attachments stay out: PerfLocale translates media in place.
	 *
	 * @param \PerfLocale\Settings $settings PerfLocale settings.
	 * @param array<int, string>   $errors   Import errors, appended to.
	 * @return list<string> Post types and taxonomies added.
	 */
	public function import_translatable_types( \PerfLocale\Settings $settings, array &$errors ): array {
		$pll   = self::settings();
		$links = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Values are bound through prepare(); table names through %i.
		$post_types = (array) $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT p.post_type FROM %i l INNER JOIN %i p ON p.ID = l.object_id WHERE l.type = %s AND l.source = %s',
				$links,
				$this->wpdb->posts,
				'post',
				SourceType::ImportedPolylang->value
			)
		);
		$taxonomies = (array) $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT tt.taxonomy FROM %i l INNER JOIN %i tt ON tt.term_id = l.object_id WHERE l.type = %s AND l.source = %s',
				$links,
				$this->wpdb->term_taxonomy,
				'term',
				SourceType::ImportedPolylang->value
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$post_types = array_merge( $post_types, is_array( $pll['post_types'] ?? null ) ? $pll['post_types'] : [] );
		$taxonomies = array_merge( $taxonomies, is_array( $pll['taxonomies'] ?? null ) ? $pll['taxonomies'] : [] );

		$current_types = $settings->get_translatable_post_types();
		$current_taxes = $settings->get_translatable_taxonomies();
		$new_types     = [];
		$new_taxes     = [];

		foreach ( array_unique( array_map( 'strval', $post_types ) ) as $type ) {
			if ( $type !== 'attachment' && post_type_exists( $type ) && ! in_array( $type, $current_types, true ) ) {
				$new_types[] = $type;
			}
		}

		foreach ( array_unique( array_map( 'strval', $taxonomies ) ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) && ! in_array( $taxonomy, $current_taxes, true ) ) {
				$new_taxes[] = $taxonomy;
			}
		}

		if ( $new_types === [] && $new_taxes === [] ) {
			return [];
		}

		$update = [];

		if ( $new_types !== [] ) {
			$update['translatable_post_types'] = array_values( array_merge( (array) $settings->get( 'translatable_post_types', [] ), $new_types ) );
		}

		if ( $new_taxes !== [] ) {
			$update['translatable_taxonomies'] = array_values( array_merge( (array) $settings->get( 'translatable_taxonomies', [] ), $new_taxes ) );
		}

		$added = array_merge( $new_types, $new_taxes );

		if ( ! $settings->update( $update ) ) {
			$errors[] = sprintf(
				/* translators: %s: comma-separated post type and taxonomy names */
				__( 'Polylang translated %s, but PerfLocale could not save them as translatable. Tick them under PerfLocale → Settings → Translation.', 'perflocale' ),
				implode( ', ', $added )
			);
			return [];
		}

		$errors[] = sprintf(
			/* translators: %s: comma-separated post type and taxonomy names */
			__( 'Polylang translated %s, so PerfLocale now treats them as translatable too.', 'perflocale' ),
			implode( ', ', $added )
		);

		return $added;
	}

	/**
	 * Switch price sync off when Polylang kept different prices per language.
	 *
	 * Price sync copies one language's price fields to the others on every
	 * product save, so the first edit would overwrite the other languages'
	 * own prices (a sale price in one language only, say). It is switched
	 * off once per blog; a later run that finds it on again (the operator's
	 * choice) keeps it and warns.
	 *
	 * @param \PerfLocale\Settings $settings PerfLocale settings.
	 * @param array<int, string>   $errors   Import errors, appended to.
	 * @return int Products whose language versions have different prices.
	 */
	public function keep_prices_per_language( \PerfLocale\Settings $settings, array &$errors ): int {
		if ( ! post_type_exists( 'product' ) || ! (bool) $settings->get( 'wc_sync_prices', true ) ) {
			return 0;
		}

		// Every product in a group this import linked, whatever wrote the
		// other members' links (a product saved under PerfLocale before the
		// import keeps its own link).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Values are bound through prepare(); table names through %i.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT l.group_id, p.ID FROM %i l INNER JOIN %i p ON p.ID = l.object_id AND p.post_type = %s
				WHERE l.type = %s AND l.group_id IN ( SELECT i.group_id FROM %i i WHERE i.type = %s AND i.source = %s )',
				Schema::table( 'translation_links' ),
				$this->wpdb->posts,
				'product',
				'post',
				Schema::table( 'translation_links' ),
				'post',
				SourceType::ImportedPolylang->value
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$prices = [];

		foreach ( array_chunk( (array) $rows, 200 ) as $chunk ) {
			$ids = array_map( static fn( object $row ): int => (int) $row->ID, $chunk );

			update_meta_cache( 'post', $ids );

			foreach ( $chunk as $row ) {
				$prices[ (int) $row->group_id ][ self::price_signature( (int) $row->ID ) ] = true;
			}

			$this->keep_alive();
		}

		$differing = count( array_filter( $prices, static fn( array $set ): bool => count( $set ) > 1 ) );

		if ( $differing === 0 ) {
			return 0;
		}

		// Price Sync is switched off once. When it is on again after that,
		// the operator switched it on: keep it and only warn.
		if ( MigrationState::has_flag( 'polylang', self::PRICE_SYNC_FLAG ) || ! $settings->update( [ 'wc_sync_prices' => false ] ) ) {
			$errors[] = sprintf(
				/* translators: %d: number of products */
				_n( 'The language versions of %d product have different prices, as Polylang kept them. Switch off Price Sync under PerfLocale → Settings → Addons → WooCommerce before editing it, or the first edit copies one language\'s prices to the others.', 'The language versions of %d products have different prices, as Polylang kept them. Switch off Price Sync under PerfLocale → Settings → Addons → WooCommerce before editing them, or the first edit copies one language\'s prices to the others.', $differing, 'perflocale' ),
				$differing
			);
			return $differing;
		}

		MigrationState::set_flag( 'polylang', self::PRICE_SYNC_FLAG );

		$errors[] = sprintf(
			/* translators: %d: number of products */
			_n( 'The language versions of %d product have different prices, as Polylang kept them, so Price Sync (PerfLocale → Settings → Addons → WooCommerce) was switched off: editing one language\'s price no longer changes the others. Switch it on again if every language should share one price.', 'The language versions of %d products have different prices, as Polylang kept them, so Price Sync (PerfLocale → Settings → Addons → WooCommerce) was switched off: editing one language\'s price no longer changes the others. Switch it on again if every language should share one price.', $differing, 'perflocale' ),
			$differing
		);

		return $differing;
	}

	/**
	 * A product's own prices and its variations' prices, as one comparable string.
	 *
	 * Variations are compared as a sorted list: their IDs differ between the
	 * language versions.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function price_signature( int $product_id ): string {
		$own = get_post_meta( $product_id, '_regular_price', true ) . '/' . get_post_meta( $product_id, '_sale_price', true );

		// An empty 'lang' is Polylang's "every language": while Polylang is
		// active, the query is otherwise limited to its current language.
		$variation_ids = get_posts(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $product_id,
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => -1,
				'lang'        => '',
			]
		);

		$variations = [];

		foreach ( $variation_ids as $variation_id ) {
			$variations[] = get_post_meta( (int) $variation_id, '_regular_price', true ) . '/' . get_post_meta( (int) $variation_id, '_sale_price', true );
		}

		sort( $variations );

		return $own . '#' . implode( ',', $variations );
	}
}
