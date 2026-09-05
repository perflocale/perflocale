<?php
/**
 * XLIFF 2.0 importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Xliff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports translations from XLIFF 2.0 files.
 *
 * Matches translation units by ID pattern (post-{id}-{field})
 * and updates the corresponding WordPress posts.
 *
 * Includes XXE attack prevention via secure XML loading.
 */
final class XliffImporter {

	/**
	 * Import translations from XLIFF content.
	 *
	 * @param string $xliff_content XLIFF XML string.
	 * @return array{imported: int, skipped: int, errors: array<string>}
	 *
	 * @throws XliffFormatException If the payload is empty, does not parse as XML,
	 *                             declares XML entities, or carries a `trgLang`
	 *                             that matches no active language. Callers that
	 *                             catch `\RuntimeException` still catch it.
	 */
	public function import( string $xliff_content ): array {
		// Empty input: DOMDocument::loadXML() throws a raw ValueError on an
		// empty string (PHP 8+), which bypasses the libxml error handling
		// below. Reject it up front with the same RuntimeException contract as
		// every other malformed input so direct callers get a consistent error.
		if ( trim( $xliff_content ) === '' ) {
			throw new XliffFormatException(
				esc_html__( 'XLIFF content is empty.', 'perflocale' )
			);
		}

		// Prevent XXE attacks.
		$previous = libxml_use_internal_errors( true );

		$dom = new \DOMDocument();

		// Disable external entity loading for security.
		// LIBXML_NONET prevents network access. Do NOT use LIBXML_NOENT
		// as it enables entity substitution (opposite of XXE prevention).
		$loaded = $dom->loadXML( $xliff_content, LIBXML_NONET | LIBXML_NOCDATA );

		if ( ! $loaded ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			$messages = array_map( fn( $e ) => trim( $e->message ), $errors );

			throw new XliffFormatException(
				sprintf(
					/* translators: %s: Error messages */
					esc_html__( 'Invalid XLIFF XML: %s', 'perflocale' ),
					esc_html( implode( '; ', $messages ) )
				)
			);
		}

		libxml_use_internal_errors( $previous );

		// Reject documents that declare internal XML entities. LIBXML_NONET
		// blocks external-entity XXE, but nothing else stops a "billion
		// laughs" style expansion attack via recursively-nested internal
		// entities. We don't use entities in PerfLocale XLIFF files, so any
		// presence of them here is a strong signal the upload is hostile.
		if ( $dom->doctype && $dom->doctype->entities && $dom->doctype->entities->length > 0 ) {
			throw new XliffFormatException(
				esc_html__( 'XLIFF document declares XML entities and was rejected as unsafe.', 'perflocale' )
			);
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];

		// Resolve the TARGET language from <xliff trgLang="...">. The unit id
		// (post-{id}-{field}) is the SOURCE post id baked in at export time, so
		// the translated <target> text must be written to the target-LANGUAGE
		// translation of that source — NOT to the source post itself (which
		// would overwrite the original content with the translation).
		$root        = $dom->getElementsByTagName( 'xliff' )->item( 0 );
		$target_slug = $root instanceof \DOMElement ? $this->resolve_language_slug( $root->getAttribute( 'trgLang' ) ) : '';

		if ( $target_slug === '' ) {
			throw new XliffFormatException(
				esc_html__( 'XLIFF trgLang attribute is missing or does not match any active language; refusing to import (it would otherwise overwrite source content).', 'perflocale' )
			);
		}

		$plugin  = \PerfLocale\Plugin::get_instance();
		$manager = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );

		$units = $dom->getElementsByTagName( 'unit' );

		// XLIFF 2.0 requires a unit id to be unique within its file, but
		// nothing stops a generator (or a hostile upload) from repeating one.
		// Applying every repeat means N full wp_update_post() calls - N
		// revisions, N cache invalidations, N sets of save_post hooks - that
		// all write the SAME field, and only the last one survives. Resolve
		// the winner up front and let the loop skip the superseded copies:
		// the committed result is identical, the cost is one write.
		$last_occurrence = [];
		$index           = -1;

		foreach ( $units as $pre_unit ) {
			++$index;
			$last_occurrence[ $pre_unit->getAttribute( 'id' ) ] = $index;
		}

		$index = -1;

		foreach ( $units as $unit ) {
			/** @var \DOMElement $unit */
			++$index;
			$unit_id = $unit->getAttribute( 'id' );

			if ( ! preg_match( '/^post-(\d+)-(title|content|excerpt)$/', $unit_id, $matches ) ) {
				++$skipped;
				continue;
			}

			// A superseded duplicate: counted, never written.
			if ( ( $last_occurrence[ $unit_id ] ?? $index ) !== $index ) {
				++$skipped;
				continue;
			}

			$post_id = (int) $matches[1];
			$field   = $matches[2];

			$segments = $unit->getElementsByTagName( 'segment' );

			if ( $segments->length === 0 ) {
				++$skipped;
				continue;
			}

			// XLIFF 2.0 §2.1: a unit's content is the ordered concatenation of
			// its <segment>/<ignorable> children. CAT/TM tools sentence-segment a
			// paragraph into many segments, so reading only the first would
			// silently drop the rest. Walk the children in document order; use
			// each segment's <target>, falling back to <source> for an
			// untranslated segment so a partial translation never loses content.
			$parts      = [];
			$has_target = false;

			foreach ( $unit->childNodes as $child ) {
				if ( ! $child instanceof \DOMElement ) {
					continue;
				}

				if ( $child->localName !== 'segment' && $child->localName !== 'ignorable' ) {
					continue;
				}

				$target = $child->getElementsByTagName( 'target' )->item( 0 );

				if ( $target instanceof \DOMElement && trim( $target->textContent ) !== '' ) {
					$parts[]    = $target->textContent;
					$has_target = true;
					continue;
				}

				$source = $child->getElementsByTagName( 'source' )->item( 0 );

				if ( $source instanceof \DOMElement ) {
					$parts[] = $source->textContent;
				}
			}

			if ( ! $has_target ) {
				++$skipped;
				continue;
			}

			$translated_text = implode( '', $parts );

			// The unit id is the SOURCE post; verify it exists.
			$source_post = get_post( $post_id );

			if ( ! $source_post ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'PerfLocale XLIFF import: source post %d not found.', $post_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				$errors[] = __( 'Referenced post not found.', 'perflocale' );
				continue;
			}

			// IDOR guard: require edit rights on the SOURCE post before its
			// content is copied into a new translation below. The unit id is
			// attacker-controlled and perflocale_import_export is a delegatable
			// capability, so without this a holder could reference an arbitrary
			// (incl. private/draft) post id and exfiltrate its content. Mirrors
			// the source-side edit_post gate the export path already enforces.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$errors[] = __( 'You do not have permission to edit a referenced post.', 'perflocale' );
				continue;
			}

			// Resolve the target-LANGUAGE translation of the source, creating it
			// if it doesn't exist yet (the export→translate→import flow may be
			// producing the translation for the first time). The translated text
			// lands here, leaving the source untouched.
			$target_id = $manager->get_translation_id( $post_id, $target_slug );

			if ( ! $target_id ) {
				$created = $manager->create_translation( $post_id, $target_slug, true );

				if ( $created === false ) {
					$errors[] = __( 'Could not create the target-language translation for a referenced post.', 'perflocale' );
					continue;
				}

				$target_id = (int) $created;
			}

			// Permission is checked on the TARGET post being written.
			if ( ! current_user_can( 'edit_post', $target_id ) ) {
				$errors[] = __( 'You do not have permission to edit a referenced post.', 'perflocale' );
				continue;
			}

			// Update the appropriate field on the TARGET translation.
			$update_data = [ 'ID' => $target_id ];

			switch ( $field ) {
				case 'title':
					$update_data['post_title'] = sanitize_text_field( $translated_text );
					break;

				case 'content':
					// XLIFF can come from external agencies (untrusted) OR from a
					// trusted in-house translator. Mirror WordPress's own trust
					// model: users WordPress already lets post raw HTML
					// (unfiltered_html) get a lossless round-trip; everyone else
					// gets the tightened MT allowlist. wp_update_post() also
					// applies cap-based kses, so untrusted content stays safe.
					$update_data['post_content'] = current_user_can( 'unfiltered_html' )
						? $translated_text
						: \PerfLocale\MachineTranslation\TranslationService::sanitize_mt_html( $translated_text );
					break;

				case 'excerpt':
					$update_data['post_excerpt'] = sanitize_textarea_field( $translated_text );
					break;
			}

			// $wp_error=true: without this, wp_update_post returns integer 0
			// on failure (never WP_Error), so the is_wp_error branch below was
			// unreachable and every failed update silently counted as success.
			// wp_slash: DOM textContent is unslashed; wp_update_post() unslashes
			// internally, so backslash-bearing translations would be corrupted.
			$result = wp_update_post( wp_slash( $update_data ), true );

			if ( is_wp_error( $result ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'PerfLocale XLIFF import: failed to update post %d (%s): %s', $post_id, $field, $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				$errors[] = __( 'Failed to import a translation unit.', 'perflocale' );
			} else {
				++$imported;
			}
		}

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		];
	}

	/**
	 * Map a BCP-47 language tag (the XLIFF trgLang, e.g. "de-DE") to an active
	 * PerfLocale language slug. Matches the language's own locale (rendered as
	 * BCP-47) or its slug, case-insensitively.
	 *
	 * @param string $bcp47 trgLang attribute value.
	 * @return string PerfLocale language slug, or '' if no active language matches.
	 */
	private function resolve_language_slug( string $bcp47 ): string {
		$bcp47 = strtolower( trim( $bcp47 ) );

		if ( $bcp47 === '' ) {
			return '';
		}

		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );

		foreach ( $lang_repo->get_active() as $lang ) {
			if ( strtolower( \PerfLocale\Helper::format_locale_as_bcp47( (string) $lang->locale ) ) === $bcp47
				|| strtolower( (string) $lang->slug ) === $bcp47 ) {
				return (string) $lang->slug;
			}
		}

		return '';
	}
}
