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
 * The dispatcher, with the type's capability; another user also needs the supervisor capability, the type's
 * ALSO_REQUIRES and edit rights on the content a translation job names (another user's site run: manage_options). The map is a constant: a type's
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
	public const CONTENT_TYPES = [ 'bulk_translate', 'site_translate', 'auto_translate', 'content_resync' ];

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
		'xliff_import'             => 'perflocale_import_export',
		'content_resync'           => 'perflocale_translate',
		'global_fields_sync'       => 'perflocale_manage_translations',
	];

	/**
	 * Job type => capabilities every run of it needs besides its own (AbstractJob::also_requires() reads this), as its
	 * screen asks; another user also needs them to see or change such a job.
	 *
	 * @var array<string, list<string>>
	 */
	public const ALSO_REQUIRES = [
		'data_export' => [ 'manage_options' ],
		'data_import' => [ 'manage_options' ],
		'string_scan' => [ 'manage_options' ],
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

		if ( '' === $capability || ! current_user_can( self::SUPERVISOR_CAPABILITY ) || ! current_user_can( $capability ) ) {
			return false;
		}

		foreach ( self::ALSO_REQUIRES[ $type ] ?? [] as $also ) {
			if ( ! current_user_can( $also ) ) {
				return false;
			}
		}

		return true;
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
	 * Whether args are a one-pair machine-translation hand-off (PiecesHandOff), which runs with perflocale_use_mt.
	 *
	 * @param string $type Job type.
	 * @param mixed  $args Job args.
	 * @return bool
	 */
	public static function is_handoff( string $type, mixed $args ): bool {
		return 'bulk_translate' === $type && is_array( $args ) && ! empty( $args['handoff'] )
			&& 1 === count( (array) ( $args['source_ids'] ?? [] ) ) && 1 === count( (array) ( $args['target_lang_ids'] ?? [] ) );
	}

	/**
	 * Of the current user's own rows that may_handle_own() refused, the ids of their hand-offs they may still handle.
	 *
	 * @param list<string> $ids Own bulk_translate rows.
	 * @return array<string, true>
	 */
	public static function own_handoffs( array $ids ): array {
		if ( [] === $ids || ! current_user_can( 'perflocale_use_mt' ) ) {
			return [];
		}

		$out = [];

		foreach ( JobState::args( $ids ) as $id => $args ) {
			if ( self::is_handoff( 'bulk_translate', $args ) ) {
				$out[ (string) $id ] = true;
			}
		}

		return $out;
	}

	/**
	 * Whether the current user may handle this job row: their own under may_handle_own() (a hand-off: perflocale_use_mt),
	 * another user's under may_handle_others() and may_edit_content().
	 *
	 * @param array<string, mixed> $state Job state row (type, created_by, args).
	 * @return bool
	 */
	public static function may_handle( array $state ): bool {
		$created_by = is_numeric( $state['created_by'] ?? null ) ? (int) $state['created_by'] : 0;
		$type       = is_string( $state['type'] ?? null ) ? $state['type'] : '';

		if ( $created_by > 0 && (int) get_current_user_id() === $created_by ) {
			return self::may_handle_own( $type ) || ( self::is_handoff( $type, $state['args'] ?? null ) && current_user_can( 'perflocale_use_mt' ) );
		}

		return self::may_handle_others( $type ) && self::may_edit_content( $state );
	}

	/**
	 * The job rows the current user may handle (may_handle(), the detail route's gate), for the job lists: one batched
	 * args read for other users' translation jobs and one for the user's own refused bulk rows (hand-offs). A row whose
	 * args cannot be read is left out.
	 *
	 * @param array<int|string, array<string, mixed>> $rows Summary rows by job id (type, created_by).
	 * @return array<int|string, array<string, mixed>>
	 */
	public static function visible( array $rows ): array {
		$uid      = (int) get_current_user_id();
		$allowed  = [ [], [] ];
		$content  = [];
		$handoffs = [];
		$out      = [];

		foreach ( $rows as $id => $row ) {
			$type = is_string( $row['type'] ?? null ) ? $row['type'] : '';
			$mine = $uid > 0 && is_numeric( $row['created_by'] ?? null ) && (int) $row['created_by'] === $uid ? 1 : 0;

			$allowed[ $mine ][ $type ] ??= $mine ? self::may_handle_own( $type ) : self::may_handle_others( $type );

			if ( ! $allowed[ $mine ][ $type ] ) {
				if ( 1 === $mine && 'bulk_translate' === $type ) {
					$handoffs[] = (string) $id;
				}

				continue;
			}

			if ( 0 === $mine && in_array( $type, self::CONTENT_TYPES, true ) ) {
				$content[] = (string) $id;
			}

			$out[ $id ] = $row;
		}

		$args = [] === $content ? [] : JobState::args( $content );

		foreach ( $content as $id ) {
			$state = [
				'type' => $out[ $id ]['type'],
				'args' => $args[ $id ] ?? null,
			];

			if ( ! self::may_edit_content( $state ) ) {
				unset( $out[ $id ] );
			}
		}

		// The user's own MT hand-offs (may_handle() lets them open these), in list order.
		$own = self::own_handoffs( $handoffs );

		return [] === $own ? $out : array_filter( $rows, static fn( array $row, int|string $id ): bool => isset( $out[ $id ] ) || isset( $own[ (string) $id ] ), ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * Whether the current user may edit what another user's translation job names: the existing posts of a bulk,
	 * auto-translate or ReSync sources run, and a bulk or ReSync run's translations (host-aware); a site or ReSync
	 * query run names none: manage_options only.
	 * No args answers false.
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

		if ( 'site_translate' === $type || ( 'content_resync' === $type && 'query' === ( $state['args']['mode'] ?? '' ) ) ) {
			return current_user_can( 'manage_options' );
		}

		$named = 'auto_translate' === $type ? [ $state['args']['post_id'] ?? 0 ] : (array) ( $state['args']['source_ids'] ?? [] );
		$ids   = self::ids( $named );

		if ( ! self::may_edit_posts( $ids ) ) {
			return false;
		}

		// A bulk or ReSync run writes the translations: each needs edit rights too.
		$key = [
			'bulk_translate' => 'target_lang_ids',
			'content_resync' => 'language_ids',
		][ $type ] ?? '';

		if ( '' !== $key && [] !== $ids ) {
			$languages = self::ids( (array) ( $state['args'][ $key ] ?? [] ) );
			$repo      = new \PerfLocale\Database\Repository\ResyncRepository();

			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				$translations = $repo->translations_of( $chunk, $languages );

				if ( null === $translations || ! self::may_edit_posts( $translations ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Positive integer ids, unique.
	 *
	 * @param array<int|string, mixed> $named Raw ids.
	 * @return list<int>
	 */
	private static function ids( array $named ): array {
		return array_values( array_unique( array_filter( array_map( static fn( $id ): int => is_numeric( $id ) ? (int) $id : 0, $named ), static fn( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * Whether the current user may edit every existing post: sizes first (200 per statement), then reads within
	 * PostReads' byte budget. A failed read, or a post too large to load here, refuses.
	 *
	 * @param list<int> $ids Post ids.
	 * @return bool
	 */
	private static function may_edit_posts( array $ids ): bool {
		$ceiling = \PerfLocale\Util\WorkBudget::work_limit() ?? 0;

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$sizes = self::sizes( $chunk );

			if ( null === $sizes ) {
				return false;
			}

			$bound  = \PerfLocale\Resync\PostReads::post_bound( $ceiling );
			$budget = \PerfLocale\Resync\PostReads::budget( $ceiling );
			$batch  = [];
			$bytes  = 0;

			// A deleted post (no row) is skipped, as the job skips it: edit_post on it is refused to everyone.
			foreach ( $sizes as $id => $size ) {
				$size += \PerfLocale\Resync\PostReads::POST_BYTES;

				if ( $size > $bound ) {
					return false;
				}

				if ( [] !== $batch && $bytes + $size > $budget ) {
					if ( ! self::may_edit_read( $batch ) ) {
						return false;
					}

					$budget = \PerfLocale\Resync\PostReads::budget( $ceiling );
					$batch  = [];
					$bytes  = 0;
				}

				$batch[] = $id;
				$bytes  += $size;
			}

			if ( [] !== $batch && ! self::may_edit_read( $batch ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Text bytes by id of the posts that exist; null when the read fails.
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return array<int, int>|null
	 */
	private static function sizes( array $ids ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The IN list is built from placeholders and the byte expression from fixed column names.
		$sql = $wpdb->prepare( 'SELECT p.ID, ' . \PerfLocale\Resync\PostReads::bytes_sql() . ' AS bytes FROM %i p WHERE p.ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $wpdb->posts ], $ids ) );

		// query(), not get_results(): a failed read must not look like deleted posts.
		if ( ! is_string( $sql ) || '' === $sql || ! is_int( $wpdb->query( $sql ) ) ) {
			return null;
		}
		// phpcs:enable

		$sizes = [];

		foreach ( (array) $wpdb->last_result as $row ) {
			$row = (array) $row;

			$sizes[ \PerfLocale\Util\Scalar::int( $row['ID'] ?? 0 ) ] = \PerfLocale\Util\Scalar::int( $row['bytes'] ?? 0 );
		}

		return $sizes;
	}

	/**
	 * Whether the current user may edit each of these posts, read in one statement and released after.
	 *
	 * @param array<int, int> $ids Post ids whose rows were just read: one that cannot be read now refuses.
	 * @return bool
	 */
	private static function may_edit_read( array $ids ): bool {
		_prime_post_caches( $ids, false, false );

		try {
			foreach ( $ids as $id ) {
				if ( ! get_post( $id ) instanceof \WP_Post || ! \PerfLocale\Helper::user_can_edit_object( $id ) ) {
					return false;
				}
			}
		} finally {
			\PerfLocale\Cache\CacheManager::release_runtime_posts( $ids );
		}

		return true;
	}
}
