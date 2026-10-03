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
 * Manages translation relationships between posts across languages.
 *
 * Handles creating, linking, and querying post translations via
 * the translation groups system.
 */
final class PostTranslationManager {

	/**
	 * Per-request memo for detect_post_language(), lifted to a class-level
	 * property so writes (set_post_language) can invalidate it.
	 *
	 * Keys are composed as "{blog_id}:{post_id}" so the cache is segregated
	 * per blog on multisite - without the prefix, a switch_to_blog() call
	 * would serve the prior site's memo entry for a colliding post ID.
	 *
	 * @var array<string, object|null>
	 */
	private static array $lang_cache = [];

	/**
	 * Soft cap on $lang_cache. Long-running CLI processes (bulk MT,
	 * sitemap rebuild) walk every post and would otherwise grow this
	 * array without bound (each entry ≈200B language stdClass).
	 */
	private const LANG_CACHE_CAP = 5000;

	/**
	 * Seconds a process may sleep, in total, waiting for creation locks other
	 * processes hold (post and term creates together). The cap is on time
	 * slept, not wall time: the lock reads and lock attempts between sleeps
	 * add their own query time on top.
	 */
	private const CREATE_LOCK_WAIT_SECONDS = 5.0;

	/**
	 * Seconds a creation lock may read free while it still cannot be taken
	 * before the wait gives up on it.
	 */
	private const CREATE_LOCK_REFUSED_SECONDS = 1.5;

	/**
	 * Creation locks (post and term) held by this process right now, keyed
	 * "{blog_id}:{lock name}".
	 *
	 * @var array<string, true>
	 */
	private static array $creation_locks_held = [];

	/**
	 * What is left of this process's CREATE_LOCK_WAIT_SECONDS.
	 *
	 * @var float
	 */
	private static float $create_wait_left = self::CREATE_LOCK_WAIT_SECONDS;

	/**
	 * Filter context for `wp_unique_post_slug`.
	 *
	 * Set to the target language slug while create_translation() is running
	 * a wp_insert_post(); read by Bootstrap::allow_translation_duplicate_slugs()
	 * to decide whether the new post is in a different language than its
	 * same-slug conflicts. The translation_links row that detect_post_language()
	 * normally reads doesn't exist yet at the moment wp_unique_post_slug fires
	 * (it's created later by link_posts()), so without this hint the filter
	 * has no way to know the new post's language and conservatively falls
	 * back to WP's -2 / -3 deduplication.
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
	private static function lang_cache_key( int $post_id ): string {
		return get_current_blog_id() . ':' . $post_id;
	}

	/**
	 * Clear the request-scoped static cache.
	 *
	 * Hooked to `switch_blog` so cross-site lookups never return a stale
	 * language object that belongs to the previous blog's languages table.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$lang_cache = [];
	}

	/**
	 * Drop a single post's entry from the request-scoped lang cache.
	 *
	 * Called from `before_delete_post` so the within-request memo doesn't
	 * keep returning the dead post's language after standard deletion. The
	 * cross-request ghost-link path is covered by the get_post() guard in
	 * detect_post_language(); this method handles same-request cleanup.
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
	private readonly TranslationGroupRepository $groups;

	/**
	 * @var LanguageRepository
	 */
	private readonly LanguageRepository $languages;

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
	 * Create a translation of a post in a target language.
	 *
	 * Always resolves the default-language post as the content source,
	 * then copies content, meta, featured image, and taxonomy terms.
	 *
	 * Applies no user policy. A caller acting for a user must also authorize
	 * the post {@see self::get_copy_source_id()} returns.
	 *
	 * @param int        $source_id Source post ID (any language in the group).
	 * @param string     $target_slug Target language slug.
	 * @param bool       $copy_content Whether to copy content from source.
	 * @param SourceType $source Provenance tag persisted on the new
	 *     translation_links row. Manual is the default — the metabox /
	 *     REST / CLI manual-translate flows pass nothing. Bulk-MT,
	 *     auto-translate-on-save, and the abilities API pass a more
	 *     specific value.
	 * @param bool|null  $created Set to true when this call inserted the
	 *     translation it returns, false otherwise. A translation this call did
	 *     not create can be another author's, and the caller's own earlier
	 *     reads may not have shown it: a caller that writes into the returned
	 *     post must check this first.
	 * @return int|false Post ID of the translation: newly created, or one
	 *     already linked in the target language, which may be another
	 *     process's. False on failure. Also false when another process holds
	 *     the creation lock and this call gets no answer: the lock was still
	 *     held when the wait budget ran out, or read free but could not be
	 *     taken; or this call did not wait (this process already holds a
	 *     creation lock, or has spent its wait budget) and this process knows
	 *     of no linked translation.
	 */
	public function create_translation( int $source_id, string $target_slug, bool $copy_content = false, SourceType $source = SourceType::Manual, ?bool &$created = null ): int|false {
		$created     = false;
		$source_post = get_post( $source_id );

		// Auto-drafts are unsaved editor placeholders; cloning one links an
		// empty translation to a post WordPress may garbage-collect.
		if ( ! $source_post || 'auto-draft' === $source_post->post_status ) {
			return false;
		}

		$target_lang = $this->languages->find_by_slug( $target_slug );

		if ( ! $target_lang ) {
			return false;
		}

		// Fast path: existing translation present and live. Skip the
		// lock entirely for the (very common) case where the operator
		// asks for a translation that already exists.
		$existing = $this->get_translation_id( $source_id, $target_slug );
		if ( $existing !== null && get_post( $existing ) ) {
			return $existing;
		}

		// Per-(source_id, target_slug) lock so two concurrent callers that both
		// pass the existing-check can't both reach wp_insert_post(). No unique
		// key stops the second insert: link_object() deletes the language's
		// current link before writing its own, so the later post would take
		// the language's slot and leave the earlier translation unlinked. Key
		// is narrow: other posts / other target languages never contend.
		/**
		 * @hook perflocale/translation/create_lock_ttl
		 *
		 * Lock TTL (seconds) for the create_translation() critical section.
		 * The default of 60s covers most real-world inserts including a
		 * meta+image copy hook that runs synchronously. Sites with slow
		 * `save_post` integrations (heavy SEO plugins, image regen on
		 * insert) can extend this so a legitimately-long insert doesn't
		 * lose its lock mid-flight.
		 *
		 * @param int $ttl Default 60.
		 */
		$lock_ttl = max( 5, (int) apply_filters( 'perflocale/translation/create_lock_ttl', 60 ) );

		return self::create_under_lock(
			'create_xlat_post_' . $source_id . '_' . $target_slug,
			$lock_ttl,
			function () use ( $source_id, $target_slug, $target_lang, $copy_content, $source, &$created ): int|false {
				return $this->do_create_translation( $source_id, $target_slug, $target_lang, $copy_content, $source, $created );
			},
			function () use ( $source_id, $target_slug ): int|false {
				$existing_after = $this->get_translation_id( $source_id, $target_slug );

				return $existing_after !== null && get_post( $existing_after ) ? $existing_after : false;
			}
		);
	}

	/**
	 * Run a translation create under its creation lock, waiting a bounded time
	 * when another process holds the lock.
	 *
	 * The other process's link row is written before it copies meta, the
	 * featured image and terms (or, for a term, records display slugs), so a
	 * link appearing does not mean it has finished. The wait is for the lock
	 * itself; the create then runs under it, and its existence check, which
	 * reads the database, returns that process's translation when it landed
	 * and creates one when that process failed.
	 *
	 * @internal Shared with TermTranslationManager: a post create copies terms,
	 *     so both kinds of create draw on one wait budget and one record of the
	 *     creation locks this process holds.
	 *
	 * @param string   $lock_name Creation lock name.
	 * @param int      $lock_ttl  Lock TTL in seconds.
	 * @param callable $create    Critical section: the translation ID or false.
	 * @param callable $fallback  The answer when the create cannot wait for the
	 *                            lock: what this process already knows.
	 * @phpstan-param callable(): (int|false) $create
	 * @phpstan-param callable(): (int|false) $fallback
	 * @return int|false
	 */
	public static function create_under_lock( string $lock_name, int $lock_ttl, callable $create, callable $fallback ): int|false {
		$held_key = get_current_blog_id() . ':' . $lock_name;

		$run = static function () use ( $create, $held_key ): int|false {
			self::$creation_locks_held[ $held_key ] = true;

			try {
				return $create();
			} finally {
				unset( self::$creation_locks_held[ $held_key ] );
			}
		};

		$result = \PerfLocale\Concurrency\Lock::with( $lock_name, $lock_ttl, $run );

		if ( $result !== null ) {
			return is_int( $result ) ? $result : false;
		}

		// No wait while this process holds a creation lock. If the lock it
		// failed to take is its own (a hook inside the critical section asked
		// for the same translation), nothing else will release it. If it holds
		// another one (a post create copying terms), every second waited keeps
		// that lock a second longer, and a lock held past its TTL can be taken
		// over while its create is still running. Nor once the process has
		// spent its wait budget.
		if ( self::$creation_locks_held !== [] || self::$create_wait_left <= 0.0 ) {
			return $fallback();
		}

		return self::create_after_lock_wait( $lock_name, $lock_ttl, $run );
	}

	/**
	 * Whether this process is inside a translation create right now (post or
	 * term). The new object's copied meta, image and terms are written from
	 * the group's source, so nothing written during the create is a change
	 * for other members to follow.
	 *
	 * @return bool
	 */
	public static function is_creating(): bool {
		return self::$creation_locks_held !== [];
	}

	/**
	 * Wait for a creation lock held by another process, then run the create
	 * under it.
	 *
	 * Each poll reads the lock row and, when the row reads free, tries to take
	 * the lock. The delay between polls doubles up to a cap. The time slept
	 * comes out of the budget the whole process shares, not the lock's TTL: a
	 * holder that died costs the process at most CREATE_LOCK_WAIT_SECONDS of
	 * sleep, however many creates it runs (a publish creating stubs in every
	 * language, a bulk run). The polls' own query time is not counted, so the
	 * wall time spent waiting can be longer on a slow database.
	 *
	 * @param string   $lock_name Lock name.
	 * @param int      $lock_ttl  Lock TTL in seconds.
	 * @param callable $run       The critical section passed to Lock::with().
	 * @phpstan-param callable(): (int|false) $run
	 * @return int|false The critical section's result; false when the lock was
	 *                   not taken before the budget ran out, or when it read
	 *                   free but could not be taken for
	 *                   CREATE_LOCK_REFUSED_SECONDS.
	 */
	private static function create_after_lock_wait( string $lock_name, int $lock_ttl, callable $run ): int|false {
		$delay   = 0.02;
		$refused = 0.0;

		while ( true ) {
			$free = 0 === \PerfLocale\Concurrency\Lock::seconds_remaining( $lock_name );

			if ( $free ) {
				$result = \PerfLocale\Concurrency\Lock::with( $lock_name, $lock_ttl, $run );

				if ( $result !== null ) {
					return is_int( $result ) ? $result : false;
				}

				// The row reads free, yet the lock was not taken. For up to a
				// second that is the lock's expiry second, or another process
				// taking the lock first (the next poll then reads it held).
				// Longer, the lock cannot be taken at all: a row Lock cannot
				// parse, which only the daily reaper removes, or a failing write.
				if ( $refused >= self::CREATE_LOCK_REFUSED_SECONDS ) {
					return false;
				}
			} else {
				$refused = 0.0;
			}

			if ( self::$create_wait_left <= 0.0 ) {
				return false;
			}

			$sleep                   = min( $delay, self::$create_wait_left );
			self::$create_wait_left -= $sleep;

			if ( $free ) {
				$refused += $sleep;
			}

			usleep( (int) round( $sleep * 1000000 ) );
			$delay = min( $delay * 2, 0.2 );
		}
	}

	/**
	 * Actual create_translation() body, executed under the
	 * per-(source_id, target_slug) lock acquired by the public wrapper.
	 *
	 * Re-checks existence inside the lock, against the database, before doing
	 * any wp_insert_post() / linking work.
	 *
	 * @param int        $source_id    Source post ID.
	 * @param string     $target_slug  Target language slug.
	 * @param object     $target_lang  Target language row (resolved by the caller).
	 * @param bool       $copy_content Whether to copy source content.
	 * @param SourceType $source       Provenance tag.
	 * @param bool       $inserted     Set to true once a new post is inserted
	 *                                 and linked.
	 * @return int|false New post ID; the ID of a live post already linked in
	 *                   the target language, which another process may have
	 *                   created after this process last read the group; or
	 *                   false on failure, including no answer from the
	 *                   existence query.
	 */
	private function do_create_translation( int $source_id, string $target_slug, object $target_lang, bool $copy_content, SourceType $source, bool &$inserted ): int|false {
		// ⭐ A TEMPLATE TRANSLATION ALWAYS STARTS FROM THE SOURCE'S BLOCKS.
		//
		// For a post, starting empty is a defensible default: the translator
		// writes fresh prose and an empty draft is simply unfinished. A
		// template is not prose, it is STRUCTURE — the header part, the query
		// loop, the 404 pattern — and the translatable part is the text sitting
		// inside that structure. Handing the operator a blank canvas asks them
		// to rebuild the whole layout by hand before they can translate a word.
		//
		// Worse, it fails DESTRUCTIVELY. The empty-content guard in
		// BlockTemplateTranslator only rescues a translation that is still
		// empty; the moment somebody types one paragraph into that blank
		// template and publishes it, it becomes the layout for that language.
		// Measured on a real site: a 404 template whose German translation had
		// been created empty and then given a single line of text rendered
		// 45,853 bytes with NO header and NO footer, against 108,929 bytes with
		// both on the source language. The page did not look untranslated, it
		// looked broken.
		//
		// So the flag is overridden rather than respected for these two types.
		// Every caller benefits — the Generate Missing Translations button, the
		// REST/abilities API, WP-CLI — and none of them has a reason to want an
		// empty template.
		if ( ! $copy_content ) {
			$source_type = get_post_type( $source_id );

			if ( is_string( $source_type ) && BlockTemplateSupport::is_template_type( $source_type ) ) {
				$copy_content = true;
			}
		}

		// Re-check existing INSIDE the lock, and read the database to do it.
		// get_translation_id() answers from this process's memo, filled before
		// the lock was taken - by the public method's fast path, or much
		// earlier by a batch that primed it - and blind to a translation
		// another process has linked since. Inserting on that answer would
		// make a second post, and linking it would take the language's slot
		// from the other translation. No answer from the database returns false
		// for the same reason: inserting on an unknown answer risks that
		// duplicate.
		$linked = $this->groups->find_translation_link_uncached( $source_id, ObjectType::Post, (int) $target_lang->id );

		if ( $linked === false ) {
			return false;
		}

		if ( $linked !== null ) {
			$existing = $linked['object_id'];

			if ( get_post( $existing ) ) {
				// Later reads in this process, the caller's follow-up save of
				// the returned post among them, must not answer from the view
				// that missed it.
				if ( $this->get_translation_id( $source_id, $target_slug ) !== $existing ) {
					$this->groups->forget_stale_group( $linked['group_id'], ObjectType::Post );
					self::forget_post_language( $existing );
				}

				return $existing;
			}

			// Stale link - clean it up so we can create a fresh translation.
			$this->groups->unlink_by_object_id( $existing, 'post' );
		}

		// Resolve the default-language post as the content source.
		$content_source = $this->resolve_source_post( $source_id );

		// The source post can vanish (hard-deleted) between the public
		// method's validation and here. Nothing to copy from — fail
		// gracefully so the caller can retry rather than fatal on a null
		// property access below.
		if ( ! $content_source instanceof \WP_Post ) {
			return false;
		}

		// Resolve post status from the Default Translation Status setting.
		$status_setting = (string) $this->settings->get( 'default_translation_status', 'empty' );
		$post_status    = match ( $status_setting ) {
			'pending' => 'pending',
			'draft' => 'draft',
			default => 'draft', // 'empty' also creates a draft - the translation status tracks "empty" separately.
		};

		// Create the new post with content from the resolved source. The
		// source's password comes along whether or not the content does: the
		// translation is the same page in another language, so the password
		// that protects the source protects the translation too, also once a
		// translator fills in an empty one.
		$new_post_data = [
			'post_type'     => $content_source->post_type,
			'post_status'   => $post_status,
			'post_author'   => $content_source->post_author,
			'post_title'    => $content_source->post_title,
			'post_name'     => $content_source->post_name,
			'post_content'  => $copy_content ? $content_source->post_content : '',
			'post_excerpt'  => $copy_content ? $content_source->post_excerpt : '',
			'post_password' => $content_source->post_password,
		];

		// Hierarchical types: attach the new translation to the PARENT'S
		// translation in the SAME language - the mapping
		// TermTranslationManager already applies to term parents. Without it
		// every translated child is inserted at the site root, which both
		// flattens the target-language page tree and drops the child into the
		// top-level slug namespace, where it competes with unrelated pages for
		// its own slug. When the parent has no translation yet there is no
		// same-language parent to attach to, so the child stays top level -
		// identical to the previous behaviour for that case.
		if ( is_post_type_hierarchical( $content_source->post_type ) && (int) $content_source->post_parent > 0 ) {
			$parent_translation = $this->get_translation_id( (int) $content_source->post_parent, $target_slug );

			// A translation_links row can outlive its post (hard delete on a
			// site whose cleanup hook never ran), and wp_insert_post() would
			// store that dead ID as the parent without complaint. A TRASHED
			// parent is the subtler case: get_post() still returns a WP_Post,
			// nothing unlinks a trashed post from its group (before_delete_post
			// fires only on permanent delete), and WordPress renames a trashed
			// post to `slug__trashed` — which the child would then inherit into
			// its own public URL. Trashing is WordPress's default delete, so
			// require a parent that is genuinely usable: present, not trashed,
			// and of the same post type as the source's parent.
			$parent_post = $parent_translation !== null ? get_post( $parent_translation ) : null;

			if ( $parent_post instanceof \WP_Post
				&& $parent_post->post_status !== 'trash'
				&& $parent_post->post_type === $content_source->post_type
			) {
				$new_post_data['post_parent'] = (int) $parent_post->ID;
			}
		}

		/** @hook perflocale/translation/post_data Filter the new translation post data before insertion. */
		$new_post_data = (array) apply_filters( 'perflocale/translation/post_data', $new_post_data, $source_id, $target_slug );

		// Suppress auto-assign during translation creation - we'll link it properly below.
		remove_action( 'save_post', [ \PerfLocale\Bootstrap::class, 'auto_assign_default_language' ], 5 );

		// Hint the wp_unique_post_slug filter that this insert is creating a
		// translation in $target_slug. Without it, the slug filter sees a
		// brand-new post with no translation_links row yet and can't tell it
		// belongs in a different language - so it would conservatively keep
		// WP's -2 / -3 dedup, even though the cross-language case is exactly
		// what this plugin should allow.
		self::$creating_translation_lang_slug = $target_slug;

		// $wp_error=true so the is_wp_error() guard below catches real DB
		// failures (the 2nd-arg-omitted shape returns int|0 only, collapsing
		// real errors into the "couldn't insert" path indistinguishably).
		// wp_slash: the copied title/content/excerpt come from get_post()
		// (unslashed); wp_insert_post() unslashes internally, so a source
		// containing backslashes would otherwise produce a corrupted copy.
		$new_post_id = wp_insert_post( wp_slash( $new_post_data ), true );

		self::$creating_translation_lang_slug = null;

		// Re-enable auto-assign.
		add_action( 'save_post', [ \PerfLocale\Bootstrap::class, 'auto_assign_default_language' ], 5, 2 );

		if ( is_wp_error( $new_post_id ) || $new_post_id === 0 ) {
			return false;
		}

		// Link the new post to the translation group. If linking fails we
		// roll back the freshly-created post so no orphaned language-less
		// draft is left behind. wp_insert_post lives in the wp_posts table
		// which InnoDB handles in its own transaction context, so we trash
		// it explicitly rather than relying on a wrapping transaction.
		$link_ok = $this->link_posts( $source_id, $new_post_id, $target_slug, $source );

		if ( ! $link_ok ) {
			wp_delete_post( $new_post_id, true );
			return false;
		}

		$inserted = true;

		// Flush the source object's translation cache so get_translations()
		// returns the updated list immediately (not stale for up to 1 hour).
		$source_group = $this->groups->find_for_object( $source_id, ObjectType::Post );

		if ( $source_group ) {
			$this->groups->invalidate_group_cache( (int) $source_group->id );
		}

		$this->finish_new_translation( $content_source, $new_post_id, $target_slug, $source_id );

		return $new_post_id;
	}

	/**
	 * Give a translation an importer inserted and linked what create_translation()
	 * gives its own translations: the content source's meta, featured image and
	 * taxonomy terms (term translations are created as needed), then the
	 * `perflocale/translation/created` action, which is where the WooCommerce
	 * addon gives a product translation its product type and variations.
	 *
	 * The content source is resolved as create_translation() resolves it: the
	 * group's default-language member. Everything is written as part of a
	 * translation create ({@see self::is_creating()} is true), so sync listeners
	 * take it for the new post's starting state, not for an edit to pass on.
	 *
	 * @param int    $new_post_id Translation post, already linked in the source's group.
	 * @param int    $source_id   Post it translates.
	 * @param string $target_slug Language slug of the translation.
	 * @return bool False when the source post is gone; nothing is copied then.
	 */
	public function complete_imported_translation( int $new_post_id, int $source_id, string $target_slug ): bool {
		$content_source = $this->resolve_source_post( $source_id );

		if ( ! $content_source instanceof \WP_Post || ! get_post( $new_post_id ) instanceof \WP_Post ) {
			return false;
		}

		$held_key = get_current_blog_id() . ':import_xlat_post_' . $new_post_id;

		self::$creation_locks_held[ $held_key ] = true;

		try {
			$this->finish_new_translation( $content_source, $new_post_id, $target_slug, $source_id );
		} finally {
			unset( self::$creation_locks_held[ $held_key ] );
		}

		return true;
	}

	/**
	 * Copy meta, featured image and taxonomy terms from the content source to a
	 * new, linked translation, then fire `perflocale/translation/created`.
	 *
	 * Failures here don't roll back the link (a translation with missing meta
	 * is re-syncable; a rolled-back link loses the translator's work) but
	 * aren't swallowed either. Each step has its own try/catch so one failure
	 * doesn't skip the rest; collected failures persist to
	 * `_perflocale_meta_copy_errors` (MetaBox shows + clears the notice) and
	 * fire `perflocale/translation/meta_copy_failed` for integrators.
	 *
	 * @param \WP_Post $content_source Post the meta, image and terms come from.
	 * @param int      $new_post_id    New translation post ID.
	 * @param string   $target_slug    Target language slug.
	 * @param int      $source_id      Post the translation was created from.
	 * @return void
	 */
	private function finish_new_translation( \WP_Post $content_source, int $new_post_id, string $target_slug, int $source_id ): void {
		$copy_errors = [];

		try {
			$this->copy_post_meta( $content_source->ID, $new_post_id );
		} catch ( \Throwable $e ) {
			$copy_errors[] = [
				'step'    => 'post_meta',
				'message' => $e->getMessage(),
			];
		}

		try {
			$this->copy_featured_image( $content_source->ID, $new_post_id, $target_slug );
		} catch ( \Throwable $e ) {
			$copy_errors[] = [
				'step'    => 'featured_image',
				'message' => $e->getMessage(),
			];
		}

		try {
			$this->copy_taxonomy_terms( $content_source->ID, $new_post_id, $target_slug );
		} catch ( \Throwable $e ) {
			$copy_errors[] = [
				'step'    => 'taxonomy_terms',
				'message' => $e->getMessage(),
			];
		}

		if ( ! empty( $copy_errors ) ) {
			update_post_meta( $new_post_id, '_perflocale_meta_copy_errors', wp_slash( $copy_errors ) );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				foreach ( $copy_errors as $err ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging
					error_log( 'PerfLocale: copy_' . $err['step'] . ' failed during create_translation for post ' . $new_post_id . ': ' . $err['message'] );
				}
			}

			/**
			 * Fires when one or more copy_* steps failed during translation
			 * creation. The translation post + link are still created;
			 * this signal exists so integrators can surface the failure
			 * to the operator (Slack alert, email, error tracker, etc.).
			 *
			 * @hook  perflocale/translation/meta_copy_failed
			 * @since 1.0.0
			 *
			 * @param int                                                  $new_post_id Newly-created translation post ID.
			 * @param int                                                  $source_id   Source post ID the translation was created from.
			 * @param string                                               $target_slug Target language slug.
			 * @param array<int, array{step: string, message: string}>     $errors      One entry per failed copy step.
			 */
			do_action( 'perflocale/translation/meta_copy_failed', $new_post_id, $content_source->ID, $target_slug, $copy_errors );
		}

		/**
		 * Fires after a translation post is created.
		 *
		 * @hook perflocale/translation/created
		 *
		 * @param int $new_id Newly-created post ID.
		 * @param string $type Object type ('post' or 'term').
		 * @param string $target_slug Target language slug.
		 * @param int $source_id Source post ID the translation was created from.
		 */
		do_action( 'perflocale/translation/created', $new_post_id, 'post', $target_slug, $source_id );
	}

	/**
	 * The post create_translation() would copy from, for a caller to authorize.
	 *
	 * Mirrors create_translation()'s own decisions so the post a caller checks
	 * is the post that gets copied: 0 when nothing would be copied (the source
	 * is missing or an auto-draft, the target language is unknown, or a live
	 * translation already exists and would simply be returned), otherwise the
	 * resolved content source — the default-language member of the group, or
	 * `$source_id` itself. A link whose post is gone does not count as an
	 * existing translation, exactly as in create_translation(), which unlinks
	 * it and creates a fresh copy.
	 *
	 * Side-effect free, and reads the same cached group map as
	 * create_translation(), so both resolve the same content source unless
	 * that map is refreshed between the two calls. It carries no user policy:
	 * callers acting for a user apply their own check to the returned id.
	 *
	 * @param int    $source_id   Post ID the caller was given.
	 * @param string $target_slug Target language slug.
	 * @return int Post ID that would be copied, or 0 when nothing would be.
	 */
	public function get_copy_source_id( int $source_id, string $target_slug ): int {
		$source_post = get_post( $source_id );

		if ( ! $source_post || 'auto-draft' === $source_post->post_status ) {
			return 0;
		}

		if ( ! $this->languages->find_by_slug( $target_slug ) ) {
			return 0;
		}

		$existing = $this->get_translation_id( $source_id, $target_slug );

		if ( $existing !== null && get_post( $existing ) ) {
			return 0;
		}

		$content_source = $this->resolve_source_post( $source_id );

		return $content_source instanceof \WP_Post ? (int) $content_source->ID : 0;
	}

	/**
	 * Resolve the default-language post as the content source.
	 *
	 * When creating a translation from a non-default language post,
	 * this ensures content always comes from the default language (source of truth).
	 * The default-language post is used whatever its status, trash included.
	 *
	 * @param int $source_id Any post ID in the translation group.
	 * @return \WP_Post|null The default-language post, the source post as
	 *                       fallback, or null if the post was hard-deleted
	 *                       between validation and here (get_post() miss).
	 */
	private function resolve_source_post( int $source_id ): ?\WP_Post {
		$default_lang = $this->languages->get_default();

		if ( ! $default_lang ) {
			return get_post( $source_id );
		}

		$translations = $this->get_translations( $source_id );
		$default_id   = $translations[ $default_lang->slug ] ?? null;

		if ( $default_id && $default_id !== $source_id ) {
			$default_post = get_post( $default_id );

			if ( $default_post ) {
				return $default_post;
			}
		}

		return get_post( $source_id );
	}

	/**
	 * Key segments that mark a meta key as a credential or secret (see
	 * is_sensitive_meta_key()). Such keys are never copied to a translation,
	 * seeded onto one, or sent to a machine translation provider.
	 */
	private const SENSITIVE_META_PATTERNS = [
		'_password',
		'_secret',
		'_token',
		'_api_key',
		'_apikey',
		'stripe',
		'paypal',
		'_credentials',
		'_encrypted',
		'_auth',
	];

	/**
	 * The sensitive meta-key patterns in force for the current site.
	 *
	 * Runs the filter on every call rather than memoising: the answer can
	 * differ per blog, and callers ask once per operation, not per key.
	 * Entries that are not strings are dropped.
	 *
	 * @return array<int, string>
	 */
	public static function sensitive_meta_patterns(): array {
		/** @hook perflocale/translation/dangerous_meta_patterns Filter patterns for meta keys that should never be copied. */
		$patterns = apply_filters( 'perflocale/translation/dangerous_meta_patterns', self::SENSITIVE_META_PATTERNS );

		if ( ! is_array( $patterns ) ) {
			return [];
		}

		return array_values( array_filter( $patterns, 'is_string' ) );
	}

	/**
	 * Whether a meta key matches one of the sensitive patterns, ignoring case.
	 *
	 * A built-in pattern matches whole segments of the key: it starts at the
	 * key's start, after a character that is not a letter or digit (a
	 * pattern's leading `_` is that character) or where a lower-case letter
	 * is followed by an upper-case one, and ends at the key's end or at such
	 * a boundary. Plural `s`, digits and glued credential words (`key`,
	 * `token`, `secret`, `hash`, `salt`, `code`, `id`, and the `auth`
	 * compounds `authorize`, `authorization` and `authentication`) may come
	 * between, one after another. So `_auth` matches `api_auth`,
	 * `_auth_token`, `_authkey`, `_authTokenHash` and `_authcodehash`, and
	 * `stripe` matches `stripeSecretKey`, not `book_author`, `_authority` or
	 * `pinstripe_color`; every key it matches also holds the pattern as a
	 * substring. Any other pattern, such as one added through the filter,
	 * matches anywhere in the key.
	 *
	 * @param string       $key      Meta key.
	 * @param array<mixed> $patterns Patterns from sensitive_meta_patterns();
	 *                               entries that are not strings are ignored.
	 * @return bool
	 */
	public static function is_sensitive_meta_key( string $key, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}

			if ( in_array( strtolower( $pattern ), self::SENSITIVE_META_PATTERNS, true ) ) {
				// The pattern and the glued words ignore case, the boundaries
				// do not. Each glued alternative consumes its own text (one
				// digit, not a run), so the repeated group cannot backtrack
				// exponentially on a long key.
				$left  = preg_match( '/^[a-z0-9]/i', $pattern ) ? '(?:(?<![a-zA-Z0-9])|(?<=[a-z])(?=[A-Z]))' : '';
				$right = preg_match( '/[a-z0-9]$/i', $pattern ) ? '(?i:s|[0-9]|key|token|secret|hash|salt|code|id|oriz(?:e|ation)|entication)*(?:(?![a-zA-Z0-9])|(?<=[a-z])(?=[A-Z]))' : '';

				if ( preg_match( '/' . $left . '(?i:' . preg_quote( $pattern, '/' ) . ')' . $right . '/', $key ) ) {
					return true;
				}
			} elseif ( stripos( $key, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Copy post meta from source to target, excluding internal WP keys.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @return void
	 */
	private function copy_post_meta( int $source_id, int $target_id ): void {
		$all_meta = get_post_meta( $source_id );

		if ( ! is_array( $all_meta ) ) {
			return;
		}

		$excluded = [
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_encloseme',
			'_pingme',
			'_thumbnail_id',
			// Per-source copy-failure bookkeeping — copying it would surface a
			// stale failure notice on a freshly-created translation.
			'_perflocale_meta_copy_errors',
			// Keys the copy source's owner emptied. The new translation has not
			// emptied anything, so it must still receive later seeds.
			ContentSync::SEED_CLEARED_META,
		];

		/** @hook perflocale/translation/excluded_meta_keys Filter meta keys excluded from copying. */
		$excluded = apply_filters( 'perflocale/translation/excluded_meta_keys', $excluded, $source_id );

		$dangerous_patterns = self::sensitive_meta_patterns();

		foreach ( $all_meta as $key => $values ) {
			if ( in_array( $key, $excluded, true ) || str_starts_with( $key, '_wp_trash' ) ) {
				continue;
			}

			// Per-language featured-image overrides (`_perflocale_thumbnail_{lang}`)
			// are language-scoped bookkeeping owned by the source post. Copying
			// them onto a translation makes MediaTranslationManager render the
			// copied override on the sibling's own /{lang}/ view (shadowing its
			// real featured image), and the per-language panel is hidden on the
			// translation so there's no UI to clear it. The translation's own
			// featured image is copied via _thumbnail_id (copy_featured_image).
			if ( str_starts_with( $key, '_perflocale_thumbnail_' ) ) {
				continue;
			}

			// Skip keys matching dangerous patterns (credentials, tokens, secrets).
			if ( self::is_sensitive_meta_key( (string) $key, $dangerous_patterns ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				// Restricted allowed_classes: post meta is user-written data,
				// so no class with unserialize side effects is instantiated
				// while it is copied into the target post. Same pattern as
				// PolylangImporter. The @ suppresses the unsupported-class
				// notice that the allowed_classes option itself neutralises.
				if ( is_string( $value ) && is_serialized( $value ) ) {
					// Allow ONLY stdClass: it has no __wakeup/__destruct so it
					// can't be a POP gadget (the object-injection risk that
					// allowed_classes=false guards against), while letting
					// object-typed builder payloads — Beaver Builder's
					// _fl_builder_data / _fl_builder_data_settings are arrays of
					// stdClass nodes — round-trip. allowed_classes=false turned
					// them into __PHP_Incomplete_Class, which threw the moment
					// add_post_meta()'s wp_unslash tried to write a property,
					// aborting the whole meta copy at the first BB key.
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes restricted to gadget-free stdClass.
					$decoded = @unserialize( $value, [ 'allowed_classes' => [ 'stdClass' ] ] );
				} else {
					$decoded = $value;
				}
				// Deep-slash counteracts add_post_meta()'s internal wp_unslash();
				// $decoded came unslashed from get_post_meta(), so without it
				// backslash-bearing builder JSON (_elementor_data, Bricks/Oxygen)
				// AND the string properties of stdClass builder nodes (Beaver)
				// are corrupted on every newly-created translation. deep_slash
				// recurses arrays AND objects, mirroring wp_unslash exactly.
				add_post_meta( $target_id, $key, \PerfLocale\Helper::deep_slash( $decoded ) );
			}
		}
	}

	/**
	 * Copy featured image from source to target.
	 *
	 * @param int    $source_id   Source post ID.
	 * @param int    $target_id   Target post ID.
	 * @param string $target_slug Target language slug.
	 * @return void
	 */
	private function copy_featured_image( int $source_id, int $target_id, string $target_slug ): void {
		$thumbnail_id = get_post_thumbnail_id( $source_id );

		if ( $thumbnail_id ) {
			set_post_thumbnail( $target_id, $this->attachment_in_language( (int) $thumbnail_id, $target_slug ) );
		}
	}

	/**
	 * The copy of an attachment that belongs to a language, when the site has one.
	 *
	 * Media is translated in place (per-language alt text and captions on the
	 * one attachment), but a site migrated from a plugin that duplicated media
	 * per language keeps those copies linked in a translation group, each with
	 * its own alt text and caption. A featured image copied or synced to a
	 * translation then takes that language's copy.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $lang_slug     Language slug.
	 * @return int The language's copy, or $attachment_id when it has none.
	 */
	public function attachment_in_language( int $attachment_id, string $lang_slug ): int {
		if ( $attachment_id <= 0 || $lang_slug === '' ) {
			return $attachment_id;
		}

		$copy_id = $this->get_translations( $attachment_id )[ $lang_slug ] ?? 0;

		if ( $copy_id > 0 && $copy_id !== $attachment_id && get_post_type( $copy_id ) === 'attachment' ) {
			return (int) $copy_id;
		}

		return $attachment_id;
	}

	/**
	 * Copy taxonomy terms from source to target, creating translated copies.
	 *
	 * For each translatable taxonomy, finds or creates translated term copies
	 * and assigns them to the target post. Handles hierarchical terms by
	 * sorting parents-first.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $target_slug Target language slug.
	 * @return void
	 */
	private function copy_taxonomy_terms( int $source_id, int $target_id, string $target_slug ): void {
		$taxonomies   = $this->settings->get_translatable_taxonomies();
		$term_manager = new TermTranslationManager( $this->cache );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			// Sort by ancestor depth (parents first) for hierarchical taxonomies.
			usort(
				$terms,
				function ( \WP_Term $a, \WP_Term $b ) use ( $taxonomy ): int {
					return count( get_ancestors( $a->term_id, $taxonomy ) )
					- count( get_ancestors( $b->term_id, $taxonomy ) );
				}
			);

			$target_term_ids = [];

			foreach ( $terms as $term ) {
				// Find existing translation or create a new one.
				$translated_id = $term_manager->get_translation_id( $term->term_id, $target_slug );

				if ( $translated_id === null ) {
					$translated_id = $term_manager->create_translation(
						$term->term_id,
						$taxonomy,
						$target_slug,
						true
					);
				}

				if ( $translated_id ) {
					$target_term_ids[] = (int) $translated_id;
				}
			}

			if ( ! empty( $target_term_ids ) ) {
				$assigned = wp_set_object_terms( $target_id, $target_term_ids, $taxonomy );

				// wp_set_object_terms() returns WP_Error (not a throw) on
				// failure, so the caller's try/catch can't see it. Surface it
				// rather than silently dropping the translated post's terms.
				if ( is_wp_error( $assigned ) && function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on a silent term-copy failure path.
					error_log(
						sprintf(
							'[perflocale] copy_taxonomy_terms: failed to assign %s terms to post %d: %s',
							$taxonomy,
							$target_id,
							$assigned->get_error_message()
						)
					);
				}
			}
		}
	}

	/**
	 * Link two posts as translations of each other.
	 *
	 * If the source post already has a translation group, the target
	 * post is added to that group. Otherwise, a new group is created.
	 *
	 * @param int        $source_id Source post ID.
	 * @param int        $target_id Target post ID.
	 * @param string     $target_slug Target language slug.
	 * @param SourceType $source Provenance tag persisted on the new link
	 *     row (and on the auto-created group when the source post had no
	 *     prior group).
	 * @return bool
	 */
	public function link_posts( int $source_id, int $target_id, string $target_slug, SourceType $source = SourceType::Manual ): bool {
		$target_lang = $this->languages->find_by_slug( $target_slug );

		if ( ! $target_lang ) {
			return false;
		}

		// Find existing group for the source.
		$group = $this->groups->find_for_object( $source_id, ObjectType::Post );

		if ( $group ) {
			// Add target to existing group.
			$result = $this->groups->link_object(
				(int) $group->id,
				$target_id,
				(int) $target_lang->id,
				TranslationStatus::Empty->value,
				$source
			);

			if ( $result !== false ) {
				// L1 memo may have cached `null` for the new target ID
				// when something (e.g. the wp_unique_post_slug filter
				// during the wp_insert_post that produced this target)
				// looked it up before this link existed. Drop the entry
				// so the next detect_post_language() returns the freshly-
				// linked language. Same drift TranslatorRole hits when
				// it changes a language assignment - see set_post_language().
				unset( self::$lang_cache[ self::lang_cache_key( $target_id ) ] );
			}

			return $result !== false;
		}

		// No group exists yet. Two admins translating the same untranslated
		// source within a second both read $group===null and create separate
		// groups (translation_groups has no UNIQUE on (type, object_id)), so
		// serialise this path with the token-guarded Lock (option
		// perflocale_lock_link_post_first_group_<source id>). Its CAS reclaim
		// and token-guarded release avoid the TOCTOU races a plain add_option
		// lock has.
		$lock_name = 'link_post_first_group_' . $source_id;

		$lock_result = \PerfLocale\Concurrency\Lock::with(
			$lock_name,
			30,
			function () use ( $source_id, $target_id, $target_slug, $target_lang, $source ): bool {
				// Re-read inside the lock — another worker may have
				// committed the group between the original
				// find_for_object() at the top and us acquiring the
				// lock. If so, link to its group; same code path as
				// the existing-group branch. The top-of-method call
				// memoized its null in the request-local $find_cache, so
				// drop that entry first — otherwise the re-read returns the
				// same stale null and creates a second group for this post.
				$this->groups->invalidate_find_cache( $source_id, ObjectType::Post );
				$double_check = $this->groups->find_for_object( $source_id, ObjectType::Post );
				if ( $double_check ) {
					$result = $this->groups->link_object(
						(int) $double_check->id,
						$target_id,
						(int) $target_lang->id,
						TranslationStatus::Empty->value,
						$source
					);
					if ( $result !== false ) {
						unset( self::$lang_cache[ self::lang_cache_key( $target_id ) ] );
					}
					return $result !== false;
				}

				// True first translation — find the source language and create
				// a group. Guard against a cross-request race: if a worker died
				// holding the lock and the source post was deleted before the
				// next worker took over, creating a group pointing at it would
				// leave a permanent orphan (no link, never cleaned up).
				if ( ! get_post( $source_id ) ) {
					return false;
				}

				$source_lang = $this->detect_post_language( $source_id );

				if ( ! $source_lang ) {
					$source_lang = $this->languages->get_default();
				}

				if ( ! $source_lang ) {
					return false;
				}

				// Create group with the source post. The source row itself
				// stays Manual — it represents the pre-translation post the
				// user authored manually. Only the target link inherits the
				// caller-supplied $source tag.
				$group_id = $this->groups->create_group(
					ObjectType::Post,
					$source_id,
					(int) $source_lang->id,
					TranslationStatus::Published->value
				);

				if ( $group_id === false ) {
					return false;
				}

				$result = $this->groups->link_object(
					$group_id,
					$target_id,
					(int) $target_lang->id,
					TranslationStatus::Empty->value,
					$source
				);

				if ( $result !== false ) {
					unset(
						self::$lang_cache[ self::lang_cache_key( $source_id ) ],
						self::$lang_cache[ self::lang_cache_key( $target_id ) ]
					);
				}

				return $result !== false;
			}
		);

		if ( $lock_result !== null ) {
			return $lock_result;
		}

		// Lost the lock race — sibling worker is creating the same
		// first group right now. Re-read once more: if it landed, link
		// the target through the existing-group branch. Drop the memoized
		// null first so the re-read reflects the sibling's committed group.
		$this->groups->invalidate_find_cache( $source_id, ObjectType::Post );
		$retry_group = $this->groups->find_for_object( $source_id, ObjectType::Post );
		if ( ! $retry_group ) {
			// Sibling hasn't committed yet; caller can retry.
			return false;
		}

		$result = $this->groups->link_object(
			(int) $retry_group->id,
			$target_id,
			(int) $target_lang->id,
			TranslationStatus::Empty->value,
			$source
		);
		if ( $result !== false ) {
			unset( self::$lang_cache[ self::lang_cache_key( $target_id ) ] );
		}
		return $result !== false;
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
		// Per-request memo (see self::$lang_cache). The same post's language
		// is looked up by the block-editor enqueue, metabox render, list
		// columns, query filters, and multiple save_post callbacks in a
		// single request. Caching once saves 3–5 repeat iterations without
		// changing behaviour. Key is blog-scoped for multisite correctness.
		$cache_key = self::lang_cache_key( $post_id );

		if ( array_key_exists( $cache_key, self::$lang_cache ) ) {
			return self::$lang_cache[ $cache_key ];
		}

		// FIFO eviction: insertion-ordered, slice oldest 25% on overflow.
		// Same heuristic as TranslationGroupRepository::$find_cache.
		if ( count( self::$lang_cache ) >= self::LANG_CACHE_CAP ) {
			$evict            = (int) ( self::LANG_CACHE_CAP / 4 );
			self::$lang_cache = array_slice( self::$lang_cache, $evict, null, true );
		}

		// Ghost-link guard: if a stale translation_link row outlived its post
		// (an addon bypassed wp_delete_post, or hooks fired out of order), the
		// lookup below would return the deleted post's old language. Return
		// null so callers fall through to the "unlinked post" branch. get_post
		// is an object-cache hit on the happy path, so this costs ~0µs.
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
	/**
	 * Bump the found_rows cache generation when a published post's language
	 * changes outside a post-save (REST/CLI set-language), which fire no
	 * transition_post_status. Published-only: draft moves don't affect public
	 * archive counts.
	 *
	 * @param int $post_id Post whose language changed.
	 * @return void
	 */
	private function maybe_flush_found_rows( int $post_id ): void {
		if ( get_post_status( $post_id ) === 'publish' ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( 'perflocale_found_rows' );
		}
	}

	/**
	 * Swap a post's wrong-language terms for their siblings in its current
	 * language, when the term swap service is loaded in this request.
	 *
	 * @param int $post_id Post whose language changed.
	 * @return void
	 */
	private function normalize_terms_to_language( int $post_id ): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'term_assignment_filter' ) ) {
			return;
		}

		$filter = $plugin->get( 'term_assignment_filter' );

		if ( $filter instanceof TermAssignmentFilter ) {
			$filter->normalize_post_terms( $post_id );
		}
	}

	public function set_post_language( int $post_id, string $lang_slug ): bool {
		$lang = $this->languages->find_by_slug( $lang_slug );

		if ( ! $lang ) {
			return false;
		}

		$group = $this->groups->find_for_object( $post_id, ObjectType::Post );

		if ( $group ) {
			global $wpdb;

			$links_table = \PerfLocale\Database\Schema::table( 'translation_links' );

			// Check if another post in this group already uses the target language.
			// The unique key (group_id, language_id) prevents duplicates.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$conflict = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT object_id FROM %i
					WHERE group_id = %d AND language_id = %d AND object_id != %d
					LIMIT 1',
					$links_table,
					(int) $group->id,
					(int) $lang->id,
					$post_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// $wpdb->get_var returns NULL both for "no rows" and "query error".
			// Check for DB errors to avoid silently proceeding on connection issues.
			if ( $wpdb->last_error ) {
				return false;
			}

			if ( $conflict ) {
				// Language is already taken by a sibling in this translation group.
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$links_table,
				[ 'language_id' => (int) $lang->id ],
				[
					'group_id'  => (int) $group->id,
					'object_id' => $post_id,
				],
				[ '%d' ],
				[ '%d', '%d' ]
			);

			// $wpdb->update returns false on error. A concurrent writer can
			// take the same (group_id, language_id) slot in the TOCTOU window
			// between the conflict-check SELECT above and this UPDATE, which
			// then fails on the UNIQUE KEY (ER_DUP_ENTRY 1062) → false. Don't
			// return true on false, or the admin UI confirms a change that
			// never landed.
			if ( $updated === false ) {
				return false;
			}

			// $wpdb->update can also return 0 — TWO causes that look identical:
			// (a) the link row was deleted between our conflict-check SELECT
			// and this UPDATE → real failure (callers think the post
			// moved to $lang but the row no longer exists).
			// (b) the row exists and its language_id ALREADY equals
			// (int) $lang->id → idempotent success. MySQL by default
			// reports affected_rows as CHANGED rows, not matched rows,
			// so a no-op UPDATE returns 0.
			// Re-read the current state to disambiguate. One extra SELECT
			// only on this rare path; the common case (changed-row) returns 1
			// and never enters this branch.
			if ( $updated === 0 ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$current_lang = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT language_id FROM %i WHERE group_id = %d AND object_id = %d LIMIT 1',
						$links_table,
						(int) $group->id,
						$post_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( $current_lang === null || (int) $current_lang !== (int) $lang->id ) {
					// Row gone (concurrent delete) or the language_id we
					// "set" isn't what landed in the database. Either way,
					// callers must not see this as success.
					return false;
				}
				// Else: idempotent — language was already this value. Fall
				// through to the cache-invalidation + true return so the
				// caller's "make this post be in $lang" expectation is met.
			}

			$this->groups->invalidate_group_cache( (int) $group->id );

			// The L1 memo still holds the PRIOR language for this post -
			// invalidate_group_cache handles the group-scoped caches but
			// leaves $lang_cache stale. Drop the entry so the very next
			// detect_post_language() re-reads the new language row.
			unset( self::$lang_cache[ self::lang_cache_key( $post_id ) ] );

			// A language reassignment moves a published post between
			// language-filtered archives; this path fires no
			// transition_post_status, so bump found_rows explicitly.
			$this->maybe_flush_found_rows( $post_id );

			// Terms attached under the previous language follow the post to
			// its new one (a post created with terms is labelled with the
			// default language first, and its terms swapped to match).
			if ( $updated === 1 ) {
				$this->normalize_terms_to_language( $post_id );

				// Cached WP_Query results are keyed by their SQL and the posts
				// last_changed stamp; the language constraint in that SQL is
				// unchanged, so without a new stamp the post stays in its old
				// language's cached lists.
				wp_cache_set_posts_last_changed();
			}

			return true;
		}

		$group_id = $this->groups->create_group(
			ObjectType::Post,
			$post_id,
			(int) $lang->id,
			TranslationStatus::Published->value
		);

		if ( $group_id !== false ) {
			// Per-request caches in detect_post_language() and
			// find_for_object() may have memoized a null lookup for this
			// post before it had a language. Bust both so subsequent
			// reads see the newly-created group instead of null.
			$this->groups->invalidate_find_cache( $post_id, ObjectType::Post );
			unset( self::$lang_cache[ self::lang_cache_key( $post_id ) ] );

			// First-time language assignment to a published post moves it into
			// a language-filtered archive — bump found_rows (no
			// transition_post_status fires on this REST/CLI path).
			$this->maybe_flush_found_rows( $post_id );

			// The post joins its language's cached WP_Query lists.
			wp_cache_set_posts_last_changed();
		}

		return $group_id !== false;
	}
}
