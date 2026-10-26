<?php
/**
 * Keeps the source's markup in a translation created by copying it.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts back the source markup the saving user's content filters (kses) stripped from a copied translation.
 *
 * Only while the given column is byte for byte the copied post's stored one, untrashed and still in the source's
 * group; written through SourceBoundWrite. See docs-ai/11-code-rationale.md, "CopiedContentSave: restored markup".
 */
final class CopiedContentSave {

	/**
	 * Columns a copy takes from the source.
	 */
	private const COLUMNS = [ 'post_content', 'post_excerpt' ];

	/**
	 * Post whose columns were copied.
	 *
	 * @var int
	 */
	private int $copy_id;

	/**
	 * Post the translation is created from.
	 *
	 * @var int
	 */
	private int $source_id;

	/**
	 * Post updated (0: the save is an insert).
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
	 * Whether the save itself was handled.
	 *
	 * @var bool
	 */
	private bool $main_done = false;

	/**
	 * Whether the revision of the save was handled.
	 *
	 * @var bool
	 */
	private bool $revision_done = false;

	/**
	 * Registered callback.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $filter = null;

	/**
	 * Conditional writes armed by restores.
	 *
	 * @var array<int, SourceBoundWrite>
	 */
	private array $writes = [];

	/**
	 * Constructor.
	 *
	 * @param int $copy_id   Post whose content and excerpt were copied.
	 * @param int $source_id Post the translation is created from.
	 * @param int $target_id Post the save updates; 0 for an insert.
	 */
	public function __construct( int $copy_id, int $source_id, int $target_id = 0 ) {
		$this->copy_id   = $copy_id;
		$this->source_id = $source_id;
		$this->target_id = $target_id;
	}

	/**
	 * The restore for a copying insert of these post fields; null when neither the content nor the excerpt has text.
	 *
	 * @param array<mixed>         $post_data The insert's post fields.
	 * @param int                  $copy_id   Post whose content and excerpt were copied.
	 * @param int                  $source_id Post the translation is created from.
	 * @return self|null
	 */
	public static function for_insert( array $post_data, int $copy_id, int $source_id ): ?self {
		$has_text = static fn( mixed $value ): bool => is_string( $value ) && '' !== $value;

		return $has_text( $post_data['post_content'] ?? null ) || $has_text( $post_data['post_excerpt'] ?? null )
			? new self( $copy_id, $source_id )
			: null;
	}

	/**
	 * Add the callback, scoped to the save the caller starts next.
	 *
	 * @return void
	 */
	public function arm(): void {
		if ( null !== $this->filter || $this->copy_id <= 0 ) {
			return;
		}

		$this->blog_id       = get_current_blog_id();
		$this->depth         = SaveScope::depth();
		$this->main_done     = false;
		$this->revision_done = false;
		$this->filter        = fn( mixed $data, mixed $postarr = [], mixed $unsanitized = [] ): mixed => $this->on_data( $data, $postarr, $unsanitized );

		$this->writes = [];

		add_filter( 'wp_insert_post_data', $this->filter, PHP_INT_MAX, 3 );
		add_filter( 'wp_insert_attachment_data', $this->filter, PHP_INT_MAX, 3 );

		if ( $this->target_id > 0 ) {
			SaveScope::claim( $this->target_id );
		}
	}

	/**
	 * Remove the callback. Safe to call more than once.
	 *
	 * @return void
	 */
	public function disarm(): void {
		if ( null === $this->filter ) {
			return;
		}

		remove_filter( 'wp_insert_post_data', $this->filter, PHP_INT_MAX );
		remove_filter( 'wp_insert_attachment_data', $this->filter, PHP_INT_MAX );

		foreach ( $this->writes as $write ) {
			$write->disarm();
		}

		$this->writes = [];

		if ( $this->target_id > 0 ) {
			SaveScope::release( $this->target_id );
		}

		$this->filter = null;
	}

	/**
	 * `wp_insert_post_data` callback.
	 *
	 * @param mixed $data        Slashed post data about to be written.
	 * @param mixed $postarr     Sanitized arguments of the save.
	 * @param mixed $unsanitized Arguments of the save before the content filters.
	 * @return mixed
	 */
	private function on_data( mixed $data, mixed $postarr, mixed $unsanitized ): mixed {
		if ( null === $this->filter || ! is_array( $data ) || ! is_array( $unsanitized ) || get_current_blog_id() !== $this->blog_id ) {
			return $data;
		}

		if ( ! $this->main_done && $this->is_armed_save( $postarr ) ) {
			$this->main_done = true;

			return $this->restore( $data, $unsanitized, $this->target_id );
		}

		if ( $this->main_done && ! $this->revision_done && $this->target_id > 0 && SaveScope::is_revision_insert( $data, $postarr, $this->target_id, $this->depth ) ) {
			$this->revision_done = true;

			return $this->restore( $data, $unsanitized, 0 );
		}

		return $data;
	}

	/**
	 * Whether a save is the one this object was armed for.
	 *
	 * @param mixed $postarr Arguments of the save.
	 * @return bool
	 */
	private function is_armed_save( mixed $postarr ): bool {
		if ( SaveScope::depth() !== $this->depth + 1 ) {
			return false;
		}

		$id = is_array( $postarr ) ? ( $postarr['ID'] ?? 0 ) : 0;

		return $this->target_id > 0
			? is_numeric( $id ) && (int) $id === $this->target_id
			: empty( $id );
	}

	/**
	 * Put back each copied column the filters changed, when it is the stored
	 * column of the copied post.
	 *
	 * @param array<mixed> $data        Slashed post data.
	 * @param array<mixed> $unsanitized Arguments before the content filters.
	 * @param int          $post_id     Post the save updates; 0 for an insert (the copy, a revision).
	 * @return array<mixed>
	 */
	private function restore( array $data, array $unsanitized, int $post_id ): array {
		$changed = [];

		foreach ( self::COLUMNS as $column ) {
			$given = $unsanitized[ $column ] ?? null;
			$saved = $data[ $column ] ?? null;

			if ( is_string( $given ) && is_string( $saved ) && $given !== $saved ) {
				$changed[ $column ] = wp_unslash( $given );
			}
		}

		if ( [] === $changed || ! \PerfLocale\MachineTranslation\ProtectedBlockSave::keep_markup( 'copy', $this->target_id, $this->copy_id ) ) {
			return $data;
		}

		$stored = $this->stored_copy();

		if ( null === $stored || ! \PerfLocale\MachineTranslation\ProtectedBlockSave::may_read_source( $this->copy_id, $stored['post_status'] ) ) {
			return $data;
		}

		foreach ( $changed as $column => $given ) {
			if ( ! isset( $stored[ $column ] ) || $given !== $stored[ $column ] ) {
				continue;
			}

			// The database writes the source's bytes only while the copied
			// post still holds them (and is still linked); otherwise what the
			// filters made of them.
			$write = new SourceBoundWrite(
				$column,
				$post_id,
				$given,
				wp_unslash( is_string( $data[ $column ] ?? null ) ? $data[ $column ] : '' ),
				SourceBoundWrite::source_holds( $this->copy_id, $column, $given, $stored['post_status'], $this->copy_id === $this->source_id ? 0 : $this->source_id )
			);
			$write->arm();

			$this->writes[]  = $write;
			$data[ $column ] = $write->token();
		}

		return $data;
	}

	/**
	 * The copied post's content, excerpt and status as stored now, when it is
	 * not in the trash and, when it is not the source itself, is still in one
	 * translation group with the source in this blog.
	 *
	 * @return array{post_status: string, post_content?: string, post_excerpt?: string}|null
	 */
	private function stored_copy(): ?array {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		if ( $this->copy_id === $this->source_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row at write time: another request may have changed it.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT post_content, post_excerpt, post_status FROM %i WHERE ID = %d', $wpdb->posts, $this->copy_id ), ARRAY_A );
		} else {
			$links = Schema::table( 'translation_links' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row and link at write time: another request may have changed them.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT p.post_content, p.post_excerpt, p.post_status FROM %i p INNER JOIN %i c ON c.type = %s AND c.object_id = p.ID INNER JOIN %i s ON s.group_id = c.group_id AND s.type = %s AND s.object_id = %d WHERE p.ID = %d LIMIT 1',
					$wpdb->posts,
					$links,
					'post',
					$links,
					'post',
					$this->source_id,
					$this->copy_id
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $row ) || '' !== $wpdb->last_error || 'trash' === ( $row['post_status'] ?? '' ) || ! is_string( $row['post_status'] ?? null ) ) {
			return null;
		}

		$out = [ 'post_status' => $row['post_status'] ];

		foreach ( self::COLUMNS as $column ) {
			$value = $row[ $column ] ?? null;

			if ( is_string( $value ) ) {
				$out[ $column ] = $value;
			}
		}

		return $out;
	}
}
