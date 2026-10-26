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
 * Adds `inLanguage` and `workTranslation` links to the schema graphs of the SEO addons.
 *
 * No side effects; one memoised get_translations_for_current_page() call per request.
 */
final class SchemaEnricher {

	/**
	 * Page- and work-level `@type`s to enrich; Organization, Person and WebSite are language-neutral.
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
	 * Per-request context shared by every addon hook; reset() clears it.
	 *
	 * @var array{in_language: string, siblings: array<string, string>, current_url: string}|null
	 */
	private static ?array $memo = null;

	/**
	 * Enrich an `@graph` envelope, a list of entities or one entity, keeping its shape.
	 *
	 * A keyed map counts as one entity and comes back unchanged: enrich its values with enrich_single().
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

		// JSON-LD envelope with an inner @graph list.
		if ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
			$graph['@graph'] = array_map(
				static fn( $entity ) => is_array( $entity ) ? self::enrich_single( $entity, $post_id ) : $entity,
				$graph['@graph']
			);

			return $graph;
		}

		// A bare list. Not array_is_list(): Plugin Check flags it as WordPress 6.5.
		if ( $graph === array_values( $graph ) ) {
			return array_map(
				static fn( $entity ) => is_array( $entity ) ? self::enrich_single( $entity, $post_id ) : $entity,
				$graph
			);
		}

		// One entity as the whole payload.
		return self::enrich_single( $graph, $post_id );
	}

	/**
	 * Enrich one schema entity; one that is not a page or work type comes back unchanged.
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

		// inLanguage and workTranslation never overwrite a value the SEO plugin set.
		if ( $ctx['in_language'] !== '' && ! isset( $entity['inLanguage'] ) ) {
			$entity['inLanguage'] = $ctx['in_language'];
		}

		// workTranslation points this page at its translations.
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
	 * Reset the per-request memo, so one site's sibling URLs never reach another after switch_blog.
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
	 * Whether a schema `@type` (a string or a list of strings) is one to enrich.
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
	 * @param int|null $post_id Reserved for a per-post context; unused.
	 * @return array{in_language: string, siblings: array<string, string>, current_url: string}|null
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Reserved for future per-post context binding; kept in signature to avoid call-site churn.
	private static function context( ?int $post_id ): ?array {
		if ( self::$memo !== null ) {
			return self::$memo;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			// Never memoise a null: the router may become available later in the request.
			return null;
		}

		$lang = $plugin->get( 'router' )->get_current_language();

		if ( ! is_object( $lang ) ) {
			// Never memoise a null: the language may resolve later in the request.
			return null;
		}

		$locale = (string) ( $lang->locale ?? '' );
		// Canonical BCP 47 (`en-US`), the form rich-results validators expect.
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
