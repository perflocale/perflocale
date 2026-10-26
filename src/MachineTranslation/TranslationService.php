<?php
/**
 * Machine translation of posts, texts and strings.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\MachineTranslation\Provider\DeepLProvider;
use PerfLocale\MachineTranslation\Provider\GoogleProvider;
use PerfLocale\MachineTranslation\Provider\LibreTranslateProvider;
use PerfLocale\MachineTranslation\Provider\MicrosoftProvider;
use PerfLocale\MachineTranslation\Provider\ExternalAgencyProvider;
use PerfLocale\MachineTranslation\Provider\WpAiClientProvider;
use PerfLocale\Settings;
use PerfLocale\Translation\BlockTemplateSupport;
use PerfLocale\Translation\PostTranslationManager;
use PerfLocale\Translation\TemplateBlockRefs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chooses the provider, sends posts and texts to it, guards and sanitizes what comes back, and writes translations.
 */
final class TranslationService {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Registered providers.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( Settings $settings, CacheManager $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;

		$this->register_default_providers();
	}

	/**
	 * Register the built-in providers.
	 *
	 * @return void
	 */
	private function register_default_providers(): void {
		$this->providers['deepl']           = new DeepLProvider( $this->settings );
		$this->providers['google']          = new GoogleProvider( $this->settings );
		$this->providers['microsoft']       = new MicrosoftProvider( $this->settings );
		$this->providers['libretranslate']  = new LibreTranslateProvider( $this->settings );
		$this->providers['external_agency'] = new ExternalAgencyProvider( $this->settings );
		$this->providers['wp_ai_client']    = new WpAiClientProvider( $this->settings );

		/**
		 * Filter the machine translation providers.
		 *
		 * @param array<string, ProviderInterface> $providers Registered providers.
		 */
		$this->providers = apply_filters( 'perflocale/machine_translation/providers', $this->providers );
	}

	/**
	 * Get a provider by ID.
	 *
	 * @param string $id Provider ID (empty = use configured default).
	 * @return ProviderInterface
	 *
	 * @throws \RuntimeException If provider not found or not configured.
	 */
	public function get_provider( string $id = '' ): ProviderInterface {
		if ( $id === '' ) {
			$id = $this->settings->get_mt_provider();
		}

		if ( $id === '' ) {
			throw new \RuntimeException(
				esc_html__(
					'No machine translation provider has been selected. Open PerfLocale → Settings → Addons → Machine Translation and pick a provider.',
					'perflocale'
				)
			);
		}

		if ( ! isset( $this->providers[ $id ] ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: Provider ID */
						__( 'Unknown translation provider: %s', 'perflocale' ),
						$id
					)
				)
			);
		}

		$provider = $this->providers[ $id ];

		if ( ! $provider->is_configured() ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: Provider name */
						__( '%s is not configured. Check its settings under PerfLocale → Settings → Addons → Machine Translation.', 'perflocale' ),
						$provider->get_name()
					)
				)
			);
		}

		return $provider;
	}

	/**
	 * Mark on a machine translation this service created until its content and meta are written ("<time>", or
	 * "<time>:c" until create_translation() returned): a bulk run redoes a translation that still has it.
	 */
	public const PENDING_META_KEY = '_perflocale_mt_pending';

	/**
	 * The time in a pending mark ("<time>" or "<time>:c"); 0 for any other value.
	 *
	 * @param mixed $value Meta value.
	 * @return int
	 */
	public static function pending_time( mixed $value ): int {
		$value = is_int( $value ) ? (string) $value : ( is_string( $value ) ? $value : '' );
		$value = str_ends_with( $value, ':c' ) ? substr( $value, 0, -2 ) : $value;

		return '' !== $value && \PerfLocale\Helper::is_ascii_digits( $value ) ? (int) $value : 0;
	}

	/**
	 * Sanitize provider HTML with core's post allowlist minus object and textarea, button limited to the core/button
	 * block's attributes, plus `source`; filterable as `perflocale/mt/allowed_html`.
	 * Docs: docs-ai/11-code-rationale.md, "TranslationService: the machine-translation HTML allowlist".
	 *
	 * @param string $html Raw HTML from the provider.
	 * @return string Sanitized HTML safe for storage as post content.
	 */
	public static function sanitize_mt_html( string $html ): string {
		if ( $html === '' ) {
			return '';
		}

		$allowed = wp_kses_allowed_html( 'post' );

		// Allowed by core, with no reason to appear in a machine translation.
		unset( $allowed['object'], $allowed['textarea'] );

		// Only the attributes the core/button block saves, so a response cannot add form, popover or event behaviour.
		$allowed['button'] = [
			'type'       => [
				'values' => [ 'button', 'submit', 'reset' ],
			],
			'class'      => true,
			'id'         => true,
			'style'      => true,
			'title'      => true,
			'dir'        => true,
			'lang'       => true,
			'aria-label' => true,
		];

		$allowed['source'] = [
			'src'    => true,
			'type'   => true,
			'srcset' => true,
			'sizes'  => true,
			'media'  => true,
		];

		/**
		 * Filter the HTML allowlist applied to machine-translated content.
		 *
		 * @hook perflocale/mt/allowed_html
		 * @param array<string, array<string, bool>> $allowed Allowed tags/attributes.
		 */
		$allowed = apply_filters( 'perflocale/mt/allowed_html', $allowed );

		// wp_kses() keeps a dropped tag's text, so a <style> or <script> would show its code: empty them first.
		$html = self::empty_raw_text_elements( $html, $allowed );

		// wp_kses() also neutralises javascript: and data: URLs and strips event handlers.
		return wp_kses( $html, $allowed );
	}

	/**
	 * Empty each <script> and <style> element the allowlist does not allow, found by WordPress's HTML API where a
	 * browser finds the same element (raw_text_spans()). A tag name in an attribute or comment, or `</styles>`, is no element.
	 *
	 * @param string $html    HTML.
	 * @param mixed  $allowed Allowed tags; the filter may also return a kses context string (then unchanged).
	 * @return string Unchanged when the HTML API cannot read it (wp_kses() still runs).
	 */
	private static function empty_raw_text_elements( string $html, mixed $allowed ): string {
		if ( ! is_array( $allowed ) ) {
			return $html;
		}

		$tags = array_values( array_diff( [ 'SCRIPT', 'STYLE' ], array_map( static fn( $tag ): string => strtr( (string) $tag, self::ASCII_LOWER, self::ASCII_UPPER ), array_keys( $allowed ) ) ) );

		// No "<s", also with control characters wp_kses() removes in between: no element to empty.
		if ( [] === $tags || 0 === preg_match( '/<[\x00-\x08\x0B\x0C\x0E-\x1F]*s/i', $html ) ) {
			return $html;
		}

		// Read as wp_kses() reads it: without the control characters it removes first.
		$html = wp_kses_no_null( $html, [ 'slash_zero' => 'keep' ] );

		try {
			// Not method_exists(): the static analysis stubs are a 6.7+ core, where it is always true.
			$set_text = in_array( 'set_modifiable_text', get_class_methods( \WP_HTML_Tag_Processor::class ), true );
			$out      = $set_text ? self::emptied_texts( $html, $tags ) : self::emptied_elements( $html, $tags );
		} catch ( \Throwable $e ) {
			$out = null;
		}

		return $out ?? $html;
	}

	/**
	 * WordPress 6.7+: the text of each element is cut, its tags kept. The added newline keeps 6.7-6.9 from reading past
	 * the end of a final `<!----`.
	 *
	 * @param string       $html HTML.
	 * @param list<string> $tags Upper-case tag names.
	 * @return string
	 */
	private static function emptied_texts( string $html, array $tags ): string {
		$spans     = self::raw_text_spans( $html );
		$processor = new \WP_HTML_Tag_Processor( $html . "\n" );
		$out       = '';
		$at        = 0;

		while ( $processor->next_tag() ) {
			if ( $processor->is_tag_closer() || ! in_array( $processor->get_tag(), $tags, true ) ) {
				continue;
			}

			$span = self::token_span( $processor );
			$raw  = null === $span ? null : ( $spans[ $span[0] ] ?? null );

			// Read differently from a browser: the rest is left to wp_kses().
			if ( null === $raw || $raw[0] !== $span[1] ) {
				break;
			}

			$out .= substr( $html, $at, $raw[1] - $at );
			$at   = $raw[2];
		}

		return 0 === $at ? $html : $out . substr( $html, $at );
	}

	/**
	 * WordPress 6.4-6.6: each element becomes an empty one, located by core's bookmark spans. The added `x"'>` ends any
	 * open tag, value or quote, so those versions neither loop nor read past the end; an element reaching into it was unclosed.
	 *
	 * @param string       $html HTML.
	 * @param list<string> $tags Upper-case tag names.
	 * @return string
	 */
	private static function emptied_elements( string $html, array $tags ): string {
		$spans     = self::raw_text_spans( $html );
		$processor = new \WP_HTML_Tag_Processor( $html . "x\"'>" );
		$visit     = [ 'tag_closers' => 'visit' ];
		$out       = '';
		$at        = 0;

		while ( $processor->next_tag( $visit ) ) {
			$tag = (string) $processor->get_tag();

			if ( $processor->is_tag_closer() || ! in_array( $tag, $tags, true ) ) {
				continue;
			}

			$span = self::token_span( $processor );

			if ( null !== $span && ! $span[2] ) {
				$closer = self::closer_span( $processor, $tag );
				$span   = null === $closer ? null : [ $span[0], $closer[1], true ];
			}

			// Unclosed, or read differently from a browser: the rest is left to wp_kses().
			if ( null === $span || ( $spans[ $span[0] ][0] ?? null ) !== $span[1] ) {
				break;
			}

			$name = strtr( $tag, self::ASCII_UPPER, self::ASCII_LOWER );
			$out .= substr( $html, $at, $span[0] - $at ) . '<' . $name . '></' . $name . '>';
			$at   = $span[1];
		}

		return 0 === $at ? $html : $out . substr( $html, $at );
	}

	private const ASCII_LOWER = 'abcdefghijklmnopqrstuvwxyz';

	private const ASCII_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * Each script and style element a browser finds (HTML tokenizer rules) as start => [ end, text start, text end ], up
	 * to plaintext, an unclosed tag, comment or element, or an svg, math, select or noscript element browsers may read
	 * differently (passed when closed with plain tags inside: pass_special()).
	 *
	 * @param string $html HTML.
	 * @return array<int, array{0: int, 1: int, 2: int}>
	 */
	private static function raw_text_spans( string $html ): array {
		// One ASCII lower-case copy (same offsets, locale-free; stripos() copies the whole text per call before PHP 8.2).
		$html  = strtr( $html, self::ASCII_UPPER, self::ASCII_LOWER );
		$spans = [];
		$at    = strpos( $html, '<' );
		$dash  = -1;
		$bang  = -1;
		$in    = null;

		while ( false !== $at ) {
			$next  = $html[ $at + 1 ] ?? '';
			$close = '/' === $next ? 1 : 0;

			if ( '!' === $next && '--' === substr( $html, $at + 2, 2 ) ) {
				// Ends at the first "-->" from its own dashes on ("<!-->" too), or "--!>" after them. Each next one is
				// searched again only once passed (PHP_INT_MAX: none left), so block posts stay linear.
				if ( $dash < $at + 2 ) {
					$dash = strpos( $html, '-->', $at + 2 );
					$dash = false === $dash ? PHP_INT_MAX : $dash;
				}

				if ( $bang < $at + 4 ) {
					$bang = strpos( $html, '--!>', $at + 4 );
					$bang = false === $bang ? PHP_INT_MAX : $bang;
				}

				$end = PHP_INT_MAX === min( $dash, $bang ) ? null : ( $dash < $bang ? $dash + 3 : $bang + 4 );
			} elseif ( 1 === strspn( $html, self::ASCII_LOWER, $at + 1 + $close, 1 ) ) {
				$from = $at + 1 + $close;
				$stop = $from + strcspn( $html, "\t\n\f\r />", $from );
				$name = substr( $html, $from, $stop - $from );
				$end  = self::tag_end( $html, $stop );

				if ( null !== $in && null !== $end ) {
					$in = self::pass_special( $html, $in, $name, (bool) $close, $at, $end );

					if ( false === $in ) {
						break;
					}
				} elseif ( 0 === $close && null !== $end ) {
					if ( 'plaintext' === $name ) {
						break;
					}

					if ( in_array( $name, [ 'svg', 'math', 'select', 'noscript' ], true ) ) {
						$in = [ $name, $end, '' ];
					}

					if ( in_array( $name, [ 'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'title', 'textarea' ], true ) ) {
						$text   = $end;
						$closer = self::raw_text_closer( $html, $text, $name );
						$end    = null === $closer ? null : self::tag_end( $html, $closer + 2 + strlen( $name ) );

						if ( null !== $end && null !== $closer && ( 'script' === $name || 'style' === $name ) ) {
							$spans[ $at ] = [ $end, $text, $closer ];
						}
					}
				}
			} elseif ( '!' === $next || '?' === $next || 1 === $close ) {
				// Doctype, bogus comment, `</>`: to the first ">". Foreign content reads CDATA differently.
				if ( null !== $in && ( '' !== $in[2] || ( ( 'svg' === $in[0] || 'math' === $in[0] ) && '[cdata[' === substr( $html, $at + 2, 7 ) ) ) ) {
					break;
				}

				$end = strpos( $html, '>', $at + 2 );
				$end = false === $end ? null : $end + 1;
			} else {
				$end = $at + 1;
			}

			if ( null === $end ) {
				break;
			}

			$at = strpos( $html, '<', $end );
		}

		return $spans;
	}

	/**
	 * One tag inside an svg, math, select or noscript element [ name, text start, open integration point ]: the state
	 * after it, null once the element's closer ends it, false where browsers may read the rest differently (a raw text,
	 * nested special or non-text inside an integration point; noscript's text must end where its markup does).
	 *
	 * @param string                              $html  HTML, ASCII lower case.
	 * @param array{0: string, 1: int, 2: string} $in    The element.
	 * @param string                              $name  Tag name.
	 * @param bool                                $close Whether it is an end tag.
	 * @param int                                 $at    Offset of its "<".
	 * @param int                                 $end   Offset after its ">".
	 * @return array{0: string, 1: int, 2: string}|null|false
	 */
	private static function pass_special( string $html, array $in, string $name, bool $close, int $at, int $end ): array|null|false {
		if ( '' !== $in[2] ) {
			return $close && $name === $in[2] ? [ $in[0], $in[1], '' ] : false;
		}

		if ( $close ) {
			if ( $name !== $in[0] ) {
				return $in;
			}

			return 'noscript' !== $in[0] || self::raw_text_closer( $html, $in[1], 'noscript' ) === $at ? null : false;
		}

		if ( in_array( $name, [ 'svg', 'math', 'select', 'noscript', 'plaintext', 'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'textarea' ], true ) ) {
			return false;
		}

		// HTML integration points of svg and math: text only, up to their closer.
		$foreign = 'svg' === $in[0] || 'math' === $in[0];

		if ( $foreign && in_array( $name, [ 'foreignobject', 'desc', 'title', 'mi', 'mo', 'mn', 'ms', 'mtext', 'annotation-xml' ], true ) ) {
			return '/' === ( $html[ $end - 2 ] ?? '' ) ? false : [ $in[0], $in[1], $name ];
		}

		return 'title' === $name ? false : $in;
	}

	/**
	 * Offset of the "<" of the closer of raw text element $name whose text starts at $at; script data follows its
	 * escaped (`<!--`) and double-escaped (`<!--<script>`) states.
	 *
	 * @param string $html HTML, ASCII lower case.
	 * @param int    $at   Offset after the opener.
	 * @param string $name Lower-case tag name.
	 * @return int|null Null when unclosed.
	 */
	private static function raw_text_closer( string $html, int $at, string $name ): ?int {
		if ( 'script' !== $name ) {
			$after = 2 + strlen( $name );
			$at    = strpos( $html, '</' . $name, $at );

			while ( false !== $at && 0 === strspn( $html, "\t\n\f\r />", $at + $after, 1 ) ) {
				$at = strpos( $html, '</' . $name, $at + 2 );
			}

			return false === $at ? null : $at;
		}

		$state = 0;

		while ( true ) {
			$at = 0 === $state ? strpos( $html, '<', $at ) : $at + strcspn( $html, '-<', $at );

			if ( false === $at || ! isset( $html[ $at ] ) ) {
				return null;
			}

			if ( '-' === $html[ $at ] ) {
				$dashes = strspn( $html, '-', $at );
				$at    += $dashes;

				if ( $dashes > 1 && '>' === ( $html[ $at ] ?? '' ) ) {
					$state = 0;
				}

				continue;
			}

			if ( self::script_tag( $html, $at + 1, '/' ) ) {
				if ( 2 !== $state ) {
					return $at;
				}

				$state = 1;
			} elseif ( 0 === $state && '!--' === substr( $html, $at + 1, 3 ) ) {
				$state = 1;
				++$at;
			} elseif ( 1 === $state && self::script_tag( $html, $at + 1, '' ) ) {
				$state = 2;
			}

			++$at;
		}
	}

	/**
	 * Whether `$prefix` + "script" + a tag name end start at $at.
	 *
	 * @param string $html   HTML, ASCII lower case.
	 * @param int    $at     Offset.
	 * @param string $prefix "/" or "".
	 * @return bool
	 */
	private static function script_tag( string $html, int $at, string $prefix ): bool {
		$length = strlen( $prefix ) + 6;

		return $at + $length < strlen( $html ) && 0 === substr_compare( $html, $prefix . 'script', $at, $length ) && 1 === strspn( $html, "\t\n\f\r />", $at + $length, 1 );
	}

	/**
	 * Offset after the ">" ending the tag whose name ends at $at, its attribute values quoted or not.
	 *
	 * @param string $html HTML.
	 * @param int    $at   Offset after the tag name.
	 * @return int|null Null when the tag is unclosed.
	 */
	private static function tag_end( string $html, int $at ): ?int {
		$length = strlen( $html );

		while ( $at < $length ) {
			if ( '>' === $html[ $at ] ) {
				return $at + 1;
			}

			if ( 1 === strspn( $html, "\t\n\f\r /", $at, 1 ) ) {
				++$at;
				continue;
			}

			// An attribute name (it may start with "="), then an optional value.
			$at += 1 + strcspn( $html, "\t\n\f\r />=", $at + 1 );
			$at += strspn( $html, "\t\n\f\r ", $at );

			if ( '=' !== ( $html[ $at ] ?? '' ) ) {
				continue;
			}

			$at   += 1 + strspn( $html, "\t\n\f\r ", $at + 1 );
			$quote = $html[ $at ] ?? '';

			if ( '"' === $quote || "'" === $quote ) {
				$at = strpos( $html, $quote, $at + 1 );

				if ( false === $at ) {
					return null;
				}

				++$at;
			} else {
				$at += strcspn( $html, "\t\n\f\r >", $at );
			}
		}

		return null;
	}

	/**
	 * WordPress 6.4: span of the closer of $tag, which is the next tag after its opener.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor on the opener.
	 * @param string                 $tag       Upper-case tag name.
	 * @return array{0: int, 1: int, 2: bool}|null
	 */
	private static function closer_span( \WP_HTML_Tag_Processor $processor, string $tag ): ?array {
		return $processor->next_tag( [ 'tag_closers' => 'visit' ] ) && $processor->is_tag_closer() && $processor->get_tag() === $tag ? self::token_span( $processor ) : null;
	}

	/**
	 * [ start, end, whole element ] of the current tag from core's protected bookmarks: 6.4 stores the ">" offset as
	 * `end`, 6.5+ a `length` over the whole script or style element.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor on a tag.
	 * @return array{0: int, 1: int, 2: bool}|null
	 */
	private static function token_span( \WP_HTML_Tag_Processor $processor ): ?array {
		if ( ! $processor->set_bookmark( 'pfl_raw' ) ) {
			return null;
		}

		$bookmarks = ( new \ReflectionProperty( \WP_HTML_Tag_Processor::class, 'bookmarks' ) )->getValue( $processor );
		$processor->release_bookmark( 'pfl_raw' );

		$span  = is_array( $bookmarks ) && isset( $bookmarks['pfl_raw'] ) && is_object( $bookmarks['pfl_raw'] ) ? get_object_vars( $bookmarks['pfl_raw'] ) : [];
		$start = $span['start'] ?? null;

		if ( ! is_int( $start ) ) {
			return null;
		}

		if ( isset( $span['length'] ) && is_int( $span['length'] ) ) {
			return [ $start, $start + $span['length'], true ];
		}

		return isset( $span['end'] ) && is_int( $span['end'] ) ? [ $start, $span['end'] + 1, false ] : null;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Whether the selected provider can translate: a key alone is not enough (no provider selected fails in
	 * get_provider()), and the WordPress AI Client also needs a connected text provider. The editor shows Translate
	 * buttons only then.
	 *
	 * @return bool
	 */
	public function is_active_provider_ready(): bool {
		$id = $this->settings->get_mt_provider();

		if ( $id === '' || ! isset( $this->providers[ $id ] ) ) {
			return false;
		}

		$provider = $this->providers[ $id ];

		if ( ! $provider->is_configured() ) {
			return false;
		}

		return $provider instanceof WpAiClientProvider ? $provider->is_ready_for_text() : true;
	}

	/**
	 * Target of a "set up machine translation" link: core's Settings > Connectors screen when the AI Client is selected
	 * and that screen exists, else `$fallback`.
	 *
	 * @param string $fallback PerfLocale settings URL for every other case.
	 * @return string
	 */
	public function setup_url( string $fallback ): string {
		if ( 'wp_ai_client' === $this->settings->get_mt_provider() && self::connectors_screen_exists() ) {
			return admin_url( 'options-connectors.php' );
		}

		return $fallback;
	}

	/**
	 * Whether core's Settings → Connectors screen exists (WordPress 7.0+).
	 *
	 * @return bool
	 */
	public static function connectors_screen_exists(): bool {
		return file_exists( ABSPATH . 'wp-admin/options-connectors.php' );
	}

	/**
	 * Translate one text (the block toolbar's entry point): nothing is stored, the monthly cap still applies.
	 *
	 * @param string $text Source HTML/text to translate.
	 * @param string $source_lang Source language slug (e.g. "en").
	 * @param string $target_lang Target language slug (e.g. "fr").
	 * @param string $provider_id Provider ID (empty = configured default).
	 * @param bool   $fast_fail   Skip provider-internal retries.
	 * @return string Translated text.
	 *
	 * @throws \RuntimeException On provider failure, misconfiguration, or
	 *   when the monthly character cap would be exceeded.
	 */
	public function translate_text( string $text, string $source_lang, string $target_lang, string $provider_id = '', bool $fast_fail = false ): string {
		$text = trim( $text );

		if ( $text === '' ) {
			return '';
		}

		$provider = $this->get_provider( $provider_id );

		/**
		 * Filter the source text of a single-text translation (as perflocale/mt/pre_translate for posts).
		 *
		 * @hook perflocale/mt/pre_translate_text
		 * @param string $text
		 * @param string $source_lang
		 * @param string $target_lang
		 * @param string $provider_id
		 */
		$text = (string) apply_filters(
			'perflocale/mt/pre_translate_text',
			$text,
			$source_lang,
			$target_lang,
			$provider->get_id()
		);

		// The monthly cap, counted on exactly what is sent, as translate_batch_texts() does.
		if ( $this->would_exceed_limit( mb_strlen( $text ) ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Characters required, %2$s: Monthly limit */
						__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( mb_strlen( $text ) ),
						number_format_i18n( (int) $this->settings->get( 'mt_monthly_char_limit', 500000 ) )
					)
				)
			);
		}

		// Shortcode tags are held back from the provider and put back.
		$shortcodes = ShortcodeMask::mask( $text, $provider instanceof AbstractProvider && $provider->placeholders_as_elements() );
		$translated = $provider->translate( null === $shortcodes ? $text : $shortcodes->text(), $source_lang, $target_lang, $fast_fail );

		if ( null !== $shortcodes && '' !== trim( $translated ) ) {
			$translated = $shortcodes->restore( $translated );
		}

		// The same allowlist as translate_post(): Gutenberg renders block attributes as HTML.
		$translated = self::sanitize_mt_html( $translated );

		// A non-empty source that comes back empty is a provider failure: the editor would write '' into the block under a
		// success notice. Before the post_translate_text filter, which may still empty it on purpose.
		if ( '' === trim( $translated ) ) {
			throw new \RuntimeException(
				esc_html__( 'The translation provider returned an empty result; aborting so existing content is not overwritten.', 'perflocale' )
			);
		}

		/**
		 * Filter the translated text before return.
		 *
		 * @hook perflocale/mt/post_translate_text
		 * @param string $translated
		 * @param string $source
		 * @param string $source_lang
		 * @param string $target_lang
		 * @param string $provider_id
		 */
		return (string) apply_filters(
			'perflocale/mt/post_translate_text',
			$translated,
			$text,
			$source_lang,
			$target_lang,
			$provider->get_id()
		);
	}

	/**
	 * Translate texts in one provider call (providers also charge per request), with translate_text()'s filters and
	 * sanitising.
	 *
	 * @param string[] $texts Source HTML/text strings to translate (in order).
	 * @param string   $source_lang
	 * @param string   $target_lang
	 * @param string   $provider_id Provider ID ('' = configured default).
	 * @param bool     $fast_fail   Skip provider-internal retries.
	 * @param string   $format      'html' (HTML mode, allowlist sanitiser) or 'text' (text mode, text sanitiser); one
	 *   format per call.
	 * @return string[] Translated strings, parallel-indexed to $texts.
	 *
	 * @throws \RuntimeException On provider failure or misconfiguration.
	 */
	public function translate_batch_texts( array $texts, string $source_lang, string $target_lang, string $provider_id = '', bool $fast_fail = false, string $format = 'html' ): array {
		if ( empty( $texts ) ) {
			return [];
		}

		// Keys 0..N-1, the indexing the result keeps.
		$texts          = array_values( $texts );
		$original_count = count( $texts );

		// Only non-empty texts are sent (empty ones cost budget, and DeepL rejects them); the result is expanded back.
		$non_empty    = [];      // subset_index → text
		$position_map = [];   // subset_index → original_index

		foreach ( $texts as $orig_idx => $t ) {
			$t_str = (string) $t;
			if ( trim( $t_str ) === '' ) {
				continue;
			}
			$position_map[] = $orig_idx;
			$non_empty[]    = $t_str;
		}

		if ( empty( $non_empty ) ) {
			// All empty: parallel empty strings.
			return array_fill( 0, $original_count, '' );
		}

		$provider = $this->get_provider( $provider_id );

		// Per entry, so single-text filter callbacks work unchanged.
		foreach ( $non_empty as $i => $t ) {
			/** This filter is documented in src/MachineTranslation/TranslationService.php */
			$non_empty[ $i ] = (string) apply_filters(
				'perflocale/mt/pre_translate_text',
				(string) $t,
				$source_lang,
				$target_lang,
				$provider->get_id()
			);
		}

		// The monthly cap for every provider-bound path (term MT, bulk strings): $non_empty is exactly what is billed.
		$provider_chars = 0;
		foreach ( $non_empty as $t ) {
			$provider_chars += mb_strlen( (string) $t );
		}

		if ( $this->would_exceed_limit( $provider_chars ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Characters required, %2$s: Monthly limit */
						__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( $provider_chars ),
						number_format_i18n( (int) $this->settings->get( 'mt_monthly_char_limit', 500000 ) )
					)
				)
			);
		}

		// Shortcode tags in an html text are held back from the provider and put back.
		$shortcodes = [];
		$sent       = $non_empty;

		if ( 'html' === $format ) {
			$elements = $provider instanceof AbstractProvider && $provider->placeholders_as_elements();

			foreach ( $non_empty as $i => $t ) {
				$shortcodes[ $i ] = ShortcodeMask::mask( (string) $t, $elements );

				if ( null !== $shortcodes[ $i ] ) {
					$sent[ $i ] = $shortcodes[ $i ]->text();
				}
			}
		}

		$translated = $provider->translate_batch( $sent, $source_lang, $target_lang, $fast_fail, $format );

		// A shorter or longer answer (truncated response, partial timeout) fails the call: a missing slot would blank
		// the block text it is written back to.
		if ( count( $translated ) !== count( $non_empty ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: number of translations returned, 2: number sent */
						__( 'Translation provider returned %1$d entries for a batch of %2$d; aborting to avoid overwriting source text with empty strings.', 'perflocale' ),
						count( $translated ),
						count( $non_empty )
					)
				)
			);
		}

		foreach ( $shortcodes as $i => $held ) {
			if ( null !== $held && isset( $translated[ $i ] ) && '' !== trim( (string) $translated[ $i ] ) ) {
				$translated[ $i ] = $held->restore( (string) $translated[ $i ] );
			}
		}

		// Sanitize per entry, in order.
		foreach ( $translated as $i => $t ) {
			// Text batches use the text sanitiser: wp_kses would turn a bare '&' into '&amp;', what text mode was meant to keep.
			$t = ( 'text' === $format )
				? sanitize_textarea_field( (string) $t )
				: self::sanitize_mt_html( (string) $t );

			/** This filter is documented in src/MachineTranslation/TranslationService.php */
			$translated[ $i ] = (string) apply_filters(
				'perflocale/mt/post_translate_text',
				$t,
				$non_empty[ $i ] ?? '',
				$source_lang,
				$target_lang,
				$provider->get_id()
			);
		}

		// Back to the caller's indexing; empty inputs give empty outputs.
		$result = array_fill( 0, $original_count, '' );

		foreach ( $position_map as $subset_i => $orig_i ) {
			$result[ $orig_i ] = isset( $translated[ $subset_i ] ) ? (string) $translated[ $subset_i ] : '';
		}

		return $result;
	}

	/**
	 * Translate a post's title, content and excerpt into a language, creating or updating its translation.
	 *
	 * @param int       $post_id      Source post ID.
	 * @param string    $target_lang  Target language slug.
	 * @param string    $provider_id  Provider ID (empty = default).
	 * @param bool      $fast_fail    Fail on the first provider error instead of retrying.
	 * @param bool      $include_meta Translate the registered meta fields too.
	 * @param bool      $inserted     Set to true when this call inserted the translation post,
	 *                                false when it updated one that existed.
	 * @return array<string, mixed> post_id, title, content, excerpt, and warnings, kept, builder_warning, meta when they apply.
	 *
	 * @throws \RuntimeException On translation failure.
	 */
	public function translate_post( int $post_id, string $target_lang, string $provider_id = '', bool $fast_fail = false, bool $include_meta = false, bool &$inserted = false ): array {
		$inserted = false;
		$post     = get_post( $post_id );

		if ( ! $post ) {
			throw new \RuntimeException(
				esc_html__( 'Source post not found.', 'perflocale' )
			);
		}

		// The monthly cap before any call.
		$this->enforce_character_limit( $post );

		$provider = $this->get_provider( $provider_id );

		// The source post's own language, not the site default (imported originals are often in another language); it
		// also reaches MetaTranslator and the after hook. Null only for a post without a language row.
		$source_language = ( new PostTranslationManager( $this->cache, $this->settings ) )
			->detect_post_language( $post_id );

		if ( $source_language && ! empty( $source_language->slug ) ) {
			$source_lang = (string) $source_language->slug;
		} else {
			$lang_repo   = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
			$default     = $lang_repo->get_default();
			$source_lang = $default ? (string) $default->slug : 'en';
		}

		// Never into the post's own language: the result would overwrite the post. Here so every caller is covered.
		if ( strtolower( trim( $source_lang ) ) === strtolower( trim( $target_lang ) ) ) {
			throw new SameLanguageException(
				esc_html__( 'Source and target languages match; nothing to translate.', 'perflocale' )
			);
		}

		/** @hook perflocale/machine_translation/before Fires before machine translation. */
		do_action( 'perflocale/machine_translation/before', $post_id, $provider->get_id() );

		try {
			// The translation's text before the call: a person may save it meanwhile, and it is overwritten only while unchanged.
			$manager       = new PostTranslationManager( $this->cache, $this->settings );
			$translated_id = $manager->get_translation_id( $post_id, $target_lang );
			$pending_mark  = $translated_id ? get_post_meta( (int) $translated_id, self::PENDING_META_KEY, true ) : '';
			$was_pending   = ! empty( $pending_mark );

			// A creation a stopped worker left inside its copy steps gets them before the text.
			if ( $was_pending ) {
				self::redo_stopped_creation( (int) $translated_id, $pending_mark, static fn() => $manager->complete_imported_translation( (int) $translated_id, $post_id, $target_lang ) );
			}

			$target_before = $translated_id ? self::stored_text( (int) $translated_id ) : null;

			// A page builder layout this translation leaves out is reported with the result.
			$builder = BuilderLayout::pending( $post_id, (string) $post->post_content );

			$texts = [
				$post->post_title,
				$post->post_content,
				$post->post_excerpt,
			];

			/**
			 * Filter the texts sent to the machine translation provider.
			 *
			 * @param array $texts Array of texts to translate.
			 * @param string $source_lang Source language slug.
			 * @param string $target_lang Target language slug.
			 * @param string $provider_id Provider ID.
			 */
			$texts = apply_filters( 'perflocale/mt/pre_translate', $texts, $source_lang, $target_lang, $provider->get_id() );

			// Title and excerpt in text mode (HTML mode entity-escapes & and '), content in HTML mode: two calls. Empty slots
			// are not sent (DeepL rejects empty strings).
			$plain_inputs = [];
			$plain_slots  = [];

			foreach ( [ 0, 2 ] as $slot ) {
				$value = (string) ( $texts[ $slot ] ?? '' );

				if ( '' !== $value ) {
					$plain_slots[]  = $slot;
					$plain_inputs[] = $value;
				}
			}

			$plain_out = [] === $plain_inputs
				? []
				: $provider->translate_batch( $plain_inputs, $source_lang, $target_lang, $fast_fail, 'text' );

			// Shortcode tags and block delimiters become placeholders the provider cannot change; text kept only in block
			// attributes travels as extra segments after the content.
			$elements    = $provider instanceof AbstractProvider && $provider->placeholders_as_elements();
			$content_src = (string) ( $texts[1] ?? '' );
			$shortcodes  = '' === $content_src ? null : ShortcodeMask::mask( $content_src, $elements );
			$content_in  = null === $shortcodes ? $content_src : $shortcodes->text();
			$content_in  = $elements ? \PerfLocale\Translation\BlockSkipFilter::placeholders_as_elements( $content_in ) : $content_in;
			$mask        = '' === $content_in ? null : BlockDelimiterMask::mask( $content_in, $elements );
			$html_in     = null === $mask ? [ $content_in ] : array_merge( [ $mask->text() ], $mask->segment_texts() );
			$html_out    = '' === $content_src
				? [ '' ]
				: $provider->translate_batch( $html_in, $source_lang, $target_lang, $fast_fail, 'html' );

			// One answer per input, in order: a mismatch would scramble [title, content, excerpt], so it fails the item.
			if ( count( $plain_out ) !== count( $plain_inputs ) || count( $html_out ) !== count( $html_in ) ) {
				throw new \RuntimeException(
					esc_html(
						sprintf(
							/* translators: 1: provider id, 2: expected count, 3: actual count. */
							__( 'Translation provider "%1$s" returned %3$d segments for %2$d inputs; aborting to avoid corrupting the translation.', 'perflocale' ),
							$provider->get_id(),
							count( $plain_inputs ) + count( $html_in ),
							count( $plain_out ) + count( $html_out )
						)
					)
				);
			}

			// A title or excerpt the provider cut off is not saved; a stored one stays.
			$cut = CutText::refusal( $plain_inputs, $plain_out, $plain_slots, $target_lang );

			if ( '' !== $cut ) {
				throw new NotSavedException( esc_html( $cut ) );
			}

			// A placeholder lost, repeated or invented fails the item; an empty answer goes to the empty-field handling.
			$content_out = (string) $html_out[0];

			if ( null !== $mask && '' !== trim( $content_out ) ) {
				$content_out = $mask->restore( $content_out, array_map( 'strval', array_slice( $html_out, 1 ) ) );
			}

			// Every block marker came back, but a block's text did not; read with the shortcode tags still held back.
			$emptied_block = null === $mask || '' === trim( $content_out ) ? '' : BlockStructureGuard::lost_text( null === $shortcodes ? $content_src : $shortcodes->text(), $content_out );

			if ( '' !== $emptied_block ) {
				throw new NotSavedException( esc_html( BlockStructureGuard::reason( $emptied_block ) ) );
			}

			if ( null !== $shortcodes && '' !== trim( $content_out ) ) {
				$content_out = $shortcodes->restore( $content_out );
			}

			// Back to the "Do not translate" placeholders BlockSkipFilter restores; kses would drop the elements.
			if ( $elements && '' !== trim( $content_out ) ) {
				$content_out = \PerfLocale\Translation\BlockSkipFilter::placeholders_from_elements( $content_out, $content_src );
			}

			// Content without blocks: checked on the raw answer, since kses rewrites a cut-off tag.
			if ( null === $mask && '' !== trim( $content_out ) ) {
				$classic = ClassicStructureGuard::mismatch( $content_src, $content_out );

				if ( '' !== $classic ) {
					throw new NotSavedException( esc_html( $classic ) );
				}
			}

			// Sanitized here, before the post_translate filters, against a compromised provider; the "Do not translate"
			// placeholders are comments kses keeps, and the delimiters are put back as they were.
			$content_raw = $content_out;
			$content_out = self::sanitize_mt_html( $content_out );

			if ( null !== $mask && '' !== trim( $content_out ) ) {
				$content_out = BlockDelimiterMask::keep_delimiters(
					$content_out,
					$content_raw,
					$mask->trusted() + BlockDelimiterMask::delimiter_texts( (string) $post->post_content )
				);
			}

			// The positional [title, content, excerpt] triple the filters expect.
			$translated = [ '', $content_out, '' ];

			foreach ( $plain_slots as $i => $slot ) {
				$translated[ $slot ] = (string) ( $plain_out[ $i ] ?? '' );
			}

			/**
			 * Filter texts after receiving from the MT provider.
			 *
			 * The output of this filter is saved as is; sanitize what you add. The content arrives sanitized
			 * (`perflocale/mt/allowed_html`) and is not sanitized again, so restored "Do not translate" blocks keep their markup.
			 *
			 * @hook perflocale/mt/post_translate
			 * @param array  $translated  [title, content, excerpt] from the provider.
			 * @param array  $original    [title, content, excerpt] of the source post.
			 * @param string $target_lang Target language slug.
			 * @param string $provider_id Provider ID.
			 */
			$pre_filter = $translated;
			$translated = apply_filters( 'perflocale/mt/post_translate', $translated, [ $post->post_title, $post->post_content, $post->post_excerpt ], $target_lang, $provider->get_id() );

			// A lost "Do not translate" placeholder made BlockSkipFilter put the source content back: reported, and an
			// existing translation keeps its content (below).
			$content_kept_source = \PerfLocale\Translation\BlockSkipFilter::consume_kept_source( [ $post->post_title, $post->post_content, $post->post_excerpt ] );

			// The source blocks BlockSkipFilter put back; the save keeps them byte for byte (ProtectedBlockSave).
			$protected_blocks = \PerfLocale\Translation\BlockSkipFilter::consume_restored( [ $post->post_title, $post->post_content, $post->post_excerpt ] );

			// The exact [0 => title, 1 => content, 2 => excerpt] string triple, else the unfiltered result: a short or keyed
			// array would blank the content.
			$valid_shape = is_array( $translated )
				&& array_key_exists( 0, $translated ) && is_string( $translated[0] )
				&& array_key_exists( 1, $translated ) && is_string( $translated[1] )
				&& array_key_exists( 2, $translated ) && is_string( $translated[2] );

			if ( ! $valid_shape ) {
				_doing_it_wrong(
					'apply_filters( "perflocale/mt/post_translate", ... )',
					esc_html(
						sprintf(
							/* translators: %s is the offending return type. */
							__( 'A hook on perflocale/mt/post_translate returned %s - must be an array<int,string> of [title, content, excerpt]. Falling back to the unfiltered translation.', 'perflocale' ),
							is_array( $translated ) ? 'a malformed array (expected positional [title, content, excerpt] strings)' : get_debug_type( $translated )
						)
					),
					'1.0.0'
				);
				$translated = $pre_filter;
			}

			// Title and excerpt are plain text from text mode: no entity decode (a typed '&amp;' would become '&'). The content
			// was sanitized before the filters.
			$result = [
				'title'   => \PerfLocale\Helper::sanitize_plain_text_field( $translated[0] ?? '' ),
				'content' => is_string( $translated[1] ?? null ) ? $translated[1] : '',
				'excerpt' => \PerfLocale\Helper::sanitize_plain_textarea_field( $translated[2] ?? '' ),
			];

			// Block content must keep the source's block structure, or the item fails and nothing is written; empty content
			// goes to the empty-field handling below.
			if ( '' !== trim( $result['content'] ) ) {
				$structure = BlockStructureGuard::mismatch( (string) $post->post_content, $result['content'] );

				if ( '' !== $structure ) {
					throw new NotSavedException( esc_html( $structure ) );
				}
			}

			// A save stopped before it wrote anything: no meta translation and no success hook follow.
			$nothing_written = false;

			// Create or update the translation. An empty field is never written over a non-empty one: a provider can answer ""
			// with the right count. Only that field is dropped and named in `kept`; on create it is written as is.
			// Docs: docs-ai/11-code-rationale.md, "TranslationService: what translate_post() never writes".
			$emptied = [];

			foreach (
				[
					'title'   => 'post_title',
					'content' => 'post_content',
					'excerpt' => 'post_excerpt',
				] as $field => $column
			) {
				if ( '' === trim( (string) $result[ $field ] ) && '' !== trim( (string) $post->{$column} ) ) {
					$emptied[ $field ] = $column;
				}
			}

			// A classic body answered with tags but no text (<p></p>) is an empty answer too.
			if ( null === $mask && BlockStructureGuard::has_text( (string) $post->post_content ) && ! BlockStructureGuard::has_text( (string) $result['content'] ) ) {
				$emptied['content'] = 'post_content';
			}

			$content_emptied = isset( $emptied['content'] );

			if ( $content_emptied ) {
				$result['warnings'][] = __( 'The provider returned no translated content; the existing content was kept.', 'perflocale' );
			}

			$kept_source_warning = __( 'A "Do not translate" section was lost in machine translation, so the content was kept unchanged.', 'perflocale' );

			if ( $content_kept_source ) {
				$result['content_kept_source'] = true;
				$result['warnings'][]          = $kept_source_warning;
			}

			if ( $translated_id ) {
				// The content is the untranslated source: an existing translation keeps its content, an empty one gets the source.
				if ( $content_kept_source ) {
					$existing_content = get_post_field( 'post_content', (int) $translated_id, 'raw' );

					if ( is_string( $existing_content ) && '' !== trim( $existing_content ) ) {
						$emptied['content'] = 'post_content';
					}
				}

				// Field list for CLI and REST callers (not shown to users).
				if ( $emptied !== [] ) {
					$result['kept'] = array_keys( $emptied );
				}

				// An empty page_template keeps the stored one: a template the theme lacks would stop the save before its hooks.
				$update_fields = [
					'ID'            => $translated_id,
					'post_title'    => $result['title'],
					'post_content'  => $result['content'],
					'post_excerpt'  => $result['excerpt'],
					'page_template' => '',
				];

				foreach ( $emptied as $column ) {
					unset( $update_fields[ $column ] );
				}

				// Saved by someone while the provider worked: their text stays. Checked here and by the guard before the write.
				$conflict = static fn(): NotSavedException => new NotSavedException(
					esc_html__( 'Not saved: someone edited the translation while the provider was translating it, so their edit was kept.', 'perflocale' )
				);

				if ( self::stored_text( (int) $translated_id ) !== $target_before ) {
					throw $conflict();
				}

				// The redo's own save must read as "not saved after the mark" if this run stops before the meta too.
				if ( $was_pending ) {
					update_post_meta( (int) $translated_id, self::PENDING_META_KEY, time() );
				}

				// "Do not translate" blocks pass the content filters as placeholders and are put back, checked against the stored
				// source, right before the row is written.
				$keep = isset( $update_fields['post_content'] )
					&& [] !== $protected_blocks
					? ProtectedBlockSave::prepare( (string) $update_fields['post_content'], (string) $post->post_content, $post_id, (int) $translated_id )
					: null;

				if ( null !== $keep ) {
					$update_fields['post_content'] = $keep->content();
				}

				$guard = self::guard_text( (int) $translated_id, $target_before );

				// A save that writes the content is the machine translation's: the general save rule leaves it to
				// ProtectedBlockSave. A kept content is saved again as stored, so that rule keeps its blocks.
				$claimed = isset( $update_fields['post_content'] );

				if ( $claimed ) {
					\PerfLocale\Translation\SaveScope::claim( (int) $translated_id );
				}

				try {
					$keep?->arm();

					$update_result = wp_update_post(
						// wp_slash: wp_update_post() unslashes, and backslashes in the output would be lost.
						wp_slash( $update_fields ),
						true
					);
				} finally {
					$keep?->disarm();

					if ( $claimed ) {
						\PerfLocale\Translation\SaveScope::release( (int) $translated_id );
					}

					$stopped = $guard();
				}

				if ( ! is_wp_error( $update_result ) || ! $stopped ) {
					$keep?->settle();
				}

				if ( $stopped && is_wp_error( $update_result ) ) {
					throw $conflict();
				}

				// A protected block did not match the stored source at write time: the save stopped, the translation keeps what it
				// has, and the lost section is reported. If the stop did not take, only title and excerpt changed.
				if ( null !== $keep && $keep->refused() ) {
					$result['content_kept_source'] = true;
					$result['kept']                = is_wp_error( $update_result )
						? [ 'title', 'content', 'excerpt' ]
						: array_values( array_unique( array_merge( array_keys( $emptied ), [ 'content' ] ) ) );
					$nothing_written               = is_wp_error( $update_result );

					if ( ! in_array( $kept_source_warning, $result['warnings'] ?? [], true ) ) {
						$result['warnings'][] = $kept_source_warning;
					}
				} elseif ( is_wp_error( $update_result ) ) {
					throw new \RuntimeException( $update_result->get_error_message() );
				}

				self::content_replaced( $post->post_type, (int) $translated_id, $update_fields, $update_result, $keep );
			} else {
				$created = false;

				// The pending mark is written by the insert itself, before the translation is linked.
				$marked = 0;
				$mark   = static function ( $new_id, $new_post, $update ) use ( &$marked, $post ): void {
					if ( 0 === $marked && ! $update && $new_post instanceof \WP_Post && $new_post->post_type === $post->post_type ) {
						$marked = is_numeric( $new_id ) ? (int) $new_id : 0;
						add_post_meta( $marked, self::PENDING_META_KEY, time() . ':c', true );
					}
				};

				add_action( 'wp_insert_post', $mark, 1, 3 );

				try {
					$translated_id = $manager->create_translation( $post_id, $target_lang, false, \PerfLocale\Enum\SourceType::MachineTranslation, $created );
				} finally {
					remove_action( 'wp_insert_post', $mark, 1 );
				}

				if ( $marked > 0 && ( true !== $created || $marked !== (int) $translated_id ) ) {
					delete_post_meta( $marked, self::PENDING_META_KEY );
				}

				// create_translation() returned, so its copy steps ran: the mark keeps no creation stage.
				if ( true === $created && $translated_id ) {
					update_post_meta( (int) $translated_id, self::PENDING_META_KEY, time() );
				}

				if ( ! $translated_id ) {
					// create_translation() can return false (race, target removed, link transaction failed): characters were spent
					// and no post exists, which must not read as success.
					throw new \RuntimeException(
						esc_html__( 'Failed to create the translation post; the source-language post may have been moved or its translation group invalidated mid-translation.', 'perflocale' )
					);
				}

				// Machine output goes only into a translation this call inserted: one that existed may hold a person's work the
				// overwrite checks never saw, and this write skips the empty-field guard.
				if ( true !== $created ) {
					throw new \RuntimeException(
						esc_html__( 'This translation already exists, so the machine translation was not written to it.', 'perflocale' )
					);
				}

				$inserted = true;
				$new_row  = self::stored_text( (int) $translated_id, true );

				// "Do not translate" blocks pass the content filters as placeholders and are put back, checked against the stored
				// source, right before the row is written.
				$keep = [] !== $protected_blocks
					? ProtectedBlockSave::prepare( (string) $result['content'], (string) $post->post_content, $post_id, (int) $translated_id )
					: null;

				\PerfLocale\Translation\SaveScope::claim( (int) $translated_id );

				try {
					$keep?->arm();

					$update_result = wp_update_post(
						// wp_slash: wp_update_post() unslashes. An empty page_template keeps the stored one (see above).
						wp_slash(
							[
								'ID'            => $translated_id,
								'post_title'    => $result['title'],
								'post_content'  => null !== $keep ? $keep->content() : $result['content'],
								'post_excerpt'  => $result['excerpt'],
								'page_template' => '',
							]
						),
						true
					);
				} finally {
					$keep?->disarm();
					\PerfLocale\Translation\SaveScope::release( (int) $translated_id );
				}

				$keep?->settle();

				// A protected block did not match the stored source: nothing was written, the inserted translation is removed
				// below, and the run fails with the lost section reason.
				if ( null !== $keep && $keep->refused() ) {
					if ( null !== $new_row && self::stored_text( (int) $translated_id, true ) === $new_row ) {
						wp_delete_post( (int) $translated_id, true );
						$inserted = false;
					}

					throw new NotSavedException( esc_html( $kept_source_warning ) );
				}

				if ( is_wp_error( $update_result ) ) {
					// The inserted translation holds none of the output: linked, a later run would skip it, so it is removed while its
					// row is still as inserted.
					if ( null !== $new_row && self::stored_text( (int) $translated_id, true ) === $new_row ) {
						wp_delete_post( (int) $translated_id, true );
						$inserted = false;
					}

					throw new \RuntimeException( $update_result->get_error_message() );
				}

				if ( BlockTemplateSupport::is_template_type( $post->post_type ) ) {
					TemplateBlockRefs::content_replaced( (int) $translated_id );
				}
			}

			if ( '' !== $builder ) {
				$result = BuilderLayout::report( $result, $builder );
			}

			$result['post_id'] = (int) $translated_id;

			if ( $nothing_written ) {
				return $result;
			}

			// Meta fields: a failure never fails the post (it goes to $result['meta']['errors'] and the breadcrumb).
			if ( $include_meta ) {
				$result['meta'] = ( new MetaTranslator( $this->settings, $this ) )
					->translate_post_meta( $post_id, (int) $translated_id, $source_lang, $target_lang, $provider_id );
			}

			if ( $inserted || $was_pending ) {
				delete_post_meta( (int) $translated_id, self::PENDING_META_KEY );
			}

			/**
			 * Fires after successful machine translation.
			 *
			 * @hook perflocale/machine_translation/after
			 * @param int      $post_id     Source post ID.
			 * @param string   $provider_id Provider that translated.
			 * @param array    $result      {title, content, excerpt, post_id}.
			 * @param string   $target_lang Target language slug.
			 * @param string   $source_lang Source language slug.
			 * @param \WP_Post $post        Source post object.
			 */
			do_action( 'perflocale/machine_translation/after', $post_id, $provider->get_id(), $result, $target_lang, $source_lang, $post );

			return $result;
		} catch ( \Throwable $e ) {
			/** @hook perflocale/machine_translation/failed Fires when machine translation fails. */
			do_action( 'perflocale/machine_translation/failed', $post_id, $provider->get_id(), $e );

			throw $e;
		} finally {
			// BlockSkipFilter keeps this post's "do not translate" subtrees in a static between pre_ and post_translate; after
			// an exception or a bailed restore they would stay for the process, so the entry is cleared.
			\PerfLocale\Translation\BlockSkipFilter::discard_stash( [ $post->post_title, $post->post_content, $post->post_excerpt ] );
		}
	}

	/**
	 * After the update branch's save: a template translation whose content it wrote gets its references rewritten.
	 * A refused "Do not translate" save wrote none of it and does not throw, so the user's switch-back stays.
	 *
	 * @param string                  $post_type     Source post type.
	 * @param int                     $translated_id Translation id.
	 * @param array<string, mixed>    $update_fields Fields sent to wp_update_post().
	 * @param mixed                   $update_result wp_update_post()'s result.
	 * @param ProtectedBlockSave|null $keep          The "Do not translate" save, if any.
	 * @return void
	 */
	private static function content_replaced( string $post_type, int $translated_id, array $update_fields, mixed $update_result, ?ProtectedBlockSave $keep ): void {
		if ( isset( $update_fields['post_content'] ) && ! is_wp_error( $update_result ) && ! ( null !== $keep && $keep->refused() ) && BlockTemplateSupport::is_template_type( $post_type ) ) {
			TemplateBlockRefs::content_replaced( $translated_id );
		}
	}

	/**
	 * Complete a creation a stopped worker left: a "<time>:c" mark older than a lease (younger: a live call's), the post
	 * not saved more than a minute after it (later: a person finished it; the rule of BulkTranslateJob's redo).
	 *
	 * @param int      $translated_id Translation post.
	 * @param mixed    $mark          Its pending mark.
	 * @param callable $complete      The copy steps.
	 * @return void
	 */
	private static function redo_stopped_creation( int $translated_id, mixed $mark, callable $complete ): void {
		$time = is_string( $mark ) && str_ends_with( $mark, ':c' ) ? self::pending_time( $mark ) : 0;

		// The age is checked before the row is read.
		if ( $time <= 0 || time() - $time < \PerfLocale\Background\JobResume::LEASE ) {
			return;
		}

		$row   = self::stored_text( $translated_id, true );
		$saved = is_string( $row['post_modified_gmt'] ?? null ) ? strtotime( $row['post_modified_gmt'] . ' UTC' ) : false;

		if ( false !== $saved && $saved <= $time + MINUTE_IN_SECONDS ) {
			self::complete_creation( $translated_id, $complete );
		}
	}

	/**
	 * Run a stopped creation's copy steps again, adding no meta row under a key the translation holds; then drop the
	 * creation stage from its pending mark. `translation/created` listeners run again.
	 *
	 * @param int      $translated_id Translation post.
	 * @param callable $complete      The copy steps (PostTranslationManager::complete_imported_translation()).
	 * @return void
	 */
	private static function complete_creation( int $translated_id, callable $complete ): void {
		$held = get_post_meta( $translated_id );
		$held = is_array( $held ) ? array_map( 'strval', array_keys( $held ) ) : [];
		$skip = static fn( $check, $object_id, $meta_key ) => is_numeric( $object_id ) && (int) $object_id === $translated_id && is_string( $meta_key ) && in_array( $meta_key, $held, true ) ? false : $check;

		add_filter( 'add_post_metadata', $skip, 1, 3 );

		try {
			$complete();
		} finally {
			remove_filter( 'add_post_metadata', $skip, 1 );
		}

		update_post_meta( $translated_id, self::PENDING_META_KEY, time() );
	}

	/**
	 * A post's title, content and excerpt (or whole row) as the database holds them now, not as a cache saw them.
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $whole_row Every column of the row, not only the three text columns.
	 * @return array<string, string>|null Null when the post has no row or the read failed.
	 */
	private static function stored_text( int $post_id, bool $whole_row = false ): ?array {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$row = $whole_row
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row: another request may have saved it.
			? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $post_id ), ARRAY_A )
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row: another request may have saved it.
			: $wpdb->get_row( $wpdb->prepare( 'SELECT post_title, post_content, post_excerpt FROM %i WHERE ID = %d', $wpdb->posts, $post_id ), ARRAY_A );

		if ( ! is_array( $row ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$text = [];

		foreach ( $whole_row ? array_keys( $row ) : [ 'post_title', 'post_content', 'post_excerpt' ] as $column ) {
			$value                    = $row[ $column ] ?? null;
			$text[ (string) $column ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $text;
	}

	/**
	 * Hold a post's text to $expected during wp_update_post(): right before the main posts UPDATE (after the
	 * wp_insert_post_data filters and pre_post_update) the text is read again, and when it changed the UPDATE is not sent
	 * (wp_update_post() returns 'db_update_error' before any after-save hook).
	 *
	 * @param int                        $post_id  Post being saved.
	 * @param array<string, string>|null $expected stored_text() before the save.
	 * @return \Closure(): bool Removes the guard; true when it stopped the UPDATE.
	 */
	private static function guard_text( int $post_id, ?array $expected ): \Closure {
		$armed   = false;
		$stopped = false;
		$arm     = static function ( $id ) use ( $post_id, &$armed ): void {
			if ( is_numeric( $id ) && (int) $id === $post_id ) {
				$armed = true;
			}
		};
		$check   = static function ( $query ) use ( $post_id, $expected, &$armed, &$stopped ) {
			/**
			 * WordPress database.
			 *
			 * @var \wpdb $wpdb
			 */
			global $wpdb;

			if ( ! $armed || ! is_string( $query ) || ! str_starts_with( $query, 'UPDATE `' . $wpdb->posts . '` SET ' ) || 1 !== preg_match( "/ WHERE `ID` = '?" . $post_id . "'?\\z/", $query ) ) {
				return $query;
			}

			$armed = false;

			if ( self::stored_text( $post_id ) === $expected ) {
				return $query;
			}

			$stopped = true;

			return '';
		};

		add_action( 'pre_post_update', $arm, PHP_INT_MAX, 1 );
		add_filter( 'query', $check, PHP_INT_MAX, 1 );

		return static function () use ( $arm, $check, &$stopped ): bool {
			remove_action( 'pre_post_update', $arm, PHP_INT_MAX );
			remove_filter( 'query', $check, PHP_INT_MAX );

			return $stopped;
		};
	}

	/**
	 * Whether sending $estimated_chars would pass the monthly cap (usage already at it, or this request over it);
	 * false when the cap is 0 (unlimited). For callers that skip instead of failing.
	 *
	 * @param int $estimated_chars Characters the caller is about to send.
	 * @return bool True if the request would exceed the monthly cap.
	 */
	public function would_exceed_limit( int $estimated_chars ): bool {
		$limit = (int) $this->settings->get( 'mt_monthly_char_limit', 500000 );

		if ( $limit === 0 ) {
			return false;
		}

		$month_key     = 'perflocale_mt_usage_' . gmdate( 'Y_m' );
		$current_usage = (int) get_option( $month_key, 0 );

		if ( $current_usage >= $limit ) {
			return true;
		}

		return ( $current_usage + max( 0, $estimated_chars ) ) > $limit;
	}

	/**
	 * Per trigger, whether a path sends a password-protected post when nothing hooks
	 * `perflocale/mt/send_password_protected`: only when a person chose that post. An unlisted trigger does not send.
	 * The triggers are described on the filter (may_send_post()).
	 */
	private const SEND_PROTECTED_BY_DEFAULT = [
		'auto_publish'   => false,
		'auto_create'    => false,
		'site_translate' => false,
		'bulk'           => true,
		'cli_ids'        => true,
		'cli_all'        => false,
		'rest'           => true,
		'ability'        => true,
		'editor'         => true,
	];

	/**
	 * Whether a path sends a password-protected post by default (SEND_PROTECTED_BY_DEFAULT).
	 *
	 * @param string $trigger The path's trigger.
	 * @return bool
	 */
	public static function sends_protected_by_default( string $trigger ): bool {
		return self::SEND_PROTECTED_BY_DEFAULT[ $trigger ] ?? false;
	}

	/**
	 * Whether a path sends this post's text to the provider: always without a password, else as the
	 * `perflocale/mt/send_password_protected` filter decides from the path's default.
	 *
	 * @param \WP_Post $post    The post whose text would be sent.
	 * @param string   $trigger The path (a key of SEND_PROTECTED_BY_DEFAULT).
	 * @return bool
	 */
	public static function may_send_post( \WP_Post $post, string $trigger ): bool {
		if ( '' === (string) $post->post_password ) {
			return true;
		}

		/**
		 * Whether a machine-translation path sends a password-protected post's text to the provider; a refused path skips
		 * the post as any skipped item. Triggers (default): auto_publish (false), auto_create (false), site_translate
		 * (false), bulk (true), cli_ids (true), cli_all incl. --async (false), rest (true), ability (true), editor (true).
		 *
		 * @hook  perflocale/mt/send_password_protected
		 * @since 1.0.7
		 *
		 * @param bool     $send    Whether to send the post: the trigger's default.
		 * @param \WP_Post $post    The password-protected post.
		 * @param string   $trigger One of the triggers above.
		 */
		return (bool) apply_filters( 'perflocale/mt/send_password_protected', self::sends_protected_by_default( $trigger ), $post, $trigger );
	}

	/**
	 * The message a path shows when it skips a password-protected post.
	 *
	 * @return string
	 */
	public static function password_protected_skip_message(): string {
		return __( 'This post is password-protected and was not sent for machine translation.', 'perflocale' );
	}

	/**
	 * The monthly cap check of the user-initiated path, as an exception the admin can show.
	 *
	 * @param \WP_Post $post Post to be translated.
	 * @return void
	 *
	 * @throws \RuntimeException If limit would be exceeded.
	 */
	private function enforce_character_limit( \WP_Post $post ): void {
		$limit = (int) $this->settings->get( 'mt_monthly_char_limit', 500000 );

		if ( $limit === 0 ) {
			return;
		}

		$month_key     = 'perflocale_mt_usage_' . gmdate( 'Y_m' );
		$current_usage = (int) get_option( $month_key, 0 );

		if ( $current_usage >= $limit ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Current usage, %2$s: Monthly limit */
						__( 'Monthly character limit reached (%1$s / %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( $current_usage ),
						number_format_i18n( $limit )
					)
				)
			);
		}

		$estimated = mb_strlen( $post->post_title ) + mb_strlen( $post->post_content ) + mb_strlen( $post->post_excerpt );

		if ( ( $current_usage + $estimated ) > $limit ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Estimated characters, %2$s: Remaining quota */
						__( 'This post requires ~%1$s characters but only %2$s remain in the monthly quota. Reduce content or increase the limit in settings.', 'perflocale' ),
						number_format_i18n( $estimated ),
						number_format_i18n( $limit - $current_usage )
					)
				)
			);
		}
	}
}
