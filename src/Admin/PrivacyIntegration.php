<?php
/**
 * WordPress Privacy API integration.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\DataRegistry;
use PerfLocale\Database\Schema;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Personal data exporter and eraser (user-meta preferences, job ownership) and the suggested policy text.
 */
final class PrivacyIntegration {

	/**
	 * Exporter/eraser slug registered with WordPress.
	 */
	private const SLUG = 'perflocale';

	/**
	 * Background-job rows per exporter page: bounds one AJAX request, not the export.
	 */
	private const EXPORT_JOBS_PER_PAGE = 200;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
		add_action( 'admin_init', [ $this, 'add_privacy_policy_content' ] );
	}

	/**
	 * Advertise the exporter to WordPress.
	 *
	 * @param array<string, array<string, mixed>> $exporters Existing exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::SLUG ] = [
			'exporter_friendly_name' => __( 'PerfLocale', 'perflocale' ),
			'callback'               => [ $this, 'export_personal_data' ],
		];

		return $exporters;
	}

	/**
	 * Advertise the eraser to WordPress.
	 *
	 * @param array<string, array<string, mixed>> $erasers Existing erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::SLUG ] = [
			'eraser_friendly_name' => __( 'PerfLocale', 'perflocale' ),
			'callback'             => [ $this, 'erase_personal_data' ],
		];

		return $erasers;
	}

	/**
	 * Export the user's admin-UI preferences (page 1) and the background jobs they dispatched (paged).
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          Pagination cursor, 1-based; supplied by WP.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}|\WP_Error Error when the jobs cannot be read, so the export stops instead of completing without them.
	 */
	public function export_personal_data( string $email_address, int $page = 1 ) {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$data = [];
		$page = max( 1, $page );

		// Page 1 only: WordPress merges items sharing an item_id, so repeats would duplicate the fields.
		if ( 1 === $page ) {
			$meta_fields = [];

			// The registry's user-meta entries (hidden language columns, items per page).
			foreach ( DataRegistry::user_meta_keys() as $meta_key ) {
				$value = get_user_meta( (int) $user->ID, $meta_key, true );

				if ( $value === '' || $value === [] || $value === null ) {
					continue;
				}

				$meta_fields[] = [
					'name'  => $meta_key,
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				];
			}

			if ( ! empty( $meta_fields ) ) {
				$data[] = [
					'group_id'    => 'perflocale-user-prefs',
					'group_label' => __( 'PerfLocale Admin Preferences', 'perflocale' ),
					'item_id'     => 'perflocale-user-prefs-' . (int) $user->ID,
					'data'        => $meta_fields,
				];
			}
		}

		// The jobs the eraser anonymises. OFFSET paging, as core's comments exporter: a row deleted between
		// pages skips one later row; see docs-ai/11-code-rationale.md, "PrivacyIntegration: export and erasure".
		$jobs_table = Schema::table( 'jobs' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $jobs_table is Schema::table('jobs'), bound as a %i identifier.
		$found = $wpdb->query(
			$wpdb->prepare(
				'SELECT uuid, type, status, created_at FROM %i WHERE created_by = %d ORDER BY id ASC LIMIT %d OFFSET %d',
				$jobs_table,
				(int) $user->ID,
				self::EXPORT_JOBS_PER_PAGE,
				( $page - 1 ) * self::EXPORT_JOBS_PER_PAGE
			) ?? ''
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// query() is false on a database error and on a statement a `query` filter emptied (last_error stays as it was).
		if ( false === $found ) {
			return new \WP_Error(
				'perflocale_export_jobs_failed',
				__( 'PerfLocale could not read its background-job records. Run the export again.', 'perflocale' )
			);
		}

		$job_rows = (array) $wpdb->last_result;

		foreach ( $job_rows as $job ) {
			$data[] = [
				'group_id'    => 'perflocale-jobs',
				'group_label' => __( 'PerfLocale Background Jobs', 'perflocale' ),
				'item_id'     => 'perflocale-job-' . (string) ( $job->uuid ?? '' ),
				'data'        => [
					[
						'name'  => __( 'Job type', 'perflocale' ),
						'value' => (string) ( $job->type ?? '' ),
					],
					[
						'name'  => __( 'Status', 'perflocale' ),
						'value' => (string) ( $job->status ?? '' ),
					],
					[
						'name'  => __( 'Dispatched at', 'perflocale' ),
						'value' => (string) ( $job->created_at ?? '' ),
					],
				],
			];
		}

		// A short page is the last one (a full last page costs one empty page).
		return [
			'data' => $data,
			'done' => count( $job_rows ) < self::EXPORT_JOBS_PER_PAGE,
		];
	}

	/**
	 * Erase the user's PerfLocale data: delete the preference user-meta and anonymise their background jobs.
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          Pagination cursor (single-batch flow; ignored).
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		// Zeroing `created_by` makes the user's pending runs fail their capability check, except a type that runs without a user.
		// Null: the UPDATE failed and the rows still carry the user's ID.
		$jobs_result     = \PerfLocale\Background\JobState::try_anonymize_for_user( (int) $user->ID );
		$jobs_failed     = null === $jobs_result;
		$jobs_anonymised = (int) $jobs_result;

		// Runs even when the job update failed.
		$meta_deleted = 0;

		foreach ( DataRegistry::user_meta_keys() as $meta_key ) {
			if ( delete_user_meta( (int) $user->ID, $meta_key ) ) {
				++$meta_deleted;
			}
		}

		$messages = [];

		if ( $jobs_failed ) {
			$messages[] = __( 'PerfLocale could not update its background-job records, so they may still contain your user ID. Run the erasure again.', 'perflocale' );
		}

		if ( $jobs_anonymised > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of background-job rows anonymised */
				_n(
					'%d background-job record had your user ID removed from its dispatcher field.',
					'%d background-job records had your user ID removed from their dispatcher fields.',
					$jobs_anonymised,
					'perflocale'
				),
				$jobs_anonymised
			);
		}

		if ( $meta_deleted > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of deleted user-meta keys */
				_n(
					'%d PerfLocale admin-preference entry was deleted from your user profile.',
					'%d PerfLocale admin-preference entries were deleted from your user profile.',
					$meta_deleted,
					'perflocale'
				),
				$meta_deleted
			);
		}

		// A failed job UPDATE counts as one retained item. `done` stays true: privacy-tools.js requests the next
		// page at once and without limit, so false would loop forever on a lasting failure.
		return [
			'items_removed'  => $meta_deleted + $jobs_anonymised,
			'items_retained' => $jobs_failed ? 1 : 0,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	/**
	 * Build the deduplicated pattern list of a user's personal identifiers.
	 *
	 * Email and login always; name fields only when no other user shares them; nothing under 4 characters.
	 * Public so an add-on's scrub uses the same identifiers as this one.
	 *
	 * @param \WP_User $user
	 * @param int      $user_id
	 * @return array<int, string>
	 */
	public static function collect_pii_patterns( \WP_User $user, int $user_id ): array {
		$patterns = [];

		// Unique by WordPress design.
		foreach ( [ (string) $user->user_email, (string) $user->user_login ] as $p ) {
			$p = trim( $p );
			if ( strlen( $p ) >= 4 ) {
				$patterns[] = $p;
			}
		}

		// Shared names are skipped: scrubbing them would corrupt notes about other users.
		$display = trim( (string) $user->display_name );
		if (
			strlen( $display ) >= 4
			&& ! self::another_user_has_display_name( $display, $user_id )
		) {
			$patterns[] = $display;
		}

		$first = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
		$last  = trim( (string) get_user_meta( $user_id, 'last_name', true ) );

		if ( $first !== '' && $last !== '' ) {
			$full = $first . ' ' . $last;
			if (
				strlen( $full ) >= 4
				&& ! self::another_user_has_full_name( $first, $last, $user_id )
			) {
				$patterns[] = $full;
			}
		}

		if (
			strlen( $first ) >= 4
			&& ! self::another_user_has_meta( 'first_name', $first, $user_id )
		) {
			$patterns[] = $first;
		}

		if (
			strlen( $last ) >= 4
			&& ! self::another_user_has_meta( 'last_name', $last, $user_id )
		) {
			$patterns[] = $last;
		}

		return array_values( array_unique( $patterns ) );
	}

	/**
	 * @param string $value
	 * @param int    $exclude_user_id
	 * @return bool True if any OTHER user has this exact display_name.
	 */
	private static function another_user_has_display_name( string $value, int $exclude_user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->users} WHERE display_name = %s AND ID != %d LIMIT 1",
				$value,
				$exclude_user_id
			)
		) > 0;
	}

	/**
	 * @param string $key             `first_name` or `last_name`.
	 * @param string $value
	 * @param int    $exclude_user_id
	 * @return bool True if any OTHER user has this value in this user-meta key.
	 */
	private static function another_user_has_meta( string $key, string $value, int $exclude_user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s AND user_id != %d LIMIT 1",
				$key,
				$value,
				$exclude_user_id
			)
		) > 0;
	}

	/**
	 * @return bool True if any OTHER user has the same first_name AND
	 *              last_name pair (joined across both meta rows).
	 */
	private static function another_user_has_full_name( string $first, string $last, int $exclude_user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT u.ID)
			 FROM {$wpdb->users} u
			 INNER JOIN {$wpdb->usermeta} fm ON fm.user_id = u.ID AND fm.meta_key = 'first_name' AND fm.meta_value = %s
			 INNER JOIN {$wpdb->usermeta} lm ON lm.user_id = u.ID AND lm.meta_key = 'last_name'  AND lm.meta_value = %s
			 WHERE u.ID != %d
			 LIMIT 1",
				$first,
				$last,
				$exclude_user_id
			)
		) > 0;
	}

	/**
	 * Register suggested privacy-policy text on WP’s Privacy Guide page.
	 *
	 * GeoIP, MT and browser-language sections appear only when those features are on.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( 'PerfLocale', $this->build_privacy_policy_content() );
	}

	/**
	 * Who receives machine-translation content, as escaped HTML: the provider's label, or for the AI Client
	 * the provider connected under Settings > Connectors.
	 *
	 * @param string $provider Selected provider id.
	 * @return string
	 */
	private function mt_provider_recipient( string $provider ): string {
		if ( $provider === '' ) {
			return esc_html__( 'the configured machine-translation provider', 'perflocale' );
		}

		if ( $provider === 'wp_ai_client' ) {
			return esc_html__( 'the AI provider connected under Settings → Connectors', 'perflocale' );
		}

		$label  = $provider;
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'cache' ) ) {
			try {
				$service   = new \PerfLocale\MachineTranslation\TranslationService( $this->settings, $plugin->get( 'cache' ) );
				$providers = $service->get_providers();

				if ( isset( $providers[ $provider ] ) && $providers[ $provider ]->get_name() !== '' ) {
					$label = $providers[ $provider ]->get_name();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return '<strong>' . esc_html( $label ) . '</strong>';
	}

	/**
	 * Build the policy-text HTML for the Privacy Policy Guide; public for tests and custom screens.
	 *
	 * @return string Concatenated HTML sections (already escaped per section).
	 */
	public function build_privacy_policy_content(): string {
		$sections = [];

		$sections[] = '<p>' . esc_html__(
			'This site uses PerfLocale to serve content in multiple languages. The plugin processes a small amount of visitor data to remember language preference and (optionally) detect the preferred language automatically.',
			'perflocale'
		) . '</p>';

		$sections[] = '<h3>' . esc_html__( 'Language preference cookie', 'perflocale' ) . '</h3>'
			. '<p>' . esc_html__(
				'When you view the site in a specific language, a cookie named "perflocale_lang" is set that stores only the chosen language slug (e.g. "en", "fr"). No personal data is stored in this cookie. It is marked HttpOnly, Secure on HTTPS, and SameSite=Lax, with a default lifetime of 365 days. Clearing browser cookies removes it.',
				'perflocale'
			) . '</p>';

		if ( (bool) $this->settings->get( 'redirect_browser_lang', false ) ) {
			$sections[] = '<h3>' . esc_html__( 'Browser language detection', 'perflocale' ) . '</h3>'
				. '<p>' . esc_html__(
					'On a first visit, the standard Accept-Language HTTP header your browser sends is read to match you to a supported language. The header itself is not stored or logged.',
					'perflocale'
				) . '</p>';
		}

		if ( (bool) $this->settings->get( 'redirect_geo_enabled', false ) ) {
			$provider = (string) $this->settings->get( 'geo_provider', '' );

			$provider_label = $provider !== ''
				? $provider
				: __( 'the geolocation provider configured by this site', 'perflocale' );

			$sections[] = '<h3>' . esc_html__( 'Geolocation-based language detection', 'perflocale' ) . '</h3>'
				. '<p>' . sprintf(
					/* translators: %s: GeoIP provider name */
					esc_html__( 'On a first visit, the visitor IP address is sent to %s to resolve a country code and suggest a language version. The resolved country code is cached server-side for 24 hours under a salted one-way hash of the anonymized network address (host bits removed); neither the IP address nor any value reversible to it is stored. This feature can be disabled in PerfLocale settings.', 'perflocale' ),
					'<strong>' . esc_html( $provider_label ) . '</strong>'
				) . '</p>';
		}

		if (
			(bool) $this->settings->get( 'redirect_edge_hint_enabled', false )
			&& $this->settings->edge_integration_enabled()
		) {
			$sections[] = '<h3>' . esc_html__( 'Edge-decided language redirect', 'perflocale' ) . '</h3>'
				. '<p>' . esc_html__(
					'When this site is deployed behind an edge platform (Cloudflare, Vercel, Netlify), an edge worker may inspect the country code provided by the platform and forward a chosen language slug to the origin via the X-PerfLocale-Lang request header or a perflocale_edge_lang cookie. The cookie, when set, contains only the language slug (for example "en", "fr") - no IP address, no personal data. The country lookup happens at the edge; this plugin does not transmit your IP address to a third-party API for this feature.',
					'perflocale'
				) . '</p>';
		}

		if ( (bool) $this->settings->get( 'mt_enabled', false ) ) {
			$provider = (string) $this->settings->get( 'mt_provider', '' );

			$sections[] = '<h3>' . esc_html__( 'Machine translation', 'perflocale' ) . '</h3>'
				. '<p>' . sprintf(
					/* translators: %s: configured machine-translation provider name */
					esc_html__( 'Site administrators may send post, page, or string content to %s for machine translation. This is triggered by site staff, not by end visitors, and sends the content to the provider&rsquo;s API for the sole purpose of returning a translated version. Visit your provider&rsquo;s data-processing documentation for details on their retention and processing guarantees.', 'perflocale' ),
					$this->mt_provider_recipient( $provider )
				) . '</p>'
				. '<p>' . esc_html__(
					'Text you send for machine translation is transmitted to the provider you configured and is not retained by PerfLocale beyond the translated result saved on the post.',
					'perflocale'
				) . '</p>';
		}

		$sections[] = '<h3>' . esc_html__( 'Admin user preferences', 'perflocale' ) . '</h3>'
			. '<p>' . esc_html__(
				'For registered users with access to the WordPress admin area, PerfLocale stores a small set of UI preferences against your user profile (which language columns you have hidden on the Strings and Translations admin tables, and how many rows you display per page). These are not shared with anyone, are exported via Tools &rarr; Export Personal Data, and are deleted via Tools &rarr; Erase Personal Data or automatic cleanup when your user account is removed.',
				'perflocale'
			) . '</p>';

		return implode( "\n", $sections );
	}
}
