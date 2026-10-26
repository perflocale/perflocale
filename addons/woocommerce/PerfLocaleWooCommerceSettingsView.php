<?php
/**
 * PerfLocale WooCommerce addon: the settings subtab (admin only).
 *
 * Loaded only by PerfLocaleWooCommerce::render_settings_subtab().
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the WooCommerce settings subtab of PerfLocale → Settings → Addons.
 */
final class PerfLocaleWooCommerceSettingsView {

	/**
	 * The subtab content (see PerfLocaleWooCommerce::render_settings_subtab()).
	 *
	 * @param \PerfLocale\Settings $settings The plugin settings service.
	 * @return void
	 */
	public static function render( \PerfLocale\Settings $settings ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			?>
			<tr>
				<td colspan="2">
					<div class="notice notice-warning inline" style="margin:8px 0;">
						<p><?php echo esc_html__( 'WooCommerce is not active. Install and activate WooCommerce to use these settings.', 'perflocale' ); ?></p>
					</div>
				</td>
			</tr>
			<?php
			return;
		}

		$email_translation = (bool) $settings->get( 'wc_email_translation', true );
		$sync_stock        = (bool) $settings->get( 'wc_sync_stock', true );
		$sync_prices       = (bool) $settings->get( 'wc_sync_prices', true );
		$currency_per_lang = (bool) $settings->get( 'wc_currency_per_lang', false );
		$wc_currencies     = (array) $settings->get( 'wc_currencies', [] );
		$auto_sync         = (bool) $settings->get( 'wc_exchange_rate_auto', false );
		$rate_provider     = (string) $settings->get( 'wc_exchange_rate_provider', '' );
		$rate_interval     = (string) $settings->get( 'wc_exchange_rate_interval', 'daily' );

		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->get( 'cache' ) );
		$all_langs = $lang_repo->get_active();

		$rate_sync = new \PerfLocale\WooCommerce\ExchangeRateSync( $settings );
		$providers = $rate_sync->get_providers();
		$intervals = \PerfLocale\WooCommerce\ExchangeRateSync::get_intervals();
		$last_sync = \PerfLocale\WooCommerce\ExchangeRateSync::get_last_sync();

		// Sync-now, create-pages and rate readonly wiring; addon-settings-conditional.js (enqueued by
		// SettingsPage) shows and hides the data-perflocale-show-if rows.
		wp_enqueue_script(
			'perflocale-wc-settings',
			PERFLOCALE_URL . 'assets/js/wc-settings.js',
			[],
			PERFLOCALE_VERSION,
			true
		);
		wp_localize_script(
			'perflocale-wc-settings',
			'perflocaleWcData',
			[
				'syncRatesNonce'    => wp_create_nonce( 'perflocale_sync_rates' ),
				'createPagesNonce'  => wp_create_nonce( 'perflocale_create_wc_pages' ),
				'i18nRatesUpdated'  => __( 'Rates updated.', 'perflocale' ),
				'i18nSyncFailed'    => __( 'Sync failed.', 'perflocale' ),
				'i18nNetworkError'  => __( 'Network error.', 'perflocale' ),
				'i18nCreatingPages' => __( 'Creating pages...', 'perflocale' ),
				'i18nFailed'        => __( 'Failed', 'perflocale' ),
				'i18nFailedDot'     => __( 'Failed.', 'perflocale' ),
				'i18nDone'          => __( 'Done', 'perflocale' ),
				'i18nNetworkErr'    => __( 'Network error', 'perflocale' ),
			]
		);

		?>

		<tr>
			<td colspan="2"><h3 style="margin:0 0 4px;"><?php echo esc_html__( 'Pages', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'WooCommerce Pages', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin:0 0 8px;">
					<?php echo esc_html__( 'Create translation stubs for Cart, Checkout, My Account, and Shop pages in all active languages. Page titles will be translated using local WordPress translations first, then machine translation if enabled. Existing translations will not be affected.', 'perflocale' ); ?>
				</p>
				<div style="display:flex;align-items:center;gap:8px;">
					<button type="button" class="button" id="perflocale-create-wc-pages">
						<?php echo esc_html__( 'Create Page Translations', 'perflocale' ); ?>
					</button>
				</div>
				<div id="perflocale-wc-progress" style="display:none;margin-top:10px;max-width:420px;">
					<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
						<span id="perflocale-wc-status" style="font-size:13px;color:#50575e;"><?php echo esc_html__( 'Creating pages...', 'perflocale' ); ?></span>
						<span id="perflocale-wc-percent" style="font-size:12px;color:#50575e;font-weight:500;"></span>
					</div>
					<div style="width:100%;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
						<div id="perflocale-wc-bar" style="width:0;height:100%;background:#2271b1;border-radius:4px;transition:width 0.3s ease;"></div>
					</div>
				</div>
				<div id="perflocale-wc-pages-result" style="margin-top:8px;"></div>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Products', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Inventory Sync', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_sync_stock" value="1" <?php checked( $sync_stock ); ?>>
					<?php echo esc_html__( 'Sync stock, SKU, and pricing across all language variants', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'Keeps stock levels, SKU, GTIN, price, weight, and dimensions identical across all translations of the same product whenever WooCommerce saves it, whether the change comes from an order, the product editor, Quick Edit, the REST API, an import, or another plugin. Prevents over-selling when the same physical product exists in multiple languages.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<?php \PerfLocale\WooCommerce\StockAlignScreen::render( $sync_stock ); ?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Price Sync', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_sync_prices" value="1" <?php checked( $sync_prices ); ?>>
					<?php echo esc_html__( 'Keep prices the same across all language variants', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'Included in Inventory Sync above. Disable only if you want language-specific base prices (unusual - typically handled via the currency table below).', 'perflocale' ); ?></p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Emails', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Order Email Language', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_email_translation" value="1" <?php checked( $email_translation ); ?>>
					<?php echo esc_html__( 'Send order confirmation and status emails in the customer\'s language', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'The customer\'s language is detected when they place the order and stored with the order. All subsequent emails (processing, completed, refunded, etc.) are sent in that language.', 'perflocale' ); ?></p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Currency', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Per-Language Currency', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_currency_per_lang" value="1" <?php checked( $currency_per_lang ); ?> id="perflocale-wc-currency-toggle">
					<?php echo esc_html__( 'Display prices in a different currency per language', 'perflocale' ); ?>
				</label>
				<p class="description">
					<?php echo esc_html__( 'Prices are converted on the fly using the exchange rate below, and checkout and orders are processed in the displayed (per-language) currency. Make sure your payment gateway accepts each currency you enable.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/woocommerce/#per-language-currency" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'WooCommerce multilingual setup', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr id="perflocale-wc-currency-table" data-perflocale-show-if='{"wc_currency_per_lang":true}'
		<?php
		if ( ! $currency_per_lang ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Exchange Rates', 'perflocale' ); ?></th>
			<td>
				<?php if ( count( $all_langs ) <= 1 ) : ?>
					<p class="description" style="margin:0;">
						<?php
						printf(
							/* translators: %s: URL to the Languages admin page */
							esc_html__( 'Add a second language at %s to configure per-language exchange rates.', 'perflocale' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ) . '">' . esc_html__( 'PerfLocale → Languages', 'perflocale' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
				<p class="description" style="margin-bottom:8px;">
					<?php echo esc_html__( 'Set the currency code and exchange rate for each language. Prices are multiplied by the exchange rate.', 'perflocale' ); ?>
				</p>
				<table class="widefat fixed perflocale-mc-currency-table" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:20%;padding-left:8px;"><?php echo esc_html__( 'Language', 'perflocale' ); ?></th>
							<th style="width:12%;"><?php echo esc_html__( 'Currency', 'perflocale' ); ?></th>
							<th style="width:16%;"><?php echo esc_html__( 'Rate', 'perflocale' ); ?></th>
							<th style="width:24%;"><?php echo esc_html__( 'Display as', 'perflocale' ); ?></th>
							<th style="width:28%;"><?php echo esc_html__( 'Position', 'perflocale' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$default_currency = (string) get_option( 'woocommerce_currency', 'USD' );
						// Auto-synced rates live in their own option, not in wc_currencies.
						$auto_rates = $auto_sync ? get_option( \PerfLocale\WooCommerce\ExchangeRateSync::RATES_OPTION, [] ) : [];
						$last_sync  = $auto_sync ? get_option( \PerfLocale\WooCommerce\ExchangeRateSync::LAST_SYNC_OPTION, [] ) : [];

						foreach ( $all_langs as $lang ) :
							$flag  = \PerfLocale\Helper::get_flag_emoji( $lang );
							$saved = $wc_currencies[ $lang->slug ] ?? [];

							// Saved value wins; otherwise infer the currency
							// from the language's locale (pl_PL → PLN etc.).
							if ( isset( $saved['currency_code'] ) && $saved['currency_code'] !== '' ) {
								$curr_code = (string) $saved['currency_code'];
							} else {
								$inferred  = \PerfLocale\WooCommerce\LocaleCurrency::guess_currency( $lang );
								$curr_code = $inferred !== '' ? $inferred : $default_currency;
							}
							// A manually pinned currency keeps its saved rate even with auto-sync on.
							$is_manual = (bool) ( $saved['manual_rate'] ?? false );
							$synced    = $auto_sync && ! $is_manual ? \PerfLocale\WooCommerce\MultiCurrency::synced_rate( $auto_rates, $last_sync, (string) $lang->slug, $curr_code, $default_currency ) : null;
							$rate      = $synced ?? ( $saved['exchange_rate'] ?? 1.0 );
							$display  = $saved['display'] ?? 'symbol';
							$position = $saved['position'] ?? 'default';
							$prefix   = 'wc_currencies[' . esc_attr( $lang->slug ) . ']';
							$symbol   = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $curr_code ) : $curr_code;
							?>
							<tr>
								<td style="padding-left:8px;"><?php echo esc_html( $flag . ' ' . ( $lang->native_name ?: $lang->name ) ); ?></td>
								<td>
									<input type="text"
										name="<?php echo esc_attr( $prefix ); ?>[currency_code]"
										value="<?php echo esc_attr( $curr_code ); ?>"
										placeholder="<?php echo esc_attr( $default_currency ); ?>"
										maxlength="3"
										class="small-text"
										style="width:100%;text-transform:uppercase;">
								</td>
								<td>
									<input type="number"
										name="<?php echo esc_attr( $prefix ); ?>[exchange_rate]"
										value="<?php echo esc_attr( (string) $rate ); ?>"
										min="0.0001"
										step="0.0001"
										class="small-text perflocale-rate-input"
										style="width:100%;"
										<?php
										if ( $auto_sync && ! $is_manual ) {
											echo 'readonly'; }
										?>
										>
									<label class="perflocale-mc-rate-note" style="display:block;">
										<input type="checkbox"
											name="<?php echo esc_attr( $prefix ); ?>[manual_rate]"
											value="1"
											<?php checked( $is_manual ); ?>>
										<?php echo esc_html__( 'Manual (do not auto-sync)', 'perflocale' ); ?>
									</label>
									<?php if ( $auto_sync && ! $is_manual ) : ?>
										<span class="description perflocale-mc-rate-note">
											<?php echo esc_html__( 'Auto-synced', 'perflocale' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<select name="<?php echo esc_attr( $prefix ); ?>[display]" style="width:100%;">
										<option value="symbol" <?php selected( $display, 'symbol' ); ?>><?php /* translators: %s: currency symbol */ printf( esc_html__( 'Symbol (%s)', 'perflocale' ), esc_html( $symbol ) ); ?></option>
										<option value="code" <?php selected( $display, 'code' ); ?>><?php /* translators: %s: currency code */ printf( esc_html__( 'Code (%s)', 'perflocale' ), esc_html( $curr_code ) ); ?></option>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr( $prefix ); ?>[position]" style="width:100%;">
										<option value="default" <?php selected( $position, 'default' ); ?>><?php echo esc_html__( 'Default (WC setting)', 'perflocale' ); ?></option>
										<option value="left" <?php selected( $position, 'left' ); ?>><?php echo esc_html( $symbol . '10' ); ?></option>
										<option value="left_space" <?php selected( $position, 'left_space' ); ?>><?php echo esc_html( $symbol . ' 10' ); ?></option>
										<option value="right" <?php selected( $position, 'right' ); ?>><?php echo esc_html( '10' . $symbol ); ?></option>
										<option value="right_space" <?php selected( $position, 'right_space' ); ?>><?php echo esc_html( '10 ' . $symbol ); ?></option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top:6px;">
					<?php
					printf(
						/* translators: %s: default WooCommerce currency */
						esc_html__( 'Your store\'s base currency is %s. Set exchange rate to 1.0 to use the base currency for a language.', 'perflocale' ),
						'<strong>' . esc_html( $default_currency ) . '</strong>'
					);
					?>
				</p>
				<?php endif; // count > 1 ?>
			</td>
		</tr>

		<tr id="perflocale-wc-auto-sync-section" data-perflocale-show-if='{"wc_currency_per_lang":true}'
		<?php
		if ( ! $currency_per_lang ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Auto-Sync Rates', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_exchange_rate_auto" value="1" <?php checked( $auto_sync ); ?> id="perflocale-auto-sync-toggle">
					<?php echo esc_html__( 'Automatically fetch exchange rates from a live API', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'When enabled, exchange rates are updated automatically on the schedule below. Manual rate fields become read-only.', 'perflocale' ); ?></p>
			</td>
		</tr>

		<tr id="perflocale-wc-sync-provider" data-perflocale-show-if='{"op":"AND","rules":[{"wc_currency_per_lang":true},{"wc_exchange_rate_auto":true}]}'
		<?php
		if ( ! $currency_per_lang || ! $auto_sync ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Rate Provider', 'perflocale' ); ?></th>
			<td>
				<?php if ( empty( $providers ) ) : ?>
					<p class="description" style="margin-top:0;">
						<?php echo esc_html__( 'No exchange-rate provider is registered. PerfLocale does not bundle one, so no request is made to any rate service unless your site arranges it.', 'perflocale' ); ?>
					</p>
				<?php else : ?>
					<select name="wc_exchange_rate_provider" id="perflocale-rate-provider">
						<?php
						// A stored id no provider registers keeps its own option, so saving unchanged keeps it.
						if ( '' !== $rate_provider && ! isset( $providers[ $rate_provider ] ) ) :
							?>
							<option value="<?php echo esc_attr( $rate_provider ); ?>" selected="selected">
								<?php
								/* translators: %s: provider id stored in the settings. */
								echo esc_html( sprintf( __( '%s (not registered)', 'perflocale' ), $rate_provider ) );
								?>
							</option>
						<?php endif; ?>
						<?php foreach ( $providers as $pid => $prov ) : ?>
							<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $rate_provider, $pid ); ?>
								data-needs-key="<?php echo esc_attr( ! empty( $prov['needs_key'] ) ? '1' : '0' ); ?>"
								data-key-setting="<?php echo esc_attr( $prov['key_setting'] ?? '' ); ?>">
								<?php echo esc_html( (string) ( $prov['name'] ?? $pid ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Register a rate provider with the perflocale/woocommerce/exchange_rate_providers filter, or supply rates directly from perflocale/woocommerce/exchange_rates_fetched.', 'perflocale' ); ?>
					<br>
					<a href="https://perflocale.com/docs/exchange-rates/#sync-intervals" target="_blank" rel="noopener"><?php echo esc_html__( 'Exchange rate providers docs', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr id="perflocale-wc-sync-interval" data-perflocale-show-if='{"op":"AND","rules":[{"wc_currency_per_lang":true},{"wc_exchange_rate_auto":true}]}'
		<?php
		if ( ! $currency_per_lang || ! $auto_sync ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Sync Interval', 'perflocale' ); ?></th>
			<td>
				<select name="wc_exchange_rate_interval">
					<?php foreach ( $intervals as $ikey => $ilabel ) : ?>
						<option value="<?php echo esc_attr( $ikey ); ?>" <?php selected( $rate_interval, $ikey ); ?>>
							<?php echo esc_html( $ilabel ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Choose based on your API plan limits. Free tiers typically allow 1,000-1,500 requests per month.', 'perflocale' ); ?></p>
			</td>
		</tr>

		<?php
		// No API-key fields: PerfLocale bundles no rate provider.
		$key_fields = [];

		foreach ( $key_fields as $field_key => $field ) :
			$is_visible      = $currency_per_lang && $auto_sync && $rate_provider === $field['provider'];
			$override_source = $settings->get_override_source( $field_key );
			$is_overridden   = $override_source !== null;
			$stored_val      = $is_overridden ? '' : (string) $settings->get( $field_key, '' );
			$show_if_json    = wp_json_encode(
				[
					'op'    => 'AND',
					'rules' => [
						[ 'wc_currency_per_lang' => true ],
						[ 'wc_exchange_rate_auto' => true ],
						[ 'wc_exchange_rate_provider' => $field['provider'] ],
					],
				]
			);
			?>
		<tr class="perflocale-api-key-row" data-provider="<?php echo esc_attr( $field['provider'] ); ?>" data-perflocale-show-if="<?php echo esc_attr( (string) $show_if_json ); ?>" 
			<?php
			if ( ! $is_visible ) {
				echo 'style="display:none;"'; }
			?>
		>
			<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
			<td>
				<?php if ( $is_overridden ) : ?>
					<code><?php echo esc_html( $field['constant'] ); ?></code>
					<span class="description">
						<?php
						echo esc_html(
							match ( $override_source ) {
							'env'       => __( 'Defined in environment variable', 'perflocale' ),
							'connector' => __( 'Provided by WordPress Connectors API', 'perflocale' ),
							default     => __( 'Defined in wp-config.php', 'perflocale' ),
							}
						);
						?>
					</span>
				<?php else : ?>
					<input type="password"
						name="<?php echo esc_attr( $field_key ); ?>"
						value="<?php echo esc_attr( $stored_val ); ?>"
						class="regular-text"
						autocomplete="off">
					<p class="description">
						<?php
						printf(
							/* translators: %s: PHP constant or environment variable name (same name used for both). */
							esc_html__( 'Can also be set as the %s environment variable or PHP constant for security.', 'perflocale' ),
							'<code>' . esc_html( $field['constant'] ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>

		<tr id="perflocale-wc-sync-status" data-perflocale-show-if='{"op":"AND","rules":[{"wc_currency_per_lang":true},{"wc_exchange_rate_auto":true}]}'
		<?php
		if ( ! $currency_per_lang || ! $auto_sync ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Sync Status', 'perflocale' ); ?></th>
			<td>
				<p id="perflocale-last-sync-info">
					<?php if ( ! empty( $last_sync['timestamp'] ) ) : ?>
						<?php
						$provider_name = $providers[ $last_sync['provider'] ?? '' ]['name'] ?? ( $last_sync['provider'] ?? '' );
						printf(
							/* translators: 1: date/time, 2: provider name */
							esc_html__( 'Last synced: %1$s via %2$s', 'perflocale' ),
							'<strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_sync['timestamp'] ) ) . '</strong>',
							esc_html( $provider_name )
						);
						?>
					<?php else : ?>
						<?php echo esc_html__( 'No sync performed yet.', 'perflocale' ); ?>
					<?php endif; ?>
				</p>
				<button type="button" class="button" id="perflocale-sync-now" style="margin-top:4px;vertical-align:middle;">
					<?php echo esc_html__( 'Sync Now', 'perflocale' ); ?>
				</button>
				<span id="perflocale-sync-result" style="margin-left:4px;vertical-align:middle;margin-top:4px;display:inline-block;"></span>
				<span id="perflocale-sync-spinner" class="spinner" style="float:none;vertical-align:middle;margin-top:4px;"></span>
			</td>
		</tr>

		<?php
	}
}
