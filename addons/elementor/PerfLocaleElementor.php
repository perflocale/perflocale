<?php
/**
 * PerfLocale Elementor addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor integration for PerfLocale.
 *
 * Registers a Language Switcher Elementor widget and marks Elementor
 * page data as translatable meta for content sync.
 */
final class PerfLocaleElementor implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [ 'elementor/elementor.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register language switcher widget.
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );

		// Add Elementor data as translatable meta.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// ⚠️⚠️ SEED ONCE, NEVER MIRROR. DO NOT PUT THIS KEY BACK ON
		// `perflocale/sync/mirror_meta_keys`.
		//
		// The line that used to live here justified a full mirror with "the layout
		// must stay structurally identical across siblings (text inside is
		// translated at render)". The second half of that sentence is FALSE for
		// Elementor: `_elementor_data` is not a layout skeleton, it is the JSON
		// document holding every widget's heading, paragraph, button label and alt
		// text, and nothing in this plugin translates it at render time — the only
		// Elementor hook registered anywhere is `elementor/widgets/register`, which
		// adds a language switcher widget.
		//
		// ContentSync's mirror is bidirectional by design (see its own docblock:
		// "any group member's save propagates group-wide"), so with the key on that
		// list a translator finishing the German page and clicking Update copied
		// German JSON onto the ENGLISH source, and the next English save copied
		// English back over the German. Reproduced on Elementor 4.2.1 and 4.1.3
		// through Elementor's own document-save API, with anonymous HTTP then
		// serving the overwritten language in both directions.
		//
		// Removing it from the mirror list moves it into ContentSync's SEED-ONLY
		// class, which copies to a sibling only when that sibling has no rows for
		// the key and never deletes. That fixes both directions at once, and also
		// stops a source with no Elementor data from deleting a translated layout.
		//
		// The cost, accepted deliberately: a later structural edit to the source no
		// longer propagates to existing translations. Propagating layout without
		// destroying translated text needs an Elementor-aware JSON merge that can
		// tell a layout node from a localisable value — a raw whole-document mirror
		// cannot give you both. Losing automatic layout updates is the lesser harm.
		//
		// Note this is also what the host expects: Elementor's own Polylang
		// integration copies its meta on create and explicitly NOT on sync
		// (elementor/includes/compatibility.php, save_polylang_meta()).

		// A raw meta mirror of the layout leaves the sibling's GENERATED
		// caches keyed to the pre-sync layout; drop them so Elementor
		// rebuilds on next view.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_generated_caches' ], 10, 3 );

		// ⭐ …and do not SEED those same generated caches in the first place.
		//
		// `add_meta_keys()` below says `_elementor_css` is "deliberately NOT" copied,
		// and that was never true. The seed path is
		// `PostTranslationManager::copy_post_meta()`, which is a BLANKET copy of every
		// meta row on the source minus an eight-key blocklist — not an allowlist built
		// from `translatable_meta_keys`. So leaving a key out of that filter excludes
		// it from nothing, and a new translation was born holding the SOURCE's compiled
		// CSS status, rendered-HTML cache and asset list.
		//
		// What the operator sees: a freshly created translation whose `_elementor_css`
		// row tells Elementor the CSS is already built (so it does not rebuild, and the
		// page renders unstyled against a stylesheet scoped to the SOURCE's element
		// IDs), and whose `_elementor_element_cache` serves the source language's
		// rendered HTML until that cache expires.
		//
		// The list is Elementor's OWN — the exclusions it applies when a post is
		// duplicated (elementor/includes/compatibility.php, yoast_duplicate_post()) —
		// rather than one guessed here.
		//
		// ⚠️ `_elementor_template_type` is deliberately NOT excluded even though
		// Elementor lists it: Elementor drops it from the copy and then writes it back
		// explicitly on `duplicate_post_post_copy`. PerfLocale has no such second step,
		// so excluding it would leave the translation with no document type at all.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_generated_caches' ], 10, 2 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Register the PerfLocale Language Switcher Elementor widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widget manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ): void {
		require_once __DIR__ . '/widgets/class-language-switcher-widget.php';

		if ( class_exists( 'PerfLocale_Elementor_Language_Switcher' ) ) {
			$widgets_manager->register( new \PerfLocale_Elementor_Language_Switcher() );
		}
	}

	/**
	 * Add Elementor meta keys as translatable.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_elementor_data';
		// `_elementor_css` is not listed here — it is a generated per-post CSS cache
		// whose rules are scoped to the SOURCE post's element IDs, so a sibling holding
		// it serves wrong-ID CSS.
		//
		// ⚠️ Leaving it out of THIS filter does not actually prevent the copy, and this
		// comment used to claim it did. The seed path is a blanket copy of every meta
		// row minus a small blocklist, so exclusion has to be stated explicitly — see
		// exclude_generated_caches(), registered on
		// `perflocale/translation/excluded_meta_keys` in boot().

		return $keys;
	}

	/**
	 * Drop the sibling's generated Elementor caches after a layout mirror.
	 *
	 * Mirrors Elementor's own on-save invalidation (Document::save): the
	 * post CSS file + `_elementor_css` status meta, the `_elementor_page_assets`
	 * dependency list, and the `_elementor_element_cache` rendered-HTML cache
	 * all describe the OLD layout after `_elementor_data` is overwritten, and
	 * Elementor only rebuilds each of them when its meta row is absent.
	 *
	 * @param int                $source_id   Source post ID.
	 * @param int                $target_id   Sibling post ID whose layout meta was overwritten.
	 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
	 * @return void
	 */
	public function invalidate_generated_caches( int $source_id, int $target_id, array $mirror_keys ): void {
		if ( ! in_array( '_elementor_data', $mirror_keys, true ) ) {
			return;
		}

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			// Deletes the generated CSS file AND the `_elementor_css` meta.
			\Elementor\Core\Files\CSS\Post::create( $target_id )->delete();
		} else {
			delete_post_meta( $target_id, '_elementor_css' );
		}

		delete_post_meta( $target_id, '_elementor_page_assets' );
		delete_post_meta( $target_id, '_elementor_element_cache' );
	}

	/**
	 * Keep Elementor's GENERATED caches out of a new translation.
	 *
	 * These describe the SOURCE post — its compiled CSS, its rendered HTML, its asset
	 * dependency list, its editor screenshot. None of them is content, every one of
	 * them is rebuilt on demand from `_elementor_data`, and each is keyed to the
	 * source's element IDs, so copying them onto a sibling is never right.
	 *
	 * Mirrors {@see self::invalidate_generated_caches()}, which drops the same family
	 * after a layout mirror — this is the create-time half of the same rule.
	 *
	 * @param array<int, string> $excluded  Meta keys already excluded from copying.
	 * @param int                $source_id Post being copied from.
	 * @return array<int, string>
	 */
	public function exclude_generated_caches( array $excluded, int $source_id ): array {
		return array_values(
			array_unique(
				array_merge(
					$excluded,
					[
						'_elementor_css',
						'_elementor_page_assets',
						'_elementor_element_cache',
						'_elementor_controls_usage',
						'_elementor_screenshot',
					]
				)
			)
		);
	}
}
