<?php
/**
 * Post translation manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Enum\TranslationStatus;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, links and queries post translations through translation groups; the writes are in PostTranslationWrites,
 * loaded on first use.
 */
class PostTranslationManager {

	/**
	 * Per-request memo for detect_post_language(), keyed "{blog_id}:{post_id}" so blogs never share entries.
	 *
	 * @var array<string, object|null>
	 */
	protected static array $lang_cache = [];

	/**
	 * Soft cap on $lang_cache: long CLI runs walk every post.
	 */
	private const LANG_CACHE_CAP = 5000;

	/**
	 * Seconds a process may sleep in total waiting for other processes' creation locks (posts and terms).
	 *
	 * Time slept, not wall time: the polls' query time comes on top.
	 */
	private const CREATE_LOCK_WAIT_SECONDS = 5.0;

	/**
	 * Creation locks (post and term) this process holds now, keyed "{blog_id}:{lock name}".
	 *
	 * @var array<string, true>
	 */
	protected static array $creation_locks_held = [];

	/**
	 * What is left of this process's CREATE_LOCK_WAIT_SECONDS.
	 *
	 * @var float
	 */
	protected static float $create_wait_left = self::CREATE_LOCK_WAIT_SECONDS;

	/**
	 * Target language slug during create_translation()'s insert, for Bootstrap::allow_translation_duplicate_slugs().
	 *
	 * The new post's link row does not exist yet when `wp_unique_post_slug` runs.
	 *
	 * @var string|null
	 */
	public static ?string $creating_translation_lang_slug = null;

	/**
	 * Compose the blog-prefixed cache key for a post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected static function lang_cache_key( int $post_id ): string {
		return get_current_blog_id() . ':' . $post_id;
	}

	/**
	 * Clear the request-scoped static cache on `switch_blog`.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$lang_cache = [];
	}

	/**
	 * Drop a single post's entry from the request-scoped lang cache (on `before_delete_post`).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function forget_post_language( int $post_id ): void {
		unset( self::$lang_cache[ self::lang_cache_key( $post_id ) ] );
	}

	/**
	 * @var TranslationGroupRepository
	 */
	protected readonly TranslationGroupRepository $groups;

	/**
	 * @var LanguageRepository
	 */
	protected readonly LanguageRepository $languages;

	/**
	 * @var CacheManager
	 */
	protected readonly CacheManager $cache;

	/**
	 * @var Settings
	 */
	protected readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 * @param Settings     $settings Plugin settings.
	 */
	public function __construct( CacheManager $cache, Settings $settings ) {
		$this->groups    = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$this->languages = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$this->cache     = $cache;
		$this->settings  = $settings;
	}

	/**
	 * The write half (PostTranslationWrites), created on first use.
	 *
	 * @var PostTranslationWrites|null
	 */
	private ?PostTranslationWrites $writes = null;

	/**
	 * The write half, with this manager's cache and settings, loaded on first use.
	 *
	 * @return PostTranslationWrites
	 */
	private function writes(): PostTranslationWrites {
		return $this->writes ??= new PostTranslationWrites( $this->cache, $this->settings );
	}

	/**
	 * Create a translation of a post in a target language, copied from the group's default-language post.
	 *
	 * @param int        $source_id Source post ID (any language in the group).
	 * @param string     $target_slug Target language slug.
	 * @param bool       $copy_content Whether to copy content from source.
	 * @param SourceType $source Provenance tag persisted on the new link row.
	 * @param bool|null  $created True when this call inserted the translation; a caller that writes into the
	 *     returned post must check it, as an existing one may be another author's.
	 * @return int|false Post ID of the translation (new, or already linked, possibly by another process), or
	 *     false on failure or when another process's creation lock gave no answer.
	 */
	public function create_translation( int $source_id, string $target_slug, bool $copy_content = false, SourceType $source = SourceType::Manual, ?bool &$created = null ): int|false {
		return $this->writes()->create_translation( $source_id, $target_slug, $copy_content, $source, $created );
	}

	/**
	 * Run a translation create under its creation lock, waiting a bounded time when another process holds it.
	 *
	 * @internal Shared with TermTranslationManager (a post create copies terms): one wait budget and held-lock record.
	 * @param string   $lock_name Creation lock name.
	 * @param int      $lock_ttl  Lock TTL in seconds.
	 * @param callable $create    Critical section: the translation ID or false.
	 * @param callable $fallback  The answer when the create cannot wait: what this process already knows.
	 * @phpstan-param callable(): (int|false) $create
	 * @phpstan-param callable(): (int|false) $fallback
	 * @return int|false
	 */
	public static function create_under_lock( string $lock_name, int $lock_ttl, callable $create, callable $fallback ): int|false {
		return PostTranslationWrites::create_under_lock( $lock_name, $lock_ttl, $create, $fallback );
	}

	/**
	 * Whether this process is inside a translation create (post or term): its writes are not edits to sync.
	 *
	 * @return bool
	 */
	public static function is_creating(): bool {
		return self::$creation_locks_held !== [];
	}

	/**
	 * Give an imported, linked translation what create_translation() gives its own: meta, image, terms, the action.
	 *
	 * @param int    $new_post_id Translation post, already linked in the source's group.
	 * @param int    $source_id   Post it translates.
	 * @param string $target_slug Language slug of the translation.
	 * @return bool False when the source post is gone; nothing is copied then.
	 */
	public function complete_imported_translation( int $new_post_id, int $source_id, string $target_slug ): bool {
		return $this->writes()->complete_imported_translation( $new_post_id, $source_id, $target_slug );
	}

	/**
	 * The post create_translation() would copy from, for a caller to authorize.
	 *
	 * @param int    $source_id   Post ID the caller was given.
	 * @param string $target_slug Target language slug.
	 * @return int Post ID that would be copied, or 0 when nothing would be.
	 */
	public function get_copy_source_id( int $source_id, string $target_slug ): int {
		return $this->writes()->get_copy_source_id( $source_id, $target_slug );
	}

	/**
	 * The meta-key patterns never sent to machine translation, filtered with the context 'machine_translation'.
	 *
	 * @return array<int, string>
	 */
	public static function mt_sensitive_meta_patterns(): array {
		return PostTranslationWrites::mt_sensitive_meta_patterns();
	}

	/**
	 * The sensitive meta-key patterns in force for the current site.
	 *
	 * @return array<int, string>
	 */
	public static function sensitive_meta_patterns(): array {
		return PostTranslationWrites::sensitive_meta_patterns();
	}

	/**
	 * Whether a meta key matches one of the sensitive patterns, ignoring case.
	 *
	 * @param string       $key      Meta key.
	 * @param array<mixed> $patterns Patterns from sensitive_meta_patterns(); non-strings are ignored.
	 * @return bool
	 */
	public static function is_sensitive_meta_key( string $key, array $patterns ): bool {
		return PostTranslationWrites::is_sensitive_meta_key( $key, $patterns );
	}

	/**
	 * The copy of an attachment that belongs to a language, when the site has one.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $lang_slug     Language slug.
	 * @return int The language's copy, or $attachment_id when it has none.
	 */
	public function attachment_in_language( int $attachment_id, string $lang_slug ): int {
		return $this->writes()->attachment_in_language( $attachment_id, $lang_slug );
	}

	/**
	 * Link two posts as translations of each other, in the source's group or a new one.
	 *
	 * @param int        $source_id Source post ID.
	 * @param int        $target_id Target post ID.
	 * @param string     $target_slug Target language slug.
	 * @param SourceType $source Provenance tag persisted on the new link row.
	 * @return bool
	 */
	public function link_posts( int $source_id, int $target_id, string $target_slug, SourceType $source = SourceType::Manual ): bool {
		return $this->writes()->link_posts( $source_id, $target_id, $target_slug, $source );
	}

	/**
	 * Get all translations of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int> language_slug => post_id map.
	 */
	public function get_translations( int $post_id ): array {
		$links = $this->groups->get_translations( $post_id, ObjectType::Post );
		$map   = [];

		foreach ( $links as $link ) {
			if ( isset( $link->language_slug ) ) {
				$map[ $link->language_slug ] = (int) $link->object_id;
			}
		}

		return $map;
	}

	/**
	 * Get the translated post ID for a specific language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang_slug Target language slug.
	 * @return int|null Translated post ID or null.
	 */
	public function get_translation_id( int $post_id, string $lang_slug ): ?int {
		$translations = $this->get_translations( $post_id );

		return $translations[ $lang_slug ] ?? null;
	}

	/**
	 * Whether a post has a translation in a language, by language ID (stable when the code changes).
	 *
	 * @param int $post_id     Post ID.
	 * @param int $language_id Language ID.
	 * @return bool
	 */
	public function has_translation_in( int $post_id, int $language_id ): bool {
		foreach ( $this->groups->get_translations( $post_id, ObjectType::Post ) as $link ) {
			$id = is_object( $link ) ? ( get_object_vars( $link )['language_id'] ?? null ) : null;

			if ( is_numeric( $id ) && (int) $id === $language_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the translation status for a post in a specific language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang_slug Language slug.
	 * @return TranslationStatus
	 */
	public function get_translation_status( int $post_id, string $lang_slug ): TranslationStatus {
		$links = $this->groups->get_translations( $post_id, ObjectType::Post );

		foreach ( $links as $link ) {
			if ( isset( $link->language_slug ) && $link->language_slug === $lang_slug ) {
				return TranslationStatus::tryFrom( $link->status ) ?? TranslationStatus::Empty;
			}
		}

		return TranslationStatus::Empty;
	}

	/**
	 * Detect the language of an existing post.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Language object or null.
	 */
	public function detect_post_language( int $post_id ): ?object {
		// Per-request memo, keyed per blog.
		$cache_key = self::lang_cache_key( $post_id );

		if ( array_key_exists( $cache_key, self::$lang_cache ) ) {
			return self::$lang_cache[ $cache_key ];
		}

		// FIFO eviction: drop the oldest 25% on overflow.
		if ( count( self::$lang_cache ) >= self::LANG_CACHE_CAP ) {
			$evict            = (int) ( self::LANG_CACHE_CAP / 4 );
			self::$lang_cache = array_slice( self::$lang_cache, $evict, null, true );
		}

		// A link row can outlive its post: a missing post has no language.
		if ( ! get_post( $post_id ) ) {
			self::$lang_cache[ $cache_key ] = null;
			return self::$lang_cache[ $cache_key ];
		}

		$links = $this->groups->get_translations( $post_id, ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) $link->object_id === $post_id && isset( $link->language_slug ) ) {
				self::$lang_cache[ $cache_key ] = $this->languages->find_by_slug( $link->language_slug );
				return self::$lang_cache[ $cache_key ];
			}
		}

		self::$lang_cache[ $cache_key ] = null;
		return self::$lang_cache[ $cache_key ];
	}

	/**
	 * Set the language for a post (creating a group if needed).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang_slug Language slug.
	 * @return bool
	 */
	public function set_post_language( int $post_id, string $lang_slug ): bool {
		return $this->writes()->set_post_language( $post_id, $lang_slug );
	}
}
