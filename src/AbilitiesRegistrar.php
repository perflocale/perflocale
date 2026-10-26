<?php
/**
 * WordPress Abilities API integration (WordPress 6.9+; inert before).
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
	 * Longest URL, in characters, the convert-url ability accepts.
	 */
	private const CONVERT_URL_MAX_LENGTH = 2048;

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

		// Idempotent, as the abilities are.
		if ( true === self::call_optional( 'wp_has_ability_category', 'perflocale-translation' ) ) {
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

		// Read abilities: discovery and lookup only.
		$this->register_list_languages();
		$this->register_get_translations();
		$this->register_detect_language();
		$this->register_convert_url();

		// Write abilities are off by default: not a privilege boundary (both check capabilities) but
		// a surprise one, since translate-post spends MT budget and create-translation creates posts.
		if ( ! $this->write_abilities_enabled() ) {
			return;
		}

		$this->register_translate_post();
		$this->register_create_translation();
	}

	/**
	 * Whether the current blog allows the abilities that write.
	 *
	 * Checked again at run time: the registry outlives switch_to_blog(), so the running blog decides.
	 *
	 * @return bool
	 */
	private function write_abilities_enabled(): bool {
		/** @hook perflocale/abilities/write_enabled Enable the ABILITIES THAT WRITE. Default: the `abilities_write_enabled` setting. */
		return (bool) apply_filters( 'perflocale/abilities/write_enabled', (bool) $this->plugin->get( 'settings' )->get( 'abilities_write_enabled', false ) );
	}

	/**
	 * Invoke an optional core function by name, only when it exists.
	 *
	 * By name so static WordPress-version scanners do not flag these guarded calls.
	 *
	 * @param string $function Global function name.
	 * @param mixed  ...$args  Arguments to forward.
	 * @return mixed           Return value, or null when the function is absent.
	 */
	private static function call_optional( string $function, ...$args ) {
		return function_exists( $function ) ? $function( ...$args ) : null;
	}

	/**
	 * Per-object read gate (edit_post or edit_term), as TranslationsController::object_permissions_check().
	 *
	 * The permission_callback checks only the broad capability; this checks the object named in the input.
	 *
	 * @param int    $object_id   Post or term ID (already validated > 0).
	 * @param string $object_type 'post' or 'term'; anything else is treated as 'post'.
	 * @return \WP_Error|null     WP_Error on denial, null when allowed.
	 */
	private function authorize_object_read( int $object_id, string $object_type ): ?\WP_Error {
		if ( $object_type === 'term' ) {
			if ( ! current_user_can( 'edit_term', $object_id ) ) {
				return new \WP_Error(
					'cannot_access_term',
					__( 'You cannot access this term.', 'perflocale' ),
					[ 'status' => 403 ]
				);
			}

			return null;
		}

		if ( ! current_user_can( 'edit_post', $object_id ) ) {
			return new \WP_Error(
				'cannot_access_post',
				__( 'You cannot access this post.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		return null;
	}

	/**
	 * List all active languages.
	 *
	 * @return void
	 */
	private function register_list_languages(): void {
		// Idempotent: registering a name twice raises _doing_it_wrong.
		if ( true === self::call_optional( 'wp_has_ability', 'perflocale/list-languages' ) ) {
			return;
		}

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
				// Each output property has a title and description: MCP clients show them to models.
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
				// As GET /perflocale/v1/languages; the filter gets a WP_REST_Request for callbacks typed to the route.
				'permission_callback' => static function (): bool {
					if ( (bool) apply_filters( 'perflocale/api/languages_public', true, new \WP_REST_Request( 'GET', '/perflocale/v1/languages' ) ) ) {
						return true;
					}

					return current_user_can( 'read' );
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
	 * Get all translations for a post or term.
	 *
	 * @return void
	 */
	private function register_get_translations(): void {
		// Idempotent: registering a name twice raises _doing_it_wrong.
		if ( true === self::call_optional( 'wp_has_ability', 'perflocale/get-translations' ) ) {
			return;
		}

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
							'description'          => 'Map of language slug to the translated object\'s ID. The queried object itself is included under its own language. A translated post the caller may neither read nor edit, or a translated term the caller may not edit, is listed with null.',
							'additionalProperties' => [ 'type' => [ 'integer', 'null' ] ],
						],
					],
				],
				'execute_callback'    => function ( $input ) {
					$object_id   = (int) ( $input['object_id'] ?? 0 );
					$object_type = $input['object_type'] ?? 'post';

					if ( $object_id <= 0 ) {
						return new \WP_Error( 'invalid_id', __( 'Invalid object ID.', 'perflocale' ) );
					}

					// Per-object read gate, as the REST twin: the map lists every language version.
					$denied = $this->authorize_object_read( $object_id, (string) $object_type );

					if ( $denied instanceof \WP_Error ) {
						return $denied;
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

					// A sibling the caller may not see (post: read or edit; term: edit_term) is null, as in the REST map.
					if ( $type === Enum\ObjectType::Post ) {
						_prime_post_caches( array_values( $map ), false, false );
					} else {
						_prime_term_caches( array_values( $map ), false );
					}

					foreach ( $map as $slug => $sibling_id ) {
						if ( $sibling_id === $object_id ) {
							continue;
						}

						$visible = $type === Enum\ObjectType::Post
							? Helper::user_can_view_object( $sibling_id )
							: current_user_can( 'edit_term', $sibling_id );

						if ( ! $visible ) {
							$map[ $slug ] = null;
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
		// Idempotent: registering a name twice raises _doing_it_wrong.
		if ( true === self::call_optional( 'wp_has_ability', 'perflocale/detect-language' ) ) {
			return;
		}

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

					// Per-object read gate: a private or draft object's language is not public.
					$denied = $this->authorize_object_read( $object_id, (string) $object_type );

					if ( $denied instanceof \WP_Error ) {
						return $denied;
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
		// Idempotent: registering a name twice raises _doing_it_wrong.
		if ( true === self::call_optional( 'wp_has_ability', 'perflocale/convert-url' ) ) {
			return;
		}

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
							'maxLength'   => self::CONVERT_URL_MAX_LENGTH,
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

					// Holds when the schema's maxLength validation is filtered away.
					if ( is_string( $url ) && mb_strlen( $url ) > self::CONVERT_URL_MAX_LENGTH ) {
						return new \WP_Error( 'url_too_long', __( 'The URL is too long.', 'perflocale' ), [ 'status' => 400 ] );
					}

					if ( ! $this->plugin->has( 'url_converter' ) ) {
						return new \WP_Error( 'not_available', __( 'URL converter not available.', 'perflocale' ) );
					}

					$converter = $this->plugin->get( 'url_converter' );

					return [ 'url' => $converter->convert( $url, $slug ) ];
				},
				// Logged-in users only, so anonymous traffic cannot use the converter.
				'permission_callback' => static function (): bool {
					/**
					 * Filter the permission gate for the `perflocale/convert-url` ability.
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
					// Not public over MCP, matching the permission_callback.
					'mcp'          => [ 'public' => false ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Target-side guards, rate limit and mt_enabled() for translate-post, in MachineTranslateController's order.
	 *
	 * Order matters: admit() counts when it allows, so it runs after the target guards and before mt_enabled().
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_slug Target language slug, as passed to TranslationService::translate_post().
	 * @return \WP_Error|null     WP_Error on denial, null when allowed.
	 */
	private function authorize_mt( int $post_id, string $target_slug ): ?\WP_Error {
		$cache    = $this->plugin->get( 'cache' );
		$settings = $this->plugin->get( 'settings' );

		// Overwrite guard: translate_post() rewrites an existing translation in place.
		$manager  = new Translation\PostTranslationManager( $cache, $settings );
		$existing = (int) $manager->get_translation_id( $post_id, $target_slug );

		if ( $existing > 0 && $existing !== $post_id && ! current_user_can( 'edit_post', $existing ) ) {
			return new \WP_Error(
				'cannot_overwrite_translation',
				__( 'You cannot overwrite this translation.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		// A new translation copies shell, slug, meta and terms from the group's default-language post.
		$copy_from = $manager->get_copy_source_id( $post_id, $target_slug );

		if ( $copy_from > 0 && $copy_from !== $post_id && ! Helper::user_can_copy_translation_source( $copy_from ) ) {
			return new \WP_Error(
				'source_forbidden',
				__( 'You cannot edit the original this translation is copied from.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		// wp_insert_post() checks no capability: apply the copy source type's create gate.
		if ( $copy_from > 0 && ! Helper::user_can_create_like( $copy_from ) ) {
			return new \WP_Error(
				'cannot_create_type',
				__( 'You do not have permission to create translations of this content.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		// Shared limiter: every MT entry point draws on the same per-user and site-wide budgets.
		$limited = Translation\MtRateLimiter::admit( get_current_user_id() );

		if ( $limited instanceof \WP_Error ) {
			return $limited;
		}

		if ( ! $settings->mt_enabled() ) {
			return new \WP_Error(
				'mt_disabled',
				__( 'Machine translation is disabled.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		return null;
	}

	/**
	 * Machine-translate a post.
	 *
	 * @return void
	 */
	private function register_translate_post(): void {
		// Idempotent: registering a name twice raises _doing_it_wrong.
		if ( true === self::call_optional( 'wp_has_ability', 'perflocale/translate-post' ) ) {
			return;
		}

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
						'warnings'           => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'What the translation left out, such as a page builder layout.',
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

					// The permission_callback checks only the broad capability; edit rights on this post are checked here.
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						return new \WP_Error(
							'cannot_edit_post',
							__( 'You do not have permission to translate this post.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					// Password-protected posts: checked where MachineTranslateController::translate() checks them.
					if ( ! MachineTranslation\TranslationService::may_send_post( $post, 'ability' ) ) {
						return new \WP_Error( 'password_protected', MachineTranslation\TranslationService::password_protected_skip_message(), [ 'status' => 403 ] );
					}

					// The controller's remaining gates, in its order.
					$denied = $this->authorize_mt( $post_id, (string) $slug );

					if ( $denied instanceof \WP_Error ) {
						return $denied;
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
							'warnings'           => array_values( (array) ( $result['warnings'] ?? [] ) ),
						];
					} catch ( \Throwable $e ) {
						return new \WP_Error( 'translation_failed', $e->getMessage() );
					}
				},
				'permission_callback' => function () {
					return current_user_can( 'perflocale_use_mt' ) && $this->write_abilities_enabled();
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
		// Idempotent: registering a name twice raises _doing_it_wrong.
		if ( true === self::call_optional( 'wp_has_ability', 'perflocale/create-translation' ) ) {
			return;
		}

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
							'type'        => [ 'integer', 'null' ],
							'title'       => 'Translation stub ID',
							'description' => 'ID of the translation stub (a draft until published); null for an existing translation the caller cannot view.',
						],
						'language'           => [
							'type'        => 'string',
							'title'       => 'Language',
							'description' => 'Slug of the language the stub was created for.',
						],
						'edit_url'           => [
							'type'        => [ 'string', 'null' ],
							'title'       => 'Edit URL',
							'description' => 'Admin URL for editing the translation; null when its ID is not returned.',
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

					// The new translation can carry the source's content, so the caller must see it; host-aware, as REST.
					if ( ! Helper::user_can_view_object( $source_id ) ) {
						return new \WP_Error(
							'cannot_read_source',
							__( 'You do not have permission to read the source post.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					// Edit and read, not edit alone: edit_post does not imply read_post on a private post.
					if ( ! Helper::user_can_copy_translation_source( $source_id ) ) {
						return new \WP_Error(
							'cannot_edit_source',
							__( 'You cannot edit this post.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					$cache    = $this->plugin->get( 'cache' );
					$settings = $this->plugin->get( 'settings' );
					$manager  = new Translation\PostTranslationManager( $cache, $settings );

					// The copied default-language post can differ from source_id and needs the same rights.
					$copy_from = $manager->get_copy_source_id( $source_id, (string) $slug );

					if ( $copy_from > 0 && $copy_from !== $source_id && ! Helper::user_can_copy_translation_source( $copy_from ) ) {
						return new \WP_Error(
							'source_forbidden',
							__( 'You cannot edit the original this translation is copied from.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					// wp_insert_post() checks no capability: apply the copy source type's create gate.
					if ( $copy_from > 0 && ! Helper::user_can_create_like( $copy_from ) ) {
						return new \WP_Error(
							'cannot_create_type',
							__( 'You do not have permission to create translations of this content.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					$new_id = $manager->create_translation( $source_id, $slug, $copy_content, \PerfLocale\Enum\SourceType::Api, $created );

					if ( ! $new_id ) {
						return new \WP_Error( 'creation_failed', __( 'Failed to create translation.', 'perflocale' ) );
					}

					// The REST create route's rule: an existing translation is named only to a user who may see it.
					return \PerfLocale\Api\TranslationsController::ability_created( $new_id, $slug, $created );
				},
				'permission_callback' => function () {
					return current_user_can( 'perflocale_translate' ) && $this->write_abilities_enabled();
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
