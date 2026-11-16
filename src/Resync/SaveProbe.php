<?php
/**
 * Content ReSync: the save-filter probe.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the acting user's save chain would store for a proposal: each field through sanitize_post_field( ..., 'db' )
 * as wp_insert_post() runs it, plus core's emoji step on utf8/utf8mb3. Without `unfiltered_html` a field is held only
 * when MarkupRemoval finds a removal; with it, any byte change holds. `wp_insert_post_data` is read back, not simulated.
 * A custom-field value that is not the source's own, a person's or already the translation's passes the MT allowlist first.
 *
 * @phpstan-type Stored array{fields: array<string, string>, meta: array<string, string|null>, held_fields: list<string>, held_meta: list<string>}
 */
final class SaveProbe {

	/** The fields the probe covers. */
	public const FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];

	/** A field policy: the acting user's HTML rules (the default). */
	public const FIELD_USER = 'user';

	/**
	 * What a ReSync write as the acting user stores and what of it is held for review: the one place ReSync applies the
	 * acting user's HTML rules, on every write path. Nobody logged in has no `unfiltered_html`, so the strict rules apply.
	 * Held under KSES rules only on a MarkupRemoval removal (cosmetic rewrites are stored); elsewhere on any byte change.
	 *
	 * @param array<string, mixed> $fields    post_title, post_content, post_excerpt (strings; others ignored).
	 * @param array<string, mixed> $meta      Custom-field key => value (string, or null for a deleted field).
	 * @param int                  $post_id   The post the write goes to.
	 * @param int                  $source_id The translation's source, for perflocale/mt/meta_key_format (0 when unknown).
	 * @param array<int, string>   $people    Keys whose value a person gave; any other value that is neither the source's
	 *                                        own nor the translation's stored one counts as machine output.
	 * @return array<string, mixed>
	 * @phpstan-return Stored
	 */
	public static function stored( array $fields, array $meta, int $post_id, int $source_id = 0, array $people = [] ): array {
		$trusted   = current_user_can( 'unfiltered_html' );
		$out       = [];
		$held_meta = [];
		$keys      = array_values( array_diff( array_map( 'strval', array_keys( $meta ) ), $people ) );
		$source    = $source_id > 0 && [] !== $keys ? \PerfLocale\Automation\PostTarget::field_values( $source_id, $keys ) : [];

		// Least trust: the source's own value from an author or last editor without unfiltered_html keeps only what the
		// MT allowlist keeps, and no machine value keeps its held markup.
		require_once __DIR__ . '/../Translation/SourceTrust.php';
		$least = [] !== $keys && $source_id > 0 && ! \PerfLocale\Translation\SourceTrust::trusted( $source_id );
		$live  = [] === $keys ? [] : self::live_values( $post_id, $keys );

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( ! is_string( $value ) ) {
				$out[ $key ] = null;
				continue;
			}

			$checked = in_array( $key, $keys, true );
			$machine = $checked && ( $live[ $key ] ?? null ) !== $value && ( $least || ( $source[ $key ] ?? null ) !== $value );

			[ $out[ $key ], $by_kses ] = self::field_value( $key, $value, $post_id, $trusted, $source_id, $machine ? ( $least ? '' : \PerfLocale\Util\Scalar::str( $source[ $key ] ?? '' ) ) : null );

			if ( $out[ $key ] !== $value && ( ! $by_kses || MarkupRemoval::found( $value, $out[ $key ] ) ) ) {
				$held_meta[] = $key;
			}
		}

		$probed      = [] === $fields ? [] : self::probe( $fields, $post_id );
		$held_fields = [];

		foreach ( self::FIELDS as $field ) {
			$given = $fields[ $field ] ?? null;

			if ( ! is_string( $given ) ) {
				continue;
			}

			$after = $probed[ $field ] ?? '';

			if ( $after !== $given && ( $trusted || MarkupRemoval::found( $given, $after ) ) ) {
				$held_fields[] = $field;
			}
		}

		return [
			'fields'      => $probed,
			'meta'        => $out,
			'held_fields' => $held_fields,
			'held_meta'   => $held_meta,
		];
	}

	/**
	 * The translation's stored values, read only when its writers and the acting user have `unfiltered_html`: a value an
	 * untrusted writer stored stays machine output (SourceTrust), so a rewrite never lifts it to the actor's rules.
	 *
	 * @param int                $post_id The translation.
	 * @param array<int, string> $keys    Custom-field keys.
	 * @return array<string, string|null>
	 */
	private static function live_values( int $post_id, array $keys ): array {
		return \PerfLocale\Translation\SourceTrust::trusted( $post_id ) ? \PerfLocale\Automation\PostTarget::field_values( $post_id, $keys ) : [];
	}

	/**
	 * The custom-field keys whose stored value would differ from the given
	 * one, byte for byte. A key the probe did not answer counts as changed.
	 *
	 * @param array<string, mixed>       $meta   The given values.
	 * @param array<string, string|null> $stored What the write stores (stored()['meta']).
	 * @return list<string>
	 */
	public static function meta_differs( array $meta, array $stored ): array {
		$out = [];

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $value ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * The given fields and custom-field values holding HTML the acting user could not save: what an undo would restore
	 * byte for byte. Empty with `unfiltered_html`; a lone `&` stored as `&amp;` (lone_amp()) does not count.
	 *
	 * @param array<string, mixed> $fields    post_title, post_content, post_excerpt (strings; others ignored).
	 * @param array<string, mixed> $meta      Custom-field key => value (strings; others ignored).
	 * @param int                  $post_id   The post.
	 * @param int                  $source_id The translation's source, for perflocale/mt/meta_key_format (0 when unknown).
	 * @return list<string> Field names and custom-field keys.
	 */
	public static function unsavable( array $fields, array $meta, int $post_id, int $source_id = 0 ): array {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return [];
		}

		$text = [];

		foreach ( self::FIELDS as $field ) {
			if ( is_string( $fields[ $field ] ?? null ) ) {
				$text[ $field ] = $fields[ $field ];
			}
		}

		$values = array_filter( $meta, 'is_string' );
		$stored = self::stored( $text, $values, $post_id, $source_id, array_map( 'strval', array_keys( $values ) ) );
		$out    = [];

		foreach ( $text as $field => $value ) {
			$after = $stored['fields'][ $field ] ?? null;

			if ( $after !== $value && $after !== self::lone_amp( $value ) ) {
				$out[] = $field;
			}
		}

		foreach ( self::meta_differs( $values, $stored['meta'] ) as $key ) {
			$out[] = $key;
		}

		return $out;
	}

	/**
	 * One custom-field value as a ReSync write stores it: the field's `perflocale/resync/field_html_policy` callable (a
	 * format step), then the MT allowlist for machine output (the source's own held elements kept; not for plain text
	 * under the strict rules), then the acting user's rules (plain_text() or as_user()).
	 *
	 * @param string      $key       Custom-field key.
	 * @param string      $value     The value.
	 * @param int         $post_id   The post.
	 * @param bool        $trusted   Whether the acting user has `unfiltered_html`.
	 * @param int         $source_id The translation's source (0 when unknown).
	 * @param string|null $machine   The source's current value when the value is machine output, else null.
	 * @return array{string, bool} The value to store, and whether only the user's HTML rules (KSES) changed it.
	 */
	private static function field_value( string $key, string $value, int $post_id, bool $trusted, int $source_id, ?string $machine ): array {
		/**
		 * Filters how ReSync stores one custom-field value it writes: 'user' (the acting user's HTML rules), or a callable(
		 * $value, $key, $post_id ) that reformats it. Its string result still passes the MT allowlist (machine output) and
		 * the acting user's rules, so it never allows more HTML. Anything else, or a callable that throws or returns no
		 * string, gives the default; a value the policy changes is held for review. A field that stores code takes the
		 * 'copy' format of perflocale/mt/meta_key_format.
		 *
		 * @internal Not public: takes a callable on the HTML rules path.
		 * @hook  perflocale/resync/field_html_policy
		 * @since 1.0.8
		 *
		 * @param string|callable $policy  'user' (default) or a callable( string $value, string $key, int $post_id ): string.
		 * @param string          $key     The custom-field key.
		 * @param int             $post_id The post the value is written to.
		 */
		/**
		 * A filter may return anything; only a callable other than 'user' is taken.
		 *
		 * @var mixed $policy
		 */
		$policy = apply_filters( 'perflocale/resync/field_html_policy', self::FIELD_USER, $key, $post_id );
		$given  = $value;

		if ( self::FIELD_USER !== $policy && is_callable( $policy ) ) {
			try {
				$clean = $policy( $value, $key, $post_id );
			} catch ( \Throwable $e ) {
				$clean = null;
			}

			$value = is_string( $clean ) ? $clean : $value;
		}

		if ( $trusted ) {
			return [ null === $machine ? $value : \PerfLocale\MachineTranslation\TranslationService::sanitize_mt_value_kept( $value, $machine ), false ];
		}

		/** This filter is documented in src/MachineTranslation/MetaTranslator.php */
		$format = apply_filters( 'perflocale/mt/meta_key_format', 'html', $key, (string) get_post_type( $post_id ), $source_id );

		// Plain text keeps no tag, so the HTML allowlist would only escape a bare `<` first.
		if ( 'text' === $format ) {
			return [ self::plain_text( $value ), false ];
		}

		$user = self::as_user( $value );

		if ( null === $machine ) {
			return [ $user, $value === $given ];
		}

		// Machine output follows DQ-MS-1 only when it equals the user's rules on the same value: KSES is not idempotent
		// (a comment it escapes twice shows its text), so any other difference holds.
		$out = self::as_user( \PerfLocale\MachineTranslation\TranslationService::sanitize_mt_value_kept( $value, $machine ) );

		return [ $out, $value === $given && $out === $user ];
	}

	/**
	 * An HTML custom-field value as the acting user saves it: as given with `unfiltered_html`, else through KSES (a lone
	 * `&` written as `&amp;` is not HTML the user could not save: kept as given).
	 *
	 * @param string $value The value.
	 * @return string
	 */
	public static function as_user( string $value ): string {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $value;
		}

		$kept = wp_kses_post( $value );

		return $kept === self::lone_amp( $value ) ? $value : $kept;
	}

	/**
	 * A plain-text value as a user without `unfiltered_html` stores it: what sanitize_textarea_field() keeps, except that
	 * escaping only `<` characters that cannot open a tag ("5 < 10", "I <3 it") keeps the value as given, so it is not held.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function plain_text( string $value ): string {
		$kept = sanitize_textarea_field( $value );

		if ( $kept === $value ) {
			return $value;
		}

		if ( 0 === preg_match( '/<(?:[A-Za-z\/!?]|$)/', $value ) && htmlspecialchars_decode( $kept, ENT_QUOTES ) === $value ) {
			return $value;
		}

		return $kept;
	}

	/**
	 * A text with each `&` that cannot begin a character reference written as `&amp;`; a browser reads both the same,
	 * while every other KSES change, a re-spelled reference included, is a change.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function lone_amp( string $value ): string {
		return (string) preg_replace( '/&(?![A-Za-z0-9#])/', '&amp;', $value );
	}

	/**
	 * The text fields a save of a validated payload runs through the chain: the proposal's, plus the stored title or
	 * excerpt where it has none, because `wp_update_post()` merges the stored row and re-filters all of it.
	 *
	 * @param array<string, mixed> $clean        The validated payload (post_title and post_excerpt null when absent).
	 * @param string               $live_title   The stored title.
	 * @param string               $live_excerpt The stored excerpt.
	 * @return array<string, string>
	 */
	public static function fields_of( array $clean, string $live_title, string $live_excerpt ): array {
		$title   = $clean['post_title'] ?? null;
		$excerpt = $clean['post_excerpt'] ?? null;
		$content = $clean['post_content'] ?? '';

		return [
			'post_content' => is_string( $content ) ? $content : '',
			'post_title'   => is_string( $title ) ? $title : $live_title,
			'post_excerpt' => is_string( $excerpt ) ? $excerpt : $live_excerpt,
		];
	}

	/**
	 * Run the acting user's save chain over the given fields.
	 *
	 * @param array<string, mixed> $fields  post_title, post_content, post_excerpt (strings; others ignored).
	 * @param int                  $post_id The post the fields would be written to.
	 * @return array<string, string> The value each field would be stored as.
	 */
	public static function probe( array $fields, int $post_id ): array {
		global $wpdb;

		$out = [];
		$db  = $wpdb instanceof \wpdb ? $wpdb : null;

		foreach ( self::FIELDS as $field ) {
			if ( ! is_string( $fields[ $field ] ?? null ) ) {
				continue;
			}

			$value = sanitize_post_field( $field, wp_slash( $fields[ $field ] ), $post_id, 'db' );
			$value = is_string( $value ) ? wp_unslash( $value ) : '';

			$charset = null === $db ? '' : $db->get_col_charset( $db->posts, $field );

			if ( 'utf8' === $charset || 'utf8mb3' === $charset ) {
				$value = wp_encode_emoji( $value );
			}

			$out[ $field ] = $value;
		}

		return $out;
	}

	/**
	 * The names of the tags a save would remove: every tag whose number of
	 * opening occurrences is lower afterwards, lowercase, once each, sorted.
	 *
	 * @param string $before The proposal.
	 * @param string $after  What the save would store.
	 * @return list<string>
	 */
	public static function removed_tags( string $before, string $after ): array {
		$count = static function ( string $html ): array {
			$names = [];

			if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)[\s\/>]/', $html, $m ) ) {
				foreach ( $m[1] as $name ) {
					$name           = strtolower( $name );
					$names[ $name ] = ( $names[ $name ] ?? 0 ) + 1;
				}
			}

			return $names;
		};

		$was = $count( $before );
		$is  = $count( $after );
		$out = [];

		foreach ( $was as $name => $n ) {
			if ( $n > ( $is[ $name ] ?? 0 ) ) {
				$out[] = $name;
			}
		}

		sort( $out );

		return $out;
	}
}
