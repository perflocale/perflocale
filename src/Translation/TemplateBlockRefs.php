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
 * Points the menu and pattern refs of stored translated templates at that language's translations.
 *
 * The Site Editor edits the stored template, so a render-time swap alone would let
 * "Header (de)" edit the original menu. A reference the user changed or switched back
 * is never rewritten: see docs-ai/11-code-rationale.md, "TemplateBlockRefs: the rules".
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
	 * Post meta of a removed menu or pattern translation: its source, the block
	 * occurrences its removal pointed back per container, and `blocks`, which finds
	 * them again after they moved. Its restore points exactly those again.
	 */
	public const FALLBACK_META = '_perflocale_ref_fallback';

	/**
	 * Post meta of a container translation: block name => ( occurrence => [ source
	 * member, id written ] ) for each occurrence these rules wrote. An occurrence
	 * naming the source where its entry says another id was written was switched back.
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
	 * Kinds of fan-out over one language's containers: `forward` (source to its
	 * translation), `back` (removed translation to its source, recorded), `left`
	 * (to the language's own member or the source), `restore` (the recorded ones again).
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
	 * Post types a save hook passed for posts about to be linked, by blog: post
	 * id => post type. An entry goes when the post's cache is cleaned, since its
	 * type may have changed.
	 *
	 * @var array<int, array<int, string>>
	 */
	private static array $known_types = [];

	/**
	 * Whether forget_known_type() is attached to `clean_post_cache`.
	 *
	 * @var bool
	 */
	private static bool $forget_attached = false;

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
	 * The type of a post that is about to be linked, from the post object a
	 * save hook passed ({@see \PerfLocale\Bootstrap::auto_assign_default_language()}),
	 * so the end-of-request rules do not read the post again to learn it.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Its type.
	 * @return void
	 */
	public static function note_known_type( int $post_id, string $post_type ): void {
		if ( $post_id <= 0 || '' === $post_type ) {
			return;
		}

		$blog = get_current_blog_id();

		if ( count( self::$known_types[ $blog ] ?? [] ) >= self::QUEUE_LIMIT ) {
			self::$known_types[ $blog ] = [];
		}

		self::$known_types[ $blog ][ $post_id ] = $post_type;

		if ( ! self::$forget_attached ) {
			self::$forget_attached = true;
			add_action( 'clean_post_cache', [ self::class, 'forget_known_type' ], 10, 1 );
		}
	}

	/**
	 * `clean_post_cache`: a post's cached row was dropped, so the type noted
	 * for it may be stale; relevant_types() reads it again.
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public static function forget_known_type( $post_id ): void {
		if ( is_numeric( $post_id ) ) {
			unset( self::$known_types[ get_current_blog_id() ][ (int) $post_id ] );
		}
	}

	/**
	 * A non-user write (machine translation, XLIFF import) replaced a template
	 * translation's content: its rewrite record does not describe it, so it goes
	 * and the forward rules run on the new content, as for a new translation.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function content_replaced( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::CONTAINERS, true ) ) {
			return;
		}

		if ( ! self::$system && is_user_logged_in() && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		delete_post_meta( $post_id, self::REWRITTEN_META );

		$context = self::context();
		$slug    = null === $context ? false : array_search( $post_id, $context['manager']->get_translations( $post_id ), true );

		if ( is_string( $slug ) ) {
			self::localise_container( $post, $slug );
		}
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
	 * Noted like a link; a menu or pattern also has the references to it in the
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
	 * Of some restored post ids, the linked menus, patterns, templates and parts:
	 * one query per QUEUE_LIMIT ids. A paged restore merges the pages and calls
	 * process_restored() once.
	 *
	 * @param array<int, int> $post_ids Post ids the restore linked.
	 * @return array<int, string> Post id => post type.
	 */
	public static function restored_types( array $post_ids ): array {
		if ( [] === $post_ids ) {
			return [];
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

		return $types;
	}

	/**
	 * Run the rules once for restored posts of known type
	 * ({@see self::restored_types()}). A failure leaves the references as
	 * they were, which the Site Editor notice shows.
	 *
	 * @param array<int, string> $types Post id => post type.
	 * @return void
	 */
	public static function process_restored( array $types ): void {
		if ( [] === $types ) {
			return;
		}

		try {
			self::process_types( $types );
		} catch ( \Throwable $e ) {
			// The restore itself is written.
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
		$known       = self::$known_types;
		self::$queue = [];
		self::$left  = [];

		self::$known_types = [];

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
				self::process_links( $ids, $known[ $blog ] ?? [] );
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
	 * The container translations the upgrade backfill reads after one id, in id
	 * order: non-default, not trashed, content holding a `ref`. Null when they
	 * could not be read: "could not read" is never "nothing to read".
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
	 * One page of the upgrade backfill: localise_container() for existing container translations.
	 *
	 * Runs for every container whoever the current user is: a rewrite only points a
	 * reference at the same group's member in the container's language, which the
	 * page already renders.
	 *
	 * @param int        $after Last container id read, 0 to start.
	 * @param int        $limit Containers per page.
	 * @param float|null $until A microtime( true ) deadline, checked after each container; null: none.
	 * @return array{next: int, read: int, updated: int, references: int, error: bool, failed: int, cause: string} next
	 *         is 0 after the last page; on a failed write, failed is its id and cause 'database' or 'conflict'.
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

		// One batch read per kind for the page instead of reads per container.
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
	 * posts, meta, groups, originals, and the menus and patterns they name.
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
	 * @param array<int, int>    $ids   Linked post ids.
	 * @param array<int, string> $known Post id => type a save hook passed ({@see self::note_known_type()}).
	 * @return void
	 */
	private static function process_links( array $ids, array $known = [] ): void {
		$types = self::relevant_types( $ids, $known );

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
	 * Point the references to menus and patterns that left a language back, in
	 * that language's containers ({@see self::left_target()}).
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
	 * Post type of each id that is a referenced or container type. A type a
	 * save hook passed is used as it is; posts in the cache are read there;
	 * the rest in one query per QUEUE_LIMIT ids.
	 *
	 * @param array<int, int>    $ids   Post ids.
	 * @param array<int, string> $known Post id => type a save hook passed.
	 * @return array<int, string> Post id => post type.
	 */
	private static function relevant_types( array $ids, array $known = [] ): array {
		$wanted  = array_merge( array_keys( self::TARGETS ), self::CONTAINERS );
		$types   = [];
		$missing = [];

		foreach ( $ids as $id ) {
			if ( isset( $known[ $id ] ) ) {
				if ( in_array( $known[ $id ], $wanted, true ) ) {
					$types[ $id ] = $known[ $id ];
				}

				continue;
			}

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
	 * included, merged into FALLBACK_META while it records the same source.
	 *
	 * @param int                                                       $translation The removed menu or pattern translation.
	 * @param int                                                       $source      The source the occurrences point at now.
	 * @param array<int, array<int, int>>                               $written     Container id => the occurrences pointed back.
	 * @param array<int, array{sum: string, fp: array<int, string>}> $identity    Container id => its block identity as written.
	 * @return void
	 */
	private static function record_fallback( int $translation, int $source, array $written, array $identity = [] ): void {
		if ( [] === $written || ! get_post( $translation ) instanceof WP_Post ) {
			return;
		}

		$recorded = self::fallback_record( $translation );
		$same     = $recorded['source'] === $source;
		$merged   = $same ? $recorded['containers'] : [];
		$blocks   = $same ? $recorded['blocks'] : [];

		foreach ( $written as $container => $at ) {
			$merged[ $container ] = array_values( array_unique( array_merge( $merged[ $container ] ?? [], $at ) ) );

			if ( isset( $identity[ $container ] ) ) {
				$blocks[ $container ] = $identity[ $container ];
			}
		}

		update_post_meta(
			$translation,
			self::FALLBACK_META,
			[
				'source'     => $source,
				'containers' => $merged,
				'blocks'     => $blocks,
			]
		);
	}

	/**
	 * A removed translation's FALLBACK_META, read: its source (0 when it has
	 * none) and container id => the occurrences pointed back.
	 *
	 * @param int $post_id Menu or pattern translation.
	 * @return array{source: int, containers: array<int, array<int, int>>, blocks: array<int, array{sum: string, fp: array<int, string>}>}
	 */
	private static function fallback_record( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::FALLBACK_META, true );
		$out    = [
			'source'     => 0,
			'containers' => [],
			'blocks'     => [],
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

		foreach ( is_array( $stored['blocks'] ?? null ) ? $stored['blocks'] : [] as $container => $identity ) {
			if ( ! is_int( $container ) || ! is_array( $identity ) || ! is_string( $identity['sum'] ?? null ) || ! is_array( $identity['fp'] ?? null ) ) {
				continue;
			}

			$fp = [];

			foreach ( $identity['fp'] as $occurrence => $value ) {
				if ( is_int( $occurrence ) && is_string( $value ) ) {
					$fp[ $occurrence ] = $value;
				}
			}

			$out['blocks'][ $container ] = [
				'sum' => $identity['sum'],
				'fp'  => $fp,
			];
		}

		return $out;
	}

	/**
	 * A post came back from the trash (`untrashed_post`): forward rules, then the
	 * occurrences its removal pointed back, while they still name the source.
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
	 * Apply a menu or pattern event to the containers of one language: the first
	 * SYNC_LIMIT now, the rest (and any failed page) in a TemplateRefsFanoutJob, or
	 * in this request when no job can be queued.
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
	 * One page of a queued fan-out, after a check that its event still stands
	 * (self::standing_task()); an event that does not is done with nothing written.
	 * Without a default language the page is an error with cause 'language'.
	 *
	 * @param array{kind: string, language: int, type: string, from: int, to: int} $task  The fan-out.
	 * @param int                                                                  $after Last container done, 0 to start.
	 * @param int                                                                  $limit Containers per page.
	 * @param float|null                                                           $until A microtime( true ) deadline, checked after each container. Null: none.
	 * @return array{next: int, read: int, updated: int, references: int, error: bool, failed: int, cause: string, stands: bool}
	 *         As {@see self::fan_out_page()}; stands is false when the event does not stand (next is 0).
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
	 * A queued fan-out as it applies now, or null when its event does not stand:
	 * the target is not that source's openable translation, the removed one is back,
	 * or the menu or pattern returned to the language it left.
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
	 * One page of a fan-out: the containers after one id, in id order, each rewritten by compare-and-set.
	 *
	 * A `restore` reads the translation's FALLBACK_META instead, which is its queue:
	 * each container done leaves it, and $after is not used.
	 *
	 * @param array{kind: string, language: int, type: string, from: int, to: int} $task  The fan-out.
	 * @param int                                                                  $after Last container done, 0 to start.
	 * @param int                                                                  $limit Containers per page.
	 * @param float|null                                                           $until A microtime( true ) deadline, checked after each container. Null: none.
	 * @return array{next: int, read: int, updated: int, references: int, error: bool, failed: int, cause: string}
	 *         next is 0 after the last page; cause 'database', 'conflict' or 'language' on an error.
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

		// A recorded container is replayed only while it is still a container of
		// the language (never an original, never one that moved language).
		$live = null === $record ? null : self::containers_page( $task['language'], $from, 0, max( 1, count( $ids ) ), $ids );

		if ( null !== $record && null === $live ) {
			$out['error'] = true;
			$out['cause'] = 'database';

			return $out;
		}

		$live = null === $live ? null : array_flip( $live );

		self::prime_containers( $ids, $forward ? $context : null );

		$before   = self::$rewritten;
		$last     = $after;
		$done     = [];
		$written  = [];
		$identity = [];
		$stopped  = false;

		foreach ( $ids as $id ) {
			$post    = get_post( $id );
			$content = $post instanceof WP_Post ? (string) $post->post_content : null;
			$allow   = null;

			if ( null !== $record ) {
				$at    = self::restore_allowed( $record, $id, (string) $content, $block );
				$allow = static fn( string $name, int $occurrence ): bool => isset( $at[ $occurrence ] );
			} elseif ( $forward ) {
				$allow = self::not_switched_back( $id, (string) $content );
			}

			$skip = null === $content
				|| ( null !== $live && ! isset( $live[ $id ] ) )
				|| ( $forward && ! in_array( $from, self::block_refs( self::original_content( $id, $context ), $block ), true ) );

			$changed = [];
			$stored  = '';
			$result  = $skip ? false : self::write_container( $id, [ $block => [ $from => $to ] ], $allow, $changed, $content, $stored );

			if ( null === $result ) {
				$out['failed'] = $id;
				$out['cause']  = self::$write_failure;
				break;
			}

			if ( true === $result ) {
				++$out['updated'];
				$written[ $id ] = $changed[ $block ] ?? [];

				if ( 'back' === $task['kind'] ) {
					$identity[ $id ] = self::block_identity( $stored, $block, $written[ $id ] );
				}
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
			self::record_fallback( $from, $to, $written, $identity );
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
	 * Drop the containers a restore did from a translation's FALLBACK_META, read
	 * again first since a removal may have added to it; deleted once empty.
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
					'blocks'     => $recorded['blocks'],
				]
			);
		}

		return count( $recorded['containers'] );
	}

	/**
	 * Read what a page of a fan-out reads, one batch per kind: posts and meta,
	 * and for a forward rewrite their groups and originals.
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

		// The LIKEs only narrow the rows (any `"ref"` form the parser reads); the
		// rewrite matches the number exactly, so `"ref":7` never rewrites `"ref":70`.
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
	 * Store a container's content with its references mapped, compare-and-set on
	 * the content the rewrite was computed from. Not wp_update_post(): it would run
	 * kses for a user without `unfiltered_html` and add a revision the user did not make.
	 *
	 * @param int                                     $id      Container id.
	 * @param array<string, array<int, int>>          $maps    Block name => ( post id => post id ).
	 * @param (callable(string, int, int): bool)|null $allow   Whether an occurrence (block name, occurrence, id) may be rewritten; null: all.
	 * @param array<string, array<int, int>>          $changed Set to block name => the occurrences the write changed.
	 * @param string|null                             $known   Content the caller just read, the first comparison's base; null: read it.
	 * @param string                                  $stored  Set to the content written.
	 * @return bool|null Written, false when nothing to write, null on a failure (self::$write_failure says which).
	 */
	private static function write_container( int $id, array $maps, ?callable $allow = null, array &$changed = [], ?string $known = null, string &$stored = '' ): ?bool {
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
				$stored           = $rewritten;
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
	 * The forward rewrites' filter for one container: skips an occurrence its user
	 * switched back, and one without an entry naming a source another occurrence
	 * was switched back to (a block its user added).
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
	 * The entries of one block name matched to the content, since blocks added or
	 * removed before an occurrence shift its position: see
	 * docs-ai/11-code-rationale.md, "TemplateBlockRefs: rewrite and fallback records".
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

		// The user may have switched a block back and added one naming the written
		// id: the entry moves to the occurrence naming the source.
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
	 * source-language member, the id written ]. An entry whose occurrence names
	 * neither is dropped.
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
	 * What finds a container's pointed-back blocks again: the md5 of its content
	 * with reference numbers masked, and per occurrence the md5 of the block's
	 * attributes, '' when another block of that name has the same.
	 *
	 * @param string          $content     Container content as written.
	 * @param string          $block       Block name without namespace.
	 * @param array<int, int> $occurrences Occurrences pointed back.
	 * @return array{sum: string, fp: array<int, string>}
	 */
	private static function block_identity( string $content, string $block, array $occurrences ): array {
		$fps    = self::fingerprints( $content, $block );
		$counts = array_count_values( $fps );
		$out    = [];

		foreach ( $occurrences as $occurrence ) {
			$fp                 = $fps[ $occurrence ] ?? '';
			$out[ $occurrence ] = '' !== $fp && 1 === $counts[ $fp ] ? $fp : '';
		}

		return [
			'sum' => self::masked_sum( $content ),
			'fp'  => $out,
		];
	}

	/**
	 * The occurrences a restore may point at the translation again: the recorded
	 * ones while the content is as the removal left it (reference numbers aside),
	 * else each recorded block that exactly one block matches by attributes.
	 *
	 * @param array{source?: int, containers: array<int, array<int, int>>, blocks?: array<int, array{sum: string, fp: array<int, string>}>} $record The translation's FALLBACK_META, read.
	 * @param int                                                                                                               $container Container id.
	 * @param string                                                                                                            $content   Its content now.
	 * @param string                                                                                                            $block     Block name without namespace.
	 * @return array<int, true>
	 */
	private static function restore_allowed( array $record, int $container, string $content, string $block ): array {
		$at       = $record['containers'][ $container ] ?? [];
		$identity = $record['blocks'][ $container ] ?? null;

		// A record without an identity: by position, only while exactly the recorded blocks name the source.
		if ( null === $identity ) {
			$naming = array_keys( self::occurrences( $content, $block ), $record['source'] ?? 0, true );
			$want   = array_values( array_unique( $at ) );
			sort( $want );

			return $want === $naming ? array_fill_keys( $want, true ) : [];
		}

		if ( $identity['sum'] === self::masked_sum( $content ) ) {
			return array_fill_keys( $at, true );
		}

		$now     = self::fingerprints( $content, $block );
		$allowed = [];

		foreach ( $at as $occurrence ) {
			$fp    = $identity['fp'][ $occurrence ] ?? '';
			$found = '' === $fp ? [] : array_keys( $now, $fp, true );

			if ( 1 === count( $found ) ) {
				$allowed[ $found[0] ] = true;
			}
		}

		return $allowed;
	}

	/**
	 * Block markup's md5 with the `ref` number of every menu and pattern block masked.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	private static function masked_sum( string $content ): string {
		$masked = preg_replace_callback(
			self::DELIMITER,
			static function ( array $token ): string {
				foreach ( self::TARGETS as $block ) {
					if ( self::token_ref( $token, $block ) > 0 ) {
						return (string) preg_replace( '/("ref"\s*:\s*)"?[0-9]+"?/', '${1}0', $token[0] );
					}
				}

				return $token[0];
			},
			$content
		);

		return md5( is_string( $masked ) ? $masked : $content );
	}

	/**
	 * Per occurrence of one block name, the md5 of its attributes (a pointed-back
	 * block names the source both when recorded and when restored).
	 *
	 * @param string $content Block markup.
	 * @param string $block   Block name without namespace.
	 * @return array<int, string>
	 */
	private static function fingerprints( string $content, string $block ): array {
		$out = [];

		if ( ! str_contains( $content, '"ref"' ) || ! preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $matches as $token ) {
			if ( self::token_ref( $token, $block ) <= 0 ) {
				continue;
			}

			$out[] = md5( (string) wp_json_encode( json_decode( (string) ( $token['attrs'] ?? '' ), true ) ) );
		}

		return $out;
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
	 * A block holding "ref" more than once is left as it is: the top-level one
	 * cannot be told apart without re-encoding the attributes.
	 *
	 * @param string                                  $content Block markup.
	 * @param array<string, array<int, int>>          $maps    Block name => ( post id => post id ).
	 * @param (callable(string, int, int): bool)|null $allow   Whether an occurrence (block name, occurrence, id) may be rewritten; null: all.
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
