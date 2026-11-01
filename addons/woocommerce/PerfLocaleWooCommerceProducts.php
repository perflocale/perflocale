<?php
/**
 * PerfLocale WooCommerce addon: product writes, loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product writes of the WooCommerce addon: what a new product translation copies (type, catalog terms, variations,
 * lookup row), machine translation of variation texts, and the shared-SKU sibling check.
 */
final class PerfLocaleWooCommerceProducts {

	/**
	 * Product type mirror_product_type() gave each translation created in this request.
	 * Keyed "<blog id>:<post id>"; refresh_copy_lookup_row() reads and clears it.
	 *
	 * @var array<string, string>
	 */
	private array $created_product_types = [];

	/**
	 * The addon's term translation manager.
	 *
	 * @var \Closure(): \PerfLocale\Translation\TermTranslationManager
	 */
	private \Closure $term_manager;

	/**
	 * Constructor.
	 *
	 * @param \Closure(): \PerfLocale\Translation\TermTranslationManager $term_manager The addon's term translation manager.
	 */
	public function __construct( \Closure $term_manager ) {
		$this->term_manager = $term_manager;
	}

	/**
	 * Give a freshly-created product translation the SOURCE's product_type.
	 *
	 * The product_type taxonomy is internal and no copy carries it; without it wc_get_product() loads
	 * a WC_Product_Simple. Priority 15: before the variation clone (20) loads the product.
	 * Also mirrors visibility, shipping class and brand through mirror_catalog_terms().
	 *
	 * @param int    $new_id      Newly created translation post ID.
	 * @param string $object_type Object type ('post' for post translations).
	 * @param string $target_slug Target language slug (unused: product_type is never translated).
	 * @param int    $source_id   Source post ID.
	 * @return void
	 */
	public function mirror_product_type( $new_id, $object_type, $target_slug, $source_id ): void {
		if ( 'post' !== (string) $object_type ) {
			return;
		}

		$new_id    = (int) $new_id;
		$source_id = (int) $source_id;

		if ( $new_id <= 0 || $source_id <= 0 || 'product' !== get_post_type( $source_id ) ) {
			return;
		}

		$types = wp_get_object_terms( $source_id, 'product_type', [ 'fields' => 'slugs' ] );

		// No product_type term means simple, for the source and the translation alike.
		if ( ! is_wp_error( $types ) && $types !== [] ) {
			// Not translated: these slugs are machinery, and translated ones resolve no product class.
			wp_set_object_terms( $new_id, array_map( 'strval', $types ), 'product_type' );
			$this->created_product_types[ get_current_blog_id() . ':' . $new_id ] = (string) reset( $types );
		}

		$this->mirror_catalog_terms( $new_id, $source_id );

		// WC caches a product's type on first load; a published translation was loaded before it had
		// the term, so drop the cached 'simple'.
		if ( class_exists( \WC_Cache_Helper::class ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'product_' . $new_id );
		}
	}

	/**
	 * Clone a variable product's variations onto its freshly-created translation.
	 *
	 * SKUs are copied verbatim (siblings share inventory), so unique-SKU validation is suspended for
	 * the clone; attribute options and values map to the target language's terms. Idempotent.
	 *
	 * @param int    $new_id      Newly created translation post ID.
	 * @param string $object_type Object type ('post' for post translations).
	 * @param string $target_slug Target language slug.
	 * @param int    $source_id   Source post ID.
	 * @return void
	 */
	public function clone_product_variations( $new_id, $object_type, $target_slug, $source_id ): void {
		if ( 'post' !== (string) $object_type || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		if ( 'product' !== get_post_type( (int) $source_id ) ) {
			return;
		}

		try {
			$source = wc_get_product( (int) $source_id );

			if ( ! $source instanceof \WC_Product || ! $source->is_type( 'variable' ) ) {
				return;
			}

			// Set the type to variable BEFORE loading the target, so WC returns the right class.
			wp_set_object_terms( (int) $new_id, 'variable', 'product_type' );

			$target = wc_get_product( (int) $new_id );

			if ( ! $target instanceof \WC_Product_Variable ) {
				return;
			}

			// Idempotency: never duplicate existing children.
			if ( [] !== $target->get_children() ) {
				return;
			}

			// Taxonomy attribute options must name the TARGET language's terms: the dropdown offers only terms
			// in both the options and the product's (sibling) terms, so source options leave it empty.
			$target->set_attributes( $this->translate_attribute_options( $source->get_attributes(), (string) $target_slug ) );
			$target->save();

			// Translation siblings share SKUs by design; suspend WC's
			// unique-SKU validation for the duration of the clone only.
			add_filter( 'wc_product_has_unique_sku', '__return_false', 999 );

			try {
				foreach ( $source->get_children() as $variation_id ) {
					$sv = wc_get_product( (int) $variation_id );

					if ( ! $sv instanceof \WC_Product_Variation ) {
						continue;
					}

					$nv = new \WC_Product_Variation();
					$nv->set_parent_id( (int) $new_id );
					// Values are term slugs and move with the parent's options: find_matching_product_variation()
					// matches the posted attribute_pa_* value against this meta.
					$nv->set_attributes( $this->translate_variation_attributes( $sv->get_attributes(), (string) $target_slug ) );
					$nv->set_status( $sv->get_status() );
					$nv->set_regular_price( (string) $sv->get_regular_price( 'edit' ) );
					$nv->set_sale_price( (string) $sv->get_sale_price( 'edit' ) );
					// Copy the sale window: without _sale_price_dates_to the sale never ends (or a future one starts today).
					$nv->set_date_on_sale_from( $sv->get_date_on_sale_from( 'edit' ) );
					$nv->set_date_on_sale_to( $sv->get_date_on_sale_to( 'edit' ) );
					$nv->set_manage_stock( (bool) $sv->get_manage_stock( 'edit' ) );
					$nv->set_backorders( (string) $sv->get_backorders( 'edit' ) );
					$nv->set_low_stock_amount( $sv->get_low_stock_amount( 'edit' ) );

					if ( $sv->get_manage_stock( 'edit' ) ) {
						$nv->set_stock_quantity( $sv->get_stock_quantity( 'edit' ) );
					}

					$nv->set_stock_status( (string) $sv->get_stock_status( 'edit' ) );
					$nv->set_virtual( (bool) $sv->get_virtual( 'edit' ) );
					$nv->set_downloadable( (bool) $sv->get_downloadable( 'edit' ) );

					if ( $sv->get_downloadable( 'edit' ) ) {
						// Without the files, the downloadable flag grants no download permission at purchase.
						$nv->set_downloads( $sv->get_downloads() );
						$nv->set_download_limit( $sv->get_download_limit( 'edit' ) );
						$nv->set_download_expiry( $sv->get_download_expiry( 'edit' ) );
					}

					$nv->set_weight( (string) $sv->get_weight( 'edit' ) );
					$nv->set_length( (string) $sv->get_length( 'edit' ) );
					$nv->set_width( (string) $sv->get_width( 'edit' ) );
					$nv->set_height( (string) $sv->get_height( 'edit' ) );
					$nv->set_tax_class( (string) $sv->get_tax_class( 'edit' ) );
					// Per-variation shipping class (e.g. "bulky") - without it the
					// clone falls back to the parent/none and is charged wrong.
					$nv->set_shipping_class_id( (int) $sv->get_shipping_class_id( 'edit' ) );
					$nv->set_menu_order( (int) $sv->get_menu_order( 'edit' ) );
					$nv->set_image_id( (int) $sv->get_image_id( 'edit' ) );
					// Description seeds the translation; the translator owns it after.
					$nv->set_description( (string) $sv->get_description( 'edit' ) );

					$sku = (string) $sv->get_sku( 'edit' );

					if ( '' !== $sku ) {
						$nv->set_sku( $sku );
					}

					$nv->save();
					require_once __DIR__ . '/PerfLocaleWooCommerceVariationText.php';
					\PerfLocaleWooCommerceVariationText::keep_backslashes( $nv );
				}
			} finally {
				remove_filter( 'wc_product_has_unique_sku', '__return_false', 999 );
			}

			// Rebuild the parent's price range / stock rollups + lookup row.
			\WC_Product_Variable::sync( (int) $new_id );

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $new_id );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PerfLocale WC: variation clone failed for translation ' . (int) $new_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Build the wc_product_meta_lookup row of a freshly-created, non-variable product translation.
	 *
	 * Translations skip WC's CRUD create, so they get no row (wrong price sort, no SKU search). Built
	 * from meta by the data store's update_lookup_table(); never a CRUD save (save hooks) or
	 * wc_update_product_stock() (its sibling mirror could overwrite a concurrent sale).
	 *
	 * @param int    $new_id      Newly created translation post ID.
	 * @param string $object_type Object type ('post' for post translations).
	 * @return void
	 */
	public function refresh_copy_lookup_row( $new_id, $object_type ): void {
		if ( 'post' !== (string) $object_type || ! function_exists( 'WC' ) || ! class_exists( \WC_Data_Store::class ) || ! class_exists( \WC_Product_Factory::class ) ) {
			return;
		}

		$new_id = (int) $new_id;
		$key    = get_current_blog_id() . ':' . $new_id;
		$type   = $this->created_product_types[ $key ] ?? null;

		unset( $this->created_product_types[ $key ] );

		if ( $new_id <= 0 || 'product' !== get_post_type( $new_id ) ) {
			return;
		}

		try {
			$type = $type ?? \WC_Product_Factory::get_product_type( $new_id );

			if ( ! is_string( $type ) || $type === '' || 'variable' === $type ) {
				return;
			}

			$store = \WC_Data_Store::load( 'product-' . sanitize_key( $type ) );

			if ( version_compare( (string) WC()->version, '10.8.0', '>=' ) ) {
				$store->refresh_product_lookup_table( $new_id );
			} else {
				$this->write_lookup_row( $store, $new_id );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PerfLocale WC: lookup row rebuild failed for translation ' . $new_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Machine-translate a translated product's variation descriptions and local attribute options.
	 *
	 * Only values still holding the source's copy, never Sync Fields or emptied keys. Translated options
	 * are mirrored to the variation values with a translated => original map, so stock sync keeps
	 * pairing variations. Failures never fail the post translation.
	 *
	 * @param int|mixed      $source_id   Source post ID.
	 * @param string         $provider_id Provider that translated the post.
	 * @param array|mixed    $result      translate_post() result (post_id = the translation).
	 * @param string         $target_slug Target language slug.
	 * @param string         $source_slug Source language slug.
	 * @param \WP_Post|mixed $source_post Source post as translate_post() read it.
	 *                                    Its post type is used when its ID is $source_id.
	 * @return void
	 */
	public function translate_variation_texts( $source_id = 0, $provider_id = '', $result = [], $target_slug = '', $source_slug = '', $source_post = null ): void {
		$source_id = (int) $source_id;
		$target_id = is_array( $result ) ? (int) ( $result['post_id'] ?? 0 ) : 0;

		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id || '' === (string) $target_slug || '' === (string) $source_slug ) {
			return;
		}

		// Type from the passed source post: by ID it can cost a query, as the sibling sync of the
		// translation's save drops the source from the post cache.
		$post_type = ( $source_post instanceof \WP_Post && (int) $source_post->ID === $source_id )
			? (string) $source_post->post_type
			: get_post_type( $source_id );

		if ( 'product' !== $post_type || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$source = wc_get_product( $source_id );
		$target = wc_get_product( $target_id );

		if ( ! $source instanceof \WC_Product || ! $target instanceof \WC_Product ) {
			return;
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->get( 'settings' );
		$errors   = [];

		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror_variation = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', (array) $settings->get( 'sync_fields', [] ), 'product_variation' );
		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror_product = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', (array) $settings->get( 'sync_fields', [] ), 'product' );

		$pairs        = $source instanceof \WC_Product_Variable && $target instanceof \WC_Product_Variable
			? $this->variation_pairs( $source, $target, (string) $target_slug )
			: [];
		$descriptions = in_array( '_variation_description', array_map( 'strval', $mirror_variation ), true )
			? []
			: $this->seeded_variation_descriptions( $pairs );
		$options      = in_array( '_product_attributes', array_map( 'strval', $mirror_product ), true ) || isset( \PerfLocale\Translation\ContentSync::seed_cleared_keys( $target_id )['_product_attributes'] )
			? []
			: $this->seeded_local_options( $source, $target );

		if ( [] === $descriptions && [] === $options ) {
			return;
		}

		try {
			$service = new \PerfLocale\MachineTranslation\TranslationService( $settings, $plugin->get( 'cache' ) );
		} catch ( \Throwable $e ) {
			return;
		}

		// One check for both batches, so a limit that covers only one of them
		// sends neither.
		$needed = 0;

		foreach ( $descriptions as $text ) {
			$needed += mb_strlen( $text );
		}

		foreach ( $options as $list ) {
			foreach ( $list as $option ) {
				$needed += mb_strlen( $option );
			}
		}

		if ( $service->would_exceed_limit( $needed ) ) {
			$descriptions = [];
			$options      = [];
			$errors[]     = sprintf(
				/* translators: %1$s: Characters required, %2$s: Monthly limit */
				__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
				number_format_i18n( $needed ),
				number_format_i18n( (int) $settings->get( 'mt_monthly_char_limit', 500000 ) )
			);
		}

		if ( [] !== $descriptions ) {
			$errors = array_merge( $errors, $this->write_variation_descriptions( $service, $descriptions, (string) $source_slug, (string) $target_slug, (string) $provider_id ) );
		}

		if ( [] !== $options ) {
			$errors = array_merge( $errors, $this->write_local_options( $service, $source_id, $target_id, $options, (string) $source_slug, (string) $target_slug, (string) $provider_id ) );
		}

		if ( [] !== $errors ) {
			$message = sprintf(
				/* translators: %s: the reasons, separated by " | ". */
				__( 'Variation descriptions or attribute options were not translated: %s', 'perflocale' ),
				implode( ' | ', array_unique( $errors ) )
			);

			\PerfLocale\MachineTranslation\MetaTranslator::record_meta_errors( $target_id, [ $message ] );

			/** This action is documented in src/MachineTranslation/MetaTranslator.php */
			do_action( 'perflocale/mt/meta_translate_failed', $source_id, $target_id, [ '_variation_description', '_product_attributes' ], $message );
		}
	}

	/**
	 * Whether every OTHER product or variation holding a lookup value is a translation sibling.
	 *
	 * WC's own lookups return only the lowest matching id, which cannot tell the sibling case apart.
	 *
	 * @param string $column     'sku' or 'global_unique_id'.
	 * @param string $value      The value being validated.
	 * @param int    $product_id The product under validation.
	 * @return bool True when at least one other holder exists and all are siblings.
	 */
	public function siblings_own_all_holders( string $column, string $value, int $product_id ): bool {
		$holders = $this->lookup_value_holders( $column, $value, $product_id );

		if ( $holders === [] ) {
			// WC reported a conflict but the lookup table lists no other holder
			// (e.g. its row is not built yet): defer to WC's own decision.
			return false;
		}

		$plugin = \PerfLocale\Plugin::get_instance();
		$repo   = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );

		foreach ( $holders as $holder_id ) {
			if ( ! $this->is_translation_sibling( $product_id, $holder_id, $repo ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Give a freshly-created product translation the source's visibility, shipping class and brand terms.
	 *
	 * WC reads these props from non-translatable taxonomies alone. Operator flags are copied; outofstock
	 * and rated-N come from the translation's own meta, as update_visibility() derives them. Written
	 * directly: a CRUD save() would fire every save listener mid-create.
	 *
	 * @param int $new_id    Newly created translation post ID.
	 * @param int $source_id Source post ID.
	 * @return void
	 */
	private function mirror_catalog_terms( int $new_id, int $source_id ): void {
		$plugin       = \PerfLocale\Plugin::get_instance();
		$translatable = $plugin->has( 'settings' ) ? (array) $plugin->get( 'settings' )->get_translatable_taxonomies() : [];

		if ( taxonomy_exists( 'product_visibility' ) && ! in_array( 'product_visibility', $translatable, true ) ) {
			$flags = wp_get_object_terms( $source_id, 'product_visibility', [ 'fields' => 'slugs' ] );

			if ( ! is_wp_error( $flags ) ) {
				$terms = array_values( array_intersect( [ 'featured', 'exclude-from-search', 'exclude-from-catalog' ], array_map( 'strval', $flags ) ) );

				if ( 'outofstock' === (string) get_post_meta( $new_id, '_stock_status', true ) ) {
					$terms[] = 'outofstock';
				}

				$rating = min( 5, (int) round( (float) get_post_meta( $new_id, '_wc_average_rating', true ) ) );

				if ( $rating > 0 ) {
					$terms[] = 'rated-' . $rating;
				}

				wp_set_object_terms( $new_id, $terms, 'product_visibility' );
			}
		}

		foreach ( [ 'product_shipping_class', 'product_brand' ] as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) || in_array( $taxonomy, $translatable, true ) ) {
				continue;
			}

			$term_ids = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_wp_error( $term_ids ) ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $term_ids ), $taxonomy );
			}
		}
	}

	/**
	 * Write a product's wc_product_meta_lookup row through its data store on WooCommerce below 10.8.
	 *
	 * Calls the protected update_lookup_table() (what 10.8's refresh_product_lookup_table() wraps)
	 * through closures scoped to the declaring classes; no action fires and no meta is written.
	 *
	 * @param \WC_Data_Store $store      Wrapper from WC_Data_Store::load().
	 * @param int            $product_id Product ID.
	 * @return void
	 * @throws \RuntimeException When the wrapper has no store instance or the
	 *                           store is not a WC_Data_Store_WP.
	 */
	private function write_lookup_row( \WC_Data_Store $store, int $product_id ): void {
		if ( ! property_exists( \WC_Data_Store::class, 'instance' ) || ! class_exists( \WC_Data_Store_WP::class ) ) {
			throw new \RuntimeException( 'WooCommerce has no data store instance' );
		}

		$read = \Closure::bind(
			static function ( \WC_Data_Store $wrapper ): mixed {
				return $wrapper->instance;
			},
			null,
			\WC_Data_Store::class
		);
		/**
		 * The store object the wrapper resolved. WooCommerce documents the
		 * property as a WC_Data_Store.
		 *
		 * @var object|null $instance
		 */
		$instance = $read( $store );

		if ( ! $instance instanceof \WC_Data_Store_WP ) {
			throw new \RuntimeException( 'the product data store is not a WC_Data_Store_WP' );
		}

		$write = \Closure::bind(
			static function ( \WC_Data_Store_WP $data_store, int $id ): void {
				$data_store->update_lookup_table( $id, 'wc_product_meta_lookup' );
			},
			null,
			\WC_Data_Store_WP::class
		);
		$write( $instance, $product_id );
	}

	/**
	 * Pair each source variation with the translation's variation of the same attributes.
	 *
	 * Taxonomy values map to target terms; local values read through the translated => original map.
	 *
	 * @param \WC_Product_Variable $source      Source product.
	 * @param \WC_Product_Variable $target      Translated product.
	 * @param string               $target_slug Target language slug.
	 * @return array<int, int> source variation id => target variation id.
	 */
	private function variation_pairs( \WC_Product_Variable $source, \WC_Product_Variable $target, string $target_slug ): array {
		$source_children = array_map( 'intval', $source->get_children() );
		$target_children = array_map( 'intval', $target->get_children() );

		if ( [] === $source_children || [] === $target_children ) {
			return [];
		}

		_prime_post_caches( array_merge( $source_children, $target_children ), false, true );

		$local = get_post_meta( $target->get_id(), \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, true );
		$local = is_array( $local ) ? $local : [];

		$by_signature = [];

		foreach ( $target_children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child instanceof \WC_Product_Variation ) {
				continue;
			}

			$attrs = [];

			foreach ( $child->get_attributes() as $key => $value ) {
				$key   = (string) $key;
				$value = (string) $value;

				if ( isset( $local[ $key ][ $value ] ) && is_string( $local[ $key ][ $value ] ) ) {
					$value = $local[ $key ][ $value ];
				}

				$attrs[ $key ] = $value;
			}

			ksort( $attrs );
			$sig = (string) wp_json_encode( $attrs );

			if ( ! isset( $by_signature[ $sig ] ) ) {
				$by_signature[ $sig ] = $child_id;
			}
		}

		$pairs = [];

		foreach ( $source_children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child instanceof \WC_Product_Variation ) {
				continue;
			}

			$attrs = array_map( 'strval', $this->translate_variation_attributes( $child->get_attributes(), $target_slug ) );
			ksort( $attrs );
			$sig = (string) wp_json_encode( $attrs );

			if ( isset( $by_signature[ $sig ] ) ) {
				$pairs[ $child_id ] = $by_signature[ $sig ];
			}
		}

		return $pairs;
	}

	/**
	 * Paired variations whose translation still holds the source's description, not emptied there.
	 *
	 * @param array<int, int> $pairs source variation id => target variation id.
	 * @return array<int, string> target variation id => source description.
	 */
	private function seeded_variation_descriptions( array $pairs ): array {
		$out = [];

		foreach ( $pairs as $source_vid => $target_vid ) {
			$sv = wc_get_product( $source_vid );
			$tv = wc_get_product( $target_vid );

			if ( ! $sv instanceof \WC_Product_Variation || ! $tv instanceof \WC_Product_Variation ) {
				continue;
			}

			$text = (string) $sv->get_description( 'edit' );

			if ( '' === trim( $text ) || (string) $tv->get_description( 'edit' ) !== $text ) {
				continue;
			}

			if ( isset( \PerfLocale\Translation\ContentSync::seed_cleared_keys( $target_vid )['_variation_description'] ) ) {
				continue;
			}

			$out[ $target_vid ] = $text;
		}

		return $out;
	}

	/**
	 * Local attributes whose options the translation still holds as the source's copy.
	 *
	 * @param \WC_Product $source Source product.
	 * @param \WC_Product $target Translated product.
	 * @return array<string, array<int, string>> attribute key => source options.
	 */
	private function seeded_local_options( \WC_Product $source, \WC_Product $target ): array {
		$target_attrs = [];

		foreach ( $target->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$target_attrs[ sanitize_title( $attribute->get_name() ) ] = array_values( array_map( 'strval', $attribute->get_options() ) );
			}
		}

		$out = [];

		foreach ( $source->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() ) {
				continue;
			}

			$key     = sanitize_title( $attribute->get_name() );
			$options = array_values( array_map( 'strval', $attribute->get_options() ) );

			if ( '' === $key || [] === $options || ( $target_attrs[ $key ] ?? null ) !== $options ) {
				continue;
			}

			$out[ $key ] = $options;
		}

		return $out;
	}

	/**
	 * Translate and write variation descriptions (HTML, placeholders masked).
	 *
	 * @param \PerfLocale\MachineTranslation\TranslationService $service      MT service.
	 * @param array<int, string>                                $descriptions target variation id => source description.
	 * @param string                                            $source_slug  Source language slug.
	 * @param string                                            $target_slug  Target language slug.
	 * @param string                                            $provider_id  Provider id.
	 * @return array<int, string> Error reasons.
	 */
	private function write_variation_descriptions( \PerfLocale\MachineTranslation\TranslationService $service, array $descriptions, string $source_slug, string $target_slug, string $provider_id ): array {
		$masked = [];

		foreach ( $descriptions as $vid => $text ) {
			$masked[ $vid ] = \PerfLocale\Translation\PlaceholderMasker::mask( $text );
		}

		/* translators: %d: variation ID */
		$labels = array_map( static fn( int $vid ): string => sprintf( __( 'variation #%d description', 'perflocale' ), $vid ), array_keys( $masked ) );

		try {
			$out = $service->translate_batch_texts( array_values( array_map( static fn( array $m ): string => (string) $m[0], $masked ) ), $source_slug, $target_slug, $provider_id, false, 'html', $labels );
		} catch ( \Throwable $e ) {
			return [ $e->getMessage() ];
		}

		$errors = [];
		$i      = 0;

		foreach ( $masked as $vid => $m ) {
			$translated = (string) ( $out[ $i ] ?? '' );
			++$i;

			if ( '' === trim( $translated ) ) {
				$errors[] = 'empty translation';
				continue;
			}

			$restored = \PerfLocale\Translation\PlaceholderMasker::restore( $translated, $m[1] );

			if ( ! \PerfLocale\Translation\PlaceholderMasker::preserves_placeholders( $descriptions[ $vid ], $restored ) ) {
				$errors[] = 'placeholder lost';
				continue;
			}

			// A description in block markup keeps its block structure, as
			// post content does; the variation keeps its description otherwise.
			$structure = \PerfLocale\MachineTranslation\BlockStructureGuard::mismatch( $descriptions[ $vid ], $restored );

			if ( '' !== $structure ) {
				$errors[] = $structure;
				continue;
			}

			// The provider wait can be long: a description someone changed
			// meanwhile is theirs.
			clean_post_cache( $vid );
			$variation = wc_get_product( $vid );

			if ( ! $variation instanceof \WC_Product_Variation || (string) $variation->get_description( 'edit' ) !== $descriptions[ $vid ] ) {
				continue;
			}

			$variation->set_description( $restored );
			$variation->save();
			require_once __DIR__ . '/PerfLocaleWooCommerceVariationText.php';
			\PerfLocaleWooCommerceVariationText::keep_backslashes( $variation );
		}

		return $errors;
	}

	/**
	 * Translate a product's local attribute options; write them to the parent and its variations' values.
	 *
	 * @param \PerfLocale\MachineTranslation\TranslationService $service     MT service.
	 * @param int                                               $source_id   Source product id.
	 * @param int                                               $target_id   Translated product id.
	 * @param array<string, array<int, string>>                 $options     attribute key => source options.
	 * @param string                                            $source_slug Source language slug.
	 * @param string                                            $target_slug Target language slug.
	 * @param string                                            $provider_id Provider id.
	 * @return array<int, string> Error reasons.
	 */
	private function write_local_options( \PerfLocale\MachineTranslation\TranslationService $service, int $source_id, int $target_id, array $options, string $source_slug, string $target_slug, string $provider_id ): array {
		$target = wc_get_product( $target_id );

		if ( ! $target instanceof \WC_Product ) {
			return [];
		}

		$children = $target instanceof \WC_Product_Variable ? array_map( 'intval', $target->get_children() ) : [];
		$values   = [];

		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( $child instanceof \WC_Product_Variation ) {
				$values[ $child_id ] = array_map( 'strval', $child->get_attributes() );
			}
		}

		// An attribute whose variations hold a value that is not one of its
		// options (an older slug form, a hand edit) keeps its options: moving
		// the options without those values would unpair the variations.
		foreach ( $options as $key => $list ) {
			foreach ( $values as $attrs ) {
				$value = $attrs[ $key ] ?? '';

				if ( '' !== $value && ! in_array( $value, $list, true ) ) {
					unset( $options[ $key ] );
					break;
				}
			}
		}

		if ( [] === $options ) {
			return [];
		}

		$flat   = [];
		$labels = [];

		foreach ( $options as $key => $list ) {
			$position = 0;

			foreach ( $list as $option ) {
				$flat[] = $option;
				/* translators: 1: attribute key, 2: option position, 3: number of options */
				$labels[] = sprintf( __( 'attribute %1$s option %2$d of %3$d', 'perflocale' ), $key, ++$position, count( $list ) );
			}
		}

		try {
			$out = $service->translate_batch_texts( $flat, $source_slug, $target_slug, $provider_id, false, 'text', $labels );
		} catch ( \Throwable $e ) {
			return [ $e->getMessage() ];
		}

		// The original of each option, through the source's own map when the
		// source is itself a translated copy, so every copy maps to one value.
		$source_map = get_post_meta( $source_id, \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, true );
		$source_map = is_array( $source_map ) ? $source_map : [];
		$maps       = [];
		$renames    = [];
		$errors     = [];
		$i          = 0;

		foreach ( $options as $key => $list ) {
			$translated = [];
			$seen       = [];
			$usable     = true;

			foreach ( $list as $option ) {
				// WooCommerce joins local options with "|".
				$t = trim( str_replace( '|', '/', sanitize_text_field( (string) ( $out[ $i ] ?? '' ) ) ) );
				++$i;
				$fold = wc_strtolower( $t );

				if ( '' === $t || isset( $seen[ $fold ] ) ) {
					$usable = false;
					continue;
				}

				$seen[ $fold ]         = true;
				$translated[ $option ] = $t;
			}

			if ( ! $usable ) {
				$errors[] = sprintf(
					/* translators: %s: product attribute name. */
					__( 'attribute "%s" kept its options (a translated option was empty or repeated)', 'perflocale' ),
					$key
				);
				continue;
			}

			$renames[ $key ] = $translated;

			foreach ( $translated as $option => $t ) {
				$original           = $source_map[ $key ][ $option ] ?? $option;
				$maps[ $key ][ $t ] = is_string( $original ) ? $original : $option;
			}
		}

		if ( [] === $renames ) {
			return $errors;
		}

		// The map first, so a sync fired by the saves below already pairs
		// the renamed variations.
		$stored = get_post_meta( $target_id, \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, true );
		$stored = is_array( $stored ) ? $stored : [];
		update_post_meta( $target_id, \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, wp_slash( array_replace( $stored, $maps ) ) );

		// New attribute objects: WooCommerce records a change only when the
		// array it is given differs from the one it holds, and the objects
		// get_attributes() returns are the held ones.
		$attributes = [];

		foreach ( $target->get_attributes() as $name => $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() && isset( $renames[ sanitize_title( $attribute->get_name() ) ] ) ) {
				$attribute = clone $attribute;
				$attribute->set_options( array_values( $renames[ sanitize_title( $attribute->get_name() ) ] ) );
			}

			$attributes[ $name ] = $attribute;
		}

		$target->set_attributes( $attributes );
		$target->save();

		foreach ( $values as $child_id => $attrs ) {
			$changed = false;

			foreach ( $renames as $key => $translated ) {
				$value = $attrs[ $key ] ?? '';

				if ( '' !== $value && isset( $translated[ $value ] ) ) {
					$attrs[ $key ] = $translated[ $value ];
					$changed       = true;
				}
			}

			if ( ! $changed ) {
				continue;
			}

			$child = wc_get_product( $child_id );

			if ( $child instanceof \WC_Product_Variation ) {
				$child->set_attributes( $attrs );
				$child->save();
			}
		}

		if ( $target instanceof \WC_Product_Variable ) {
			\WC_Product_Variable::sync( $target_id );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $target_id );
		}

		return $errors;
	}

	/**
	 * Map a variable product's taxonomy attribute options onto the target language's sibling terms.
	 *
	 * @param array<int|string, mixed> $attributes  WC_Product_Attribute set.
	 * @param string                   $target_slug Target language slug.
	 * @return array<int|string, mixed>
	 */
	private function translate_attribute_options( array $attributes, string $target_slug ): array {
		if ( $target_slug === '' ) {
			return $attributes;
		}

		$manager = ( $this->term_manager )();

		foreach ( $attributes as $attribute ) {
			// Custom attributes carry literal strings, not term IDs: there is no sibling to map.
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->is_taxonomy() ) {
				continue;
			}

			$mapped  = [];
			$changed = false;

			foreach ( $attribute->get_options() as $term_id ) {
				$sibling = $manager->get_translation_id( (int) $term_id, $target_slug );

				if ( $sibling !== null && $sibling > 0 ) {
					$mapped[] = $sibling;
					$changed  = true;
					continue;
				}

				// Untranslated term: keep the source term so the option is still offered.
				$mapped[] = (int) $term_id;
			}

			if ( $changed ) {
				$attribute->set_options( $mapped );
			}
		}

		return $attributes;
	}

	/**
	 * Map a variation's attribute values onto the target language's sibling term slugs.
	 *
	 * So they keep matching the parent's translated options.
	 *
	 * @param array<string, string> $attributes  taxonomy => term slug.
	 * @param string                $target_slug Target language slug.
	 * @return array<string, string>
	 */
	private function translate_variation_attributes( array $attributes, string $target_slug ): array {
		if ( $target_slug === '' ) {
			return $attributes;
		}

		$manager = ( $this->term_manager )();

		foreach ( $attributes as $taxonomy => $slug ) {
			// "Any <attribute>" is stored as an empty value - keep it empty.
			if ( ! is_string( $slug ) || $slug === '' || ! taxonomy_exists( (string) $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, (string) $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$sibling_id = $manager->get_translation_id( (int) $term->term_id, $target_slug );

			if ( $sibling_id === null || $sibling_id <= 0 ) {
				continue;
			}

			$sibling = get_term( $sibling_id );

			if ( $sibling instanceof \WP_Term ) {
				$attributes[ $taxonomy ] = $sibling->slug;
			}
		}

		return $attributes;
	}

	/**
	 * Whether $holder_id is $product_id in another language: a linked sibling or its variation.
	 *
	 * Variations are not group-linked, but WC validates their SKUs and GTINs too.
	 *
	 * @param int                                                          $product_id Product under validation.
	 * @param int                                                          $holder_id  Other holder of the value.
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository    $repo       Group repository.
	 * @return bool
	 */
	private function is_translation_sibling( int $product_id, int $holder_id, \PerfLocale\Database\Repository\TranslationGroupRepository $repo ): bool {
		foreach ( $repo->get_translations( $product_id, \PerfLocale\Enum\ObjectType::Post ) as $link ) {
			if ( (int) $link->object_id === $holder_id ) {
				return true;
			}
		}

		$parent        = wp_get_post_parent_id( $product_id );
		$holder_parent = wp_get_post_parent_id( $holder_id );

		if ( $parent > 0 && $holder_parent > 0 ) {
			foreach ( $repo->get_translations( $parent, \PerfLocale\Enum\ObjectType::Post ) as $link ) {
				if ( (int) $link->object_id === $holder_parent ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Every product or variation except $product_id holding $value in a lookup-table column.
	 *
	 * WC's is_existing_sku() query without its LIMIT 1.
	 *
	 * @param string $column     'sku' or 'global_unique_id'.
	 * @param string $value      The value to match.
	 * @param int    $product_id The product to exclude.
	 * @return int[]
	 */
	private function lookup_value_holders( string $column, string $value, int $product_id ): array {
		global $wpdb;

		// $column is interpolated as a SQL identifier - restrict it to the two
		// known lookup columns so it can never carry arbitrary input.
		if ( ! in_array( $column, [ 'sku', 'global_unique_id' ], true ) ) {
			return [];
		}

		// wp_slash() matches WC's own lookup parameter handling. Write context only (product save),
		// so no result cache.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT lookup.product_id
				FROM {$wpdb->posts} AS posts
				INNER JOIN %i AS lookup ON posts.ID = lookup.product_id
				WHERE posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND lookup.%i = %s
				AND lookup.product_id <> %d",
				$wpdb->prefix . 'wc_product_meta_lookup',
				$column,
				wp_slash( $value ),
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map( 'intval', (array) $ids );
	}
}
