<?php
/**
 * A term's URL slug follows its DB slug through a rename until someone sets it apart.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Admin\Typed;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\SlugTranslationWrites;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: required by its callers (edit_terms in ContentSync, the REST pre-insert filter, Site Health), not in the map.
 *
 * @since 1.0.8
 */
final class TermSlugFollow {

	/**
	 * Terms being updated: "blog:term" => old DB slug, requested slug, whether it was typed, language id.
	 *
	 * @var array<string, array{slug: string, requested: string, typed: bool, lang: int}>
	 */
	private static array $pending = [];

	/**
	 * REST updates of a term: "blog:term" => whether the request also sends perflocale_url_slug.
	 *
	 * @var array<string, bool>
	 */
	private static array $rest_field = [];

	/**
	 * Follows waiting for the REST field: "blog:term" => taxonomy, language id, language row, current and new URL slug.
	 *
	 * @var array<string, array{0: string, 1: int, 2: object, 3: string, 4: string}>
	 */
	private static array $deferred = [];

	/**
	 * Messages of this term's last follow, for the Quick Edit row: "blog:term" => messages.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static array $messages = [];

	/**
	 * Filter rest_pre_insert_{taxonomy}: note whether this REST update of the term also sends perflocale_url_slug.
	 *
	 * @param mixed $prepared Term prepared for the database (returned unchanged).
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public static function rest_request( mixed $prepared, mixed $request = null ): mixed {
		if ( $request instanceof \WP_REST_Request && is_numeric( $request->get_param( 'id' ) ) ) {
			self::$rest_field[ self::key( Typed::int( $request->get_param( 'id' ) ) ) ] = null !== $request->get_param( 'perflocale_url_slug' );
		}

		return $prepared;
	}

	/**
	 * On edit_terms: remember the term's DB slug, the slug asked for and its language before core writes.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $taxonomy Taxonomy.
	 * @param mixed $args     Arguments of wp_update_term() (merged with the stored term).
	 * @return void
	 */
	public static function before( mixed $term_id, mixed $taxonomy = '', mixed $args = [] ): void {
		if ( ! is_numeric( $term_id ) || ! is_string( $taxonomy ) || ! in_array( $taxonomy, Plugin::get_instance()->settings()->get_translatable_taxonomies(), true ) ) {
			return;
		}

		$key = self::key( Typed::int( $term_id ) );

		// An earlier update of this term in the same process (REST batch, worker) left nothing for this one.
		unset( self::$deferred[ $key ], self::$messages[ $key ] );

		$term = get_term( Typed::int( $term_id ), $taxonomy );
		$lang = $term instanceof \WP_Term ? ( new TermTranslationManager( Plugin::get_instance()->cache() ) )->detect_term_language( $term->term_id ) : null;

		if ( ! $term instanceof \WP_Term || ! is_object( $lang ) || ! is_numeric( $lang->id ?? null ) ) {
			return;
		}

		// A nested update of the same term (an edited_term listener) keeps the outer rename's old slug.
		if ( isset( self::$pending[ $key ] ) && self::$pending[ $key ]['slug'] !== $term->slug ) {
			return;
		}

		$args  = is_array( $args ) ? $args : [];
		$typed = isset( $args['slug'] ) && is_string( $args['slug'] ) && '' !== $args['slug'];
		// An empty slug makes core derive one from the name (then "-2" when another language holds it).
		$requested = $typed
			? sanitize_title( $args['slug'] )
			: sanitize_title( wp_unslash( is_string( $args['name'] ?? null ) ? $args['name'] : $term->name ) );

		self::$pending[ $key ] = [
			'slug'      => $term->slug,
			'requested' => $requested,
			'typed'     => $typed && '' !== $requested,
			'lang'      => Typed::int( $lang->id ),
		];

		if ( false === has_action( 'saved_term', [ self::class, 'after' ] ) ) {
			add_action( 'saved_term', [ self::class, 'after' ], 10, 4 );
		}
	}

	/**
	 * On saved_term (after the URL slug field's form save): move the URL slug when it still follows the old DB slug.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $tt_id    Term taxonomy ID.
	 * @param mixed $taxonomy Taxonomy.
	 * @param mixed $update   True for an update.
	 * @return void
	 */
	public static function after( mixed $term_id, mixed $tt_id = 0, mixed $taxonomy = '', mixed $update = false ): void {
		unset( $tt_id );
		$key  = is_numeric( $term_id ) ? self::key( Typed::int( $term_id ) ) : '';
		$rest = self::$rest_field[ $key ] ?? false;
		unset( self::$rest_field[ $key ] );

		if ( true !== $update || ! isset( self::$pending[ $key ] ) || ! is_string( $taxonomy ) ) {
			return;
		}

		$was = self::$pending[ $key ];
		unset( self::$pending[ $key ] );

		$term = get_term( Typed::int( $term_id ), $taxonomy );

		if ( ! $term instanceof \WP_Term || $term->slug === $was['slug'] ) {
			return;
		}

		// A language changed in this save leaves the rows to the language paths.
		$lang = ( new TermTranslationManager( Plugin::get_instance()->cache() ) )->detect_term_language( $term->term_id );

		if ( ! is_object( $lang ) || Typed::int( $lang->id ?? 0 ) !== $was['lang'] || ! is_string( $lang->slug ?? null ) ) {
			return;
		}

		$cur = Plugin::get_instance()->slug_manager()->get_slug( 'term', $term->term_id, $was['lang'] );

		// Without a URL slug the new DB slug is the URL, unless another term of the language shows it ("-N").
		if ( ! is_string( $cur ) || '' === $cur ) {
			self::moved( $term->term_id, 0, $lang );

			return;
		}

		if ( ! self::follows( $cur, $was['slug'], $lang->slug, self::active_slugs() ) ) {
			return;
		}

		// What was asked, not core's "-N"; a typed Slug as typed, one from the name without its own-language ending.
		$to = $was['typed'] ? $was['requested'] : self::derive( '' !== $was['requested'] ? $was['requested'] : $term->slug, $lang->slug );

		if ( $to === $cur ) {
			return;
		}

		// REST writes its additional fields after this: the field decides (settle_rest()), so nothing is written twice.
		if ( $rest ) {
			self::$deferred[ $key ] = [ $taxonomy, $was['lang'], $lang, $cur, $to ];

			return;
		}

		self::write( $key, $taxonomy, $term->term_id, $was['lang'], $lang, $cur, $to );
	}

	/**
	 * REST field update after a rename in the same request: the value shown before (untouched) lets the URL slug follow.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $value   perflocale_url_slug sent.
	 * @return bool True when this handled the field; false: the caller writes the value (the follow is dropped).
	 */
	public static function settle_rest( int $term_id, string $value ): bool {
		$key = self::key( $term_id );
		$d   = self::$deferred[ $key ] ?? null;
		unset( self::$deferred[ $key ] );

		if ( null === $d || sanitize_title( $value ) !== $d[3] ) {
			return false;
		}

		self::write( $key, $d[0], $term_id, $d[1], $d[2], $d[3], $d[4] );

		return true;
	}

	/**
	 * Whether URL slug $display still follows DB slug $db: equal, or with the "-<lang>" and "-N" creation and core's
	 * uniqueness add.
	 *
	 * @param string             $display URL slug in the term's language.
	 * @param string             $db      DB slug before the rename.
	 * @param string             $lang    The term's language slug.
	 * @param array<int, string> $active Active language slugs (creation strips any of them from a source's slug).
	 * @return bool
	 */
	public static function follows( string $display, string $db, string $lang, array $active ): bool {
		if ( $display === $db || 1 === preg_match( '/^' . preg_quote( $display, '/' ) . '(?:-' . preg_quote( $lang, '/' ) . ')?(?:-[0-9]+)?$/', $db ) ) {
			return true;
		}

		$quoted = array_map( static fn( string $s ): string => preg_quote( $s, '/' ), array_filter( $active, static fn( string $s ): bool => '' !== $s ) );

		return [] !== $quoted && $display === preg_replace( '/-(?:' . implode( '|', $quoted ) . ')$/', '', $db );
	}

	/**
	 * The URL slug for DB slug $db: without its own-language "-<lang>" or "-<lang>-N" (never another language's).
	 *
	 * @param string $db   DB slug.
	 * @param string $lang The term's language slug.
	 * @return string
	 */
	public static function derive( string $db, string $lang ): string {
		$base = preg_replace( '/-' . preg_quote( $lang, '/' ) . '(?:-[0-9]+)?$/', '', $db );

		return is_string( $base ) && '' !== $base ? $base : $db;
	}

	/**
	 * Messages of the term's follow in this request (the Quick Edit row shows them).
	 *
	 * @param int $term_id Term ID.
	 * @return array<int, string>
	 */
	public static function messages( int $term_id ): array {
		return self::$messages[ self::key( $term_id ) ] ?? [];
	}

	/**
	 * The follow's write, compare-and-set on the URL slug read; its outcome to the screen, Quick Edit row or WP-CLI.
	 *
	 * @param string $key      Pending key.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @param int    $lang_id  Language ID.
	 * @param object $lang     Language row.
	 * @param string $cur      URL slug read.
	 * @param string $to       New URL slug.
	 * @return void
	 */
	private static function write( string $key, string $taxonomy, int $term_id, int $lang_id, object $lang, string $cur, string $to ): void {
		$r    = SlugTranslationWrites::write_term_url_slug( $taxonomy, $term_id, $lang_id, $to, false, 3, 200, $cur );
		$name = $lang->native_name ?? $lang->name ?? $lang->slug ?? '';
		$name = is_string( $name ) ? $name : '';

		if ( 'conflict' === $r['error'] ) {
			$holder = get_term( Typed::int( $r['conflict_term_id'] ) );
			/* translators: 1: URL slug, 2: name of the term using it, 3: language name. */
			$msgs = [ sprintf( __( 'URL slug not changed: "%1$s" is used by %2$s in %3$s.', 'perflocale' ), urldecode( $r['slug'] ), $holder instanceof \WP_Term ? $holder->name : '#' . Typed::int( $r['conflict_term_id'] ), $name ) ];
		} elseif ( 'busy' === $r['error'] ) {
			/* translators: %s: language name. */
			$msgs = [ sprintf( __( 'URL slug in %s not changed: an import or another URL slug change is running. Save the term again in a few seconds.', 'perflocale' ), $name ) ];
		} else {
			// Rule 1 notes: another term's old URL slug stops redirecting.
			$msgs = $r['ok'] ? $r['notes'] : [];
		}

		self::notify( $key, $msgs );
	}

	/**
	 * A language move while URL slug rows exist: the link write and its URL slug repair in one lock and transaction, so
	 * both land or neither; while an import holds the lock the move is refused and the screen says why.
	 *
	 * @param int             $term_id  Term ID.
	 * @param int             $old_lang Language ID it leaves.
	 * @param object          $lang     Language row it joins.
	 * @param callable(): bool $link    The link write.
	 * @return bool Whether the term moved.
	 */
	public static function move_atomically( int $term_id, int $old_lang, object $lang, callable $link ): bool {
		$linked = false;
		$r      = \PerfLocale\Admin\ImportLock::with_slug_write(
			static function () use ( $term_id, $old_lang, $lang, $link, &$linked ): array {
				$linked = $link();

				return [ 'ok' => $linked && self::moved( $term_id, $old_lang, $lang ) ];
			},
			3,
			200
		);
		$name = $lang->native_name ?? $lang->name ?? $lang->slug ?? '';
		$name = is_string( $name ) ? $name : '';

		if ( false === $r ) {
			/* translators: %s: language name. */
			self::notify( self::key( $term_id ), [ sprintf( __( 'Language not changed to %s: an import or another URL slug change is running. Try again in a few seconds.', 'perflocale' ), $name ) ] );

			return false;
		}

		// A failed link write is the caller's false, as without URL slug rows.
		if ( empty( $r['ok'] ) && $linked ) {
			/* translators: %s: language name. */
			self::notify( self::key( $term_id ), array_merge( [ sprintf( __( 'Language not changed to %s: the term\'s URL slug could not move with it.', 'perflocale' ), $name ) ], self::messages( $term_id ) ) );
		}

		return ! empty( $r['ok'] );
	}

	/**
	 * After set_term_language() moved the term: its URL slug rows follow it, "-N" when another term holds the slug.
	 * $old_lang 0: a first language or a rename without a URL slug (only a DB slug another term shows gets "-N");
	 * a busy lock queues it for TermSlugRetry.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $old_lang Language ID it left, or 0.
	 * @param object $lang     Language row it joined.
	 * @return bool False when the URL slug rows could not follow.
	 */
	public static function moved( int $term_id, int $old_lang, object $lang ): bool {
		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term || ! is_numeric( $lang->id ?? null ) ) {
			return true;
		}

		$r    = SlugTranslationWrites::move_term_url_slug( $term->taxonomy, $term_id, $old_lang, Typed::int( $lang->id ), $term->slug );
		$name = $lang->native_name ?? $lang->name ?? $lang->slug ?? '';
		$name = is_string( $name ) ? $name : '';
		$held = null !== $r['conflict_term_id'] ? get_term( $r['conflict_term_id'] ) : null;
		$held = $held instanceof \WP_Term ? $held->name : '#' . Typed::int( $r['conflict_term_id'] );

		if ( $r['ok'] && '' !== $r['slug'] && null !== $r['conflict_term_id'] ) {
			/* translators: 1: language name, 2: new URL slug, 3: name of the term that already uses this term's slug. */
			$msgs = array_merge( [ sprintf( __( 'URL slug in %1$s set to "%2$s": %3$s already uses this term\'s slug there.', 'perflocale' ), $name, urldecode( $r['slug'] ), $held ) ], $r['notes'] );
		} elseif ( 'busy' === $r['error'] && 0 === $old_lang ) {
			require_once __DIR__ . '/TermSlugRetry.php';
			TermSlugRetry::queue( $term->taxonomy, $term_id, Typed::int( $lang->id ), '' );
			/* translators: %s: language name. */
			$msgs = [ sprintf( __( 'URL slug in %s waits for the import that is running: another term uses this term\'s slug there, so this term gets its own in a few minutes.', 'perflocale' ), $name ) ];
		} elseif ( 'busy' === $r['error'] ) {
			/* translators: %s: language name. */
			$msgs = [ sprintf( __( 'URL slug in %s not moved: an import or another URL slug change is running. Set the URL slug on this term.', 'perflocale' ), $name ) ];
		} elseif ( 'conflict' === $r['error'] ) {
			/* translators: 1: language name, 2: name of the term using the slug. */
			$msgs = [ sprintf( __( 'URL slug in %1$s not set: %2$s uses this term\'s slug there. Set a URL slug on this term.', 'perflocale' ), $name, $held ) ];
		} else {
			$msgs = $r['ok'] ? $r['notes'] : [];
		}

		self::notify( self::key( $term_id ), $msgs );

		return $r['ok'];
	}

	/**
	 * Messages of a follow or move to the term screen, the Quick Edit row or WP-CLI.
	 *
	 * @param string             $key  Pending key.
	 * @param array<int, string> $msgs Messages.
	 * @return void
	 */
	private static function notify( string $key, array $msgs ): void {
		if ( [] === $msgs ) {
			return;
		}

		self::$messages[ $key ] = $msgs;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read only to pick the notice channel; core checked the nonce.
		$action  = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
		$user_id = get_current_user_id();

		// MetaBox::maybe_show_language_save_error() shows it on the term screen the form redirects to.
		if ( 'editedtag' === $action && $user_id > 0 ) {
			set_transient( 'perflocale_lang_save_error_' . $user_id, implode( ' ', $msgs ), 60 );
		} elseif ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			foreach ( $msgs as $msg ) {
				\WP_CLI::warning( $msg );
			}
		}
	}

	/**
	 * Active language slugs.
	 *
	 * @return array<int, string>
	 */
	private static function active_slugs(): array {
		$out = [];

		foreach ( ( new LanguageRepository( Plugin::get_instance()->cache() ) )->get_active() as $lang ) {
			if ( is_string( $lang->slug ?? null ) ) {
				$out[] = $lang->slug;
			}
		}

		return $out;
	}

	/**
	 * Per-blog key of a term.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private static function key( int $term_id ): string {
		return get_current_blog_id() . ':' . $term_id;
	}
}
