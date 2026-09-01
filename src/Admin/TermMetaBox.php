<?php
/**
 * Translation fields for taxonomy term edit screens.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Settings;
use PerfLocale\Translation\TermTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds language selector and translation panel to taxonomy term screens.
 *
 * On the "Add New" form: shows a language dropdown.
 * On the "Edit" form: shows translation rows with status and create/edit links.
 */
final class TermMetaBox {

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param CacheManager $cache    Cache manager.
	 */
	public function __construct( Settings $settings, CacheManager $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	/**
	 * Register hooks for all translatable taxonomies.
	 *
	 * Deferred to admin_init so all addons (including WooCommerce) have booted
	 * and their pa_* taxonomies appear in the translatable list.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_taxonomy_hooks' ] );
	}

	/**
	 * Register per-taxonomy form hooks.
	 *
	 * @return void
	 */
	public function register_taxonomy_hooks(): void {
		$taxonomies = $this->settings->get_translatable_taxonomies();

		foreach ( $taxonomies as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", [ $this, 'render_add_fields' ] );
			add_action( "{$taxonomy}_edit_form_fields", [ $this, 'render_edit_fields' ], 10, 2 );
			add_action( "created_{$taxonomy}", [ $this, 'save_term_language' ] );
			add_action( "edited_{$taxonomy}", [ $this, 'save_term_language' ] );
		}
	}

	/**
	 * Render language field on the "Add New Term" form.
	 *
	 * @param string $taxonomy Taxonomy slug — required by the WP `{$taxonomy}_add_form_fields` hook signature; the rendered field is taxonomy-agnostic.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP {$taxonomy}_add_form_fields hook signature; the rendered language field is taxonomy-agnostic.
	public function render_add_fields( string $taxonomy ): void {
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$languages = $lang_repo->get_active();
		$default   = $lang_repo->get_default();

		wp_nonce_field( 'perflocale_term_lang', 'perflocale_term_nonce' );

		echo '<div class="form-field">';
		echo '<label for="perflocale_term_language">' . esc_html__( 'Language', 'perflocale' ) . '</label>';
		echo '<select id="perflocale_term_language" name="perflocale_term_language">';

		$option_allowed = [
			'option' => [
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			],
		];
		foreach ( $languages as $lang ) {
			echo wp_kses(
				'<option value="' . esc_attr( $lang->slug ) . '"' . selected( $default && $default->slug === $lang->slug, true, false ) . '>',
				$option_allowed
			);
			echo esc_html( ( $lang->native_name ?? $lang->name ?? '' ) ?: ( $lang->name ?? '' ) );
			echo '</option>';
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The language for this term.', 'perflocale' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render translation panel on the "Edit Term" form.
	 *
	 * @param \WP_Term $term     Term being edited.
	 * @param string   $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function render_edit_fields( \WP_Term $term, string $taxonomy ): void {
		$lang_repo    = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$manager      = new TermTranslationManager( $this->cache );
		$languages    = $lang_repo->get_active();
		$translations = $manager->get_translations( $term->term_id );
		$term_lang    = $manager->detect_term_language( $term->term_id );

		wp_nonce_field( 'perflocale_term_lang', 'perflocale_term_nonce' );

		// Translation rows.
		echo '<tr class="form-field">';
		echo '<th scope="row">' . esc_html__( 'Translations', 'perflocale' ) . '</th>';
		echo '<td>';
		echo '<div class="perflocale-term-translations">';

		foreach ( $languages as $lang ) {
			$has_translation = isset( $translations[ $lang->slug ] );
			$translated_id   = $translations[ $lang->slug ] ?? null;

			// Skip deleted terms.
			if ( $has_translation && $translated_id && ! get_term( $translated_id ) ) {
				$has_translation = false;
				$translated_id   = null;
			}

			$is_current = ( $term_lang && $term_lang->slug === $lang->slug );

			echo '<div class="perflocale-mb__row' . ( $is_current ? ' perflocale-mb__row--active' : '' ) . '">';

			// Left: badge + name.
			echo '<div class="perflocale-mb__lang">';
			echo '<span class="perflocale-mb__badge">' . esc_html( \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ) ) . '</span>';
			echo '<span class="perflocale-mb__name">' . esc_html( ( $lang->native_name ?? $lang->name ?? '' ) ?: ( $lang->name ?? '' ) ) . '</span>';
			echo '</div>';

			// Right: action.
			echo '<div class="perflocale-mb__action">';

			if ( $is_current ) {
				echo '<span class="perflocale-mb__status perflocale-mb__status--green">';
				echo esc_html__( 'Current', 'perflocale' );
				echo '</span>';
			} elseif ( $has_translation && $translated_id ) {
				$edit_url = get_edit_term_link( $translated_id, $taxonomy );

				if ( $edit_url ) {
					echo '<a href="' . esc_url( $edit_url ) . '" class="perflocale-mb__status perflocale-mb__status--green">';
					echo esc_html__( 'Edit', 'perflocale' );
					echo '</a>';
				}
			} else {
				$create_url = add_query_arg(
					[
						'action'      => 'perflocale_create_term_translation',
						'source_id'   => $term->term_id,
						'taxonomy'    => $taxonomy,
						'target_lang' => $lang->slug,
						'_wpnonce'    => wp_create_nonce( 'perflocale_create_term_' . $term->term_id ),
					],
					admin_url( 'admin-post.php' )
				);

				echo '<a href="' . esc_url( $create_url ) . '" class="perflocale-mb__create">';
				echo '+ ' . esc_html__( 'Create', 'perflocale' );
				echo '</a>';
			}

			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
		echo '</td>';
		echo '</tr>';

		// Language selector.
		echo '<tr class="form-field">';
		echo '<th scope="row"><label for="perflocale_term_language">' . esc_html__( 'Language', 'perflocale' ) . '</label></th>';
		echo '<td>';
		echo '<select id="perflocale_term_language" name="perflocale_term_language">';

		$option_allowed = [
			'option' => [
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			],
		];
		foreach ( $languages as $lang ) {
			echo wp_kses(
				'<option value="' . esc_attr( $lang->slug ) . '"' . selected( $term_lang && $term_lang->slug === $lang->slug, true, false ) . '>',
				$option_allowed
			);
			echo esc_html( ( $lang->native_name ?? $lang->name ?? '' ) ?: ( $lang->name ?? '' ) );
			echo '</option>';
		}

		echo '</select>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Save the term language when a term is created or edited.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save_term_language( int $term_id ): void {
		if ( ! isset( $_POST['perflocale_term_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['perflocale_term_nonce'] ), 'perflocale_term_lang' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$language = sanitize_key( $_POST['perflocale_term_language'] ?? '' );

		if ( $language !== '' ) {
			$manager = new TermTranslationManager( $this->cache );

			if ( ! $manager->set_term_language( $term_id, $language ) ) {
				// Same per-user one-shot transient that
				// MetaBox::maybe_show_language_save_error() renders —
				// admin_notices fires on term screens too, so the post
				// metabox's renderer covers this writer.
				$user_id = get_current_user_id();

				if ( $user_id > 0 ) {
					set_transient(
						'perflocale_lang_save_error_' . $user_id,
						sprintf(
							/* translators: 1: term ID, 2: language slug. */
							__( 'PerfLocale could not save the language selection ("%2$s") for term %1$d. The language may have been deleted, or a database error occurred — please try again.', 'perflocale' ),
							$term_id,
							$language
						),
						60
					);
				}
			}
		}
	}
}
