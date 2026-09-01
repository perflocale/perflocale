<?php
/**
 * Per-language date/time format integration.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges per-language date_format / time_format columns to WordPress's
 * native option filters.
 *
 * Hooks `option_date_format` and `option_time_format` on the frontend so
 * that every call site that reads those options (get_the_date(), comments,
 * Yoast schema, theme date displays, etc.) automatically picks up the
 * current language's override - without any per-call site changes.
 *
 * Admin requests are skipped: admin pages should stay in the user's admin
 * locale, not the visitor-facing language.
 */
final class LocaleDateFormat {

	private readonly LanguageRouter $router;

	/**
	 * Memoized format strings for one "blog_id:language_id" pair. Recomputed
	 * whenever that key changes — i.e. when the router swaps languages
	 * mid-request (rare but possible in tests) or a switch_to_blog() moves
	 * the request to another blog.
	 *
	 * `lang_id` holds the composite "blog:lang" STRING that resolve() builds,
	 * not a bare language id.
	 *
	 * @var array{lang_id:string,date:string,time:string}|null
	 */
	private ?array $cache = null;

	public function __construct( LanguageRouter $router ) {
		$this->router = $router;
	}

	public function register_hooks(): void {
		// Skip admin / REST / CLI - admin uses the admin locale, not the
		// visitor language.
		if ( is_admin() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Opt-in feature: date_format / time_format columns ship as
		// `NOT NULL DEFAULT ''` (Schema.php). If no active language has a
		// non-empty override there is nothing to filter, so don't pay the
		// per-option-read filter dispatch (~5-10 calls per frontend render
		// via get_the_date(), comments, themes, SEO addons). The bootstrap
		// bundle that backs get_active_languages() is already cached, so
		// the gate itself is essentially free.
		$has_date = false;
		$has_time = false;
		foreach ( $this->router->get_active_languages() as $lang ) {
			if ( ! $has_date && ! empty( $lang->date_format ) ) {
				$has_date = true;
			}
			if ( ! $has_time && ! empty( $lang->time_format ) ) {
				$has_time = true;
			}
			if ( $has_date && $has_time ) {
				break;
			}
		}

		if ( $has_date ) {
			add_filter( 'option_date_format', [ $this, 'filter_date_format' ] );
		}
		if ( $has_time ) {
			add_filter( 'option_time_format', [ $this, 'filter_time_format' ] );
		}
	}

	/**
	 * @param mixed $value Original site option value.
	 * @return mixed
	 */
	public function filter_date_format( $value ) {
		$override = $this->resolve()['date'] ?? '';
		return $override !== '' ? $override : $value;
	}

	/**
	 * @param mixed $value Original site option value.
	 * @return mixed
	 */
	public function filter_time_format( $value ) {
		$override = $this->resolve()['time'] ?? '';
		return $override !== '' ? $override : $value;
	}

	/**
	 * Resolve and cache the per-language format pair for the current
	 * language, or empty strings if none / default.
	 *
	 * @return array{date:string,time:string}
	 */
	private function resolve(): array {
		$lang = $this->router->get_current_language();
		$id   = $lang ? (int) $lang->id : 0;

		// Blog-KEY the memo: language IDs are per-blog auto-increments, so on
		// multisite a mid-request switch_to_blog() would otherwise serve blog
		// A's date/time format for blog B's same-numbered language. Keying by
		// blog id self-corrects on switch without needing a reset hook (same
		// pattern as UrlConverter / HreflangTags / OgLocale).
		$blog = is_multisite() ? get_current_blog_id() : 0;
		$key  = $blog . ':' . $id;

		if ( $this->cache !== null && $this->cache['lang_id'] === $key ) {
			return [
				'date' => $this->cache['date'],
				'time' => $this->cache['time'],
			];
		}

		$date = $lang && ! empty( $lang->date_format ) ? (string) $lang->date_format : '';
		$time = $lang && ! empty( $lang->time_format ) ? (string) $lang->time_format : '';

		// Apply the same `perflocale/date_format` / `perflocale/time_format`
		// filters Helper exposes, so addons can override without touching DB.
		// Only filter when the override is non-empty - otherwise we'd return
		// the global default and short-circuit WP's own option filter chain.
		if ( $date !== '' ) {
			$date = (string) apply_filters( 'perflocale/date_format', $date, $lang );
		}
		if ( $time !== '' ) {
			$time = (string) apply_filters( 'perflocale/time_format', $time, $lang );
		}

		$this->cache = [
			'lang_id' => $key,
			'date'    => $date,
			'time'    => $time,
		];

		return [
			'date' => $date,
			'time' => $time,
		];
	}
}
