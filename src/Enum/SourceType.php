<?php
/**
 * Translation-link provenance enum.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Enum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provenance of a translation link, stored verbatim in `translation_links.source`.
 *
 * Renaming or removing a value needs a migration: rows hold the strings. A new case needs a label() arm; the
 * Translations page source filter lists every case.
 */
enum SourceType: string {

	/** Human-authored translation entered through the editor or REST. */
	case Manual = 'manual';

	/** Machine-translation result persisted to the link row. */
	case MachineTranslation = 'mt';

	/** Imported from Polylang via the migration importer. */
	case ImportedPolylang = 'imported_polylang';

	/** Imported from WPML via the migration importer. */
	case ImportedWpml = 'imported_wpml';

	/** Imported from TranslatePress via the migration importer. */
	case ImportedTrp = 'imported_trp';

	/** WP-CLI bulk operation. */
	case Cli = 'cli';

	/** REST API write that did not specify a more specific source. */
	case Api = 'api';

	/**
	 * Coerce a raw DB value into a SourceType; an unknown value reads as Manual.
	 *
	 * @param string $value Stored column value.
	 * @return self
	 */
	public static function from_db( string $value ): self {
		return self::tryFrom( $value ) ?? self::Manual;
	}

	/**
	 * Human-readable label for admin UIs. Translated.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- false positive: `$this` is a valid object reference inside enum methods (PHP 8.1+). The sniff pre-dates enums.
		return match ( $this ) {
			self::Manual             => __( 'Manual', 'perflocale' ),
			self::MachineTranslation => __( 'Machine translation', 'perflocale' ),
			self::ImportedPolylang   => __( 'Imported (Polylang)', 'perflocale' ),
			self::ImportedWpml       => __( 'Imported (WPML)', 'perflocale' ),
			self::ImportedTrp        => __( 'Imported (TranslatePress)', 'perflocale' ),
			self::Cli                => __( 'WP-CLI', 'perflocale' ),
			self::Api                => __( 'REST API', 'perflocale' ),
		};
	}
}
