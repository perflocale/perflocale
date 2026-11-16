<?php
/**
 * Admin: the estimate step and the result of a Content ReSync run.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Background\Jobs\ContentResyncJob;
use PerfLocale\Resync\Engine;
use PerfLocale\Resync\Estimator;
use PerfLocale\Resync\RunRequest;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a person sees after pressing a Content ReSync button: the estimate step (with Start and Cancel; no Start
 * over an exact limit) and the result. Read only when the redirect carries their flag, so an ordinary screen reads
 * nothing new. Values are escaped where written and the HTML passes wp_kses_post() at the sink.
 *
 * @phpstan-type Line array{text: string, url: string, label: string, action: string}
 * @phpstan-type View array{status: string, title: string, lines: list<Line>, start: string, cancel: string, refusal: string, outcome?: string}
 * @phpstan-type Refusal array{source_id: int, language_id: int, translation_id: int, post_id: int, text: string}
 */
final class ResyncNotices {

	/** The longest title a memory refusal line prints, in characters. */
	private const TITLE_MAX = 200;

	/**
	 * Echo the estimate step or the result on a screen with admin notices (the block and Site Editors use their
	 * own notices). Called only when one of the two flags is present.
	 *
	 * @return void
	 */
	public static function admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen && ( 'site-editor' === $screen->base || $screen->is_block_editor() ) ) {
			return;
		}

		$view = self::current_view();

		if ( null !== $view ) {
			echo wp_kses_post( self::html( $view ) );
		}
	}

	/**
	 * The notice of this request for an editor's localized data, or null without a flag (nothing is read then).
	 *
	 * @return array{status: string, text: string, actions: list<array{label: string, url: string, nonce: string}>, outcome: string}|null
	 */
	public static function for_editor(): ?array {
		$view = self::current_view();

		return null === $view ? null : self::editor_notice( $view );
	}

	/**
	 * A view as an editor notice: one text and up to four actions, each named with its language so two actions of
	 * one kind can be told apart outside the text. A handler action carries its nonce ('' for a plain link).
	 * `outcome` tells the editors' JavaScript hooks a result from the estimate step (see view()).
	 *
	 * @param array<string, mixed> $view The view.
	 * @phpstan-param View $view
	 * @return array{status: string, text: string, actions: list<array{label: string, url: string, nonce: string}>, outcome: string}
	 */
	public static function editor_notice( array $view ): array {
		$title   = rtrim( $view['title'] );
		$texts   = [ '' === $title || 1 === preg_match( '/[.!?:]$/u', $title ) ? $title : $title . '.' ];
		$actions = [];

		foreach ( $view['lines'] as $line ) {
			$texts[] = $line['text'];

			if ( '' !== $line['url'] && count( $actions ) < 4 ) {
				$actions[] = [
					'label' => '' !== $line['action'] ? $line['action'] : $line['label'],
					'url'   => $line['url'],
					'nonce' => ResyncLinks::post_link_nonce( $line['url'] ),
				];
			}
		}

		if ( '' !== $view['refusal'] ) {
			$texts[] = $view['refusal'];
		}

		if ( '' !== $view['start'] ) {
			$actions = [
				[
					'label' => __( 'Start', 'perflocale' ),
					'url'   => $view['start'],
					'nonce' => ResyncLinks::post_link_nonce( $view['start'] ),
				],
			];
		}

		if ( '' !== $view['cancel'] ) {
			$actions[] = [
				'label' => __( 'Cancel', 'perflocale' ),
				'url'   => $view['cancel'],
				'nonce' => ResyncLinks::post_link_nonce( $view['cancel'] ),
			];
		}

		return [
			'status'  => $view['status'],
			'text'    => implode( ' ', $texts ),
			'actions' => $actions,
			'outcome' => $view['outcome'] ?? '',
		];
	}

	/**
	 * The view the request's flag asks for: the current user's estimate step or last result (read once).
	 * Null without a flag.
	 *
	 * @return array<string, mixed>|null
	 * @phpstan-return View|null
	 */
	public static function current_view(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flags; the token only names the current user's own run request.
		if ( isset( $_GET[ ResyncEntry::CONFIRM_FLAG ] ) ) {
			$token   = RunRequest::valid_token( Scalar::str( wp_unslash( $_GET[ ResyncEntry::CONFIRM_FLAG ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only a 32-character hex token passes valid_token().
			$request = '' === $token ? null : RunRequest::get( $token );

			if ( null === $request ) {
				return self::view( 'warning', __( 'Content ReSync', 'perflocale' ), [ self::line( __( 'This estimate has expired or the run was already started. Press the button again.', 'perflocale' ) ) ] );
			}

			return self::estimate_view( $request, $token );
		}

		if ( isset( $_GET[ ResyncEntry::DONE_FLAG ] ) ) {
			$result = RunRequest::take_result();

			return null === $result ? null : self::result_view( $result );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return null;
	}

	/**
	 * The estimate step of a run request.
	 *
	 * @param array<string, mixed> $request The run request.
	 * @param string               $token   Its token.
	 * @return array<string, mixed>
	 * @phpstan-return View
	 */
	public static function estimate_view( array $request, string $token ): array {
		$kind     = Scalar::str( $request['kind'] ?? '' );
		$estimate = RunRequest::keyed( $request['estimate'] ?? null );
		$args     = RunRequest::keyed( $request['args'] ?? null );
		$lines    = RunRequest::KIND_STRINGS === $kind ? self::strings_lines( $estimate ) : self::content_lines( $estimate, Scalar::str( $args['fill'] ?? '' ) );

		foreach ( self::limit_lines( $estimate ) as $line ) {
			$lines[] = $line;
		}

		$warning = Scalar::str( $estimate['migration_warning'] ?? '' );

		if ( '' !== $warning ) {
			$lines[] = self::line( $warning );
		}

		$refusal = RunRequest::KIND_STRINGS === $kind ? '' : ResyncEntry::machine_refusal( $args );
		$refusal = '' !== $refusal ? $refusal : ResyncEntry::limit_refusal( $estimate );
		$view    = self::view( '' === $refusal && '' === $warning ? 'info' : 'warning', self::title( $kind, true ), $lines, 'estimate' );

		$view['refusal'] = $refusal;
		$view['start']   = '' === $refusal ? self::link( 'perflocale_resync_start', $token ) : '';
		$view['cancel']  = self::link( 'perflocale_resync_cancel', $token );

		return $view;
	}

	/**
	 * The result of a run (or a handler's message).
	 *
	 * @param array<string, mixed> $r The result summary.
	 * @return array<string, mixed>
	 * @phpstan-return View
	 */
	public static function result_view( array $r ): array {
		$kind    = Scalar::str( $r['kind'] ?? '' );
		$mode    = Scalar::str( $r['mode'] ?? 'none' );
		$title   = self::title( $kind, false );
		$message = Scalar::str( $r['message'] ?? '' );
		$jobs    = admin_url( 'admin.php?page=perflocale-jobs' );

		if ( 'none' === $mode ) {
			return self::view( 'warning', $title, [ self::line( $message ) ], 'failed' );
		}

		if ( RunRequest::KIND_STRINGS === $kind ) {
			return self::strings_result( $title, $r );
		}

		if ( in_array( $mode, [ 'denied', 'error' ], true ) ) {
			return self::view( 'error', $title, [ self::line( __( 'The run did not start.', 'perflocale' ) . ( '' === $message ? '' : ' ' . $message ) ) ], 'failed' );
		}

		if ( 'async' === $mode ) {
			return self::view( 'info', $title, [ self::line( __( 'The run continues in the background.', 'perflocale' ), $jobs, __( 'Jobs', 'perflocale' ) ) ], 'background' );
		}

		$counts = is_array( $r['counts'] ?? null ) ? $r['counts'] : [];
		$items  = is_array( $r['items'] ?? null ) ? $r['items'] : [];
		$lines  = [];
		$warn   = false;

		if ( RunRequest::KIND_POST === $kind ) {
			foreach ( $items as $item ) {
				$item    = is_array( $item ) ? $item : [];
				$status  = Scalar::str( $item[3] ?? '' );
				$line    = self::item_line( Scalar::int( $item[1] ?? 0 ), $status, Scalar::int( $item[5] ?? 0 ), Scalar::str( $r['retry'] ?? '' ), Scalar::str( $item[4] ?? '' ) );
				$lines[] = $line;
				$warn    = $warn || ! in_array( $status, [ 'applied', 'held', 'held_same', 'unchanged', 'outdated', 'confirmed', 'filled', 'created' ], true );
			}

			if ( [] === $items ) {
				$lines[] = self::line( __( 'Nothing was resynced.', 'perflocale' ) );
			}
		} else {
			$lines[] = self::line(
				sprintf(
					/* translators: 1: translations updated, 2: held for review, 3: already up to date, 4: created, 5: not processed. */
					__( 'Updated: %1$d. Held for review: %2$d. Already up to date: %3$d. Created: %4$d. Not processed: %5$d.', 'perflocale' ),
					Scalar::int( $counts['applied'] ?? 0 ),
					Scalar::int( $counts['held'] ?? 0 ),
					Scalar::int( $counts['unchanged'] ?? 0 ) + Scalar::int( $counts['confirmed'] ?? 0 ),
					Scalar::int( $counts['created'] ?? 0 ),
					Scalar::int( $counts['not_processed'] ?? 0 )
				)
			);

			$outdated = self::outdated_line( Scalar::int( $counts['outdated'] ?? 0 ) );

			if ( null !== $outdated ) {
				$lines[] = $outdated;
			}

			if ( Scalar::int( $counts['filled_existing'] ?? 0 ) > 0 ) {
				$lines[] = self::line(
					sprintf(
						/* translators: %d: existing empty translations filled with machine translation. */
						__( 'Empty translations filled: %d.', 'perflocale' ),
						Scalar::int( $counts['filled_existing'] ?? 0 )
					)
				);
			}

			$links = 0;

			foreach ( $items as $item ) {
				$item = is_array( $item ) ? $item : [];

				if ( $links < 10 && in_array( Scalar::str( $item[3] ?? '' ), [ 'held', 'held_same' ], true ) && Scalar::int( $item[5] ?? 0 ) > 0 ) {
					$lines[] = self::item_line( Scalar::int( $item[1] ?? 0 ), Scalar::str( $item[3] ?? '' ), Scalar::int( $item[5] ?? 0 ), '' );
					++$links;
				}
			}

			$warn = Scalar::int( $counts['not_processed'] ?? 0 ) > 0 || Scalar::int( $counts['skipped_permission'] ?? 0 ) > 0;

			if ( Scalar::int( $counts['skipped_permission'] ?? 0 ) > 0 ) {
				$lines[] = self::line(
					sprintf(
						/* translators: %d: translations skipped. */
						__( 'Skipped because you may not edit them: %d.', 'perflocale' ),
						Scalar::int( $counts['skipped_permission'] ?? 0 )
					)
				);
			}

			$skipped = Scalar::int( $counts['skipped'] ?? 0 ) + Scalar::int( $counts['no_base'] ?? 0 ) + Scalar::int( $counts['unsupported'] ?? 0 );

			if ( $skipped > 0 ) {
				$lines[] = self::line(
					sprintf(
						/* translators: %d: translations skipped. */
						__( 'Skipped: %d (the source is not published or not covered, the update was rejected before, or the content is not supported for resync).', 'perflocale' ),
						$skipped
					)
				);
			}

			foreach ( array_slice( self::memory_refusals( $items ), 0, 10 ) as $refusal ) {
				$lines[] = self::line( self::memory_line( $refusal ) );
				$warn    = true;
			}
		}

		if ( '' !== Scalar::str( $r['continued'] ?? '' ) ) {
			$lines[] = self::line( __( 'The rest continues in the background.', 'perflocale' ), $jobs, __( 'Jobs', 'perflocale' ) );
		}

		$unavailable = self::mt_unavailable_text( Scalar::str( $r['mt_unavailable'] ?? '' ) );

		// The run's note names the cause already when pairs were left for it.
		if ( '' !== $unavailable && ! str_contains( Scalar::str( $r['first_error'] ?? '' ), $unavailable ) ) {
			$lines[] = self::line( $unavailable );
			$warn    = true;
		}

		if ( '' !== Scalar::str( $r['first_error'] ?? '' ) ) {
			$lines[] = self::line( Scalar::str( $r['first_error'] ) );
			$warn    = true;
		}

		// An inline run that ended with an error of its own.
		if ( '' !== $message ) {
			$lines[] = self::line( $message );
			$warn    = true;
		}

		return self::view( $warn ? 'warning' : 'success', $title, $lines, '' !== Scalar::str( $r['continued'] ?? '' ) ? 'background' : 'done' );
	}

	/**
	 * A view as notice HTML (escaped as it is written).
	 *
	 * @param array<string, mixed> $view The view.
	 * @phpstan-param View $view
	 * @return string
	 */
	public static function html( array $view ): string {
		$status = in_array( $view['status'], [ 'info', 'success', 'warning', 'error' ], true ) ? $view['status'] : 'info';
		$html   = '<div class="notice notice-' . esc_attr( $status ) . ' perflocale-resync-notice" role="status">';
		$html  .= '<p><strong>' . esc_html( $view['title'] ) . '</strong></p>';

		if ( [] !== $view['lines'] ) {
			$html .= '<ul class="perflocale-resync-notice__lines">';

			foreach ( $view['lines'] as $line ) {
				$html .= '<li>' . esc_html( $line['text'] );

				if ( '' !== $line['url'] ) {
					$html .= ' <a href="' . esc_url( $line['url'] ) . '"' . ResyncLinks::post_link_attributes( $line['url'] ) . '>' . esc_html( $line['label'] ) . '</a>';
				}

				$html .= '</li>';
			}

			$html .= '</ul>';
		}

		if ( '' !== $view['refusal'] ) {
			$html .= '<p class="perflocale-resync-notice__refusal">' . esc_html( $view['refusal'] ) . '</p>';
		}

		if ( '' !== $view['start'] || '' !== $view['cancel'] ) {
			$html .= '<p class="perflocale-resync-notice__actions">';

			if ( '' !== $view['start'] ) {
				$html .= '<a class="button button-primary" href="' . esc_url( $view['start'] ) . '"' . ResyncLinks::post_link_attributes( $view['start'] ) . '>' . esc_html__( 'Start', 'perflocale' ) . '</a> ';
			}

			if ( '' !== $view['cancel'] ) {
				$html .= '<a class="button" href="' . esc_url( $view['cancel'] ) . '"' . ResyncLinks::post_link_attributes( $view['cancel'] ) . '>' . esc_html__( 'Cancel', 'perflocale' ) . '</a>';
			}

			$html .= '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * The lines of a content estimate.
	 *
	 * @param array<string, mixed> $e    The estimate envelope.
	 * @param string               $fill The run's fill choice.
	 * @return list<array{text: string, url: string, label: string, action: string}>
	 */
	private static function content_lines( array $e, string $fill ): array {
		// A dry run counts parts (an exact estimate, or one an import stopped); an upper bound counts translations only.
		$parts = Estimator::BOUND_EXACT === Scalar::str( $e['bound'] ?? '' ) || '' !== Scalar::str( $e['stopped'] ?? '' );
		$lines = [
			self::line(
				sprintf(
					/* translators: %d: translations in the run. */
					__( 'Translations in this run: %d.', 'perflocale' ),
					Scalar::int( $e['pairs'] ?? 0 )
				)
			),
			self::line(
				$parts
					? sprintf(
						/* translators: 1: translations to update, 2: parts reused, 3: parts left in the source language, 4: parts machine translated. */
						__( 'Translations to update: %1$d. Parts reused: %2$d. Parts left in the source language for a translator: %3$d. Parts machine translated: %4$d.', 'perflocale' ),
						Scalar::int( $e['proposals'] ?? 0 ),
						Scalar::int( $e['reused'] ?? 0 ),
						Scalar::int( $e['left_source'] ?? 0 ),
						Scalar::int( $e['machine_translated'] ?? 0 )
					)
					: sprintf(
						/* translators: %d: translations to update. */
						__( 'Translations to update: %d. Parts are not counted in advance for a run this large.', 'perflocale' ),
						Scalar::int( $e['proposals'] ?? 0 )
					)
			),
			self::line(
				sprintf(
					/* translators: %d: translations already up to date. */
					__( 'Already up to date: %d.', 'perflocale' ),
					Scalar::int( $e['unchanged'] ?? 0 ) + Scalar::int( $e['confirmed'] ?? 0 )
				)
			),
		];

		$outdated = self::outdated_line( Scalar::int( $e['outdated'] ?? 0 ) );

		if ( null !== $outdated ) {
			$lines[] = $outdated;
		}

		if ( Scalar::int( $e['fills'] ?? 0 ) > 0 || '' !== $fill ) {
			$lines[] = self::line(
				sprintf(
					/* translators: 1: missing translations to create and empty drafts to fill, 2: how ("machine translated drafts" or "empty drafts"). */
					__( 'Translations to create or fill: %1$d, as %2$s.', 'perflocale' ),
					Scalar::int( $e['fills'] ?? 0 ),
					ContentResyncJob::FILL_MACHINE === $fill ? __( 'machine translated drafts', 'perflocale' ) : __( 'empty drafts', 'perflocale' )
				)
			);
		}

		$left = Scalar::int( $e['missing'] ?? 0 ) + Scalar::int( $e['empty'] ?? 0 );

		if ( $left > 0 ) {
			$lines[] = self::line(
				sprintf(
					/* translators: %d: missing or empty translations. */
					__( 'Missing or empty translations left as they are: %d.', 'perflocale' ),
					$left
				)
			);
		}

		foreach (
			[
				/* translators: %d: translations. */
				'skipped_permission' => __( 'Skipped because you may not edit them: %d.', 'perflocale' ),
				/* translators: %d: translations. */
				'busy'               => __( 'Busy right now, run again later: %d.', 'perflocale' ),
			] as $key => $format
		) {
			if ( Scalar::int( $e[ $key ] ?? 0 ) > 0 ) {
				$lines[] = self::line( sprintf( $format, Scalar::int( $e[ $key ] ?? 0 ) ) );
			}
		}

		$unsupported = Scalar::int( $e['no_base'] ?? 0 ) + Scalar::int( $e['unsupported'] ?? 0 );

		if ( $unsupported > 0 ) {
			$lines[] = self::line(
				sprintf(
					/* translators: %d: translations. */
					__( 'Not supported for resync: %d.', 'perflocale' ),
					$unsupported
				)
			);
		}

		$lines[] = self::line( self::chars_text( Scalar::int( $e['chars'] ?? 0 ), Scalar::str( $e['bound'] ?? '' ) ) );

		if ( Scalar::int( $e['proposals'] ?? 0 ) > 0 ) {
			$lines[] = self::line( __( 'Updates to published translations are held for review.', 'perflocale' ) );
		}

		if ( Scalar::int( $e['needs_mt'] ?? 0 ) > 0 ) {
			$lines[] = self::line(
				sprintf(
					/* translators: %d: translations whose changed parts need machine translation. */
					__( 'Translations that need machine translation: %d.', 'perflocale' ),
					Scalar::int( $e['needs_mt'] ?? 0 )
				)
			);
		}

		if ( \PerfLocale\Util\ResyncOff::reread_pending() ) {
			$lines[] = self::line( __( 'Content ReSync was off, so this run reads every source again.', 'perflocale' ) );
		}

		return $lines;
	}

	/**
	 * The lines of a Strings page estimate.
	 *
	 * @param array<string, mixed> $e The estimate.
	 * @return list<array{text: string, url: string, label: string, action: string}>
	 */
	private static function strings_lines( array $e ): array {
		$lines = [];

		if ( ! empty( $e['missing'] ) ) {
			$lines[] = self::line(
				sprintf(
					/* translators: 1: strings, 2: characters. */
					__( 'Missing strings to translate: %1$d, about %2$s characters.', 'perflocale' ),
					Scalar::int( $e['missing_strings'] ?? 0 ),
					number_format_i18n( Scalar::int( $e['missing_chars'] ?? 0 ) )
				)
			);
		}

		if ( ! empty( $e['outdated'] ) ) {
			$lines[] = self::line(
				sprintf(
					/* translators: 1: strings, 2: characters. */
					__( 'Outdated strings to translate again: %1$d, about %2$s characters. Each new translation is held for review.', 'perflocale' ),
					Scalar::int( $e['outdated_strings'] ?? 0 ),
					number_format_i18n( Scalar::int( $e['outdated_chars'] ?? 0 ) )
				)
			);

			$rejected = self::rejected_line( Scalar::int( $e['outdated_rejected'] ?? 0 ) );

			if ( null !== $rejected ) {
				$lines[] = $rejected;
			}
		}

		$lines[] = self::line( self::chars_text( Scalar::int( $e['chars'] ?? 0 ), Estimator::BOUND_EXACT ) );

		return $lines;
	}

	/**
	 * The monthly limit line, when a limit is set.
	 *
	 * @param array<string, mixed> $e The estimate.
	 * @return list<array{text: string, url: string, label: string, action: string}>
	 */
	private static function limit_lines( array $e ): array {
		$limit = Scalar::int( $e['monthly_limit'] ?? 0 );

		if ( $limit <= 0 ) {
			return [];
		}

		return [
			self::line(
				sprintf(
					/* translators: 1: characters used this month, 2: the monthly limit. */
					__( 'Monthly limit: %1$s of %2$s characters used.', 'perflocale' ),
					number_format_i18n( Scalar::int( $e['monthly_used'] ?? 0 ) ),
					number_format_i18n( $limit )
				)
			),
		];
	}

	/**
	 * The characters line, with the bound.
	 *
	 * @param int    $chars Characters.
	 * @param string $bound exact, at_most or at_least.
	 * @return string
	 */
	private static function chars_text( int $chars, string $bound ): string {
		$n = number_format_i18n( $chars );

		if ( Estimator::BOUND_AT_MOST === $bound ) {
			/* translators: %s: characters. */
			return sprintf( __( 'Characters sent to machine translation: at most %s.', 'perflocale' ), $n );
		}

		if ( Estimator::BOUND_AT_LEAST === $bound ) {
			/* translators: %s: characters. */
			return sprintf( __( 'Characters sent to machine translation: at least %s.', 'perflocale' ), $n );
		}

		/* translators: %s: characters. */
		return sprintf( __( 'Characters sent to machine translation: %s.', 'perflocale' ), $n );
	}

	/**
	 * Why machine translation is unavailable, as a sentence.
	 *
	 * @param string $code mt_disabled, no_capability, no_provider or ''.
	 * @return string
	 */
	public static function mt_reason( string $code ): string {
		return match ( $code ) {
			'mt_disabled'        => __( 'Machine translation is switched off.', 'perflocale' ),
			'no_capability'      => __( 'You may not use machine translation.', 'perflocale' ),
			'no_provider'        => __( 'No machine translation provider is ready.', 'perflocale' ),
			'password_protected' => __( 'This site does not send password-protected posts to machine translation.', 'perflocale' ),
			default              => '',
		};
	}

	/**
	 * What a run did with translations that needed MT it could not use: left as they are, with the reason.
	 *
	 * @param string $code mt_disabled, no_capability, no_provider, password_protected or ''.
	 * @return string
	 */
	public static function mt_unavailable_text( string $code ): string {
		$why = self::mt_reason( $code );

		/* translators: %s: why machine translation is unavailable, a full sentence. */
		return '' === $why ? '' : sprintf( __( '%s Translations that need machine translation were left as they are.', 'perflocale' ), $why );
	}

	/**
	 * The line for unchanged translations that stay Outdated (incomplete sync); null for none.
	 *
	 * @param int $count Translations.
	 * @return array{text: string, url: string, label: string, action: string}|null
	 */
	private static function outdated_line( int $count ): ?array {
		if ( $count <= 0 ) {
			return null;
		}

		/* translators: %d: translations not changed that stay Outdated. */
		return self::line( sprintf( __( 'Not changed and still Outdated until a person checks them: %d.', 'perflocale' ), $count ) );
	}

	/**
	 * The line of outdated strings left out because a person rejected or undid their MT, or null for none.
	 *
	 * @param int $count Outdated string translations left out.
	 * @return array{text: string, url: string, label: string, action: string}|null
	 */
	private static function rejected_line( int $count ): ?array {
		if ( $count <= 0 ) {
			return null;
		}

		/* translators: %d: outdated string translations not sent again. */
		return self::line( sprintf( __( 'Outdated strings not sent again because their machine translation was rejected or undone by a person: %d. Each is sent again once its source text, its translation or the context sent with it changes.', 'perflocale' ), $count ) );
	}

	/**
	 * The result of a Strings page run.
	 *
	 * @param string               $title The title.
	 * @param array<string, mixed> $r     The result summary.
	 * @return array<string, mixed>
	 * @phpstan-return View
	 */
	private static function strings_result( string $title, array $r ): array {
		$lines = [];
		$warn  = false;
		$parts = is_array( $r['strings'] ?? null ) ? $r['strings'] : [];
		$jobs  = admin_url( 'admin.php?page=perflocale-jobs' );

		foreach ( $parts as $part => $p ) {
			$p     = is_array( $p ) ? $p : [];
			$mode  = Scalar::str( $p['mode'] ?? '' );
			$label = 'missing' === $part ? __( 'Missing strings', 'perflocale' ) : __( 'Outdated strings', 'perflocale' );

			if ( in_array( $mode, [ 'denied', 'error' ], true ) ) {
				/* translators: 1: "Missing strings" or "Outdated strings", 2: the reason. */
				$lines[] = self::line( sprintf( __( '%1$s: the run did not start. %2$s', 'perflocale' ), $label, Scalar::str( $p['error'] ?? '' ) ) );
				$warn    = true;
				continue;
			}

			if ( 'async' === $mode ) {
				/* translators: %s: "Missing strings" or "Outdated strings". */
				$lines[] = self::line( sprintf( __( '%s: the run continues in the background.', 'perflocale' ), $label ), $jobs, __( 'Jobs', 'perflocale' ) );
				continue;
			}

			$lines[] = self::line(
				'missing' === $part
					/* translators: 1: strings translated, 2: strings that failed. */
					? sprintf( __( 'Missing strings: %1$d translated, %2$d failed.', 'perflocale' ), Scalar::int( $p['translated'] ?? 0 ), Scalar::int( $p['failed'] ?? 0 ) )
					/* translators: 1: strings held for review, 2: strings that failed. */
					: sprintf( __( 'Outdated strings: %1$d held for review below, %2$d failed.', 'perflocale' ), Scalar::int( $p['held'] ?? 0 ), Scalar::int( $p['failed'] ?? 0 ) )
			);

			$rejected = 'missing' === $part ? null : self::rejected_line( Scalar::int( $p['skipped_rejected'] ?? 0 ) );

			if ( null !== $rejected ) {
				$lines[] = $rejected;
			}

			if ( Scalar::int( $p['failed'] ?? 0 ) > 0 && '' !== Scalar::str( $p['first_error'] ?? '' ) ) {
				$lines[] = self::line( Scalar::str( $p['first_error'] ) );
				$warn    = true;
			}
		}

		return self::view( $warn ? 'warning' : 'success', $title, $lines );
	}

	/**
	 * One language of a per-post result.
	 *
	 * @param int    $language_id Language.
	 * @param string $status      The pair's status.
	 * @param int    $change_id   A held change.
	 * @param string $retry       The per-post link.
	 * @param string $reason      The pair's reason.
	 * @return array{text: string, url: string, label: string, action: string}
	 */
	private static function item_line( int $language_id, string $status, int $change_id, string $retry, string $reason = '' ): array {
		$memory = self::memory_text( $status, $reason );
		$text   = '' !== $memory ? $memory : match ( $status ) {
			'applied'                                                      => __( 'updated', 'perflocale' ),
			'held', 'held_same'                                            => __( 'held for review', 'perflocale' ),
			'unchanged'                                                    => __( 'already up to date', 'perflocale' ),
			'outdated'                                                     => __( 'not changed: it stays Outdated until a person checks it', 'perflocale' ),
			'confirmed'                                                    => __( 'recorded as up to date', 'perflocale' ),
			'filled', 'created'                                            => __( 'created', 'perflocale' ),
			'empty'                                                        => __( 'the translation is empty; use Translate or Create', 'perflocale' ),
			'missing'                                                      => __( 'there is no translation yet; use Create', 'perflocale' ),
			'skipped_permission', 'forbidden'                              => __( 'skipped: you may not edit it', 'perflocale' ),
			'out_of_scope'                                                 => __( 'skipped: Content ReSync does not cover it', 'perflocale' ),
			'target_gone'                                                  => __( 'skipped: the translation is in the Trash', 'perflocale' ),
			'skipped_decided'                                              => __( 'skipped: this update was rejected before', 'perflocale' ),
			'no_source'                                                    => __( 'skipped: the source is not available', 'perflocale' ),
			'needs_estimate'                                               => __( 'not processed: it now needs machine translation, so it needs the estimate first', 'perflocale' ),
			'mt_unavailable'                                               => __( 'not changed: it needs machine translation, which is not available', 'perflocale' ),
			'no_base'                                                      => self::no_base_text( $reason ),
			'unsupported', 'compose_error'                                 => __( 'not supported for resync', 'perflocale' ),
			default                                                        => __( 'not processed; run it again', 'perflocale' ),
		};

		$url   = '';
		$label = '';

		if ( in_array( $status, [ 'held', 'held_same' ], true ) && $change_id > 0 ) {
			$url   = ReviewUpdate::url( $change_id );
			$label = __( 'Review update', 'perflocale' );
		} elseif ( 'needs_estimate' === $status && '' !== $retry ) {
			$url   = $retry;
			$label = __( 'Show the estimate', 'perflocale' );
		}

		$language = self::language_label( $language_id );
		/* translators: 1: the link's name ("Review update" or "Show the estimate"), 2: language name. */
		$action = '' === $label ? '' : sprintf( __( '%1$s: %2$s', 'perflocale' ), $label, $language );

		/* translators: 1: language name, 2: what happened to its translation. */
		return self::line( sprintf( __( '%1$s: %2$s.', 'perflocale' ), $language, $text ), $url, $label, $action );
	}

	/**
	 * The text of a pair with no base to compare with: the next step depends on what is missing.
	 *
	 * @param string $reason The pair's reason.
	 * @return string
	 */
	private static function no_base_text( string $reason ): string {
		if ( 'no_revisions' === $reason ) {
			/* translators: What happened to a translation, after a language name and a colon (e.g. "Español: ..."); the period follows. */
			return __( 'the source keeps no revisions, so its changes cannot be worked out and nothing was changed. Update the translation by hand', 'perflocale' );
		}

		if ( 'unsaved_draft' === $reason ) {
			/* translators: What happened to a translation, after a language name and a colon (e.g. "Español: ..."); the period follows. */
			return __( 'this draft has never been saved, so the source text it was copied from is unknown and nothing was changed. Save it once, then resync; source changes made before that save must be added by hand', 'perflocale' );
		}

		/* translators: What happened to a translation, after a language name and a colon (e.g. "Español: ..."); the period follows. */
		return __( 'no revision of the source is old enough to compare with, so nothing was changed. Save the source, then update the translation by hand and save it', 'perflocale' );
	}

	/**
	 * The text of a pair refused because a post is too large for the PHP memory limit, by side; '' for any other
	 * pair (an engine refusal of the base keeps "not supported for resync").
	 *
	 * @param string $status The pair's status.
	 * @param string $reason The pair's reason.
	 * @return string
	 */
	public static function memory_text( string $status, string $reason ): string {
		if ( 'unsupported' !== $status ) {
			return '';
		}

		if ( 'source: ' . Engine::ERROR_MEMORY === $reason ) {
			/* translators: What happened to a translation, after a language name and a colon (e.g. "Español: ..."); the period follows. */
			return __( 'the source is too large to resync with the current PHP memory limit. Raise the limit or update the translation by hand', 'perflocale' );
		}

		if ( 'translation: ' . Engine::ERROR_MEMORY === $reason ) {
			/* translators: What happened to a translation, after a language name and a colon (e.g. "Español: ..."); the period follows. */
			return __( 'the translation is too large to resync with the current PHP memory limit. Raise the limit or update it by hand', 'perflocale' );
		}

		return '';
	}

	/**
	 * A run's stop as a fixed sentence (the job stores the same one) in the viewer's language; '' for others.
	 *
	 * @param string $stopped The result's `stopped` code.
	 * @return string
	 */
	public static function stop_text( string $stopped ): string {
		switch ( $stopped ) {
			case 'limit':
				return __( 'The monthly machine translation limit was reached; the run stopped before this pair, and nothing of it was written.', 'perflocale' );
			case 'provider_unavailable':
				return __( 'The machine translation provider is not available right now; the run stopped. Run it again later.', 'perflocale' );
			case 'import':
				return __( 'An import is running, so the run stopped before any translation. Run it again when the import has finished.', 'perflocale' );
		}

		return '';
	}

	/**
	 * The pairs of a result's items refused for memory, in item order, with the post that is too large.
	 *
	 * @param array<int|string, mixed> $items The result's items: [source, language, translation, status, reason, change].
	 * @return list<array{source_id: int, language_id: int, translation_id: int, post_id: int, text: string}>
	 */
	public static function memory_refusals( array $items ): array {
		$out = [];

		foreach ( $items as $item ) {
			$item   = is_array( $item ) ? array_values( $item ) : [];
			$reason = Scalar::str( $item[4] ?? '' );
			$text   = self::memory_text( Scalar::str( $item[3] ?? '' ), $reason );

			if ( '' === $text ) {
				continue;
			}

			$source      = Scalar::int( $item[0] ?? 0 );
			$translation = Scalar::int( $item[2] ?? 0 );
			$out[]       = [
				'source_id'      => $source,
				'language_id'    => Scalar::int( $item[1] ?? 0 ),
				'translation_id' => $translation,
				'post_id'        => str_starts_with( $reason, 'translation: ' ) && $translation > 0 ? $translation : $source,
				'text'           => $text,
			];
		}

		return $out;
	}

	/**
	 * One refused pair as a line (source title, language, text); as HTML the title links to the edit screen when
	 * the user may edit. The refused post is never loaded ({@see self::post_fields()}).
	 *
	 * @param array{source_id: int, language_id: int, translation_id: int, post_id: int, text: string} $refusal The pair.
	 * @param bool                                                                                     $html    HTML (escaped, with the link) instead of text.
	 * @return string
	 */
	public static function memory_line( array $refusal, bool $html = false ): string {
		$target = $refusal['translation_id'] > 0 ? $refusal['translation_id'] : $refusal['source_id'];
		$fields = self::post_fields( $html ? [ $refusal['source_id'], $target ] : [ $refusal['source_id'] ] );
		$title  = mb_substr( $fields[ $refusal['source_id'] ]['title'] ?? '', 0, self::TITLE_MAX );
		$title  = '' !== $title ? $title : '#' . $refusal['source_id'];
		/* translators: 1: the source post's title, 2: language name, 3: why the translation was not resynced and what to do, without its final period. */
		$format   = __( '%1$s (%2$s): %3$s.', 'perflocale' );
		$language = self::language_label( $refusal['language_id'] );

		if ( ! $html ) {
			return sprintf( $format, $title, $language, $refusal['text'] );
		}

		$edit = isset( $fields[ $target ] ) ? self::edit_url( $target, $fields[ $target ] ) : '';
		$name = '' !== $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );

		return sprintf( esc_html( $format ), $name, esc_html( $language ), esc_html( $refusal['text'] ) );
	}

	/**
	 * Title, type, status and author of posts by id, one statement without the content: a refused post may be too
	 * large for the memory limit, so the notice lines never load it.
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return array<int, array{title: string, type: string, status: string, author: int}>
	 */
	private static function post_fields( array $ids ): array {
		/**
		 * The database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$ids = array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$out = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One %d placeholder per id; the table is bound as a %i identifier.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_title, post_type, post_status, post_author FROM %i WHERE ID IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $wpdb->posts ], $ids ) ), ARRAY_A ) as $row ) {
			$row = (array) $row;

			$out[ Scalar::int( $row['ID'] ?? 0 ) ] = [
				'title'  => Scalar::str( $row['post_title'] ?? '' ),
				'type'   => Scalar::str( $row['post_type'] ?? '' ),
				'status' => Scalar::str( $row['post_status'] ?? '' ),
				'author' => Scalar::int( $row['post_author'] ?? 0 ),
			];
		}

		return $out;
	}

	/**
	 * A post's edit screen for the current user without loading the post: checks the primitive capabilities
	 * map_meta_cap() asks for `edit_post`. '' for unregistered or non-post.php types, trashed posts, or no right.
	 *
	 * @param int                                                             $id     Post id.
	 * @param array{title: string, type: string, status: string, author: int} $fields Its fields.
	 * @return string
	 */
	private static function edit_url( int $id, array $fields ): string {
		$type = get_post_type_object( $fields['type'] );

		if ( ! $type instanceof \WP_Post_Type || 'trash' === $fields['status'] || 'post.php?post=%d' !== $type->_edit_link ) {
			return '';
		}

		$cap       = (array) $type->cap;
		$published = in_array( $fields['status'], [ 'publish', 'future' ], true );

		if ( ! $type->map_meta_cap ) {
			$caps = [ $cap['edit_post'] ?? '' ];
		} elseif ( $fields['author'] > 0 && get_current_user_id() === $fields['author'] ) {
			$caps = [ $published ? $cap['edit_published_posts'] ?? '' : $cap['edit_posts'] ?? '' ];
		} else {
			$caps = [ $cap['edit_others_posts'] ?? '' ];

			if ( $published ) {
				$caps[] = $cap['edit_published_posts'] ?? '';
			} elseif ( 'private' === $fields['status'] ) {
				$caps[] = $cap['edit_private_posts'] ?? '';
			}
		}

		foreach ( Scalar::strings( $caps ) as $primitive ) {
			if ( ! current_user_can( $primitive ) ) {
				return '';
			}
		}

		return admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	/**
	 * The Jobs screen's note on pairs refused for memory (posts counted once); '' when there are none.
	 *
	 * @param list<array{source_id: int, language_id: int, translation_id: int, post_id: int, text: string}> $refusals The pairs ({@see self::memory_refusals()}).
	 * @return string
	 */
	public static function memory_note( array $refusals ): string {
		$posts = count( array_unique( array_column( $refusals, 'post_id' ) ) );

		if ( 0 === $posts ) {
			return '';
		}

		return sprintf(
			/* translators: %d: posts too large to resync. The posts are listed below this text. */
			_n( '%d post is too large to resync with the current PHP memory limit. Raise the limit or update it by hand; it is listed below.', '%d posts are too large to resync with the current PHP memory limit. Raise the limit or update them by hand; they are listed below.', $posts, 'perflocale' ),
			$posts
		);
	}

	/**
	 * A language's name as notices and the Review screen show it: native name, else name, else slug.
	 *
	 * @param int $language_id Language.
	 * @return string
	 */
	public static function language_label( int $language_id ): string {
		$language = \PerfLocale\Plugin::get_instance()->lang_repo()->find( $language_id );

		if ( null === $language ) {
			/* translators: %d: language id. */
			return sprintf( __( 'Language %d', 'perflocale' ), $language_id );
		}

		$native = Scalar::str( Scalar::prop( $language, 'native_name' ) );
		$name   = Scalar::str( Scalar::prop( $language, 'name' ) );

		return '' !== $native ? $native : ( '' !== $name ? $name : Scalar::str( Scalar::prop( $language, 'slug' ) ) );
	}

	/**
	 * The title of a notice.
	 *
	 * @param string $kind     The kind.
	 * @param bool   $estimate The estimate step (else the result).
	 * @return string
	 */
	private static function title( string $kind, bool $estimate ): string {
		$name = match ( $kind ) {
			RunRequest::KIND_POST    => __( 'Resync from source', 'perflocale' ),
			RunRequest::KIND_STRINGS => __( 'Translate outdated and missing strings', 'perflocale' ),
			default                  => __( 'Update translations', 'perflocale' ),
		};

		/* translators: %s: the button's name. */
		return $estimate ? sprintf( __( '%s: estimate', 'perflocale' ), $name ) : $name;
	}

	/**
	 * A Start or Cancel link of a token, without the nonce (a press posts it).
	 *
	 * @param string $action perflocale_resync_start or perflocale_resync_cancel.
	 * @param string $token  The token.
	 * @return string
	 */
	private static function link( string $action, string $token ): string {
		return add_query_arg(
			[
				'action' => $action,
				'token'  => $token,
			],
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * A line.
	 *
	 * @param string $text   Text.
	 * @param string $url    Link ('' for none).
	 * @param string $label  Link text.
	 * @param string $action The link's name as an editor notice action ('' for the link text).
	 * @return array{text: string, url: string, label: string, action: string}
	 */
	private static function line( string $text, string $url = '', string $label = '', string $action = '' ): array {
		return [
			'text'   => $text,
			'url'    => $url,
			'label'  => $label,
			'action' => $action,
		];
	}

	/**
	 * A view.
	 *
	 * @param string                                                                $status  info, success, warning or error.
	 * @param string                                                                $title   Title.
	 * @param list<array{text: string, url: string, label: string, action: string}> $lines   Lines.
	 * @param string                                                                $outcome For the editors' resync JS hooks: estimate, done, background, failed or ''.
	 * @return array<string, mixed>
	 * @phpstan-return View
	 */
	private static function view( string $status, string $title, array $lines, string $outcome = '' ): array {
		return [
			'status'  => $status,
			'title'   => $title,
			'lines'   => $lines,
			'start'   => '',
			'cancel'  => '',
			'refusal' => '',
			'outcome' => $outcome,
		];
	}
}
