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
 * Imports XLIFF 2.0 translations: units with ids `post-{id}-{field}` update the trgLang translation of that post in
 * one save per post, with entity declarations refused and size limits checked before parsing.
 */
final class XliffImporter {

	/**
	 * Largest XLIFF document load() accepts, in bytes (32 MB): the DOM's memory is libxml's, outside memory_limit.
	 * Measurements: docs-ai/11-code-rationale.md, "XliffImporter: input limits".
	 */
	public const MAX_BYTES = 33554432;

	/**
	 * Most `<unit>` elements load() accepts: each costs DOM memory and can cost a post write.
	 */
	public const MAX_UNITS = 30000;

	/**
	 * Most markup items (tags, end tags, comments, CDATA, instructions, attributes) load() accepts: libxml's DOM memory
	 * per element is outside memory_limit.
	 */
	public const MAX_NODES = 1000000;

	/**
	 * Most attributes on one element: libxml 2.9 checks duplicates in quadratic time.
	 */
	public const MAX_ATTRIBUTES = 64;

	/**
	 * Mark on a translation an import creates, "<source id>:<target slug>:<time>", written with the insert, extended by
	 * ":<row fingerprint>" when the insert ends, removed when create_translation() returns: recover_creation() reads it.
	 */
	public const CREATING_META_KEY = '_perflocale_xliff_creating';

	/**
	 * With a message limit, most bytes the kept messages take as JSON: the job result holding them is capped at 64 KB.
	 */
	public const ERROR_BYTES = 32768;

	/**
	 * Import translations from XLIFF content in one run.
	 *
	 * @param string $xliff_content XLIFF XML string.
	 * @return array{imported: int, skipped: int, errors: array<string>}
	 *
	 * @throws XliffFormatException As load().
	 * @throws \RuntimeException    If PHP lacks ext-dom or ext-libxml: the server's fault (5xx), not the client's.
	 */
	public function import( string $xliff_content ): array {
		[ $dom, $target_slug ] = $this->load( $xliff_content );

		$state = $this->apply( $dom, $target_slug );

		return [
			'imported' => $state['imported'],
			'skipped'  => $state['skipped'],
			'errors'   => $state['errors'],
		];
	}

	/**
	 * Check and parse a document, writing nothing: size limits before parsing, entity declarations refused, trgLang
	 * resolved.
	 *
	 * @param string $xliff_content XLIFF XML string.
	 * @return array{0: \DOMDocument, 1: string} The document and the target language slug.
	 *
	 * @throws XliffFormatException If the payload is empty, is over MAX_BYTES,
	 *                             MAX_NODES or MAX_UNITS, does not parse as XML,
	 *                             declares XML entities, or carries a `trgLang`
	 *                             that matches no active language. Callers that
	 *                             catch `\RuntimeException` still catch it.
	 * @throws \RuntimeException   If PHP lacks ext-dom or ext-libxml: the server's fault (5xx), not the client's.
	 */
	public function load( string $xliff_content ): array {
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
		$this->check_limits( $xliff_content );

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
		if ( $dom->getElementsByTagName( 'unit' )->length > self::MAX_UNITS ) {
			throw self::limit_refusal( 'units' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
		}

		// The target language from trgLang: the unit id names the source post, and the text goes to its translation in
		// that language, never over the source.
		$root        = $dom->getElementsByTagName( 'xliff' )->item( 0 );
		$target_slug = $root instanceof \DOMElement ? $this->resolve_language_slug( $root->getAttribute( 'trgLang' ) ) : '';

		if ( $target_slug === '' ) {
			throw new XliffFormatException(
				esc_html__( 'XLIFF trgLang attribute is missing or does not match any active language; refusing to import (it would otherwise overwrite source content).', 'perflocale' )
			);
		}

		return [ $dom, $target_slug ];
	}

	/**
	 * Apply a loaded document post by post from $from's position: each post's units reach its translation in one
	 * write, so a stop between posts never leaves one half-written.
	 *
	 * @param \DOMDocument         $dom         Document from load().
	 * @param string               $target_slug Target language slug from load().
	 * @param array<string, mixed> $from        A state this method returned, to resume from; [] to start.
	 * @param (callable(array<string, mixed>, int): bool)|null $before_post True stops the run before that post.
	 * @param int                  $max_errors  Most messages kept (0: all), within ERROR_BYTES; the rest are counted in
	 *                                          errors_more.
	 * @param bool                 $titles      Whether messages may name a post the user can edit by its title.
	 * @return array{done: int, posts: int, imported: int, skipped: int, errors: array<int, string>, errors_more: int}
	 *         Finished when done equals posts.
	 */
	public function apply( \DOMDocument $dom, string $target_slug, array $from = [], ?callable $before_post = null, int $max_errors = 0, bool $titles = true ): array {
		[ $posts, $plan_skipped ] = self::plan( $dom );

		$total   = count( $posts );
		$resumed = self::resumed( $from, $total );
		$state   = $resumed ?? [
			'done'        => 0,
			'posts'       => $total,
			'imported'    => 0,
			'skipped'     => $plan_skipped,
			'errors'      => [],
			'errors_more' => 0,
		];
		$start   = $state['done'];
		$bytes   = $max_errors > 0 ? strlen( (string) wp_json_encode( $state['errors'] ) ) : 0;
		$plugin  = \PerfLocale\Plugin::get_instance();
		$manager = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );

		// A title only for a post the user can edit; never for a missing post or one they cannot edit.
		$title_for = static function ( int $id ) use ( $titles ): string {
			$post = $titles && $id > 0 ? get_post( $id ) : null;

			return $post instanceof \WP_Post && current_user_can( 'edit_post', $id ) ? (string) $post->post_title : '';
		};

		for ( $i = $start; $i < $total; $i++ ) {
			if ( null !== $before_post && $before_post( $state, $posts[ $i ][0] ) ) {
				break;
			}

			$report = new XliffImportErrors();

			// A resumed run's first post may have been stopped inside the creation of its translation; a translation
			// edited since keeps its text.
			if ( null !== $resumed && $i === $start && ! self::recover_creation( $manager, $posts[ $i ][0], $target_slug, $posts[ $i ][1], $report ) ) {
				[ $imported, $skipped ] = [ 0, count( $posts[ $i ][1] ) ];
			} else {
				// A resumed run's first post may have been saved before the stop: an identical save is skipped.
				[ $imported, $skipped ] = $this->apply_post( $posts[ $i ][1], $target_slug, $manager, $report, null !== $resumed && $i === $start );
			}

			$state['imported'] += $imported;
			$state['skipped']  += $skipped;

			foreach ( $report->messages( $title_for ) as $message ) {
				$size = $max_errors > 0 ? strlen( (string) wp_json_encode( $message ) ) + 1 : 0;

				if ( $max_errors > 0 && ( count( $state['errors'] ) >= $max_errors || $bytes + $size > self::ERROR_BYTES ) ) {
					++$state['errors_more'];
				} else {
					$state['errors'][] = $message;
					$bytes            += $size;
				}
			}

			$state['done'] = $i + 1;
		}

		return $state;
	}

	/**
	 * The posts to apply, in the order of their first applied unit, each with its units in file order; and the units
	 * skipped up front: an id not of the form post-{id}-{field}, or a repeated id (only its last occurrence applies).
	 *
	 * @param \DOMDocument $dom Document.
	 * @return array{0: array<int, array{0: int, 1: array<int, \DOMElement>}>, 1: int}
	 */
	private static function plan( \DOMDocument $dom ): array {
		$units = [];
		$last  = [];

		foreach ( $dom->getElementsByTagName( 'unit' ) as $unit ) {
			$last[ $unit->getAttribute( 'id' ) ] = count( $units );
			$units[]                             = $unit;
		}

		$by_post = [];
		$skipped = 0;

		foreach ( $units as $index => $unit ) {
			$unit_id = $unit->getAttribute( 'id' );

			if ( ! preg_match( '/^post-(\d+)-(title|content|excerpt)$/', $unit_id, $matches ) || $last[ $unit_id ] !== $index ) {
				++$skipped;
				continue;
			}

			$by_post[ (int) $matches[1] ][] = $unit;
		}

		$posts = [];

		foreach ( $by_post as $post_id => $post_units ) {
			$posts[] = [ $post_id, $post_units ];
		}

		return [ $posts, $skipped ];
	}

	/**
	 * A state to resume from, or null to start: $from is empty or does not fit this document.
	 *
	 * @param array<string, mixed> $from  A state apply() returned.
	 * @param int                  $total Posts in this document.
	 * @return array{done: int, posts: int, imported: int, skipped: int, errors: array<int, string>, errors_more: int}|null
	 */
	private static function resumed( array $from, int $total ): ?array {
		if ( [] === $from ) {
			return null;
		}

		$done = $from['done'] ?? null;

		if ( ! is_int( $done ) || $done < 0 || $done > $total || ( $from['posts'] ?? null ) !== $total ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic for a checkpoint that does not match the file.
			error_log( 'PerfLocale XLIFF import: the stored progress does not match the file, so the import starts again at the first post.' );

			return null;
		}

		return [
			'done'        => $done,
			'posts'       => $total,
			'imported'    => \PerfLocale\Admin\Typed::int( $from['imported'] ?? 0 ),
			'skipped'     => \PerfLocale\Admin\Typed::int( $from['skipped'] ?? 0 ),
			'errors'      => \PerfLocale\Admin\Typed::strs( $from['errors'] ?? [] ),
			'errors_more' => \PerfLocale\Admin\Typed::int( $from['errors_more'] ?? 0 ),
		];
	}

	/**
	 * Apply one source post's units: the checks per unit, then one wp_update_post() of its target translation.
	 *
	 * @param array<int, \DOMElement> $units       The post's units, in file order.
	 * @param string                  $target_slug Target language slug.
	 * @param \PerfLocale\Translation\PostTranslationManager $manager Writes through it.
	 * @param XliffImportErrors       $report      Collects the units not applied.
	 * @param bool                    $skip_same   No save when the translation already holds every value.
	 * @return array{0: int, 1: int} Units imported and units skipped.
	 */
	private function apply_post( array $units, string $target_slug, \PerfLocale\Translation\PostTranslationManager $manager, XliffImportErrors $report, bool $skip_same ): array {
		$skipped   = 0;
		$target_id = 0;
		/** @var array{post_title?: string, post_content?: string, post_excerpt?: string} $fields Unslashed, sanitised values. */
		$fields = [];
		/** @var array<string, int> $held Unit ids applied, each mapped to the post ID it names. */
		$held = [];

		foreach ( $units as $unit ) {
			$unit_id = $unit->getAttribute( 'id' );

			if ( ! preg_match( '/^post-(\d+)-(title|content|excerpt)$/', $unit_id, $matches ) ) {
				continue;
			}

			$post_id = (int) $matches[1];
			$field   = $matches[2];

			if ( $unit->getElementsByTagName( 'segment' )->length === 0 ) {
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
			if ( ! get_post( $post_id ) ) {
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
			$target_id = (int) $manager->get_translation_id( $post_id, $target_slug );

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

				$created = self::create_marked( $manager, $post_id, $target_slug );

				if ( $created === false ) {
					// Logged whatever WP_DEBUG says: the reported error points here.
					error_log( sprintf( 'PerfLocale XLIFF import: could not create the %s translation of post %d (%s).', $target_slug, $post_id, $unit_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					$report->add( XliffImportErrors::CREATE_FAILED, $post_id, $unit_id );
					continue;
				}

				$target_id = $created;
			}

			// Permission is checked on the TARGET post being written.
			if ( ! current_user_can( 'edit_post', $target_id ) ) {
				$report->add( XliffImportErrors::CANNOT_EDIT_TRANSLATION, $post_id, $unit_id, $target_id );
				continue;
			}

			switch ( $field ) {
				case 'title':
					// Not sanitize_text_field(): it deletes %XX sequences ("100%25 off" would become "100 off").
					$fields['post_title'] = \PerfLocale\Helper::sanitize_plain_text_field( $translated_text );
					break;

				case 'content':
					// WordPress's trust model: unfiltered_html users get a lossless round trip, everyone else the MT allowlist
					// (wp_update_post() applies kses by capability too).
					$fields['post_content'] = current_user_can( 'unfiltered_html' )
						? $translated_text
						: \PerfLocale\Translation\ProtectedUnits::sanitize_around( $translated_text, $post_id, [ \PerfLocale\MachineTranslation\TranslationService::class, 'sanitize_mt_html' ] );
					break;

				case 'excerpt':
					// As content: an excerpt can carry markup (WooCommerce's `<p>` short description).
					$fields['post_excerpt'] = current_user_can( 'unfiltered_html' )
						? $translated_text
						: \PerfLocale\MachineTranslation\TranslationService::sanitize_mt_html( $translated_text );
					break;
			}

			$held[ $unit_id ] = $post_id;
		}

		if ( [] === $held ) {
			return [ 0, $skipped ];
		}

		if ( ! $skip_same || ! self::holds( $target_id, $fields ) ) {
			// One wp_slash() before one wp_update_post() (textContent is unslashed and wp_update_post() unslashes). An empty
			// page_template keeps the stored one: a template the theme lacks would stop the save before its hooks.
			$postarr                  = $fields;
			$postarr['ID']            = $target_id;
			$postarr['page_template'] = '';

			$result = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $result ) ) {
				// Logged whatever WP_DEBUG says: the reported error points here.
				error_log( sprintf( 'PerfLocale XLIFF import: failed to update post %d (%s): %s', $target_id, implode( ', ', array_keys( $held ) ), $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				// Every held unit is reported, under the post its id names.
				foreach ( $held as $held_unit_id => $held_source_id ) {
					$report->add( XliffImportErrors::SAVE_FAILED, $held_source_id, (string) $held_unit_id );
				}

				return [ 0, $skipped ];
			}
		}

		if ( isset( $fields['post_content'] ) && \PerfLocale\Translation\BlockTemplateSupport::is_template_type( (string) get_post_type( $target_id ) ) ) {
			\PerfLocale\Translation\TemplateBlockRefs::content_replaced( $target_id );
		}

		return [ count( $held ), $skipped ];
	}

	/**
	 * Create the translation with CREATING_META_KEY on the new post (written with the insert, sealed when the insert
	 * ends) until create_translation() returns.
	 *
	 * @param \PerfLocale\Translation\PostTranslationManager $manager     Manager.
	 * @param int                                            $source_id   Source post.
	 * @param string                                         $target_slug Target language slug.
	 * @return int|false As create_translation().
	 */
	private static function create_marked( \PerfLocale\Translation\PostTranslationManager $manager, int $source_id, string $target_slug ): int|false {
		$mark = $source_id . ':' . $target_slug . ':' . time();
		$held = $mark;
		$add  = static function ( $data, $for_source = 0, $for_slug = '' ) use ( $source_id, $target_slug, $mark ) {
			if ( is_array( $data ) && $for_source === $source_id && $for_slug === $target_slug ) {
				$data['meta_input']                            = is_array( $data['meta_input'] ?? null ) ? $data['meta_input'] : [];
				$data['meta_input'][ self::CREATING_META_KEY ] = $mark;
			}

			return $data;
		};

		// The row as the insert left it: recovery completes only a linked row still equal to it.
		$seal = static function ( $post_id, $post = null, $update = false ) use ( $mark, &$held ): void {
			$id = is_numeric( $post_id ) ? (int) $post_id : 0;

			if ( $update || $held !== $mark || $id <= 0 || get_post_meta( $id, self::CREATING_META_KEY, true ) !== $mark ) {
				return;
			}

			$row = self::stored_row( $id );

			if ( null !== $row && update_post_meta( $id, self::CREATING_META_KEY, $mark . ':' . self::fingerprint( $row ), $mark ) ) {
				$held = $mark . ':' . self::fingerprint( $row );
			}
		};

		add_filter( 'perflocale/translation/post_data', $add, PHP_INT_MAX, 3 );
		add_action( 'wp_after_insert_post', $seal, PHP_INT_MAX, 3 );

		try {
			$created = $manager->create_translation( $source_id, $target_slug, true );
		} finally {
			remove_filter( 'perflocale/translation/post_data', $add, PHP_INT_MAX );
			remove_action( 'wp_after_insert_post', $seal, PHP_INT_MAX );
		}

		if ( false !== $created ) {
			delete_post_meta( $created, self::CREATING_META_KEY, $held );
		}

		return $created;
	}

	/**
	 * A creation of this source's translation a stopped worker left (CREATING_META_KEY older than a lease): linked, its
	 * copy steps run again, only on a row equal to the sealed one and with the rights the creation needed; otherwise it is
	 * kept (and the mark too when a right is missing) and reported. An unlinked draft is always kept, and reported to a
	 * user who may edit the source.
	 *
	 * @param \PerfLocale\Translation\PostTranslationManager $manager     Manager.
	 * @param int                                            $source_id   Source post.
	 * @param string                                         $target_slug Target language slug.
	 * @param array<int, \DOMElement>                        $units       The post's units, named in a report.
	 * @param XliffImportErrors                              $report      Collects what was kept.
	 * @return bool False when the post's units must not be applied: its translation was edited since.
	 */
	private static function recover_creation( \PerfLocale\Translation\PostTranslationManager $manager, int $source_id, string $target_slug, array $units, XliffImportErrors $report ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed read, on the first post of a resumed import only.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT m.post_id, m.meta_value, p.post_status FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value LIKE %s', $wpdb->postmeta, $wpdb->posts, self::CREATING_META_KEY, $wpdb->esc_like( $source_id . ':' . $target_slug . ':' ) . '%' ), ARRAY_A );
		$prefix = $source_id . ':' . $target_slug . ':';
		$apply  = true;
		$note   = static function ( string $cause, int $id ) use ( $report, $units, $source_id ): void {
			foreach ( $units as $unit ) {
				$report->add( $cause, $source_id, $unit->getAttribute( 'id' ), $id );
			}
		};

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id   = is_numeric( $row['post_id'] ?? null ) ? (int) $row['post_id'] : 0;
			$mark = is_string( $row['meta_value'] ?? null ) ? $row['meta_value'] : '';
			$seal = explode( ':', substr( $mark, strlen( $prefix ) ), 2 );
			$time = (int) $seal[0];

			// Younger than a lease: a live import's.
			if ( $id <= 0 || $time <= 0 || time() - $time < \PerfLocale\Background\JobResume::LEASE ) {
				continue;
			}

			if ( (int) $manager->get_translation_id( $source_id, $target_slug ) === $id ) {
				if ( ! self::may_finish( $manager, $source_id, $id ) ) {
					$note( XliffImportErrors::RECOVERY_CANNOT_FINISH, $id );
					continue;
				}

				if ( self::unchanged( $id, $seal[1] ?? '' ) ) {
					self::complete_creation( $manager, $id, $source_id, $target_slug );
				} else {
					$note( XliffImportErrors::RECOVERY_EDITED_TRANSLATION, $id );
					$apply = false;
				}
			} elseif ( null === $manager->detect_post_language( $id ) && in_array( $row['post_status'] ?? '', [ 'draft', 'pending' ], true ) ) {
				// apply_post() reports the source as CANNOT_EDIT.
				if ( ! current_user_can( 'edit_post', $source_id ) ) {
					continue;
				}

				// Never trashed: cached link reads and a posts-row seal cannot prove nobody linked or edited it since.
				$note( XliffImportErrors::RECOVERY_KEPT_DRAFT, $id );
			}

			if ( current_user_can( 'edit_post', $id ) ) {
				delete_post_meta( $id, self::CREATING_META_KEY, $mark );
			}
		}

		return $apply;
	}

	/**
	 * Whether the current user may finish a stopped creation: the rights apply_post() checks before creating it.
	 *
	 * @param \PerfLocale\Translation\PostTranslationManager $manager   Manager.
	 * @param int                                            $source_id Source post the unit names.
	 * @param int                                            $id        The translation.
	 * @return bool
	 */
	private static function may_finish( \PerfLocale\Translation\PostTranslationManager $manager, int $source_id, int $id ): bool {
		if ( ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}

		// The post the copy steps read: as PostTranslationWrites::resolve_source_post(), the group's default-language one.
		$repo      = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$default   = method_exists( $repo, 'get_default' ) ? $repo->get_default() : null;
		$slug      = is_object( $default ) && is_string( $default->slug ?? null ) ? $default->slug : '';
		$copy_from = '' !== $slug ? (int) ( $manager->get_translations( $source_id )[ $slug ] ?? 0 ) : 0;
		$copy_from = $copy_from > 0 && get_post( $copy_from ) instanceof \WP_Post ? $copy_from : $source_id;

		return ( $copy_from === $source_id || \PerfLocale\Helper::user_can_copy_translation_source( $copy_from ) )
			&& \PerfLocale\Helper::user_can_create_like( $copy_from );
	}

	/**
	 * Whether the stored row still equals the one the seal fingerprints; no seal proves nothing.
	 *
	 * @param int    $id   Post ID.
	 * @param string $seal Fingerprint from the mark, or ''.
	 * @return bool
	 */
	private static function unchanged( int $id, string $seal ): bool {
		$row = '' !== $seal ? self::stored_row( $id ) : null;

		return null !== $row && hash_equals( $seal, self::fingerprint( $row ) );
	}

	/**
	 * A post's whole row as the database holds it now, every value a string; null when absent or the read failed.
	 *
	 * @param int $id Post ID.
	 * @return array<string, string>|null
	 */
	private static function stored_row( int $id ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row: another request may have saved it.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $id ), ARRAY_A );

		if ( ! is_array( $row ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( $row as $column => $value ) {
			$out[ (string) $column ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $out;
	}

	/**
	 * A row's fingerprint.
	 *
	 * @param array<string, string> $row Row from stored_row().
	 * @return string
	 */
	private static function fingerprint( array $row ): string {
		return md5( (string) wp_json_encode( $row ) );
	}

	/**
	 * Run a stopped creation's copy steps again; a key the translation holds gets further rows only after its held rows
	 * matched the original's in order.
	 *
	 * @param \PerfLocale\Translation\PostTranslationManager $manager     Manager.
	 * @param int                                            $id          Translation post.
	 * @param int                                            $source_id   Source post.
	 * @param string                                         $target_slug Target language slug.
	 * @return void
	 */
	private static function complete_creation( \PerfLocale\Translation\PostTranslationManager $manager, int $id, int $source_id, string $target_slug ): void {
		$held = [];

		// Without a key get_post_meta() answers the stored strings, as maybe_serialize() writes them.
		foreach ( (array) get_post_meta( $id ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				$held[ (string) $key ][] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		$kept = [];

		// As TranslationService::complete_creation(): held rows match the original's in order; at a mismatch the key is a person's.
		$skip = static function ( $check, $object_id, $meta_key, $meta_value = null ) use ( $id, &$held, &$kept ) {
			if ( ! is_numeric( $object_id ) || (int) $object_id !== $id || ! is_string( $meta_key ) || ! isset( $held[ $meta_key ] ) ) {
				return $check;
			}

			if ( isset( $kept[ $meta_key ] ) ) {
				return false;
			}

			if ( [] === $held[ $meta_key ] ) {
				return $check;
			}

			$stored = maybe_serialize( $meta_value );

			if ( ( is_scalar( $stored ) ? (string) $stored : '' ) === reset( $held[ $meta_key ] ) ) {
				array_shift( $held[ $meta_key ] );
			} else {
				$kept[ $meta_key ] = true;
			}

			return false;
		};

		add_filter( 'add_post_metadata', $skip, 1, 4 );

		try {
			$manager->complete_imported_translation( $id, $source_id, $target_slug );
		} finally {
			remove_filter( 'add_post_metadata', $skip, 1 );
		}
	}

	/**
	 * Whether the post already holds every value.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $fields  Field => value.
	 * @return bool
	 */
	private static function holds( int $post_id, array $fields ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$stored = [
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
		];

		foreach ( $fields as $key => $value ) {
			if ( ( $stored[ $key ] ?? null ) !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Refuse a document over MAX_BYTES or MAX_UNITS units without building a DOM: substr_count( "<unit" ) is an upper
	 * bound, and only a file over it pays for the exact count.
	 *
	 * @param string $xml XLIFF XML string.
	 * @throws XliffFormatException When a limit is exceeded.
	 */
	private function check_limits( string $xml ): void {
		if ( strlen( $xml ) > self::MAX_BYTES ) {
			throw self::limit_refusal( 'bytes' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
		}

		// Before the unit count: its XMLReader pass parses too.
		if ( self::over_markup( $xml ) ) {
			throw self::limit_refusal( 'nodes' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
		}

		if ( substr_count( $xml, '<unit' ) <= self::MAX_UNITS ) {
			return;
		}

		if ( $this->count_units( $xml, self::MAX_UNITS + 1 ) > self::MAX_UNITS ) {
			throw self::limit_refusal( 'units' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped in limit_refusal().
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
	 * @param 'bytes'|'units'|'nodes' $limit Which limit was exceeded.
	 */
	private static function limit_refusal( string $limit ): XliffFormatException {
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
				number_format_i18n( self::MAX_UNITS )
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
