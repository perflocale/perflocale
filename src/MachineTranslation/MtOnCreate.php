<?php
/**
 * Machine translation of a new translation on Create.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "translate on create" step of the classic panel's + Create and the block sidebar's Create.
 * Docs: docs-ai/11-code-rationale.md, "AdminController: Create translation".
 */
final class MtOnCreate {

	/** Seconds a Create's claim on its pair lasts: the interactive provider calls, the insert and the meta. */
	private const CLAIM_TTL = 120;

	/**
	 * Whether machine translation may fill a language (mt_auto_translate_languages; empty: all).
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $lang     Target language slug.
	 * @return bool
	 */
	public static function in_scope( Settings $settings, string $lang ): bool {
		$scope = (array) $settings->get( 'mt_auto_translate_languages', [] );

		return [] === $scope || in_array( $lang, $scope, true );
	}

	/**
	 * Machine-translate a new translation when "translate on create" applies. Call only when no translation is linked and
	 * there is a copy source.
	 *
	 * @param Settings      $settings    Plugin settings.
	 * @param CacheManager  $cache       Cache.
	 * @param int           $copy_from   The post the translation is copied from (get_copy_source_id()), not the one clicked.
	 * @param string        $target_lang Target language slug.
	 * @param callable|null $fallback    The caller's plain copy, run under the pair's claim when the claimed run made
	 *                                   nothing: [ translation id or false, whether it inserted it ].
	 * @phpstan-param (callable(): array{0: int|false, 1: bool})|null $fallback
	 * @return array{ran: bool, id: int, inserted: bool, queued: bool, reason: string, own: bool, warning: string, busy: bool, unread: bool}
	 *         id 0: no translation (reason says why when ran); queued: a job took it and id is $copy_from; busy: another
	 *         request is creating the pair, unread: its link or jobs could not be read (warning says retry); for both the
	 *         caller makes nothing.
	 */
	public static function run( Settings $settings, CacheManager $cache, int $copy_from, string $target_lang, ?callable $fallback = null ): array {
		$out         = [
			'ran'      => false,
			'id'       => 0,
			'inserted' => false,
			'queued'   => false,
			'reason'   => '',
			'own'      => false,
			'warning'  => '',
			'busy'     => false,
			'unread'   => false,
		];
		$source_post = get_post( $copy_from );

		// The MT capability, edit_post on the copied post (the host may refuse), the password filter, then the REST rate limit (it counts).
		if (
			! $settings->mt_enabled()
			|| ! (bool) $settings->get( 'mt_auto_translate_on_create' )
			|| ! self::in_scope( $settings, $target_lang )
			|| ! current_user_can( 'perflocale_use_mt' )
			|| ! \PerfLocale\Util\ObjectPermission::edit_narrowed( $copy_from )
			|| ! $source_post instanceof \WP_Post
		) {
			return $out;
		}

		$out['ran'] = true;

		if ( ! TranslationService::may_send_post( $source_post, 'auto_create' ) ) {
			$out['reason'] = __( 'The original is password-protected, and machine translation does not send password-protected posts when a translation is created.', 'perflocale' );

			return $out;
		}

		// One provider run per pair: copy_from is the group's default member whichever post was clicked.
		$claim = 'mt_create_' . $copy_from . '_' . $target_lang;

		if ( ! \PerfLocale\Concurrency\Lock::acquire( $claim, self::CLAIM_TTL ) ) {
			$out['busy']   = true;
			$out['reason'] = __( 'Another request is creating this translation with machine translation. Reload the page in a moment to open it.', 'perflocale' );

			return $out;
		}

		try {
			$out = self::run_claimed( $settings, $cache, $copy_from, $target_lang, $out );

			// Still under the claim, so no other Create of the pair pays while the copy is made.
			if ( null !== $fallback && $out['id'] <= 0 && ! $out['queued'] && ! $out['unread'] ) {
				[ $made, $inserted ] = $fallback();
				$out['id']           = false === $made ? 0 : $made;
				$out['inserted']     = $inserted;
			}

			return $out;
		} finally {
			\PerfLocale\Concurrency\Lock::release( $claim );
		}
	}

	/**
	 * The rest of run(), under the pair's claim: the link read again from the table, then the rate limit and the provider.
	 *
	 * @param Settings     $settings    Plugin settings.
	 * @param CacheManager $cache       Cache.
	 * @param int          $copy_from   The post copied.
	 * @param string       $target_lang Target language slug.
	 * @param array        $out         run()'s answer so far.
	 * @phpstan-param array{ran: bool, id: int, inserted: bool, queued: bool, reason: string, own: bool, warning: string, busy: bool, unread: bool} $out
	 * @return array{ran: bool, id: int, inserted: bool, queued: bool, reason: string, own: bool, warning: string, busy: bool, unread: bool}
	 */
	private static function run_claimed( Settings $settings, CacheManager $cache, int $copy_from, string $target_lang, array $out ): array {
		$lang    = ( new \PerfLocale\Database\Repository\LanguageRepository( $cache ) )->find_by_slug( $target_lang );
		$lang_id = null === $lang ? 0 : \PerfLocale\Util\Scalar::int( \PerfLocale\Util\Scalar::prop( $lang, 'id' ) );
		$linked  = $lang_id > 0 ? ( new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache ) )->find_translation_link_uncached( $copy_from, \PerfLocale\Enum\ObjectType::Post, $lang_id ) : false;

		// No answer: nothing is paid for on a guess, and a copy could take the slot of a job this request did not look for.
		if ( false === $linked ) {
			$out['unread']  = true;
			$out['warning'] = __( 'It could not be checked whether this translation already exists, so nothing was created. Please try again.', 'perflocale' );

			return $out;
		}

		// An earlier Create of the pair finished meanwhile: its translation is the answer, not a second provider run.
		if ( null !== $linked && get_post( $linked['object_id'] ) instanceof \WP_Post ) {
			$out['ran'] = false;
			$out['id']  = $linked['object_id'];

			return $out;
		}

		$meta = TranslationService::include_meta( true, 'create', $copy_from, $target_lang );

		// A job for the pair (any user's Create handed off earlier) is still queued or running: it is the answer.
		require_once __DIR__ . '/PiecesHandOff.php';

		$in_flight = PiecesHandOff::pair_in_flight( $copy_from, $lang_id );

		// Unknown is not "none": a job may hold the pair, so nothing is paid or copied now.
		if ( null === $in_flight ) {
			$out['unread']  = true;
			$out['warning'] = __( 'It could not be checked whether this translation is already being made in a background job, so nothing was created. Please try again.', 'perflocale' );

			return $out;
		}

		if ( $in_flight ) {
			$out['id']      = $copy_from;
			$out['queued']  = true;
			$out['warning'] = __( 'This translation is already being machine-translated in a background job. It appears when the job is done.', 'perflocale' );

			return $out;
		}

		$throttled = \PerfLocale\Translation\MtRateLimiter::admit( get_current_user_id() );

		if ( $throttled instanceof \WP_Error ) {
			$out['reason'] = $throttled->get_error_message();

			return $out;
		}

		try {
			$service  = new TranslationService( $settings, $cache );
			$inserted = false;
			$queued   = '';
			$service->hand_off_above( TranslationService::interactive_pieces() );

			try {
				$result = $service->translate_post( $copy_from, $target_lang, '', false, $meta, $inserted );
			} catch ( PiecesHandOff $e ) {
				// A long post, a provider wait or a slow provider goes to a job; a long one that cannot be queued is
				// translated here, a stopped one fails named.
				$queued = '' === $e->queue( 'auto_create' ) ? '' : $e->notice();

				if ( '' === $queued && '' !== $e->unqueued() ) {
					$out['reason'] = esc_html( $e->unqueued() );

					return $out;
				}

				$service->hand_off_above( 0 );
				$result = '' === $queued ? $service->translate_post( $copy_from, $target_lang, '', false, $meta, $inserted ) : [ 'post_id' => $copy_from ];
			}

			$out['id']       = is_numeric( $result['post_id'] ?? null ) ? (int) $result['post_id'] : 0;
			$out['inserted'] = $inserted;
			$out['queued']   = '' !== $queued;
			$out['warning']  = '' !== $queued ? $queued : self::warning( $result );

			if ( $out['id'] <= 0 ) {
				$out['reason'] = __( 'The machine translation returned no translation.', 'perflocale' );
			}
		} catch ( \Throwable $e ) {
			// Save guard refusal or provider error: the caller makes the stub and shows the reason.
			$out['id']     = 0;
			$out['reason'] = $e->getMessage();
			$out['own']    = $e instanceof NotSavedException;
		}

		return $out;
	}

	/**
	 * The create notice's warnings of a translate_post() result, or ''; MtCreateNotice loads only for a warned create.
	 *
	 * @param array<string, mixed> $result translate_post() result.
	 * @return string
	 */
	private static function warning( array $result ): string {
		return isset( $result['builder_warning'] ) || isset( $result['length_warning'] ) ? \PerfLocale\Admin\MtCreateNotice::warning_of( $result ) : '';
	}
}
