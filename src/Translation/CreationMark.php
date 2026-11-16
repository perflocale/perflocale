<?php
/**
 * Creation mark of a translation a machine translation or an XLIFF import creates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Enum\SourceType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A marked creation: an intent row before the insert, the token in the posts INSERT (guid) and a meta mark, a seal, a
 * fenced claim before the link. The next marked creation of the pair recovers a stopped one: never trashed, kept and
 * reported for a person. One statement per step, no transaction; loaded on first use.
 *
 * @since 1.0.7.5
 * @internal
 */
final class CreationMark {

	/** Post meta key: "<source id>:<target slug>:<time>:<token>", sealed with ":<row fp>:<full fp>". */
	public const KEY = '_perflocale_xliff_creating';

	/** Intent option prefix; the value is "<state c|l|r>|<token>|<start>|<id floor>|<insert fp>". */
	private const INTENT = 'perflocale_creating_';

	/** Query argument of the guid the posts INSERT carries until the mark row is written (core then writes the permalink). */
	private const GUID = 'perflocale_creating=';

	/** TranslationService::PENDING_META_KEY, a literal so no import loads that class. */
	private const MT_PENDING = '_perflocale_mt_pending';

	/** Insert columns no step of the creation rewrites before the seal (guid, post_name and pings may change). */
	private const INSERT_COLUMNS = [ 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt', 'post_status', 'post_type', 'comment_status', 'ping_status', 'post_password', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_mime_type' ];

	/**
	 * Open creations' link verdicts, by token: may_link() asks each.
	 *
	 * @var array<string, \Closure(int): bool>
	 */
	private static array $gates = [];

	/**
	 * Report rows this request wrote and has not acknowledged, by intent name: row name => value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $reported = [];

	/**
	 * True while a caller lists the reports elsewhere (a bulk job: the Jobs page panel), so acknowledge() keeps them.
	 *
	 * @var bool
	 */
	private static bool $held = false;

	/**
	 * Run create_translation() as a marked creation; a stopped creation of the pair is recovered first.
	 *
	 * @param PostTranslationManager  $manager      Manager.
	 * @param int                     $source_id    Source post.
	 * @param string                  $target_slug  Target language slug.
	 * @param bool                    $copy_content As create_translation().
	 * @param SourceType              $source       As create_translation().
	 * @param bool|null               $created      As create_translation().
	 * @param array<int, string>|null $recovered    Set to draft id => outcome (see recover()) for the caller to report.
	 * @param-out array<int, string>    $recovered
	 * @return int|false As create_translation(); false also when the intent could not be written or the claim was lost.
	 */
	public static function create( PostTranslationManager $manager, int $source_id, string $target_slug, bool $copy_content = false, SourceType $source = SourceType::Manual, ?bool &$created = null, ?array &$recovered = null ): int|false {
		global $wpdb;

		$recovered = [];

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			$token = '';
		}

		// As core's sanitize_post() will store it, so the insert is recognised by it.
		$guid = '' !== $token ? sanitize_post_field( 'guid', home_url( '/?' . self::GUID . $token ), 0, 'db' ) : '';

		if ( ! $wpdb instanceof \wpdb || ! is_string( $guid ) || ! str_contains( $guid, self::GUID . $token ) ) {
			return $manager->create_translation( $source_id, $target_slug, $copy_content, $source, $created );
		}

		$name  = self::intent_name( $source_id, $target_slug );
		$mark  = $source_id . ':' . $target_slug . ':' . time() . ':' . $token;
		$state = [
			'armed'  => false,
			'refuse' => false,
			'intent' => '',
			'insert' => '',
			'id'     => 0,
			'held'   => $mark,
			'lost'   => false,
		];

		// Inside the pair's creation lock, right before wp_insert_post(): recover, then write this creation's intent.
		$prepare = static function ( $data, $for_source = 0, $for_slug = '' ) use ( $source_id, $target_slug, $token, $guid, $mark, &$state, &$recovered, $wpdb ) {
			if ( ! is_array( $data ) || $state['armed'] || $for_source !== $source_id || $for_slug !== $target_slug ) {
				return $data;
			}

			$state['armed'] = true;
			$recovered      = self::recover( $source_id, $target_slug );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The id floor recovery searches above.
			$floor = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(ID) FROM %i', $wpdb->posts ) );
			$value = 'c|' . $token . '|' . time() . '|' . ( is_numeric( $floor ) ? (int) $floor : 0 ) . '|';

			// Never through the options API: read and compared as stored.
			$sql = $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::INTENT . $source_id . '_' . $target_slug, $value, 'no' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if ( ! self::failed() && null !== $sql && 1 === $wpdb->query( $sql ) ) {
				$state['intent'] = $value;
			} else {
				$state['refuse'] = true;
			}

			$data['guid']                    = $guid;
			$data['meta_input']              = is_array( $data['meta_input'] ?? null ) ? $data['meta_input'] : [];
			$data['meta_input'][ self::KEY ] = $mark;

			return $data;
		};

		// This creation's insert (by its guid) is refused when no intent row was written.
		$refuse = static function ( $maybe_empty, $postarr = [] ) use ( $guid, &$state ) {
			return $state['refuse'] && is_array( $postarr ) && ( $postarr['guid'] ?? '' ) === $guid ? true : $maybe_empty;
		};

		// The final INSERT data: its fingerprint proves an unsealed draft untouched.
		$inserting = static function ( $data ) use ( $guid, $name, &$state ): void {
			if ( ! is_array( $data ) || '' === $state['intent'] || '' !== $state['insert'] || ( $data['guid'] ?? '' ) !== $guid ) {
				return;
			}

			$state['insert'] = self::insert_fp( $data );

			if ( self::swap( $name, $state['intent'], $state['intent'] . $state['insert'] ) ) {
				$state['intent'] .= $state['insert'];
			}
		};

		// The mark row is written: the post is found by it now, so core's own guid step runs as without the mark.
		$marked = static function ( $meta_id, $object_id, $meta_key, $value ) use ( $mark, $guid, &$state, $wpdb ): void {
			if ( self::KEY !== $meta_key || $mark !== $value || ! is_numeric( $object_id ) || 0 !== $state['id'] ) {
				return;
			}

			$state['id'] = (int) $object_id;

			$where = [
				'ID'   => $state['id'],
				'guid' => $guid,
			];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set on this creation's own guid.
			$wpdb->update( $wpdb->posts, [ 'guid' => '' ], $where, [ '%s' ], [ '%d', '%s' ] );
			clean_post_cache( $state['id'] );
		};

		// Sealed with the state the insert left; registered after the machine translation's pending seal, so it includes it.
		$seal = static function ( $post_id, $post = null, $update = false ) use ( $mark, &$state ): void {
			if ( $update || ! is_numeric( $post_id ) || (int) $post_id !== $state['id'] ) {
				return;
			}

			$row = self::stored_row( $state['id'] );

			// The full fingerprint only while the row still equals the INSERT (none before WordPress 6.9: no pre_post_insert).
			if ( null !== $row ) {
				$full   = '' !== $state['insert'] && hash_equals( $state['insert'], self::insert_fp( $row ) ) ? self::full_fp( $state['id'] ) : null;
				$sealed = $mark . ':' . self::row_fp( $row ) . ':' . ( $full ?? '' );

				if ( update_post_meta( $state['id'], self::KEY, $sealed, $mark ) ) {
					$state['held'] = $sealed;
				}
			}
		};

		// Right before link_posts(): the claim of this creation's intent, then the pair's slot must still be free (a creator
		// after a lock takeover may have linked its own); lost, nothing is linked.
		self::$gates[ $token ] = static function ( int $new_id ) use ( $source_id, $target_slug, $guid, $name, &$state ): bool {
			if ( 0 === $state['id'] && self::guid_of( $new_id ) === $guid ) {
				$state['id'] = $new_id;
			}

			if ( $new_id !== $state['id'] || '' === $state['intent'] ) {
				return true;
			}

			// The claim carries its own time: a recovery lease runs from the claim, not from the creation's start.
			$parts    = explode( '|', $state['intent'] );
			$parts[0] = 'l';
			$parts[2] = (string) time();
			$linking  = implode( '|', $parts );

			if ( self::swap( $name, $state['intent'], $linking ) ) {
				$state['intent'] = $linking;
				$holder          = self::slot_holder( $source_id, $target_slug );

				if ( 0 === $holder || $new_id === $holder ) {
					return true;
				}

				// The slot is another's: revoked, then removed by finish(), as this creator reports its own draft.
				if ( self::swap( $name, $linking, 'r' . substr( $linking, 1 ) ) ) {
					$state['intent'] = 'r' . substr( $linking, 1 );
				}
			}

			$state['lost'] = true;

			return false;
		};

		add_filter( 'perflocale/translation/post_data', $prepare, PHP_INT_MAX, 3 );
		add_filter( 'wp_insert_post_empty_content', $refuse, PHP_INT_MAX, 2 );
		add_action( 'pre_post_insert', $inserting, PHP_INT_MAX, 1 );
		add_action( 'added_post_meta', $marked, 10, 4 );
		add_action( 'wp_after_insert_post', $seal, PHP_INT_MAX, 3 );

		try {
			$translation = $manager->create_translation( $source_id, $target_slug, $copy_content, $source, $created );

			// Claimed but not linked (the insert-only link was refused): lost too.
			if ( false === $translation && $state['id'] > 0 && str_starts_with( $state['intent'], 'l|' ) && false === self::linked( $state['id'] ) ) {
				$state['lost'] = true;
			}
		} finally {
			unset( self::$gates[ $token ] );
			remove_filter( 'perflocale/translation/post_data', $prepare, PHP_INT_MAX );
			remove_filter( 'wp_insert_post_empty_content', $refuse, PHP_INT_MAX );
			remove_action( 'pre_post_insert', $inserting, PHP_INT_MAX );
			remove_action( 'added_post_meta', $marked, 10 );
			remove_action( 'wp_after_insert_post', $seal, PHP_INT_MAX );

			$pending = self::finish( $name, $guid, $state, $translation ?? false );
		}

		// Created, but the token guid could not be replaced: retried by recovery (state g).
		if ( $pending && is_int( $translation ) && $translation > 0 ) {
			$recovered[ $translation ] = 'guid_pending';
		}

		// Lost the claim or the slot: the draft is kept and reported here.
		if ( $state['lost'] && $state['id'] > 0 && 'trash' !== get_post_status( $state['id'] ) ) {
			$recovered[ $state['id'] ] = 'left';
		}

		return $translation;
	}

	/**
	 * Whether a marked creation is open in this request (its link is insert-only).
	 *
	 * @return bool
	 */
	public static function gated(): bool {
		return [] !== self::$gates;
	}

	/**
	 * Whether the open marked creations let do_create_translation() link this new post (false: a lost claim).
	 *
	 * @param int $new_id Post the insert returned.
	 * @return bool
	 */
	public static function may_link( int $new_id ): bool {
		foreach ( self::$gates as $gate ) {
			if ( ! $gate( $new_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recover the pair's stopped creation under its creation lock, also when the pair was linked since (a resumed job's
	 * stopped pair); an empty answer when the lock is held or nothing was stopped.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @return array<int, string> As create()'s $recovered.
	 */
	public static function recover_pair( int $source_id, string $target_slug ): array {
		if ( null === self::read_intent( $source_id, $target_slug ) ) {
			return [];
		}

		/**
		 * The lock's answer: null when another process holds it.
		 *
		 * @var array<int, string>|null $out
		 */
		$out = \PerfLocale\Concurrency\Lock::with( 'create_xlat_post_' . $source_id . '_' . $target_slug, 60, static fn(): array => self::recover( $source_id, $target_slug ) );

		return is_array( $out ) ? $out : [];
	}

	/**
	 * The existing post that holds the pair's language slot in the source's post group, read from the tables; 0 when
	 * free, -1 when the read failed.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @return int
	 */
	private static function slot_holder( int $source_id, string $target_slug ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return -1;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Never the link caches.
		$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT l2.object_id FROM %i l1 INNER JOIN %i g ON g.id = l1.group_id AND g.type = %s INNER JOIN %i l2 ON l2.group_id = l1.group_id INNER JOIN %i lang ON lang.id = l2.language_id INNER JOIN %i p ON p.ID = l2.object_id WHERE l1.object_id = %d AND lang.slug = %s LIMIT 1', \PerfLocale\Database\Schema::table( 'translation_links' ), \PerfLocale\Database\Schema::table( 'translation_groups' ), 'post', \PerfLocale\Database\Schema::table( 'translation_links' ), \PerfLocale\Database\Schema::table( 'languages' ), $wpdb->posts, $source_id, $target_slug ) );

		if ( '' !== $wpdb->last_error ) {
			return -1;
		}

		return is_numeric( $holder ) ? (int) $holder : 0;
	}

	/**
	 * After create_translation(): a finished creation removes its mark and intent; a stopped one keeps both for recovery.
	 *
	 * @param string               $name        Intent option name.
	 * @param string               $guid        This creation's guid.
	 * @param array<string, mixed> $state       The creation's state.
	 * @param int|false            $translation create_translation()'s answer (false also when it threw).
	 * @return bool True when the guid reset is still pending (the intent waits in state g).
	 */
	private static function finish( string $name, string $guid, array $state, int|false $translation ): bool {
		global $wpdb;

		$id     = is_int( $state['id'] ) ? $state['id'] : 0;
		$intent = is_string( $state['intent'] ) ? $state['intent'] : '';
		$held   = is_string( $state['held'] ) ? $state['held'] : '';

		// No mark row written: the guid is still this creation's.
		$reset = 0 === $id || self::reset_guid( $id, $guid );

		if ( '' === $intent ) {
			return false;
		}

		// The intent goes, or waits in state g (guid repair) while the token guid could not be replaced.
		$end = static function () use ( $name, $intent, $reset ): bool {
			$parts    = explode( '|', $intent );
			$parts[0] = 'g';
			$parts[2] = (string) time();

			self::swap( $name, $intent, $reset ? null : implode( '|', $parts ) );

			return ! $reset;
		};

		// Lost (the slot is another's): this creator reports its draft, so its own intent goes; a recovery may own it.
		if ( true === $state['lost'] ) {
			if ( $id > 0 ) {
				self::keep( $name, (string) ( explode( '|', $intent )[1] ?? '' ), [ $id => 'left' ] );
			}

			return $end();
		}

		if ( $id > 0 && $translation === $id ) {
			delete_post_meta( $id, self::KEY, $held );

			return $end();
		}

		// A draft left behind (a throw before the link), or one that could not be read: the next creation recovers it.
		if ( $id > 0 && ( null !== self::stored_row( $id ) || self::failed() ) ) {
			return false;
		}

		if ( 0 === $id ) {
			$floor = (int) ( explode( '|', $intent )[3] ?? 0 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The insert's own guid, above its id floor (a primary key range).
			$found = $wpdb instanceof \wpdb ? $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID > %d AND guid = %s LIMIT 1', $wpdb->posts, $floor, $guid ) ) : '1';

			// Unknown, or a post that carries the guid: left to recovery.
			if ( null !== $found || ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) ) {
				return false;
			}
		}

		self::swap( $name, $intent, null );

		return false;
	}

	/**
	 * Recover the pair's stopped creation, if its intent row is there. Runs under the pair's creation lock.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @return array<int, string> Draft id => 'stopped' (unchanged since the stop), 'edited', 'kept' (could not be proven
	 *                            unchanged), 'cannot_delete' or 'unchecked' (a read failed: the intent stays; draft 0
	 *                            when the discovery failed); never trashed (a person decides).
	 */
	private static function recover( int $source_id, string $target_slug ): array {
		$name  = self::intent_name( $source_id, $target_slug );
		$value = null;

		// One retry: the creator may move its own intent on between the read and the revoke.
		for ( $try = 0; $try < 2 && null === $value; $try++ ) {
			$read = self::read_intent( $source_id, $target_slug );

			if ( null === $read ) {
				return [];
			}

			$parts = explode( '|', $read );

			// Not this format: owns nothing, and would refuse every later creation of the pair.
			if ( count( $parts ) < 5 || 1 !== preg_match( '/^[a-f0-9]{32}$/', $parts[1] ) || ! in_array( $parts[0], [ 'c', 'l', 'r', 'g' ], true ) ) {
				self::swap( $name, $read, null );

				return [];
			}

			if ( 'c' !== $parts[0] ) {
				$value = $read;
			} elseif ( self::swap( $name, $read, 'r' . substr( $read, 1 ) ) ) {
				$value = 'r' . substr( $read, 1 );
			}
		}

		if ( null === $value ) {
			return [];
		}

		[ $status, $token, $start, $floor, $insert ] = explode( '|', $value );
		$out   = [];
		$found = self::candidates( $source_id, $target_slug, $token, (int) $floor );

		// A failed read never empties the record: the intent stays for the next check.
		if ( null === $found ) {
			return [ 0 => 'unchecked' ];
		}

		// A finished creation whose guid reset failed: the same conditional reset again, verified.
		if ( 'g' === $status ) {
			foreach ( $found as $id ) {
				$row  = self::stored_row( $id );
				$held = null !== $row ? $row['guid'] ?? '' : '';

				if ( null === $row ? self::failed() : str_contains( $held, self::GUID . $token ) && ! self::reset_guid( $id, $held ) ) {
					return [];
				}
			}

			self::swap( $name, $value, null );

			return [];
		}

		// A young claim with an unlinked draft: its creator may still link; this creation is refused once instead.
		if ( 'l' === $status && time() - (int) $start < \PerfLocale\Background\JobResume::LEASE ) {
			foreach ( $found as $id ) {
				if ( true !== self::linked( $id ) ) {
					return [];
				}
			}
		}

		foreach ( $found as $id ) {
			$outcome = self::settle( $id, $source_id, $token, $status, $insert );

			if ( null !== $outcome ) {
				$out[ $id ] = $outcome;
			}
		}

		// The report outlives this request (its own row) until a caller has shown it; else the intent stays.
		if ( ! in_array( 'unchecked', $out, true ) && ( [] === $out || self::keep( $name, $token, $out ) ) ) {
			self::swap( $name, $value, null );
		}

		return $out;
	}

	/**
	 * Store a report of drafts in its own row (`<intent>_k<token>`, state k), remembered for acknowledge().
	 *
	 * @param string             $name     Intent name.
	 * @param string             $token    The stopped creation's token.
	 * @param array<int, string> $outcomes Draft id => outcome.
	 * @return bool
	 */
	private static function keep( string $name, string $token, array $outcomes ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$row   = $name . '_k' . $token;
		$value = 'k|' . $token . '|' . time() . '|' . wp_json_encode( $outcomes );

		$sql = $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $row, $value, 'no' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Never through the options API (compared as stored); prepared above.
		$written = null !== $sql ? $wpdb->query( $sql ) : false;

		if ( 1 !== $written || self::failed() ) {
			return false;
		}

		self::$reported[ $name ][ $row ] = $value;

		return true;
	}

	/**
	 * The pair's reports this request wrote have been shown: their rows go (compare-and-set), unless held.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @return void
	 */
	public static function acknowledge( int $source_id, string $target_slug ): void {
		$name = self::intent_name( $source_id, $target_slug );

		if ( self::$held ) {
			return;
		}

		foreach ( self::$reported[ $name ] ?? [] as $row => $value ) {
			self::swap( $row, $value, null );
		}

		unset( self::$reported[ $name ] );
	}

	/**
	 * Keep this request's reports for the Jobs page list (a bulk job shows only the first in its result).
	 *
	 * @return void
	 */
	public static function hold_reports(): void {
		self::$held = true;
	}

	/**
	 * Reports not acknowledged, oldest first, for the Jobs page list.
	 *
	 * @param int $limit Most rows.
	 * @param int $after Only rows after this option_id (0 for the start).
	 * @param int $next  Set to the last option_id read when the page was full (more may follow), else 0.
	 * @return array<int, array{name: string, value: string, source: int, lang: string, at: int, drafts: array<int, string>}>
	 */
	public static function kept( int $limit = 50, int $after = 0, int &$next = 0 ): array {
		global $wpdb;

		$next = 0;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$limit = max( 1, $limit );

		// The prefix unescaped (no LIKE escape on SQLite): the name pattern below filters; the option_name key bounds the scan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rows other requests wrote.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_name, option_value FROM %i WHERE option_name LIKE %s AND option_value LIKE %s AND option_id > %d ORDER BY option_id LIMIT %d', $wpdb->options, self::INTENT . '%', 'k|%', max( 0, $after ), $limit ), ARRAY_A );
		$out  = [];

		if ( count( $rows ) >= $limit ) {
			$last = end( $rows );
			$next = max( 0, (int) self::text( $last['option_id'] ?? '' ) );
		}

		foreach ( $rows as $row ) {
			$parsed = self::parse_report( self::text( $row['option_name'] ?? '' ), self::text( $row['option_value'] ?? '' ) );

			if ( null !== $parsed ) {
				$out[] = $parsed;
			}
		}

		return $out;
	}

	/**
	 * Daily: finish up to $batch waiting guid resets (state g), oldest first; each pair under its creation lock.
	 *
	 * @param int $batch Most rows per run.
	 * @return int Rows handled.
	 */
	public static function sweep( int $batch = 50 ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rows other requests wrote.
		$names = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_value LIKE %s ORDER BY option_id LIMIT %d', $wpdb->options, self::INTENT . '%', 'g|%', max( 1, $batch ) ) );
		$done  = 0;

		foreach ( $names as $name ) {
			if ( 1 === preg_match( '/^' . preg_quote( self::INTENT, '/' ) . '(\d+)_(.+)$/', self::text( $name ), $m ) ) {
				self::recover_pair( (int) $m[1], $m[2] );
				++$done;
			}
		}

		return $done;
	}

	/**
	 * One report row as stored, or null.
	 *
	 * @param string $name Report row name.
	 * @return array{name: string, value: string, source: int, lang: string, at: int, drafts: array<int, string>}|null
	 */
	public static function report( string $name ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! str_starts_with( $name, self::INTENT ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As stored now.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );

		return is_string( $value ) ? self::parse_report( $name, $value ) : null;
	}

	/**
	 * A person's Move to trash from the list: re-checked (still listed, unlinked, deletable), then off the report.
	 *
	 * @param string $name  Report row name.
	 * @param int    $draft Draft the report lists.
	 * @return string 'trashed', 'gone' (not listed), 'linked', 'denied' or 'failed'.
	 */
	public static function trash_kept( string $name, int $draft ): string {
		$report = self::report( $name );

		if ( null === $report || ! isset( $report['drafts'][ $draft ] ) ) {
			return 'gone';
		}

		$linked = self::linked( $draft );

		if ( false !== $linked ) {
			return null === $linked ? 'failed' : 'linked';
		}

		if ( ! \PerfLocale\Helper::user_can_delete_object( $draft ) ) {
			return 'denied';
		}

		if ( 'trash' !== get_post_status( $draft ) && ! self::trash( $draft ) ) {
			return 'failed';
		}

		$left = array_diff_key( $report['drafts'], [ $draft => true ] );
		$rest = [] === $left ? null : implode( '|', array_slice( explode( '|', $report['value'], 4 ), 0, 3 ) ) . '|' . wp_json_encode( $left );

		self::swap( $name, $report['value'], $rest );

		return 'trashed';
	}

	/**
	 * A report row's fields, or null when the name or value is not a report.
	 *
	 * @param string $name  Row name.
	 * @param string $value Row value.
	 * @return array{name: string, value: string, source: int, lang: string, at: int, drafts: array<int, string>}|null
	 */
	private static function parse_report( string $name, string $value ): ?array {
		$parts = explode( '|', $value, 4 );
		$found = json_decode( $parts[3] ?? '', true );

		if ( 'k' !== $parts[0] || 1 !== preg_match( '/^' . preg_quote( self::INTENT, '/' ) . '(\\d+)_(.+)_k[a-f0-9]{32}$/', $name, $m ) || ! is_array( $found ) ) {
			return null;
		}

		$drafts = [];

		foreach ( $found as $draft => $outcome ) {
			if ( is_numeric( $draft ) && is_string( $outcome ) ) {
				$drafts[ (int) $draft ] = $outcome;
			}
		}

		return [
			'name'   => $name,
			'value'  => $value,
			'source' => (int) $m[1],
			'lang'   => $m[2],
			'at'     => (int) ( $parts[2] ?? 0 ),
			'drafts' => $drafts,
		];
	}

	/**
	 * Remove one listed report (a person dismissed it, or its drafts are gone), compare-and-set.
	 *
	 * @param string $name  Report row name.
	 * @param string $value Its value as listed.
	 * @return bool
	 */
	public static function dismiss( string $name, string $value ): bool {
		return str_starts_with( $name, self::INTENT ) && str_starts_with( $value, 'k|' ) && self::swap( $name, $value, null );
	}

	/**
	 * What recovery does with one candidate of a stopped creation; null when it is not an unlinked draft of that creation.
	 *
	 * @param int    $id        Candidate post.
	 * @param int    $source_id Source post.
	 * @param string $token     The stopped creation's token.
	 * @param string $status    Its intent state: 'r' (revoked) or 'l' (it claimed the link).
	 * @param string $insert    Its insert fingerprint, or ''.
	 * @return string|null
	 */
	private static function settle( int $id, int $source_id, string $token, string $status, string $insert ): ?string {
		$row = self::stored_row( $id );

		if ( null === $row && self::failed() ) {
			return 'unchecked';
		}

		$mark = self::mark_of( $id, $token );

		if ( false === $mark ) {
			return 'unchecked';
		}

		$guid = $row['guid'] ?? '';

		// Its token in the guid (the INSERT's) or in the mark; anything else is another post.
		if ( null === $row || ( ! str_contains( $guid, self::GUID . $token ) && null === $mark ) ) {
			return null;
		}

		$guid = str_contains( $guid, self::GUID . $token ) ? $guid : '';

		$linked = self::linked( $id );

		if ( null === $linked ) {
			return 'unchecked';
		}

		if ( true === $linked ) {
			self::reset_guid( $id, $guid );

			return null;
		}

		$parts = null !== $mark ? explode( ':', $mark ) : [];
		$seal  = $parts[5] ?? '';

		// Sealed without the full fingerprint (the row had changed, or no pre_post_insert): never proven.
		if ( isset( $parts[4] ) && '' === $seal ) {
			$insert = '';
		}

		$outcome = self::verdict( $id, $source_id, $row, $linked, $status, $seal, $insert );

		self::reset_guid( $id, $guid );

		// Never trashed here: a link, an edit or a child can land between any check and the trash (h8b High 1). An
		// unchanged draft is reported as such; a person decides.
		if ( 'unchanged' === $outcome ) {
			$again   = self::linked( $id );
			$outcome = null === $again ? 'unchecked' : ( false === $again ? 'stopped' : 'kept' );
		}

		// Kept without delete rights, the mark stays as evidence for an editor.
		if ( null !== $mark && 'cannot_delete' !== $outcome && current_user_can( 'edit_post', $id ) ) {
			delete_post_meta( $id, self::KEY, $mark );
		}

		return $outcome;
	}

	/**
	 * The verdict on an unlinked candidate before the trash: 'unchanged', or why it is kept.
	 *
	 * @param int                   $id        Candidate post.
	 * @param int                   $source_id Source post.
	 * @param array<string, string> $row       Its stored row.
	 * @param bool|null             $linked    false: no link row; null: the read failed.
	 * @param string                $status    Intent state.
	 * @param string                $seal      Full fingerprint from the seal, or ''.
	 * @param string                $insert    Insert fingerprint, or ''.
	 * @return string
	 */
	private static function verdict( int $id, int $source_id, array $row, ?bool $linked, string $status, string $seal, string $insert ): string {
		// A claimed link: the creator may still write it.
		if ( null === $linked || 'r' !== $status || ! in_array( $row['post_status'] ?? '', [ 'draft', 'pending' ], true ) ) {
			return 'kept';
		}

		if ( ! current_user_can( 'edit_post', $source_id ) || ! \PerfLocale\Helper::user_can_delete_object( $id ) ) {
			return 'cannot_delete';
		}

		$children = self::has_children( $id );

		if ( false !== $children ) {
			return null === $children ? 'unchecked' : 'edited';
		}

		if ( self::proven( $id, $seal, $insert ) ) {
			return 'unchanged';
		}

		return '' !== $seal ? 'edited' : 'kept';
	}

	/**
	 * Whether a stopped creation's draft is provably as that creation left it: its seal, else (stopped before the seal)
	 * its INSERT with no meta but the creation's and no terms but the type's defaults.
	 *
	 * @param int    $id     Draft.
	 * @param string $seal   Full fingerprint from the seal, or ''.
	 * @param string $insert Insert fingerprint, or ''.
	 * @return bool
	 */
	private static function proven( int $id, string $seal, string $insert ): bool {
		if ( '' !== $seal ) {
			$full = self::full_fp( $id );

			return null !== $full && hash_equals( $seal, $full );
		}

		$row = self::stored_row( $id );

		return '' !== $insert && null !== $row && hash_equals( $insert, self::insert_fp( $row ) )
			&& self::only_own_meta( $id ) && self::only_default_terms( $id, $row['post_type'] ?? '' );
	}

	/**
	 * The stopped creation's posts: its guid above the id floor, and its mark rows.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @param string $token       Token.
	 * @param int    $floor       MAX(ID) before its insert.
	 * @return array<int, int>|null Null when a read failed.
	 */
	private static function candidates( int $source_id, string $target_slug, string $token, int $floor ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rows another process wrote: never a cache.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID > %d AND guid LIKE %s', $wpdb->posts, $floor, '%' . $wpdb->esc_like( $token ) ) );

		if ( self::failed() ) {
			return null;
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_value FROM %i WHERE meta_key = %s AND meta_value LIKE %s', $wpdb->postmeta, self::KEY, $wpdb->esc_like( $source_id . ':' . $target_slug . ':' ) . '%' ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		foreach ( $rows as $row ) {
			if ( is_string( $row['meta_value'] ?? null ) && ( explode( ':', $row['meta_value'] )[3] ?? '' ) === $token ) {
				$ids[] = $row['post_id'] ?? 0;
			}
		}

		$ids = array_values( array_unique( array_map( 'intval', array_filter( $ids, 'is_numeric' ) ) ) );

		return array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * The stored mark of this token on a post: null when none, false when the read failed.
	 *
	 * @param int    $id    Post.
	 * @param string $token Token.
	 * @return string|false|null
	 */
	private static function mark_of( int $id, string $token ): string|false|null {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As stored now.
		$values = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $id, self::KEY ) );

		if ( self::failed() ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( is_string( $value ) && ( explode( ':', $value )[3] ?? '' ) === $token ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Whether a post is in any post translation group, read from the tables; null when the read failed.
	 *
	 * @param int $id Post.
	 * @return bool|null
	 */
	private static function linked( int $id ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Never the link caches: another process may have linked it.
		$link = $wpdb->get_var( $wpdb->prepare( 'SELECT l.id FROM %i l INNER JOIN %i g ON g.id = l.group_id WHERE l.object_id = %d AND g.type = %s LIMIT 1', \PerfLocale\Database\Schema::table( 'translation_links' ), \PerfLocale\Database\Schema::table( 'translation_groups' ), $id, 'post' ) );

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return null !== $link;
	}

	/**
	 * Whether a post has a child post (a revision a save made, an attachment); null when the read failed.
	 *
	 * @param int $id Post.
	 * @return bool|null
	 */
	private static function has_children( int $id ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As stored now.
		$child = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_parent = %d LIMIT 1', $wpdb->posts, $id ) );

		return '' !== $wpdb->last_error ? null : null !== $child;
	}

	/**
	 * Whether a draft holds no meta but the creation's own (its mark, the machine translation's pending mark).
	 *
	 * @param int $id Draft.
	 * @return bool
	 */
	private static function only_own_meta( int $id ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As stored now.
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE post_id = %d', $wpdb->postmeta, $id ) );

		return ! self::failed() && [] === array_diff( array_map( self::text( ... ), (array) $keys ), [ self::KEY, self::MT_PENDING ] );
	}

	/**
	 * Whether a draft holds no term but its type's default ones (what wp_insert_post() assigns before the meta).
	 *
	 * @param int    $id   Draft.
	 * @param string $type Its post type.
	 * @return bool
	 */
	private static function only_default_terms( int $id, string $type ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As stored now.
		$terms = $wpdb->get_results( $wpdb->prepare( 'SELECT tt.term_id, tt.taxonomy FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d', $wpdb->term_relationships, $wpdb->term_taxonomy, $id ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		foreach ( (array) $terms as $term ) {
			$taxonomy = is_string( $term['taxonomy'] ?? null ) ? $term['taxonomy'] : '';
			$term_id  = is_numeric( $term['term_id'] ?? null ) ? (int) $term['term_id'] : 0;
			$default  = 'category' === $taxonomy ? get_option( 'default_category' ) : get_option( 'default_term_' . $taxonomy );

			if ( '' === $taxonomy || ! is_object_in_taxonomy( $type, $taxonomy ) || ! is_numeric( $default ) || (int) $default !== $term_id ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Move a stopped creation's unlinked draft to the trash, without the default language save_post would give it.
	 *
	 * @param int $id Draft.
	 * @return bool False when the site has no trash (wp_trash_post() would delete for good) or the trash refused it.
	 */
	public static function trash( int $id ): bool {
		if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
			return false;
		}

		$assign = [ \PerfLocale\Bootstrap::class, 'auto_assign_default_language' ];
		$had    = remove_action( 'save_post', $assign, 5 );

		try {
			$trashed = wp_trash_post( $id );
		} finally {
			if ( $had ) {
				add_action( 'save_post', $assign, 5, 2 );
			}
		}

		return $trashed instanceof \WP_Post;
	}

	/**
	 * The marks of this pair at least $min_age seconds old (a lease: a stopped worker's), one indexed read.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @param int    $min_age     Youngest mark read, in seconds.
	 * @return array<int, array{id: int, mark: string, seal: string, status: string}> 'seal': the row fingerprint, or ''.
	 */
	public static function stale( int $source_id, string $target_slug, int $min_age = \PerfLocale\Background\JobResume::LEASE ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed read, on a resumed job's first pair only.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.post_id, m.meta_value, p.post_status FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value LIKE %s', $wpdb->postmeta, $wpdb->posts, self::KEY, $wpdb->esc_like( $source_id . ':' . $target_slug . ':' ) . '%' ), ARRAY_A );
		$out  = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id    = is_numeric( $row['post_id'] ?? null ) ? (int) $row['post_id'] : 0;
			$mark  = is_string( $row['meta_value'] ?? null ) ? $row['meta_value'] : '';
			$parts = explode( ':', $mark );
			$time  = (int) ( $parts[2] ?? 0 );

			// Younger than a lease: a live job's.
			if ( $id <= 0 || $time <= 0 || time() - $time < $min_age ) {
				continue;
			}

			$out[] = [
				'id'     => $id,
				'mark'   => $mark,
				'seal'   => $parts[4] ?? '',
				'status' => is_string( $row['post_status'] ?? null ) ? $row['post_status'] : '',
			];
		}

		return $out;
	}

	/**
	 * Seal a kept mark again with the row as stored now, after its holder's own write; the mark as stored afterwards.
	 *
	 * The time and token stay, so a resume still reads it as the stopped worker's; the full fingerprint is dropped (it held
	 * the old row). A lost reply is settled by the stored value; a failed read keeps $held.
	 *
	 * @param int    $id   Post ID.
	 * @param string $held The mark as stored.
	 * @return string
	 */
	public static function reseal( int $id, string $held ): string {
		$row   = self::stored_row( $id );
		$parts = explode( ':', $held );

		if ( null === $row || count( $parts ) < 5 ) {
			return $held;
		}

		$sealed = implode( ':', array_slice( $parts, 0, 4 ) ) . ':' . self::row_fp( $row ) . ':';

		if ( $sealed === $held || update_post_meta( $id, self::KEY, $sealed, $held ) ) {
			return $sealed;
		}

		$now = self::mark_of( $id, $parts[3] );

		return is_string( $now ) ? $now : $held;
	}

	/**
	 * Whether the stored row still equals the one the seal fingerprints; no seal proves nothing.
	 *
	 * @param int    $id   Post ID.
	 * @param string $seal Row fingerprint from the mark, or ''.
	 * @return bool
	 */
	public static function unchanged( int $id, string $seal ): bool {
		$row = '' !== $seal ? self::stored_row( $id ) : null;

		return null !== $row && hash_equals( $seal, self::row_fp( $row ) );
	}

	/**
	 * The report line for a recovery outcome (machine translation results and job reports).
	 *
	 * @param int    $draft     The draft.
	 * @param int    $source_id Its source post.
	 * @param string $outcome   An outcome from create()'s $recovered.
	 * @return string
	 */
	public static function message( int $draft, int $source_id, string $outcome ): string {
		return match ( $outcome ) {
			/* translators: 1: ID of the draft, 2: ID of the source post. */
			'trashed'     => sprintf( __( 'An earlier run that stopped left an unlinked draft (post %1$d) of post %2$d, which was moved to the trash. Restore it from the trash if you need it.', 'perflocale' ), $draft, $source_id ),
			/* translators: 1: ID of the draft, 2: ID of the source post. */
			'not_trashed' => sprintf( __( 'An earlier run that stopped left an unlinked draft (post %1$d) of post %2$d, which could not be moved to the trash (the site\'s trash may be turned off), so it was kept. Delete it if you do not need it.', 'perflocale' ), $draft, $source_id ),
			/* translators: 1: ID of the draft, 2: ID of the source post. */
			'edited'      => sprintf( __( 'An earlier run that stopped left an unlinked draft (post %1$d) of post %2$d that may have been edited since, so it was kept. Delete it if you do not need it.', 'perflocale' ), $draft, $source_id ),
			/* translators: 1: ID of the draft, 2: ID of the source post. */
			'cannot_delete' => sprintf( __( 'An earlier run that stopped left an unlinked draft (post %1$d) of post %2$d that you do not have permission to delete. Ask an editor to delete it.', 'perflocale' ), $draft, $source_id ),
			/* translators: 1: ID of the draft, 2: ID of the source post. */
			'stopped'     => sprintf( __( 'An earlier run that stopped left an unlinked draft (post %1$d) of post %2$d, unchanged since, so it was kept. Move it to the trash if you do not need it.', 'perflocale' ), $draft, $source_id ),
			/* translators: %d: ID of the source post. */
			'unchecked'   => 0 === $draft ? sprintf( __( 'An earlier run that stopped on post %d could not be checked (a database read failed). It will be checked again by the next translation of this post.', 'perflocale' ), $source_id )
				/* translators: 1: ID of the draft, 2: ID of the source post. */
				: sprintf( __( 'An earlier run that stopped left an unlinked draft (post %1$d) of post %2$d that could not be checked (a database read failed), so it was kept. It will be checked again by the next translation of this post.', 'perflocale' ), $draft, $source_id ),
			/* translators: 1: ID of the translation, 2: ID of the source post. */
			'guid_pending' => sprintf( __( 'The translation (post %1$d) of post %2$d was created and works, but its temporary guid could not be replaced yet (a database write failed). It is retried when a stopped run of this post is next recovered.', 'perflocale' ), $draft, $source_id ),
			/* translators: 1: ID of the draft, 2: ID of the source post. */
			'left'        => sprintf( __( 'The translation of post %2$d could not be finished, and its unlinked draft (post %1$d) was kept. Delete it if you do not need it.', 'perflocale' ), $draft, $source_id ),
			/* translators: 1: ID of the draft, 2: ID of the source post. */
			default       => sprintf( __( 'An earlier run that stopped left an unlinked draft (post %1$d) of post %2$d that could not be checked, so it was kept. Delete it if you do not need it.', 'perflocale' ), $draft, $source_id ),
		};
	}

	/**
	 * The intent option name of a pair.
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @return string
	 */
	private static function intent_name( int $source_id, string $target_slug ): string {
		return self::INTENT . $source_id . '_' . $target_slug;
	}

	/**
	 * The pair's intent row as stored; null when there is none (or the read failed).
	 *
	 * @param int    $source_id   Source post.
	 * @param string $target_slug Target language slug.
	 * @return string|null
	 */
	private static function read_intent( int $source_id, string $target_slug ): ?string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As stored: never the options cache.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::INTENT . $source_id . '_' . $target_slug ) );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Compare-and-set the intent row: replaced, or deleted with null; true when this call changed it.
	 *
	 * @param string      $name Option name.
	 * @param string      $from The exact stored value.
	 * @param string|null $to   New value, or null to delete.
	 * @return bool
	 */
	private static function swap( string $name, string $from, ?string $to ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// One atomic statement: the fence between a creator and recovery.
		$done = null === $to
			? self::write( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $from )
			: self::write( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $to, $name, $from );

		return 1 === $done;
	}

	/**
	 * A post's guid as stored, or ''.
	 *
	 * @param int $id Post.
	 * @return string
	 */
	private static function guid_of( int $id ): string {
		$row = self::stored_row( $id );

		return null !== $row ? $row['guid'] ?? '' : '';
	}

	/**
	 * Replace this creation's guid with the permalink, as core writes it (compare-and-set), then read it back.
	 *
	 * @param int    $id   Post.
	 * @param string $guid This creation's guid.
	 * @return bool False while the stored guid still holds it (a failed write or read); true also when someone else
	 *              changed it, which is never overwritten.
	 */
	private static function reset_guid( int $id, string $guid ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$row = self::stored_row( $id );

		if ( null === $row || ( $row['guid'] ?? '' ) !== $guid ) {
			return ! self::failed();
		}

		clean_post_cache( $id );
		$permalink = get_permalink( $id );

		$where = [
			'ID'   => $id,
			'guid' => $guid,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set on this creation's own guid.
		$wpdb->update( $wpdb->posts, [ 'guid' => is_string( $permalink ) ? $permalink : '' ], $where, [ '%s' ], [ '%d', '%s' ] );
		clean_post_cache( $id );

		$row = self::stored_row( $id );

		return null === $row ? ! self::failed() : ( $row['guid'] ?? '' ) !== $guid;
	}

	/**
	 * A post's whole row as the database holds it now, every value a string; null when absent or the read failed.
	 *
	 * @param int $id Post ID.
	 * @return array<string, string>|null
	 */
	private static function stored_row( int $id ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row: another request may have saved it.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $id ), ARRAY_A );

		if ( ! is_array( $row ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( $row as $column => $value ) {
			$out[ (string) $column ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $out;
	}

	/**
	 * Fingerprint of the insert columns of a row or of the INSERT data.
	 *
	 * @param array<mixed> $data Row or INSERT data.
	 * @return string
	 */
	private static function insert_fp( array $data ): string {
		$values = [];

		foreach ( self::INSERT_COLUMNS as $column ) {
			$values[] = is_scalar( $data[ $column ] ?? null ) ? (string) $data[ $column ] : '';
		}

		return md5( (string) wp_json_encode( $values ) );
	}

	/**
	 * A row's fingerprint (XLIFF completes a linked creation only while its row is unchanged).
	 *
	 * @param array<string, string> $row Row from stored_row().
	 * @return string
	 */
	private static function row_fp( array $row ): string {
		return md5( (string) wp_json_encode( $row ) );
	}

	/**
	 * Fingerprint of a post's row, its meta but the creation's own two marks, and its terms; null when a read failed.
	 *
	 * @param int $id Post.
	 * @return string|null
	 */
	private static function full_fp( int $id ): ?string {
		global $wpdb;

		$row = self::stored_row( $id );

		if ( null === $row || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As stored now.
		$meta  = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key <> %s AND meta_key <> %s ORDER BY meta_id', $wpdb->postmeta, $id, self::KEY, self::MT_PENDING ), ARRAY_N );
		$error = self::failed();
		$terms = $wpdb->get_col( $wpdb->prepare( 'SELECT term_taxonomy_id FROM %i WHERE object_id = %d ORDER BY term_taxonomy_id', $wpdb->term_relationships, $id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $error || self::failed() ) {
			return null;
		}

		return md5( (string) wp_json_encode( [ $row, (array) $meta, array_map( self::text( ... ), (array) $terms ) ] ) );
	}

	/**
	 * Run one prepared write; false when it failed or could not be prepared.
	 *
	 * @param literal-string $template SQL with placeholders.
	 * @param mixed          ...$args  Values.
	 * @return int|bool Rows affected, as wpdb::query().
	 */
	private static function write( string $template, ...$args ): int|bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $template is one of this class's literal templates.
		$sql = $wpdb instanceof \wpdb ? $wpdb->prepare( $template, ...$args ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; rows another process may hold: never a cache.
		return null !== $sql && $wpdb instanceof \wpdb ? $wpdb->query( $sql ) : false;
	}

	/**
	 * Whether the last statement failed.
	 *
	 * @return bool
	 */
	private static function failed(): bool {
		global $wpdb;

		return ! $wpdb instanceof \wpdb || '' !== $wpdb->last_error;
	}

	/**
	 * A read value as a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
