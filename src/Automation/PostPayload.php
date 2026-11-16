<?php
/**
 * Automation: the shape of a held post change.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Automation;

use PerfLocale\Translation\PostTranslationManager;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The payload of a held post change: title, content, excerpt, fields, its base and the hashes compared.
 * Approve validates the shape before any write and never defaults a field; an invalid payload is refused whole.
 *
 * @phpstan-type Payload array{post_title: string|null, post_content: string, post_excerpt: string|null, meta: array<string, string|null>, base_id: int, source_hash: string, base_fields_hash: string, parts: list<mixed>, parts_complete: bool, block_map: list<mixed>}
 */
final class PostPayload {

	/**
	 * Validate and normalise a decoded payload.
	 *
	 * @param array<mixed>       $payload      The decoded payload.
	 * @param array<int, string> $allowed_keys The meta keys the payload may name.
	 * @return array<string, mixed>|null Null when the shape is invalid.
	 * @phpstan-return Payload|null
	 */
	public static function validate( array $payload, array $allowed_keys ): ?array {
		$content = $payload['post_content'] ?? null;

		if ( ! is_string( $content ) ) {
			return null;
		}

		// A present field must be a string; an absent one stays absent.
		$title   = null;
		$excerpt = null;

		if ( array_key_exists( 'post_title', $payload ) ) {
			if ( ! is_string( $payload['post_title'] ) ) {
				return null;
			}

			$title = $payload['post_title'];
		}

		if ( array_key_exists( 'post_excerpt', $payload ) ) {
			if ( ! is_string( $payload['post_excerpt'] ) ) {
				return null;
			}

			$excerpt = $payload['post_excerpt'];
		}

		$meta = [];

		if ( array_key_exists( 'meta', $payload ) ) {
			if ( ! is_array( $payload['meta'] ) ) {
				return null;
			}

			$allowed = array_fill_keys( array_map( 'strval', $allowed_keys ), true );

			// PHP keeps a key spelled as digits ('123') as an int: it is checked by its string spelling.
			foreach ( $payload['meta'] as $key => $value ) {
				if ( ! isset( $allowed[ (string) $key ] ) || ( null !== $value && ! is_string( $value ) ) ) {
					return null;
				}

				$meta[ (string) $key ] = $value;
			}
		}

		$base_id     = $payload['base_id'] ?? null;
		$source_hash = $payload['source_hash'] ?? null;
		$fields_hash = $payload['base_fields_hash'] ?? null;

		if ( ! is_int( $base_id ) || $base_id < 0 || ! is_string( $source_hash ) || ! is_string( $fields_hash ) ) {
			return null;
		}

		foreach ( [ 'parts', 'block_map' ] as $list ) {
			if ( array_key_exists( $list, $payload ) && ! is_array( $payload[ $list ] ) ) {
				return null;
			}
		}

		return [
			'post_title'       => $title,
			'post_content'     => $content,
			'post_excerpt'     => $excerpt,
			'meta'             => $meta,
			'base_id'          => $base_id,
			'source_hash'      => $source_hash,
			'base_fields_hash' => $fields_hash,
			'parts'            => array_values( is_array( $payload['parts'] ?? null ) ? $payload['parts'] : [] ),
			'parts_complete'   => true === ( $payload['parts_complete'] ?? null ),
			'block_map'        => array_values( is_array( $payload['block_map'] ?? null ) ? $payload['block_map'] : [] ),
		];
	}

	/**
	 * Whether a held proposal carries source-language text: a summary count or part status says so, or a part
	 * echoes its source (labels never decide alone). Beyond its parts, a proposal counts unless the job marked it
	 * `parts_complete`; an import drops that marker. Review, approve() and apply() ask this same question.
	 *
	 * @param array<int, mixed> $parts          The payload's parts.
	 * @param array<mixed>      $summary        The change's summary.
	 * @param bool              $parts_complete The payload's `parts_complete` marker.
	 * @return bool
	 */
	public static function holds_source_text( array $parts, array $summary, bool $parts_complete = false ): bool {
		foreach ( [ 'left_source', 'same_as_source', 'source_echo' ] as $count ) {
			if ( Scalar::int( $summary[ $count ] ?? 0 ) > 0 ) {
				return true;
			}
		}

		$compared = false;

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$compared = true;

			if ( in_array( Scalar::str( $part['status'] ?? '' ), [ 'left_source', 'same_as_source' ], true ) ) {
				return true;
			}

			if ( self::echoes_source( $part ) ) {
				return true;
			}
		}

		if ( ! $compared && [] !== $parts ) {
			return true;
		}

		return ! $parts_complete;
	}

	/**
	 * Whether a part's proposed text equals its non-empty source byte for byte or as displayed (shown_text()),
	 * so an untranslated unit returned with `&#39;` still echoes. Case counts; a whitespace-only source
	 * counts only byte for byte.
	 *
	 * @param array<mixed> $part A part.
	 * @return bool
	 */
	public static function echoes_source( array $part ): bool {
		$source   = Scalar::str( $part['source'] ?? '' );
		$proposed = Scalar::str( $part['proposed'] ?? '' );

		if ( '' === $source ) {
			return false;
		}

		if ( $proposed === $source ) {
			return true;
		}

		$shown = self::shown_text( $source );

		return '' !== $shown && self::shown_text( $proposed ) === $shown;
	}

	/**
	 * A text as displayed: entities decoded, a no-break space as a space,
	 * each run of ASCII whitespace as one space, trimmed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function shown_text( string $text ): string {
		$text = str_replace( "\u{00A0}", ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return trim( (string) preg_replace( '/[\x09-\x0D\x20]+/', ' ', $text ) );
	}

	/**
	 * Whether a resync never writes a meta key: `_perflocale_*` and sensitive keys.
	 *
	 * @param string       $key      Meta key.
	 * @param array<mixed> $patterns The sensitive patterns (PostTranslationManager::sensitive_meta_patterns()).
	 * @return bool
	 */
	public static function excluded_key( string $key, array $patterns ): bool {
		return str_starts_with( $key, '_perflocale_' ) || PostTranslationManager::is_sensitive_meta_key( $key, $patterns );
	}

	/**
	 * A producer's key list minus the keys a resync never writes.
	 *
	 * @param array<int, string> $keys     Meta keys.
	 * @param array<mixed>       $patterns The sensitive patterns.
	 * @return list<string>
	 */
	public static function writable_keys( array $keys, array $patterns ): array {
		$out = [];

		foreach ( $keys as $key ) {
			if ( ! self::excluded_key( $key, $patterns ) ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * The meta keys a held change may name: the pair's machine translation registry minus seed-cleared,
	 * sensitive and `_perflocale_*` keys.
	 *
	 * @param \WP_Post $source         Source.
	 * @param int      $translation_id Translation id.
	 * @return list<string>
	 */
	public static function allowed_keys( \WP_Post $source, int $translation_id ): array {
		$patterns = PostTranslationManager::sensitive_meta_patterns();
		$cleared  = \PerfLocale\Translation\ContentSync::seed_cleared_keys( $translation_id );
		$out      = [];

		foreach ( \PerfLocale\Resync\SyncRecorder::field_keys( $source, $translation_id ) as $key ) {
			if ( isset( $cleared[ $key ] ) || self::excluded_key( $key, $patterns ) ) {
				continue;
			}

			$out[] = $key;
		}

		return $out;
	}

	/**
	 * The keys a stored change may write now: its summary's keys the pair still allows.
	 *
	 * @param \WP_Post           $source         Source.
	 * @param int                $translation_id Translation id.
	 * @param array<int, string> $fields         `summary.fields`.
	 * @return list<string>
	 */
	public static function approvable_keys( \WP_Post $source, int $translation_id, array $fields ): array {
		return array_values( array_intersect( self::allowed_keys( $source, $translation_id ), array_map( 'strval', $fields ) ) );
	}
}
