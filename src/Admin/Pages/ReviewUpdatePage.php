<?php
/**
 * Per-translation "Review update" screen.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\Pages;

use PerfLocale\Admin\ObjectLinks;
use PerfLocale\Automation\ChangeService;
use PerfLocale\Automation\PostPayload;
use PerfLocale\Automation\PostTarget;
use PerfLocale\Database\Repository\PendingChangeRepository as Repo;
use PerfLocale\Database\Repository\ResyncRepository;
use PerfLocale\Helper;
use PerfLocale\Resync\DiffBudget;
use PerfLocale\Resync\SaveProbe;
use PerfLocale\Resync\Store;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The review of one held post change (`admin.php?page=perflocale-review-update&change=<id>`, no menu entry).
 * An id the viewer may not decide answers the same 404 as a missing one. The forms come before the diffs, so they
 * stay reachable however long the diffs are; source-language text shows only to a viewer who can read the source.
 *
 * @phpstan-import-type ChangeRow from Repo
 * @phpstan-import-type LiveRead from PostTarget
 */
final class ReviewUpdatePage {

	/** Id of the heading that names the group of decision forms. */
	private const ACTIONS_TITLE_ID = 'perflocale-review-actions-title';

	/** Id of the notice about the translation's state (a later edit, a first resync, an unfinished Apply). */
	private const HOLD_NOTICE_ID = 'perflocale-review-hold';

	/** Id of the warning that the viewer's HTML rules would change the proposal. */
	private const KSES_NOTICE_ID = 'perflocale-review-kses';

	/**
	 * The work budget of this screen's diffs.
	 *
	 * @var DiffBudget
	 */
	private DiffBudget $budget;

	/** Start with a full diff budget. */
	public function __construct() {
		$this->budget = new DiffBudget();
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'perflocale_translate' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Read-only routing of a GET screen (every action posts with its own nonce): a non-negative integer through Scalar::int() and a key through sanitize_key(), neither output nor stored.
		$id      = Scalar::int( $_GET['change'] ?? 0 );
		$outcome = sanitize_key( Scalar::str( wp_unslash( $_GET['outcome'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput

		$row = $id > 0 && ChangeService::available() ? ( new Repo() )->find( $id ) : null;

		// A change the viewer may not decide gets the answer of a missing one, so the answer never tells them apart.
		if ( null === $row || 'post' !== $row['object_type'] || ! ChangeService::may_decide( $row, 'approve' ) ) {
			wp_die( esc_html__( 'The change no longer exists.', 'perflocale' ), '', [ 'response' => 404 ] );
		}

		$translation_id = $row['object_id'];
		$source_id      = $row['source_id'];
		$translation    = get_post( $translation_id );
		$source         = get_post( $source_id );
		$summary        = Repo::decode( $row['summary'] );
		$keys           = Scalar::strings( $summary['fields'] ?? [] );
		$read           = $translation instanceof \WP_Post ? PostTarget::read( $translation_id, $keys ) : null;
		$can_read_src   = $source instanceof \WP_Post && \PerfLocale\Util\ObjectPermission::read_text( $source );

		echo '<div class="wrap perflocale-review">';
		echo '<h1>' . esc_html__( 'Review update', 'perflocale' ) . '</h1>';

		$this->notice( $outcome, $translation_id );
		$this->header( $row, $translation, $source, $can_read_src );

		if ( Repo::STATUS_PENDING === $row['status'] ) {
			$this->pending( $row, $summary, $keys, $read, $source, $can_read_src, self::unfinished_apply( $row, $read ) );
		} elseif ( Repo::STATUS_APPLIED === $row['status'] ) {
			$this->applied( $row, $keys, $read );
		} else {
			echo '<p>' . esc_html( ChangeService::status_label( $row['status'] ) ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * The outcome notice after a redirect; `post_locked` names the holder of a fresh lock that is not the viewer's.
	 *
	 * @param string $outcome        Outcome code ('' = none).
	 * @param int    $translation_id The translation.
	 * @return void
	 */
	private function notice( string $outcome, int $translation_id ): void {
		if ( '' === $outcome ) {
			return;
		}

		$result  = ChangeService::outcome( $outcome );
		$class   = $result['ok'] ? 'notice-success' : 'notice-error';
		$message = $result['message'];

		if ( 'post_locked' === $outcome ) {
			$holder = PostTarget::lock_user( $translation_id );
			$user   = $holder > 0 && get_current_user_id() !== $holder ? get_userdata( $holder ) : false;

			if ( $user instanceof \WP_User ) {
				/* translators: %s: the display name of the user editing the translation. */
				$message = sprintf( __( 'Nothing was written: %s is currently editing this translation.', 'perflocale' ), $user->display_name );
			}
		}

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * The translation, its source and language.
	 *
	 * @param array<string, int|string> $row          Change row.
	 * @phpstan-param ChangeRow $row
	 * @param \WP_Post|null             $translation  Translation.
	 * @param \WP_Post|null             $source       Source.
	 * @param bool                      $can_read_src Whether the viewer may read the source.
	 * @return void
	 */
	private function header( array $row, ?\WP_Post $translation, ?\WP_Post $source, bool $can_read_src ): void {
		echo '<table class="form-table perflocale-review__facts" role="presentation"><tbody>';

		$title = $translation instanceof \WP_Post ? get_the_title( $translation ) : __( '(deleted)', 'perflocale' );
		$edit  = $translation instanceof \WP_Post ? ObjectLinks::edit_url( $translation->ID ) : '';
		echo '<tr><th scope="row">' . esc_html__( 'Translation', 'perflocale' ) . '</th><td>';

		if ( '' !== $edit ) {
			echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}

		echo ' <span class="description">' . esc_html( \PerfLocale\Admin\ResyncNotices::language_label( $row['language_id'] ) ) . '</span></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Source', 'perflocale' ) . '</th><td>';

		if ( $source instanceof \WP_Post && $can_read_src ) {
			$src_edit = ObjectLinks::edit_url( $source->ID );
			echo '' !== $src_edit ? '<a href="' . esc_url( $src_edit ) . '">' . esc_html( get_the_title( $source ) ) . '</a>' : esc_html( get_the_title( $source ) );
		} elseif ( $source instanceof \WP_Post ) {
			echo esc_html__( 'A source you cannot read.', 'perflocale' );
		} else {
			echo esc_html__( '(deleted)', 'perflocale' );
		}

		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Status', 'perflocale' ) . '</th><td>' . esc_html( ChangeService::status_label( Scalar::str( $row['status'] ) ) );

		// Why a waiting update is held, on its own line; a decided row shows its status only.
		if ( Repo::STATUS_PENDING === $row['status'] && '' !== Scalar::str( $row['reason'] ) && 'policy' !== $row['reason'] && 'approved' !== $row['reason'] ) {
			$texts = self::flag_texts( [ Scalar::str( $row['reason'] ) ] );
			/* translators: %s: why the update waits for review. */
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Held because: %s', 'perflocale' ), (string) reset( $texts ) ) ) . '</span>';
		}

		echo '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * A pending change: summary, flags, warnings, the forms, parts, diffs.
	 *
	 * @param array<string, int|string> $row          Change row.
	 * @phpstan-param ChangeRow $row
	 * @param array<mixed>              $summary      Summary.
	 * @param array<int, string>        $keys         Field keys.
	 * @param array<string, mixed>|null $read         The live read.
	 * @phpstan-param LiveRead|null $read
	 * @param \WP_Post|null             $source       The source.
	 * @param bool                      $can_read_src Whether the viewer may read the source.
	 * @param bool                      $unfinished   Whether an Apply of this row died after its write (the next decision reconciles it).
	 * @return void
	 */
	private function pending( array $row, array $summary, array $keys, ?array $read, ?\WP_Post $source, bool $can_read_src, bool $unfinished ): void {
		$id      = $row['id'];
		$payload = Repo::decode( $row['payload'] );
		$clean   = $source instanceof \WP_Post && null !== $read ? PostPayload::validate( $payload, PostPayload::approvable_keys( $source, $row['object_id'], $keys ) ) : null;

		$parts = self::summary_parts( $summary );

		if ( [] !== $parts ) {
			echo '<p class="perflocale-review__summary">' . esc_html( implode( ', ', $parts ) . '.' ) . '</p>';
		}

		$flags = Scalar::strings( $summary['flags'] ?? [] );

		if ( [] !== $flags ) {
			echo '<ul class="perflocale-review__flags">';

			foreach ( self::flag_texts( $flags ) as $flag => $text ) {
				echo '<li><code>' . esc_html( $flag ) . '</code> ' . esc_html( $text ) . '</li>';
			}

			echo '</ul>';
		}

		if ( null === $read || ! $source instanceof \WP_Post || null === $clean ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( null === $clean && null !== $read && $source instanceof \WP_Post ? ChangeService::outcome( 'invalid_payload' )['message'] : ChangeService::outcome( 'target_gone' )['message'] ) . '</p></div>';
			$this->reject_form( $id );

			return;
		}

		// A proposal holding source-language text is shown and applied only to a viewer who can read the source
		// (approve refuses it the same way).
		if ( ! $can_read_src && PostPayload::holds_source_text( $clean['parts'], $summary, $clean['parts_complete'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This update keeps text from a source you cannot read. Only a user who can read the source can review and apply it.', 'perflocale' ) . '</p></div>';
			$this->reject_form( $id );

			return;
		}

		$edited   = $read['hash'] !== $row['base_hash'] || $read['digest'] !== Scalar::str( $clean['base_fields_hash'] );
		$describe = [];

		if ( $unfinished ) {
			// The translation holds what that Apply wrote; the next decision reconciles it and records the row applied.
			$edited     = false;
			$describe[] = self::HOLD_NOTICE_ID;
			echo '<div class="notice notice-info inline"><p id="' . esc_attr( self::HOLD_NOTICE_ID ) . '">' . esc_html__( 'An earlier Apply of this update did not finish. Apply or Reject completes its record first; this screen then shows the result with Undo.', 'perflocale' ) . '</p></div>';
		} else {
			$notice = self::hold_notice( Scalar::str( $row['reason'] ), $edited );

			if ( '' !== $notice ) {
				$describe[] = self::HOLD_NOTICE_ID;
				echo '<div class="notice ' . ( $edited ? 'notice-warning' : 'notice-info' ) . ' inline"><p id="' . esc_attr( self::HOLD_NOTICE_ID ) . '">' . esc_html( $notice ) . '</p></div>';
			}
		}

		// The viewer's own HTML rules: the save filters over stored title, excerpt and field values
		// (SaveProbe::stored()).
		$kses_ok = false;
		$fields  = SaveProbe::fields_of( $clean, $read['post']->post_title, $read['post']->post_excerpt );
		$stored  = SaveProbe::stored( $fields, $clean['meta'], $row['object_id'], $row['source_id'] );
		$differs = $stored['held_fields'];
		$altered = $stored['held_meta'];

		if ( [] !== $differs || [] !== $altered ) {
			$kses_ok = true;
			$removed = [];

			foreach ( $differs as $field ) {
				$removed = array_merge( $removed, SaveProbe::removed_tags( $fields[ $field ], $stored['fields'][ $field ] ?? '' ) );
			}

			foreach ( $altered as $key ) {
				$removed = array_merge( $removed, SaveProbe::removed_tags( Scalar::str( $clean['meta'][ $key ] ?? '' ), Scalar::str( $stored['meta'][ $key ] ?? '' ) ) );
			}

			$differs = array_merge( $differs, $altered );

			$removed = array_values( array_unique( $removed ) );
			sort( $removed );

			$describe[] = self::KSES_NOTICE_ID;
			echo '<div class="notice notice-warning inline"><p id="' . esc_attr( self::KSES_NOTICE_ID ) . '">';
			echo esc_html__( 'Your account cannot store this update exactly as proposed: the rules for what your account can save would change it.', 'perflocale' );
			echo ' ' . esc_html( sprintf( /* translators: %s: field names */ __( 'Fields affected: %s.', 'perflocale' ), implode( ', ', $differs ) ) );

			if ( [] !== $removed ) {
				echo ' ' . esc_html( sprintf( /* translators: %s: HTML tag names */ __( 'Tags that would be removed: %s.', 'perflocale' ), implode( ', ', $removed ) ) );
			}

			echo ' ' . esc_html__( 'Apply stores it as those rules change it.', 'perflocale' );
			echo '</p></div>';
		}

		// The decision comes before the parts and diffs: on the page before any diff is computed.
		$described = implode( ' ', $describe );
		self::actions_open( __( 'Decide on this update', 'perflocale' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="perflocale-review__form">';
		echo '<input type="hidden" name="action" value="perflocale_review_apply">';
		echo '<input type="hidden" name="change" value="' . esc_attr( (string) $id ) . '">';

		if ( $kses_ok ) {
			echo '<input type="hidden" name="kses_ok" value="1">';
		}

		wp_nonce_field( 'perflocale_review_apply_' . $id );
		echo '<button type="submit" class="button button-primary"' . ( $edited ? ' disabled' : '' ) . ( '' === $described ? '' : ' aria-describedby="' . esc_attr( $described ) . '"' ) . '>' . esc_html__( 'Apply', 'perflocale' ) . '</button> ';
		echo '<button type="submit" class="button" name="edit" value="1"' . ( $edited ? ' disabled' : '' ) . ( '' === $described ? '' : ' aria-describedby="' . esc_attr( $described ) . '"' ) . '>' . esc_html__( 'Apply and edit', 'perflocale' ) . '</button>';
		echo '</form>';
		$this->reject_form( $id );
		echo '</div>';

		$plugin = \PerfLocale\Plugin::get_instance();
		$this->parts_table(
			$clean['parts'],
			$can_read_src,
			$plugin->lang_repo()->find( $row['language_id'] ),
			$can_read_src ? ( new \PerfLocale\Translation\PostTranslationManager( $plugin->cache(), $plugin->settings() ) )->detect_post_language( $row['source_id'] ) : null
		);

		$post = $read['post'];
		$this->diff( __( 'Title', 'perflocale' ), $post->post_title, is_string( $clean['post_title'] ) ? $clean['post_title'] : $post->post_title );
		$this->diff( __( 'Content', 'perflocale' ), $post->post_content, Scalar::str( $clean['post_content'] ) );
		$this->diff( __( 'Excerpt', 'perflocale' ), $post->post_excerpt, is_string( $clean['post_excerpt'] ) ? $clean['post_excerpt'] : $post->post_excerpt );

		foreach ( $clean['meta'] as $key => $value ) {
			$current = $read['fields'][ $key ] ?? null;
			/* translators: %s: custom field key */
			$this->diff( sprintf( __( 'Field %s', 'perflocale' ), $key ), null === $current ? '' : $current, null === $value ? '' : $value, null === $value );
		}
	}

	/**
	 * Open the group of decision forms, named by a screen-reader heading.
	 *
	 * @param string $label The group's name.
	 * @return void
	 */
	private static function actions_open( string $label ): void {
		echo '<h2 id="' . esc_attr( self::ACTIONS_TITLE_ID ) . '" class="screen-reader-text">' . esc_html( $label ) . '</h2>';
		echo '<div class="perflocale-review__actions" role="group" aria-labelledby="' . esc_attr( self::ACTIONS_TITLE_ID ) . '">';
	}

	/**
	 * The Reject form.
	 *
	 * @param int $id Change id.
	 * @return void
	 */
	private function reject_form( int $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="perflocale-review__form">';
		echo '<input type="hidden" name="action" value="perflocale_review_reject">';
		echo '<input type="hidden" name="change" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'perflocale_review_reject_' . $id );
		echo '<button type="submit" class="button">' . esc_html__( 'Reject', 'perflocale' ) . '</button>';
		echo '</form>';
	}

	/**
	 * An applied change: Undo while nothing changed since (or why not), then before and now.
	 *
	 * @param array<string, int|string> $row  Change row.
	 * @phpstan-param ChangeRow $row
	 * @param array<int, string>        $keys Field keys.
	 * @param array<string, mixed>|null $read The live read.
	 * @phpstan-param LiveRead|null $read
	 * @return void
	 */
	private function applied( array $row, array $keys, ?array $read ): void {
		$id       = $row['id'];
		$previous = Repo::decode( $row['previous'] );
		$blocked  = ChangeService::undo_blocked( $row );
		$why      = '' !== $blocked ? $blocked : ( ChangeService::may_decide( $row, 'undo' ) ? PostTarget::unchanged_since( $row ) : 'forbidden' );

		// Undo, or why it is not offered, comes before "Before and now".
		if ( '' !== $why ) {
			echo '<p class="description">' . esc_html( ChangeService::outcome( $why )['message'] ) . '</p>';
		} else {
			self::actions_open( __( 'Undo this update', 'perflocale' ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="perflocale-review__form">';
			echo '<input type="hidden" name="action" value="perflocale_review_undo">';
			echo '<input type="hidden" name="change" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( 'perflocale_review_undo_' . $id );
			echo '<button type="submit" class="button">' . esc_html__( 'Undo', 'perflocale' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}

		if ( null === $read || '' !== $blocked || ! PostTarget::previous_valid( $previous ) ) {
			return;
		}

		$post = $read['post'];
		echo '<h2>' . esc_html__( 'Before and now', 'perflocale' ) . '</h2>';
		$this->diff( __( 'Title', 'perflocale' ), Scalar::str( $previous['post_title'] ), $post->post_title, false, __( 'Before', 'perflocale' ), __( 'Now', 'perflocale' ) );
		$this->diff( __( 'Content', 'perflocale' ), Scalar::str( $previous['post_content'] ), $post->post_content, false, __( 'Before', 'perflocale' ), __( 'Now', 'perflocale' ) );
		$this->diff( __( 'Excerpt', 'perflocale' ), Scalar::str( $previous['post_excerpt'] ), $post->post_excerpt, false, __( 'Before', 'perflocale' ), __( 'Now', 'perflocale' ) );

		$meta = is_array( $previous['meta'] ?? null ) ? $previous['meta'] : [];

		foreach ( $keys as $key ) {
			$spec   = $meta[ $key ] ?? null;
			$before = is_array( $spec ) && ! empty( $spec['exists'] ) ? Scalar::str( $spec['value'] ?? '' ) : '';
			$now    = $read['fields'][ $key ] ?? null;
			/* translators: %s: custom field key */
			$this->diff( sprintf( __( 'Field %s', 'perflocale' ), $key ), $before, null === $now ? '' : $now, false, __( 'Before', 'perflocale' ), __( 'Now', 'perflocale' ) );
		}
	}

	/**
	 * The parts table from the payload; a part that replaces a person's text is labelled.
	 *
	 * @param array<int, mixed> $parts        Parts.
	 * @param bool              $can_read_src Whether the source text may be shown.
	 * @param object|null       $target_lang  The translation's language (lang/dir of its cells).
	 * @param object|null       $source_lang  The source post's language.
	 * @return void
	 */
	private function parts_table( array $parts, bool $can_read_src, ?object $target_lang = null, ?object $source_lang = null ): void {
		if ( [] === $parts ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Parts', 'perflocale' ) . '</h2>';
		echo '<table class="widefat striped perflocale-review__parts"><thead><tr>';
		echo '<th>' . esc_html__( 'Part', 'perflocale' ) . '</th><th>' . esc_html__( 'Status', 'perflocale' ) . '</th>';

		if ( $can_read_src ) {
			echo '<th>' . esc_html__( 'New source text', 'perflocale' ) . '</th>';
		}

		echo '<th>' . esc_html__( 'Current translation', 'perflocale' ) . '</th><th>' . esc_html__( 'Proposed', 'perflocale' ) . '</th>';
		echo '</tr></thead><tbody>';

		$target = \PerfLocale\Admin\StringReview::lang_attrs( $target_lang );
		$source = \PerfLocale\Admin\StringReview::lang_attrs( $source_lang );
		// The opening tags go through wp_kses at the echo; the cell text stays esc_html only.
		$tags = array_fill_keys( [ 'td', 'span' ], array_fill_keys( [ 'lang', 'dir' ], true ) );

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			echo '<tr>';
			echo '<td>' . esc_html( trim( Scalar::str( $part['role'] ?? '' ) . ' ' . Scalar::str( $part['block'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( Scalar::str( $part['status'] ?? '' ) ) . '</td>';

			if ( $can_read_src ) {
				echo wp_kses( '<td' . $source . '>', $tags ) . esc_html( Scalar::str( $part['source'] ?? '' ) ) . '</td>';
			}

			$note = self::part_note( $part );

			// The note is admin text, so the language goes on the translation's span, not the cell.
			echo '<td>' . wp_kses( '<span' . $target . '>', $tags ) . esc_html( Scalar::str( $part['current'] ?? '' ) ) . '</span>' . ( '' === $note ? '' : '<p class="description">' . esc_html( $note ) . '</p>' ) . '</td>';
			echo wp_kses( '<td' . $target . '>', $tags ) . esc_html( Scalar::str( $part['proposed'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The label of a part whose current text a person wrote or changed (not ReSync's last output), whatever the
	 * setting for such blocks; '' otherwise.
	 *
	 * @param array<mixed> $part A stored part.
	 * @return string
	 */
	public static function part_note( array $part ): string {
		return true === ( $part['person'] ?? null ) ? __( 'Written or changed outside ReSync; this update replaces it.', 'perflocale' ) : '';
	}

	/**
	 * One diff (wp_text_diff()), only when the texts differ; over the screen's diff budget, both texts in full.
	 *
	 * @param string $title   Heading.
	 * @param string $left    Left text.
	 * @param string $right   Right text.
	 * @param bool   $removed Whether the right side is a removal.
	 * @param string $left_t  Left column title.
	 * @param string $right_t Right column title.
	 * @return void
	 */
	private function diff( string $title, string $left, string $right, bool $removed = false, string $left_t = '', string $right_t = '' ): void {
		if ( $left === $right && ! $removed ) {
			return;
		}

		echo '<h2>' . esc_html( $title ) . ( $removed ? ' <span class="description">' . esc_html__( '(removed)', 'perflocale' ) . '</span>' : '' ) . '</h2>';

		$left_t  = '' === $left_t ? __( 'Now', 'perflocale' ) : $left_t;
		$right_t = '' === $right_t ? __( 'Proposed', 'perflocale' ) : $right_t;

		if ( ! $this->budget->take( $left, $right ) ) {
			self::plain( $left, $right, $left_t, $right_t );

			return;
		}

		$diff = wp_text_diff(
			$left,
			$right,
			[
				'title_left'      => $left_t,
				'title_right'     => $right_t,
				'show_split_view' => true,
			]
		);

		if ( '' === $diff ) {
			echo '<p class="description">' . esc_html__( 'No visible difference.', 'perflocale' ) . '</p>';

			return;
		}

		echo wp_kses_post( $diff );
	}

	/**
	 * Both texts in full, escaped, in two columns (a difference too large to compare on this screen).
	 *
	 * @param string $left    Left text.
	 * @param string $right   Right text.
	 * @param string $left_t  Left column title.
	 * @param string $right_t Right column title.
	 * @return void
	 */
	private static function plain( string $left, string $right, string $left_t, string $right_t ): void {
		echo '<p class="description">' . esc_html__( 'This difference is too large to compare line by line here, so both versions are shown in full.', 'perflocale' ) . '</p>';
		echo '<table class="widefat perflocale-review__plain"><thead><tr>';
		echo '<th scope="col">' . esc_html( $left_t ) . '</th><th scope="col">' . esc_html( $right_t ) . '</th>';
		echo '</tr></thead><tbody><tr>';
		echo '<td><pre>' . esc_html( $left ) . '</pre></td><td><pre>' . esc_html( $right ) . '</pre></td>';
		echo '</tr></tbody></table>';
	}

	/**
	 * Whether an Apply of a pending row died after its write: the state row holds an approve intent naming the
	 * row and the live read is one the reconciler records.
	 *
	 * @param array<string, int|string> $row  Change row.
	 * @phpstan-param ChangeRow $row
	 * @param array<string, mixed>|null $read The translation's live read; null takes the intent alone.
	 * @phpstan-param LiveRead|null $read
	 * @return bool
	 */
	public static function unfinished_apply( array $row, ?array $read = null ): bool {
		if ( ! Store::available() ) {
			return false;
		}

		$state = ( new ResyncRepository() )->state( $row['object_id'] );

		if ( null === $state || '' === $state['intent'] ) {
			return false;
		}

		$intent = Repo::decode( $state['intent'] );

		if ( 'approve' !== Scalar::str( $intent['kind'] ?? '' ) || Scalar::int( $intent['row_id'] ?? 0 ) !== $row['id'] ) {
			return false;
		}

		if ( null === $read ) {
			return true;
		}

		// The post's schedule never turns a plan into 'nothing'.
		$live = [
			'post_hash'     => $read['hash'],
			'fields_digest' => $read['digest'],
			'fields'        => $read['fields'],
			'status'        => $read['status'],
		];

		return 'nothing' !== \PerfLocale\Resync\SyncRecorder::reconcile_plan( $intent, $live, false );
	}

	/**
	 * What the screen says about a pending hold: the later-edit warning, or for an untouched `human_edit` hold the
	 * first-resync explanation (no automatic write recorded yet; Apply records one); '' otherwise.
	 *
	 * @param string $reason The row's reason.
	 * @param bool   $edited Whether the live hash or field digest moved since the proposal.
	 * @return string
	 */
	public static function hold_notice( string $reason, bool $edited ): string {
		if ( $edited ) {
			return __( 'The translation was changed after this update was computed. Apply will refuse it; reject it and run the resync again to get a proposal built on the current text.', 'perflocale' );
		}

		if ( 'human_edit' === $reason ) {
			return __( 'Held for review because this translation or one of its fields was changed outside ReSync, or no automatic write is recorded for it. Apply writes the proposal and records it; later updates follow the policy and the Content ReSync setting for blocks changed outside ReSync.', 'perflocale' );
		}

		return '';
	}

	/**
	 * The summary line's parts, one per non-zero count, in a fixed order.
	 *
	 * @param array<mixed> $summary The change's summary.
	 * @return list<string>
	 */
	public static function summary_parts( array $summary ): array {
		$texts = [
			/* translators: %d: number of parts */
			'reused'             => static fn ( int $n ): string => sprintf( _n( '%d part reused', '%d parts reused', $n, 'perflocale' ), $n ),
			/* translators: %d: number of parts */
			'machine_translated' => static fn ( int $n ): string => sprintf( _n( '%d machine translated', '%d machine translated', $n, 'perflocale' ), $n ),
			/* translators: %d: number of parts */
			'left_source'        => static fn ( int $n ): string => sprintf( _n( '%d left in the source language', '%d left in the source language', $n, 'perflocale' ), $n ),
			/* translators: %d: number of parts */
			'removed'            => static fn ( int $n ): string => sprintf( _n( '%d removed', '%d removed', $n, 'perflocale' ), $n ),
			/* translators: %d: number of parts */
			'fuzzy'              => static fn ( int $n ): string => sprintf( _n( '%d near match', '%d near matches', $n, 'perflocale' ), $n ),
			/* translators: %d: number of parts */
			'same_as_source'     => static fn ( int $n ): string => sprintf( _n( '%d same as the source', '%d same as the source', $n, 'perflocale' ), $n ),
			/* translators: %d: number of custom fields */
			'fields_changed'     => static fn ( int $n ): string => sprintf( _n( '%d field changed', '%d fields changed', $n, 'perflocale' ), $n ),
			/* translators: %d: number of custom fields */
			'unknown_base'       => static fn ( int $n ): string => sprintf( _n( '%d field with an unknown earlier value', '%d fields with an unknown earlier value', $n, 'perflocale' ), $n ),
		];
		$out   = [];

		foreach ( $texts as $key => $text ) {
			$n = Scalar::int( $summary[ $key ] ?? 0 );

			if ( $n > 0 ) {
				$out[] = $text( $n );
			}
		}

		return $out;
	}

	/**
	 * One explanation per distinct flag; an unknown flag shows its name.
	 *
	 * @param array<int, string> $flags Flags.
	 * @return array<string, string> Keyed by flag.
	 */
	public static function flag_texts( array $flags ): array {
		$texts = [
			'kses'             => __( 'The rules for what the acting user can save would change the proposal.', 'perflocale' ),
			'structure'        => __( 'The translation\'s structure differs from the source\'s in a way that could not be merged.', 'perflocale' ),
			'anchor'           => __( 'A block that exists only in the translation could not be placed next to its neighbour. It was put at the end of its container, or left out where the update has no place for it.', 'perflocale' ),
			'exceptions'       => __( 'A per-language exception (a block or removal only in the translation) was kept next to a changed source.', 'perflocale' ),
			'ambiguous'        => __( 'Blocks could not be matched one to one between the versions.', 'perflocale' ),
			'heuristic_base'   => __( 'The earlier source version was guessed from a revision.', 'perflocale' ),
			'protected_source' => __( 'The source is private or password protected.', 'perflocale' ),
			'mt_quality'       => __( 'A machine translation failed its quality check and the part kept the source text.', 'perflocale' ),
			'stored_differs'   => __( 'The stored bytes differed from the written ones.', 'perflocale' ),
			'human_edit'       => __( 'This translation or one of its fields was changed outside ReSync, or no automatic write is recorded for it.', 'perflocale' ),
			'person_block'     => __( 'The update changes or removes a block that was written or changed outside ReSync in this translation. The Content ReSync settings hold such updates for review.', 'perflocale' ),
			'revisions_unread' => __( 'This translation has too many revisions to read them all, so the text of the last automatic update is not known. Every block counts as changed outside ReSync. Check each change before you apply it.', 'perflocale' ),
			'post_locked'      => __( 'Another user was editing the translation.', 'perflocale' ),
			'missed_schedule'  => __( 'The translation is scheduled to publish within two minutes or its date has passed.', 'perflocale' ),
			'not_safe'         => __( 'The update holds machine translations, near matches or parts left in the source language.', 'perflocale' ),
			'unknown_base'     => __( 'A field\'s earlier source value is unknown, so that field was kept as it is.', 'perflocale' ),
			'unsafe_markup'    => __( 'The update adds code (a script, an event attribute or a script link) that neither the source nor the translation holds, so it is never written. Reject it and run ReSync again.', 'perflocale' ),
		];
		$out   = [];

		foreach ( $flags as $flag ) {
			$flag = Scalar::str( $flag );

			if ( '' !== $flag ) {
				$out[ $flag ] = $texts[ $flag ] ?? $flag;
			}
		}

		return $out;
	}
}
