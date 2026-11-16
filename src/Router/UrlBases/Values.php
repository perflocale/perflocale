<?php
/**
 * URL bases user values.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `perflocale_url_bases` option: switches, one value per (key, non-default language) and the value history.
 *
 * Values are in norm() form, segments joined by `/`. Each write is one transaction (update()). `pub` records every
 * address a published map served as a current or history alias (key => lang => list); `v` 2 marks it as maintained.
 *
 * @phpstan-type Entry array{on?: bool, grp?: string, tr?: array<string, string>, hist?: array<string, list<string>>, reg?: list<string>}
 * @phpstan-type Served array<string, array<string, list<string>>>
 * @phpstan-type LangRow array{id: int, slug: string, locale: string, is_default: bool, is_active: bool}
 * @phpstan-type ValueSet array{v: int, on: bool, redirect: bool, groups: array<string, bool>, keys: array<string, Entry>, pub: Served, forget?: list<string>}
 *
 * @internal
 */
final class Values {

	public const OPTION = 'perflocale_url_bases';

	/**
	 * History values kept per key and language, newest first.
	 */
	public const HISTORY_CAP = 5;

	/**
	 * Published addresses recorded per key and language: a new value beyond them is refused, never an old one dropped.
	 */
	public const PUB_CAP = 12;

	/**
	 * Registered bases kept per key, newest first: today's and the previous one (the compile writes them).
	 */
	public const REG_CAP = 2;

	/**
	 * Segments per value.
	 */
	public const MAX_SEGMENTS = 4;

	/**
	 * Bytes per segment (norm form).
	 */
	public const MAX_SEGMENT_BYTES = 200;

	/**
	 * Keys a site may configure (3.2 cap).
	 */
	public const MAX_KEYS = 200;

	/**
	 * Languages a value set may hold (3.2 cap).
	 */
	public const MAX_LANGUAGES = 64;

	/**
	 * Forget entries a value set may hold pending (one per listed old address).
	 */
	public const MAX_FORGET_ENTRIES = 1000;

	/**
	 * Bytes of a forget entry's address (a published alias, registered bases included: no segment limit).
	 */
	public const MAX_ADDRESS_BYTES = 1024;

	/**
	 * Bytes of a forget entry id: key (36), separator, language slug (7), separator, address.
	 */
	public const MAX_ENTRY_BYTES = 45 + self::MAX_ADDRESS_BYTES;

	/**
	 * Seconds the values lease lasts.
	 */
	public const LOCK_TTL = 10;

	/**
	 * The values lock: every values write and every language slug change (LanguageWrites).
	 */
	public const LOCK = 'url_bases_values';

	/**
	 * Defaults: the feature off until the user ticks it, every group on, redirect aliases on, no values.
	 *
	 * @return ValueSet
	 */
	public static function defaults(): array {
		return [
			'v'        => 2,
			'on'       => false,
			'redirect' => true,
			'groups'   => [],
			'keys'     => [],
			'pub'      => [],
		];
	}

	/**
	 * The stored values; `$fresh` reads the row past every cache and throws when that read fails (never the defaults).
	 *
	 * @param bool $fresh Read with $wpdb.
	 * @return ValueSet
	 * @throws \UnexpectedValueException When the fresh read failed.
	 */
	public static function get( bool $fresh = false ): array {
		if ( $fresh ) {
			return self::fresh() ?? throw new \UnexpectedValueException( 'URL bases values unreadable' );
		}

		return self::shape( get_option( self::OPTION, null ) );
	}

	/**
	 * The stored values past every cache; null when the read failed.
	 *
	 * @return ValueSet|null
	 */
	public static function fresh(): ?array {
		$row = self::read();

		return $row->failed ? null : self::shape( null !== $row->bytes ? maybe_unserialize( $row->bytes ) : null );
	}

	/**
	 * Whether the option row may exist (the feature was ever configured): a failed read counts as yes.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		return ! self::read()->absent();
	}

	/**
	 * The stored row, past every cache: its bytes, absent or failed.
	 *
	 * @return \PerfLocale\Database\OptionRead
	 */
	public static function read(): \PerfLocale\Database\OptionRead {
		return \PerfLocale\Database\OptionRead::of( self::OPTION );
	}

	/**
	 * Apply a change to the stored values in one transaction and store the result.
	 *
	 * The language rows, then the values row, are read with row locks held to COMMIT; the change gets the live slugs and
	 * the write is a guarded compare-and-set (three attempts). The lease only spaces writers out; correctness does not
	 * depend on it. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: slug renames and deletes".
	 *
	 * @param callable(ValueSet, list<string>, list<LangRow>): mixed $change Given the value set, the live language slugs and the
	 *                                                                     locked language rows, returns a value set (shaped after); may run more than once.
	 * @param int                                                    $ttl    Lock lease in seconds.
	 * @return bool|null True when stored, false when nothing changed, null when the lock was busy or a read or the write failed.
	 */
	public static function update( callable $change, int $ttl = self::LOCK_TTL ): ?bool {
		// Inside another plugin transaction (a hook): refused before the lock row or a START TRANSACTION joins or commits it.
		if ( \PerfLocale\Database\Tx::open() ) {
			return null;
		}

		$result = 'failed';

		try {
			$result = \PerfLocale\Database\Tx::leased( self::LOCK, $ttl, static fn(): string => self::transact( $change ) );
		} finally {
			// And after it (a completion listener may throw): a reader that marked the blog between the two is undone.
			if ( 'same' !== $result ) {
				Lifecycle::clear_none();
			}
		}

		if ( 'stored' === $result ) {
			Publication::reset_dry_run();

			return true;
		}

		return 'same' === $result ? false : null;
	}

	/**
	 * One values write as its own transaction: COMMIT only after a store on the connection it began on, else ROLLBACK.
	 *
	 * @param callable(ValueSet, list<string>, list<LangRow>): mixed $change Change.
	 * @return string 'stored', 'same' or 'failed'.
	 */
	private static function transact( callable $change ): string {
		$result  = 'failed';
		$outcome = null;

		try {
			$outcome = \PerfLocale\Database\Tx::run(
				'url_bases',
				static function ( \PerfLocale\Database\Tx $tx ) use ( $change, &$result ): bool {
					// Every language row first, in id order (the global lock order).
					$result = Inputs::lock_languages() && $tx->alive() ? self::write_in_transaction( $change, $tx->guard() ) : 'failed';

					return 'stored' === $result;
				},
				// COMMIT unconfirmed: the row's bytes say whether it landed (a shaped compare would take a rolled-back row that only
				// differs in dropped parts for the write).
				static function (): bool {
					$wrote = \PerfLocale\Database\OptionWriter::wrote( self::OPTION );
					$row   = self::read();

					return null !== $wrote && ! $row->failed && $row->bytes === $wrote[0];
				},
				'',
				// Before any completion listener (one may throw): the builds made from the old values.
				static function (): void {
					Publication::reset_dry_run();
				}
			);
		} finally {
			// Rolled back, unknown or thrown: every cached form of a row written went (settle), and the builds made from the values go.
			if ( null === $outcome || [] !== $outcome->forgotten ) {
				Publication::reset_dry_run();
			}

			$result = null !== $outcome && 'done' === $outcome->status ? 'stored' : ( null !== $outcome && 'body' === $outcome->why && 'same' === $result ? 'same' : 'failed' );

			if ( 'stored' !== $result ) {
				wp_cache_delete( self::OPTION, 'options' );
			}
		}

		return $result;
	}

	/**
	 * Apply a change inside an open transaction: the live language slugs (locked), then the values row (locked), then a
	 * guarded compare-and-set. A failed read never runs the change (CODING-RULES A).
	 *
	 * @internal Tx units (delete, compile) and the Replace import call it inside their transaction.
	 *
	 * @param callable(ValueSet, list<string>, list<LangRow>): mixed $change Change.
	 * @param \PerfLocale\Database\ConnectionGuard                   $guard  The open transaction's guard.
	 * @return string 'stored', 'same' or 'failed'.
	 */
	public static function write_in_transaction( callable $change, \PerfLocale\Database\ConnectionGuard $guard ): string {
		// Only for the transaction that owns the request's plugin transaction.
		if ( ! $guard->owns() ) {
			return 'failed';
		}

		// Language rows first (one lock order for every transaction): the slugs this write may bind.
		$rows = Inputs::rows( true );

		// A lost connection (the server rolled back) stops before anything more is sent.
		if ( null === $rows || ! $guard->holds() ) {
			return 'failed';
		}

		$live = array_column( $rows, 'slug' );

		for ( $try = 0; $try < 3; $try++ ) {
			// Locking: inside the transaction a plain re-read would see the first snapshot.
			$row = \PerfLocale\Database\OptionRead::of( self::OPTION, true );
			$raw = $row->bytes;

			// A failed read is not "no row": writing defaults over the stored set would lose it.
			if ( $row->failed || ! $guard->holds() ) {
				return 'failed';
			}

			$old    = null !== $raw ? maybe_unserialize( $raw ) : false;
			$stored = self::shape( null !== $raw ? $old : null );
			// A `ren` key from a pre-release build: shape() drops it and this write stores the set without it.
			$stale = is_array( $old ) && array_key_exists( 'ren', $old );
			$next  = self::shape( $change( $stored, $live, $rows ) );

			// The change may read (the delete's proofs).
			if ( ! $guard->holds() ) {
				return 'failed';
			}

			// No row is created for a set that holds nothing a user chose (an unchanged first save).
			if ( null === $raw ? self::untouched( $next ) : $next === $stored && ! $stale ) {
				return 'same';
			}

			// Before the write: no reader may skip these values on a marker this write makes stale. Replay-safe: a resent
			// marker DELETE only makes readers read the rows.
			Lifecycle::clear_none();

			if ( ! $guard->holds() ) {
				return 'failed';
			}

			// A row that held only pre-release renames goes (compare-and-delete).
			if ( $stale && self::defaults() === $next ) {
				$deleted = \PerfLocale\Database\OptionWriter::compare_and_delete( self::OPTION, $raw, true, $guard->condition() );

				if ( null !== $deleted ) {
					return $guard->holds() ? 'stored' : 'failed';
				}
			} else {
				// Same end state whoever wrote it: a resent statement that landed counts as written (rule F).
				$written = \PerfLocale\Database\OptionWriter::compare_and_set( self::OPTION, $next, $old, $raw, false, true, $guard->condition() );

				// A reply lost after the write: the server rolled the transaction back.
				if ( null !== $written ) {
					return ! $guard->holds() ? 'failed' : ( $written ? 'stored' : 'same' );
				}
			}

			wp_cache_delete( self::OPTION, 'options' );

			// A lost connection (the server rolled back) or a database failure stops; a row changed since the read runs
			// the change again.
			if ( ! $guard->kept() || 'database' === ( \PerfLocale\Database\OptionWriter::last_failure( self::OPTION )['reason'] ?? '' ) ) {
				return 'failed';
			}
		}

		return 'failed';
	}

	/**
	 * Whether a stored or shaped set uses URL bases: the switch on and a typed value or history in some key.
	 *
	 * @param mixed $raw Value set.
	 * @return bool
	 */
	public static function in_use( mixed $raw ): bool {
		return is_array( $raw ) && ! empty( $raw['on'] ) && self::typed( $raw );
	}

	/**
	 * Whether any key of a set holds a typed value or history (malformed ones count: never a reason to delete).
	 *
	 * @param array<mixed> $raw Value set.
	 * @return bool
	 */
	public static function typed( array $raw ): bool {
		foreach ( is_array( $raw['keys'] ?? null ) ? $raw['keys'] : [] as $entry ) {
			if ( ! is_array( $entry ) || ! empty( $entry['tr'] ) || ! empty( $entry['hist'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a shaped set holds nothing a user chose: every switch at its default, no value, history or forget.
	 *
	 * @param ValueSet $v Value set.
	 * @return bool
	 */
	public static function untouched( array $v ): bool {
		if ( $v['on'] || ! $v['redirect'] || in_array( false, $v['groups'], true ) || ! empty( $v['forget'] ) || self::typed( $v ) ) {
			return false;
		}

		foreach ( $v['keys'] as $entry ) {
			if ( false === ( $entry['on'] ?? true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Set one value; the replaced value goes to the history (newest first) only when it was live, the new one leaves it.
	 *
	 * @param ValueSet $values   Values.
	 * @param string   $key      Key.
	 * @param string   $lang     Language slug.
	 * @param string   $segs     New value in norm form, '' to clear.
	 * @param string   $grp      Group of the key now.
	 * @param bool     $was_live The replaced value was live (was_live()); true when unsure.
	 * @return ValueSet
	 */
	public static function with_value( array $values, string $key, string $lang, string $segs, string $grp, bool $was_live = true ): array {
		$entry        = $values['keys'][ $key ] ?? [];
		$entry['grp'] = $grp;
		$old          = $entry['tr'][ $lang ] ?? '';

		if ( $old !== $segs ) {
			$hist = array_values( array_filter( $entry['hist'][ $lang ] ?? [], static fn( string $h ): bool => $h !== $segs && $h !== $old ) );

			if ( '' !== $old && $was_live ) {
				array_unshift( $hist, $old );
			}

			$entry['hist'][ $lang ] = array_slice( $hist, 0, self::HISTORY_CAP );

			if ( '' === $segs ) {
				unset( $entry['tr'][ $lang ] );
			} else {
				$entry['tr'][ $lang ] = $segs;
			}
		}

		$values['keys'][ $key ] = $entry;

		return self::shape( $values );
	}

	/**
	 * Current and history alias values of the published map by key and language; [] without a map, null when unsure
	 * (a failed read or a row that is not a map).
	 *
	 * @return Served|null
	 */
	public static function published(): ?array {
		$row = \PerfLocale\Database\OptionRead::of( Publication::MAP );

		if ( $row->failed ) {
			return null;
		}

		if ( null === $row->bytes ) {
			return [];
		}

		$map = maybe_unserialize( $row->bytes );

		return is_array( $map ) && 1 === ( $map['v'] ?? null ) ? self::served( $map ) : null;
	}

	/**
	 * Current and history alias values of a map by key and language.
	 *
	 * @param array<mixed> $map Map v1.
	 * @return Served
	 */
	public static function served( array $map ): array {
		$out = [];

		foreach ( is_array( $map['alias'] ?? null ) ? $map['alias'] : [] as $list ) {
			foreach ( is_array( $list ) ? $list : [] as $e ) {
				if ( is_array( $e ) && in_array( $e['kind'] ?? '', [ 'current', 'history' ], true ) && is_string( $e['key'] ?? null ) && is_string( $e['lang'] ?? null ) && is_array( $e['segs'] ?? null ) ) {
					$out[ $e['key'] ][ $e['lang'] ][] = implode( '/', \PerfLocale\Admin\Typed::strs( $e['segs'] ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a value was live for a key and language: the map serves or keeps it there, or under a slug that is no
	 * language now. Null `$served` is unsure: true.
	 *
	 * @param array<string, array<string, list<string>>>|null $served Values by key and language (served()).
	 * @param string                                          $key    Key.
	 * @param string                                          $lang   Language slug.
	 * @param string                                          $segs   Value in norm form.
	 * @param list<string>                                    $langs  Language slugs of this site now.
	 * @return bool
	 */
	public static function was_live( ?array $served, string $key, string $lang, string $segs, array $langs ): bool {
		if ( null === $served ) {
			return true;
		}

		foreach ( $served[ $key ] ?? [] as $l => $list ) {
			if ( in_array( $segs, $list, true ) && ( $l === $lang || ! in_array( $l, $langs, true ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The set with every address of `$served` recorded in `pub` (new ones first); a set not yet maintained (`v` 1) is
	 * seeded from its typed values and history first (any may have been published) and becomes `v` 2.
	 *
	 * @param ValueSet     $values Values.
	 * @param Served       $served Addresses a published map serves.
	 * @param list<string> $live   Language slugs now: only theirs are recorded.
	 * @return ValueSet
	 */
	public static function with_pub( array $values, array $served, array $live ): array {
		if ( 2 !== $values['v'] ) {
			foreach ( $values['keys'] as $key => $entry ) {
				foreach ( $entry['tr'] ?? [] as $lang => $segs ) {
					$served[ $key ][ $lang ][] = $segs;
				}

				foreach ( $entry['hist'] ?? [] as $lang => $list ) {
					$served[ $key ][ $lang ] = array_merge( $served[ $key ][ $lang ] ?? [], $list );
				}
			}

			$values['v'] = 2;
		}

		foreach ( $served as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $list ) {
				// A deleted language's addresses are not recorded: they would block a later language with its slug.
				if ( ! in_array( (string) $lang, $live, true ) ) {
					continue;
				}

				$have = $values['pub'][ $key ][ $lang ] ?? [];
				$new  = array_values( array_diff( array_unique( $list ), $have ) );

				if ( [] !== $new ) {
					$values['pub'][ $key ][ $lang ] = array_merge( $new, $have );
				}
			}
		}

		return self::shape( $values );
	}

	/**
	 * Whether `pub` may be behind what was published: the set is not maintained yet, or a map serves an address it lacks
	 * (one with a pending forget aside: the forget removed it on purpose).
	 *
	 * @param ValueSet                  $values Values.
	 * @param array<string, mixed>|null $map    The stored map v1.
	 * @param list<string>|null         $forget Pending forget items; null: the set's own.
	 * @return bool
	 */
	public static function pub_behind( array $values, ?array $map, ?array $forget = null ): bool {
		return 2 !== $values['v'] || self::pub_lacks( $values, self::unforgotten( null !== $map ? self::served( $map ) : [], $forget ?? $values['forget'] ?? [] ) );
	}

	/**
	 * Served addresses without those a pending forget names (an entry, its key or `*`).
	 *
	 * @param Served       $served Addresses by key and language.
	 * @param list<string> $forget Pending forget items.
	 * @return Served
	 */
	public static function unforgotten( array $served, array $forget ): array {
		if ( in_array( '*', $forget, true ) ) {
			return [];
		}

		foreach ( $served as $key => $by_lang ) {
			if ( in_array( $key, $forget, true ) ) {
				unset( $served[ $key ] );
				continue;
			}

			foreach ( $by_lang as $lang => $list ) {
				$served[ $key ][ $lang ] = array_values( array_filter( $list, static fn( string $a ): bool => ! in_array( self::entry_id( $key, $lang, $a ), $forget, true ) ) );
			}
		}

		return $served;
	}

	/**
	 * Every address known live for a set: what a map serves and what `pub` recorded; null (unsure) when the record is not
	 * maintained yet or the map is unread.
	 *
	 * @param ValueSet    $values Values.
	 * @param Served|null $served What the published map serves; null when unread.
	 * @return Served|null
	 */
	public static function witnesses( array $values, ?array $served ): ?array {
		if ( null === $served || 2 !== $values['v'] ) {
			return null;
		}

		foreach ( $values['pub'] as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $list ) {
				$served[ $key ][ $lang ] = array_merge( $served[ $key ][ $lang ] ?? [], $list );
			}
		}

		return $served;
	}

	/**
	 * Whether `pub` lacks an address of `$served`.
	 *
	 * @param ValueSet $values Values.
	 * @param Served   $served Addresses by key and language.
	 * @return bool
	 */
	public static function pub_lacks( array $values, array $served ): bool {
		foreach ( $served as $key => $by_lang ) {
			foreach ( $by_lang as $lang => $list ) {
				if ( [] !== array_diff( $list, $values['pub'][ $key ][ $lang ] ?? [] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Forget confirmed old addresses (`key|lang|segs`, lang `*` = a previous registered base): each leaves the history, a
	 * typed value only for the default language or an off key (it no longer serves). Nothing unlisted goes; the `forget`
	 * list keeps the entries until a compile publishes them.
	 *
	 * @param ValueSet            $values       Values.
	 * @param list<string>        $entries      Confirmed entries.
	 * @param array<string, bool> $off          Off keys.
	 * @param string              $default_lang Default language slug.
	 * @return ValueSet
	 */
	public static function forget( array $values, array $entries, array $off, string $default_lang = '' ): array {
		$done = [];

		foreach ( $entries as $item ) {
			$e = self::entry( $item );

			if ( null === $e ) {
				continue;
			}

			[ $key, $lang, $segs ] = $e;
			$done[]                = $item;
			$entry                 = $values['keys'][ $key ] ?? null;

			if ( null === $entry ) {
				continue;
			}

			if ( '*' === $lang ) {
				$entry['reg'] = array_values( array_diff( $entry['reg'] ?? [], [ $segs ] ) );
			} else {
				$entry['hist'][ $lang ] = array_values( array_diff( $entry['hist'][ $lang ] ?? [], [ $segs ] ) );
				$values['pub']          = self::without_pub( $values['pub'], $key, $lang, $segs );

				if ( $segs === ( $entry['tr'][ $lang ] ?? null ) && ( $lang === $default_lang || ! empty( $off[ $key ] ) ) ) {
					unset( $entry['tr'][ $lang ] );
				}
			}

			$values['keys'][ $key ] = $entry;
		}

		if ( [] !== $done ) {
			$values['forget'] = array_merge( $values['forget'] ?? [], $done );
		}

		return self::shape( $values );
	}

	/**
	 * `pub` without one address.
	 *
	 * @param Served $pub  Recorded addresses.
	 * @param string $key  Key.
	 * @param string $lang Language slug.
	 * @param string $segs Address.
	 * @return Served
	 */
	public static function without_pub( array $pub, string $key, string $lang, string $segs ): array {
		if ( isset( $pub[ $key ][ $lang ] ) ) {
			$pub[ $key ][ $lang ] = array_values( array_diff( $pub[ $key ][ $lang ], [ $segs ] ) );
		}

		return $pub;
	}

	/**
	 * A forget entry's id: key, language slug (or `*`) and value in norm form.
	 *
	 * @param string $key  Key.
	 * @param string $lang Language slug or `*`.
	 * @param string $segs Value in norm form.
	 * @return string
	 */
	public static function entry_id( string $key, string $lang, string $segs ): string {
		return $key . '|' . $lang . '|' . $segs;
	}

	/**
	 * A row id of "Stop redirecting old addresses": one redirect, a key (the single key of a single/archive pair) and an
	 * address. `+` is no language slug and not `*`, so a row id is never a forget entry.
	 *
	 * @param string $key  Key.
	 * @param string $segs Address in norm form.
	 * @return string
	 */
	public static function row_id( string $key, string $segs ): string {
		return $key . '|+|' . $segs;
	}

	/**
	 * A row id's key and address, or null when it is not one.
	 *
	 * @param string $item Row id.
	 * @return array{0: string, 1: string}|null
	 */
	public static function row( string $item ): ?array {
		$p = explode( '|', $item, 3 );

		return 3 === count( $p ) && '+' === $p[1] && 1 === preg_match( Registry::KEY_PATTERN, $p[0] ) && self::is_address( $p[2] ) ? [ $p[0], $p[2] ] : null;
	}

	/**
	 * A forget entry's parts, or null when it is not one (a key never holds `|`, so the value is the rest). The value is
	 * any address the compiler can publish, not only a typed one: a registered base may have more segments.
	 *
	 * @param string $item Entry id.
	 * @return array{0: string, 1: string, 2: string}|null
	 */
	public static function entry( string $item ): ?array {
		$p = explode( '|', $item, 3 );

		if ( 3 !== count( $p ) || 1 !== preg_match( Registry::KEY_PATTERN, $p[0] ) || ( '*' !== $p[1] && ! self::is_lang( $p[1] ) ) || ! self::is_address( $p[2] ) ) {
			return null;
		}

		return [ $p[0], $p[1], $p[2] ];
	}

	/**
	 * Normalize a stored or imported value set; unknown and malformed parts are dropped.
	 *
	 * @param mixed $raw Raw value.
	 * @return ValueSet
	 */
	public static function shape( mixed $raw ): array {
		$out = self::defaults();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		// Only a set written before `pub` existed (1.0.8 RCs) says 1: unsure what it published.
		$out['v']        = 1 === ( $raw['v'] ?? null ) ? 1 : 2;
		$out['on']       = array_key_exists( 'on', $raw ) && (bool) $raw['on'];
		$out['redirect'] = ! array_key_exists( 'redirect', $raw ) || (bool) $raw['redirect'];

		foreach ( is_array( $raw['groups'] ?? null ) ? $raw['groups'] : [] as $grp => $on ) {
			if ( is_string( $grp ) && 1 === preg_match( Registry::GROUP_PATTERN, $grp ) ) {
				$out['groups'][ $grp ] = (bool) $on;
			}
		}

		foreach ( is_array( $raw['keys'] ?? null ) ? $raw['keys'] : [] as $key => $entry ) {
			if ( is_string( $key ) && 1 === preg_match( Registry::KEY_PATTERN, $key ) && is_array( $entry ) ) {
				$out['keys'][ $key ] = self::shape_entry( $entry );
			}
		}

		ksort( $out['keys'] );

		// Every valid address is kept (a witness is never dropped on shape); a cell is bounded for stored data only.
		foreach ( is_array( $raw['pub'] ?? null ) ? $raw['pub'] : [] as $key => $by_lang ) {
			if ( ! is_string( $key ) || 1 !== preg_match( Registry::KEY_PATTERN, $key ) || ! is_array( $by_lang ) ) {
				continue;
			}

			foreach ( $by_lang as $lang => $list ) {
				$keep = is_string( $lang ) && self::is_lang( $lang ) && is_array( $list ) ? array_values( array_unique( array_filter( $list, static fn( $a ): bool => is_string( $a ) && self::is_address( $a ) ) ) ) : [];

				if ( [] !== $keep ) {
					$out['pub'][ $key ][ $lang ] = array_slice( $keep, 0, 2 * self::PUB_CAP );
				}
			}
		}

		ksort( $out['pub'] );

		$forget = [];

		foreach ( is_array( $raw['forget'] ?? null ) ? $raw['forget'] : [] as $key ) {
			if ( is_string( $key ) && ( '*' === $key || 1 === preg_match( Registry::KEY_PATTERN, $key ) || null !== self::entry( $key ) ) ) {
				$forget[] = $key;
			}
		}

		// Pending forget instructions (keys, `*` or entries); more keys than the key cap means all. Entries over their cap
		// are dropped, never widened: those addresses keep redirecting.
		if ( [] !== $forget ) {
			$forget        = array_values( array_unique( $forget ) );
			$keys          = array_filter( $forget, static fn( string $f ): bool => ! str_contains( $f, '|' ) );
			$out['forget'] = in_array( '*', $forget, true ) || count( $keys ) > self::MAX_KEYS ? [ '*' ] : array_slice( $forget, 0, self::MAX_KEYS + self::MAX_FORGET_ENTRIES );
		}

		return $out;
	}

	/**
	 * One key's stored entry, normalized.
	 *
	 * @param array<mixed> $entry Raw entry.
	 * @return Entry
	 */
	private static function shape_entry( array $entry ): array {
		$clean = [];

		if ( array_key_exists( 'on', $entry ) ) {
			$clean['on'] = (bool) $entry['on'];
		}

		if ( isset( $entry['grp'] ) && is_string( $entry['grp'] ) && 1 === preg_match( Registry::GROUP_PATTERN, $entry['grp'] ) ) {
			$clean['grp'] = $entry['grp'];
		}

		foreach ( is_array( $entry['tr'] ?? null ) ? $entry['tr'] : [] as $lang => $segs ) {
			$segs = is_string( $segs ) ? self::clean_segments( $segs ) : null;

			if ( is_string( $lang ) && self::is_lang( $lang ) && null !== $segs ) {
				$clean['tr'][ $lang ] = $segs;
			}
		}

		foreach ( is_array( $entry['hist'] ?? null ) ? $entry['hist'] : [] as $lang => $list ) {
			if ( ! is_string( $lang ) || ! self::is_lang( $lang ) || ! is_array( $list ) ) {
				continue;
			}

			$hist = [];

			foreach ( $list as $segs ) {
				$segs = is_string( $segs ) ? self::clean_segments( $segs ) : null;

				if ( null !== $segs && ! in_array( $segs, $hist, true ) && $segs !== ( $clean['tr'][ $lang ] ?? null ) ) {
					$hist[] = $segs;
				}
			}

			if ( [] !== $hist ) {
				$clean['hist'][ $lang ] = array_slice( $hist, 0, self::HISTORY_CAP );
			}
		}

		$reg = [];

		foreach ( is_array( $entry['reg'] ?? null ) ? $entry['reg'] : [] as $segs ) {
			$segs = is_string( $segs ) ? self::clean_segments( $segs ) : null;

			if ( null !== $segs && ! in_array( $segs, $reg, true ) ) {
				$reg[] = $segs;
			}
		}

		if ( [] !== $reg ) {
			$clean['reg'] = array_slice( $reg, 0, self::REG_CAP );
		}

		return $clean;
	}

	/**
	 * A value already in norm form and within the length rules, or null.
	 *
	 * @param string $segs Value.
	 * @return string|null
	 */
	public static function clean_segments( string $segs ): ?string {
		$parts = explode( '/', trim( $segs, '/' ) );

		if ( count( $parts ) > self::MAX_SEGMENTS ) {
			return null;
		}

		foreach ( $parts as $part ) {
			if ( '' === $part || strlen( $part ) > self::MAX_SEGMENT_BYTES || Validator::norm( $part ) !== $part || Validator::guard_hit( $part ) ) {
				return null;
			}
		}

		return implode( '/', $parts );
	}

	/**
	 * Whether a value is an address in norm form: non-empty segments past the guard, at most MAX_ADDRESS_BYTES.
	 *
	 * @param string $segs Value.
	 * @return bool
	 */
	public static function is_address( string $segs ): bool {
		if ( '' === $segs || strlen( $segs ) > self::MAX_ADDRESS_BYTES ) {
			return false;
		}

		foreach ( explode( '/', $segs ) as $part ) {
			if ( '' === $part || Validator::norm( $part ) !== $part || Validator::guard_hit( $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A language slug shape (LanguageRepository::SLUG_PATTERN).
	 *
	 * @param string $lang Slug.
	 * @return bool
	 */
	public static function is_lang( string $lang ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/', $lang );
	}
}
