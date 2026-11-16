<?php
/**
 * Admin: outdated string translations held for review.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Automation\ChangeService;
use PerfLocale\Automation\StringTarget;
use PerfLocale\Database\Repository\PendingChangeReads;
use PerfLocale\Database\Repository\PendingChangeRepository;
use PerfLocale\Helper;
use PerfLocale\Strings\Domains;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Strings page's review of outdated strings: each new MT of an outdated string is a pending string change,
 * listed with Apply / Reject (the handlers re-check capability and live value). Restricted domains are not listed.
 * One indexed statement per page for a user who may decide; the notice reads its change only under its flag.
 */
final class StringReview {

	/** The redirect flag of a decision (its value is the change id). */
	public const OUTCOME_FLAG = 'perflocale_string_review';

	/** Rows the list shows, at most. */
	public const LIMIT = 20;

	/**
	 * The outcome notice and the list, as HTML (escaped as it is written).
	 *
	 * @return string
	 */
	public static function html(): string {
		if ( ! current_user_can( 'perflocale_manage_translations' ) || ! PendingChangeReads::available() ) {
			return '';
		}

		return self::outcome_html() . self::list_html();
	}

	/**
	 * The elements and attributes html() writes, for the kses pass at the sink.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$plain = [
			'class' => true,
			'id'    => true,
			'role'  => true,
		];

		return [
			'div'     => $plain,
			'p'       => $plain,
			'h2'      => $plain,
			'table'   => $plain,
			'caption' => $plain,
			'thead'   => $plain,
			'tbody'   => $plain,
			'tr'      => $plain,
			'th'      => $plain + [ 'scope' => true ],
			'td'      => $plain + [
				'lang' => true,
				'dir'  => true,
			],
			'ol'      => $plain,
			'li'      => $plain,
			'form'    => $plain + [
				'method' => true,
				'action' => true,
			],
			'input'   => [
				'type'  => true,
				'name'  => true,
				'value' => true,
			],
			'button'  => $plain + [
				'type'             => true,
				'aria-describedby' => true,
			],
		];
	}

	/**
	 * The notice of a decision just taken, with Undo after an Apply.
	 *
	 * @return string
	 */
	private static function outcome_html(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display flags only.
		if ( ! isset( $_GET[ self::OUTCOME_FLAG ] ) ) {
			return '';
		}

		$id   = max( 0, Scalar::int( wp_unslash( $_GET[ self::OUTCOME_FLAG ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A non-negative integer through Scalar::int(); never output or stored as text.
		$code = isset( $_GET['outcome'] ) ? sanitize_key( Scalar::str( wp_unslash( $_GET['outcome'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() is the sanitiser; Scalar::str() only types the value for it.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$outcome = ChangeService::outcome( $code );
		$html    = '<div class="notice ' . ( $outcome['ok'] ? 'notice-success' : 'notice-warning' ) . ' perflocale-string-review-outcome" role="status"><p>' . esc_html( $outcome['message'] ) . '</p>';
		$row     = 'applied' === $code && $id > 0 ? ( new PendingChangeRepository() )->find( $id ) : null;

		if ( null !== $row && 'string' === $row['object_type'] && PendingChangeRepository::STATUS_APPLIED === $row['status'] && ChangeService::may_decide( $row, 'undo' ) ) {
			$html .= self::form( 'undo', $id, __( 'Undo', 'perflocale' ), 'button' );
		}

		return $html . '</div>';
	}

	/**
	 * The pending string changes, newest first.
	 *
	 * @return string
	 */
	private static function list_html(): string {
		// One row more than the list shows tells whether more are waiting, without a count.
		$rows = PendingChangeReads::strings( self::LIMIT + 1, Domains::restricted_for_current_user() );
		$more = count( $rows ) > self::LIMIT;
		$rows = array_slice( $rows, 0, self::LIMIT );

		if ( [] === $rows ) {
			return '';
		}

		$by_lang = [];

		foreach ( $rows as $row ) {
			$by_lang[ $row['language_id'] ][] = $row['object_id'];
		}

		$states = [];

		foreach ( $by_lang as $lang => $ids ) {
			// A failed read lists the rows without their current text; Apply rereads the string before it writes.
			$states[ $lang ] = StringTarget::snapshot( $ids, $lang ) ?? [];
		}

		$langs = \PerfLocale\Plugin::get_instance()->lang_repo();
		$html  = '<div class="perflocale-string-review" id="perflocale-string-review">';
		$html .= '<h2>' . esc_html__( 'String updates waiting for review', 'perflocale' ) . '</h2>';
		$html .= '<p class="description">' . esc_html__( 'These strings changed in their source. Their new machine translations are not live until you apply them. A string whose new translation is rejected or undone is left out of "Translate outdated and missing strings" until its source text, its translation or the context sent with it changes.', 'perflocale' ) . '</p>';
		$html .= '<table class="widefat striped perflocale-string-review__table"><caption class="screen-reader-text">' . esc_html__( 'String updates waiting for review', 'perflocale' ) . '</caption><thead><tr>';
		$html .= '<th scope="col">' . esc_html__( 'Original', 'perflocale' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Language', 'perflocale' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Current translation', 'perflocale' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Proposed translation', 'perflocale' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Actions', 'perflocale' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$state    = $states[ $row['language_id'] ][ $row['object_id'] ] ?? null;
			$language = $langs->find( $row['language_id'] );
			$label    = null === $language ? (string) $row['language_id'] : Scalar::str( Scalar::prop( $language, 'native_name' ) );
			$label    = '' !== $label ? $label : Scalar::str( Scalar::prop( $language, 'slug' ) );
			$current  = null === $state ? [] : array_merge( [ $state['translation'] ], $state['extra_forms'] );
			$extra    = $row['payload']['extra_forms'] ?? null;
			$proposed = array_merge( [ Scalar::str( $row['payload']['translation'] ?? '' ) ], is_array( $extra ) ? Scalar::strings( $extra ) : ( null === $state ? [] : $state['extra_forms'] ) );
			$plural   = count( $current ) > 1 || count( $proposed ) > 1;
			$cells    = 'perflocale-sr-o-' . $row['id'] . ' perflocale-sr-l-' . $row['id'];
			$lang     = self::lang_attrs( $language );

			$html .= '<tr>';
			$html .= '<td id="perflocale-sr-o-' . esc_attr( (string) $row['id'] ) . '">' . esc_html( null === $state ? '' : $state['original'] ) . '</td>';
			$html .= '<td id="perflocale-sr-l-' . esc_attr( (string) $row['id'] ) . '">' . esc_html( $label ) . '</td>';
			$html .= '<td' . $lang . '>' . self::forms_html( $current, $plural ) . '</td>';
			$html .= '<td' . $lang . '>' . self::forms_html( $proposed, $plural ) . '</td>';
			$html .= '<td class="perflocale-string-review__actions">' . self::form( 'apply', $row['id'], __( 'Apply', 'perflocale' ), 'button button-primary', $cells ) . ' ' . self::form( 'reject', $row['id'], __( 'Reject', 'perflocale' ), 'button', $cells ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		if ( $more ) {
			$html .= '<p class="description perflocale-string-review__more">' . esc_html(
				sprintf(
					/* translators: %d: number of string updates the list shows. */
					__( 'The %d newest string updates are shown. Apply or reject them to see the others.', 'perflocale' ),
					self::LIMIT
				)
			) . '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * A translation cell: the text, or every plural form as a numbered list
	 * (Apply writes forms 2..N too; null extra forms keep the stored ones).
	 *
	 * @param array<int, string> $forms  Form 1 first.
	 * @param bool               $plural Whether the row has more than one form.
	 * @return string
	 */
	private static function forms_html( array $forms, bool $plural ): string {
		if ( ! $plural ) {
			return esc_html( $forms[0] ?? '' );
		}

		$html = '<ol class="perflocale-string-review__forms">';

		foreach ( $forms as $form ) {
			$html .= '<li>' . esc_html( $form ) . '</li>';
		}

		return $html . '</ol>';
	}

	/**
	 * ` lang="..." dir="ltr|rtl"` of a language (its locale as BCP 47, else its slug), or '' without one.
	 *
	 * @param object|null $language Language row.
	 * @return string
	 */
	public static function lang_attrs( ?object $language ): string {
		$locale = Scalar::str( Scalar::prop( $language, 'locale' ) );
		$tag    = Helper::format_locale_as_bcp47( '' !== $locale ? $locale : Scalar::str( Scalar::prop( $language, 'slug' ) ) );

		if ( '' === $tag ) {
			return '';
		}

		return ' lang="' . esc_attr( $tag ) . '" dir="' . ( 'rtl' === Scalar::prop( $language, 'text_direction' ) ? 'rtl' : 'ltr' ) . '"';
	}

	/**
	 * A decision form posting to the per-translation review handlers.
	 *
	 * @param string $decision apply, reject or undo.
	 * @param int    $id       Change id.
	 * @param string $label    Button text.
	 * @param string $classes  Button classes.
	 * @param string $cells    Ids of the cells that name the row (aria-describedby), or ''.
	 * @return string
	 */
	private static function form( string $decision, int $id, string $label, string $classes, string $cells = '' ): string {
		return '<form method="post" class="perflocale-string-review__form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( 'perflocale_review_' . $decision ) . '">'
			. '<input type="hidden" name="change" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'perflocale_review_' . $decision . '_' . $id ) ) . '">'
			. '<button type="submit" class="' . esc_attr( $classes ) . '"' . ( '' === $cells ? '' : ' aria-describedby="' . esc_attr( $cells ) . '"' ) . '>' . esc_html( $label ) . '</button>'
			. '</form>';
	}
}
