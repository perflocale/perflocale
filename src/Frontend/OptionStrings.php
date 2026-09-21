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
 * Translates `blogname` and `blogdescription` per language.
 *
 * These are the two pieces of front-end text a multilingual site asks about most
 * and the only ones WordPress core stores as OPTIONS rather than as posts or
 * gettext calls, so neither the content nor the string pipeline reached them.
 *
 * WHERE THE TRANSLATIONS LIVE
 * Ordinary rows in the strings table, written through
 * {@see StringRepository::register_setting_string()} — the same seam the
 * WooCommerce addon uses for attribute labels
 * (addons/woocommerce/PerfLocaleWooCommerce.php:1220). That buys the Strings admin
 * screen, machine translation, PO/XLIFF import-export and both serving modes
 * (generated .l10n.php files and lazy database reads) without a line of new UI or
 * a new table.
 *
 * ⚠️ The domain MUST NOT begin with an underscore. TranslationFileGenerator skips
 * leading-underscore domains (the `_pfl_dyn` convention), so `_pfl_options` would
 * work in database mode and return null forever in files mode.
 *
 * WHY `option_blogname` AND NOT `bloginfo`
 * `get_bloginfo()` only applies the `bloginfo` filter when `$filter === 'display'`,
 * and `render_block_core_site_title()` calls it with the default `'raw'`
 * (wp-includes/blocks/site-title.php:18; site-tagline.php does the same). The
 * apparently safer output-only hook would therefore miss the exact blocks that
 * block themes use to render the title. `option_blogname` is the only complete
 * seam — which is why the guards below carry the whole weight of this feature.
 *
 * THE HAZARD THIS CLASS EXISTS TO CONTAIN
 * If a translated value is read anywhere WordPress then writes it back, the
 * ORIGINAL English string is destroyed, silently and permanently. `is_admin()`
 * closes exactly one of the five ways that can happen; see register_hooks() and
 * {@see self::veto_translated_write()}.
 */
final class OptionStrings {

	/**
	 * Text domain for option strings.
	 *
	 * Deliberately not underscore-prefixed — see the class docblock.
	 */
	public const DOMAIN = 'perflocale-options';

	/**
	 * Options this class translates, mapped to the label shown on the Strings
	 * screen. The option name doubles as the string context, so a context can
	 * never collide with another option's.
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
	 * Translations resolved ONCE when the filters are attached, keyed by option
	 * name, held in core's escaped storage form (see attach_read_filters()).
	 * Resolving at attach time rather than memoising per read is the cheapest
	 * possible read path: the filter closes over a plain string and does no
	 * lookup at all.
	 *
	 * @var array<string, string>
	 */
	private array $resolved = [];

	/**
	 * Each resolved translation after sanitize_option() — the bytes
	 * update_option() would hand the write-side veto — keyed by option name.
	 * Usually identical to $resolved, which is already escaped, but
	 * sanitize_option() can do more than escape (a utf8mb3 options column
	 * encodes emoji; a sanitize_option_* filter may rewrite the value). Filled
	 * lazily by {@see self::sanitized_translation()} and cleared alongside
	 * $resolved.
	 *
	 * @var array<string, string>
	 */
	private array $sanitized = [];

	/**
	 * Whether this request ever passed the read-side guards.
	 *
	 * Set once register_hooks() has cleared admin / CLI / XML-RPC / cron, and
	 * cleared again by the REST detach. A language-override window may only
	 * re-resolve while this is true — otherwise an override would reattach read
	 * filters in exactly the contexts the guards exist to keep them out of.
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
		// WRITE SIDE — always on, in every context. This is the net that makes
		// the catastrophic outcome structurally impossible rather than merely
		// unlikely, so it must not sit behind any of the read-side guards.
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			add_filter( 'pre_update_option_' . $option, [ $this, 'veto_translated_write' ], 5, 3 );
		}

		// Re-register the source strings whenever the operator edits them, and
		// after a string scan. NEVER on a read path: register_setting_string()
		// takes a Lock and does SELECT-then-INSERT with a stale-string migration.
		foreach ( array_keys( self::OPTIONS ) as $option ) {
			add_action( 'update_option_' . $option, [ $this, 'register_sources' ] );
		}

		add_action( 'perflocale/strings/after_scan', [ $this, 'register_sources' ] );

		// Show "Site Title" on the Strings screen rather than `blogname`. The whole
		// point of this feature is that an operator went looking for their site
		// title and could not find it; an identifier meant for code is barely
		// better than nothing. Registered in every context because the Strings
		// screen is an admin screen, and the read-side guards below return early
		// there.
		add_filter( 'perflocale/strings/context_label', [ self::class, 'context_label' ], 10, 2 );

		// On upgrade, so the strings are on the Strings screen the moment someone
		// updates — without this they only appear after a scan or a settings
		// save, and an operator looking for their site title finds nothing. That
		// gap is what produced the support thread this feature answers.
		add_action( 'perflocale/updated', [ self::class, 'register_source_strings' ] );

		// ⚠️ Protect the domain from the stale-string GC. These two strings are
		// registered by hand and NEVER rediscovered by the scanner, so their
		// `last_seen_at` moves only when register_source_strings() runs. Today
		// the `after_scan` hook above happens to keep them fresh, which closes
		// the window by construction — but that is a coupling between two
		// unrelated hooks, not a guarantee, and the failure mode is a translated
		// site title deleted 90 days later with its translations, silently, by
		// cron. Declaring the domain protected removes the coupling outright.
		// Registered BEFORE the read-side guards below because the GC runs on
		// cron, where those guards return early.
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

		// READ SIDE — everything below decides whether the option_* filters are
		// ever attached at all.
		//
		// Each guard closes a distinct way the translated value could be written
		// back over the original:
		//
		//  1. is_admin()  — Settings > General renders the field and options.php
		//                   saves the POST verbatim.
		//  2. WP_CLI      — `wp option get blogname` then `wp option update`.
		//  3. XMLRPC      — class-wp-xmlrpc-server.php wp.getOptions / wp.setOptions.
		//  4. wp_doing_cron() — update_option() reads $old_value through this very
		//                   filter (wp-includes/option.php:887), so a translated
		//                   read corrupts the `$value === $old_value` no-op check.
		//  5. REST        — handled separately below, because it CANNOT be
		//                   detected here.
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		// ⚠️ `defined( 'REST_REQUEST' )` is USELESS here and testing it would be
		// dead code: the constant is defined by rest_api_loaded()
		// (wp-includes/rest-api.php:478) long after plugins have registered their
		// hooks. REST matters because register_setting() maps blogname to the
		// REST `title` field and the Site Editor's site-title control GETs it and
		// PUTs it straight back — with is_admin() false.
		//
		// So detach instead of refusing to attach. rest_api_loaded() defines the
		// constant, then rest_get_server() fires `rest_api_init`, then
		// serve_request() dispatches — so unhooking at priority 0 removes the
		// filters before any controller can read through them, at zero per-read
		// cost on ordinary front-end requests.
		add_action( 'rest_api_init', [ $this, 'detach_read_filters' ], 0 );

		// Attach once the language is known. Priority 20 leaves room for anything
		// that needs to run against the raw value first.
		add_action( 'perflocale/language/detected', [ $this, 'attach_read_filters' ], 20 );

		// ⚠️ And again whenever a rendering window imposes a different language.
		// `override_current_language()` (LanguageRouter.php:2373) is the supported
		// way to render part of a request in another language — WooCommerce order
		// emails are the live example. Subscribing only to `language/detected`
		// left the title frozen in the language the REQUEST arrived in, so a
		// German customer's confirmation email carried the English site title
		// while the router reported German.
		//
		// $attached gates this: after the REST detach, or in a context whose
		// guards refused the read filters, an override must NOT resurrect them.
		$this->attached = true;

		add_action( 'perflocale/language/overridden', [ $this, 'relanguage' ], 20 );
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

		// Drop the previous window's answers first — including the memoised
		// sanitized forms, which belong to the old language's translation and
		// would otherwise let the write-side veto match the wrong string.
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

		// ⚠️ Do NOT cache a "no language yet" answer. An early get_option()
		// before the language is known would otherwise poison the whole request.
		if ( '' === (string) $current ) {
			return;
		}

		foreach ( array_keys( self::OPTIONS ) as $option ) {
			$raw = $this->raw_option( $option );

			if ( '' === $raw ) {
				continue;
			}

			$translated = $this->lookup( $raw, $option );

			// ⚠️ Serve the translation in core's STORAGE form: sanitize_option()
			// esc_html()s both options (wp-includes/formatting.php), and core
			// treats the stored value as already escaped. Escape once here, when
			// resolved, so every translation and both serving modes match that
			// form. Not in filter_option(), which runs on every read; not
			// sanitize_option(), which can query the options column charset on a
			// front-end request.
			//
			// esc_html() does not double-encode, so a translation already in the
			// stored form is unchanged and renders exactly as the same title typed
			// into Settings > General would. It runs BEFORE the comparison below:
			// a translation that differs from the source only by escaping is no
			// translation, and invalid UTF-8 (escaped to '') serves the source.
			if ( null !== $translated ) {
				$translated = esc_html( $translated );
			}

			// Only attach a filter for an option that actually has a translation.
			// A site that has translated neither pays nothing.
			if ( null !== $translated && '' !== $translated && $translated !== $raw ) {
				$this->resolved[ $option ] = $translated;
				add_filter( 'option_' . $option, [ $this, 'filter_option' ], 10, 2 );
			}
		}

		if ( [] === $this->resolved ) {
			return;
		}

		// Multisite: get_blog_details() (ms-blogs.php:253) and
		// WP_Site::get_details() (class-wp-site.php:340) both read blogname and
		// then wp_cache_set() the result into `blog-details` / `site-details`,
		// which load.php registers as GLOBAL cache groups. A translated title
		// stored there persists network-wide, including in Network Admin.
		//
		// WP_Site caches at :346 BEFORE the site_details filter at :358, so the
		// cached object is already poisoned by the time any filter sees it. We
		// cannot stop the write; we can make sure no CALLER ever receives it.
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

		// Also closes the door on relanguage(): once REST has detached, no
		// override window may reattach.
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
		// ms_is_switched() alone is not enough: ms-blogs.php:248 only switches
		// when the target blog differs, so get_blog_details() on the CURRENT blog
		// never trips it. It still guards the genuine cross-blog case, where this
		// blog's translation must not leak into another blog's details.
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

		// ⚠️ This callback fires for EVERY blog's details, not only ours. Writing
		// this blog's raw title onto another blog's object relabels that blog
		// with this one's name — `get_site( $other )->blogname` comes back as
		// the caller's title.
		//
		// Only OUR blog can have been poisoned in the first place: filter_option()
		// bails when ms_is_switched(), and get_blog_details() for another blog
		// switches. So restoring anything else is both wrong and unnecessary.
		$blog_id = isset( $details->blog_id ) ? (int) $details->blog_id : 0;

		if ( 0 !== $blog_id ) {
			if ( $blog_id !== get_current_blog_id() ) {
				return $details;
			}
		} elseif ( is_multisite() && ms_is_switched() ) {
			// No blog_id to check against: fall back to the same signal
			// filter_option() uses, rather than guessing.
			//
			// ⚠️ is_multisite() first — ms_is_switched() lives in ms-blogs.php and
			// does NOT EXIST on a single-site install, so calling it unguarded is
			// a fatal. On single site there is only one blog, so falling through
			// to restore is also the correct answer.
			return $details;
		}

		$details->blogname = $this->raw_option( 'blogname' );

		return $details;
	}

	/**
	 * Refuse a write that is really a translated value coming back.
	 *
	 * The last line of defence. Every read guard is a negative allowlist, and
	 * negative allowlists leak; this makes "the operator saved the German title
	 * over the English one" impossible even if one of them is later broken.
	 *
	 * ⚠️ `$old_value` is NOT trustworthy here — wp-includes/option.php:887 obtains
	 * it with `get_option()`, which passes through the read filter. The raw value
	 * is re-read with the filter detached instead.
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

		// ⚠️ COMPARE AT WORDPRESS'S SANITIZATION BOUNDARY, NOT BEFORE IT.
		// update_option() runs sanitize_option() before it applies this filter,
		// so $value has ALREADY been through esc_html(). A translation compared
		// in any other form misses every title containing an apostrophe,
		// ampersand or angle bracket — "Alex's Bakery" arrives as
		// "Alex&#039;s Bakery" — and the guard silently lets the round-trip
		// through, destroying the operator's real title with no backup and no
		// error.
		//
		// $resolved is already held in that escaped form, which covers the
		// ordinary round-trip. The sanitized form is checked as well because
		// sanitize_option() can change more than the escaping (see $sanitized),
		// and a miss here is unrecoverable.
		if ( $value === $this->resolved[ $option ] || $value === $this->sanitized_translation( $option ) ) {
			return $this->raw_option( $option );
		}

		return $value;
	}

	/**
	 * The resolved translation as WordPress would store it.
	 *
	 * Memoised per option: sanitize_option() applies filters and can query the
	 * options column charset, and a write path should not pay for it twice.
	 * Computed lazily rather than at attach time so the ordinary front-end read
	 * path — which never writes — pays nothing at all.
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
	 * @return void
	 */
	public function register_sources(): void {
		self::register_source_strings();
	}

	/**
	 * Register both options as translatable strings.
	 *
	 * Static so activation can call it before any service exists. Idempotent:
	 * register_setting_string() no-ops when the text is unchanged, and migrates
	 * existing translations when it is not.
	 *
	 * ⚠️ Must never run on a read path — it takes a Lock and does
	 * SELECT-then-INSERT with a stale-string migration
	 * (StringRepository.php:853-945).
	 *
	 * @return void
	 */
	public static function register_source_strings(): void {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		// Constructed directly rather than fetched from the container: there is
		// no `string_repo` service, and this mirrors how the WooCommerce addon
		// registers its own setting strings
		// (addons/woocommerce/PerfLocaleWooCommerce.php:1218).
		$repo = new StringRepository( $plugin->get( 'cache' ) );

		foreach ( array_keys( self::OPTIONS ) as $option ) {
			// Read raw. On activation no read filter can be attached yet, and on
			// the update path the guards have already refused to attach one — but
			// reading through get_option() unconditionally would be a latent
			// source-corruption bug the day either of those changes.
			$raw = (string) get_option( $option, '' );

			if ( '' === $raw ) {
				continue;
			}

			// ⚠️ Register the STORED form, escaping and all. sanitize_option()
			// runs esc_html() on both of these (wp-includes/formatting.php), and
			// 14 core call sites decode on the way out. Registering a decoded
			// form would never match at lookup time, and "helpfully" decoding a
			// translation produces a double-encoding mismatch that only surfaces
			// in emails.
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
	 * Look the translation up in whichever string service is serving.
	 *
	 * Both are tried because the two serving modes (generated .l10n.php files and
	 * lazy database reads) expose different services. Each has already preloaded
	 * the whole per-language map on `perflocale/language/detected`, so this is an
	 * array lookup and never a query.
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
	 * Labels for the Strings screen, so an operator sees "Site Title" rather
	 * than a bare option name.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return self::OPTIONS;
	}

	/**
	 * Supply the friendly label for one of our own contexts.
	 *
	 * Scoped to this domain: another domain's `blogname` context — an addon's,
	 * say — must keep its own label.
	 *
	 * ⚠️ The labels are written out as LITERALS here rather than translated from
	 * the OPTIONS map. `__( $variable )` cannot be read by make-pot, so the
	 * strings would be missing from the POT file and untranslatable in every
	 * language — the failure being silent, and in a feature whose entire purpose
	 * is translation. A switch costs two lines and keeps the extractor working.
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
