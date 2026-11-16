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
use PerfLocale\Resync\SyncRecorder;
use PerfLocale\Settings;
use PerfLocale\Translation\BlockTemplateSupport;
use PerfLocale\Translation\PostTranslationManager;
use PerfLocale\Translation\TemplateBlockRefs;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\Utf8;

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
	 * Most pieces translate_post() sends before it hands the post to a job instead (0: no limit); hand_off_above().
	 *
	 * @var int
	 */
	private int $hand_off_above = 0;

	/**
	 * Answers a job slice keeps for the next one (PieceAnswers); null outside a job slice.
	 *
	 * @var PieceAnswers|null
	 */
	private ?PieceAnswers $kept = null;

	/**
	 * Answers the current interactive request was paid for, keyed as a job keeps them; its hand-off carries them.
	 *
	 * @var PieceAnswers|null
	 */
	private ?PieceAnswers $heard = null;

	/**
	 * Whether an interactive caller set hand_off_above(): its calls and waits stay within the request (CallDeadline::room()).
	 *
	 * @var bool
	 */
	private bool $in_request = false;

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
	 * "<time>:c" until create_translation() returned), each with ":<row fingerprint>" after the service's last write to
	 * the post: a bulk run redoes a translation that still has it and an unchanged row.
	 */
	public const PENDING_META_KEY = '_perflocale_mt_pending';

	/** NotSavedException code: a redo found the translation changed, or finished, since its pending mark. */
	public const KEPT_ON_REDO = 2;

	/** Pieces one interactive request sends: each is a provider call of up to 8 s (fast fail) within a 30 s request. */
	public const INTERACTIVE_PIECES = 3;

	/** Smallest piece size a filter may set: below it nearly every element would be a request of its own. */
	private const PIECE_MIN_CHARS = 500;

	/** Peak memory of HtmlPieces::split() per byte of content: measured 53-70x for markup-heavy posts (9x tagless). */
	private const SPLIT_PEAK_FACTOR = 70;

	/**
	 * Pieces + Create and the /machine-translate routes send in the request; a post needing more goes to a job.
	 *
	 * @return int 1 to 10.
	 */
	public static function interactive_pieces(): int {
		/**
		 * Filter how many pieces of long post content + Create and the /machine-translate REST routes send
		 * in the request; a post needing more is translated in a background job. Clamped to 1-10; not a number: 3.
		 *
		 * @hook  perflocale/mt/interactive_pieces
		 * @since 1.0.8
		 *
		 * @param int $pieces Default 3, for a 30 s request limit.
		 */
		return self::clamp_pieces( apply_filters( 'perflocale/mt/interactive_pieces', self::INTERACTIVE_PIECES ) );
	}

	/**
	 * A filtered piece count, floored and clamped to 1-10; INTERACTIVE_PIECES for anything not a finite number.
	 *
	 * @param mixed $value Filter result.
	 * @return int
	 */
	private static function clamp_pieces( mixed $value ): int {
		$pieces = is_numeric( $value ) ? (float) $value : NAN;

		return is_finite( $pieces ) ? (int) max( 1, min( 10, floor( $pieces ) ) ) : self::INTERACTIVE_PIECES;
	}

	/**
	 * Make translate_post() throw PiecesHandOff, before any provider call, for content in more than $pieces pieces.
	 *
	 * @param int $pieces Most pieces sent in this request; 0 for no limit.
	 * @return void
	 */
	public function hand_off_above( int $pieces ): void {
		$this->hand_off_above = max( 0, $pieces );
		$this->in_request     = $this->in_request || $pieces > 0;
	}

	/**
	 * Whether a job slice runs this service's calls: a stop there redoes the post in the next run.
	 *
	 * @return bool
	 */
	public function in_job(): bool {
		return null !== $this->kept || null !== CallDeadline::window();
	}

	/**
	 * Keep each provider answer of the next translate_post() in $kept, and use the answers it already holds.
	 *
	 * @param PieceAnswers|null $kept Answers of the post in progress; null to stop.
	 * @return void
	 */
	public function keep_answers( ?PieceAnswers $kept ): void {
		$this->kept = $kept;
	}

	/**
	 * The time in a pending mark ("<time>" or "<time>:c", either with ":<fingerprint>"); 0 for any other value.
	 *
	 * @param mixed $value Meta value.
	 * @return int
	 */
	public static function pending_time( mixed $value ): int {
		$value = self::unsealed( $value );
		$value = str_ends_with( $value, ':c' ) ? substr( $value, 0, -2 ) : $value;

		return '' !== $value && \PerfLocale\Helper::is_ascii_digits( $value ) ? (int) $value : 0;
	}

	/**
	 * The row fingerprint a pending mark carries, or ''.
	 *
	 * @param mixed $value Meta value.
	 * @return string
	 */
	public static function pending_seal( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/:([0-9a-f]{32})$/D', $value, $m ) ? $m[1] : '';
	}

	/**
	 * A fingerprint of a posts row (every column, as stored_text() or a SELECT p.* reads it), values taken as strings.
	 *
	 * @param array<mixed> $row Row.
	 * @return string
	 */
	public static function row_fingerprint( array $row ): string {
		$out = [];

		foreach ( $row as $column => $value ) {
			$out[ (string) $column ] = is_scalar( $value ) ? (string) $value : '';
		}

		return md5( (string) wp_json_encode( $out ) );
	}

	/**
	 * A pending mark without its fingerprint.
	 *
	 * @param mixed $value Meta value.
	 * @return string
	 */
	private static function unsealed( mixed $value ): string {
		$value = is_int( $value ) ? (string) $value : ( is_string( $value ) ? $value : '' );

		return '' === self::pending_seal( $value ) ? $value : substr( $value, 0, -33 );
	}

	/**
	 * Write the pending mark "<time><stage>:<fingerprint of the stored row>" ("<time><stage>" when the row cannot be
	 * read or differs from $expected: a mark without a fingerprint is never redone, so another save is never sealed).
	 *
	 * @param int                        $post_id  Translation.
	 * @param string                     $stage    '' or ':c'.
	 * @param array<string, string>|null $expected Columns the row must hold to be sealed; null: any row.
	 * @param bool                       $keep     Leave the mark as it is when the row cannot be read.
	 * @return void
	 */
	private static function seal_pending( int $post_id, string $stage = '', ?array $expected = null, bool $keep = false ): void {
		$row = self::stored_text( $post_id, true );

		if ( null === $row && $keep ) {
			return;
		}

		$sealed = null !== $row;

		foreach ( $sealed ? (array) $expected : [] as $column => $value ) {
			$sealed = $sealed && ( $row[ $column ] ?? null ) === $value;
		}

		update_post_meta( $post_id, self::PENDING_META_KEY, time() . $stage . ( $sealed && null !== $row ? ':' . self::row_fingerprint( $row ) : '' ) );
	}

	/**
	 * A translation's pending mark as the database holds it, not as a cache saw it.
	 *
	 * @param int $post_id Translation.
	 * @return string|null '' when it has none; null when the read failed.
	 */
	public static function stored_mark( int $post_id ): ?string {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another request may have written it.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1', $wpdb->postmeta, $post_id, self::PENDING_META_KEY ) );

		return '' !== $wpdb->last_error ? null : ( is_scalar( $value ) ? (string) $value : '' );
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

		$allowed = self::mt_allowed_html();

		// wp_kses() keeps a dropped tag's text, so a <style> or <script> would show its code: empty them first.
		$html = self::empty_raw_text_elements( $html, $allowed );

		// Strips event handlers; javascript:, vbscript: and data: URLs are neutralised even where a site allows them.
		return wp_kses( $html, $allowed, self::mt_protocols() );
	}

	/**
	 * The MT allowlist (sanitize_mt_html()) keeping the source's own markup the allowlist would change, where the answer
	 * has it as text (HeldMarkup); a source without markup gives sanitize_mt_html().
	 *
	 * @param string $html   Answer with its placeholders back.
	 * @param string $source The text sent.
	 * @return string
	 */
	public static function sanitize_mt_kept( string $html, string $source ): string {
		if ( 1 !== preg_match( '/<\/?[a-zA-Z]/', $source ) ) {
			return self::sanitize_mt_html( $html );
		}

		require_once __DIR__ . '/HeldMarkup.php';

		return HeldMarkup::sanitize( $html, $source );
	}

	/**
	 * The value rule of sanitize_mt_value() keeping the source's own markup as sanitize_mt_kept() does.
	 *
	 * @param string    $value  Value with its placeholders back.
	 * @param string    $source The source value.
	 * @param bool|null $kept   Set to whether some was kept: the acting user's save rules then apply on top.
	 * @param-out bool $kept
	 * @return string
	 */
	public static function sanitize_mt_value_kept( string $value, string $source, ?bool &$kept = null ): string {
		$kept = false;

		if ( 1 !== preg_match( '/<\/?[a-zA-Z]/', $source ) ) {
			return self::sanitize_mt_value( $value );
		}

		require_once __DIR__ . '/HeldMarkup.php';

		return HeldMarkup::value( $value, $source, $kept );
	}

	/**
	 * Refuse a non-empty answer that lost an element held back whole (the provider put its marker inside other markup).
	 *
	 * @param string             $out  Sanitized answer.
	 * @param ShortcodeMask|null $mask The mask sent.
	 * @return void
	 * @throws NotSavedException When one is missing.
	 */
	private static function require_units( string $out, ?ShortcodeMask $mask ): void {
		foreach ( null === $mask || '' === trim( $out ) ? [] : array_count_values( $mask->units() ) as $unit => $count ) {
			if ( substr_count( $out, (string) $unit ) < $count ) {
				throw new NotSavedException( esc_html__( 'The machine translation was not saved because the provider placed held-back code or an embed inside other markup.', 'perflocale' ) );
			}
		}
	}

	/**
	 * The URL schemes sanitize_mt_html() keeps: the site's, without REFUSED_PROTOCOLS.
	 *
	 * @return list<string>
	 */
	public static function mt_protocols(): array {
		return array_values( array_udiff( wp_allowed_protocols(), self::REFUSED_PROTOCOLS, 'strcasecmp' ) );
	}

	/**
	 * The allowlist sanitize_mt_html() applies, after the filter and the lock.
	 *
	 * @return array<string, array<mixed>>
	 */
	public static function mt_allowed_html(): array {
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
		 * Filter the HTML allowlist applied to machine-translated content. Entries that could run script are refused
		 * (locked_allowlist()).
		 *
		 * @hook perflocale/mt/allowed_html
		 * @param array<string, array<string, bool>> $allowed Allowed tags/attributes.
		 */
		return self::locked_allowlist( apply_filters( 'perflocale/mt/allowed_html', $allowed ) );
	}

	/** Elements a machine translation never keeps, whatever the allowlist filter returns. */
	public const REFUSED_ELEMENTS = [ 'script', 'style', 'base', 'meta', 'link', 'object', 'embed', 'applet', 'frame', 'frameset', 'animate', 'animatemotion', 'animatetransform', 'set', 'handler', 'listener' ];

	/** URL schemes that run script or load an inline document. */
	public const REFUSED_PROTOCOLS = [ 'javascript', 'vbscript', 'data', 'livescript', 'mocha' ];

	/** Attributes that hold a document or a URL core's KSES does not check (wp_kses_uri_attributes() lacks xlink:href). */
	private const REFUSED_ATTRIBUTES = [ 'srcdoc', 'xlink:href' ];

	/**
	 * The filtered allowlist without what could run script: REFUSED_ELEMENTS, on* and REFUSED_ATTRIBUTES. A kses context
	 * string is read as wp_kses() reads it; any other non-array allows no tags.
	 *
	 * An element given as true (no attributes) becomes [], which kses reads the same.
	 *
	 * @param mixed $allowed The filtered allowlist.
	 * @return array<string, array<mixed>>
	 */
	private static function locked_allowlist( mixed $allowed ): array {
		$allowed = is_string( $allowed ) ? wp_kses_allowed_html( $allowed ) : $allowed;
		$out     = [];

		foreach ( is_array( $allowed ) ? $allowed : [] as $tag => $attrs ) {
			$tag   = strtolower( (string) $tag );
			$attrs = is_array( $attrs ) ? $attrs : [];

			if ( in_array( $tag, self::REFUSED_ELEMENTS, true ) ) {
				continue;
			}

			foreach ( array_keys( $attrs ) as $name ) {
				$low = strtolower( (string) $name );

				if ( str_starts_with( $low, 'on' ) || in_array( $low, self::REFUSED_ATTRIBUTES, true ) ) {
					unset( $attrs[ $name ] );
				}
			}

			$out[ $tag ] = $attrs;
		}

		return $out;
	}

	/**
	 * A provider-derived value with its placeholders back, through the MT allowlist (sanitize_mt_html()). Without a `<`
	 * no markup can form; a value the allowlist changes only by writing a lone `&` as `&amp;` stays as it is.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	public static function sanitize_mt_value( string $value ): string {
		if ( ! str_contains( $value, '<' ) ) {
			return $value;
		}

		$clean = self::sanitize_mt_html( $value );

		return (string) preg_replace( '/&(?![A-Za-z0-9#])/', '&amp;', $value ) === $clean ? $value : $clean;
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
	public static function raw_text_spans( string $html ): array {
		$tokens = null;

		return self::scan( $html, $tokens )[0];
	}

	/**
	 * Where markup_tokens() stops reading (the length when it reads to the end), without recording the tokens.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	public static function markup_end( string $html ): int {
		$tokens = null;

		return self::scan( $html, $tokens )[1];
	}

	/**
	 * The markup a browser finds outside svg, math, select and noscript, read as raw_text_spans() reads it: each tag
	 * ('open', 'close'), comment, doctype or bogus comment ('other') and raw text or special element ('whole') as
	 * [ start, end, lower-case name, kind ], and the offset where reading stopped (the length when it read to the end).
	 *
	 * @param string $html HTML.
	 * @return array{0: list<array{0: int, 1: int, 2: string, 3: string}>, 1: int}
	 */
	public static function markup_tokens( string $html ): array {
		$tokens = [];
		$stop   = self::scan( $html, $tokens )[1];

		return [ $tokens ?? [], $stop ];
	}

	/**
	 * The scan behind raw_text_spans() and markup_tokens(): the spans and the stop offset; tokens recorded when given.
	 *
	 * @param string                                                 $html   HTML.
	 * @param list<array{0: int, 1: int, 2: string, 3: string}>|null $tokens Filled when not null.
	 * @return array{0: array<int, array{0: int, 1: int, 2: int}>, 1: int}
	 */
	private static function scan( string $html, ?array &$tokens ): array {
		// One ASCII lower-case copy (same offsets, locale-free; stripos() copies the whole text per call before PHP 8.2).
		$html   = strtr( $html, self::ASCII_UPPER, self::ASCII_LOWER );
		$spans  = [];
		$at     = strpos( $html, '<' );
		$dash   = -1;
		$bang   = -1;
		$in     = null;
		$in_at  = 0;
		$halt   = strlen( $html );
		$record = null !== $tokens;

		while ( false !== $at ) {
			$next  = $html[ $at + 1 ] ?? '';
			$close = '/' === $next ? 1 : 0;
			$kind  = '';
			$name  = '';
			$first = $at;

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

				$end  = PHP_INT_MAX === min( $dash, $bang ) ? null : ( $dash < $bang ? $dash + 3 : $bang + 4 );
				$kind = 'other';
			} elseif ( 1 === strspn( $html, self::ASCII_LOWER, $at + 1 + $close, 1 ) ) {
				$from = $at + 1 + $close;
				$stop = $from + strcspn( $html, "\t\n\f\r />", $from );
				$name = substr( $html, $from, $stop - $from );
				$end  = self::tag_end( $html, $stop );
				$kind = 1 === $close ? 'close' : 'open';

				if ( null !== $in && null !== $end ) {
					$in = self::pass_special( $html, $in, $name, (bool) $close, $at, $end );

					if ( false === $in ) {
						$halt = $in_at;
						break;
					}

					// Inside the element, which is recorded whole once its closer ends it.
					$kind  = null === $in ? 'whole' : '';
					$first = $in_at;
				} elseif ( 0 === $close && null !== $end ) {
					if ( 'plaintext' === $name ) {
						$halt = $at;
						break;
					}

					if ( in_array( $name, [ 'svg', 'math', 'select', 'noscript' ], true ) ) {
						$in    = [ $name, $end, '' ];
						$in_at = $at;
						$kind  = '';
					}

					if ( in_array( $name, [ 'script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'title', 'textarea' ], true ) ) {
						$text   = $end;
						$closer = self::raw_text_closer( $html, $text, $name );
						$end    = null === $closer ? null : self::tag_end( $html, $closer + 2 + strlen( $name ) );
						$kind   = 'whole';

						if ( null !== $end && null !== $closer && ( 'script' === $name || 'style' === $name ) ) {
							$spans[ $at ] = [ $end, $text, $closer ];
						}
					}
				}
			} elseif ( '!' === $next || '?' === $next || 1 === $close ) {
				// Doctype, bogus comment, `</>`: to the first ">". Foreign content reads CDATA differently.
				if ( null !== $in && ( '' !== $in[2] || ( ( 'svg' === $in[0] || 'math' === $in[0] ) && '[cdata[' === substr( $html, $at + 2, 7 ) ) ) ) {
					$halt = $in_at;
					break;
				}

				$end  = strpos( $html, '>', $at + 2 );
				$end  = false === $end ? null : $end + 1;
				$kind = 'other';
			} else {
				$end = $at + 1;
			}

			if ( null === $end ) {
				$halt = null !== $in ? $in_at : $at;
				break;
			}

			if ( $record && '' !== $kind && null === $in ) {
				$tokens[] = [ $first, $end, $name, $kind ];
			}

			$at = strpos( $html, '<', $end );
		}

		// An svg, math, select or noscript element still open at the end: its content is not read as text.
		if ( null !== $in && $halt > $in_at ) {
			$halt = $in_at;
		}

		return [ $spans, $halt ];
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

		// Markup without a letter or digit comes back as it is, unsent.
		if ( ! BlockStructureGuard::has_text( $text, true ) ) {
			return $text;
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

		// Shortcode tags are held back from the provider and put back.
		$shortcodes = ShortcodeMask::mask( $text, $provider instanceof AbstractProvider && $provider->placeholders_as_elements() );
		// The monthly cap, reserved on exactly what is sent, as translate_batch_texts() does.
		$reserved = $this->reserve_cap( Utf8::length( $text ) );
		$was      = CallDeadline::interactive();

		CallDeadline::in_request( $was || ( $fast_fail && null === CallDeadline::window() ) );

		try {
			$translated = $provider->translate( null === $shortcodes ? $text : $shortcodes->text(), $source_lang, $target_lang, $fast_fail );
		} catch ( DeadlineReachedException $e ) {
			throw $e->paced() || ! CallDeadline::interactive() ? $e : new \RuntimeException( esc_html( self::request_too_short() ), 504, $e );
		} finally {
			CallDeadline::in_request( $was );
			AbstractProvider::release_usage( $reserved, true );
		}

		if ( null !== $shortcodes && '' !== trim( $translated ) ) {
			$translated = $shortcodes->restore( $translated );
		}

		// The same allowlist as translate_post(): Gutenberg renders block attributes as HTML.
		$translated = self::sanitize_mt_kept( $translated, $text );
		self::require_units( $translated, $shortcodes );

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
	 * @param string[] $labels      Names of the texts by position in $texts, for a failure message, such as "meta key
	 *   price" ("text k of n" for a missing one); since 1.0.7.5.
	 * @return string[] Translated strings, parallel-indexed to $texts; a blank text comes back as given.
	 *
	 * @throws \RuntimeException On provider failure or misconfiguration.
	 */
	public function translate_batch_texts( array $texts, string $source_lang, string $target_lang, string $provider_id = '', bool $fast_fail = false, string $format = 'html', array $labels = [] ): array {
		return $this->run_batch( $texts, null, $source_lang, $target_lang, $provider_id, $fast_fail, $format, $labels );
	}

	/**
	 * Batch-translate texts, each with its context: every step of translate_batch_texts() applies, contexts pass
	 * `perflocale/mt/pre_translate_text` too and the monthly cap counts them when the provider bills them. With no
	 * context, or a provider that takes none ({@see provider_supports_context()}), it is translate_batch_texts().
	 *
	 * @since 1.0.8
	 *
	 * @param array<int, string> $texts       Texts to translate (in order).
	 * @param array<int, string> $contexts    Context per text, parallel to `$texts`; '' for none.
	 * @param string             $source_lang Source language slug.
	 * @param string             $target_lang Target language slug.
	 * @param string             $provider_id Provider ID ('' = configured default).
	 * @param bool               $fast_fail   Skip provider-internal retries.
	 * @param string             $format      'html' or 'text', as for translate_batch_texts().
	 * @param string[]           $labels      Names of the texts by position, for a failure message.
	 * @return array<int, string> Translated strings, parallel-indexed to `$texts`.
	 *
	 * @throws \RuntimeException On provider failure or misconfiguration.
	 */
	public function translate_batch_texts_with_context( array $texts, array $contexts, string $source_lang, string $target_lang, string $provider_id = '', bool $fast_fail = false, string $format = 'html', array $labels = [] ): array {
		$texts    = array_values( $texts );
		$contexts = array_values( $contexts );
		$parallel = [];
		$any      = false;

		foreach ( array_keys( $texts ) as $i ) {
			$parallel[ $i ] = $contexts[ $i ] ?? '';
			$any            = $any || '' !== trim( $parallel[ $i ] );
		}

		if ( ! $any || ! $this->provider_supports_context( $this->get_provider( $provider_id ) ) ) {
			return $this->run_batch( $texts, null, $source_lang, $target_lang, $provider_id, $fast_fail, $format, $labels );
		}

		return $this->run_batch( $texts, $parallel, $source_lang, $target_lang, $provider_id, $fast_fail, $format, $labels );
	}

	/**
	 * Whether a provider receives context: it implements {@see ContextAwareProviderInterface} and
	 * `perflocale/mt/provider_supports_context` agrees.
	 *
	 * @since 1.0.8
	 *
	 * @param ProviderInterface $provider The provider.
	 * @return bool
	 */
	public function provider_supports_context( ProviderInterface $provider ): bool {
		$aware = $provider instanceof ContextAwareProviderInterface;

		/**
		 * Filters whether a provider receives context with the texts Content ReSync sends. True changes nothing for a
		 * provider without ContextAwareProviderInterface; false sends a context-aware provider texts only.
		 *
		 * @internal Not public: perflocale/mt/context can return [] for a provider.
		 * @hook perflocale/mt/provider_supports_context
		 * @since 1.0.8
		 *
		 * @param bool   $supports    Default: whether the provider implements ContextAwareProviderInterface.
		 * @param string $provider_id The provider.
		 */
		return $aware && true === (bool) apply_filters( 'perflocale/mt/provider_supports_context', $aware, $provider->get_id() );
	}

	/**
	 * Whether the context sent to a provider counts toward the monthly character limit: it receives and bills it.
	 *
	 * @since 1.0.8
	 *
	 * @param ProviderInterface $provider The provider.
	 * @return bool
	 */
	public function context_billed( ProviderInterface $provider ): bool {
		return $provider instanceof ContextAwareProviderInterface && $this->provider_supports_context( $provider ) && $provider->context_billed();
	}

	/**
	 * The batch path of translate_batch_texts() and translate_batch_texts_with_context(); with `$contexts` null it is
	 * one provider request without context.
	 *
	 * @param array<string>           $texts       Texts.
	 * @param array<int, string>|null $contexts    Context per text, or null for none.
	 * @param string                  $source_lang Source language slug.
	 * @param string                  $target_lang Target language slug.
	 * @param string                  $provider_id Provider ID.
	 * @param bool                    $fast_fail   Skip provider-internal retries.
	 * @param string                  $format      'html' or 'text'.
	 * @param string[]                $labels      Names of the texts by position, for a failure message.
	 * @return array<int, string>
	 *
	 * @throws \RuntimeException On provider failure or misconfiguration.
	 */
	private function run_batch( array $texts, ?array $contexts, string $source_lang, string $target_lang, string $provider_id, bool $fast_fail, string $format, array $labels = [] ): array {
		if ( empty( $texts ) ) {
			return [];
		}

		// Keys 0..N-1, the indexing the result keeps.
		$texts          = array_values( $texts );
		$original_count = count( $texts );

		// Only texts with a letter or digit are sent (others cost budget, and DeepL rejects empty ones); the result is expanded back.
		// Contexts follow their texts.
		$non_empty    = [];      // subset_index → text
		$position_map = [];   // subset_index → original_index
		$context_of   = [];     // Subset index → context.

		// The result starts as the input: a slot not sent comes back as it was given.
		$result = [];

		foreach ( $texts as $orig_idx => $t ) {
			$t_str    = (string) $t;
			$result[] = $t_str;
			if ( ! BlockStructureGuard::has_text( $t_str, 'html' === $format ) ) {
				continue;
			}
			$position_map[] = $orig_idx;
			$non_empty[]    = $t_str;
			$context_of[]   = null === $contexts ? '' : (string) ( $contexts[ $orig_idx ] ?? '' );
		}

		if ( empty( $non_empty ) ) {
			return $result;
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

		// Contexts pass the same filter with the same arguments, so a site's redaction sees them too.
		foreach ( $context_of as $i => $context ) {
			if ( '' === $context ) {
				continue;
			}

			/** This filter is documented in src/MachineTranslation/TranslationService.php */
			$context_of[ $i ] = Scalar::str(
				apply_filters(
					'perflocale/mt/pre_translate_text',
					$context,
					$source_lang,
					$target_lang,
					$provider->get_id()
				)
			);
		}

		// The monthly cap for every provider-bound path (term MT, bulk strings): $non_empty is exactly what is billed,
		// plus the context a provider bills.
		$provider_chars = 0;
		foreach ( $non_empty as $t ) {
			$provider_chars += Utf8::length( (string) $t );
		}

		if ( null !== $contexts && $provider instanceof ContextAwareProviderInterface && $provider->context_billed() ) {
			foreach ( $context_of as $context ) {
				$provider_chars += Utf8::length( $context );
			}
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

		$reserved = $this->reserve_cap( $provider_chars );
		$was      = CallDeadline::interactive();

		CallDeadline::in_request( $was || ( $fast_fail && null === CallDeadline::window() ) );

		try {
			$translated = null === $contexts || ! $provider instanceof ContextAwareProviderInterface
				? $provider->translate_batch( $sent, $source_lang, $target_lang, $fast_fail, $format )
				: self::translate_grouped( $provider, $sent, $context_of, $source_lang, $target_lang, $fast_fail, $format );
		} catch ( DeadlineReachedException $e ) {
			throw $e->paced() || ! CallDeadline::interactive() ? $e : new \RuntimeException( esc_html( self::request_too_short() ), 504, $e );
		} catch ( \RuntimeException $e ) {
			$label = static function ( int $i ) use ( $labels, $position_map, $original_count ): string {
				$at = $position_map[ $i ];

				/* translators: 1: position of the text, 2: number of texts */
				return (string) ( $labels[ $at ] ?? sprintf( __( 'text %1$d of %2$d', 'perflocale' ), $at + 1, $original_count ) );
			};

			throw ItemsFailedException::named( $e, $sent, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in named().
		} finally {
			CallDeadline::in_request( $was );
			AbstractProvider::release_usage( $reserved, true );
		}

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
				: self::sanitize_mt_kept( (string) $t, (string) ( $non_empty[ $i ] ?? '' ) );

			if ( 'html' === $format ) {
				self::require_units( $t, $shortcodes[ $i ] ?? null );
			}

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

		// Back to the caller's indexing.
		foreach ( $position_map as $subset_i => $orig_i ) {
			$result[ $orig_i ] = isset( $translated[ $subset_i ] ) ? (string) $translated[ $subset_i ] : '';
		}

		return $result;
	}

	/**
	 * One provider request per distinct context, in order of first appearance (a context a filter emptied passes '',
	 * read as none). Outputs come back in text order; a group reply of the wrong length returns [], which the caller's
	 * count guard refuses.
	 *
	 * @param ContextAwareProviderInterface $provider    The provider.
	 * @param array<int, string>            $texts       Filtered texts, 0..N-1.
	 * @param array<int, string>            $contexts    Filtered contexts, parallel.
	 * @param string                        $source_lang Source language slug.
	 * @param string                        $target_lang Target language slug.
	 * @param bool                          $fast_fail   Skip provider-internal retries.
	 * @param string                        $format      'html' or 'text'.
	 * @return array<int, string>
	 */
	private static function translate_grouped( ContextAwareProviderInterface $provider, array $texts, array $contexts, string $source_lang, string $target_lang, bool $fast_fail, string $format ): array {
		$groups = [];

		foreach ( $texts as $i => $text ) {
			$groups[ $contexts[ $i ] ?? '' ][] = $i;
		}

		$out = [];

		foreach ( $groups as $context => $positions ) {
			$batch = [];

			foreach ( $positions as $i ) {
				$batch[] = $texts[ $i ];
			}

			$replies = array_values( $provider->translate_batch_with_context( $batch, array_fill( 0, count( $batch ), (string) $context ), $source_lang, $target_lang, $fast_fail, $format ) );

			if ( count( $replies ) !== count( $batch ) ) {
				return [];
			}

			foreach ( $positions as $k => $i ) {
				$out[ $i ] = Scalar::str( $replies[ $k ] ?? '' );
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Translate a term's text fields in one call; a failure names the field ("term name", "term description", else its key).
	 *
	 * @since 1.0.7.5
	 * @param array<string, string> $fields      Field key => source text.
	 * @param string                $source_lang Source language slug.
	 * @param string                $target_lang Target language slug.
	 * @param string                $provider_id Provider ID ('' = configured default).
	 * @param bool                  $fast_fail   Skip provider-internal retries.
	 * @return array<string, string> Field key => translation (the source for a field not sent).
	 * @throws \RuntimeException On provider failure or misconfiguration.
	 */
	public function translate_term_fields( array $fields, string $source_lang, string $target_lang, string $provider_id = '', bool $fast_fail = false ): array {
		$out = array_map( 'strval', $fields );

		// A field without a letter keeps its source, unsent (sends_item()).
		$sent   = array_filter( $out, static fn( string $text, int|string $key ): bool => self::sends_item( $text, 'description' === $key ? 'term_description' : 'term_name', $target_lang, true ), ARRAY_FILTER_USE_BOTH );
		$keys   = array_map( 'strval', array_keys( $sent ) );
		$labels = array_map(
			static fn( string $key ): string => match ( $key ) {
				'name'        => __( 'term name', 'perflocale' ),
				'description' => __( 'term description', 'perflocale' ),
				default       => $key,
			},
			$keys
		);

		if ( [] !== $sent ) {
			foreach ( $this->translate_batch_texts( array_values( $sent ), $source_lang, $target_lang, $provider_id, $fast_fail, 'html', $labels ) as $i => $text ) {
				$out[ $keys[ $i ] ] = (string) $text;
			}
		}

		return $out;
	}

	/**
	 * Whether a title, excerpt, meta value or term field goes to the provider: it shows a letter, or it shows a digit and
	 * perflocale/mt/translate_letterless sends it. Content keeps the letter-or-digit rule.
	 *
	 * @since 1.0.8
	 * @param string      $text          Text as read for the rule (placeholders taken out by the caller).
	 * @param string      $item_type     'title', 'excerpt', 'meta', 'term_name' or 'term_description'.
	 * @param string      $language_code Target language code.
	 * @param bool        $attributes    Also the attribute values has_text() reads (HTML).
	 * @param string|null $original      The text the filter gets, when not $text.
	 * @return bool
	 */
	public static function sends_item( string $text, string $item_type, string $language_code, bool $attributes = false, ?string $original = null ): bool {
		if ( BlockStructureGuard::has_letter( $text, $attributes ) ) {
			return true;
		}

		// No letter or digit: never sent, so the filter is not asked.
		if ( ! BlockStructureGuard::has_text( $text, $attributes ) ) {
			return false;
		}

		/**
		 * Filter whether a title, excerpt, meta value or term field without a letter ("2026", "10:30") goes to machine
		 * translation, for example to localize digits. Text with no letter or digit is never sent and never reaches it.
		 *
		 * @hook  perflocale/mt/translate_letterless
		 * @since 1.0.8
		 *
		 * @param bool   $send          Default false: the item keeps its source.
		 * @param string $text          The source text.
		 * @param string $item_type     'title', 'excerpt', 'meta', 'term_name' or 'term_description'.
		 * @param string $language_code Target language code.
		 * @return bool
		 */
		return (bool) apply_filters( 'perflocale/mt/translate_letterless', false, $original ?? $text, $item_type, $language_code );
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
	 * @param bool      $redo         The caller redoes the pair for its pending mark: an existing translation is written
	 *                                only while its row is the one the mark fingerprints.
	 * @return array<string, mixed> post_id, title, content, excerpt, and warnings, kept, builder_warning, length_warning, redo_note, meta when they apply.
	 *
	 * @throws \RuntimeException On translation failure.
	 */
	public function translate_post( int $post_id, string $target_lang, string $provider_id = '', bool $fast_fail = false, bool $include_meta = false, bool &$inserted = false, bool $redo = false ): array {
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

		// The ReSync capture is called here, not from a listener, so a request that never translates registers
		// nothing; every translate_post() caller passes through before_mt(), after_mt() and failed_mt().
		$capture = \PerfLocale\Util\ResyncSwitch::on();

		if ( $capture ) {
			SyncRecorder::before_mt( $post_id );
		}

		/** @hook perflocale/machine_translation/before Fires before machine translation (also for a post then handed to a job). */
		do_action( 'perflocale/machine_translation/before', $post_id, $provider->get_id() );

		// An interactive request's calls and waits stay within it; its call times are its own.
		$interactive   = null === $this->kept && null === CallDeadline::window() && ( $this->in_request || $fast_fail || CallDeadline::interactive() );
		$was_request   = CallDeadline::in_request( $interactive );
		$translated_id = null;
		$units         = [];

		if ( $interactive ) {
			CallDeadline::times( [] );
		}

		try {
			// The translation's text before the call: a person may save it meanwhile, and it is overwritten only while unchanged.
			$manager       = new PostTranslationManager( $this->cache, $this->settings );
			$translated_id = $manager->get_translation_id( $post_id, $target_lang );
			$pending_mark  = $translated_id ? get_post_meta( (int) $translated_id, self::PENDING_META_KEY, true ) : '';
			$was_pending   = ! empty( $pending_mark );

			// A creation a stopped worker left inside its copy steps gets them before the text, unless it was edited since.
			$open      = false;
			$redo_note = $was_pending ? self::redo_stopped_creation( (int) $translated_id, $pending_mark, static fn() => $manager->complete_imported_translation( (int) $translated_id, $post_id, $target_lang ), $open ) : '';
			// A creation whose copy failed again keeps its stage until this run ends, so a stop before then redoes the copy.
			$stage = $open ? ':c' : '';

			// A redo's baseline is the row its mark was checked against, so the write guard holds that row.
			$target_before = $translated_id ? ( $redo ? self::redo_baseline( (int) $translated_id ) : self::stored_text( (int) $translated_id ) ) : null;

			// No baseline to hold the write to (a read error): failed before the provider is paid, as redo_baseline() does.
			if ( $translated_id && null === $target_before ) {
				throw new \RuntimeException( esc_html__( 'Not saved: the translation could not be read, so it was kept as it is. A later run tries it again.', 'perflocale' ) );
			}

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

			// Title and excerpt in text mode (HTML mode entity-escapes & and '), content in HTML mode. An excerpt holding
			// markup (a WooCommerce short description) goes in HTML mode on its own. A title or excerpt without a letter
			// ("2026", "10:30") is not sent and stays as it is, like a string: some providers refuse text without one.
			$plain_inputs = [];
			$plain_slots  = [];
			$slot_texts   = [];
			$excerpt_html = '';

			foreach ( [ 0, 2 ] as $slot ) {
				$value               = (string) ( $texts[ $slot ] ?? '' );
				$slot_texts[ $slot ] = $value;
				$html                = 2 === $slot && 1 === preg_match( '/<[a-z][a-z0-9-]*+[\s\/>]/i', $value );

				if ( ! self::sends_item( $value, 0 === $slot ? 'title' : 'excerpt', $target_lang, $html ) ) {
					continue;
				}

				if ( $html ) {
					$excerpt_html = $value;
				} else {
					$plain_slots[]  = $slot;
					$plain_inputs[] = $value;
				}
			}

			// Shortcode tags, script and style elements and block delimiters become placeholders the provider cannot change;
			// text kept only in block attributes travels as extra segments after the content.
			$elements    = $provider instanceof AbstractProvider && $provider->placeholders_as_elements();
			$content_src = (string) ( $texts[1] ?? '' );
			$blank       = '' === trim( $content_src );
			$shortcodes  = $blank ? null : ShortcodeMask::mask( $content_src, $elements, true );
			$content_in  = null === $shortcodes ? $content_src : $shortcodes->text();
			$content_in  = $elements ? \PerfLocale\Translation\BlockSkipFilter::placeholders_as_elements( $content_in ) : $content_in;
			$mask        = $blank ? null : BlockDelimiterMask::mask( $content_in, $elements );
			$html_in     = null === $mask ? [ $content_in ] : array_merge( [ $mask->text() ], $mask->segment_texts() );

			// Markup or placeholders without a letter or digit are not sent and come back as the source.
			if ( ! $blank && [] === array_filter( $html_in, static fn( string $t ): bool => BlockStructureGuard::has_text( $t, true ) ) ) {
				$blank      = true;
				$shortcodes = null;
				$mask       = null;
				$html_in    = [ $content_src ];
			}

			// Content over the provider's piece size goes in pieces, one call each: classic content cut between whole
			// elements, block content between whole blocks or inside a leaf block's own HTML (BlockPieces). Measured on
			// the text sent; under the size nothing below loads.
			$pieces = null;
			$sent   = null === $mask ? $content_in : $mask->text();
			$limit  = $blank ? 0 : self::piece_chars( $provider );

			if ( $limit > 0 && strlen( $sent ) > $limit ) {
				self::split_fits( $post_id, strlen( $sent ) );

				if ( null === $mask ) {
					require_once __DIR__ . '/HtmlPieces.php';
					$pieces = HtmlPieces::split( $content_in, $limit );
				} else {
					require_once __DIR__ . '/BlockPieces.php';
					$pieces = BlockPieces::nests( (string) $post->post_content ) ? BlockPieces::split( $mask, $limit, array_values( array_filter( [ $mask->any_pattern(), null === $shortcodes ? '' : $shortcodes->any_pattern() ] ) ) ) : null;
				}
			}

			$segments = null === $mask ? [] : array_values( $mask->segment_texts() );
			$units    = null === $pieces ? [ $html_in ] : array_merge( array_map( static fn( string $t ): array => [ $t ], $pieces->texts() ), self::packed( $segments, $limit ) );
			$html_in  = array_merge( ...$units );
			$parts    = null === $pieces ? 0 : $pieces->count();
			$label    = self::content_labels( $parts, count( $segments ), $pieces instanceof BlockPieces ? $pieces->atomic_over() : [] );

			// Before any provider call: an interactive caller queues a long post as a job (PiecesHandOff::queue()).
			if ( null !== $pieces && $this->hand_off_above > 0 && count( $units ) > $this->hand_off_above ) {
				self::refuse_while_key_refused( $provider );

				require_once __DIR__ . '/PiecesHandOff.php';
				throw new PiecesHandOff( $post_id, $target_lang, $include_meta, (int) $translated_id, count( $units ) );
			}

			try {
				$plain_out = [] === $plain_inputs
					? []
					: $this->send( $provider, [ $plain_inputs ], $source_lang, $target_lang, $fast_fail, 'text' );
			} catch ( \RuntimeException $e ) {
				$plain_label = static fn( int $i ): string => 2 === ( $plain_slots[ $i ] ?? 0 ) ? __( 'excerpt', 'perflocale' ) : __( 'title', 'perflocale' );

				throw ItemsFailedException::named( $e, $plain_inputs, $plain_label ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in named().
			}

			// Least trust: a post an author or last editor without unfiltered_html wrote keeps no held markup.
			require_once __DIR__ . '/../Translation/SourceTrust.php';
			$trusted     = \PerfLocale\Translation\SourceTrust::trusted( $post_id );
			$excerpt_out = '' === $excerpt_html ? null : $this->html_excerpt( $provider, $excerpt_html, $source_lang, $target_lang, $fast_fail, $elements, $trusted );

			// Each piece's answer passes its own check before it is kept, so a damaged piece stops the pieces after it.
			$check = null === $pieces ? null : static fn( int $unit, array $answers ): string => ( $unit < $parts ? $pieces->check( $unit, is_string( $answers[0] ?? null ) ? $answers[0] : '' ) : '' );

			try {
				$html_out = $blank
					? [ $content_src ]
					: $this->send( $provider, $units, $source_lang, $target_lang, $fast_fail, 'html', $check, null === $pieces ? null : $label );
			} catch ( \RuntimeException $e ) {
				$named = ItemsFailedException::named( $e, $html_in, $label );
				$note  = \RuntimeException::class === $named::class ? self::over_limit( $provider, $html_in ) : '';

				throw '' === $note ? $named : new \RuntimeException( $named->getMessage() . ' ' . esc_html( $note ), (int) $named->getCode(), $named ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in named() and here.
			}

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

			$length_warning = CutText::warning( $plain_inputs, $plain_out, $plain_slots );

			// A placeholder lost, repeated or invented fails the item; an empty answer goes to the empty-field handling.
			$html_out    = array_map( 'strval', $html_out );
			$content_out = null === $pieces ? $html_out[0] : $pieces->join( array_slice( $html_out, 0, $parts ) );

			if ( null !== $mask && '' !== trim( $content_out ) ) {
				$content_out = $mask->restore( $content_out, array_slice( $html_out, null === $pieces ? 1 : $parts ) );
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
			if ( $elements && ! $blank && '' !== trim( $content_out ) ) {
				$content_out = \PerfLocale\Translation\BlockSkipFilter::placeholders_from_elements( $content_out, $content_src );
			}

			// Content without blocks: checked on the raw answer, since kses rewrites a cut-off tag.
			if ( null === $mask && ! $blank && '' !== trim( $content_out ) ) {
				$classic = ClassicStructureGuard::mismatch( $content_src, $content_out );

				// Block markup the provider added to content without blocks (kses keeps comments).
				if ( '' === $classic && BlockStructureGuard::has_stray( $content_out ) && ! BlockStructureGuard::has_stray( $content_src ) ) {
					$classic = BlockStructureGuard::reason( __( 'the result adds block delimiters the source does not have', 'perflocale' ) );
				}

				if ( '' !== $classic ) {
					throw new NotSavedException( esc_html( $classic ) );
				}
			}

			// Sanitized here, before the post_translate filters, against a compromised provider; the "Do not translate"
			// placeholders are comments kses keeps, and the delimiters are put back as they were.
			$content_raw = $content_out;
			$content_out = self::sanitize_mt_kept( $content_out, $trusted ? $content_src : '' );

			if ( $trusted ) {
				self::require_units( $content_out, $shortcodes );
			}

			// Also for block content not sent: kses would change delimiter JSON a person wrote (`--`, a raw `&`).
			if ( ( null !== $mask || ( $blank && str_contains( $content_raw, '<!-- wp:' ) ) ) && '' !== trim( $content_out ) ) {
				$content_out = BlockDelimiterMask::keep_delimiters(
					$content_out,
					$content_raw,
					( null === $mask ? [] : $mask->trusted() ) + BlockDelimiterMask::delimiter_texts( (string) $post->post_content )
				);
			}

			// The positional [title, content, excerpt] triple the filters expect; a slot not sent keeps its text.
			$translated = [ $slot_texts[0], $content_out, $excerpt_out ?? $slot_texts[2] ];

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
			// A slot not sent keeps the source's bytes exactly unless a filter changed it (a title `</div>` is not emptied).
			$kept_title = ! in_array( 0, $plain_slots, true ) && ( $translated[0] ?? null ) === $slot_texts[0];
			$kept_exc   = null === $excerpt_out && ! in_array( 2, $plain_slots, true ) && ( $translated[2] ?? null ) === $slot_texts[2];

			$result = [
				'title'   => $kept_title ? $slot_texts[0] : \PerfLocale\Helper::sanitize_plain_text_field( $translated[0] ?? '' ),
				'content' => is_string( $translated[1] ?? null ) ? $translated[1] : '',
				'excerpt' => match ( true ) {
					$kept_exc            => $slot_texts[2],
					null === $excerpt_out => \PerfLocale\Helper::sanitize_plain_textarea_field( $translated[2] ?? '' ),
					default              => is_string( $translated[2] ?? null ) ? $translated[2] : '',
				},
			];

			if ( '' !== $redo_note ) {
				$result['warnings'][] = $redo_note;
				$result['redo_note']  = $redo_note;
			}

			// Before the kept-content warnings: a bulk job reports the last warning of a kept row.
			if ( '' !== $length_warning ) {
				$result['warnings'][]     = $length_warning;
				$result['length_warning'] = $length_warning;
			}

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

				// A fresh mark for the redo: a live call's again until it is a lease old.
				if ( $was_pending ) {
					self::seal_pending( (int) $translated_id, $stage, $target_before );
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

				$written = null;
				$guard   = self::guard_text( (int) $translated_id, $target_before, $written, $keep );

				// A save that writes the content is the machine translation's: the general save rule leaves it to
				// ProtectedBlockSave. A kept content is saved again as stored, so that rule keeps its blocks.
				$claimed = isset( $update_fields['post_content'] );

				if ( $claimed ) {
					\PerfLocale\Translation\SaveScope::claim( (int) $translated_id );
				}

				// wp_update_post() merges the cached row: a status, password or slug another request saved meanwhile is kept.
				wp_cache_delete( (int) $translated_id, 'posts' );

				// Core would rename a published translation whose slug a same-language draft holds.
				require_once __DIR__ . '/SlugPin.php';
				$unpin = SlugPin::keep( (int) $translated_id );

				try {
					$keep?->arm();

					$update_result = wp_update_post(
						// wp_slash: wp_update_post() unslashes, and backslashes in the output would be lost.
						wp_slash( $update_fields ),
						true
					);
				} finally {
					$keep?->disarm();
					$unpin();

					if ( $claimed ) {
						\PerfLocale\Translation\SaveScope::release( (int) $translated_id );
					}

					$outcome = $guard();
				}

				if ( ! is_wp_error( $update_result ) || '' === $outcome || 'unguarded' === $outcome ) {
					$keep?->settle();
				}

				if ( 'conflict' === $outcome ) {
					throw $conflict();
				}

				if ( 'refused' === $outcome ) {
					throw new NotSavedException(
						ExpectedWrite::charset_known()
							? esc_html__( 'Not saved: another plugin changed the database query, so the translation was kept as it is.', 'perflocale' )
							: esc_html__( 'Not saved: the database server did not report its connection charset, so the translation was kept as it is. Set DB_CHARSET to utf8mb4 in wp-config.php.', 'perflocale' )
					);
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

				// The redo's own save is sealed, only while the row holds what it wrote: a stop before the meta leaves it to the
				// next run. A template's content may be rewritten by content_replaced(): only its title and excerpt are checked.
				// A refused or unread save keeps the mark sealed before the write.
				$own = null === $written || is_wp_error( $update_result ) || ( null !== $keep && $keep->refused() ) ? null : $written;

				if ( null !== $own && null !== $keep ) {
					// The query filter wrote the kept bytes in place of the placeholder content the data hook saw.
					$own['post_content'] = $keep->kept() ?? '';
				}

				if ( $was_pending && null !== $own ) {
					self::seal_pending( (int) $translated_id, $stage, ! BlockTemplateSupport::is_template_type( $post->post_type ) ? $own : array_diff_key( $own, [ 'post_content' => true ] ) );
				}
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

				// Sealed with the row the insert left once its last hook ran.
				$seal = static function ( $new_id, $new_post = null, $update = false ) use ( &$marked ): void {
					if ( ! $update && $marked > 0 && is_numeric( $new_id ) && (int) $new_id === $marked ) {
						self::seal_pending( $marked, ':c' );
					}
				};

				add_action( 'wp_insert_post', $mark, 1, 3 );
				add_action( 'wp_after_insert_post', $seal, PHP_INT_MAX, 3 );

				$recovered = [];

				try {
					// A marked creation: a stopped one of this pair is recovered first and reported with this result.
					$translated_id = \PerfLocale\Translation\CreationMark::create( $manager, $post_id, $target_lang, false, \PerfLocale\Enum\SourceType::MachineTranslation, $created, $recovered );
				} finally {
					remove_action( 'wp_insert_post', $mark, 1 );
					remove_action( 'wp_after_insert_post', $seal, PHP_INT_MAX );
				}

				$notes = [];

				foreach ( $recovered as $draft => $outcome ) {
					$notes[] = \PerfLocale\Translation\CreationMark::message( (int) $draft, $post_id, $outcome );
				}

				$noted = [] !== $notes ? ' ' . esc_html( implode( ' ', $notes ) ) : '';
				\PerfLocale\Translation\CreationMark::acknowledge( $post_id, $target_lang );

				if ( $marked > 0 && ( true !== $created || $marked !== (int) $translated_id ) ) {
					delete_post_meta( $marked, self::PENDING_META_KEY );
				}

				// create_translation() returned, so its copy steps ran: the mark keeps no creation stage.
				if ( true === $created && $translated_id ) {
					self::seal_pending( (int) $translated_id );
				}

				if ( ! $translated_id ) {
					// create_translation() can return false (race, target removed, link transaction failed): characters were spent
					// and no post exists, which must not read as success.
					throw new \RuntimeException(
						esc_html__( 'Failed to create the translation post; the source-language post may have been moved or its translation group invalidated mid-translation.', 'perflocale' ) . $noted // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped above.
					);
				}

				// Machine output goes only into a translation this call inserted: one that existed may hold a person's work the
				// overwrite checks never saw, and this write skips the empty-field guard.
				if ( true !== $created ) {
					throw new \RuntimeException(
						esc_html__( 'This translation already exists, so the machine translation was not written to it.', 'perflocale' ) . $noted // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped above.
					);
				}

				// A bulk job reports the first one when it has no other warning.
				foreach ( $notes as $note ) {
					$result['warnings'][]      = $note;
					$result['creation_note'] ??= $note;
				}

				$inserted = true;
				$new_row  = self::stored_text( (int) $translated_id, true );

				// "Do not translate" blocks pass the content filters as placeholders and are put back, checked against the stored
				// source, right before the row is written.
				$keep = [] !== $protected_blocks
					? ProtectedBlockSave::prepare( (string) $result['content'], (string) $post->post_content, $post_id, (int) $translated_id )
					: null;

				\PerfLocale\Translation\SaveScope::claim( (int) $translated_id );
				wp_cache_delete( (int) $translated_id, 'posts' );

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
					if ( null !== $new_row && self::as_inserted( $new_row, $keep->sent_fields(), self::stored_text( (int) $translated_id, true ) ) ) {
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

				// The text save is sealed: a stop before the meta leaves it to the next run.
				self::seal_pending( (int) $translated_id );
			}

			if ( '' !== $builder ) {
				$result = BuilderLayout::report( $result, $builder );
			}

			$result['post_id'] = (int) $translated_id;
			// Whether this call inserted the translation: field ownership needs the fact, not a date (core moves a
			// draft's floating date to now on every update).
			$result['created'] = $inserted;

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

			// A redo finished the stopped creation: its creation mark would make an XLIFF import take it for unfinished.
			if ( $was_pending ) {
				delete_post_meta( (int) $translated_id, \PerfLocale\Translation\CreationMark::KEY );
			}

			if ( $capture ) {
				SyncRecorder::after_mt( $post_id, $result, $target_lang, $post );
			}

			/**
			 * Fires after successful machine translation.
			 *
			 * @hook perflocale/machine_translation/after
			 * @param int      $post_id     Source post ID.
			 * @param string   $provider_id Provider that translated.
			 * @param array    $result      {title, content, excerpt, post_id, created: whether this call inserted the translation}.
			 * @param string   $target_lang Target language slug.
			 * @param string   $source_lang Source language slug.
			 * @param \WP_Post $post        Source post object.
			 */
			do_action( 'perflocale/machine_translation/after', $post_id, $provider->get_id(), $result, $target_lang, $source_lang, $post );

			return $result;
		} catch ( \Throwable $e ) {
			$e = $interactive ? $this->interactive_stop( $e, $post_id, $target_lang, $include_meta, (int) $translated_id, max( 1, count( $units ) ) ) : $e;

			if ( $capture ) {
				SyncRecorder::failed_mt( $post_id );
			}

			/** @hook perflocale/machine_translation/failed Fires when machine translation fails (not for a hand-off). */
			if ( ! $e instanceof PiecesHandOff ) {
				do_action( 'perflocale/machine_translation/failed', $post_id, $provider->get_id(), $e );
			}

			throw $e;
		} finally {
			CallDeadline::in_request( $was_request );
			$this->heard = null;

			// BlockSkipFilter keeps this post's "do not translate" subtrees in a static between pre_ and post_translate; after
			// an exception or a bailed restore they would stay for the process, so the entry is cleared.
			\PerfLocale\Translation\BlockSkipFilter::discard_stash( [ $post->post_title, $post->post_content, $post->post_excerpt ] );
		}
	}

	/**
	 * Refuse a hand-off while the provider's breaker is open for a refused key: the job would fail at once.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return void
	 * @throws \RuntimeException Named, code 401, nothing queued.
	 */
	private static function refuse_while_key_refused( ProviderInterface $provider ): void {
		$status = \PerfLocale\Concurrency\Breaker::status( 'mt_' . $provider->get_id() );

		if ( 'open' !== ( $status['state'] ?? '' ) || 'auth' !== ( $status['reason'] ?? '' ) ) {
			return;
		}

		throw new \RuntimeException(
			esc_html(
				sprintf(
					/* translators: 1: provider name, 2: seconds until the provider is tried again */
					__( '%1$s refused the API key or access, so nothing was queued. Check the key under PerfLocale > Settings > Addons > Machine Translation (or Settings > Connectors for the WordPress AI client), then try again (next try in %2$d seconds).', 'perflocale' ),
					$provider->get_name(),
					is_int( $status['cooldown_remaining'] ?? null ) ? $status['cooldown_remaining'] : 0
				)
			),
			401 // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int.
		);
	}

	/**
	 * An interactive request's stop: a provider wait (Retry-After) or a call that would not fit the request becomes a
	 * hand-off to a job when the caller can queue one, else a named failure.
	 *
	 * @param \Throwable $e            What translate_post() threw.
	 * @param int        $post_id      Source post.
	 * @param string     $target_lang  Target language slug.
	 * @param bool       $include_meta Translate the registered meta fields too.
	 * @param int        $translation  The pair's translation (0: none).
	 * @param int        $units        Provider calls the content needs.
	 * @return \Throwable
	 */
	private function interactive_stop( \Throwable $e, int $post_id, string $target_lang, bool $include_meta, int $translation, int $units ): \Throwable {
		if ( ! $e instanceof DeadlineReachedException || '' !== $e->job_stop() ) {
			return $e;
		}

		$times   = CallDeadline::times();
		$slowest = [] === $times ? 0.0 : max( array_map( 'abs', $times ) );

		if ( $this->hand_off_above > 0 ) {
			require_once __DIR__ . '/PiecesHandOff.php';

			// The job starts from this request's slowest call (negative: cut) and the answers it was paid for.
			$cut = [] !== $times && in_array( -$slowest, $times, true );

			return new PiecesHandOff( $post_id, $target_lang, $include_meta, $translation, $units, $e->paced() ? 'wait' : 'slow', $e->wait(), (int) round( $cut ? -$slowest : $slowest ), $e->asked(), $this->heard );
		}

		return $e->paced() ? $e : new \RuntimeException( esc_html( self::request_too_short() ), 504, $e );
	}

	/**
	 * The failure of a call that did not fit an interactive request.
	 *
	 * @return string
	 */
	private static function request_too_short(): string {
		$seconds = (int) ( CallDeadline::REQUEST_SECONDS - CallDeadline::MARGIN_SECONDS );

		return sprintf(
			/* translators: %d: seconds */
			__( 'The provider did not answer within the %d seconds this request has, so nothing was saved. Translate the post with the bulk action (a background job) or with WP-CLI.', 'perflocale' ),
			$seconds
		);
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
	 * Complete a creation a stopped worker left: a "<time>:c" mark older than a lease (younger: a live call's) and the
	 * stored row equal to the one it fingerprints; any change, no fingerprint or a failed read keeps the post as it is.
	 *
	 * @param int      $translated_id Translation post.
	 * @param mixed    $mark          Its pending mark.
	 * @param callable $complete      The copy steps.
	 * @param bool     $open          Set to true when a copy step failed twice: the mark keeps its creation stage.
	 * @return string Why the copy steps were not run again, or failed again, for a stopped creation, or ''.
	 */
	private static function redo_stopped_creation( int $translated_id, mixed $mark, callable $complete, bool &$open = false ): string {
		$time = str_ends_with( self::unsealed( $mark ), ':c' ) ? self::pending_time( $mark ) : 0;

		// The age is checked before the row is read.
		if ( $time <= 0 || time() - $time < \PerfLocale\Background\JobResume::LEASE ) {
			return '';
		}

		$row  = self::stored_text( $translated_id, true );
		$seal = self::pending_seal( $mark );

		if ( null !== $row && '' !== $seal && hash_equals( $seal, self::row_fingerprint( $row ) ) ) {
			// One retry; the second failure is reported, and the copy warning stays on the translation.
			$failed = self::complete_creation( $translated_id, $complete, $row );
			$failed = null === $failed ? null : self::complete_creation( $translated_id, $complete, $row );
			$open   = null !== $failed;

			return $open ? sprintf(
				/* translators: 1: ID of the translation post. 2: comma-separated items, for example: custom fields (_price, gallery), featured image. */
				__( 'An earlier run stopped while creating the translation (post %1$d), and these items still did not copy from the original: %2$s. Fill them in on the translation.', 'perflocale' ),
				$translated_id,
				\PerfLocale\Translation\CopyFailureWatch::items( $failed )
			) : '';
		}

		return sprintf(
			/* translators: %d: ID of the translation post. */
			__( 'The translation (post %d) was edited after an earlier run stopped while creating it, so the original\'s custom fields, image and terms were not copied to it again. Add them in its editor if it needs them.', 'perflocale' ),
			$translated_id
		);
	}

	/**
	 * The text a redo may overwrite: the stored row while its pending mark fingerprints it.
	 *
	 * @param int $translated_id Translation post.
	 * @return array<string, string> As stored_text().
	 * @throws NotSavedException KEPT_ON_REDO when the mark is gone or the row changed since: the translation is kept.
	 * @throws \RuntimeException When the row cannot be read: the pair fails and keeps its mark for the next run.
	 */
	private static function redo_baseline( int $translated_id ): array {
		$row  = self::stored_text( $translated_id, true );
		$mark = null === $row ? null : self::stored_mark( $translated_id );

		if ( null === $row || null === $mark ) {
			throw new \RuntimeException( esc_html__( 'Not saved: the translation could not be read, so it was kept as it is. A later run tries it again.', 'perflocale' ) );
		}

		$seal = self::pending_seal( $mark );

		if ( '' === $seal || ! hash_equals( $seal, self::row_fingerprint( $row ) ) ) {
			throw new NotSavedException(
				esc_html__( 'Not saved: the translation changed after an earlier run stopped while writing it, so it was kept as it is.', 'perflocale' ),
				self::KEPT_ON_REDO // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant.
			);
		}

		$text = [];

		// stored_text()'s column order: the guard compares the arrays strictly.
		foreach ( [ 'post_title', 'post_content', 'post_excerpt' ] as $column ) {
			$text[ $column ] = $row[ $column ] ?? '';
		}

		return $text;
	}

	/**
	 * Run a stopped creation's copy steps again; then drop the creation stage from its pending mark (kept when a step failed). A key the translation
	 * already holds gets the original's further rows only once every row it holds was matched in order (the stop came
	 * inside that key); a held row the original does not offer first is a person's edit, and the key is kept.
	 * `translation/created` listeners run again.
	 *
	 * @param int                   $translated_id Translation post.
	 * @param callable              $complete      The copy steps (PostTranslationManager::complete_imported_translation()).
	 * @param array<string, string> $checked       The row the mark was checked against (whole).
	 * @phpstan-impure
	 * @return array<int, mixed>|null The failed steps (the mark keeps its creation stage), or null.
	 */
	private static function complete_creation( int $translated_id, callable $complete, array $checked ): ?array {
		$held = [];

		// Without a key get_post_meta() answers the stored strings, as maybe_serialize() writes them.
		foreach ( (array) get_post_meta( $translated_id ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				$held[ (string) $key ][] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		$kept = [];

		// The copy steps offer a key's rows in the original's order, which the stopped creation copied from its start.
		$skip = static function ( $check, $object_id, $meta_key, $meta_value = null ) use ( $translated_id, &$held, &$kept ) {
			if ( ! is_numeric( $object_id ) || (int) $object_id !== $translated_id || ! is_string( $meta_key ) || ! isset( $held[ $meta_key ] ) ) {
				return $check;
			}

			if ( isset( $kept[ $meta_key ] ) ) {
				return false;
			}

			if ( [] === $held[ $meta_key ] ) {
				return $check;
			}

			$stored = maybe_serialize( $meta_value );

			// A held row the original does not offer next is a person's edit: the key gets no further row.
			if ( ( is_scalar( $stored ) ? (string) $stored : '' ) === reset( $held[ $meta_key ] ) ) {
				array_shift( $held[ $meta_key ] );
			} else {
				$kept[ $meta_key ] = true;
			}

			return false;
		};

		add_filter( 'add_post_metadata', $skip, 1, 4 );

		try {
			$failed = \PerfLocale\Translation\CopyFailureWatch::run( $translated_id, $complete );
		} finally {
			remove_filter( 'add_post_metadata', $skip, 1 );
		}

		// Sealed only on the checked text: an edit during the copy steps is never sealed. A template's content may be
		// rewritten by its created listener, so only its title and excerpt are checked.
		$text = [];

		foreach ( BlockTemplateSupport::is_template_type( $checked['post_type'] ?? '' ) ? [ 'post_title', 'post_excerpt' ] : [ 'post_title', 'post_content', 'post_excerpt' ] as $column ) {
			$text[ $column ] = $checked[ $column ] ?? '';
		}

		self::seal_pending( $translated_id, null !== $failed ? ':c' : '', $text, true );

		return $failed;
	}

	/**
	 * Whether the stored row is the inserted one, alone or with exactly the refused save's own title, excerpt and dates
	 * (its content kept as inserted); a value written by anything else keeps the post.
	 *
	 * @param array<string, string>      $new_row Row as inserted (stored_text(), whole row).
	 * @param array<string, mixed>|null  $sent    ProtectedBlockSave::sent_fields().
	 * @param array<string, string>|null $stored  Row as stored now.
	 * @return bool
	 */
	private static function as_inserted( array $new_row, ?array $sent, ?array $stored ): bool {
		$own = $new_row;

		// Not every sent column: WordPress also sends the ones it inherited from the row, which another save may have changed.
		foreach ( [ 'post_title', 'post_excerpt', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ] as $column ) {
			if ( array_key_exists( $column, $own ) && is_scalar( $sent[ $column ] ?? null ) ) {
				$own[ $column ] = (string) $sent[ $column ];
			}
		}

		return null !== $stored && ( $stored === $new_row || ( null !== $sent && $stored === $own ) );
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
	 * Hold a post's text to $expected during wp_update_post(): re-read before the posts UPDATE (a change stops it before any
	 * after-save hook), and the UPDATE only matches the row while it holds $expected. The bound is added last, after
	 * ProtectedBlockSave and SourceBoundWrite matched the plain tail. Docs: docs-ai/11-code-rationale.md, "ExpectedWrite".
	 *
	 * @param int                        $post_id  Post being saved.
	 * @param array<string, string>|null $expected stored_text() before the save.
	 * @param array<string, string>|null $written  Set to the title, content and excerpt the UPDATE was sent with.
	 * @param ProtectedBlockSave|null    $keep     The save's kept blocks, whose bytes replace its token in the content.
	 * @return \Closure(): string Removes the guard; 'conflict' (kept from the row), 'refused' (not sent), 'unguarded' (sent
	 *                          unbound after the re-read: ExpectedWrite::unbound_charset() or converts()) or ''.
	 */
	private static function guard_text( int $post_id, ?array $expected, ?array &$written = null, ?ProtectedBlockSave $keep = null ): \Closure {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$open      = null !== ExpectedWrite::unbound_charset() || ExpectedWrite::converts( $wpdb->posts, [ 'post_title', 'post_content', 'post_excerpt' ] );
		$level     = ExpectedWrite::level();
		$hook      = ExpectedWrite::level( 'pre_post_update' );
		$head      = 'UPDATE `' . $wpdb->posts . '` SET ';
		$tails     = [ ' WHERE `ID` = ' . $post_id, " WHERE `ID` = '" . $post_id . "'" ];
		$condition = null === $expected ? '' : implode( ' AND ', array_map( static fn( string $column ): string => ExpectedWrite::holds( $column, $expected[ $column ] ?? '' ), [ 'post_title', 'post_content', 'post_excerpt' ] ) );
		$outcome   = '';
		/** @var string $state idle, armed, checked, sent, held or done; the closures share it by reference. */
		$state = 'idle';
		/** @var array<string, string>|null $written */
		$written = null;

		// Whether the row holds $expected now, by the same condition.
		$holds = static function () use ( $post_id, $condition ): bool {
			/**
			 * WordPress database.
			 *
			 * @var \wpdb $wpdb
			 */
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The stored row; $condition is built from md5() hex only.
			return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) . ' AND ' . $condition );
		};
		// What the save would write, with its kept blocks' bytes in place of their token.
		$own = static function () use ( $keep, &$written ): ?array {
			$kept = $keep?->kept();

			return null === $written || null === $kept ? $written : array_merge( $written, [ 'post_content' => $kept ] );
		};
		// After the bound UPDATE ran: 0 rows (none matched, or MySQL counted none changed) is a conflict unless the save
		// would change nothing. Not inside log_query_custom_data: wpdb sets rows_affected after it.
		$settle = static function () use ( $expected, $condition, $holds, $own, $keep, &$state, &$outcome, &$written ): void {
			/**
			 * WordPress database.
			 *
			 * @var \wpdb $wpdb
			 */
			global $wpdb;

			if ( 'sent' !== $state || ! str_contains( (string) $wpdb->last_query, $condition ) || in_array( 'log_query_custom_data', (array) ( $GLOBALS['wp_current_filter'] ?? [] ), true ) ) {
				return;
			}

			$state = 'done';

			if ( '' !== $wpdb->last_error || (int) $wpdb->rows_affected > 0 ) {
				return;
			}

			// A kept-block save the database kept as stored (its CASE took ELSE) wrote the stored content.
			$sent = $own();
			$same = $sent === $expected || ( null !== $keep && null !== $sent && null !== $expected && array_merge( $sent, [ 'post_content' => $expected['post_content'] ?? '' ] ) === $expected );

			if ( ! $same || ! $holds() ) {
				$state   = 'held';
				$outcome = 'conflict';
				$written = null;
			}
		};
		$check  = static function ( $query ) use ( $post_id, $expected, $open, $level, $head, $tails, $settle, &$state, &$outcome ) {
			$settle();

			if ( ! is_string( $query ) || '' === $query || ! in_array( $state, [ 'armed', 'held' ], true ) || ! ExpectedWrite::at( $level ) || ! ExpectedWrite::writes( $query ) ) {
				return $query;
			}

			// After a conflict, the rest of the save (terms, slug) is not written over the person's row either.
			if ( 'held' === $state ) {
				return '';
			}

			$state = 'done';

			if ( null === $expected || ( ! $open && null === ExpectedWrite::bind( $query, $head, $tails, '1' ) ) ) {
				$outcome = 'refused';
				ExpectedWrite::refused( sprintf( 'the machine translation of post %d', $post_id ) );

				return '';
			}

			if ( $open ) {
				$outcome = 'unguarded';

				return $query;
			}

			$state = 'checked';

			return $query;
		};
		$bind   = static function ( $query ) use ( $post_id, $level, $head, $tails, $condition, $settle, &$state, &$outcome ) {
			$settle();

			if ( 'checked' !== $state || ! is_string( $query ) || '' === $query || ! ExpectedWrite::at( $level ) || ! ExpectedWrite::writes( $query ) ) {
				return $query;
			}

			$bound = ExpectedWrite::bind( $query, $head, $tails, $condition );
			$state = null === $bound ? 'done' : 'sent';

			if ( null === $bound ) {
				$outcome = 'refused';
				ExpectedWrite::refused( sprintf( 'the machine translation of post %d', $post_id ) );
			}

			return $bound ?? '';
		};
		$arm    = static function ( $id, $data = null ) use ( $post_id, $expected, $hook, $bind, &$state, &$outcome, &$written ): void {
			if ( 'idle' !== $state || ! is_numeric( $id ) || (int) $id !== $post_id || ! ExpectedWrite::at( $hook ) ) {
				return;
			}

			$state = 'armed';

			// Re-read here, not in the query filter: a query there turns wpdb's invalid-text check back on for the UPDATE.
			if ( null !== $expected && self::stored_text( $post_id ) !== $expected ) {
				$state   = 'held';
				$outcome = 'conflict';

				return;
			}

			// pre_post_update's data is the unslashed row the UPDATE writes.
			if ( is_array( $data ) && is_scalar( $data['post_title'] ?? null ) && is_scalar( $data['post_content'] ?? null ) && is_scalar( $data['post_excerpt'] ?? null ) ) {
				$written = [
					'post_title'   => (string) $data['post_title'],
					'post_content' => (string) $data['post_content'],
					'post_excerpt' => (string) $data['post_excerpt'],
				];
			}

			add_filter( 'query', $bind, PHP_INT_MAX, 1 );
		};

		add_action( 'pre_post_update', $arm, PHP_INT_MAX, 2 );
		add_filter( 'query', $check, PHP_INT_MAX, 1 );

		return static function () use ( $post_id, $arm, $check, $bind, $settle, $holds, $own, $expected, &$state, &$outcome, &$written ): string {
			remove_action( 'pre_post_update', $arm, PHP_INT_MAX );
			remove_filter( 'query', $check, PHP_INT_MAX );
			remove_filter( 'query', $bind, PHP_INT_MAX );
			$settle();

			// Sent but not seen run (a later filter changed it): unchanged row = not written, the save's text = written.
			if ( 'sent' === $state ) {
				$outcome = $holds() ? ( $own() === $expected ? '' : 'refused' ) : ( null !== $own() && self::stored_text( $post_id ) === $own() ? '' : 'conflict' );
				$written = '' === $outcome ? $written : null;
			}

			return $outcome;
		};
	}

	/**
	 * The provider's answers to $units (lists of texts), in order: one call per unit in a job slice ($this->kept: each
	 * answer kept, a kept one never sent again) or for a provider without its own lists, else one call. Each unit's
	 * answers pass $check before they are kept. Before a call the latency rule runs (before_call()); each call's time
	 * is recorded (CallDeadline::took()), a call the slice's deadline cut as such.
	 *
	 * @param ProviderInterface                     $provider  Provider.
	 * @param list<list<string>>                    $units     Units.
	 * @param string                                $source    Source language slug.
	 * @param string                                $target    Target language slug.
	 * @param bool                                  $fast_fail Fail on the first provider error.
	 * @param string                                $format    'html' or 'text'.
	 * @param (callable(int, list<string>): string)|null $check Unit index and answers => '' or why they are refused.
	 * @param (callable(int): string)|null          $name      Name of the text at a flat position, for a stop note.
	 * @return list<string>
	 * @throws \RuntimeException On a provider failure (positions in the flat list), a refused answer or a stop.
	 */
	private function send( ProviderInterface $provider, array $units, string $source, string $target, bool $fast_fail, string $format, ?callable $check = null, ?callable $name = null ): array {
		$kept     = $this->kept;
		$per_unit = null !== $kept || ( count( $units ) > 1 && $provider instanceof AbstractProvider && ! $provider->batches_natively() );
		$calls    = $per_unit ? array_map( static fn( array $u ): array => [ $u ], $units ) : [ $units ];
		$out      = [];
		$at       = 0;
		$index    = 0;

		foreach ( $calls as $call ) {
			$texts  = array_merge( ...$call );
			$key    = null === $kept ? '' : $kept->key( $provider->get_id(), $source, $target, $format, $texts );
			$answer = null === $kept ? null : $kept->get( $key, count( $texts ) );

			if ( null === $answer ) {
				// Kept answers that are never used again mean the texts change between runs (a filter adding random markup):
				// stopped before the same parts are paid for in every run.
				if ( null !== $kept && 'html' === $format && $kept->count() > 2 * ( count( $units ) + 2 ) ) {
					throw new DeadlineReachedException( 0, false, esc_html__( 'The text sent for this post changes in every background run (a filter or plugin adds changing markup), so its kept parts cannot be used and the job was stopped. Run background jobs with WP-CLI (system cron, no time limit) or remove the changing markup; then retry the job.', 'perflocale' ) );
				}

				if ( null !== $kept ) {
					$kept->in_flight = match ( true ) {
						'text' === $format => __( 'the title and excerpt', 'perflocale' ),
						null === $name     => __( 'the content', 'perflocale' ),
						default            => $name( $at ),
					};
				}

				self::before_call();

				// The monthly cap, reserved per call on what it sends (a kept answer costs nothing).
				$reserved = $this->reserve_cap( self::chars_of( $texts ) );
				$left     = CallDeadline::left();
				$began    = microtime( true );

				try {
					$answer = array_map( 'strval', array_values( $provider->translate_batch( $texts, $source, $target, $fast_fail, $format ) ) );
				} catch ( DeadlineReachedException $e ) {
					$elapsed = microtime( true ) - $began;

					// A call our clamp cut (a stop before any call takes no time): a cut time, which a slice judges at once and an
					// interactive request hands to the job it goes to.
					if ( ! $e->paced() && '' === $e->job_stop() && $elapsed >= 0.1 && ( CallDeadline::interactive() || ( null !== $left && $elapsed >= max( $left - 1.0, $left * 0.9 ) ) ) ) {
						CallDeadline::took( $elapsed, true );
						self::stop_if_too_slow();
					}

					throw $e;
				} catch ( \RuntimeException $e ) {
					$failure = $e instanceof ItemsFailedException ? $e->failure : $e;
					$elapsed = microtime( true ) - $began;

					// Cut by the slice's deadline: a stop the next slice retries, or the job stops when the provider needs more
					// than a whole window.
					if ( null !== $left && \RuntimeException::class === $failure::class && $elapsed >= max( $left - 1.0, $left * 0.9 ) ) {
						CallDeadline::took( $elapsed, true );
						self::stop_if_too_slow();

						throw new DeadlineReachedException();
					}

					throw self::at( $e, $at, count( $texts ), count( $units ) > 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped where it was thrown.
				} finally {
					AbstractProvider::release_usage( $reserved, true );
				}

				CallDeadline::took( microtime( true ) - $began );

				if ( count( $answer ) === count( $texts ) ) {
					$from = 0;

					// Markup the provider escaped as \uXXXX text is put back or refused before any check or keep.
					if ( 'html' === $format ) {
						foreach ( $answer as $n => $text ) {
							$answer[ $n ] = self::unescaped( $texts[ $n ], $text, null === $name ? __( 'the content', 'perflocale' ) : $name( $at + $n ) );
						}
					}

					foreach ( $call as $k => $unit ) {
						$why = null === $check ? '' : $check( $per_unit ? $index : $k, array_slice( $answer, $from, count( $unit ) ) );

						if ( '' !== $why ) {
							throw new NotSavedException( esc_html( $why ) );
						}

						$from += count( $unit );
					}

					$kept?->put( $key, $answer );

					if ( null === $kept && $this->hand_off_above > 0 ) {
						$this->heard( $provider, $source, $target, $format, $call, $answer );
					}
				}
			}

			array_push( $out, ...$answer );
			$at += count( $texts );
			++$index;
		}

		if ( null !== $kept ) {
			$kept->in_flight = '';
		}

		return $out;
	}

	/**
	 * Keep a checked call's answers per unit, as a job slice sends each unit alone (its PieceAnswers keys).
	 *
	 * @param ProviderInterface  $provider Provider.
	 * @param string             $source   Source language slug.
	 * @param string             $target   Target language slug.
	 * @param string             $format   'html' or 'text'.
	 * @param list<list<string>> $call     The call's units.
	 * @param list<string>       $answer   Its answers, in order.
	 * @return void
	 */
	private function heard( ProviderInterface $provider, string $source, string $target, string $format, array $call, array $answer ): void {
		if ( null === $this->heard ) {
			require_once __DIR__ . '/PieceAnswers.php';
			$this->heard = new PieceAnswers( [], $this->settings, static function (): void {} );
		}

		$from = 0;

		foreach ( $call as $unit ) {
			$this->heard->put( $this->heard->key( $provider->get_id(), $source, $target, $format, $unit ), array_slice( $answer, $from, count( $unit ) ) );
			$from += count( $unit );
		}
	}

	/**
	 * An answer with the \uXXXX escapes the source does not hold decoded, when each is a markup or quote character and
	 * the decoded answer adds no tag the source lacks; a sequence the source holds is never decoded. One linear scan.
	 *
	 * @param string $source Text sent.
	 * @param string $answer Provider answer.
	 * @param string $item   The text's name, such as "content part 2 of 6".
	 * @return string
	 * @throws NotSavedException When an escape cannot be put back.
	 */
	private static function unescaped( string $source, string $answer, string $item ): string {
		if ( false === stripos( $answer, '\\u' ) ) {
			return $answer;
		}

		$own = preg_match_all( '/\\\\u[0-9a-f]{4}/i', $source, $m ) ? array_count_values( array_map( 'strtolower', $m[0] ) ) : [];
		$bad = '';
		$out = preg_replace_callback(
			'/\\\\u([0-9a-f]{4})/i',
			static function ( array $e ) use ( &$own, &$bad ): string {
				$key = strtolower( $e[0] );

				if ( ( $own[ $key ] ?? 0 ) > 0 ) {
					--$own[ $key ];

					return $e[0];
				}

				$code = (int) hexdec( $e[1] );
				$char = $code < 0x80 ? chr( $code ) : ''; // Only ASCII can match below; not mb_chr(), core polyfills it only from WP 7.1.

				if ( ! in_array( $char, [ '<', '>', '&', '"', "'" ], true ) ) {
					$bad = '' === $bad ? $e[0] : $bad;

					return $e[0];
				}

				return $char;
			},
			$answer
		);

		if ( null !== $out && '' === $bad && ( $out === $answer || self::tags_within( $source, $out ) ) ) {
			return $out;
		}

		throw new NotSavedException(
			esc_html(
				sprintf(
					/* translators: 1: the escape, such as \u003c, 2: the text, such as "content part 2 of 6" */
					__( 'The machine translation was not saved because the provider returned escaped text (%1$s) in %2$s that cannot be put back as the source has it.', 'perflocale' ),
					'' === $bad ? '\\u' : $bad,
					$item
				)
			)
		);
	}

	/**
	 * Whether every tag name occurs in $html at most as often as in $source.
	 *
	 * @param string $source Source.
	 * @param string $html   HTML.
	 * @return bool
	 */
	private static function tags_within( string $source, string $html ): bool {
		$count = static fn( string $t ): array => preg_match_all( '/<\/?([a-z][a-z0-9-]*+)/i', $t, $m ) ? array_count_values( array_map( 'strtolower', $m[0] ) ) : [];
		$had   = $count( $source );

		foreach ( $count( $html ) as $tag => $n ) {
			if ( $n > ( $had[ $tag ] ?? 0 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A failure of the call that sent the texts from flat position $at on, with positions in the flat list.
	 *
	 * @param \RuntimeException $e     Failure.
	 * @param int               $at    Flat position of the call's first text.
	 * @param int               $count Texts in the call.
	 * @param bool              $named Whether to name the call's texts (several units).
	 * @return \RuntimeException
	 */
	private static function at( \RuntimeException $e, int $at, int $count, bool $named ): \RuntimeException {
		if ( $e instanceof ItemsFailedException ) {
			return new ItemsFailedException( $e->failure, $at + $e->first, $at + $e->last );
		}

		return $named ? ItemsFailedException::of( $e, $at, $at + $count - 1 ) : $e;
	}

	/**
	 * The latency rule before a provider call: in a slice the job stops when the provider needs more than a whole window,
	 * and the slice stops when the expected time does not fit what is left; in an interactive request a call that would
	 * not fit the room is not started (the caller hands the post off).
	 *
	 * @return void
	 * @throws DeadlineReachedException When the call is not started.
	 */
	private static function before_call(): void {
		$expected = CallDeadline::expected();

		if ( null !== CallDeadline::window() ) {
			self::stop_if_too_slow();
		}

		$room = CallDeadline::room();

		if ( null !== $expected && null !== $room && $expected > $room ) {
			throw new DeadlineReachedException();
		}
	}

	/**
	 * Stop the job (checkpoint kept) when the recent call times say the provider needs more than one slice's window.
	 *
	 * @return void
	 * @throws DeadlineReachedException With the job stop reason.
	 */
	private static function stop_if_too_slow(): void {
		$window = CallDeadline::window();

		if ( null === $window || ! CallDeadline::too_slow( $window, CallDeadline::left() ) ) {
			return;
		}

		$seconds = (int) round( CallDeadline::expected() ?? $window );

		throw new DeadlineReachedException(
			0,
			false,
			esc_html(
				sprintf(
					/* translators: 1: seconds the provider's answers took, 2: seconds one background run has */
					__( 'The provider\'s recent answers took %1$d seconds or more, longer than one background run has (%2$d seconds), so the job was stopped. Allow longer runs with the perflocale/jobs/bulk_translate/slice_seconds filter (if your host allows longer requests), send smaller parts with the perflocale/mt/piece_chars filter, or run background jobs with WP-CLI (system cron); then retry the job.', 'perflocale' ),
					$seconds,
					(int) round( $window )
				)
			)
		);
	}

	/**
	 * Texts packed greedily into units of at most $limit characters, never cut (one over the size goes alone).
	 *
	 * @param list<string> $texts Texts.
	 * @param int          $limit Characters.
	 * @return list<list<string>>
	 */
	private static function packed( array $texts, int $limit ): array {
		$units = [];
		$unit  = [];
		$size  = 0;

		foreach ( $texts as $text ) {
			$chars = Utf8::length( $text );

			if ( [] !== $unit && $size + $chars > $limit ) {
				$units[] = $unit;
				$unit    = [];
				$size    = 0;
			}

			$unit[] = $text;
			$size  += $chars;
		}

		return [] === $unit ? $units : [ ...$units, $unit ];
	}

	/**
	 * Names of the content texts by flat position: the content and its block texts, or its parts (a part over the size
	 * with its block name) and then the block texts.
	 *
	 * @param int                                              $parts    Pieces (0: not split).
	 * @param int                                              $segments Block texts.
	 * @param list<array{name: string, chars: int, part: int}> $over     Parts over the size that could not be cut (part 1-based).
	 * @return callable(int): string
	 */
	private static function content_labels( int $parts, int $segments, array $over ): callable {
		$blocks = [];

		foreach ( $over as $part ) {
			$blocks[ $part['part'] - 1 ] = $part['name'];
		}

		return static function ( int $i ) use ( $parts, $segments, $blocks ): string {
			if ( $i < $parts ) {
				return isset( $blocks[ $i ] )
					/* translators: 1: position of the part, 2: number of parts, 3: block name, such as core/table */
					? sprintf( __( 'content part %1$d of %2$d, block %3$s', 'perflocale' ), $i + 1, $parts, $blocks[ $i ] )
					/* translators: 1: position of the part, 2: number of parts */
					: sprintf( __( 'content part %1$d of %2$d', 'perflocale' ), $i + 1, $parts );
			}

			if ( 0 === $parts && 0 === $i ) {
				return __( 'content', 'perflocale' );
			}

			/* translators: 1: position of the block text, 2: number of block texts */
			return sprintf( __( 'block text %1$d of %2$d', 'perflocale' ), $i - max( 1, $parts ) + 1, $segments );
		};
	}

	/**
	 * An excerpt holding markup (a WooCommerce short description) translated in HTML mode: shortcodes held back, the
	 * HTML structure kept, sanitized as content is.
	 *
	 * @param ProviderInterface $provider  Provider.
	 * @param string            $excerpt   Source excerpt.
	 * @param string            $source    Source language slug.
	 * @param string            $target    Target language slug.
	 * @param bool              $fast_fail Fail on the first provider error.
	 * @param bool              $elements  Placeholders as elements.
	 * @param bool              $trusted   Whether the post's held markup may be kept (SourceTrust).
	 * @return string
	 * @throws \RuntimeException On a provider failure (named "excerpt") or a refused answer.
	 */
	private function html_excerpt( ProviderInterface $provider, string $excerpt, string $source, string $target, bool $fast_fail, bool $elements, bool $trusted = true ): string {
		$held = ShortcodeMask::mask( $excerpt, $elements, true );
		$sent = null === $held ? $excerpt : $held->text();

		try {
			$answer = (string) ( $this->send( $provider, [ [ $sent ] ], $source, $target, $fast_fail, 'html', null, static fn(): string => __( 'the excerpt', 'perflocale' ) )[0] ?? '' );
		} catch ( \RuntimeException $e ) {
			throw ItemsFailedException::named( $e, [ $sent ], static fn(): string => __( 'excerpt', 'perflocale' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in named().
		}

		if ( '' === trim( $answer ) ) {
			return '';
		}

		$answer = null === $held ? $answer : $held->restore( $answer );
		$wrong  = ClassicStructureGuard::mismatch( $excerpt, $answer );

		if ( '' !== $wrong ) {
			/* translators: %s: why the translation was not saved */
			throw new NotSavedException( esc_html( sprintf( __( 'Excerpt: %s', 'perflocale' ), $wrong ) ) );
		}

		$answer = self::sanitize_mt_kept( $answer, $trusted ? $excerpt : '' );

		if ( $trusted ) {
			self::require_units( $answer, $held );
		}

		return $answer;
	}

	/**
	 * Raise PHP's memory limit as core does for image work, then refuse a post whose split would pass it (a fatal error
	 * otherwise); a limit of -1 has no guard.
	 *
	 * @param int $post_id Source post id.
	 * @param int $bytes   Content length in bytes.
	 * @return void
	 * @throws \RuntimeException When the split does not fit.
	 */
	private static function split_fits( int $post_id, int $bytes ): void {
		/**
		 * Filter the memory limit PHP is raised to before machine translation cuts a long post into parts (core's
		 * wp_raise_memory_limit() context filter). Default: WP_MAX_MEMORY_LIMIT.
		 *
		 * @hook  perflocale_mt_memory_limit
		 * @since 1.0.8
		 *
		 * @param int|string $filtered_limit Limit such as '512M', or -1 for none.
		 */
		wp_raise_memory_limit( 'perflocale_mt' );

		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$need  = $bytes * self::SPLIT_PEAK_FACTOR;

		if ( $limit <= 0 || memory_get_usage( true ) + $need <= $limit ) {
			return;
		}

		throw new \RuntimeException(
			esc_html(
				sprintf(
					/* translators: 1: post id, 2: content size, 3: memory needed, 4: PHP memory limit */
					__( 'Post %1$d was not translated: cutting its content (%2$s) into parts needs about %3$s of memory, more than the PHP memory limit of %4$s allows. Raise the limit with the perflocale_mt_memory_limit filter or WP_MAX_MEMORY_LIMIT, or run the translation with WP-CLI.', 'perflocale' ),
					$post_id,
					size_format( $bytes, 1 ),
					size_format( $need ),
					size_format( $limit )
				)
			)
		);
	}

	/**
	 * Characters per piece of long post content for a built-in provider; 0 sends it whole.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return int
	 */
	private static function piece_chars( ProviderInterface $provider ): int {
		$chars = match ( true ) {
			// Batches: the batch size; one text per request: the site's maximum, else whole.
			$provider instanceof ExternalAgencyProvider => $provider->batches_natively() ? $provider->batch_max_chars() : $provider->max_chars(),
			// Under the API's 50,000 per text, as its batch lists.
			$provider instanceof MicrosoftProvider      => 45000,
			// Measured per AI provider: answers within 7 s at p90.
			$provider instanceof WpAiClientProvider     => $provider->default_piece_chars(),
			default                                     => 0,
		};

		/**
		 * Filter the most characters in one piece of long post content sent to a provider, measured on the text sent;
		 * longer content goes in pieces cut between whole elements or blocks. Below 1: sent whole; else at least 500.
		 *
		 * @hook  perflocale/mt/piece_chars
		 * @since 1.0.8
		 *
		 * @param int    $chars       Default per provider: External Agency with batches on its batch size, else its
		 *                            "Maximum characters per request" setting (0 when empty);
		 *                            Microsoft 45000; WordPress AI client 2500 with OpenAI, else 1000; others 0.
		 * @param string $provider_id Provider ID.
		 */
		return self::piece_size( apply_filters( 'perflocale/mt/piece_chars', $chars, $provider->get_id() ), $chars );
	}

	/**
	 * A filtered piece size: below 1 is 0 (whole), else at least PIECE_MIN_CHARS; not a finite number: $default.
	 *
	 * @param mixed $value   Filter result.
	 * @param int   $default The provider's default.
	 * @return int
	 */
	private static function piece_size( mixed $value, int $default ): int {
		$chars = is_numeric( $value ) ? (float) $value : NAN;

		if ( ! is_finite( $chars ) ) {
			return max( 0, $default );
		}

		if ( $chars < 1 ) {
			return 0;
		}

		return $chars >= 9.0E18 ? PHP_INT_MAX : (int) max( self::PIECE_MIN_CHARS, floor( $chars ) );
	}

	/**
	 * The sentence for texts over a provider's request limit (Microsoft 50,000 characters per text, DeepL 128 KiB), or ''.
	 *
	 * @param ProviderInterface  $provider Provider.
	 * @param array<int, string> $sent     Texts of the failed call.
	 * @return string
	 */
	private static function over_limit( ProviderInterface $provider, array $sent ): string {
		$over = match ( true ) {
			$provider instanceof MicrosoftProvider => [] !== $sent && max( array_map( [ Utf8::class, 'length' ], $sent ) ) > 50000,
			$provider instanceof DeepLProvider     => array_sum( array_map( 'strlen', $sent ) ) > 131072,
			default                                => false,
		};

		/* translators: %s: provider name */
		return $over ? sprintf( __( 'The text is longer than %s accepts in one request.', 'perflocale' ), $provider->get_name() ) : '';
	}

	/**
	 * Reserve characters under the monthly cap before a provider call, so parallel runs cannot pass it together.
	 *
	 * @param int $chars Characters about to be sent.
	 * @return int The characters reserved (0 when the cap is off); give them to AbstractProvider::release_usage().
	 * @throws \RuntimeException When the cap would be passed.
	 */
	private function reserve_cap( int $chars ): int {
		$limit = (int) $this->settings->get( 'mt_monthly_char_limit', 500000 );

		if ( 0 === $limit || $chars <= 0 ) {
			AbstractProvider::open_cap( $limit );

			return 0;
		}

		if ( ! AbstractProvider::reserve_usage( $chars, $limit ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %1$s: Characters required, %2$s: Monthly limit */
						__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
						number_format_i18n( $chars ),
						number_format_i18n( $limit )
					)
				)
			);
		}

		AbstractProvider::open_cap( $limit );

		return $chars;
	}

	/**
	 * Characters of a list of texts.
	 *
	 * @param array<int|string, mixed> $texts Texts.
	 * @return int
	 */
	private static function chars_of( array $texts ): int {
		$chars = 0;

		foreach ( $texts as $text ) {
			$chars += Utf8::length( Scalar::str( $text ) );
		}

		return $chars;
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
		'resync'         => true,
		'resync_fill'    => true,
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
		 * (false), bulk (true), cli_ids (true), cli_all incl. --async (false), rest (true), ability (true), editor (true),
		 * resync and resync_fill (true: Content ReSync runs a person started; @since 1.0.8).
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
	 * Whether an MT path also translates the registered meta; the keys stay gated by mt_meta_seo and mt_meta_custom_fields.
	 *
	 * @param bool   $meta    The path's own answer.
	 * @param string $path    publish, rest, abilities, create, bulk or cli.
	 * @param int    $post_id Source post.
	 * @param string $lang    Target language slug.
	 * @return bool
	 */
	public static function include_meta( bool $meta, string $path, int $post_id, string $lang ): bool {
		/**
		 * Whether machine translation on a path also translates the registered meta (SEO and custom field keys, each
		 * still gated by its setting). Default: false on publish (unattended), true on rest, abilities and create; the
		 * user's choice on bulk and cli.
		 *
		 * @hook  perflocale/mt/include_meta
		 * @since 1.0.8
		 *
		 * @param bool   $include Whether to translate the meta.
		 * @param string $path    publish, rest, abilities, create, bulk or cli.
		 * @param int    $post_id Source post ID.
		 * @param string $lang    Target language slug.
		 */
		return (bool) apply_filters( 'perflocale/mt/include_meta', $meta, $path, $post_id, $lang );
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

		$estimated = Utf8::length( $post->post_title ) + Utf8::length( $post->post_content ) + Utf8::length( $post->post_excerpt );

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
