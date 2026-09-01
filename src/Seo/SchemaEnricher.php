<?php
/**
 * Per-language enrichment for JSON-LD schema graphs emitted by SEO addons.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Seo;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds `inLanguage` + translation cross-links to any schema graph handed in.
 *
 * Shared by every built-in SEO addon so enrichment logic lives in exactly
 * one place. Each addon keeps its own filter hook wiring (so it can be
 * disabled per-addon) but delegates the actual graph walk here.
 *
 * Completely side-effect-free - the input graph is copied, enriched, and
 * returned. No DB writes. No remote calls. One call to the per-request
 * cached `UrlConverter::get_translations_for_current_page()` per page load
 * regardless of how many addons invoke us.
 */
final class SchemaEnricher {

	/**
	 * Schema.org `@type` values we should add `inLanguage` to. Types that
	 * describe a specific page or work - not organisation-wide entities
	 * like `Organization`, `Person`, `WebSite` which are language-neutral.
	 *
	 * @var array<int, string>
	 */
	private const PAGE_TYPES = [
		'WebPage',
		'ItemPage',
		'CollectionPage',
		'AboutPage',
		'ContactPage',
		'FAQPage',
		'QAPage',
		'ProfilePage',
		'Article',
		'NewsArticle',
		'BlogPosting',
		'TechArticle',
		'ScholarlyArticle',
		'Product',
		'Recipe',
		'HowTo',
		'Event',
	];

	/**
	 * Per-request memo so repeat calls across multiple addon hooks don't
	 * re-dereference the container or rebuild the sibling URL map.
	 *
	 * @var array{in_language: string, siblings: array<string, string>, current_url: string}|null
	 */
	private static ?array $memo = null;

	/**
	 * Enrich a full schema graph ({"@context": "...", "@graph": [...]}) OR
	 * a flat list of entity arrays. Both shapes are preserved on return.
	 *
	 * @param array<mixed> $graph Schema graph or entity list.
	 * @param int|null     $post_id Override post context (defaults to current).
	 * @return array<mixed>
	 */
	public static function enrich_graph( array $graph, ?int $post_id = null ): array {
		if ( ! self::should_enrich() ) {
			return $graph;
		}

		$ctx = self::context( $post_id );

		if ( $ctx === null ) {
			return $graph;
		}

		// Handle the JSON-LD envelope form with an inner @graph list.
		if ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
			$graph['@graph'] = array_map(
				static fn( $entity ) => is_array( $entity ) ? self::enrich_single( $entity, $post_id ) : $entity,
				$graph['@graph']
			);

			return $graph;
		}

		// Handle a bare list of entities. ($graph === array_values($graph) is
		// the idiomatic list check, equivalent to array_is_list() — which
		// Plugin Check maps to WP 6.5 although it's a PHP 8.1 native the
		// plugin's PHP floor already guarantees.)
		if ( $graph === array_values( $graph ) ) {
			return array_map(
				static fn( $entity ) => is_array( $entity ) ? self::enrich_single( $entity, $post_id ) : $entity,
				$graph
			);
		}

		// Single entity passed as the top-level payload.
		return self::enrich_single( $graph, $post_id );
	}

	/**
	 * Enrich a single schema entity. Returns the entity unchanged when
	 * its `@type` isn't a page-level or work-level type we care about.
	 *
	 * @param array<string, mixed> $entity Schema entity.
	 * @param int|null             $post_id Override post context.
	 * @return array<string, mixed>
	 */
	public static function enrich_single( array $entity, ?int $post_id = null ): array {
		if ( ! self::should_enrich() ) {
			return $entity;
		}

		$ctx = self::context( $post_id );

		if ( $ctx === null ) {
			return $entity;
		}

		if ( ! self::is_page_type( $entity['@type'] ?? '' ) ) {
			return $entity;
		}

		// Always add inLanguage - universally supported across validators.
		if ( $ctx['in_language'] !== '' && ! isset( $entity['inLanguage'] ) ) {
			$entity['inLanguage'] = $ctx['in_language'];
		}

		// Cross-link translations via workTranslation. Points the canonical
		// entity (this page) at its siblings. Schema.org validators + SERP
		// features (Google's "see this in X language") read this property.
		if ( ! empty( $ctx['siblings'] ) && ! isset( $entity['workTranslation'] ) ) {
			$entity['workTranslation'] = array_values(
				array_map(
					static fn( string $url ): array => [
						'@type' => 'CreativeWork',
						'url'   => $url,
					],
					$ctx['siblings']
				)
			);
		}

		return $entity;
	}

	/**
	 * Reset the per-request memo. Called on `switch_blog` so sibling URLs
	 * from one site don't leak into another.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * Resolve the master on/off switch for enrichment.
	 *
	 * @return bool
	 */
	private static function should_enrich(): bool {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return false;
		}

		return $plugin->get( 'settings' )->seo_schema_enrichment_enabled();
	}

	/**
	 * Whether the given schema `@type` (string or array of strings) is one
	 * we should enrich.
	 *
	 * @param mixed $type Raw `@type` value.
	 * @return bool
	 */
	private static function is_page_type( mixed $type ): bool {
		if ( is_string( $type ) ) {
			return in_array( $type, self::PAGE_TYPES, true );
		}

		if ( is_array( $type ) ) {
			foreach ( $type as $t ) {
				if ( is_string( $t ) && in_array( $t, self::PAGE_TYPES, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve per-request context (current hreflang + translation siblings).
	 *
	 * @param int|null $post_id Unused currently - reserved for context binding.
	 * @return array{in_language: string, siblings: array<string, string>, current_url: string}|null
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Reserved for future per-post context binding; kept in signature to avoid call-site churn.
	private static function context( ?int $post_id ): ?array {
		if ( self::$memo !== null ) {
			return self::$memo;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			// Do NOT cache a null here - the router may become available
			// later in the request (e.g. an SEO addon asking for schema
			// before parse_request has fired on a preview endpoint).
			return null;
		}

		$lang = $plugin->get( 'router' )->get_current_language();

		if ( ! is_object( $lang ) ) {
			// Same reasoning: don't lock in a null for the whole request.
			return null;
		}

		$locale = (string) ( $lang->locale ?? '' );
		// Canonical BCP 47 form for `inLanguage` (`en-US` not `en_US` or
		// `en-us`) — search engines and rich-results validators expect it.
		$in_language = $locale !== '' ? \PerfLocale\Helper::format_locale_as_bcp47( $locale ) : (string) ( $lang->slug ?? '' );

		$siblings    = [];
		$current_url = '';

		if ( $plugin->has( 'url_converter' ) ) {
			$url_converter = $plugin->get( 'url_converter' );
			$all_urls      = $url_converter->get_translations_for_current_page();

			if ( is_array( $all_urls ) ) {
				$current_slug = (string) ( $lang->slug ?? '' );
				$current_url  = (string) ( $all_urls[ $current_slug ] ?? '' );

				foreach ( $all_urls as $slug => $url ) {
					if ( $slug === $current_slug || ! is_string( $url ) || $url === '' ) {
						continue;
					}

					$siblings[ $slug ] = $url;
				}
			}
		}

		self::$memo = [
			'in_language' => $in_language,
			'siblings'    => $siblings,
			'current_url' => $current_url,
		];

		return self::$memo;
	}
}
