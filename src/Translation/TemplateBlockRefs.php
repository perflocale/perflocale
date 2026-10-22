<?php
/**
 * Menu and synced-pattern references stored in translated templates and
 * template parts.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Settings;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points the Navigation blocks and synced patterns of a translated template or
 * template part at that language's translation of their menu or pattern, in
 * the stored template.
 *
 * WHY THE STORED TEMPLATE AND NOT ONLY THE RENDER
 *
 * A translated part, such as "Header (de)", starts as a copy of the original,
 * so its Navigation block holds the original menu's id
 * (`<!-- wp:navigation {"ref":7} /-->`) and a synced pattern the original
 * pattern's (`<!-- wp:block {"ref":12} /-->`). On the front end
 * {@see \PerfLocale\Frontend\BlockRefTranslator} swaps those ids while the page
 * renders. The Site Editor loads the stored template, so a user who opens
 * "Header (de)" and edits the menu's labels or the pattern's text edits the
 * original, and every language that shows it.
 *
 * THE RULES (a "container" is a wp_template or wp_template_part translation; a
 * "reference" is the `ref` of a core/navigation or core/block block in it)
 *
 *   - A reference is rewritten only while it still names the source-language
 *     menu or pattern: one linked in the default language that the original
 *     container (its default-language member) names too, and whose translation
 *     in the container's language exists and can be opened in the editor
 *     (a menu published or draft, the two states core's Navigation block
 *     edits; a pattern published, draft or pending).
 *   - A reference the user changed is never touched: once the container names
 *     another menu or pattern than its original does, no rewrite reaches it.
 *     So is one the user switched back to the source: each write records,
 *     per block occurrence, what these rules put there (REWRITTEN_META), and
 *     an occurrence that names the source where its record says the rules
 *     wrote the translation is left as it is; the other occurrences of the
 *     container are still rewritten.
 *   - When a container translation is created or linked, its references to
 *     such menus and patterns are rewritten. When a menu or pattern
 *     translation is created, linked, or comes back from the trash, the
 *     references to its source in the containers of its language are
 *     rewritten.
 *   - When a menu or pattern translation is deleted or trashed, the references
 *     to it in the containers of its language go back to its source, so no
 *     container is left naming something that is gone. The occurrences that
 *     went back are recorded (FALLBACK_META); a restore points exactly those
 *     at the translation again, while they still name the source.
 *
 * Only the default-language container is a template's original; it is never
 * rewritten. A rewrite changes nothing a visitor sees: BlockRefTranslator
 * renders that language's menu or pattern when it is usable, from either
 * reference, and the source otherwise. So the write needs no cache flush.
 *
 * WHEN IT RUNS
 *
 * A create runs the rules at once (`perflocale/translation/created`), so the
 * request that made the translation answers with the containers already
 * pointing at it. Any other link of a post (an import, a language change, a
 * reset template linked back) is noted by
 * {@see \PerfLocale\Database\Repository\TranslationGroupRepository::link_object()}
 * and {@see PostTranslationManager::set_post_language()}, where the object is
 * known to be a post: one array entry. The noted posts are processed once, at
 * the end of the REST request or at shutdown (or early, after QUEUE_LIMIT
 * posts), with their post types read from the post cache or one query per
 * QUEUE_LIMIT posts. Links of terms and strings never reach this class. A
 * menu or pattern that changes language also has the references to it in the
 * containers of the language it left pointed back (at that language's own
 * member of the group, or the original). A JSON restore, which writes link
 * rows directly, runs the rules once at its end for the posts it linked
 * ({@see self::after_restore()}).
 *
 * HOW MUCH RUNS AT ONCE
 *
 * A container's own create or link rewrites that container. A menu or
 * pattern event (a translation created, linked, trashed, deleted, restored
 * or moved to another language) reaches every container of a language that
 * names it: the request that made the change rewrites the first SYNC_LIMIT
 * of them (in id order), so a site with a few templates and parts per
 * language sees them all pointing at the right menu or pattern when the
 * request answers. The rest goes to a background job
 * ({@see \PerfLocale\Background\Jobs\TemplateRefsFanoutJob}) that goes on
 * from the last container read, in keyset pages, and first checks that the
 * change still stands (a translation restored again is not pointed back).
 * Without a job (no user who may run it, as in WP-CLI without --user) the
 * request rewrites the rest itself, page by page.
 *
 * THE WRITE
 *
 * Only the number after `"ref":` in the block comment changes; every other
 * byte stays as stored. The row is updated with a compare-and-set on the
 * content the rewrite was computed from (re-read and retried when another
 * writer got there first), and its cache cleaned: wp_update_post() would run
 * the content through kses for a user without `unfiltered_html` and add a
 * revision for a change the user did not make. When a user is logged in, a
 * container that user cannot edit is skipped.
 */
final class TemplateBlockRefs {

	/**
	 * Referenced post type => the core block that references it by `ref`.
	 *
	 * @var array<string, string>
	 */
	private const TARGETS = [
		'wp_navigation' => 'navigation',
		'wp_block'      => 'block',
	];

	/**
	 * Post types whose translations hold the references.
	 *
	 * @var array<int, string>
	 */
	private const CONTAINERS = [ 'wp_template', 'wp_template_part' ];

	/**
	 * Statuses the block editor opens, per referenced post type. core's
	 * Navigation block treats any other menu status as missing; core/block
	 * shows a pattern the REST route returns in the edit context.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const OPENABLE = [
		'wp_navigation' => [ 'publish', 'draft' ],
		'wp_block'      => [ 'publish', 'draft', 'pending' ],
	];

	/**
	 * Noted links processed early once this many wait, and the size of one
	 * post-type query.
	 */
	private const QUEUE_LIMIT = 500;

	/**
	 * Post meta of a removed menu or pattern translation: its source and the
	 * block occurrences its removal pointed back at that source,
	 * `[ 'source' => id, 'containers' => [ container id => [ occurrence, ... ] ] ]`,
	 * so that its restore points exactly those again, while they still name
	 * the source ({@see self::on_removed()}, {@see self::on_restored()}). An
	 * occurrence is the position of the block among the container's blocks of
	 * that name that hold a `ref` ({@see self::occurrences()}). Every
	 * container and occurrence the removal wrote is kept: the containers of
	 * the language that named the translation bound the list.
	 */
	public const FALLBACK_META = '_perflocale_ref_fallback';

	/**
	 * Post meta of a container translation: for each block occurrence these
	 * rules wrote, block name => ( occurrence => [ the group's source-language
	 * member, the id written ] ). An occurrence that names the source while
	 * its record says the rules wrote another id there was switched back by
	 * its user, and no rule rewrites it ({@see self::switched_back()}); nor
	 * an occurrence without an entry that names a source another occurrence
	 * was switched back to. An entry is matched to the content first
	 * ({@see self::matched()}): blocks added or removed before it move it.
	 * At most RECORD_LIMIT entries per block.
	 */
	public const REWRITTEN_META = '_perflocale_ref_rewritten';

	/**
	 * Most entries kept per block in REWRITTEN_META.
	 */
	private const RECORD_LIMIT = 100;

	/**
	 * Containers a menu or pattern event reads and rewrites in the request
	 * that made the change; the rest goes to a job.
	 */
	private const SYNC_LIMIT = 20;

	/**
	 * Containers per page when a request rewrites the rest itself.
	 */
	private const PAGE = 50;

	/**
	 * The kinds of fan-out (a menu or pattern event, applied to the
	 * containers of one language): `forward` points the references to a
	 * source at its translation (only where the container's original names the
	 * source too, and not an occurrence its user switched back); `back` points
	 * the references to a removed translation at its source and records them
	 * in FALLBACK_META; `left` points the references to a menu or pattern that
	 * left the language at the language's own member or the source; `restore`
	 * points the occurrences FALLBACK_META recorded at the translation again.
	 *
	 * @var array<int, string>
	 */
	private const KINDS = [ 'forward', 'back', 'left', 'restore' ];

	/**
	 * While the upgrade backfill runs ({@see self::backfill_page()}): the rules
	 * apply to every container, whoever the current user is.
	 *
	 * @var bool
	 */
	private static bool $system = false;

	/**
	 * Why the last write_container() call that returned null failed:
	 * 'database' (a read or the UPDATE failed) or 'conflict' (every
	 * compare-and-set attempt lost to a concurrent edit); '' otherwise.
	 *
	 * @var string
	 */
	private static string $write_failure = '';

	/**
	 * References rewritten by the writes of this request (the backfill reports it).
	 *
	 * @var int
	 */
	private static int $rewritten = 0;

	/**
	 * The block comment tokenizer of core's block parser
	 * (WP_Block_Parser::next_token()).
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Posts linked in this request and not processed yet, by blog.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $queue = [];

	/**
	 * Menus and patterns that changed language in this request, by blog:
	 * post id => the language id they left.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static array $left = [];

	/**
	 * Whether the end-of-request handlers are attached.
	 *
	 * @var bool
	 */
	private static bool $flush_attached = false;

	/**
	 * A translation was created: `perflocale/translation/created`.
	 *
	 * @param mixed $new_id      New translation's id.
	 * @param mixed $object_type 'post' or 'term'.
	 * @param mixed $target_slug Language slug of the new translation.
	 * @return void
	 */
	public static function on_created( $new_id, $object_type = '', $target_slug = '' ): void {
		if ( 'post' !== $object_type || ! is_numeric( $new_id ) || ! is_string( $target_slug ) || '' === $target_slug ) {
			return;
		}

		$post = get_post( (int) $new_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( isset( self::TARGETS[ $post->post_type ] ) ) {
			self::target_available( $post );
		} elseif ( in_array( $post->post_type, self::CONTAINERS, true ) ) {
			self::localise_container( $post, $target_slug );
		} else {
			return;
		}

		// The create linked the post just before this ran: that note is done.
		unset( self::$queue[ get_current_blog_id() ][ $post->ID ] );
	}

	/**
	 * A post was linked to a translation group or changed its language there.
	 *
	 * Called where the linked object is known to be a post. Only notes it; the
	 * rules run once at the end of the request.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function note_post_link( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$blog = get_current_blog_id();

		self::$queue[ $blog ][ $post_id ] = true;

		if ( ! self::$flush_attached ) {
			self::$flush_attached = true;
			add_filter( 'rest_request_after_callbacks', [ self::class, 'flush_after_rest' ], 10, 1 );
			add_action( 'shutdown', [ self::class, 'flush' ], 1 );
		}

		if ( count( self::$queue[ $blog ] ) >= self::QUEUE_LIMIT ) {
			self::flush();
		}
	}

	/**
	 * A post changed its language inside its translation group
	 * ({@see PostTranslationManager::set_post_language()}).
	 *
	 * Noted like a link (the new language's containers follow at the end of
	 * the request); a menu or pattern also has the references to it in the
	 * containers of the language it left pointed back.
	 *
	 * @param int $post_id          Post id.
	 * @param int $left_language_id Language it had before.
	 * @return void
	 */
	public static function note_language_change( int $post_id, int $left_language_id ): void {
		if ( $post_id > 0 && $left_language_id > 0 ) {
			self::$left[ get_current_blog_id() ][ $post_id ] = $left_language_id;
		}

		self::note_post_link( $post_id );
	}

	/**
	 * After a JSON restore ({@see \PerfLocale\Admin\DataImporter::import()})
	 * wrote link rows directly: run the rules once for the posts it linked.
	 *
	 * The restored posts that are linked menus, patterns, templates or parts
	 * are found with one query per QUEUE_LIMIT restored ids, among those ids
	 * only.
	 *
	 * @param array<int, int> $post_ids Post ids the restore linked.
	 * @return void
	 */
	public static function after_restore( array $post_ids ): void {
		if ( [] === $post_ids ) {
			return;
		}

		$wanted = array_merge( array_keys( self::TARGETS ), self::CONTAINERS );

		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Placeholders only: one %s per type.
		$in_types = implode( ',', array_fill( 0, count( $wanted ), '%s' ) );
		$restored = array_values( array_filter( array_unique( array_map( 'intval', $post_ids ) ), static fn( int $id ): bool => $id > 0 ) );
		$types    = [];

		foreach ( array_chunk( $restored, self::QUEUE_LIMIT ) as $chunk ) {
			// Placeholders only: one %d per id.
			$in_ids = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- built from placeholders only; the lists are spread.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT p.ID, p.post_type FROM %i p INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' WHERE p.ID IN ({$in_ids}) AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND p.post_type IN ({$in_types})", $wpdb->posts, $wpdb->prefix . 'perflocale_translation_links', ...$chunk, ...$wanted ) );

			foreach ( (array) $rows as $row ) {
				if ( isset( $row->ID, $row->post_type ) && is_numeric( $row->ID ) && is_string( $row->post_type ) ) {
					$types[ (int) $row->ID ] = $row->post_type;
				}
			}
		}

		if ( [] === $types ) {
			return;
		}

		try {
			self::process_types( $types );
		} catch ( \Throwable $e ) {
			// The restore itself is written; a failure here leaves the
			// references as they were, which the Site Editor notice shows.
			unset( $e );
		}
	}

	/**
	 * Process the noted links when a REST route has answered, so the response
	 * reaches the client with the containers already pointing at the right
	 * menus and patterns.
	 *
	 * @param mixed $response Route response.
	 * @return mixed The response, unchanged.
	 */
	public static function flush_after_rest( $response ) {
		self::flush();

		return $response;
	}

	/**
	 * Process every noted link, each in the blog it was noted in.
	 *
	 * @return void
	 */
	public static function flush(): void {
		$queues      = self::$queue;
		$lefts       = self::$left;
		self::$queue = [];
		self::$left  = [];

		foreach ( array_unique( array_merge( array_keys( $queues ), array_keys( $lefts ) ) ) as $blog ) {
			$ids  = array_keys( $queues[ $blog ] ?? [] );
			$left = $lefts[ $blog ] ?? [];

			if ( [] === $ids && [] === $left ) {
				continue;
			}

			$switched = is_multisite() && $blog !== get_current_blog_id();

			if ( $switched ) {
				switch_to_blog( $blog );
			}

			try {
				self::process_left( $left );
				self::process_links( $ids );
			} catch ( \Throwable $e ) {
				// The links themselves are written; a failure here leaves the
				// references as they were, which the Site Editor notice shows.
				unset( $e );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * The container translations the upgrade backfill reads, after one id:
	 * templates and template parts linked in a language other than the
	 * default, not trashed, whose content holds a `ref`, in id order (keyset
	 * paging). Null when they could not be read (the services or the default
	 * language missing, a database error): "could not read" is never "nothing
	 * to read".
	 *
	 * @param int $after Last id read, 0 to start.
	 * @param int $limit Rows to read.
	 * @return array<int, int>|null Container id => language id, or null.
	 */
	private static function backfill_rows( int $after, int $limit ): ?array {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$context = self::context();
		$default = null === $context ? null : $context['languages']->get_default();
		$default = is_object( $default ) && isset( $default->id ) && is_numeric( $default->id ) ? (int) $default->id : 0;

		if ( 0 === $default ) {
			return null;
		}

		$from = "FROM %i p
			INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id <> %d
			WHERE p.ID > %d
			  AND p.post_type IN ( %s, %s )
			  AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
			  AND p.post_content LIKE %s";
		$args = [ $wpdb->posts, $wpdb->prefix . 'perflocale_translation_links', $default, $after, self::CONTAINERS[0], self::CONTAINERS[1], '%' . $wpdb->esc_like( '"ref"' ) . '%' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders only (in $from).
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.ID, l.language_id ' . $from . ' ORDER BY p.ID LIMIT %d', ...array_merge( $args, [ $limit ] ) ) );

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( (array) $rows as $row ) {
			if ( isset( $row->ID, $row->language_id ) && is_numeric( $row->ID ) && is_numeric( $row->language_id ) ) {
				$out[ (int) $row->ID ] = (int) $row->language_id;
			}
		}

		return $out;
	}

	/**
	 * One page of the upgrade backfill: the rules a container translation's
	 * create runs ({@see self::localise_container()}), run for container
	 * translations written before those rules existed. Each container's
	 * references to a source-language menu or pattern that its original
	 * names too are pointed at that menu's or pattern's translation in the
	 * container's language, when it exists and the editor opens it; a
	 * reference the user changed is kept; every write is a compare-and-set.
	 * The rules apply to every container, whoever the current user is: a
	 * rewrite only points a reference at the same group's member in the
	 * container's own language, which is what the page already renders.
	 *
	 * The page's containers, their groups, their originals and the menus and
	 * patterns they name (with their groups and translations) are read in one
	 * batch per kind before the first container, and each container's
	 * compare-and-set starts from the content read there.
	 *
	 * @param int        $after Last container id read, 0 to start.
	 * @param int        $limit Containers per page.
	 * @param float|null $until A microtime( true ) deadline, checked after each container: the page stops at
	 *                          the first container that ends past it (next is that container). Null: none.
	 * @return array{next: int, read: int, updated: int, references: int, error: bool, failed: int, cause: string} next
	 *         is 0 after the last page; error is true when the page could not be read (next is $after)
	 *         or a container's write failed (failed is its id, cause 'database' when the database did
	 *         not accept a read or the change, 'conflict' when every compare-and-set attempt lost to a
	 *         concurrent edit; next the last container before it, and read and updated count the
	 *         containers before it).
	 */
	public static function backfill_page( int $after, int $limit, ?float $until = null ): array {
		$rows    = self::backfill_rows( $after, max( 1, $limit ) );
		$context = self::context();
		$updated = 0;
		$before  = self::$rewritten;
		$slugs   = [];

		if ( ! is_array( $rows ) || null === $context ) {
			return [
				'next'       => $after,
				'read'       => 0,
				'updated'    => 0,
				'references' => 0,
				'error'      => true,
				'failed'     => 0,
				'cause'      => '',
			];
		}

		// The rules read each container's record of what they wrote, its
		// group and original, and the menus and patterns it names: one batch
		// read per kind for the page instead of reads per container.
		self::prime_page( array_keys( $rows ), $context );

		$read    = 0;
		$last    = $after;
		$failed  = 0;
		$cause   = '';
		$stopped = false;

		self::$system = true;

		try {
			foreach ( $rows as $id => $language_id ) {
				if ( ! isset( $slugs[ $language_id ] ) ) {
					$language              = $context['languages']->find( $language_id );
					$slugs[ $language_id ] = is_object( $language ) && isset( $language->slug ) && is_string( $language->slug ) ? $language->slug : '';
				}

				$post    = get_post( $id );
				$written = '' !== $slugs[ $language_id ] && $post instanceof WP_Post ? self::localise_container( $post, $slugs[ $language_id ] ) : false;

				// A write that failed stops the page at this container: the
				// page is an error, and the backfill is not recorded as done.
				if ( null === $written ) {
					$failed = $id;
					$cause  = self::$write_failure;
					break;
				}

				if ( $written ) {
					++$updated;
				}

				++$read;
				$last = $id;

				if ( null !== $until && microtime( true ) >= $until && $read < count( $rows ) ) {
					$stopped = true;
					break;
				}
			}
		} finally {
			self::$system = false;
		}

		if ( $failed > 0 ) {
			return [
				'next'       => $last,
				'read'       => $read,
				'updated'    => $updated,
				'references' => self::$rewritten - $before,
				'error'      => true,
				'failed'     => $failed,
				'cause'      => $cause,
			];
		}

		return [
			'next'       => $stopped ? $last : ( count( $rows ) < max( 1, $limit ) ? 0 : (int) array_key_last( $rows ) ),
			'read'       => $read,
			'updated'    => $updated,
			'references' => self::$rewritten - $before,
			'error'      => false,
			'failed'     => 0,
			'cause'      => '',
		];
	}

	/**
	 * Read what the rules read for a page of containers, one batch per kind:
	 * the container posts and their meta, their groups, their originals, and
	 * the menus and patterns they name with those posts' groups and their
	 * translations' posts.
	 *
	 * @param array<int, int>                                                                        $ids     Container ids.
	 * @param array{manager: PostTranslationManager, languages: LanguageRepository, default: string} $context Services.
	 * @return void
	 */
	private static function prime_page( array $ids, array $context ): void {
		$groups = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );

		if ( ! $groups instanceof \PerfLocale\Database\Repository\TranslationGroupRepository ) {
			return;
		}

		$groups->prime_translations( \PerfLocale\Enum\ObjectType::Post, $ids );

		$originals = [];
		$refs      = [];

		foreach ( $ids as $id ) {
			$original = (int) ( $context['manager']->get_translations( $id )[ $context['default'] ] ?? 0 );

			if ( $original > 0 && $original !== $id ) {
				$originals[] = $original;
			}

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			foreach ( self::TARGETS as $block ) {
				$refs = array_merge( $refs, self::block_refs( (string) $post->post_content, $block ) );
			}
		}

		$refs = array_values( array_unique( $refs ) );

		_prime_post_caches( array_merge( $originals, $refs ), false, false );

		if ( [] === $refs ) {
			return;
		}

		$groups->prime_translations( \PerfLocale\Enum\ObjectType::Post, $refs );

		$targets = [];

		foreach ( $refs as $ref ) {
			$targets = array_merge( $targets, array_values( $context['manager']->get_translations( $ref ) ) );
		}

		_prime_post_caches( array_values( array_unique( $targets ) ), false, false );
	}

	/**
	 * Run the rules for the groups of some linked posts.
	 *
	 * @param array<int, int> $ids Linked post ids.
	 * @return void
	 */
	private static function process_links( array $ids ): void {
		$types = self::relevant_types( $ids );

		if ( [] !== $types ) {
			self::process_types( $types );
		}
	}

	/**
	 * Run the rules for the groups of some posts of known type.
	 *
	 * @param array<int, string> $types Post id => post type (a referenced or container type).
	 * @return void
	 */
	private static function process_types( array $types ): void {
		$context = self::context();

		if ( null === $context ) {
			return;
		}

		$seen = [];

		foreach ( $types as $id => $type ) {
			foreach ( $context['manager']->get_translations( $id ) as $slug => $member ) {
				if ( $slug === $context['default'] || isset( $seen[ $member ] ) ) {
					continue;
				}

				$seen[ $member ] = true;
				$post            = get_post( $member );

				if ( ! $post instanceof WP_Post || $post->post_type !== $type ) {
					continue;
				}

				if ( isset( self::TARGETS[ $type ] ) ) {
					self::target_available( $post );
				} else {
					self::localise_container( $post, $slug );
				}
			}
		}
	}

	/**
	 * Point the references to menus and patterns that left a language back:
	 * in the containers of that language, at that language's own member of
	 * the group when it has one the editor opens, otherwise at the group's
	 * default-language member.
	 *
	 * @param array<int, int> $left Post id => the language id it left.
	 * @return void
	 */
	private static function process_left( array $left ): void {
		if ( [] === $left ) {
			return;
		}

		$context = self::context();

		if ( null === $context ) {
			return;
		}

		foreach ( $left as $post_id => $language_id ) {
			$post     = get_post( $post_id );
			$language = $context['languages']->find( $language_id );
			$slug     = is_object( $language ) && isset( $language->slug ) && is_string( $language->slug ) ? $language->slug : '';

			// The default language's containers are the originals: never rewritten.
			if ( ! $post instanceof WP_Post || ! isset( self::TARGETS[ $post->post_type ] ) || '' === $slug || $slug === $context['default'] ) {
				continue;
			}

			$to = self::left_target( $post, $slug, $context );

			if ( $to > 0 ) {
				self::fan_out( 'left', $language_id, $post->post_type, $post->ID, $to );
			}
		}
	}

	/**
	 * Where the references to a menu or pattern that left a language point
	 * in that language's containers: the language's own member of its group
	 * when the editor opens it, otherwise the group's default-language member
	 * when it is not in the trash; 0 when there is neither.
	 *
	 * @param WP_Post                                                                                $post    Menu or pattern that left.
	 * @param string                                                                                 $slug    The language it left.
	 * @param array{manager: PostTranslationManager, languages: LanguageRepository, default: string} $context Services.
	 * @return int
	 */
	private static function left_target( WP_Post $post, string $slug, array $context ): int {
		$translations = $context['manager']->get_translations( $post->ID );
		$own          = (int) ( $translations[ $slug ] ?? 0 );
		$own_post     = $own > 0 && $own !== $post->ID ? get_post( $own ) : null;

		if ( $own_post instanceof WP_Post && self::is_openable( $own_post, $post->post_type ) ) {
			return $own;
		}

		$source = (int) ( $translations[ $context['default'] ] ?? 0 );
		$source = $source > 0 && $source !== $post->ID ? get_post( $source ) : null;

		return $source instanceof WP_Post && $source->post_type === $post->post_type && 'trash' !== $source->post_status ? $source->ID : 0;
	}

	/**
	 * Post type of each id that is a referenced or container type. Posts in
	 * the cache are read there; the rest in one query per QUEUE_LIMIT ids.
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return array<int, string> Post id => post type.
	 */
	private static function relevant_types( array $ids ): array {
		$wanted  = array_merge( array_keys( self::TARGETS ), self::CONTAINERS );
		$types   = [];
		$missing = [];

		foreach ( $ids as $id ) {
			$cached = wp_cache_get( $id, 'posts' );

			if ( is_object( $cached ) && isset( $cached->post_type ) && is_string( $cached->post_type ) ) {
				if ( in_array( $cached->post_type, $wanted, true ) ) {
					$types[ $id ] = $cached->post_type;
				}
			} else {
				$missing[] = $id;
			}
		}

		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		foreach ( array_chunk( $missing, self::QUEUE_LIMIT ) as $chunk ) {
			// Placeholders only: one %d per id and one %s per type.
			$in_ids   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$in_types = implode( ',', array_fill( 0, count( $wanted ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- built from placeholders only; the lists are spread.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM %i WHERE ID IN ({$in_ids}) AND post_type IN ({$in_types})", $wpdb->posts, ...$chunk, ...$wanted ) );

			foreach ( (array) $rows as $row ) {
				if ( isset( $row->ID, $row->post_type ) && is_numeric( $row->ID ) && is_string( $row->post_type ) ) {
					$types[ (int) $row->ID ] = $row->post_type;
				}
			}
		}

		return $types;
	}

	/**
	 * A post is about to be deleted for good (`before_delete_post`, before the
	 * link cleanup removes its language) or moved to the trash
	 * (`wp_trash_post`).
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public static function on_removed( $post_id ): void {
		$post = is_numeric( $post_id ) ? get_post( (int) $post_id ) : null;

		if ( ! $post instanceof WP_Post || ! isset( self::TARGETS[ $post->post_type ] ) || 'trash' === $post->post_status ) {
			return;
		}

		$member = self::translation_member( $post->ID );

		if ( null === $member ) {
			return;
		}

		$source = get_post( $member['source'] );

		if ( ! $source instanceof WP_Post || $source->post_type !== $post->post_type || 'trash' === $source->post_status ) {
			return;
		}

		// The occurrences pointed back, those a user chose included, are
		// recorded in FALLBACK_META as each page writes them.
		self::fan_out( 'back', $member['language_id'], $post->post_type, $post->ID, $source->ID );
	}

	/**
	 * Remember which occurrences a removal pointed back, those a user chose
	 * included: a restore points exactly those at the translation again.
	 * Merged into the translation's FALLBACK_META while it records the same
	 * source; nothing is written for a translation that is gone.
	 *
	 * @param int                         $translation The removed menu or pattern translation.
	 * @param int                         $source      The source the occurrences point at now.
	 * @param array<int, array<int, int>> $written     Container id => the occurrences pointed back.
	 * @return void
	 */
	private static function record_fallback( int $translation, int $source, array $written ): void {
		if ( [] === $written || ! get_post( $translation ) instanceof WP_Post ) {
			return;
		}

		$recorded = self::fallback_record( $translation );
		$merged   = $recorded['source'] === $source ? $recorded['containers'] : [];

		foreach ( $written as $container => $at ) {
			$merged[ $container ] = array_values( array_unique( array_merge( $merged[ $container ] ?? [], $at ) ) );
		}

		update_post_meta(
			$translation,
			self::FALLBACK_META,
			[
				'source'     => $source,
				'containers' => $merged,
			]
		);
	}

	/**
	 * A removed translation's FALLBACK_META, read: its source (0 when it has
	 * none) and container id => the occurrences pointed back.
	 *
	 * @param int $post_id Menu or pattern translation.
	 * @return array{source: int, containers: array<int, array<int, int>>}
	 */
	private static function fallback_record( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::FALLBACK_META, true );
		$out    = [
			'source'     => 0,
			'containers' => [],
		];

		if ( ! is_array( $stored ) ) {
			return $out;
		}

		$out['source'] = is_numeric( $stored['source'] ?? null ) ? (int) $stored['source'] : 0;

		foreach ( is_array( $stored['containers'] ?? null ) ? $stored['containers'] : [] as $container => $at ) {
			if ( is_int( $container ) && $container > 0 && is_array( $at ) ) {
				$out['containers'][ $container ] = array_values( array_map( 'intval', array_filter( $at, 'is_numeric' ) ) );
			}
		}

		return $out;
	}

	/**
	 * A post came back from the trash (`untrashed_post`): the containers of
	 * its language whose original names its source point at it again, and so
	 * do the occurrences its removal pointed back (a reference a user chose
	 * among them), while they still name the source and their container is
	 * still a container of its language. The record is taken container by
	 * container as they are written; it is gone once every recorded container
	 * was done.
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public static function on_restored( $post_id ): void {
		$post = is_numeric( $post_id ) ? get_post( (int) $post_id ) : null;

		if ( ! $post instanceof WP_Post || ! isset( self::TARGETS[ $post->post_type ] ) ) {
			return;
		}

		self::target_available( $post );

		if ( ! metadata_exists( 'post', $post->ID, self::FALLBACK_META ) ) {
			return;
		}

		$recorded = self::fallback_record( $post->ID );
		$source   = $recorded['source'];
		$member   = self::translation_member( $post->ID );

		if ( $source > 0 && null !== $member && self::is_openable( $post, $post->post_type ) ) {
			self::fan_out( 'restore', $member['language_id'], $post->post_type, $source, $post->ID );

			return;
		}

		delete_post_meta( $post->ID, self::FALLBACK_META );
	}

	/**
	 * Rewrite the references to a menu or pattern translation's source in the
	 * containers of its language.
	 *
	 * @param WP_Post $target Menu or pattern translation.
	 * @return void
	 */
	private static function target_available( WP_Post $target ): void {
		if ( ! self::is_openable( $target, $target->post_type ) ) {
			return;
		}

		$member = self::translation_member( $target->ID );

		if ( null === $member || get_post_type( $member['source'] ) !== $target->post_type ) {
			return;
		}

		self::fan_out( 'forward', $member['language_id'], $target->post_type, $member['source'], $target->ID );
	}

	/**
	 * Rewrite a container translation's references to source-language menus
	 * and patterns that have a translation in its language.
	 *
	 * @param WP_Post $container Container translation.
	 * @param string  $lang_slug Its language.
	 * @return bool|null Whether the container was written; null when its
	 *                   write failed ({@see self::write_container()}).
	 */
	private static function localise_container( WP_Post $container, string $lang_slug ): ?bool {
		$content = (string) $container->post_content;

		if ( ! str_contains( $content, '"ref"' ) ) {
			return false;
		}

		$context = self::context();

		if ( null === $context || $lang_slug === $context['default'] ) {
			return false;
		}

		$original = self::original_content( $container->ID, $context );
		$maps     = [];

		foreach ( self::TARGETS as $type => $block ) {
			$from_original = self::block_refs( $original, $block );

			foreach ( self::block_refs( $content, $block ) as $ref ) {
				if ( ! in_array( $ref, $from_original, true ) || get_post_type( $ref ) !== $type ) {
					continue;
				}

				$translations = $context['manager']->get_translations( $ref );

				// Only the source-language one: a reference to any other is one
				// the user chose.
				if ( (int) ( $translations[ $context['default'] ] ?? 0 ) !== $ref ) {
					continue;
				}

				$target = (int) ( $translations[ $lang_slug ] ?? 0 );
				$post   = $target > 0 && $target !== $ref ? get_post( $target ) : null;

				if ( $post instanceof WP_Post && self::is_openable( $post, $type ) ) {
					$maps[ $block ][ $ref ] = $target;
				}
			}
		}

		$changed = [];

		return [] === $maps ? false : self::write_container( $container->ID, $maps, self::not_switched_back( $container->ID, $content ), $changed, $content );
	}

	/**
	 * Apply a menu or pattern event to the containers of one language: the
	 * first SYNC_LIMIT now, the rest in a TemplateRefsFanoutJob that goes on
	 * after the last one read. A page that could not be read, or a container
	 * whose write failed, goes to the job too (from that container), so it
	 * is tried again. Without a job (no user who may run it, or the queue
	 * refused it), this request goes on with the rest itself.
	 *
	 * @param string $kind        One of KINDS.
	 * @param int    $language_id Language of the containers.
	 * @param string $type        wp_navigation or wp_block.
	 * @param int    $from        Post the references name now.
	 * @param int    $to          Post they name afterwards.
	 * @return void
	 */
	private static function fan_out( string $kind, int $language_id, string $type, int $from, int $to ): void {
		$task = [
			'kind'     => $kind,
			'language' => $language_id,
			'type'     => $type,
			'from'     => $from,
			'to'       => $to,
		];

		$page = self::fan_out_page( $task, 0, self::SYNC_LIMIT );

		if ( 0 === $page['next'] && ! $page['error'] ) {
			return;
		}

		if ( self::defer( $task, $page['next'] ) ) {
			return;
		}

		// No job: the rest now, page by page. A container whose write failed
		// is passed over and every other container is still written; a
		// restore keeps it in its record and stops there.
		while ( true ) {
			if ( $page['failed'] > 0 && 'restore' !== $kind ) {
				$after = $page['failed'];
			} elseif ( $page['error'] || 0 === $page['next'] ) {
				return;
			} else {
				$after = $page['next'];
			}

			$page = self::fan_out_page( $task, $after, self::PAGE );
		}
	}

	/**
	 * Queue the rest of a fan-out as a TemplateRefsFanoutJob.
	 *
	 * @param array{kind: string, language: int, type: string, from: int, to: int} $task  The fan-out.
	 * @param int                                                                  $after Last container done (0: none).
	 * @return bool Whether a job holds it now.
	 */
	private static function defer( array $task, int $after ): bool {
		$job = new \PerfLocale\Background\Jobs\TemplateRefsFanoutJob();

		if ( ! current_user_can( $job->get_required_capability() ) ) {
			return false;
		}

		$queued = \PerfLocale\Background\Dispatcher::enqueue(
			$job,
			[
				'task'  => $task,
				'after' => $after,
			]
		);

		return isset( $queued['job_id'] ) && is_string( $queued['job_id'] ) && '' !== $queued['job_id'];
	}

	/**
	 * A fan-out read from a job's args, or null when they do not hold one.
	 *
	 * @param mixed $raw The job's `task` arg.
	 * @return array{kind: string, language: int, type: string, from: int, to: int}|null
	 */
	public static function fan_out_task( mixed $raw ): ?array {
		$kind = is_array( $raw ) ? ( $raw['kind'] ?? null ) : null;
		$type = is_array( $raw ) ? ( $raw['type'] ?? null ) : null;

		if ( ! is_array( $raw ) || ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) || ! is_string( $type ) || ! isset( self::TARGETS[ $type ] ) ) {
			return null;
		}

		$task = [
			'kind'     => $kind,
			'language' => is_numeric( $raw['language'] ?? null ) ? (int) $raw['language'] : 0,
			'type'     => $type,
			'from'     => is_numeric( $raw['from'] ?? null ) ? (int) $raw['from'] : 0,
			'to'       => is_numeric( $raw['to'] ?? null ) ? (int) $raw['to'] : 0,
		];

		return $task['language'] > 0 && $task['from'] > 0 && $task['to'] > 0 && $task['from'] !== $task['to'] ? $task : null;
	}

	/**
	 * One page of a queued fan-out ({@see \PerfLocale\Background\Jobs\TemplateRefsFanoutJob}),
	 * after a check that the event still stands: a forward or restore target
	 * that is still that source's translation in the language and opens in
	 * the editor, a removed translation still in the trash or gone, a menu or
	 * pattern still out of the language it left (its references then point
	 * where they would now). An event that no longer stands is done: nothing
	 * is written, and the containers not reached keep naming what they name.
	 * Without a default language that check cannot be made: the page is an
	 * error with cause 'language' (a removal's way back needs none).
	 *
	 * @param array{kind: string, language: int, type: string, from: int, to: int} $task  The fan-out.
	 * @param int                                                                  $after Last container done, 0 to start.
	 * @param int                                                                  $limit Containers per page.
	 * @param float|null                                                           $until A microtime( true ) deadline, checked after each container. Null: none.
	 * @return array{next: int, read: int, updated: int, references: int, error: bool, failed: int, cause: string, stands: bool}
	 *         As {@see self::fan_out_page()}; stands is false when the event no longer stands (next is 0).
	 */
	public static function fan_out_job_page( array $task, int $after, int $limit, ?float $until = null ): array {
		if ( 'back' !== $task['kind'] && null === self::context() ) {
			return [
				'next'       => $after,
				'read'       => 0,
				'updated'    => 0,
				'references' => 0,
				'error'      => true,
				'failed'     => 0,
				'cause'      => 'language',
				'stands'     => true,
			];
		}

		$task = self::standing_task( $task );

		if ( null === $task ) {
			return [
				'next'       => 0,
				'read'       => 0,
				'updated'    => 0,
				'references' => 0,
				'error'      => false,
				'failed'     => 0,
				'cause'      => '',
				'stands'     => false,
			];
		}

		return self::fan_out_page( $task, $after, max( 1, $limit ), $until ) + [ 'stands' => true ];
	}

	/**
	 * A queued fan-out as it applies now, or null when its event no longer
	 * stands ({@see self::fan_out_job_page()}).
	 *
	 * @param array{kind: string, language: int, type: string, from: int, to: int} $task The fan-out.
	 * @return array{kind: string, language: int, type: string, from: int, to: int}|null
	 */
	private static function standing_task( array $task ): ?array {
		$from = get_post( $task['from'] );
		$to   = get_post( $task['to'] );

		switch ( $task['kind'] ) {
			case 'forward':
			case 'restore':
				$member = $to instanceof WP_Post && self::is_openable( $to, $task['type'] ) ? self::translation_member( $to->ID ) : null;

				if ( null === $member || $member['language_id'] !== $task['language'] || $member['source'] !== $task['from'] ) {
					return null;
				}

				return 'restore' !== $task['kind'] || self::fallback_record( $task['to'] )['source'] === $task['from'] ? $task : null;

			case 'back':
				$gone = ! $from instanceof WP_Post || 'trash' === $from->post_status;

				return $gone && $to instanceof WP_Post && $to->post_type === $task['type'] && 'trash' !== $to->post_status ? $task : null;

			case 'left':
				$context  = self::context();
				$language = null === $context ? null : $context['languages']->find( $task['language'] );
				$slug     = is_object( $language ) && isset( $language->slug ) && is_string( $language->slug ) ? $language->slug : '';

				if ( null === $context || ! $from instanceof WP_Post || $from->post_type !== $task['type'] || '' === $slug || $slug === $context['default'] || (int) ( $context['manager']->get_translations( $from->ID )[ $slug ] ?? 0 ) === $from->ID ) {
					return null;
				}

				$task['to'] = self::left_target( $from, $slug, $context );

				return $task['to'] > 0 ? $task : null;
		}

		return null;
	}

	/**
	 * One page of a fan-out: the containers after one id, in id order, read
	 * in one batch per kind (the posts, their meta, and for a forward
	 * rewrite their groups and originals), then each rewritten with a
	 * compare-and-set that starts from the content read. A `restore` takes
	 * the first containers of the translation's FALLBACK_META instead (the
	 * record is the queue: each container done leaves it, and the record is
	 * deleted once empty; $after is not used), only those still a container
	 * of the language.
	 *
	 * @param array{kind: string, language: int, type: string, from: int, to: int} $task  The fan-out.
	 * @param int                                                                  $after Last container done, 0 to start.
	 * @param int                                                                  $limit Containers per page.
	 * @param float|null                                                           $until A microtime( true ) deadline, checked after each container. Null: none.
	 * @return array{next: int, read: int, updated: int, references: int, error: bool, failed: int, cause: string}
	 *         next is 0 after the last page, otherwise the last container done; error is true when the
	 *         page could not be read (failed 0, next $after, cause 'database' when a read failed,
	 *         'language' when a forward rewrite finds no default language) or a container's write failed
	 *         (failed is its id, cause 'database' or 'conflict' as in {@see self::backfill_page()}; next the
	 *         last container done before it).
	 */
	private static function fan_out_page( array $task, int $after, int $limit, ?float $until = null ): array {
		$out = [
			'next'       => 0,
			'read'       => 0,
			'updated'    => 0,
			'references' => 0,
			'error'      => false,
			'failed'     => 0,
			'cause'      => '',
		];

		$block   = self::TARGETS[ $task['type'] ] ?? '';
		$from    = $task['from'];
		$to      = $task['to'];
		$forward = 'forward' === $task['kind'];
		$restore = 'restore' === $task['kind'];

		if ( '' === $block || $from <= 0 || $to <= 0 || $from === $to || $task['language'] <= 0 ) {
			return $out;
		}

		$context = self::context();
		$record  = $restore ? self::fallback_record( $to ) : null;

		if ( null === $record ) {
			$ids = self::containers_page( $task['language'], $from, $after, $limit );
		} else {
			$ids = array_keys( $record['containers'] );
			sort( $ids );
			$ids = array_slice( $ids, 0, $limit );
		}

		if ( null === $ids || ( $forward && null === $context ) ) {
			$out['next']  = $after;
			$out['error'] = true;
			$out['cause'] = null === $ids ? 'database' : 'language';

			return $out;
		}

		// A recorded container is replayed only while it is still a container
		// of the language (never an original, never one that moved to another
		// language): the others leave the record unwritten.
		$live = null === $record ? null : self::containers_page( $task['language'], $from, 0, max( 1, count( $ids ) ), $ids );

		if ( null !== $record && null === $live ) {
			$out['error'] = true;
			$out['cause'] = 'database';

			return $out;
		}

		$live = null === $live ? null : array_flip( $live );

		self::prime_containers( $ids, $forward ? $context : null );

		$before  = self::$rewritten;
		$last    = $after;
		$done    = [];
		$written = [];
		$stopped = false;

		foreach ( $ids as $id ) {
			$post    = get_post( $id );
			$content = $post instanceof WP_Post ? (string) $post->post_content : null;
			$allow   = null;

			if ( null !== $record ) {
				$at    = array_flip( $record['containers'][ $id ] ?? [] );
				$allow = static fn( string $name, int $occurrence ): bool => isset( $at[ $occurrence ] );
			} elseif ( $forward ) {
				$allow = self::not_switched_back( $id, (string) $content );
			}

			$skip = null === $content
				|| ( null !== $live && ! isset( $live[ $id ] ) )
				|| ( $forward && ! in_array( $from, self::block_refs( self::original_content( $id, $context ), $block ), true ) );

			$changed = [];
			$result  = $skip ? false : self::write_container( $id, [ $block => [ $from => $to ] ], $allow, $changed, $content );

			if ( null === $result ) {
				$out['failed'] = $id;
				$out['cause']  = self::$write_failure;
				break;
			}

			if ( true === $result ) {
				++$out['updated'];
				$written[ $id ] = $changed[ $block ] ?? [];
			}

			++$out['read'];
			$last   = $id;
			$done[] = $id;

			if ( null !== $until && microtime( true ) >= $until && $out['read'] < count( $ids ) ) {
				$stopped = true;
				break;
			}
		}

		$out['references'] = self::$rewritten - $before;

		if ( 'back' === $task['kind'] ) {
			self::record_fallback( $from, $to, $written );
		}

		if ( null !== $record ) {
			$left = self::take_from_record( $to, $done );

			$out['next'] = $left > 0 ? max( 1, $last ) : 0;
		} else {
			$out['next'] = $out['failed'] > 0 || $stopped ? $last : ( count( $ids ) < $limit ? 0 : (int) end( $ids ) );
		}

		if ( $out['failed'] > 0 ) {
			$out['error'] = true;
		}

		return $out;
	}

	/**
	 * Drop the containers a restore did from a translation's FALLBACK_META,
	 * read again first (a removal may have added to it meanwhile); the meta is
	 * deleted once no container is left.
	 *
	 * @param int             $translation The restored menu or pattern translation.
	 * @param array<int, int> $done        Containers done.
	 * @return int Containers left in the record.
	 */
	private static function take_from_record( int $translation, array $done ): int {
		$recorded = self::fallback_record( $translation );

		foreach ( $done as $container ) {
			unset( $recorded['containers'][ $container ] );
		}

		if ( [] === $recorded['containers'] ) {
			delete_post_meta( $translation, self::FALLBACK_META );

			return 0;
		}

		if ( [] !== $done ) {
			update_post_meta(
				$translation,
				self::FALLBACK_META,
				[
					'source'     => $recorded['source'],
					'containers' => $recorded['containers'],
				]
			);
		}

		return count( $recorded['containers'] );
	}

	/**
	 * Read what a page of a fan-out reads, one batch per kind: the container
	 * posts and their meta (the record of what the rules wrote), and for a
	 * forward rewrite their groups and their originals.
	 *
	 * @param array<int, int>                                                                             $ids     Container ids.
	 * @param array{manager: PostTranslationManager, languages: LanguageRepository, default: string}|null $context Services, for a forward rewrite; null otherwise.
	 * @return void
	 */
	private static function prime_containers( array $ids, ?array $context ): void {
		if ( [] === $ids ) {
			return;
		}

		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );

		$groups = null === $context ? null : \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		if ( null === $context || ! $groups instanceof \PerfLocale\Database\Repository\TranslationGroupRepository ) {
			return;
		}

		$groups->prime_translations( \PerfLocale\Enum\ObjectType::Post, $ids );

		$originals = [];

		foreach ( $ids as $id ) {
			$original = (int) ( $context['manager']->get_translations( $id )[ $context['default'] ] ?? 0 );

			if ( $original > 0 && $original !== $id ) {
				$originals[] = $original;
			}
		}

		if ( [] !== $originals ) {
			_prime_post_caches( $originals, false, false );
		}
	}

	/**
	 * The containers linked in a language, not trashed, whose content may
	 * name an id: those after one id, in id order (keyset paging), or those
	 * among some ids. Null when they could not be read.
	 *
	 * @param int                  $language_id Language of the containers.
	 * @param int                  $ref         Menu or pattern id.
	 * @param int                  $after       Last id read, 0 to start.
	 * @param int                  $limit       Rows to read.
	 * @param array<int, int>|null $among       Only these ids; null: any.
	 * @return array<int, int>|null Container ids.
	 */
	private static function containers_page( int $language_id, int $ref, int $after, int $limit, ?array $among = null ): ?array {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		if ( [] === $among ) {
			return [];
		}

		// The two LIKEs narrow the rows read to content holding a `ref` and
		// the id's digits, in any form the block parser reads (`"ref":7`,
		// `"ref": 7`, `"ref":"7"`); the rewrite matches the number exactly,
		// so `"ref":7` never rewrites `"ref":70`.
		$args = [ $wpdb->posts, $wpdb->prefix . 'perflocale_translation_links', $language_id, $after, self::CONTAINERS[0], self::CONTAINERS[1], '%' . $wpdb->esc_like( '"ref"' ) . '%', '%' . $wpdb->esc_like( (string) $ref ) . '%' ];

		if ( null === $among ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the arguments are spread.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id = %d WHERE p.ID > %d AND p.post_type IN ( %s, %s ) AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND p.post_content LIKE %s AND p.post_content LIKE %s ORDER BY p.ID LIMIT %d", ...array_merge( $args, [ max( 1, $limit ) ] ) ) );
		} else {
			// Placeholders only: one %d per id.
			$in_ids = implode( ',', array_fill( 0, count( $among ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- built from placeholders only; the id list is spread.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p INNER JOIN %i l ON l.object_id = p.ID AND l.type = 'post' AND l.language_id = %d WHERE p.ID > %d AND p.post_type IN ( %s, %s ) AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND p.post_content LIKE %s AND p.post_content LIKE %s AND p.ID IN ({$in_ids}) ORDER BY p.ID LIMIT %d", ...array_merge( $args, array_map( 'intval', $among ), [ max( 1, $limit ) ] ) ) );
		}

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return array_values( array_map( 'intval', array_filter( $ids, 'is_numeric' ) ) );
	}

	/**
	 * Store a container's content with its references mapped, compare-and-set
	 * on the content the rewrite was computed from.
	 *
	 * @param int                                     $id      Container id.
	 * @param array<string, array<int, int>>          $maps    Block name => ( post id => post id ).
	 * @param (callable(string, int, int): bool)|null $allow   Whether an occurrence (block name,
	 *                                                         occurrence, the id it names) may be
	 *                                                         rewritten; null: every one.
	 * @param array<string, array<int, int>>          $changed Set to block name => the occurrences the write changed.
	 * @param string|null                             $known   The container's content as the caller just read it:
	 *                                                         the first compare-and-set starts from it, and the row
	 *                                                         is read again only after a lost comparison. Null: read
	 *                                                         it first.
	 * @return bool|null True when the row was written, false when there was
	 *                   nothing to write, null when a read or the write failed
	 *                   or every compare-and-set attempt lost to another writer
	 *                   (self::$write_failure says which).
	 */
	private static function write_container( int $id, array $maps, ?callable $allow = null, array &$changed = [], ?string $known = null ): ?bool {
		$changed             = [];
		self::$write_failure = '';

		if ( ! self::$system && is_user_logged_in() && ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}

		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			if ( 0 === $attempt && null !== $known ) {
				$current = $known;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$current = $wpdb->get_var( $wpdb->prepare( 'SELECT post_content FROM %i WHERE ID = %d', $wpdb->posts, $id ) );

				if ( '' !== $wpdb->last_error ) {
					self::$write_failure = 'database';
					return null;
				}
			}

			if ( ! is_string( $current ) ) {
				return false;
			}

			$rewritten = self::rewrite_refs( $current, $maps, $allow );

			if ( $rewritten === $current ) {
				return false;
			}

			// MD5 of the stored bytes: the column's collation would also match
			// content that differs only in case.
			$sql = $wpdb->prepare( 'UPDATE %i SET post_content = %s WHERE ID = %d AND MD5(post_content) = %s', $wpdb->posts, $rewritten, $id, md5( $current ) );

			if ( ! is_string( $sql ) ) {
				self::$write_failure = 'database';
				return null;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			$rows = $wpdb->query( $sql );

			if ( 1 === $rows ) {
				self::$rewritten += self::changed_refs( $current, $rewritten );
				// The record first, while the post and its meta are still cached.
				$changed = self::record_rewrite( $id, $current, $rewritten );
				clean_post_cache( $id );
				return true;
			}

			if ( false === $rows ) {
				self::$write_failure = 'database';
				return null;
			}
		}

		self::$write_failure = 'conflict';

		return null;
	}

	/**
	 * The forward rewrites' filter for one container: an occurrence its user
	 * switched back to the source is not rewritten ({@see self::switched_back()}),
	 * nor an occurrence without an entry that names a source another
	 * occurrence was switched back to (a block its user added). The record is
	 * matched to the content first ({@see self::matched()}).
	 *
	 * @param int    $container Container id.
	 * @param string $content   Its content, as the rewrite reads it.
	 * @return callable(string, int, int): bool
	 */
	private static function not_switched_back( int $container, string $content ): callable {
		$record = self::rewritten_record( $container );
		$back   = [];

		foreach ( self::TARGETS as $block ) {
			$names            = self::occurrences( $content, $block );
			$record[ $block ] = self::matched( $record[ $block ] ?? [], $names );

			foreach ( $record[ $block ] as $occurrence => $entry ) {
				if ( self::switched_back( $record, $block, $occurrence, $names[ $occurrence ] ?? 0 ) ) {
					$back[ $block ][ $entry[0] ] = true;
				}
			}
		}

		return static fn( string $block, int $occurrence, int $ref ): bool => isset( $record[ $block ][ $occurrence ] )
			? ! self::switched_back( $record, $block, $occurrence, $ref )
			: ! isset( $back[ $block ][ $ref ] );
	}

	/**
	 * The entries of one block name matched to the content: an entry whose
	 * occurrence names neither of its ids moves to the first occurrence
	 * without an entry that names one of them (blocks added or removed
	 * before it moved it), and is dropped when there is none. An entry on
	 * an occurrence naming the id it wrote moves to an occurrence without an
	 * entry that names its source, when there is one.
	 *
	 * @param array<int, array{0: int, 1: int}> $entries Occurrence => [ source, written ].
	 * @param array<int, int>                   $names   Occurrence => the id it names.
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function matched( array $entries, array $names ): array {
		$kept  = [];
		$moved = [];

		foreach ( $entries as $occurrence => $entry ) {
			$named = $names[ $occurrence ] ?? 0;

			if ( $named === $entry[0] || $named === $entry[1] ) {
				$kept[ $occurrence ] = $entry;
			} else {
				$moved[] = $entry;
			}
		}

		foreach ( $moved as $entry ) {
			foreach ( $names as $occurrence => $named ) {
				if ( ! isset( $kept[ $occurrence ] ) && ( $named === $entry[0] || $named === $entry[1] ) ) {
					$kept[ $occurrence ] = $entry;
					break;
				}
			}
		}

		// An entry on an occurrence naming the id it wrote, while an
		// occurrence without an entry names its source: the editor may have
		// switched that block back and added one naming the written id. The
		// entry moves to the source occurrence, so that one is not rewritten.
		foreach ( $kept as $occurrence => $entry ) {
			if ( $entry[0] === $entry[1] || ( $names[ $occurrence ] ?? 0 ) !== $entry[1] ) {
				continue;
			}

			foreach ( $names as $other => $named ) {
				if ( $named === $entry[0] && ! isset( $kept[ $other ] ) ) {
					unset( $kept[ $occurrence ] );
					$kept[ $other ] = $entry;
					break;
				}
			}
		}

		ksort( $kept );

		return $kept;
	}

	/**
	 * Whether a container's user switched an occurrence back to the source:
	 * it names $source while its record says these rules wrote another id
	 * there.
	 *
	 * @param array<string, array<int, array{0: int, 1: int}>> $record     The container's REWRITTEN_META, read.
	 * @param string                                           $block      Block name without namespace.
	 * @param int                                              $occurrence Occurrence.
	 * @param int                                              $source     Source-language menu or pattern it names.
	 * @return bool
	 */
	private static function switched_back( array $record, string $block, int $occurrence, int $source ): bool {
		$entry = $record[ $block ][ $occurrence ] ?? null;

		return null !== $entry && $entry[0] === $source && $entry[1] !== $source;
	}

	/**
	 * A container's REWRITTEN_META, read: block name => ( occurrence =>
	 * [ source, written ] ). Anything else stored is left out.
	 *
	 * @param int $id Container id.
	 * @return array<string, array<int, array{0: int, 1: int}>>
	 */
	private static function rewritten_record( int $id ): array {
		$stored = get_post_meta( $id, self::REWRITTEN_META, true );
		$out    = [];

		foreach ( self::TARGETS as $block ) {
			$entries = is_array( $stored ) && is_array( $stored[ $block ] ?? null ) ? $stored[ $block ] : [];

			foreach ( $entries as $occurrence => $entry ) {
				if ( is_int( $occurrence ) && $occurrence >= 0 && is_array( $entry ) && is_numeric( $entry[0] ?? null ) && is_numeric( $entry[1] ?? null ) ) {
					$out[ $block ][ $occurrence ] = [ (int) $entry[0], (int) $entry[1] ];
				}
			}
		}

		return $out;
	}

	/**
	 * Note what a write put in each occurrence it changed: [ the group's
	 * source-language member, the id written ]. An entry whose occurrence
	 * names neither any more is dropped.
	 *
	 * @param int    $id     Container id.
	 * @param string $before The content the write replaced.
	 * @param string $after  The content written.
	 * @return array<string, array<int, int>> Block name => the occurrences the write changed.
	 */
	private static function record_rewrite( int $id, string $before, string $after ): array {
		$context = self::context();
		$stored  = metadata_exists( 'post', $id, self::REWRITTEN_META );
		$record  = self::rewritten_record( $id );
		$read    = $record;
		$changed = [];

		foreach ( self::TARGETS as $block ) {
			$was = self::occurrences( $before, $block );
			$now = self::occurrences( $after, $block );

			$record[ $block ] = self::matched( $record[ $block ] ?? [], $was );

			foreach ( $now as $occurrence => $to ) {
				$from = $was[ $occurrence ] ?? $to;

				if ( $from === $to ) {
					continue;
				}

				$changed[ $block ][] = $occurrence;
				$source              = null === $context ? 0 : (int) ( $context['manager']->get_translations( $to )[ $context['default'] ] ?? 0 );

				$record[ $block ][ $occurrence ] = [ $source > 0 ? $source : $from, $to ];
			}

			$kept = [];

			foreach ( $record[ $block ] as $occurrence => $entry ) {
				$named = $now[ $occurrence ] ?? 0;

				if ( $named === $entry[0] || $named === $entry[1] ) {
					$kept[ $occurrence ] = $entry;
				}
			}

			ksort( $kept );
			$kept = array_slice( $kept, 0, self::RECORD_LIMIT, true );

			if ( [] === $kept ) {
				unset( $record[ $block ] );
			} else {
				$record[ $block ] = $kept;
			}
		}

		if ( $record === $read && ( [] !== $record || ! $stored ) ) {
			return $changed;
		}

		if ( [] === $record ) {
			delete_post_meta( $id, self::REWRITTEN_META );
		} elseif ( $stored ) {
			update_post_meta( $id, self::REWRITTEN_META, $record );
		} else {
			// No row yet (read above): one INSERT, without update_post_meta()'s lookup.
			add_post_meta( $id, self::REWRITTEN_META, $record );
		}

		return $changed;
	}

	/**
	 * How many references differ between two versions of a container.
	 *
	 * @param string $before Content before.
	 * @param string $after  Content after.
	 * @return int
	 */
	private static function changed_refs( string $before, string $after ): int {
		$changed = 0;

		foreach ( self::TARGETS as $block ) {
			$changed += count( array_diff( self::block_refs( $before, $block ), self::block_refs( $after, $block ) ) );
		}

		return $changed;
	}

	/**
	 * The ids named by the `ref` of the core blocks of one name in some block
	 * markup.
	 *
	 * @param string $content Block markup.
	 * @param string $block   Block name without namespace: navigation or block.
	 * @return array<int, int>
	 */
	public static function block_refs( string $content, string $block ): array {
		$refs = [];

		if ( ! str_contains( $content, '"ref"' ) || ! preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER ) ) {
			return $refs;
		}

		foreach ( $matches as $token ) {
			$ref = self::token_ref( $token, $block );

			if ( $ref > 0 ) {
				$refs[ $ref ] = $ref;
			}
		}

		return array_values( $refs );
	}

	/**
	 * The id named by each `ref` of the core blocks of one name in some block
	 * markup, in document order: occurrence (0, 1, ...) => id.
	 *
	 * @param string $content Block markup.
	 * @param string $block   Block name without namespace: navigation or block.
	 * @return array<int, int>
	 */
	private static function occurrences( string $content, string $block ): array {
		$refs = [];

		if ( ! str_contains( $content, '"ref"' ) || ! preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER ) ) {
			return $refs;
		}

		foreach ( $matches as $token ) {
			$ref = self::token_ref( $token, $block );

			if ( $ref > 0 ) {
				$refs[] = $ref;
			}
		}

		return $refs;
	}

	/**
	 * Block markup with the `ref` of each core block that names a key of its
	 * map replaced by the value. Every other byte is kept.
	 *
	 * A block whose attributes hold "ref" more than once (nested in another
	 * attribute) is left as it is: the top-level one cannot be told apart
	 * without re-encoding the attributes.
	 *
	 * @param string                                  $content Block markup.
	 * @param array<string, array<int, int>>          $maps    Block name => ( post id => post id ).
	 * @param (callable(string, int, int): bool)|null $allow   Whether an occurrence (block name,
	 *                                                         occurrence as {@see self::occurrences()}
	 *                                                         counts it, the id it names) may be
	 *                                                         rewritten; null: every one.
	 * @return string
	 */
	public static function rewrite_refs( string $content, array $maps, ?callable $allow = null ): string {
		if ( [] === $maps || ! str_contains( $content, '"ref"' ) ) {
			return $content;
		}

		$seen = [];
		$out  = preg_replace_callback(
			self::DELIMITER,
			static function ( array $token ) use ( $maps, $allow, &$seen ): string {
				$found = (string) ( $token['attrs'] ?? '' );

				foreach ( $maps as $block => $map ) {
					$ref = self::token_ref( $token, (string) $block );

					if ( $ref <= 0 ) {
						continue;
					}

					$occurrence     = $seen[ $block ] ?? 0;
					$seen[ $block ] = $occurrence + 1;

					if ( ! isset( $map[ $ref ] ) || substr_count( $found, '"ref"' ) !== 1 || ( null !== $allow && ! $allow( (string) $block, $occurrence, $ref ) ) ) {
						continue;
					}

					$attrs = (string) preg_replace( '/("ref"\s*:\s*)("?)' . $ref . '\2(?![0-9])/', '${1}' . (int) $map[ $ref ], $found, 1 );

					return str_replace( $found, $attrs, $token[0] );
				}

				return $token[0];
			},
			$content
		);

		return is_string( $out ) ? $out : $content;
	}

	/**
	 * The `ref` of one block delimiter match, or 0 when it is not an opening
	 * delimiter of that core block with a numeric top-level `ref`.
	 *
	 * @param array<int|string, string> $token Delimiter match.
	 * @param string                    $block Block name without namespace.
	 * @return int
	 */
	private static function token_ref( array $token, string $block ): int {
		if ( '' !== ( $token['closer'] ?? '' ) || $block !== ( $token['name'] ?? '' ) ) {
			return 0;
		}

		$namespace = (string) ( $token['namespace'] ?? '' );

		if ( '' !== $namespace && 'core/' !== $namespace ) {
			return 0;
		}

		$attrs = json_decode( (string) ( $token['attrs'] ?? '' ), true );
		$ref   = is_array( $attrs ) ? ( $attrs['ref'] ?? null ) : null;

		return is_int( $ref ) || ( is_string( $ref ) && \PerfLocale\Helper::is_ascii_digits( $ref ) ) ? (int) $ref : 0;
	}

	/**
	 * Whether the block editor opens this menu or pattern.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $type Referenced post type it must be.
	 * @return bool
	 */
	private static function is_openable( WP_Post $post, string $type ): bool {
		return $post->post_type === $type && in_array( $post->post_status, self::OPENABLE[ $type ] ?? [], true );
	}

	/**
	 * The content of a container translation's original (its group's
	 * default-language member), or '' when it has none.
	 *
	 * @param int                                                                                    $container_id Container translation.
	 * @param array{manager: PostTranslationManager, languages: LanguageRepository, default: string} $context      Services.
	 * @return string
	 */
	private static function original_content( int $container_id, array $context ): string {
		$translations = $context['manager']->get_translations( $container_id );
		$original_id  = (int) ( $translations[ $context['default'] ] ?? 0 );
		$original     = $original_id > 0 && $original_id !== $container_id ? get_post( $original_id ) : null;

		return $original instanceof WP_Post ? (string) $original->post_content : '';
	}

	/**
	 * For a post linked in a language other than the default: that language's
	 * id and the group's default-language member.
	 *
	 * @param int $post_id Post id.
	 * @return array{language_id: int, source: int}|null
	 */
	private static function translation_member( int $post_id ): ?array {
		$context = self::context();

		if ( null === $context ) {
			return null;
		}

		$translations = $context['manager']->get_translations( $post_id );
		$own          = array_search( $post_id, $translations, true );
		$source       = (int) ( $translations[ $context['default'] ] ?? 0 );

		if ( ! is_string( $own ) || $own === $context['default'] || $source <= 0 || $source === $post_id ) {
			return null;
		}

		$language = $context['languages']->find_by_slug( $own );

		if ( ! is_object( $language ) || ! isset( $language->id ) || ! is_numeric( $language->id ) || (int) $language->id <= 0 ) {
			return null;
		}

		return [
			'language_id' => (int) $language->id,
			'source'      => $source,
		];
	}

	/**
	 * The services the rules read, or null when the plugin is not set up.
	 *
	 * @return array{manager: PostTranslationManager, languages: LanguageRepository, default: string}|null
	 */
	private static function context(): ?array {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) || ! $plugin->has( 'lang_repo' ) ) {
			return null;
		}

		$settings  = $plugin->get( 'settings' );
		$cache     = $plugin->get( 'cache' );
		$languages = $plugin->get( 'lang_repo' );

		if ( ! $settings instanceof Settings || ! $cache instanceof CacheManager || ! $languages instanceof LanguageRepository ) {
			return null;
		}

		$default = $languages->get_default();
		$slug    = is_object( $default ) && isset( $default->slug ) && is_string( $default->slug ) ? $default->slug : '';

		if ( '' === $slug ) {
			return null;
		}

		return [
			'manager'   => new PostTranslationManager( $cache, $settings ),
			'languages' => $languages,
			'default'   => $slug,
		];
	}
}
