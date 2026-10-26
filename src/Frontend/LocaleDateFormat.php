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
 * Serves the current language's date and time formats through the core option filters.
 */
final class LocaleDateFormat {

	private readonly LanguageRouter $router;

	/**
	 * Memoized formats; `lang_id` holds the "blog:lang" key string, not a bare language id.
	 *
	 * @var array{lang_id:string,date:string,time:string}|null
	 */
	private ?array $cache = null;

	public function __construct( LanguageRouter $router ) {
		$this->router = $router;
	}

	public function register_hooks(): void {
		// Admin, REST and CLI keep the admin locale.
		if ( is_admin() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Hook a filter only when some active language overrides that format.
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

		// Language ids are per blog: the blog id in the key covers switch_to_blog().
		$blog = is_multisite() ? get_current_blog_id() : 0;
		$key  = $blog . ':' . $id;

		if ( $this->cache !== null && $this->cache['lang_id'] === $key ) {
			return [
				'date' => $this->cache['date'],
				'time' => $this->cache['time'],
			];
		}

		// A stored format holding markup ('<') is not used: the site option
		// applies instead.
		$date = $lang && ! empty( $lang->date_format ) && ! str_contains( (string) $lang->date_format, '<' ) ? (string) $lang->date_format : '';
		$time = $lang && ! empty( $lang->time_format ) && ! str_contains( (string) $lang->time_format, '<' ) ? (string) $lang->time_format : '';

		// Same filters as Helper; only a non-empty override is filtered, so the site option stays in charge.
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
