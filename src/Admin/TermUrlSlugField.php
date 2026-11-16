<?php
/**
 * A term's URL slug in its language: edit screen field, REST field and the shared write.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Repository\SlugTranslationWrites;
use PerfLocale\Plugin;
use PerfLocale\Translation\TermSlugFollow;
use PerfLocale\Translation\TermTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: loaded by the term edit form, its save, the REST field callbacks and `wp perflocale term url-slug`.
 *
 * @since 1.0.8
 */
final class TermUrlSlugField {

	/**
	 * Form and REST field name.
	 */
	public const FIELD = 'perflocale_url_slug';

	/**
	 * Nonce action and field of the edit form row.
	 */
	private const NONCE = 'perflocale_url_slug';

	/**
	 * Edit form row "URL slug in <language>" (translated slugs on, the term has a language).
	 *
	 * @param \WP_Term $term     Term being edited.
	 * @param string   $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function render( \WP_Term $term, string $taxonomy ): void {
		unset( $taxonomy );
		$language = self::language( $term->term_id );

		if ( null === $language || ! Plugin::get_instance()->settings()->translate_slugs_enabled() ) {
			return;
		}

		$slug = Plugin::get_instance()->slug_manager()->get_slug( 'term', $term->term_id, $language['id'] );

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce', false );

		echo '<tr class="form-field term-perflocale-url-slug-wrap">';
		/* translators: %s: language name. */
		echo '<th scope="row"><label for="' . esc_attr( self::FIELD ) . '">' . esc_html( sprintf( __( 'URL slug in %s', 'perflocale' ), $language['name'] ) ) . '</label></th>';
		echo '<td><input name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" type="text" class="regular-text" value="' . esc_attr( urldecode( (string) $slug ) ) . '" placeholder="' . esc_attr( urldecode( $term->slug ) ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '_language" value="' . esc_attr( (string) $language['id'] ) . '">';
		echo '<p class="description">' . esc_html__( 'The last part of this term\'s address in this language. When you change it, the old address redirects to the new one.', 'perflocale' ) . '</p></td>';
		echo '</tr>';
	}

	/**
	 * Save the edit form row (edited_{taxonomy}, after the term's language); an empty field leaves the slug as it is.
	 *
	 * @param mixed $term_id Term ID (core passes an int).
	 * @return void
	 */
	public static function save( $term_id ): void {
		$term_id = is_numeric( $term_id ) ? (int) $term_id : 0;

		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] ) && is_string( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_key( $_POST[ self::NONCE . '_nonce' ] ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// The form's value is for its own term (tag_ID), not a sibling saved in this request.
		$form_term = isset( $_POST['tag_ID'] ) && is_scalar( $_POST['tag_ID'] ) ? absint( $_POST['tag_ID'] ) : 0;

		if ( $form_term !== $term_id ) {
			return;
		}

		// Unslashed only: sanitize_title() in the write keeps %XX octets that sanitize_text_field() would strip.
		$value = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? trim( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_title() in SlugTranslationWrites.
		$lang  = self::language( $term_id );
		$shown = isset( $_POST[ self::FIELD . '_language' ] ) && is_scalar( $_POST[ self::FIELD . '_language' ] ) ? absint( $_POST[ self::FIELD . '_language' ] ) : 0;

		// A changed language (saved before this): the row showed the old language's slug, which is not this one's.
		if ( '' === $value || null === $lang || $shown !== $lang['id'] || sanitize_title( $value ) === Plugin::get_instance()->slug_manager()->get_slug( 'term', $term_id, $lang['id'] ) ) {
			return;
		}

		$result  = self::write( $term_id, $value );
		$user_id = get_current_user_id();

		// MetaBox::maybe_show_language_save_error() shows it on the next term screen.
		if ( ! $result['ok'] && $user_id > 0 ) {
			set_transient( 'perflocale_lang_save_error_' . $user_id, $result['message'], 60 );
		}
	}

	/**
	 * Set a term's URL slug in its own language (field, REST, CLI): strict, under the import lock, 3 tries.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $slug    URL slug.
	 * @return array{ok: bool, message: string, notes: array<int, string>, slug: string, error: string}
	 */
	public static function write( int $term_id, string $slug ): array {
		$term = get_term( $term_id );
		$fail = static fn( string $error, string $message ): array => [
			'ok'      => false,
			'message' => $message,
			'notes'   => [],
			'slug'    => $slug,
			'error'   => $error,
		];

		if ( ! $term instanceof \WP_Term ) {
			/* translators: %d: term ID. */
			return $fail( 'no_term', sprintf( __( 'Term %d does not exist.', 'perflocale' ), $term_id ) );
		}

		$lang = self::language( $term_id );

		if ( null === $lang || ! in_array( $term->taxonomy, Plugin::get_instance()->settings()->get_translatable_taxonomies(), true ) ) {
			/* translators: %s: term name. */
			return $fail( 'no_language', sprintf( __( 'The term "%s" has no language, or its taxonomy is not translated: set its language first.', 'perflocale' ), $term->name ) );
		}

		$r = SlugTranslationWrites::write_term_url_slug( $term->taxonomy, $term_id, $lang['id'], $slug, false, 3, 200 );

		if ( $r['ok'] ) {
			return [
				'ok'      => true,
				/* translators: 1: term name, 2: language name, 3: URL slug. */
				'message' => sprintf( __( 'The URL slug of "%1$s" in %2$s is "%3$s".', 'perflocale' ), $term->name, $lang['name'], urldecode( $r['slug'] ) ),
				'notes'   => $r['notes'],
				'slug'    => $r['slug'],
				'error'   => '',
			];
		}

		$other = get_term( (int) $r['conflict_term_id'] );

		return $fail(
			$r['error'],
			match ( $r['error'] ) {
				'empty'          => __( 'The URL slug is empty once the characters a URL slug cannot hold are removed.', 'perflocale' ),
				'bad_characters' => __( 'The URL slug holds an encoded slash, backslash or control character, or is a dot segment. Remove it.', 'perflocale' ),
				/* translators: 1: URL slug, 2: other term's name, 3: its ID. */
				'conflict'       => sprintf( __( 'The URL slug "%1$s" is already used in this language by "%2$s" (ID %3$d). Choose another one.', 'perflocale' ), urldecode( $r['slug'] ), $other instanceof \WP_Term ? $other->name : '', (int) $r['conflict_term_id'] ),
				/* translators: %s: term name. */
				'busy'           => sprintf( __( 'The URL slug of "%s" was not saved: an import or another change to URL slugs is running. Save again in a few seconds.', 'perflocale' ), $term->name ),
				/* translators: %s: term name. */
				default          => sprintf( __( 'The URL slug of "%s" was not saved because of a database error. Try again.', 'perflocale' ), $term->name ),
			}
		);
	}

	/**
	 * REST get_callback (context edit): the term's URL slug in its language, or null.
	 *
	 * @param array<string, mixed> $prepared Prepared response data.
	 * @return string|null
	 */
	public static function rest_get( array $prepared ): ?string {
		$term_id = isset( $prepared['id'] ) && is_numeric( $prepared['id'] ) ? (int) $prepared['id'] : 0;
		$lang    = $term_id > 0 ? self::language( $term_id ) : null;

		return null === $lang ? null : Plugin::get_instance()->slug_manager()->get_slug( 'term', $term_id, $lang['id'] );
	}

	/**
	 * REST update_callback: same permission as editing the term.
	 *
	 * @param mixed $value New URL slug.
	 * @param mixed $term  The term being created or updated.
	 * @return true|\WP_Error
	 */
	public static function rest_update( $value, $term ) {
		if ( ! $term instanceof \WP_Term || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return new \WP_Error( 'rest_cannot_update', __( 'Sorry, you are not allowed to edit this term.', 'perflocale' ), [ 'status' => rest_authorization_required_code() ] );
		}

		$value = is_string( $value ) ? $value : '';

		// A rename in this request waits for this field: the value shown before lets it follow, another value wins.
		if ( class_exists( TermSlugFollow::class, false ) && TermSlugFollow::settle_rest( $term->term_id, $value ) ) {
			return true;
		}

		$r = self::write( $term->term_id, $value );

		if ( $r['ok'] ) {
			return true;
		}

		$status = [
			'conflict' => 409,
			'busy'     => 503,
			'db_error' => 500,
		][ $r['error'] ] ?? 400;

		return new \WP_Error( 'perflocale_url_slug_' . $r['error'], $r['message'], [ 'status' => $status ] );
	}

	/**
	 * The term's language: id and display name, or null.
	 *
	 * @param int $term_id Term ID.
	 * @return array{id: int, name: string}|null
	 */
	private static function language( int $term_id ): ?array {
		$lang = ( new TermTranslationManager( Plugin::get_instance()->cache() ) )->detect_term_language( $term_id );

		if ( null === $lang || ! isset( $lang->id ) || ! is_numeric( $lang->id ) ) {
			return null;
		}

		$name = $lang->native_name ?? $lang->name ?? '';

		return [
			'id'   => (int) $lang->id,
			'name' => is_string( $name ) && '' !== $name ? $name : ( is_string( $lang->slug ?? null ) ? $lang->slug : '' ),
		];
	}
}
