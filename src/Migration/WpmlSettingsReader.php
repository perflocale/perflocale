<?php
/**
 * Read-only access to the settings and language tables a WPML site leaves behind.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads WPML's own settings for the current blog without loading WPML.
 *
 * Only plain scalar keys of `icl_sitepress_settings` are read, and the
 * locale tables are read with plain SELECTs. Nothing here writes, and
 * nothing calls WPML code. The content count is the only scan of
 * `icl_translations`; it runs when an import or a preflight asks for it,
 * never while a page renders.
 */
final class WpmlSettingsReader {

	/**
	 * WPML language codes whose locale is not a prefix of the code, used when
	 * neither `icl_locale_map` nor `icl_languages` names a locale for them.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_LOCALES = [
		'zh-hans' => 'zh_CN',
		'zh-hant' => 'zh_TW',
		'no'      => 'nb_NO',
	];

	/**
	 * The database connection.
	 *
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * Messages of the database errors met by the reads so far.
	 *
	 * @var list<string>
	 */
	private array $read_errors = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb = $wpdb;
	}

	/**
	 * Database errors the reads met, in order.
	 *
	 * @return list<string>
	 */
	public function read_errors(): array {
		return $this->read_errors;
	}

	/**
	 * The parts of WPML's settings the import compares with PerfLocale's.
	 *
	 * @return array{found: bool, default: string, active: list<string>, url_mode: string, default_in_directory: bool|null}
	 */
	public function settings(): array {
		$raw = get_option( 'icl_sitepress_settings' );

		$out = [
			'found'                => false,
			'default'              => '',
			'active'               => [],
			'url_mode'             => '',
			'default_in_directory' => null,
		];

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		$out['found'] = true;

		if ( isset( $raw['default_language'] ) && is_string( $raw['default_language'] ) ) {
			$out['default'] = self::clean_code( $raw['default_language'] );
		}

		if ( isset( $raw['active_languages'] ) && is_array( $raw['active_languages'] ) ) {
			foreach ( $raw['active_languages'] as $code ) {
				if ( is_string( $code ) ) {
					$code = self::clean_code( $code );

					if ( $code !== '' ) {
						$out['active'][] = $code;
					}
				}
			}

			$out['active'] = array_values( array_unique( $out['active'] ) );
		}

		$type = isset( $raw['language_negotiation_type'] ) && is_scalar( $raw['language_negotiation_type'] )
			? (int) $raw['language_negotiation_type']
			: 0;

		$out['url_mode'] = match ( $type ) {
			1 => 'subdirectory',
			2 => 'domain',
			3 => 'query',
			default => '',
		};

		if ( $type === 1 && isset( $raw['urls'] ) && is_array( $raw['urls'] ) && array_key_exists( 'directory_for_default_language', $raw['urls'] ) && is_scalar( $raw['urls']['directory_for_default_language'] ) ) {
			$out['default_in_directory'] = (bool) $raw['urls']['directory_for_default_language'];
		}

		return $out;
	}

	/**
	 * WPML's locale for each language code it knows.
	 *
	 * `icl_locale_map` first, then `icl_languages.default_locale` for codes the
	 * map lacks, then a short list of codes whose locale is not a prefix of the
	 * code.
	 *
	 * @return array<string, string> WPML code => locale.
	 */
	public function locales(): array {
		$locales = [];
		$map     = $this->wpdb->prefix . 'icl_locale_map';
		$langs   = $this->wpdb->prefix . 'icl_languages';

		if ( $this->table_exists( $map ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT code, locale FROM %i', $map ) );
			$this->note_error();

			foreach ( (array) $rows as $row ) {
				$code   = self::clean_code( (string) ( $row->code ?? '' ) );
				$locale = trim( (string) ( $row->locale ?? '' ) );

				if ( $code !== '' && $locale !== '' ) {
					$locales[ $code ] = $locale;
				}
			}
		}

		if ( $this->table_exists( $langs ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT code, default_locale FROM %i', $langs ) );
			$this->note_error();

			foreach ( (array) $rows as $row ) {
				$code   = self::clean_code( (string) ( $row->code ?? '' ) );
				$locale = trim( (string) ( $row->default_locale ?? '' ) );

				if ( $code !== '' && $locale !== '' && ! isset( $locales[ $code ] ) ) {
					$locales[ $code ] = $locale;
				}
			}
		}

		foreach ( self::KNOWN_LOCALES as $code => $locale ) {
			if ( ! isset( $locales[ $code ] ) ) {
				$locales[ $code ] = $locale;
			}
		}

		return $locales;
	}

	/**
	 * Post and term rows per WPML language code, without the element types the import skips.
	 *
	 * Rows whose element_id is NULL are left out. Rows pointing at a post or
	 * term deleted since are counted: telling them apart needs a join over the
	 * whole posts table, and counting them errs toward refusing.
	 *
	 * @param string[] $skipped_types Element types the import skips.
	 * @return array<string, int> WPML code => rows.
	 */
	public function content_languages( array $skipped_types ): array {
		$table = $this->wpdb->prefix . 'icl_translations';

		if ( ! $this->table_exists( $table ) ) {
			return [];
		}

		$values = [ $table, $this->wpdb->esc_like( 'post_' ) . '%', $this->wpdb->esc_like( 'tax_' ) . '%' ];

		if ( $skipped_types !== [] ) {
			$values = array_merge( $values, $skipped_types );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One %s placeholder per skipped type; the values are bound through prepare().
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT language_code, COUNT(*) AS c FROM %i
				WHERE ( element_type LIKE %s OR element_type LIKE %s ) AND element_id IS NOT NULL'
				. ( $skipped_types !== [] ? ' AND element_type NOT IN (' . implode( ',', array_fill( 0, count( $skipped_types ), '%s' ) ) . ')' : '' )
				. ' GROUP BY language_code',
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$this->note_error();

		$out = [];

		foreach ( (array) $rows as $row ) {
			$code = self::clean_code( (string) ( $row->language_code ?? '' ) );

			if ( $code !== '' ) {
				$out[ $code ] = ( $out[ $code ] ?? 0 ) + (int) $row->c;
			}
		}

		return $out;
	}

	/**
	 * Language codes that appear in WPML's string translations.
	 *
	 * @return list<string>
	 */
	public function string_languages(): array {
		$table = $this->wpdb->prefix . 'icl_string_translations';

		if ( ! $this->table_exists( $table ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$codes = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT language FROM %i GROUP BY language', $table ) );
		$this->note_error();

		$out = [];

		foreach ( (array) $codes as $code ) {
			$code = self::clean_code( (string) $code );

			if ( $code !== '' ) {
				$out[] = $code;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * True when the table exists in the current database.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public function table_exists( string $table ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table ) ) );
		$this->note_error();

		return is_string( $found ) && $found === $table;
	}

	/**
	 * A WPML language code as the importer compares it.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function clean_code( string $code ): string {
		return sanitize_text_field( $code );
	}

	/**
	 * Record the error of the read that just ran, if any.
	 *
	 * @return void
	 */
	private function note_error(): void {
		if ( $this->wpdb->last_error !== '' ) {
			$this->read_errors[] = $this->wpdb->last_error;
		}
	}
}
