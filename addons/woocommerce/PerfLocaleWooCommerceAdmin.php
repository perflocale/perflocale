<?php
/**
 * PerfLocale WooCommerce addon: admin screens and string registration, loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin and write-time code of the WooCommerce addon: settings fields and the currency save, admin links, the sync
 * opt-out field, the order-language privacy export, and the attribute-label and email string registration.
 */
final class PerfLocaleWooCommerceAdmin {

	/**
	 * WC email settings registered as translatable strings, one context per field and email id.
	 */
	private const EMAIL_STRING_FIELDS = [ 'subject', 'heading', 'additional_content' ];

	/**
	 * The addon's settings fields (PerfLocaleWooCommerce::get_settings_fields()).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_fields(): array {
		// Stored in the main perflocale_settings option, rendered and saved by render_settings_subtab(),
		// not AddonSettings. AddonRegistry lists this add-on in GLOBAL_SETTINGS_ONLY and never builds
		// these fields at boot, so every field must stay 'storage' => 'global'.
		$global = [ 'storage' => 'global' ];
		return [
			'wc_email_translation'      => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Send Order Emails in Customer\'s Language', 'perflocale' ),
				'default' => true,
			],
			'wc_sync_stock'             => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Sync Inventory Across Language Variants', 'perflocale' ),
				'default' => true,
			],
			'wc_sync_prices'            => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Synchronize Prices Across Languages', 'perflocale' ),
				'default' => true,
			],
			'wc_currency_per_lang'      => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Different Currency Per Language', 'perflocale' ),
				'default' => false,
			],
			'wc_currencies'             => $global + [
				'type'    => 'hidden',
				'default' => [],
			],
			'wc_exchange_rate_auto'     => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Auto-Sync Exchange Rates', 'perflocale' ),
				'default' => false,
			],
			'wc_exchange_rate_provider' => $global + [
				'type'    => 'select',
				'label'   => __( 'Exchange Rate Provider', 'perflocale' ),
				'default' => '',
			],
			'wc_exchange_rate_interval' => $global + [
				'type'    => 'select',
				'label'   => __( 'Sync Interval', 'perflocale' ),
				'default' => 'daily',
			],
		];
	}

	/**
	 * Sanitize the per-language WC currency rows of the settings form POST into `wc_currencies`.
	 *
	 * @return array<string, array{currency_code: string, exchange_rate: float, display: string, position: string}>
	 */
	public static function sanitize_currencies_post(): array {
		// Merge onto the saved map: only ACTIVE languages render inputs, so a replace would drop the rows
		// of deactivated languages (LanguageRepository::delete prunes the key).
		$plugin    = \PerfLocale\Plugin::get_instance();
		$existing  = $plugin->has( 'settings' ) ? (array) $plugin->get( 'settings' )->get( 'wc_currencies', [] ) : [];

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by AdminController.
		if ( empty( $_POST['wc_currencies'] ) || ! is_array( $_POST['wc_currencies'] ) ) {
			return $existing;
		}

		$out = [];

		foreach ( wp_unslash( $_POST['wc_currencies'] ) as $slug => $data ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$slug = sanitize_key( (string) $slug );

			if ( $slug === '' || ! is_array( $data ) ) {
				continue;
			}

			// Validated, never byte-truncated: a broken UTF-8 sequence corrupts the serialized settings
			// option and every plugin setting is lost. ISO 4217 codes are three ASCII letters.
			$raw_code = sanitize_text_field( (string) ( $data['currency_code'] ?? '' ) );
			$code     = preg_match( '/^[A-Za-z]{3}$/', $raw_code ) === 1 ? strtoupper( $raw_code ) : '';

			if ( $code === '' ) {
				continue;
			}

			$display  = sanitize_key( (string) ( $data['display'] ?? 'symbol' ) );
			$position = sanitize_key( (string) ( $data['position'] ?? 'default' ) );

			$out[ $slug ] = [
				'currency_code' => $code,
				// Not a finite number above zero: refused, the stored rate of
				// the row stays (rate_to_store()).
				'exchange_rate' => \PerfLocale\WooCommerce\MultiCurrency::rate_to_store( $data['exchange_rate'] ?? 1.0, $existing[ $slug ] ?? null, $code ),
				// Carry the manual-rate pin, or auto-sync overwrites a user-entered rate.
				'manual_rate'   => ! empty( $data['manual_rate'] ),
				'display'       => in_array( $display, [ 'symbol', 'code' ], true ) ? $display : 'symbol',
				'position'      => in_array( $position, [ 'default', 'left', 'left_space', 'right', 'right_space' ], true ) ? $position : 'default',
			];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array_merge( $existing, $out );
	}

	/**
	 * Point a variation row at its parent product's editor.
	 *
	 * A variation has no standalone editor; without a product parent it gets no link, not a blank screen.
	 *
	 * @param string $url       URL resolved so far ('' when core could not).
	 * @param int    $post_id   Object being linked.
	 * @param string $post_type Its post type.
	 * @return string
	 */
	public static function edit_link( string $url, int $post_id, string $post_type ): string {
		if ( 'product_variation' !== $post_type ) {
			return $url;
		}

		$parent_id = (int) wp_get_post_parent_id( $post_id );
		$parent    = $parent_id > 0 ? get_post( $parent_id ) : null;

		// Historical or incomplete imports can leave a non-product parent ID.
		if ( ! $parent instanceof \WP_Post || $parent->post_type !== 'product' ) {
			return '';
		}

		$parent_url = get_edit_post_link( $parent_id, 'raw' );

		return is_string( $parent_url ) ? $parent_url : '';
	}

	/**
	 * Restore the front-end link for a product variation.
	 *
	 * @param string   $url  URL resolved so far ('' when the type is not viewable).
	 * @param \WP_Post $post Object being linked.
	 * @return string
	 */
	public static function view_link( string $url, \WP_Post $post ): string {
		if ( 'product_variation' !== $post->post_type ) {
			return $url;
		}

		$parent_id = (int) wp_get_post_parent_id( $post->ID );
		$parent    = $parent_id > 0 ? get_post( $parent_id ) : null;

		// ⚠️ A positive parent ID does not prove a usable product exists. Woo's
		// fallback can otherwise look like a link while opening no product.
		if ( ! $parent instanceof \WP_Post || $parent->post_type !== 'product'
			|| in_array( $parent->post_status, [ 'trash', 'auto-draft' ], true )
			|| in_array( $post->post_status, [ 'trash', 'auto-draft' ], true )
		) {
			return $url;
		}

		$permalink = get_permalink( $post );

		return is_string( $permalink ) ? $permalink : $url;
	}

	/**
	 * Render the per-product sync opt-out checkbox on the product data Advanced tab.
	 *
	 * @return void
	 */
	public static function render_sync_optout_field(): void {
		woocommerce_wp_checkbox(
			[
				'id'          => \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META,
				'value'       => get_post_meta( (int) get_the_ID(), \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META, true ),
				'label'       => __( 'Independent across languages', 'perflocale' ),
				'description' => __( 'Do not synchronize this product\'s shared data (prices, stock, dimensions, SKU) with its translations. Tick it on a single language\'s product to give only that language independent values - for example a promotional price in one language.', 'perflocale' ),
			]
		);

		// Marker input: an unchecked checkbox looks absent, so saves that never render this field
		// (REST, importer, CLI) must not clear the flag.
		echo '<input type="hidden" name="_perflocale_sync_optout_present" value="1" />';
	}

	/**
	 * Persist the sync opt-out checkbox from the product edit screen.
	 *
	 * @param int $product_id Saved product ID.
	 * @return void
	 */
	public static function save_sync_optout_field( int $product_id ): void {
		// WooCommerce verifies its meta-box nonce before firing woocommerce_process_product_meta.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC's save_meta_boxes nonce verified upstream.
		if ( ! isset( $_POST['_perflocale_sync_optout_present'] ) ) {
			return;
		}

		if ( isset( $_POST[ \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META ] ) ) {
			update_post_meta( $product_id, \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META, 'yes' );
		} else {
			delete_post_meta( $product_id, \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Add the order language to the fields WooCommerce's personal-data exporter lists for an order.
	 *
	 * @param mixed $props Field key => label.
	 * @return mixed
	 */
	public static function add_order_language_export_prop( mixed $props ): mixed {
		if ( ! is_array( $props ) ) {
			return $props;
		}

		$props['perflocale_language'] = __( 'Order language', 'perflocale' );

		return $props;
	}

	/**
	 * Personal-data export value of the order language: its name, or its code when it is missing.
	 *
	 * @param mixed $value Value so far.
	 * @param mixed $prop  Field key.
	 * @param mixed $order Order being exported.
	 * @return mixed
	 */
	public static function export_order_language_prop( mixed $value, mixed $prop = '', mixed $order = null ): mixed {
		if ( 'perflocale_language' !== $prop || ! $order instanceof \WC_Order ) {
			return $value;
		}

		$slug = sanitize_key( (string) $order->get_meta( '_perflocale_language', true ) );

		if ( $slug === '' ) {
			return $value;
		}

		$language = null;

		try {
			$language = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' )->find_by_slug( $slug );
		} catch ( \Throwable $e ) {
			$language = null;
		}

		$name = is_object( $language ) && isset( $language->name ) ? (string) $language->name : '';

		return $name !== '' ? $name : $slug;
	}

	/**
	 * Register a WC attribute label as a string when the attribute is created or updated.
	 *
	 * @param int                  $id Attribute ID.
	 * @param array<string, mixed> $data Attribute data.
	 * @return void
	 */
	public static function register_attribute_label_string( int $id, array $data ): void {
		$label = $data['attribute_label'] ?? ( $data['name'] ?? '' );

		if ( $label === '' ) {
			return;
		}

		self::ensure_string_registered(
			$label,
			'woocommerce',
			\PerfLocaleWooCommerce::attribute_label_context( (string) ( $data['attribute_name'] ?? '' ) )
		);
	}

	/**
	 * Register all existing WC attribute labels as strings, on "Scan for Strings".
	 *
	 * @return void
	 */
	public static function sync_attribute_labels(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		$attributes = wc_get_attribute_taxonomies();

		foreach ( $attributes as $attr ) {
			if ( ! empty( $attr->attribute_label ) ) {
				self::ensure_string_registered(
					$attr->attribute_label,
					'woocommerce',
					\PerfLocaleWooCommerce::attribute_label_context( (string) ( $attr->attribute_name ?? '' ) )
				);
			}
		}
	}

	/**
	 * Register WC email subjects, headings and additional content as strings.
	 *
	 * Runs on "Scan for Strings" and woocommerce_settings_saved; other writes of one email's
	 * settings go through register_changed_email_strings().
	 *
	 * @return void
	 */
	public static function sync_email_strings(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$mailer = WC()->mailer();

		if ( ! $mailer ) {
			return;
		}

		$emails = $mailer->get_emails();
		$fields = self::EMAIL_STRING_FIELDS;

		foreach ( $emails as $email ) {
			if ( ! $email instanceof \WC_Email ) {
				continue;
			}

			foreach ( $fields as $field ) {
				$default_method = 'get_default_' . $field;

				if ( ! method_exists( $email, $default_method ) ) {
					continue;
				}

				$value = $email->get_option( $field, $email->{$default_method}() );

				if ( $value === '' ) {
					continue;
				}

				self::ensure_string_registered(
					$value,
					'woocommerce',
					"email_{$field}_{$email->id}"
				);
			}
		}
	}

	/**
	 * Register the email texts that a write of one `woocommerce_{id}_settings` option changed.
	 *
	 * Runs on every option write, so all work before the mailer lookup is string and array work.
	 * Texts come from the NEW value: a direct update_option() leaves the mailer's copy stale.
	 * Skipped while installing, before init and inside the WC Settings save (covered by its hook).
	 *
	 * @param mixed $option    Option name.
	 * @param mixed $old_value Previous value; null for a new option.
	 * @param mixed $value     Stored value.
	 * @return void
	 */
	public static function register_changed_email_strings( $option, $old_value, $value ): void {
		if ( ! is_string( $option ) || ! is_array( $value ) || ! str_starts_with( $option, 'woocommerce_' ) || ! str_ends_with( $option, '_settings' ) ) {
			return;
		}

		$previous = is_array( $old_value ) ? $old_value : [];
		$changed  = [];

		foreach ( self::EMAIL_STRING_FIELDS as $field ) {
			if ( ( $value[ $field ] ?? null ) !== ( $previous[ $field ] ?? null ) ) {
				$changed[] = $field;
			}
		}

		if ( [] === $changed || wp_installing() || ( defined( 'WC_INSTALLING' ) && WC_INSTALLING ) || ! did_action( 'init' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$tab = $GLOBALS['current_tab'] ?? '';

		if ( is_string( $tab ) && '' !== $tab && doing_action( 'woocommerce_settings_save_' . $tab ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$email  = null;

		foreach ( ( $mailer ? $mailer->get_emails() : [] ) as $candidate ) {
			if ( $candidate instanceof \WC_Email && $candidate->get_option_key() === $option ) {
				$email = $candidate;
				break;
			}
		}

		if ( null === $email ) {
			return;
		}

		// Read through the email's own get_option() (defaults and filter included), as EmailTranslation
		// does at send time, on a copy holding the new value.
		$reader           = clone $email;
		$reader->settings = $value;

		foreach ( $changed as $field ) {
			$default_method = 'get_default_' . $field;

			if ( ! method_exists( $reader, $default_method ) ) {
				continue;
			}

			$text = $reader->get_option( $field, $reader->{$default_method}() );

			if ( ! is_string( $text ) || '' === $text ) {
				continue;
			}

			self::ensure_string_registered( $text, 'woocommerce', "email_{$field}_{$reader->id}" );
		}
	}

	/**
	 * Ensure a string is registered in PerfLocale's String Translation system.
	 *
	 * @param string $text Original text to register.
	 * @param string $domain Text domain (default: 'woocommerce').
	 * @param string $context Translation context (default: 'attribute_label').
	 * @return void
	 */
	private static function ensure_string_registered( string $text, string $domain = 'woocommerce', string $context = 'attribute_label' ): void {
		if ( ! \PerfLocale\Database\Schema::tables_exist() ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );
		$repo   = new \PerfLocale\Database\Repository\StringRepository( $cache );

		$repo->register_setting_string( $text, $domain, $context );
	}
}
