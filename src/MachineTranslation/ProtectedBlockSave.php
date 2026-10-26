<?php
/**
 * Keeps "Do not translate" blocks byte for byte through the save of a machine translation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Database\Schema;
use PerfLocale\Translation\BlockSkipFilter;
use PerfLocale\Translation\ProtectedUnits;
use PerfLocale\Translation\SaveScope;
use PerfLocale\Translation\SourceBoundWrite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps "Do not translate" blocks through a machine-translation save and every other translation save.
 *
 * The blocks pass the save's content filters as placeholders; bytes go back only when identical to a protected block
 * of the source as stored at write time. Flow: docs-ai/11-code-rationale.md, "ProtectedBlockSave: armed save and save rule".
 */
final class ProtectedBlockSave {

	/**
	 * Start of every placeholder ({@see self::token()}).
	 */
	private const PLACEHOLDER = '<!-- perflocale-skip-';

	/**
	 * Placeholder => the unit it stands for (bytes and parent block chain).
	 *
	 * @var array<string, array{bytes: string, chain: string, context: string}>
	 */
	private array $map;

	/**
	 * Content with the placeholders in place of the protected blocks.
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Source post the run read.
	 *
	 * @var int
	 */
	private int $source_id;

	/**
	 * Translation post being saved.
	 *
	 * @var int
	 */
	private int $target_id;

	/**
	 * Blog the save runs in.
	 *
	 * @var int
	 */
	private int $blog_id = 0;

	/**
	 * Hook stack depth at arm time.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Whether the callbacks are registered.
	 *
	 * @var bool
	 */
	private bool $armed = false;

	/**
	 * Whether the armed invocation's data filter ran.
	 *
	 * @var bool
	 */
	private bool $consumed = false;

	/**
	 * Whether the save was refused.
	 *
	 * @var bool
	 */
	private bool $refused = false;

	/**
	 * Whether the revision of the armed save was handled.
	 *
	 * @var bool
	 */
	private bool $revision_done = false;

	/**
	 * Source content the last verification accepted.
	 *
	 * @var string|null
	 */
	private ?string $last_verified = null;

	/**
	 * Source status the last verification accepted.
	 *
	 * @var string
	 */
	private string $last_status = '';

	/**
	 * Conditional writes this object armed (the save, its revision).
	 *
	 * @var array<int, SourceBoundWrite>
	 */
	private array $writes = [];

	/**
	 * The content the armed save keeps (unslashed), to confirm after the write.
	 *
	 * @var string|null
	 */
	private ?string $kept_content = null;

	/**
	 * Registered data filter.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $data_filter = null;

	/**
	 * Registered query filter.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $query_filter = null;

	/**
	 * The revision core is about to restore: its post and its content.
	 *
	 * @var array{post: int, content: string}|null
	 */
	private static ?array $restoring = null;

	/**
	 * Conditional writes the save rule armed, each with the hook depth of the data filter that armed it.
	 *
	 * @var array<int, array{0: SourceBoundWrite, 1: int}>
	 */
	private static array $pending = [];

	/**
	 * Whether a save keeps the markup of its "Do not translate" blocks.
	 *
	 * @param string $path      Save path (see the filter).
	 * @param int    $post_id   Post saved (0 for a post being inserted).
	 * @param int    $source_id Post the protected bytes are verified against.
	 * @return bool
	 */
	public static function keep_markup( string $path, int $post_id, int $source_id ): bool {
		/**
		 * Whether a translation save keeps its "Do not translate" blocks' markup for a user without unfiltered_html.
		 *
		 * Only bytes identical to the source's stored ones are kept; changed or unverified bytes are always filtered.
		 * Return false for core's filtering. Paths: docs-ai/11-code-rationale.md, "ProtectedBlockSave: keep_markup paths".
		 *
		 * @hook perflocale/protected_blocks/keep_markup
		 * @param bool  $keep    Default true.
		 * @param array $context {
		 *     @type string $path      'mt', 'copy', 'editor', 'quick_edit', 'revision_restore', 'revision' or 'save'.
		 *     @type int    $post_id   Post saved; 0 for a post being inserted.
		 *     @type int    $source_id Post the bytes are verified against.
		 *     @type int    $user_id   User saving; 0 for none (cron, WP-CLI without --user).
		 *     @type int    $blog_id   Blog.
		 * }
		 */
		return (bool) apply_filters(
			'perflocale/protected_blocks/keep_markup',
			true,
			[
				'path'      => $path,
				'post_id'   => $post_id,
				'source_id' => $source_id,
				'user_id'   => get_current_user_id(),
				'blog_id'   => get_current_blog_id(),
			]
		);
	}

	/**
	 * `wp_insert_post_data` callback for every save: a translation keeps blocks byte for byte its source's protected ones.
	 *
	 * Without the marker it costs one strpos, with unchanged content one comparison. A save a scoped saver owns
	 * (SaveScope::claim()), an insert and a post that is no translation are left alone.
	 *
	 * @param mixed $data        Slashed post data about to be written.
	 * @param mixed $postarr     Sanitized arguments of the save.
	 * @param mixed $unsanitized Arguments of the save before the content filters.
	 * @return mixed
	 */
	public static function keep_on_save( mixed $data, mixed $postarr = [], mixed $unsanitized = [] ): mixed {
		// A write armed at this depth or deeper belongs to a save that has returned. A shallower one belongs to a save
		// still in flight (this save runs from one of its hooks, such as pre_post_update) and stays armed.
		$depth = SaveScope::depth();

		foreach ( self::$pending as $key => [ $write, $armed ] ) {
			if ( $armed >= $depth ) {
				$write->disarm();
				unset( self::$pending[ $key ] );
			}
		}

		$raw      = is_array( $unsanitized ) ? ( $unsanitized['post_content'] ?? null ) : null;
		$revision = is_array( $data ) && 'revision' === ( $data['post_type'] ?? '' );
		$id       = is_array( $data ) && $revision ? ( $data['post_parent'] ?? 0 ) : ( is_array( $postarr ) ? ( $postarr['ID'] ?? 0 ) : 0 );
		$post_id  = is_numeric( $id ) ? (int) $id : 0;

		// The restore note belongs to this one save of its post, whatever happens to it.
		$restore = null;

		if ( null !== self::$restoring && self::$restoring['post'] === $post_id && ! $revision ) {
			$restore         = self::$restoring;
			self::$restoring = null;
		}

		if ( ! is_string( $raw ) || ! BlockSkipFilter::may_have_marker( $raw ) || ! is_array( $data ) ) {
			return $data;
		}

		$saved = $data['post_content'] ?? null;

		if ( ! is_string( $saved ) || $saved === $raw || $post_id <= 0 || SaveScope::claimed( $post_id ) ) {
			return $data;
		}

		// Encoded as core stores it: a utf8/utf8mb3 posts table holds emoji encoded, and
		// MySQL refuses a 4-byte character in the SQL comparison with those rows.
		$source = self::stored_group_source( $post_id, self::encode_like_core( wp_unslash( $raw ) ) );

		if ( null === $source || ! self::may_read_source( $source[0], $source[3] ) ) {
			return $data;
		}

		// A restore: the note matches, or the save writes exactly one of the post's revisions (a restore with an
		// explicit field list skips the revision fields lookup that sets the note).
		if ( $revision ) {
			$path = 'revision';
		} elseif ( ( null !== $restore && wp_unslash( $raw ) === $restore['content'] ) || $source[2] ) {
			$path = 'revision_restore';
		} else {
			$path = self::save_path();
		}

		if ( ! self::keep_markup( $path, $post_id, $source[0] ) ) {
			return $data;
		}

		$units             = ProtectedUnits::of_source( $source[1] );
		[ $content, $map ] = ProtectedUnits::swap( wp_unslash( $raw ), $units, static fn(): string => self::token() );

		if ( [] === $map ) {
			return $data;
		}

		$filtered = sanitize_post_field( 'post_content', wp_slash( $content ), $post_id, 'db' );
		$restored = is_string( $filtered ) ? self::put_back( $filtered, $map ) : null;

		if ( null !== $restored && self::judged_again( $restored[0], $restored[1], $map, $units ) ) {
			// Kept bytes are written only while the source still holds what was checked; else the filtered content.
			$write = new SourceBoundWrite(
				'post_content',
				$revision ? 0 : $post_id,
				self::encode_like_core( $restored[0] ),
				wp_unslash( $saved ),
				SourceBoundWrite::source_holds( $source[0], 'post_content', $source[1], $source[3], $post_id, true ),
				$revision ? 0 : $post_id
			);
			$write->arm();

			self::$pending[]      = [ $write, $depth ];
			$data['post_content'] = $write->token();
		}

		return $data;
	}




	/**
	 * Whether the saving user may have a source's bytes: a private one only for its readers or a save without a user.
	 *
	 * The conditional write binds the status checked here, so a source that changes status before the write keeps nothing.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $status    Its stored status.
	 * @return bool
	 */
	public static function may_read_source( int $source_id, string $status ): bool {
		return 'private' !== $status || 0 === get_current_user_id() || current_user_can( 'read_post', $source_id );
	}

	/**
	 * `_wp_post_revision_fields` callback: notes the revision wp_restore_post_revision() is about to save.
	 *
	 * The next save of that post takes the note; it is a restore only when it writes that revision's content.
	 *
	 * @param mixed $fields Revision fields.
	 * @param mixed $post   Post or revision the fields are read for.
	 * @return mixed
	 */
	public static function note_restore( mixed $fields, mixed $post = null ): mixed {
		$row = is_object( $post ) ? get_object_vars( $post ) : $post;

		if ( is_array( $row ) && 'revision' === ( $row['post_type'] ?? '' ) && is_numeric( $row['post_parent'] ?? null ) ) {
			self::$restoring = [
				'post'    => (int) $row['post_parent'],
				'content' => is_string( $row['post_content'] ?? null ) ? $row['post_content'] : '',
			];
		}

		return $fields;
	}

	/**
	 * `wp_restore_post_revision` callback: the restore is over.
	 *
	 * @return void
	 */
	public static function restore_done(): void {
		self::$restoring = null;
	}

	/**
	 * The path of a save that no scoped saver owns and that is no revision restore.
	 *
	 * @return string
	 */
	private static function save_path(): string {
		if ( doing_action( 'wp_ajax_inline-save' ) ) {
			return 'quick_edit';
		}

		return \PerfLocale\Helper::is_rest_request() ? 'editor' : 'save';
	}

	/**
	 * The non-trashed default-language post of a translation's group (not the post itself), in one query.
	 *
	 * Also whether $content is exactly one of the translation's revisions while the translation does not hold it now.
	 *
	 * @param int    $post_id Translation post id.
	 * @param string $content Content the save submits (unslashed, emoji encoded as stored).
	 * @return array{0: int, 1: string, 2: bool, 3: string}|null [ source id, stored content, writes a revision, source status ].
	 */
	private static function stored_group_source( int $post_id, string $content ): ?array {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$links = Schema::table( 'translation_links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored source and link at write time.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.ID, p.post_content, p.post_status, ( SELECT pfl_c.post_content FROM %i pfl_c WHERE pfl_c.ID = %d ) AS current_content, ( SELECT pfl_r.ID FROM %i pfl_r WHERE pfl_r.post_type = %s AND pfl_r.post_parent = %d AND pfl_r.post_content = %s AND MD5( pfl_r.post_content ) = %s LIMIT 1 ) AS revision_id FROM %i t INNER JOIN %i s ON s.group_id = t.group_id AND s.type = %s AND s.object_id <> t.object_id INNER JOIN %i l ON l.id = s.language_id AND l.is_default = 1 INNER JOIN %i p ON p.ID = s.object_id WHERE t.type = %s AND t.object_id = %d LIMIT 1',
				$wpdb->posts,
				$post_id,
				$wpdb->posts,
				'revision',
				$post_id,
				$content,
				md5( $content ),
				$links,
				$links,
				'post',
				Schema::table( 'languages' ),
				$wpdb->posts,
				'post',
				$post_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || '' !== $wpdb->last_error || 'trash' === ( $row['post_status'] ?? '' ) || ! is_string( $row['post_content'] ?? null ) || ! is_numeric( $row['ID'] ?? null ) ) {
			return null;
		}

		return [ (int) $row['ID'], $row['post_content'], is_numeric( $row['revision_id'] ?? null ) && $content !== ( $row['current_content'] ?? null ), is_string( $row['post_status'] ?? null ) ? $row['post_status'] : '' ];
	}

	/**
	 * Whether the content about to be written, read again, holds exactly the judged units where they were put back.
	 *
	 * Same chain and HTML context each: the filters may have changed the markup around them or written a copy elsewhere.
	 *
	 * @param string                                                              $content Unslashed content with the units put back.
	 * @param array<int, array{0: int, 1: string}>                                $starts  [ start, placeholder ] of each unit put back, in document order.
	 * @param array<string, array{bytes: string, chain: string, context: string}> $map     Units kept, by placeholder.
	 * @param array<int, array{bytes: string, chain: string, context: string}>    $units   Units the source allows.
	 * @return bool
	 */
	private static function judged_again( string $content, array $starts, array $map, array $units ): bool {
		$again = ProtectedUnits::spans( $content, $units );

		if ( count( $again ) !== count( $starts ) ) {
			return false;
		}

		foreach ( $starts as $i => [ $start, $token ] ) {
			$found = $again[ $i ];
			$unit  = $map[ $token ];

			if ( $found['start'] !== $start || ProtectedUnits::key( $found['chain'], $found['context'], $found['bytes'] ) !== ProtectedUnits::key( $unit['chain'], $unit['context'], $unit['bytes'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Content with the emoji encoding wp_insert_post() applies for a utf8/utf8mb3 content column.
	 *
	 * The kept bytes and a value rebuilt from the submitted arguments never had it.
	 *
	 * @param string $content Unslashed content.
	 * @return string
	 */
	private static function encode_like_core( string $content ): string {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		if ( ! function_exists( 'wp_encode_emoji' ) ) {
			return $content;
		}

		$charset = $wpdb->get_col_charset( $wpdb->posts, 'post_content' );

		return 'utf8' === $charset || 'utf8mb3' === $charset ? wp_encode_emoji( $content ) : $content;
	}

	/**
	 * The filtered content (unslashed) with each placeholder, found once and in order, replaced by its bytes.
	 *
	 * Also where each unit starts. One pass over the placeholder occurrences: linear whatever the number of placeholders.
	 *
	 * @param string                                                              $filtered Slashed content after the content filters.
	 * @param array<string, array{bytes: string, chain: string, context: string}> $map      Placeholder => unit, in document order.
	 * @return array{0: string, 1: array<int, array{0: int, 1: string}>}|null [ content, [ start, placeholder ] in document order ]; null when a placeholder is missing, repeated or out of order.
	 */
	private static function put_back( string $filtered, array $map ): ?array {
		$size = self::placeholder_size( $map );

		if ( null === $size ) {
			return null;
		}

		$content = wp_unslash( $filtered );
		$order   = array_keys( $map );
		$next    = 0;
		$parts   = [];
		$starts  = [];
		$from    = 0;
		$length  = 0;
		$at      = 0;

		// A placeholder holds '<' only at its start, so occurrences never overlap.
		while ( false !== ( $pos = strpos( $content, self::PLACEHOLDER, $at ) ) ) {
			$token = substr( $content, $pos, $size );

			if ( ! isset( $map[ $token ] ) ) {
				$at = $pos + strlen( self::PLACEHOLDER );

				continue;
			}

			// Repeated, or found before one made earlier.
			if ( ( $order[ $next ] ?? null ) !== $token ) {
				return null;
			}

			++$next;
			$parts[]  = substr( $content, $from, $pos - $from );
			$length  += $pos - $from;
			$starts[] = [ $length, $token ];
			$parts[]  = $map[ $token ]['bytes'];
			$length  += strlen( $map[ $token ]['bytes'] );
			$from     = $pos + $size;
			$at       = $from;
		}

		if ( count( $order ) !== $next ) {
			return null;
		}

		$parts[] = substr( $content, $from );

		return [ implode( '', $parts ), $starts ];
	}

	/**
	 * The length all placeholders of a map share, or null when one does not start like token() or the lengths differ.
	 *
	 * @param array<string, mixed> $map Placeholder => unit.
	 * @return int|null
	 */
	private static function placeholder_size( array $map ): ?int {
		$size = null;

		foreach ( array_keys( $map ) as $token ) {
			$token = (string) $token;

			if ( ! str_starts_with( $token, self::PLACEHOLDER ) || strlen( $token ) !== ( $size ??= strlen( $token ) ) ) {
				return null;
			}
		}

		return $size;
	}

	/**
	 * Whether a text holds one of a map's placeholders; true (stop) for a map token() did not make.
	 *
	 * One pass over the placeholder occurrences, linear in the text.
	 *
	 * @param string               $text Text.
	 * @param array<string, mixed> $map  Placeholder => unit.
	 * @return bool
	 */
	private static function carries_placeholder( string $text, array $map ): bool {
		$size = self::placeholder_size( $map );
		$at   = 0;

		if ( null === $size ) {
			return true;
		}

		while ( false !== ( $pos = strpos( $text, self::PLACEHOLDER, $at ) ) ) {
			if ( isset( $map[ substr( $text, $pos, $size ) ] ) ) {
				return true;
			}

			$at = $pos + strlen( self::PLACEHOLDER );
		}

		return false;
	}

	/**
	 * Constructor.
	 *
	 * @param string                                                              $content   Content with placeholders.
	 * @param array<string, array{bytes: string, chain: string, context: string}> $map       Placeholder => unit.
	 * @param int                                                                 $source_id Source post id.
	 * @param int                                                                 $target_id Translation post id.
	 */
	private function __construct( string $content, array $map, int $source_id, int $target_id ) {
		$this->content   = $content;
		$this->map       = $map;
		$this->source_id = $source_id;
		$this->target_id = $target_id;
	}

	/**
	 * Swap the protected blocks in the content to save for fresh placeholders.
	 *
	 * Only blocks ProtectedUnits lets keep their markup; any other block goes through the save's filters as it is.
	 *
	 * @param string $content        Content as it would be saved.
	 * @param string $source_content Source content the run read.
	 * @param int    $source_id      Source post the run read.
	 * @param int    $target_id      Translation post the content is saved to.
	 * @return self|null Null when no protected block is in the content.
	 */
	public static function prepare( string $content, string $source_content, int $source_id, int $target_id ): ?self {
		if ( '' === $content || '' === $source_content || $source_id <= 0 || $target_id <= 0 || ! self::keep_markup( 'mt', $target_id, $source_id ) ) {
			return null;
		}

		[ $content, $map ] = ProtectedUnits::swap( $content, ProtectedUnits::of_source( $source_content ), static fn(): string => self::token() );

		return [] === $map ? null : new self( $content, $map, $source_id, $target_id );
	}

	/**
	 * The content to pass to wp_update_post().
	 *
	 * @return string
	 */
	public function content(): string {
		return $this->content;
	}

	/**
	 * Whether the save was refused (nothing of it was written).
	 *
	 * @return bool
	 */
	public function refused(): bool {
		return $this->refused;
	}

	/**
	 * Add the restore and the stop, scoped to the save the caller starts next.
	 *
	 * @return void
	 */
	public function arm(): void {
		if ( $this->armed ) {
			return;
		}

		$this->blog_id       = get_current_blog_id();
		$this->depth         = self::hook_depth();
		$this->consumed      = false;
		$this->refused       = false;
		$this->revision_done = false;
		$this->writes        = [];
		$this->kept_content  = null;
		$this->data_filter   = fn( mixed $data, mixed $postarr = [], mixed $unsanitized = [] ): mixed => $this->on_data( $data, $postarr, $unsanitized );
		$this->query_filter  = fn( mixed $query ): mixed => $this->stop( $query );

		add_filter( 'wp_insert_post_data', $this->data_filter, PHP_INT_MAX, 3 );
		add_filter( 'wp_insert_attachment_data', $this->data_filter, PHP_INT_MAX, 3 );
		add_filter( 'query', $this->query_filter, PHP_INT_MAX, 1 );

		$this->armed = true;
	}

	/**
	 * Remove the callbacks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function disarm(): void {
		if ( null !== $this->data_filter ) {
			remove_filter( 'wp_insert_post_data', $this->data_filter, PHP_INT_MAX );
			remove_filter( 'wp_insert_attachment_data', $this->data_filter, PHP_INT_MAX );
		}

		if ( null !== $this->query_filter ) {
			remove_filter( 'query', $this->query_filter, PHP_INT_MAX );
		}

		foreach ( $this->writes as $write ) {
			$write->disarm();
		}

		$this->armed = false;
	}

	/**
	 * `wp_insert_post_data` callback: the armed save, or its revision.
	 *
	 * @param mixed $data        Slashed post data about to be written.
	 * @param mixed $postarr     Sanitized arguments of the save.
	 * @param mixed $unsanitized Arguments of the save before the content filters.
	 * @return mixed
	 */
	private function on_data( mixed $data, mixed $postarr, mixed $unsanitized ): mixed {
		if ( $this->armed && $this->consumed && ! $this->refused && ! $this->revision_done && get_current_blog_id() === $this->blog_id && SaveScope::is_revision_insert( $data, $postarr, $this->target_id, $this->depth ) ) {
			$this->revision_done = true;

			return $this->restore_revision( $data, $unsanitized );
		}

		return $this->restore( $data, $postarr );
	}

	/**
	 * The revision of the armed save: filtered again with the protected blocks as placeholders, verified bytes put back.
	 *
	 * @param mixed $data        Slashed revision data about to be written.
	 * @param mixed $unsanitized Revision arguments before the content filters.
	 * @return mixed
	 */
	private function restore_revision( mixed $data, mixed $unsanitized ): mixed {
		$raw = is_array( $unsanitized ) ? ( $unsanitized['post_content'] ?? null ) : null;

		if ( ! is_array( $data ) || ! is_string( $raw ) ) {
			return $data;
		}

		[ $content, $map ] = ProtectedUnits::swap( wp_unslash( $raw ), array_values( $this->map ), static fn(): string => self::token() );

		if ( [] === $map ) {
			return $data;
		}

		$filtered = sanitize_post_field( 'post_content', wp_slash( $content ), 0, 'db' );
		$restored = is_string( $filtered ) ? $this->verified( $filtered, $map ) : null;

		if ( null !== $restored ) {
			$write = new SourceBoundWrite(
				'post_content',
				0,
				self::encode_like_core( $restored ),
				is_string( $data['post_content'] ?? null ) ? wp_unslash( $data['post_content'] ) : '',
				SourceBoundWrite::source_holds( $this->source_id, 'post_content', (string) $this->last_verified, $this->last_status, $this->target_id )
			);
			$write->arm();

			$this->writes[]       = $write;
			$data['post_content'] = $write->token();
		}

		return $data;
	}

	/**
	 * `wp_insert_post_data` callback: verify and put the bytes back.
	 *
	 * @param mixed $data    Slashed post data about to be written.
	 * @param mixed $postarr Sanitized arguments of the save.
	 * @return mixed
	 */
	private function restore( mixed $data, mixed $postarr ): mixed {
		if ( ! $this->armed || $this->consumed || ! $this->is_armed_invocation( $postarr ) || ! is_array( $data ) ) {
			return $data;
		}

		$this->consumed = true;

		$content  = $data['post_content'] ?? null;
		$restored = is_string( $content ) ? $this->verified( $content, $this->map ) : null;

		if ( null === $restored ) {
			// The UPDATE is stopped below. Should the stop not take, the row keeps its content: no placeholder,
			// source text or unverified byte is written.
			$this->refused = true;
			$current       = $this->stored_target();

			if ( null !== $current ) {
				$data['post_content'] = wp_slash( $current );
			}

			return $data;
		}

		// Kept bytes are written only while the source still holds what was verified, else the row keeps its
		// content; once another query carries the token first, the write is not sent.
		$kept  = self::encode_like_core( $restored );
		$write = new SourceBoundWrite(
			'post_content',
			$this->target_id,
			$kept,
			null,
			SourceBoundWrite::source_holds( $this->source_id, 'post_content', (string) $this->last_verified, $this->last_status, $this->target_id )
		);
		$write->arm();

		$this->writes[]       = $write;
		$this->kept_content   = $kept;
		$data['post_content'] = $write->token();

		return $data;
	}

	/**
	 * After the armed save: refused when its conditional write was not sent or the row lacks the kept content
	 * (the source changed before the write, so the database kept the stored content).
	 *
	 * @return void
	 */
	public function settle(): void {
		if ( $this->refused || null === $this->kept_content || [] === $this->writes ) {
			return;
		}

		if ( ! $this->writes[0]->sent() || $this->stored_target() !== $this->kept_content ) {
			$this->refused = true;
		}
	}

	/**
	 * `query` callback: stop the UPDATE of the target row when it must not be written.
	 *
	 * @param mixed $query SQL.
	 * @return mixed
	 */
	private function stop( mixed $query ): mixed {
		if ( ! $this->armed || ! is_string( $query ) || get_current_blog_id() !== $this->blog_id ) {
			return $query;
		}

		if ( ! SaveScope::updates_post( $query, $this->target_id ) ) {
			return $query;
		}

		$carries = self::carries_placeholder( $query, $this->map );
		$outer   = self::hook_depth() === $this->depth + 1;

		if ( $carries || ( $outer && ( $this->refused || ! $this->consumed ) ) ) {
			if ( $outer ) {
				$this->refused = true;
			}

			return '';
		}

		return $query;
	}

	/**
	 * Whether a save is the one this object was armed for.
	 *
	 * @param mixed $postarr Arguments of the save.
	 * @return bool
	 */
	private function is_armed_invocation( mixed $postarr ): bool {
		$id = is_array( $postarr ) ? ( $postarr['ID'] ?? 0 ) : 0;

		return is_numeric( $id )
			&& (int) $id === $this->target_id
			&& get_current_blog_id() === $this->blog_id
			&& self::hook_depth() === $this->depth + 1;
	}

	/**
	 * The content as it will be written (unslashed) with every placeholder replaced by its verified bytes.
	 *
	 * @param string                                                              $content Slashed content after the save's content filters.
	 * @param array<string, array{bytes: string, chain: string, context: string}> $map     Placeholder => unit.
	 * @return string|null Null when any placeholder does not verify or the content with the bytes put back does not hold the same units where they were put back, in the same contexts.
	 */
	private function verified( string $content, array $map ): ?string {
		$restored = self::put_back( $content, $map );

		if ( null === $restored ) {
			return null;
		}

		$source = $this->stored_source();

		if ( null === $source || ! self::may_read_source( $this->source_id, $source[1] ) ) {
			return null;
		}

		$stored = $source[0];

		$available = [];
		$units     = ProtectedUnits::of_source( $stored );

		foreach ( $units as $unit ) {
			$key               = ProtectedUnits::key( $unit['chain'], $unit['context'], $unit['bytes'] );
			$available[ $key ] = ( $available[ $key ] ?? 0 ) + 1;
		}

		foreach ( $map as $unit ) {
			$key = ProtectedUnits::key( $unit['chain'], $unit['context'], $unit['bytes'] );

			if ( empty( $available[ $key ] ) ) {
				return null;
			}

			--$available[ $key ];
		}

		if ( ! self::judged_again( $restored[0], $restored[1], $map, $units ) ) {
			return null;
		}

		$this->last_verified = $stored;
		$this->last_status   = $source[1];

		return $restored[0];
	}

	/**
	 * The source's stored content and status, while it is the target's linked source in this blog and not trashed.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function stored_source(): ?array {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$links = Schema::table( 'translation_links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row and link at write time: another request may have changed them.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT p.post_content, p.post_status FROM %i p INNER JOIN %i s ON s.type = %s AND s.object_id = p.ID INNER JOIN %i t ON t.group_id = s.group_id AND t.type = %s AND t.object_id = %d WHERE p.ID = %d LIMIT 1',
				$wpdb->posts,
				$links,
				'post',
				$links,
				'post',
				$this->target_id,
				$this->source_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || '' !== $wpdb->last_error || 'trash' === ( $row['post_status'] ?? '' ) || ! is_string( $row['post_content'] ?? null ) || ! is_string( $row['post_status'] ?? null ) ) {
			return null;
		}

		return [ $row['post_content'], $row['post_status'] ];
	}

	/**
	 * The target's content as stored now.
	 *
	 * @return string|null
	 */
	private function stored_target(): ?string {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row: another request may have saved it.
		$content = $wpdb->get_var( $wpdb->prepare( 'SELECT post_content FROM %i WHERE ID = %d', $wpdb->posts, $this->target_id ) );

		return is_string( $content ) && '' === $wpdb->last_error ? $content : null;
	}



	/**
	 * Number of hooks running right now.
	 *
	 * @return int
	 */
	private static function hook_depth(): int {
		return SaveScope::depth();
	}

	/**
	 * A fresh placeholder: an HTML comment kses keeps as it is.
	 *
	 * @return string
	 */
	private static function token(): string {
		return self::PLACEHOLDER . wp_generate_uuid4() . ' -->';
	}
}
