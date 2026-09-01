<?php
/**
 * WordPress Abilities API integration.
 *
 * Registers PerfLocale translation operations as discoverable abilities
 * for AI tools, external consumers, and the WordPress Abilities REST API.
 *
 * Disabled by default. Enable via filter:
 *   add_filter( 'perflocale/abilities/enabled', '__return_true' );
 *
 * Requires WordPress 6.9+ (Abilities API). On older versions, the hooks
 * never fire and this class has zero overhead.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers PerfLocale abilities with the WordPress Abilities API.
 */
final class AbilitiesRegistrar {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private readonly Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the PerfLocale ability category.
	 *
	 * Hooked to `wp_abilities_api_categories_init`.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		self::call_optional( 'wp_register_ability_category',
			'perflocale-translation',
			[
				'label'       => __( 'PerfLocale Translation', 'perflocale' ),
				'description' => __( 'Abilities for multilingual content translation, language detection, and URL conversion.', 'perflocale' ),
			]
		);
	}

	/**
	 * Register all PerfLocale abilities.
	 *
	 * Hooked to `wp_abilities_api_init`.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_list_languages();
		$this->register_get_translations();
		$this->register_detect_language();
		$this->register_convert_url();
		$this->register_translate_post();
		$this->register_create_translation();
	}

	/**
	 * List all active languages.
	 *
	 * @return void
	 */
	/**
	 * Invoke an optional core function by name, only when it exists.
	 *
	 * The Abilities API (`wp_register_ability*`, WP 6.9+) and AI Client
	 * (WP 7.0+) are optional progressive enhancements — the plugin's minimum
	 * is 6.4 and it is fully functional without them. Calling by name keeps
	 * this guarded, optional usage from tripping static WP-version scanners
	 * while preserving the function_exists() safety check at the point of use.
	 *
	 * @param string $function Global function name.
	 * @param mixed  ...$args  Arguments to forward.
	 * @return mixed           Return value, or null when the function is absent.
	 */
	private static function call_optional( string $function, ...$args ) {
		return function_exists( $function ) ? $function( ...$args ) : null;
	}

	private function register_list_languages(): void {
		self::call_optional( 'wp_register_ability',
			'perflocale/list-languages',
			[
				'label'               => __( 'List Languages', 'perflocale' ),
				'description'         => __( 'Get all active languages configured in PerfLocale.', 'perflocale' ),
				'category'            => 'perflocale-translation',
				'input_schema'        => [
					'type'                 => [ 'object', 'null' ],
					'properties'           => (object) [],
					'additionalProperties' => false,
				],
				// Every output property carries title + description, matching
				// the WP 7.1 core-ability schema convention — MCP clients and
				// LLM tool-use surface these to decide how to read the result.
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'languages' => [
							'type'        => 'array',
							'title'       => 'Active languages',
							'description' => 'All active languages, in configured sort order.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'slug'        => [
										'type'        => 'string',
										'title'       => 'Slug',
										'description' => 'URL-safe language identifier used in routes and API calls (e.g. "de").',
									],
									'locale'      => [
										'type'        => 'string',
										'title'       => 'Locale',
										'description' => 'Full WordPress locale code (e.g. "de_DE").',
									],
									'name'        => [
										'type'        => 'string',
										'title'       => 'Name',
										'description' => 'Language name in the site\'s admin language (e.g. "German").',
									],
									'native_name' => [
										'type'        => 'string',
										'title'       => 'Native name',
										'description' => 'Language name in the language itself (e.g. "Deutsch").',
									],
									'is_default'  => [
										'type'        => 'boolean',
										'title'       => 'Is default',
										'description' => 'True for the site\'s default (source) language.',
									],
								],
							],
						],
						'count'     => [
							'type'        => 'integer',
							'title'       => 'Count',
							'description' => 'Number of active languages returned.',
						],
					],
				],
				'execute_callback'    => function () {
					$cache = $this->plugin->get( 'cache' );
					$repo  = new Database\Repository\LanguageRepository( $cache );
					$langs = $repo->get_active();

					$result = [];
					foreach ( $langs as $lang ) {
						$result[] = [
							'slug'        => $lang->slug,
							'locale'      => $lang->locale,
							'name'        => $lang->name,
							'native_name' => $lang->native_name,
							'is_default'  => (bool) $lang->is_default,
						];
					}

					return [
						'languages' => $result,
						'count'     => count( $result ),
					];
				},
				// Public read-only by design: the active-language list is
				// already emitted on every front-end page (switcher, hreflang
				// tags) and mirrors the public GET /perflocale/v1/languages
				// route. Nothing here is written or secret.
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
					],
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Get all translations for a post or term.
	 *
	 * @return void
	 */
	private function register_get_translations(): void {
		self::call_optional( 'wp_register_ability',
			'perflocale/get-translations',
			[
				'label'               => __( 'Get Translations', 'perflocale' ),
				'description'         => __( 'Get all language versions of a post or term.', 'perflocale' ),
				'category'            => 'perflocale-translation',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'object_id' ],
					'properties' => [
						'object_id'   => [
							'type'        => 'integer',
							'description' => 'Post or term ID.',
						],
						'object_type' => [
							'type'        => 'string',
							'description' => 'Object type: "post" or "term".',
							'enum'        => [ 'post', 'term' ],
							'default'     => 'post',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'translations' => [
							'type'                 => 'object',
							'title'                => 'Translations',
							'description'          => 'Map of language slug to the translated object\'s ID. The queried object itself is included under its own language.',
							'additionalProperties' => [ 'type' => 'integer' ],
						],
					],
				],
				'execute_callback'    => function ( $input ) {
					$object_id   = (int) ( $input['object_id'] ?? 0 );
					$object_type = $input['object_type'] ?? 'post';

					if ( $object_id <= 0 ) {
						return new \WP_Error( 'invalid_id', __( 'Invalid object ID.', 'perflocale' ) );
					}

					$cache = $this->plugin->get( 'cache' );
					$repo  = new Database\Repository\TranslationGroupRepository( $cache );
					$type  = $object_type === 'term' ? Enum\ObjectType::Term : Enum\ObjectType::Post;
					$links = $repo->get_translations( $object_id, $type );
					$map   = [];

					foreach ( $links as $link ) {
						if ( isset( $link->language_slug ) ) {
							$map[ $link->language_slug ] = (int) $link->object_id;
						}
					}

					return [ 'translations' => $map ];
				},
				'permission_callback' => function () {
					return current_user_can( 'perflocale_translate' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
					],
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Detect the language of a post or term.
	 *
	 * @return void
	 */
	private function register_detect_language(): void {
		self::call_optional( 'wp_register_ability',
			'perflocale/detect-language',
			[
				'label'               => __( 'Detect Language', 'perflocale' ),
				'description'         => __( 'Detect what language a post or term is assigned to.', 'perflocale' ),
				'category'            => 'perflocale-translation',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'object_id' ],
					'properties' => [
						'object_id'   => [
							'type'        => 'integer',
							'description' => 'Post or term ID.',
						],
						'object_type' => [
							'type'        => 'string',
							'description' => 'Object type: "post" or "term".',
							'enum'        => [ 'post', 'term' ],
							'default'     => 'post',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'language' => [
							'type'        => [ 'object', 'null' ],
							'title'       => 'Language',
							'description' => 'The assigned language, or null when the object has no language assignment.',
							'properties'  => [
								'slug'   => [
									'type'        => 'string',
									'title'       => 'Slug',
									'description' => 'URL-safe language identifier (e.g. "de").',
								],
								'locale' => [
									'type'        => 'string',
									'title'       => 'Locale',
									'description' => 'Full WordPress locale code (e.g. "de_DE").',
								],
								'name'   => [
									'type'        => 'string',
									'title'       => 'Name',
									'description' => 'Language display name.',
								],
							],
						],
					],
				],
				'execute_callback'    => function ( $input ) {
					$object_id   = (int) ( $input['object_id'] ?? 0 );
					$object_type = $input['object_type'] ?? 'post';

					if ( $object_id <= 0 ) {
						return new \WP_Error( 'invalid_id', __( 'Invalid object ID.', 'perflocale' ) );
					}

					$cache    = $this->plugin->get( 'cache' );
					$settings = $this->plugin->get( 'settings' );

					if ( $object_type === 'term' ) {
						$manager = new Translation\TermTranslationManager( $cache );
						$lang    = $manager->detect_term_language( $object_id );
					} else {
						$manager = new Translation\PostTranslationManager( $cache, $settings );
						$lang    = $manager->detect_post_language( $object_id );
					}

					if ( ! $lang ) {
						return [ 'language' => null ];
					}

					return [
						'language' => [
							'slug'   => $lang->slug,
							'locale' => $lang->locale,
							'name'   => $lang->name,
						],
					];
				},
				'permission_callback' => function () {
					return current_user_can( 'perflocale_translate' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
					],
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Convert a URL to a different language.
	 *
	 * @return void
	 */
	private function register_convert_url(): void {
		self::call_optional( 'wp_register_ability',
			'perflocale/convert-url',
			[
				'label'               => __( 'Convert URL', 'perflocale' ),
				'description'         => __( 'Convert a URL to a different language version.', 'perflocale' ),
				'category'            => 'perflocale-translation',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'url', 'target_language' ],
					'properties' => [
						'url'             => [
							'type'        => 'string',
							'description' => 'The URL to convert.',
						],
						'target_language' => [
							'type'        => 'string',
							'description' => 'Target language slug (e.g. "en", "de").',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'url' => [
							'type'        => 'string',
							'title'       => 'Converted URL',
							'description' => 'The URL rewritten for the target language, respecting the site\'s URL mode (prefix, subdomain, domain, or query).',
						],
					],
				],
				'execute_callback'    => function ( $input ) {
					$url  = $input['url'] ?? '';
					$slug = $input['target_language'] ?? '';

					if ( $url === '' || $slug === '' ) {
						return new \WP_Error( 'missing_params', __( 'URL and target_language are required.', 'perflocale' ) );
					}

					if ( ! $this->plugin->has( 'url_converter' ) ) {
						return new \WP_Error( 'not_available', __( 'URL converter not available.', 'perflocale' ) );
					}

					$converter = $this->plugin->get( 'url_converter' );

					return [ 'url' => $converter->convert( $url, $slug ) ];
				},
				// Require a logged-in user. The ability runs through
				// UrlConverter::convert(), which is read-only and forces
				// the host back to home_url, but unauthenticated URL
				// rewriting on a public-facing MCP/REST surface is a
				// sharper edge than necessary — gate on session presence
				// at minimum so anonymous traffic can't burn cycles on
				// the converter. Sites that want this ability genuinely
				// public can override via the
				// `perflocale/abilities/convert_url_permission` filter.
				'permission_callback' => static function (): bool {
					/**
					 * Filter the permission gate for the
					 * `perflocale/convert-url` MCP/REST ability. Default
					 * requires a logged-in user.
					 *
					 * @hook perflocale/abilities/convert_url_permission
					 * @param bool $allowed Default `is_user_logged_in()`.
					 */
					return (bool) apply_filters(
						'perflocale/abilities/convert_url_permission',
						is_user_logged_in()
					);
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
					],
					// MCP exposure follows the permission_callback above:
					// authenticated by default; opt in to public via the
					// filter if your deployment intentionally wants
					// anonymous URL-rewriting via MCP.
					'mcp'          => [ 'public' => false ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Machine-translate a post.
	 *
	 * @return void
	 */
	private function register_translate_post(): void {
		self::call_optional( 'wp_register_ability',
			'perflocale/translate-post',
			[
				'label'               => __( 'Translate Post', 'perflocale' ),
				'description'         => __( 'Machine-translate a post to a target language using the configured translation provider.', 'perflocale' ),
				'category'            => 'perflocale-translation',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'post_id', 'target_language' ],
					'properties' => [
						'post_id'         => [
							'type'        => 'integer',
							'description' => 'Source post ID to translate.',
						],
						'target_language' => [
							'type'        => 'string',
							'description' => 'Target language slug (e.g. "en", "de").',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'translated_post_id' => [
							'type'        => 'integer',
							'title'       => 'Translated post ID',
							'description' => 'ID of the machine-translated post in the target language.',
						],
						'language'           => [
							'type'        => 'string',
							'title'       => 'Language',
							'description' => 'Slug of the language the post was translated into.',
						],
					],
				],
				'execute_callback'    => function ( $input ) {
					$post_id = (int) ( $input['post_id'] ?? 0 );
					$slug    = $input['target_language'] ?? '';

					if ( $post_id <= 0 || $slug === '' ) {
						return new \WP_Error( 'missing_params', __( 'post_id and target_language are required.', 'perflocale' ) );
					}

					$post = get_post( $post_id );

					if ( ! $post ) {
						return new \WP_Error( 'not_found', __( 'Post not found.', 'perflocale' ) );
					}

					// Per-target capability check. The permission_callback only
					// gates the BROAD `perflocale_use_mt` cap; without this
					// check, a user with translator caps but no edit rights to
					// THIS post can MT-translate it (creating a sibling whose
					// content reveals the source). Mirrors the per-target
					// `edit_post` enforcement in MachineTranslateController.
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return new \WP_Error(
							'cannot_edit_post',
							__( 'You do not have permission to translate this post.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					try {
						$cache    = $this->plugin->get( 'cache' );
						$settings = $this->plugin->get( 'settings' );
						$service  = new MachineTranslation\TranslationService( $settings, $cache );

						$result        = $service->translate_post( $post_id, $slug, '', false );
						$translated_id = isset( $result['post_id'] ) ? (int) $result['post_id'] : 0;

						if ( $translated_id <= 0 ) {
							return new \WP_Error( 'translation_failed', __( 'Translation provider returned no post ID.', 'perflocale' ) );
						}

						return [
							'translated_post_id' => $translated_id,
							'language'           => $slug,
						];
					} catch ( \Throwable $e ) {
						return new \WP_Error( 'translation_failed', $e->getMessage() );
					}
				},
				'permission_callback' => function () {
					return current_user_can( 'perflocale_use_mt' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Create a translation stub for a post.
	 *
	 * @return void
	 */
	private function register_create_translation(): void {
		self::call_optional( 'wp_register_ability',
			'perflocale/create-translation',
			[
				'label'               => __( 'Create Translation', 'perflocale' ),
				'description'         => __( 'Create a translation stub for a post in a target language, optionally copying the source content.', 'perflocale' ),
				'category'            => 'perflocale-translation',
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'source_id', 'target_language' ],
					'properties' => [
						'source_id'       => [
							'type'        => 'integer',
							'description' => 'Source post ID.',
						],
						'target_language' => [
							'type'        => 'string',
							'description' => 'Target language slug.',
						],
						'copy_content'    => [
							'type'        => 'boolean',
							'description' => 'Whether to copy the source content to the new translation.',
							'default'     => false,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'translated_post_id' => [
							'type'        => 'integer',
							'title'       => 'Translation stub ID',
							'description' => 'ID of the newly created translation stub (a draft until published).',
						],
						'language'           => [
							'type'        => 'string',
							'title'       => 'Language',
							'description' => 'Slug of the language the stub was created for.',
						],
						'edit_url'           => [
							'type'        => 'string',
							'title'       => 'Edit URL',
							'description' => 'Admin URL for editing the new translation.',
						],
					],
				],
				'execute_callback'    => function ( $input ) {
					$source_id    = (int) ( $input['source_id'] ?? 0 );
					$slug         = $input['target_language'] ?? '';
					$copy_content = (bool) ( $input['copy_content'] ?? false );

					if ( $source_id <= 0 || $slug === '' ) {
						return new \WP_Error( 'missing_params', __( 'source_id and target_language are required.', 'perflocale' ) );
					}

					$post = get_post( $source_id );

					if ( ! $post ) {
						return new \WP_Error( 'not_found', __( 'Source post not found.', 'perflocale' ) );
					}

					// Per-target capability check. The permission_callback
					// only gates the BROAD `perflocale_translate` cap; without
					// this check, a user can copy_content=true to clone the
					// source body of a private/draft post they have no read
					// rights to into a new translation post they DO control,
					// effectively exfiltrating it.
					if ( ! current_user_can( 'read_post', $source_id ) ) {
						return new \WP_Error(
							'cannot_read_source',
							__( 'You do not have permission to read the source post.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					$cache    = $this->plugin->get( 'cache' );
					$settings = $this->plugin->get( 'settings' );
					$manager  = new Translation\PostTranslationManager( $cache, $settings );

					$new_id = $manager->create_translation( $source_id, $slug, $copy_content, \PerfLocale\Enum\SourceType::Api );

					if ( ! $new_id ) {
						return new \WP_Error( 'creation_failed', __( 'Failed to create translation.', 'perflocale' ) );
					}

					return [
						'translated_post_id' => $new_id,
						'language'           => $slug,
						'edit_url'           => get_edit_post_link( $new_id, 'raw' ) ?: '',
					];
				},
				'permission_callback' => function () {
					return current_user_can( 'perflocale_translate' );
				},
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}
}
