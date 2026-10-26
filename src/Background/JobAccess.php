<?php
/**
 * Who may read and change a background job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may read, cancel, retry and delete a job row (Jobs REST routes, Jobs page, Site Health).
 *
 * The dispatcher, with the type's capability; another user also needs the supervisor capability and edit rights on
 * the content a translation job names (another user's site run: manage_options). The map is a constant: a type's
 * worker is not registered in every request.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class JobAccess {

	/**
	 * Capability needed to see other users' jobs (the Jobs supervisor).
	 */
	public const SUPERVISOR_CAPABILITY = 'perflocale_manage_translations';

	/**
	 * Job types whose args name the posts they translate (may_edit_content()).
	 *
	 * @var list<string>
	 */
	public const CONTENT_TYPES = [ 'bulk_translate', 'site_translate', 'auto_translate' ];

	/**
	 * Job type => the capability another user needs for jobs of that type (the type's own required capability).
	 *
	 * @var array<string, string>
	 */
	public const TYPE_CAPABILITIES = [
		'data_import'              => 'perflocale_import_export',
		'data_export'              => 'perflocale_import_export',
		'bulk_translate'           => 'perflocale_manage_translations',
		'auto_translate'           => 'exist',
		'bulk_string_translate'    => 'perflocale_manage_translations',
		'site_translate'           => 'perflocale_manage_translations',
		'string_scan'              => 'perflocale_translate',
		'wpml_migration'           => 'manage_options',
		'polylang_migration'       => 'manage_options',
		'translatepress_migration' => 'manage_options',
		'sqlite_group_repair'      => 'manage_options',
		'template_refs_backfill'   => 'edit_theme_options',
		'template_refs_fanout'     => 'edit_theme_options',
		'wc_stock_check'           => 'manage_woocommerce',
		'wc_stock_align'           => 'manage_woocommerce',
	];

	/**
	 * The capability another user needs for jobs of this type ('' = dispatcher-only): the map's entry or the filter's.
	 *
	 * @param string $type Job type.
	 * @return string
	 */
	public static function type_capability( string $type ): string {
		$default = self::TYPE_CAPABILITIES[ $type ] ?? '';

		/**
		 * Filter the capability, besides perflocale_manage_translations, that another user needs to read, cancel, retry or
		 * delete a job of this type. '' keeps the type dispatcher-only; a non-string is ignored.
		 *
		 * @hook  perflocale/jobs/type_capability
		 * @since 1.0.7.4
		 *
		 * @param string $capability The capability ('' for a type without an entry).
		 * @param string $type       Job type.
		 */
		return self::string_or( apply_filters( 'perflocale/jobs/type_capability', $default, $type ), $default );
	}

	/**
	 * A filter's answer when it is a string, else the default.
	 *
	 * @param mixed  $value   The filter's answer.
	 * @param string $default The default.
	 * @return string
	 */
	private static function string_or( mixed $value, string $default ): string {
		return is_string( $value ) ? $value : $default;
	}

	/**
	 * Whether the current user may handle jobs of this type that another user dispatched.
	 *
	 * @param string $type Job type.
	 * @return bool
	 */
	public static function may_handle_others( string $type ): bool {
		$capability = self::type_capability( $type );

		return '' !== $capability
			&& current_user_can( self::SUPERVISOR_CAPABILITY )
			&& current_user_can( $capability );
	}

	/**
	 * Whether the current user, as the dispatcher, may still handle their own jobs of this type.
	 *
	 * @param string $type Job type.
	 * @return bool
	 */
	public static function may_handle_own( string $type ): bool {
		$capability = self::type_capability( $type );

		return '' === $capability || current_user_can( $capability );
	}

	/**
	 * Whether the current user may handle this job row: their own under may_handle_own(), another user's under
	 * may_handle_others() and may_edit_content().
	 *
	 * @param array<string, mixed> $state Job state row (type, created_by, args).
	 * @return bool
	 */
	public static function may_handle( array $state ): bool {
		$created_by = is_numeric( $state['created_by'] ?? null ) ? (int) $state['created_by'] : 0;
		$type       = is_string( $state['type'] ?? null ) ? $state['type'] : '';

		if ( $created_by > 0 && (int) get_current_user_id() === $created_by ) {
			return self::may_handle_own( $type );
		}

		return self::may_handle_others( $type ) && self::may_edit_content( $state );
	}

	/**
	 * Whether the current user may edit what another user's translation job names: the existing posts of a bulk or
	 * auto-translate run (host-aware); a site run names none: manage_options only. No args answers false.
	 *
	 * @param array<string, mixed> $state Job state row (type, args).
	 * @return bool
	 */
	public static function may_edit_content( array $state ): bool {
		$type = $state['type'] ?? '';

		if ( ! in_array( $type, self::CONTENT_TYPES, true ) ) {
			return true;
		}

		if ( ! is_array( $state['args'] ?? null ) ) {
			return false;
		}

		if ( 'site_translate' === $type ) {
			return current_user_can( 'manage_options' );
		}

		$named = 'auto_translate' === $type ? [ $state['args']['post_id'] ?? 0 ] : (array) ( $state['args']['source_ids'] ?? [] );
		$ids   = array_values( array_unique( array_filter( array_map( static fn( $id ): int => is_numeric( $id ) ? (int) $id : 0, $named ), static fn( int $id ): bool => $id > 0 ) ) );

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );

			foreach ( $chunk as $id ) {
				// A deleted post is skipped, as the job skips it: edit_post on it is refused to everyone.
				if ( get_post( $id ) instanceof \WP_Post && ! \PerfLocale\Helper::user_can_edit_object( $id ) ) {
					return false;
				}
			}

			\PerfLocale\Cache\CacheManager::release_runtime_posts( $chunk );
		}

		return true;
	}
}
