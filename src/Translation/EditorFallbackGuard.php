<?php
/**
 * Page-builder links that would open a post of another language.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops a builder link before it opens, and saves into, a post of another language.
 * Loaded only when a logged-in request with query arguments reaches a language fallback.
 */
final class EditorFallbackGuard {

	/** Query arguments of builders that edit the post the main query loads. */
	private const ARGS = [ 'ct_builder', 'oxygen_iframe', 'bricks', 'fl_builder', 'fl_builder_ui', 'is-editor-iframe', 'et_fb', 'etch', 'elementor-preview', 'breakdance_iframe', 'vc_editable', 'vcv-editable' ];

	/**
	 * Ends the request with a 409 when it carries a builder argument and the user may edit posts of this type.
	 *
	 * @param int                $post_id       The post of another language about to load.
	 * @param callable(): string $language_name Returns that post's language name; called only when refusing.
	 * @return void
	 */
	public static function check( int $post_id, callable $language_name ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names only, nothing is read or stored.
		if ( self::matched_arg( array_keys( $_GET ) ) === '' ) {
			return;
		}

		$type = get_post_type_object( (string) get_post_type( $post_id ) );
		$cap  = $type !== null ? ( $type->cap->edit_posts ?? null ) : null;

		if ( ! is_string( $cap ) || ! current_user_can( $cap ) ) {
			return;
		}

		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: language name, for example English. */
					__( 'This translation is not published, so this link would open the %s page in the builder. Nothing was opened. Open the translation from its edit screen.', 'perflocale' ),
					$language_name()
				)
			),
			'',
			[ 'response' => 409 ]
		);
	}

	/**
	 * The first builder argument among the request's argument names, or ''.
	 *
	 * @param array<int|string, mixed> $names Request argument names.
	 * @return string
	 */
	public static function matched_arg( array $names ): string {
		/**
		 * Filters the query arguments that mark a page-builder request. When a logged-in user who may edit
		 * posts of that type opens such a link and PerfLocale would load a post of another language instead
		 * of the translation, the request stops with a 409 and nothing is opened.
		 *
		 * @hook  perflocale/routing/editor_query_args
		 * @since 1.0.7.3.1
		 *
		 * @param string[] $args Argument names, for example ct_builder, bricks or fl_builder.
		 */
		$args = array_filter( (array) apply_filters( 'perflocale/routing/editor_query_args', self::ARGS ), 'is_string' );

		foreach ( $args as $arg ) {
			if ( $arg !== '' && in_array( $arg, $names, true ) ) {
				return $arg;
			}
		}

		return '';
	}

	/**
	 * The name of the language with this id, else the default language's name.
	 *
	 * @param array<int, object> $languages   Active language rows.
	 * @param int                $language_id Language id (0 for an unlinked post).
	 * @return string
	 */
	public static function language_name( array $languages, int $language_id ): string {
		$default = '';

		foreach ( $languages as $language ) {
			$id   = $language->id ?? null;
			$name = $language->name ?? null;

			if ( ! is_string( $name ) ) {
				continue;
			}

			if ( $language_id > 0 && is_numeric( $id ) && (int) $id === $language_id ) {
				return $name;
			}

			if ( $default === '' && ! empty( $language->is_default ) ) {
				$default = $name;
			}
		}

		return $default;
	}
}
