<?php
/**
 * Database fallback for a request whose generated translation file is damaged.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Plugin;
use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only when a generated file throws or returns no array, so a page view never compiles it.
 */
final class FileLoadFallback {

	/**
	 * Whether this request already tried to build the database service.
	 *
	 * @var bool
	 */
	private static bool $started = false;

	/**
	 * Database mode's service for the rest of the request, or null when the cache service is missing.
	 *
	 * @var StringTranslation|null
	 */
	private static ?StringTranslation $db = null;

	/**
	 * Empty the loader and mark it loaded (no second include of the file this load); build database mode's string
	 * service once per request and hand it to the loader. Writes no file: the next regeneration rewrites the damaged one.
	 *
	 * @param TranslationFileLoader $loader The loader that read the file.
	 * @param string                $file   The damaged file.
	 * @param mixed                 $result What the include threw or returned.
	 * @return null Always; the native type is mixed because PHP 8.1 has no standalone null type.
	 */
	public static function start( TranslationFileLoader $loader, string $file, mixed $result ): mixed {
		$reset = \Closure::bind(
			function ( ?StringTranslation $db ): LanguageRouter {
				$this->translations = [];
				$this->loaded       = true;
				$this->db           = $db;

				return $this->router;
			},
			$loader,
			TranslationFileLoader::class
		);

		$router = $reset( self::$db );

		if ( ! self::$started ) {
			self::$started = true;
			self::build( $router, $file, $result );
			$reset( self::$db );
		}

		return null;
	}

	/**
	 * Log, register the service under database mode's id (every lookup and reset reaches it) and activate it, also
	 * again after each blog switch or language override: gettext attaches by that map's rows, not the first one's.
	 *
	 * @param LanguageRouter $router The loader's router.
	 * @param string         $file   The damaged file.
	 * @param mixed          $result What the include threw or returned.
	 * @return void
	 */
	private static function build( LanguageRouter $router, string $file, mixed $result ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, once per request.
			error_log( 'PerfLocale: translation file ' . basename( $file ) . ' is damaged (' . ( $result instanceof \Throwable ? $result->getMessage() : 'no array' ) . '); this request uses the database. Regenerate the translation files.' );
		}

		$plugin = Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );

		if ( ! $cache instanceof CacheManager ) {
			return;
		}

		$db       = new StringTranslation( $router, $cache );
		self::$db = $db;
		$plugin->register( 'string_translation', static fn(): StringTranslation => $db );
		$db->activate();

		// After Bootstrap's resets (switch_blog 10, language/overridden 5); without a language activate() attaches nothing.
		add_action( 'switch_blog', [ $db, 'activate' ], 20, 0 );
		add_action( 'perflocale/language/overridden', [ $db, 'activate' ], 10 );
	}
}
