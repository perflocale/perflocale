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
 * Tracks where a `translation_links.source` row originated.
 *
 * Stored as the literal string value in `translation_links.source`
 * (VARCHAR(20)). Drives:
 *
 *   - the Source column / filter on the Translations admin page,
 *   - per-source bulk operations (e.g. re-run MT only on MT-sourced rows),
 *   - migration provenance reporting after Polylang/WPML/TranslatePress
 *     imports.
 *
 * Do NOT rename existing case values without a migration — the strings
 * appear verbatim in the DB.
 *
 * Adding a new case: add the case here, expose a label via
 * {@see self::label()}, and update the filter dropdown in
 * `TranslationsPage::render_source_filter()`.
 */
enum SourceType: string {

	/** Human-authored translation entered through the editor or REST. */
	case Manual = 'manual';

	/** Machine-translation result persisted to the link row. */
	case MachineTranslation = 'mt';

	/** Translation memory replay applied without going through MT. */
	case TranslationMemory = 'tm';

	/** Glossary substitution. */
	case Glossary = 'glossary';

	/** Imported from Polylang via the migration importer. */
	case ImportedPolylang = 'imported_polylang';

	/** Imported from WPML via the migration importer. */
	case ImportedWpml = 'imported_wpml';

	/** Imported from TranslatePress via the migration importer. */
	case ImportedTrp = 'imported_trp';

	/** WP-CLI bulk operation. */
	case Cli = 'cli';

	/** Webhook-driven write from an external translation service. */
	case Webhook = 'webhook';

	/** REST API write that did not specify a more specific source. */
	case Api = 'api';

	/**
	 * Coerce a raw DB value into a SourceType, falling back to Manual on
	 * unknown values (forwards-compat with future-added cases).
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
			self::TranslationMemory  => __( 'Locally cached', 'perflocale' ),
			self::Glossary           => __( 'Glossary', 'perflocale' ),
			self::ImportedPolylang   => __( 'Imported (Polylang)', 'perflocale' ),
			self::ImportedWpml       => __( 'Imported (WPML)', 'perflocale' ),
			self::ImportedTrp        => __( 'Imported (TranslatePress)', 'perflocale' ),
			self::Cli                => __( 'WP-CLI', 'perflocale' ),
			self::Webhook            => __( 'Webhook', 'perflocale' ),
			self::Api                => __( 'REST API', 'perflocale' ),
		};
	}
}
