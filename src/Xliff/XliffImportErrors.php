<?php
/**
 * XLIFF import error report.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Xliff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the units an XLIFF import could not apply, as one message per post and cause.
 *
 * The importer supplies a title only for a post the current user can edit.
 */
final class XliffImportErrors {

	/** The post the unit names does not exist. */
	public const NOT_FOUND = 'not_found';

	/** The user cannot edit the post the unit names. */
	public const CANNOT_EDIT = 'cannot_edit';

	/** The user cannot edit the existing translation the text would go to. */
	public const CANNOT_EDIT_TRANSLATION = 'cannot_edit_translation';

	/** The user cannot edit the post a new translation would be copied from. */
	public const CANNOT_EDIT_COPY_SOURCE = 'cannot_edit_copy_source';

	/** The user may not create posts of the type a new translation would have. */
	public const CANNOT_CREATE = 'cannot_create';

	/** Creating the translation failed. */
	public const CREATE_FAILED = 'create_failed';

	/** Saving the translated fields failed. */
	public const SAVE_FAILED = 'save_failed';

	/** Unit ids listed in full up to this many; a longer list is shortened. */
	public const UNIT_LIST_MAX = 5;

	/** Unit ids named before "and N more" in a shortened list. */
	public const UNIT_LIST_SHOWN = 2;

	/** Longest title shown, in characters, before it is cut. */
	public const TITLE_MAX = 60;

	/** Causes whose message never carries a title, whatever the caller supplies. */
	private const NO_TITLE = [ self::NOT_FOUND, self::CANNOT_EDIT ];

	/**
	 * One entry per post and cause, in the order first reported.
	 *
	 * @var array<string, array{cause: string, post_id: int, units: array<int, string>, other_id: int}>
	 */
	private array $groups = [];

	/**
	 * Record one unit that could not be applied.
	 *
	 * @param string $cause    One of the class constants.
	 * @param int    $post_id  Post ID the unit names.
	 * @param string $unit_id  Unit id as written in the file.
	 * @param int    $other_id The translation or copy source the cause is about, else 0.
	 * @return void
	 */
	public function add( string $cause, int $post_id, string $unit_id, int $other_id = 0 ): void {
		$key = $cause . ':' . $post_id;

		if ( ! isset( $this->groups[ $key ] ) ) {
			$this->groups[ $key ] = [
				'cause'    => $cause,
				'post_id'  => $post_id,
				'units'    => [],
				'other_id' => $other_id,
			];
		}

		if ( ! in_array( $unit_id, $this->groups[ $key ]['units'], true ) ) {
			$this->groups[ $key ]['units'][] = $unit_id;
		}
	}

	/**
	 * The messages, one per post and cause.
	 *
	 * @param callable $title_for Returns the title to show for a post ID, or ''.
	 * @phpstan-param callable(int): string $title_for
	 * @return array<int, string>
	 */
	public function messages( callable $title_for ): array {
		$out = [];

		foreach ( $this->groups as $group ) {
			$title = in_array( $group['cause'], self::NO_TITLE, true ) ? '' : $title_for( $group['post_id'] );
			$out[] = self::message( $group['cause'], $group['post_id'], $title, $group['units'], $group['other_id'] );
		}

		return $out;
	}

	/**
	 * Build one message.
	 *
	 * @param string             $cause    One of the class constants.
	 * @param int                $post_id  Post ID the units name.
	 * @param string             $title    Title to show, or '' for none.
	 * @param array<int, string> $units    Unit ids.
	 * @param int                $other_id The translation or copy source, else 0.
	 * @return string
	 */
	public static function message( string $cause, int $post_id, string $title, array $units, int $other_id = 0 ): string {
		$subject = self::subject( $post_id, in_array( $cause, self::NO_TITLE, true ) ? '' : $title, $units );

		switch ( $cause ) {
			case self::NOT_FOUND:
				/* translators: %s: the post and its unit ids, for example: Post 99 (units post-99-title, post-99-content). */
				return sprintf( __( '%s: this post does not exist on this site. The file may come from another site, or the post was deleted.', 'perflocale' ), $subject );

			case self::CANNOT_EDIT:
				/* translators: %s: the post and its unit ids, for example: Post 42 (unit post-42-title). */
				return sprintf( __( '%s: you do not have permission to edit this post.', 'perflocale' ), $subject );

			case self::CANNOT_EDIT_TRANSLATION:
				/* translators: 1: the post and its unit ids, for example: Post 42 (unit post-42-title). 2: ID of the post's translation. */
				return sprintf( __( '%1$s: you do not have permission to edit its translation (post %2$d).', 'perflocale' ), $subject, $other_id );

			case self::CANNOT_EDIT_COPY_SOURCE:
				/* translators: 1: the post and its unit ids, for example: Post 42 (unit post-42-title). 2: ID of the post the translation would be copied from. */
				return sprintf( __( '%1$s: you cannot edit the original this translation is copied from (post %2$d).', 'perflocale' ), $subject, $other_id );

			case self::CANNOT_CREATE:
				/* translators: %s: the post and its unit ids, for example: Post 42 (unit post-42-title). */
				return sprintf( __( '%s: you do not have permission to create translations of this content.', 'perflocale' ), $subject );

			case self::CREATE_FAILED:
				/* translators: %s: the post and its unit ids, for example: Post 42 (unit post-42-title). */
				return sprintf( __( '%s: the translation could not be created. Import the file again; if it keeps failing, check the PHP error log.', 'perflocale' ), $subject );

			default:
				/* translators: %s: the post and its unit ids, for example: Post 42 (unit post-42-title). */
				return sprintf( __( '%s: the translation could not be saved. Import the file again; if it keeps failing, check the PHP error log.', 'perflocale' ), $subject );
		}
	}

	/**
	 * "Post 42 "About us" (units post-42-title, post-42-content)".
	 *
	 * @param int                $post_id Post ID.
	 * @param string             $title   Title, or '' for none.
	 * @param array<int, string> $units   Unit ids.
	 * @return string
	 */
	public static function subject( int $post_id, string $title, array $units ): string {
		/* translators: %s: comma-separated unit ids from the XLIFF file, for example: post-42-title, post-42-content. */
		$unit_part = sprintf( _n( 'unit %s', 'units %s', count( $units ), 'perflocale' ), self::unit_list( $units ) );
		$title     = self::short_title( $title );

		if ( $title === '' ) {
			/* translators: 1: post ID. 2: the unit ids, for example: units post-42-title, post-42-content. */
			return sprintf( __( 'Post %1$d (%2$s)', 'perflocale' ), $post_id, $unit_part );
		}

		/* translators: 1: post ID. 2: post title. 3: the unit ids, for example: units post-42-title, post-42-content. */
		return sprintf( __( 'Post %1$d "%2$s" (%3$s)', 'perflocale' ), $post_id, $title, $unit_part );
	}

	/**
	 * Unit ids joined with commas; a list longer than UNIT_LIST_MAX names the first UNIT_LIST_SHOWN.
	 *
	 * @param array<int, string> $units Unit ids.
	 * @return string
	 */
	public static function unit_list( array $units ): string {
		$units = array_values( $units );
		$count = count( $units );

		if ( $count <= self::UNIT_LIST_MAX ) {
			return implode( ', ', $units );
		}

		return sprintf(
			/* translators: 1: comma-separated unit ids. 2: how many more unit ids there are. */
			__( '%1$s and %2$d more', 'perflocale' ),
			implode( ', ', array_slice( $units, 0, self::UNIT_LIST_SHOWN ) ),
			$count - self::UNIT_LIST_SHOWN
		);
	}

	/**
	 * A title on one line, without markup, cut to TITLE_MAX characters.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	public static function short_title( string $title ): string {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $title ) ) );

		if ( mb_strlen( $title ) > self::TITLE_MAX ) {
			$title = rtrim( mb_substr( $title, 0, self::TITLE_MAX - 3 ) ) . '...';
		}

		return $title;
	}
}
