<?php
/**
 * PerfLocale Oxygen Builder Classic addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oxygen Builder Classic integration: builder meta keys and the ct_template post type.
 */
final class PerfLocaleOxygen implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Oxygen's post meta keys, in both stored spellings.
	 *
	 * Oxygen 4.8.3+ stores `_ct_*`; older versions and unmigrated sites keep `ct_*`.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const META_KEYS = [
		'builder_json'       => [ '_ct_builder_json', 'ct_builder_json' ],
		'builder_shortcodes' => [ '_ct_builder_shortcodes', 'ct_builder_shortcodes' ],
		'page_settings'      => [ '_ct_page_settings', 'ct_page_settings' ],
		'other_template'     => [ '_ct_other_template', 'ct_other_template' ],
	];

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'oxygen';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Oxygen Builder';
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
		return [ 'oxygen/functions.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'CT_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register Oxygen meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Machine translation reports this layout as not translated (BuilderLayout).
		add_filter( 'perflocale/mt/builder_documents', static fn( array $b ): array => [ ...$b, [ 'name' => 'Oxygen', 'keys' => [ '_ct_builder_json', 'ct_builder_json', '_ct_builder_shortcodes', 'ct_builder_shortcodes' ], 'always' => true ] ] );
		// Only text-free keys mirror: a mirror is bidirectional and the builder documents hold the user's words.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// A layout mirror leaves the sibling's generated CSS cache built from the old layout.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_css_cache' ], 10, 3 );

		// Register Oxygen custom post types as translatable.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// Never scope templates: Oxygen selects one by query and renders no layout when none matches;
		// resolve_template_language() keeps one row per group. See docs-ai/11-code-rationale.md, "PerfLocaleOxygen: template selection".
		add_filter( 'perflocale/query/never_scoped_post_types', [ $this, 'never_scope_template_types' ] );
		add_filter( 'the_posts', [ $this, 'resolve_template_language' ], 20, 2 );

		// oxygen-gutenberg registers a block per full-page-block page from a front-end query: unscope that query only.
		// See docs-ai/11-code-rationale.md, "PerfLocaleOxygen: full page blocks".
		add_action( 'pre_get_posts', [ $this, 'unscope_full_page_block_query' ], 1 );

		// Oxygen Classic has no element registration API: the switcher is the `[perflocale_switcher]` shortcode.
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add Oxygen meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		foreach ( self::META_KEYS as $spellings ) {
			foreach ( $spellings as $key ) {
				$keys[] = $key;
			}
		}
		// Not 'ct_options': it is a JSON attribute inside ct_* shortcodes, not post meta.

		return $keys;
	}

	/**
	 * Add Oxygen keys that keep full-mirror semantics: the text-free ones only.
	 *
	 * See docs-ai/11-code-rationale.md, "PerfLocaleOxygen: text-free mirror keys".
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mirror_keys( array $keys, string $post_type ): array {
		foreach ( self::META_KEYS['page_settings'] as $key ) {
			$keys[] = $key;
		}

		return $keys;
	}

	/**
	 * Drop the sibling's generated Oxygen CSS cache after a layout mirror.
	 *
	 * Oxygen rewrites a post's cached CSS only on its own save. Not oxygen_vsb_delete_css_file():
	 * it warns on entries flagged 'empty', which must be cleared too.
	 *
	 * @param int                $source_id   Source post ID.
	 * @param int                $target_id   Sibling post ID whose layout meta was overwritten.
	 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
	 * @return void
	 */
	public function invalidate_css_cache( int $source_id, int $target_id, array $mirror_keys ): void {
		$oxygen_keys = array_merge( self::META_KEYS['builder_json'], self::META_KEYS['builder_shortcodes'], self::META_KEYS['page_settings'] );

		if ( array_intersect( $oxygen_keys, $mirror_keys ) === [] ) {
			return;
		}

		$files_meta = get_option( 'oxygen_vsb_css_files_state', [] );

		if ( ! is_array( $files_meta ) || ! isset( $files_meta[ $target_id ] ) ) {
			return;
		}

		if ( ! empty( $files_meta[ $target_id ]['path'] ) && file_exists( $files_meta[ $target_id ]['path'] ) ) {
			wp_delete_file( $files_meta[ $target_id ]['path'] );
		}

		unset( $files_meta[ $target_id ] );

		// Oxygen's own CSS manifest option, deliberately unprefixed.
		update_option( 'oxygen_vsb_css_files_state', $files_meta );
	}

	/**
	 * Add Oxygen post types as translatable.
	 *
	 * @param array<int, string> $post_types Post types.
	 * @return array<int, string>
	 */
	public function add_post_types( array $post_types ): array {
		$post_types[] = 'ct_template';

		return array_unique( $post_types );
	}

	/**
	 * Oxygen post types that resolve by query and must never be scoped.
	 *
	 * `oxy_user_library` blocks register by slug from a front-end query; listed though not translatable, as a guard.
	 *
	 * @param array<int, string> $post_types Never-scoped post types.
	 * @return array<int, string>
	 */
	public function never_scope_template_types( array $post_types ): array {
		$post_types[] = 'ct_template';
		$post_types[] = 'oxy_user_library';

		return array_unique( $post_types );
	}

	/**
	 * Exempt oxygen-gutenberg's full-page-block registration query from scoping.
	 *
	 * Matches only post type `page` plus the `_ct_oxygenberg_full_page_block` meta key. Priority 1: before
	 * PerfLocale's own `pre_get_posts` stamping at 5.
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public function unscope_full_page_block_query( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}

		if ( (string) $query->get( 'meta_key' ) !== '_ct_oxygenberg_full_page_block' ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = is_string( $post_type ) ? [ $post_type ] : (array) $post_type;

		if ( array_diff( $post_type, [ 'page' ] ) !== [] ) {
			return;
		}

		$query->set( 'perflocale_all_languages', true );
	}

	/**
	 * Collapse each translation group to the row that belongs to this request.
	 *
	 * Oxygen-only queries: per group, the current-language row, else the default-language row, else none.
	 * Only rows the query returned count, so a draft sibling stays invisible.
	 *
	 * @param array<int, mixed> $posts Posts the query returned.
	 * @param \WP_Query         $query The query itself.
	 * @return array<int, mixed>
	 */
	public function resolve_template_language( $posts, $query ): array {
		// Not `count( $posts ) < 2`: one row in the wrong language is what the drop arm below is for.
		if ( ! is_array( $posts ) || $posts === [] || ! $query instanceof \WP_Query ) {
			return is_array( $posts ) ? $posts : [];
		}

		$requested = $query->get( 'post_type' );
		$requested = is_string( $requested ) ? [ $requested ] : (array) $requested;

		// Only our types: a mixed query (Oxygen's editor runs one) passes through untouched.
		if ( $requested === [] || array_diff( $requested, [ 'ct_template', 'oxy_user_library' ] ) !== [] ) {
			return $posts;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) || ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return $posts;
		}

		$current = $plugin->get( 'router' )->get_current_language();

		// No current language (wp-admin, pre-routing): leave the builder's own listings alone.
		if ( ! is_object( $current ) || ! isset( $current->slug ) ) {
			return $posts;
		}

		$manager = new \PerfLocale\Translation\PostTranslationManager(
			$plugin->get( 'cache' ),
			$plugin->get( 'settings' )
		);

		$default = ( new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) ) )->get_default();
		$present = [];

		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$present[ (int) $post->ID ] = true;
			}
		}

		$out   = [];
		$taken = [];

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
				$out[] = $post;
				continue;
			}

			$id           = (int) $post->ID;
			$translations = $manager->get_translations( $id );

			// Unlinked only: a template with no language renders for everyone. Not `count( $translations ) < 2`:
			// a group of one in a third language must still be dropped below.
			if ( ! is_array( $translations ) || $translations === [] ) {
				$out[] = $post;
				continue;
			}

			// Group identity: the sorted ids, the same from any member.
			$ids = array_map( 'intval', array_values( $translations ) );
			sort( $ids );
			$group_key = implode( ',', $ids );

			if ( isset( $taken[ $group_key ] ) ) {
				continue;
			}

			$winner = null;

			if ( isset( $translations[ $current->slug ] ) && isset( $present[ (int) $translations[ $current->slug ] ] ) ) {
				$winner = (int) $translations[ $current->slug ];
			} elseif ( $default && isset( $translations[ $default->slug ] ) && isset( $present[ (int) $translations[ $default->slug ] ] ) ) {
				$winner = (int) $translations[ $default->slug ];
			}

			$taken[ $group_key ] = true;

			// Neither current nor default language came back: a template falls back to its source, never
			// sideways (reached when rule meta such as `_ct_template_index` diverged between siblings).
			if ( $winner === null ) {
				continue;
			}

			if ( $winner === $id ) {
				$out[] = $post;
				continue;
			}

			$winner_post = get_post( $winner );
			$out[]       = $winner_post instanceof \WP_Post ? $winner_post : $post;
		}

		return $out;
	}
}
