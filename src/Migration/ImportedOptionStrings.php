<?php
/**
 * Site title and tagline translations from a migrated plugin.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\StringTranslationRepository;
use PerfLocale\Enum\SourceType;
use PerfLocale\Frontend\OptionStrings;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores imported translations of the options PerfLocale translates per
 * language ({@see OptionStrings}: the site title and tagline).
 */
final class ImportedOptionStrings {

	/**
	 * The translated options with their stored values, sources registered.
	 *
	 * Registering is idempotent, and it makes sure each option's string row
	 * exists before a translation is attached to it.
	 *
	 * @return array<string, string> Option name => stored value (non-empty only).
	 */
	public static function sources(): array {
		OptionStrings::register_source_strings();

		$values = [];

		foreach ( array_keys( OptionStrings::labels() ) as $option ) {
			$raw = (string) get_option( $option, '' );

			if ( $raw !== '' ) {
				$values[ $option ] = $raw;
			}
		}

		return $values;
	}

	/**
	 * Store one option's translation, unless the language already has one.
	 *
	 * @param string     $option      Option name (a key of OptionStrings::labels()).
	 * @param string     $raw         The option's stored value.
	 * @param int        $language_id Target language ID.
	 * @param string     $value       Translation, plain text.
	 * @param SourceType $source      Import source for the translation link.
	 * @return bool True when a translation was stored.
	 */
	public static function save( string $option, string $raw, int $language_id, string $value, SourceType $source ): bool {
		$value = sanitize_textarea_field( $value );

		if ( $value === '' || $language_id <= 0 ) {
			return false;
		}

		$plugin       = Plugin::get_instance();
		$strings      = new StringRepository( $plugin->get( 'cache' ) );
		$translations = new StringTranslationRepository( $plugin->get( 'cache' ) );
		$row          = $strings->find_by_hash( OptionStrings::DOMAIN, $option, $raw );

		if ( $row === null || $translations->get( (int) $row->id, $language_id ) !== '' ) {
			return false;
		}

		if ( ! $translations->set( (int) $row->id, $language_id, $value ) ) {
			return false;
		}

		if ( (int) ( $row->group_id ?? 0 ) > 0 ) {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
			/** @var \PerfLocale\Database\Repository\TranslationGroupRepository $groups */
			$groups = $plugin->get( 'group_repo' );
			$groups->upsert_link( (int) $row->group_id, (int) $row->id, $language_id, 'translated', $source );
		}

		return true;
	}
}
