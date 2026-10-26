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
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Frontend\OptionStrings;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores imported translations of the site title and tagline (OptionStrings).
 */
final class ImportedOptionStrings {

	/**
	 * The translated options with their stored values, sources registered.
	 *
	 * Registering (idempotent) makes sure each option's string row exists before a translation is attached.
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
	 * Either way the string ends up linked in a string group; an existing translation without a link
	 * (an earlier import could not link it) is linked.
	 *
	 * @param string     $option      Option name (a key of OptionStrings::labels()).
	 * @param string     $raw         The option's stored value.
	 * @param int        $language_id Target language ID.
	 * @param string     $value       Translation, plain text.
	 * @param SourceType $source      Import source for the translation link.
	 * @return bool True when a translation was stored and linked.
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

		if ( $row === null ) {
			return false;
		}

		$string_id = (int) $row->id;
		$group_id  = (int) $row->group_id;
		$stored    = false;

		if ( $translations->get( $string_id, $language_id ) === '' ) {
			if ( ! $translations->set( $string_id, $language_id, $value ) ) {
				return false;
			}

			$stored = true;
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
		/** @var \PerfLocale\Database\Repository\TranslationGroupRepository $groups */
		$groups = $plugin->get( 'group_repo' );

		// A string link goes only into a string group: one in a post or term group (or none) gets a new one.
		$moved = ! $groups->is_string_group( $group_id );

		if ( $moved ) {
			$group_id = $groups->assign_new_string_group( $string_id );
		}

		// An existing translation, already linked in its own string group, is left as it is.
		if ( ! $stored && ! $moved && null !== $groups->get_link_state( $string_id, $language_id, ObjectType::String ) ) {
			return false;
		}

		$linked = $group_id > 0 && false !== $groups->upsert_link( $group_id, $string_id, $language_id, 'translated', $source );

		return $stored && $linked;
	}
}
