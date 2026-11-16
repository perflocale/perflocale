<?php
/**
 * URL bases provider contract.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares the URL bases a plugin owns, so PerfLocale can translate them.
 *
 * Return instances from the `perflocale/url_bases/providers` filter; it runs only when PerfLocale compiles its URL
 * bases (admin, WP-CLI, cron, the end of an import), never on a visitor request.
 *
 * @api
 * @since 1.0.8
 */
interface UrlBasesProvider {

	/**
	 * Group id: `^[a-z0-9_-]{1,32}$` with at least one letter, stable across versions (stored settings use it); not `core` or `woocommerce`.
	 *
	 * @return string
	 */
	public function group(): string;

	/**
	 * Group label for Settings > URL & Routing > URL Bases, translated.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Keys and their field labels.
	 *
	 * @return array<string, string> Key (`pt:<post_type>`, `pta:<post_type>`, `tx:<taxonomy>`) => field label.
	 */
	public function keys(): array;

	/**
	 * Option names whose change alters these bases.
	 *
	 * @return string[]
	 */
	public function inputs(): array;

	/**
	 * Literal path prefixes this plugin routes under its own bases on purpose, e.g. `events/calendar/`.
	 *
	 * @return string[]
	 */
	public function shared(): array;
}
