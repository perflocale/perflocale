<?php
/**
 * Per-language site title and tagline.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Plugin;
use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates `blogname` and `blogdescription` per language through the strings table.
 *
 * Option filters, not `bloginfo` (the site-title block reads raw); a translated value written back destroys the source.
 * See docs-ai/11-code-rationale.md, "OptionStrings: option filters and write-back".
 */
final class OptionStrings {

	/**
	 * Text domain for option strings.
	 *
	 * No leading underscore: the file generator skips such domains, so files mode would serve nothing.
	 */
	public const DOMAIN = 'perflocale-options';

	/**
	 * Translated options and their Strings screen labels; the option name is the string context.
	 *
	 * @var array<string, string>
	 */
	private const OPTIONS = [
		'blogname'        => 'Site Title',
		'blogdescription' => 'Tagline',
	];

	/**
	 * Router, for the current and default language.
	 */
	private LanguageRouter $router;

	/**
	 * Translations resolved once at attach time, by option, in core's escaped storage form.
	 *
	 * @var array<string, string>
	 */
	private array $resolved = [];

	/**
	 * Each resolved translation after sanitize_option(), the bytes the write veto sees.
	 *
	 * Can differ from $resolved: a utf8mb3 column or a sanitize_option_* filter changes more than escaping.
	 *
	 * @var array<string, string>
	 */
	private array $sanitized = [];

	/**
	 * Whether this request passed the read-side guards and was not detached for REST.
	 *
	 * A language override may re-resolve only while true, or it would reattach guarded filters.
	 */
	private bool $attached = false;

	/**
	 * @param LanguageRouter $router Router.
	 */
	public function __construct( LanguageRouter $router ) {
		$this->router = $router;
	}

	/**
	 * Wire the hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Write side: in every context, never behind the read-side guards.
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			add_filter( 'pre_update_option_' . $option, [ $this, 'veto_translated_write' ], 5, 3 );
		}

		// Re-register sources on edit and after a scan, never on a read path (it locks and writes).
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			add_action( 'update_option_' . $option, [ $this, 'register_sources' ] );
		}

		add_action( 'perflocale/strings/after_scan', [ $this, 'register_sources' ] );

		// Strings screen label; before the guards, which return early in admin.
		add_filter( 'perflocale/strings/context_label', [ self::class, 'context_label' ], 10, 2 );

		// On upgrade, so the strings are listed before any scan or settings save.
		add_action( 'perflocale/updated', [ self::class, 'register_source_strings' ] );

		// The scanner never rediscovers these strings: keep the stale-string GC off them.
		// Before the guards, because the GC runs on cron.
		add_filter(
			'perflocale/strings/protected_domains',
			static function ( $domains ) {
				$domains = (array) $domains;

				if ( ! in_array( self::DOMAIN, $domains, true ) ) {
					$domains[] = self::DOMAIN;
				}

				return $domains;
			}
		);

		// Read side: each guard closes a context that can write a read value back (admin, cron, CLI, XML-RPC).
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		// REST_REQUEST is not defined yet here; the Site Editor GETs and PUTs the title, so detach before dispatch.
		add_action( 'rest_api_init', [ $this, 'detach_read_filters' ], 0 );

		// Priority 20: earlier listeners still see the raw value.
		add_action( 'perflocale/language/detected', [ $this, 'attach_read_filters' ], 20 );

		// Re-resolve when a window imposes another language (e.g. an order email); $attached gates it.
		$this->attached = true;

		add_action( 'perflocale/language/overridden', [ $this, 'relanguage' ], 20 );
		add_action( 'perflocale/language/restored', [ $this, 'relanguage' ], 20 );
	}

	/**
	 * Re-resolve for a language imposed mid-request.
	 *
	 * @return void
	 */
	public function relanguage(): void {
		if ( ! $this->attached ) {
			return;
		}

		// Drop the old language's answers, sanitized forms included, or the veto matches the wrong string.
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			remove_filter( 'option_' . $option, [ $this, 'filter_option' ], 10 );
		}

		$this->resolved  = [];
		$this->sanitized = [];

		$this->attach_read_filters();
	}

	/**
	 * Resolve both options for the current language and attach the read filters.
	 *
	 * @return void
	 */
	public function attach_read_filters(): void {
		$default = $this->router->get_default_language();
		$current = $this->router->get_current_slug();

		// A string is never translated into its own language.
		if ( $default && $current === $default->slug ) {
			return;
		}

		// Never cache a "no language yet" answer: it would hold for the whole request.
		if ( '' === (string) $current ) {
			return;
		}

		foreach ( array_keys( self::OPTIONS ) as $option ) {
			$raw = $this->raw_option( $option );

			if ( '' === $raw ) {
				continue;
			}

			$translated = $this->lookup( $raw, $option );

			// Core's escaped storage form, once here and before the comparison: not per read, not sanitize_option().
			// See docs-ai/11-code-rationale.md, "OptionStrings: storage form".
			if ( null !== $translated ) {
				$translated = esc_html( $translated );
			}

			// Only an option with a translation gets a filter.
			if ( null !== $translated && '' !== $translated && $translated !== $raw ) {
				$this->resolved[ $option ] = $translated;
				add_filter( 'option_' . $option, [ $this, 'filter_option' ], 10, 2 );
			}
		}

		if ( [] === $this->resolved ) {
			return;
		}

		// Site details are cached network-wide before their filter runs: restore the raw title for callers.
		if ( is_multisite() ) {
			add_filter( 'site_details', [ $this, 'restore_raw_site_details' ] );
			add_filter( 'blog_details', [ $this, 'restore_raw_site_details' ] );
		}
	}

	/**
	 * Remove the read filters before REST dispatch.
	 *
	 * @return void
	 */
	public function detach_read_filters(): void {
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			remove_filter( 'option_' . $option, [ $this, 'filter_option' ], 10 );
		}

		// After the REST detach no override may reattach.
		$this->attached = false;

		$this->resolved  = [];
		$this->sanitized = [];
	}

	/**
	 * Serve the translated value.
	 *
	 * @param mixed  $value  Stored value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function filter_option( $value, $option = '' ) {
		// Not for another blog's details; same-blog details are handled by restore_raw_site_details().
		if ( is_multisite() && ms_is_switched() ) {
			return $value;
		}

		return $this->resolved[ $option ] ?? $value;
	}

	/**
	 * Put the raw title back on a site-details object.
	 *
	 * @param object $details Site or blog details.
	 * @return object
	 */
	public function restore_raw_site_details( $details ) {
		if ( ! is_object( $details ) || ! isset( $details->blogname ) ) {
			return $details;
		}

		// Runs for every blog's details; only this blog's can hold a translation, and others must keep their title.
		$blog_id = isset( $details->blog_id ) ? (int) $details->blog_id : 0;

		if ( 0 !== $blog_id ) {
			if ( $blog_id !== get_current_blog_id() ) {
				return $details;
			}
		} elseif ( is_multisite() && ms_is_switched() ) {
			// No blog_id: same signal as filter_option(). ms_is_switched() does not exist on single site.
			return $details;
		}

		$details->blogname = $this->raw_option( 'blogname' );

		return $details;
	}

	/**
	 * Refuse a write that is really a translated value coming back.
	 *
	 * $old_value came through the read filter, so the raw value is re-read instead.
	 *
	 * @param mixed  $value     Incoming value.
	 * @param mixed  $old_value Previous value, per core (may be translated).
	 * @param string $option    Option name.
	 * @return mixed
	 */
	public function veto_translated_write( $value, $old_value = null, $option = '' ) {
		unset( $old_value );

		if ( ! is_string( $value ) || '' === $value || ! isset( self::OPTIONS[ $option ] ) ) {
			return $value;
		}

		if ( ! isset( $this->resolved[ $option ] ) ) {
			return $value;
		}

		// $value is already sanitized (escaped): compare in that form, or titles with ' or & slip through.
		if ( $value === $this->resolved[ $option ] || $value === $this->sanitized_translation( $option ) ) {
			return $this->raw_option( $option );
		}

		return $value;
	}

	/**
	 * The resolved translation as WordPress would store it, computed lazily on a write.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	private function sanitized_translation( string $option ): string {
		if ( isset( $this->sanitized[ $option ] ) ) {
			return $this->sanitized[ $option ];
		}

		$this->sanitized[ $option ] = (string) sanitize_option( $option, $this->resolved[ $option ] ?? '' );

		return $this->sanitized[ $option ];
	}

	/**
	 * Register the current source text so it appears on the Strings screen.
	 *
	 * A front-end save can run with the read filters attached: detach them, or the translation becomes the source.
	 *
	 * @return void
	 */
	public function register_sources(): void {
		$detached = [];

		foreach ( array_keys( self::OPTIONS ) as $option ) {
			if ( false !== has_filter( 'option_' . $option, [ $this, 'filter_option' ] ) ) {
				remove_filter( 'option_' . $option, [ $this, 'filter_option' ], 10 );
				$detached[] = $option;
			}
		}

		try {
			self::register_source_strings();
		} finally {
			foreach ( $detached as $option ) {
				add_filter( 'option_' . $option, [ $this, 'filter_option' ], 10, 2 );
			}
		}
	}

	/**
	 * Register both options as translatable strings; static for activation, idempotent.
	 *
	 * Never on a read path: it takes a lock and writes.
	 *
	 * @return void
	 */
	public static function register_source_strings(): void {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		// There is no `string_repo` service.
		$repo = new StringRepository( $plugin->get( 'cache' ) );

		foreach ( array_keys( self::OPTIONS ) as $option ) {
			// Must read raw: a caller after language detection detaches the read filters first.
			$raw = (string) get_option( $option, '' );

			if ( '' === $raw ) {
				continue;
			}

			// The stored, escaped form: a decoded source would never match at lookup time.
			$repo->register_setting_string( $raw, self::DOMAIN, $option );
		}
	}

	/**
	 * The stored value, with our own filter temporarily detached.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	private function raw_option( string $option ): string {
		$attached = has_filter( 'option_' . $option, [ $this, 'filter_option' ] );

		if ( false !== $attached ) {
			remove_filter( 'option_' . $option, [ $this, 'filter_option' ], 10 );
		}

		$value = (string) get_option( $option, '' );

		if ( false !== $attached ) {
			add_filter( 'option_' . $option, [ $this, 'filter_option' ], 10, 2 );
		}

		return $value;
	}

	/**
	 * Look the translation up in whichever string service is serving (files or database mode).
	 *
	 * Both preload their map on language detection, so this never queries.
	 *
	 * @param string $text   Source text.
	 * @param string $option Option name, used as the string context.
	 * @return string|null
	 */
	private function lookup( string $text, string $option ): ?string {
		$plugin = Plugin::get_instance();

		foreach ( [ 'string_translation', 'translation_file_loader' ] as $service_id ) {
			if ( ! $plugin->has( $service_id ) ) {
				continue;
			}

			$service = $plugin->get( $service_id );

			if ( ! method_exists( $service, 'get_translation' ) ) {
				continue;
			}

			$translated = $service->get_translation( $text, self::DOMAIN, $option );

			if ( null !== $translated && '' !== $translated ) {
				return $translated;
			}
		}

		return null;
	}

	/**
	 * Labels for the Strings screen, keyed by option name.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return self::OPTIONS;
	}

	/**
	 * Supply the friendly label for one of this domain's contexts.
	 *
	 * Literal strings, not __( $variable ): make-pot cannot extract a variable.
	 *
	 * @param string $label  Context label so far.
	 * @param string $domain The string's text domain.
	 * @return string
	 */
	public static function context_label( $label, $domain = '' ): string {
		if ( self::DOMAIN !== $domain ) {
			return (string) $label;
		}

		switch ( (string) $label ) {
			case 'blogname':
				return __( 'Site Title', 'perflocale' );

			case 'blogdescription':
				return __( 'Tagline', 'perflocale' );
		}

		return (string) $label;
	}
}
