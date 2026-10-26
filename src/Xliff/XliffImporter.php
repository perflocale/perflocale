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
 * Imports XLIFF 2.0 translations: units with ids `post-{id}-{field}` update the trgLang translation of that post,
 * with entity declarations refused and size limits checked before parsing.
 */
final class XliffImporter {

	/**
	 * Largest XLIFF document import() loads, in bytes (32 MB): the DOM's memory is libxml's, outside memory_limit.
	 * Measurements: docs-ai/11-code-rationale.md, "XliffImporter: input limits".
	 */
	public const MAX_BYTES = 33554432;

	/**
	 * Most `<unit>` elements import() loads: each costs DOM memory and can cost a post write.
	 */
	public const MAX_UNITS = 30000;

	/**
	 * Most `<unit>` elements one import over the web applies: half of a 30 s limit at the worst measured cost
	 * (0.23 s per unit when each unit creates a translated post).
	 */
	public const WEB_MAX_UNITS = 60;

	/**
	 * Most markup items (tags, end tags, comments, CDATA, instructions, attributes) import() loads: libxml's DOM memory
	 * per element is outside memory_limit.
	 */
	public const MAX_NODES = 1000000;

	/**
	 * Most attributes on one element: libxml 2.9 checks duplicates in quadratic time.
	 */
	public const MAX_ATTRIBUTES = 64;

	/**
	 * Import translations from XLIFF content.
	 *
	 * @param string $xliff_content XLIFF XML string.
	 * @param int    $max_units     Most units to apply (WEB_MAX_UNITS over the web); never above MAX_UNITS.
	 * @return array{imported: int, skipped: int, errors: array<string>}
	 *
	 * @throws XliffFormatException If the payload is empty, is over MAX_BYTES,
	 *                             MAX_NODES or $max_units, does not parse as XML,
	 *                             declares XML entities, or carries a `trgLang`
	 *                             that matches no active language. Callers that
	 *                             catch `\RuntimeException` still catch it.
	 * @throws \RuntimeException   If PHP lacks ext-dom or ext-libxml: the server's fault (5xx), not the client's.
	 */
	public function import( string $xliff_content, int $max_units = self::MAX_UNITS ): array {
		// loadXML() throws a raw ValueError on an empty string: refuse it with the usual exception.
		if ( trim( $xliff_content ) === '' ) {
			throw new XliffFormatException(
				esc_html__( 'XLIFF content is empty.', 'perflocale' )
			);
		}

		// A PHP build without ext-dom would fail with "Class DOMDocument not found"; name the remedy instead, as a
		// RuntimeException (5xx: the upload is fine).
		if ( ! class_exists( '\DOMDocument' ) || ! function_exists( 'libxml_use_internal_errors' ) ) {
			throw new \RuntimeException(
				esc_html__( 'XLIFF import needs the PHP dom and libxml extensions, which are not installed on this server. Ask your host to enable the php-xml package.', 'perflocale' )
			);
		}

		// Size limits before parsing: nothing is loaded or written for a file over one.
		$max_units = min( $max_units, self::MAX_UNITS );
		$this->check_limits( $xliff_content, $max_units );

		// Prevent XXE attacks.
		$previous = libxml_use_internal_errors( true );

		$dom = new \DOMDocument();

		// LIBXML_NONET: no network, no external entities. Never LIBXML_NOENT, which enables entity substitution.
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

		// Refuse declared internal entities ("billion laughs" expansion): PerfLocale files declare none.
		if ( $dom->doctype && $dom->doctype->entities && $dom->doctype->entities->length > 0 ) {
			throw new XliffFormatException(
				esc_html__( 'XLIFF document declares XML entities and was rejected as unsafe.', 'perflocale' )
			);
		}

		// The decoded count: the byte checks before parsing see no `<unit` in UTF-16 and other encodings.
		if ( $dom->getElementsByTagName( 'unit' )->length > $max_units ) {
			throw self::limit_refusal( 'units', $max_units ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
		}

		$imported = 0;
		$skipped  = 0;
		$report   = new XliffImportErrors();

		// The target language from trgLang: the unit id names the source post, and the text goes to its translation in
		// that language, never over the source.
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

		// Repeated unit ids: only the last is written (the same result, one write instead of N saves).
		$last_occurrence = [];
		$index           = -1;

		foreach ( $units as $pre_unit ) {
			++$index;
			$last_occurrence[ $pre_unit->getAttribute( 'id' ) ] = $index;
		}

		$index = -1;

		// One target's validated, unslashed fields are held until the loop reaches another target, then written with one
		// wp_update_post(). Locals and a closure: a property would survive a switch_to_blog().
		/** @var int|null $pending_id Target post the buffer is holding, if any. */
		$pending_id = null;
		/** @var array{post_title?: string, post_content?: string, post_excerpt?: string} $pending_fields Unslashed, sanitised field values. */
		$pending_fields = [];
		/** @var array<string, int> $pending_units Unit ids held for that post, each mapped to the post ID it names. */
		$pending_units = [];

		/**
		 * Write the held target post, if any, and account for every unit in it: one wp_slash() before one
		 * wp_update_post() (textContent is unslashed and wp_update_post() unslashes).
		 */
		$flush = function () use ( &$pending_id, &$pending_fields, &$pending_units, &$imported, $report ): void {
			if ( $pending_id === null ) {
				return;
			}

			$held_id     = $pending_id;
			$held_fields = $pending_fields;
			$held_units  = $pending_units;

			// Cleared before the write, so a throwing save hook cannot make a later flush write it twice.
			$pending_id     = null;
			$pending_fields = [];
			$pending_units  = [];

			// An empty page_template keeps the stored one: a template the theme lacks would stop the save before its hooks.
			$postarr                  = $held_fields;
			$postarr['ID']            = $held_id;
			$postarr['page_template'] = '';

			$result = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $result ) ) {
				// Logged whatever WP_DEBUG says: the reported error points here.
				error_log( sprintf( 'PerfLocale XLIFF import: failed to update post %d (%s): %s', $held_id, implode( ', ', array_keys( $held_units ) ), $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				// Every held unit is reported, under the post its id names.
				foreach ( $held_units as $held_unit_id => $held_source_id ) {
					$report->add( XliffImportErrors::SAVE_FAILED, $held_source_id, (string) $held_unit_id );
				}
			} else {
				$imported += count( $held_units );

				if ( isset( $held_fields['post_content'] ) && \PerfLocale\Translation\BlockTemplateSupport::is_template_type( (string) get_post_type( $held_id ) ) ) {
					\PerfLocale\Translation\TemplateBlockRefs::content_replaced( $held_id );
				}
			}
		};

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

			// A unit's content is its <segment>/<ignorable> children in order (XLIFF 2.0 2.1); an untranslated segment
			// falls back to its <source>, so a partial translation loses nothing.
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
				$report->add( XliffImportErrors::NOT_FOUND, $post_id, $unit_id );
				continue;
			}

			// Edit rights on the source post the file names, as on export; the copy source is checked at creation below.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$report->add( XliffImportErrors::CANNOT_EDIT, $post_id, $unit_id );
				continue;
			}

			// The target-language translation of the source, created when missing (a first import).
			$target_id = $manager->get_translation_id( $post_id, $target_slug );

			if ( ! $target_id ) {
				// Check the copy source, not only the post the unit names.
				$copy_from = $manager->get_copy_source_id( $post_id, $target_slug );

				if ( $copy_from > 0 && $copy_from !== $post_id && ! \PerfLocale\Helper::user_can_copy_translation_source( $copy_from ) ) {
					$report->add( XliffImportErrors::CANNOT_EDIT_COPY_SOURCE, $post_id, $unit_id, $copy_from );
					continue;
				}

				// The new post takes the copy source's type: that type's create capability.
				if ( $copy_from > 0 && ! \PerfLocale\Helper::user_can_create_like( $copy_from ) ) {
					$report->add( XliffImportErrors::CANNOT_CREATE, $post_id, $unit_id );
					continue;
				}

				// create_translation() copies the source row, which can be a held post (target = site default): flush first.
				$flush();

				$created = $manager->create_translation( $post_id, $target_slug, true );

				if ( $created === false ) {
					// Logged whatever WP_DEBUG says: the reported error points here.
					error_log( sprintf( 'PerfLocale XLIFF import: could not create the %s translation of post %d (%s).', $target_slug, $post_id, $unit_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					$report->add( XliffImportErrors::CREATE_FAILED, $post_id, $unit_id );
					continue;
				}

				$target_id = (int) $created;
			}

			// A different target ends the previous one's batch.
			if ( $pending_id !== null && $pending_id !== (int) $target_id ) {
				$flush();
			}

			// Permission is checked on the TARGET post being written.
			if ( ! current_user_can( 'edit_post', $target_id ) ) {
				$report->add( XliffImportErrors::CANNOT_EDIT_TRANSLATION, $post_id, $unit_id, (int) $target_id );
				continue;
			}

			// The field on the target translation.
			$update_data = [ 'ID' => $target_id ];

			switch ( $field ) {
				case 'title':
					// Not sanitize_text_field(): it deletes %XX sequences ("100%25 off" would become "100 off").
					$update_data['post_title'] = \PerfLocale\Helper::sanitize_plain_text_field( $translated_text );
					break;

				case 'content':
					// WordPress's trust model: unfiltered_html users get a lossless round trip, everyone else the MT allowlist
					// (wp_update_post() applies kses by capability too).
					$update_data['post_content'] = current_user_can( 'unfiltered_html' )
						? $translated_text
						: \PerfLocale\Translation\ProtectedUnits::sanitize_around( $translated_text, $post_id, [ \PerfLocale\MachineTranslation\TranslationService::class, 'sanitize_mt_html' ] );
					break;

				case 'excerpt':
					// As content: an excerpt can carry markup (WooCommerce's `<p>` short description).
					$update_data['post_excerpt'] = current_user_can( 'unfiltered_html' )
						? $translated_text
						: \PerfLocale\MachineTranslation\TranslationService::sanitize_mt_html( $translated_text );
					break;
			}

			// Held: later units of the same target merge in, a repeated field overwrites (last wins, as for unit ids).
			$pending_id      = (int) $target_id;
			$pending_fields  = array_merge( $pending_fields, $update_data );
			$pending_units[ $unit_id ] = $post_id;
		}

		// Commit the final target.
		$flush();

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
			// One message per post and cause. A title only for a post the user
			// can edit; never for a missing post or one they cannot edit.
			'errors'   => $report->messages(
				static function ( int $id ): string {
					$post = $id > 0 ? get_post( $id ) : null;

					return $post instanceof \WP_Post && current_user_can( 'edit_post', $id ) ? (string) $post->post_title : '';
				}
			),
		];
	}

	/**
	 * Refuse a document over MAX_BYTES or $max_units units without building a DOM: substr_count( "<unit" ) is an upper
	 * bound, and only a file over it pays for the exact count.
	 *
	 * @param string $xml       XLIFF XML string.
	 * @param int    $max_units Most units.
	 * @throws XliffFormatException When a limit is exceeded.
	 */
	private function check_limits( string $xml, int $max_units ): void {
		if ( strlen( $xml ) > self::MAX_BYTES ) {
			throw self::limit_refusal( 'bytes', $max_units ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
		}

		// Before the unit count: its XMLReader pass parses too.
		if ( self::over_markup( $xml ) ) {
			throw self::limit_refusal( 'nodes', $max_units ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
		}

		if ( substr_count( $xml, '<unit' ) <= $max_units ) {
			return;
		}

		if ( $this->count_units( $xml, $max_units + 1 ) > $max_units ) {
			throw self::limit_refusal( 'units', $max_units ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
		}
	}

	/**
	 * Whether the document has over MAX_NODES markup items or an element with over MAX_ATTRIBUTES attributes,
	 * read without a parser: CDATA, comment and instruction bodies are skipped, a quoted value is one attribute.
	 *
	 * @param string $xml XLIFF XML string.
	 * @return bool
	 */
	private static function over_markup( string $xml ): bool {
		$items = 0;
		$pos   = strpos( $xml, '<' );

		while ( false !== $pos ) {
			if ( ++$items > self::MAX_NODES ) {
				return true;
			}

			if ( false !== strpos( '!?/', $xml[ $pos + 1 ] ?? 'x' ) ) {
				foreach ( [ '<![CDATA[' => ']]>', '<!--' => '-->', '<?' => '?>', '<' => '>' ] as $open => $close ) {
					if ( 0 === substr_compare( $xml, $open, $pos, strlen( $open ) ) ) {
						$end = strpos( $xml, $close, $pos + strlen( $open ) );
						$pos = false === $end ? false : strpos( $xml, '<', $end + strlen( $close ) );

						continue 2;
					}
				}
			}

			$attrs = 0;
			$pos  += 1 + strcspn( $xml, '"\'>', $pos + 1 );

			while ( isset( $xml[ $pos ] ) && '>' !== $xml[ $pos ] ) {
				$end = strpos( $xml, $xml[ $pos ], $pos + 1 );

				if ( false === $end ) {
					return false;
				}

				if ( ++$attrs > self::MAX_ATTRIBUTES ) {
					return true;
				}

				$pos = $end + 1 + strcspn( $xml, '"\'>', $end + 1 );
			}

			$items += $attrs;

			if ( $items > self::MAX_NODES ) {
				return true;
			}

			$pos = isset( $xml[ $pos ] ) ? strpos( $xml, '<', $pos ) : false;
		}

		return false;
	}

	/**
	 * Count `<unit>` elements as getElementsByTagName( 'unit' ) does, streaming with XMLReader and the DOM load's libxml
	 * options, stopping at $stop_at. A parse error ends the count; without ext-xmlreader 0 (the DOM count decides).
	 *
	 * @param string $xml     XLIFF XML string.
	 * @param int    $stop_at Count at which to stop.
	 * @return int Units counted, at most $stop_at.
	 */
	private function count_units( string $xml, int $stop_at ): int {
		if ( ! class_exists( '\XMLReader' ) ) {
			return 0;
		}

		$previous = libxml_use_internal_errors( true );
		$count    = 0;

		try {
			$reader = \XMLReader::XML( $xml, null, LIBXML_NONET );

			if ( $reader instanceof \XMLReader ) {
				while ( $count < $stop_at && $reader->read() ) {
					if ( $reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'unit' ) {
						++$count;
					}
				}

				$reader->close();
			}
		} finally {
			// The DOM load reports its own errors only.
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		return $count;
	}

	/**
	 * The refusal for an exceeded limit (REST 413).
	 *
	 * @param 'bytes'|'units'|'nodes' $limit     Which limit was exceeded.
	 * @param int                     $max_units The unit limit applied.
	 */
	private static function limit_refusal( string $limit, int $max_units ): XliffFormatException {
		if ( $limit === 'nodes' ) {
			$message = esc_html__( 'XLIFF import refused: the file has more XML elements or attributes than one import reads. Export fewer posts per file and import each file on its own.', 'perflocale' );
		} elseif ( $limit === 'bytes' ) {
			$message = sprintf(
				/* translators: %s: size limit, for example "32 MB" */
				esc_html__( 'XLIFF import refused: the file is larger than the %s limit. Export fewer posts per file and import each file on its own.', 'perflocale' ),
				size_format( self::MAX_BYTES )
			);
		} else {
			$message = sprintf(
				/* translators: %s: unit limit, for example "30,000" */
				esc_html__( 'XLIFF import refused: the file has more than %s translation units, the limit for one import. Export fewer posts per file and import each file on its own.', 'perflocale' ),
				number_format_i18n( $max_units )
			);
		}

		return new XliffFormatException( $message, 413 );
	}

	/**
	 * Map a BCP 47 trgLang ("de-DE") to an active language slug, by its locale as BCP 47 or its slug, ignoring case.
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
