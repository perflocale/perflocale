<?php
/**
 * CDN Cache-Tag response-header emitter.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits a `Cache-Tag` response header on every frontend HTML response,
 * giving CDNs (Cloudflare, Bunny, Fastly, etc.) per-resource tags they
 * can use for surgical purges - "flush everything tagged `lang:fr_FR`"
 * or "flush `post:42`" without nuking the whole cache.
 *
 * Short-circuits at the earliest point when the feature is disabled,
 * so there's zero overhead for sites that don't use a CDN.
 *
 * Filters:
 * perflocale/cache_tags/enabled (bool) kill switch
 * perflocale/cache_tags/header_name (string) default `Cache-Tag`
 * perflocale/cache_tags/tags (array) modify tags
 * perflocale/cache_tags/max_header_length (int) default 8000 bytes
 */
final class CacheTagEmitter {

	/**
	 * Maximum number of tags emitted per response.
	 * Keeps the header under typical server/CDN header-size limits even
	 * when a page happens to collect many tags.
	 */
	private const MAX_TAGS = 32;

	/**
	 * Maximum length of any single tag.
	 */
	private const MAX_TAG_LENGTH = 128;

	/**
	 * Default maximum combined header length (bytes).
	 */
	private const DEFAULT_MAX_HEADER_LENGTH = 8000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Priority 99 so other plugins can short-circuit first if needed.
		add_action( 'send_headers', [ $this, 'emit' ], 99 );
	}

	/**
	 * Build and emit the Cache-Tag response header.
	 *
	 * @return void
	 */
	public function emit(): void {
		// Kill-switch - setting-backed + filter-backed.
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->get( 'settings' )->cdn_cache_tags_enabled() ) {
			return;
		}

		// Scope: frontend HTML responses only. Skip admin, AJAX, cron,
		// REST, XMLRPC, feeds, and already-sent headers.
		if ( is_admin() ) {
			return;
		}

		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return;
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		// Excluded paths - match the language-routing exclusion list so
		// health-check URLs, admin-ajax, or other special endpoints
		// don't accidentally carry our tags.
		if ( $this->request_is_excluded() ) {
			return;
		}

		$tags = $this->build_tags();

		if ( empty( $tags ) ) {
			return;
		}

		/**
		 * Filter the final tag list before emission.
		 *
		 * @hook perflocale/cache_tags/tags
		 * @param array<int, string> $tags Sanitised tag list.
		 */
		$tags = (array) apply_filters( 'perflocale/cache_tags/tags', $tags );
		$tags = $this->sanitize_tags( $tags );

		if ( empty( $tags ) ) {
			return;
		}

		/**
		 * Filter the response-header name.
		 *
		 * @hook perflocale/cache_tags/header_name
		 * @param string $name Default: Cache-Tag.
		 */
		$header_name = (string) apply_filters( 'perflocale/cache_tags/header_name', 'Cache-Tag' );

		// Defensive: HTTP field names are tokens limited to a specific
		// ASCII subset (RFC 7230 §3.2.6). Strip everything else from the
		// filtered value before assembling the header string. PHP's
		// header() already refuses CRLF-laced values (response-splitting
		// vector), so this is hygiene rather than fix - it guarantees
		// the emitted header is always wire-format-valid even when a
		// filter callback returns something unexpected.
		$header_name = (string) preg_replace( "/[^A-Za-z0-9!#$%&'*+\\-.^_`|~]/", '', $header_name );

		if ( $header_name === '' ) {
			return;
		}

		/**
		 * Filter the maximum header length (bytes).
		 *
		 * @hook perflocale/cache_tags/max_header_length
		 * @param int $max Default: 8000.
		 */
		$max_length = (int) apply_filters(
			'perflocale/cache_tags/max_header_length',
			self::DEFAULT_MAX_HEADER_LENGTH
		);

		$value = $this->build_header_value( $tags, $max_length );

		if ( $value === '' ) {
			return;
		}

		header( $header_name . ': ' . $value, true );
	}

	/**
	 * Whether the current request URI matches an excluded path from the
	 * settings list (same list the router uses to skip language routing).
	 *
	 * @return bool
	 */
	private function request_is_excluded(): bool {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return false;
		}

		$excluded = $plugin->get( 'settings' )->get_excluded_paths();

		if ( ! is_array( $excluded ) || $excluded === [] ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			// esc_url_raw, not sanitize_text_field (strips %XX), so excluded-path
			// matching works on percent-encoded non-Latin request paths.
			? (string) wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		if ( $uri === '' ) {
			return false;
		}

		foreach ( $excluded as $path ) {
			$path = (string) $path;

			// Prefix-match, not substring: `/wp-admin/` must not
			// accidentally match a site's own `/my-wp-admin-guide/`
			// page. Mirrors the router/UrlConverter behaviour so the
			// two consumers of the same setting stay consistent.
			if ( $path !== '' && str_starts_with( $uri, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect the tags for the current request.
	 *
	 * @return array<int, string>
	 */
	private function build_tags(): array {
		$tags   = [ 'perflocale' ];
		$plugin = Plugin::get_instance();

		// Language tags - always useful.
		if ( $plugin->has( 'router' ) ) {
			$lang = $plugin->get( 'router' )->get_current_language();

			if ( is_object( $lang ) ) {
				$locale = (string) ( $lang->locale ?? '' );
				$slug   = (string) ( $lang->slug ?? '' );

				if ( $locale !== '' ) {
					$tags[] = 'lang:' . $locale;
				}

				if ( $slug !== '' ) {
					$tags[] = 'lang-slug:' . $slug;
				}
			}
		}

		// Per-context tags.
		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			if ( $post_id > 0 ) {
				$tags[] = 'post:' . $post_id;

				$post_type = get_post_type( $post_id );

				if ( is_string( $post_type ) && $post_type !== '' ) {
					$tags[] = 'post-type:' . $post_type;
				}
			}
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$tags[] = 'term:' . $term->term_id;
				$tags[] = 'tax:' . $term->taxonomy;
			}
		} elseif ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );

			if ( is_string( $post_type ) && $post_type !== '' ) {
				$tags[] = 'archive:' . $post_type;
			}
		} elseif ( is_author() ) {
			$author_id = (int) get_queried_object_id();

			if ( $author_id > 0 ) {
				$tags[] = 'author:' . $author_id;
			}
		} elseif ( is_date() ) {
			$year  = (int) get_query_var( 'year' );
			$month = (int) get_query_var( 'monthnum' );

			if ( $year > 0 && $month > 0 ) {
				$tags[] = sprintf( 'date:%04d-%02d', $year, $month );
			} elseif ( $year > 0 ) {
				$tags[] = 'date:' . $year;
			}
		} elseif ( is_search() ) {
			$tags[] = 'search';
		} elseif ( is_404() ) {
			$tags[] = '404';
		}

		if ( is_front_page() || is_home() ) {
			$tags[] = 'home';
		}

		return $tags;
	}

	/**
	 * Sanitise and deduplicate tags.
	 *
	 * @param array<int, mixed> $tags
	 * @return array<int, string>
	 */
	private function sanitize_tags( array $tags ): array {
		$out = [];

		foreach ( $tags as $tag ) {
			if ( ! is_string( $tag ) && ! is_numeric( $tag ) ) {
				continue;
			}

			$tag = (string) $tag;

			// Strip anything that isn't ASCII-safe for HTTP headers.
			// Allow: letters, digits, hyphen, underscore, colon, dot.
			$tag = preg_replace( '/[^A-Za-z0-9\-_:.]/', '', $tag ) ?? '';
			$tag = substr( $tag, 0, self::MAX_TAG_LENGTH );

			if ( $tag === '' ) {
				continue;
			}

			$out[ $tag ] = true;
		}

		$out = array_keys( $out );

		if ( count( $out ) > self::MAX_TAGS ) {
			$out = array_slice( $out, 0, self::MAX_TAGS );
		}

		return $out;
	}

	/**
	 * Build the final header value, respecting the max-length budget.
	 *
	 * @param array<int, string> $tags
	 * @param int                $max_length
	 * @return string
	 */
	private function build_header_value( array $tags, int $max_length ): string {
		$max_length = max( 128, $max_length );

		$value = '';

		foreach ( $tags as $tag ) {
			$addition = ( $value === '' ) ? $tag : ( ',' . $tag );

			if ( strlen( $value ) + strlen( $addition ) > $max_length ) {
				break;
			}

			$value .= $addition;
		}

		return $value;
	}
}
