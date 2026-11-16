<?php
/**
 * The Content ReSync job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Background\Dispatcher;
use PerfLocale\Background\JobCanceledException;
use PerfLocale\Background\JobState;
use PerfLocale\Background\MigrationCacheHelper;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Resync\Engine;
use PerfLocale\Resync\Filler;
use PerfLocale\Resync\Outcome;
use PerfLocale\Resync\PairRunner;
use PerfLocale\Resync\PostReads;
use PerfLocale\Resync\RunOptions;
use PerfLocale\Resync\Scope;
use PerfLocale\Resync\Store;
use PerfLocale\Settings;
use PerfLocale\Util\DbErrorCode;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resyncs translations with their sources, fills missing and empty ones, and records up-to-date translations.
 * Every run acts as the person who started it; each pair checks that person's rights ({@see PairRunner::run()}).
 * Background runs go in slices; cursor and start records live in the run option, so retry and pause resume.
 *
 * @api Stable addon-facing API surface: the type, the arguments and the result keys are semver-bound.
 *
 * @phpstan-type Args array{mode: string, source_ids: list<int>, language_ids: list<int>, query: array{post_types: list<string>, outdated: bool, missing: bool, record_in_sync: bool}, fill: string, only_outdated: bool, machine: bool, trigger: string, run_id: string, dry_run: bool, mt_budget: int|null, own_post: int}
 * @phpstan-type RunState array{started_at: int, cursor: int, current: int, done: list<int>, starts: array<string, int>, counts: array<string, mixed>, total: int}
 * @phpstan-import-type StateRow from \PerfLocale\Database\Repository\ResyncRepository
 * @phpstan-type PageLink array{translation_id: int, status: string, empty: bool, state: bool, narrow: StateRow|null, drift: bool}
 */
final class ContentResyncJob extends AbstractJob {

	/** The job type. */
	public const TYPE = 'content_resync';

	/** The given sources. */
	public const MODE_SOURCES = 'sources';

	/** A selection the job makes itself (the Update translations dialog). */
	public const MODE_QUERY = 'query';

	/** Missing and empty translations are machine translated. */
	public const FILL_MACHINE = 'machine';

	/** Missing translations are created as empty drafts. */
	public const FILL_EMPTY = 'empty';

	/** The run option's prefix (covered by SiteCleanup's `perflocale_resync_%`). */
	public const RUN_OPTION_PREFIX = 'perflocale_resync_run_';

	/** Pairs from which a run goes to the background under "Auto". */
	public const DEFAULT_THRESHOLD = 3;

	/** The job and type lock lifetime, refreshed by the progress ticks. */
	public const LOCK_TTL = 300;

	/** A background slice's budget on a web request, in seconds. */
	public const SLICE_SECONDS = 15;

	/** The smallest slice budget. */
	public const MIN_SLICE_SECONDS = 5;

	/** Most pairs one background slice runs on a web request. */
	public const SLICE_MAX_PAIRS = 200;

	/** An inline run's budget, in seconds. */
	public const INLINE_SECONDS = 20;

	/** An estimate's dry-run budget, in seconds. */
	public const ESTIMATE_SECONDS = 10;

	/** Time a provider request may take: one bounded request (10 s) plus a 2 s margin. */
	public const REQUEST_SECONDS = 12;

	/** Sources per selection page. */
	public const PAGE_SOURCES = 100;

	/** Pairs between two runtime-cache releases under WP-CLI. */
	public const RELEASE_EVERY_PAIRS = 50;

	/**
	 * Share of `memory_limit` in use at which a call stops before its next source or pair
	 * ({@see memory_ceiling()}).
	 */
	public const MEMORY_CEILING = 0.8;

	/** Unfinished starts after which a pair is skipped as too slow. */
	public const MAX_STARTS = 2;

	/**
	 * The page start record's key in `starts`: set before a page's pairs, cleared when the page is left normally.
	 */
	public const PAGE_START = 'page';

	/** Pair outcomes the result lists, at most. */
	public const ITEMS_MAX = 100;

	/** Seconds between two progress reports. */
	private const TICK_SECONDS = 2.0;

	/** A pair's status when the switch stopped it before any write; never tallied. */
	private const OFF = 'off';

	/** A pair's status when the switch could not be read before any write; never tallied. */
	private const UNREAD = 'switch_unread';

	/** Stage keys (the Jobs page labels them). */
	public const STAGE_RESYNC   = 'resync';
	public const STAGE_FILL     = 'fill';
	public const STAGE_CONFIRM  = 'confirm';
	public const STAGE_ESTIMATE = 'estimate';

	/** Outcome statuses and the result key each is counted under. */
	private const STATUS_KEYS = [
		'applied'            => 'applied',
		'held'               => 'held',
		'held_same'          => 'held',
		'unchanged'          => 'unchanged',
		'outdated'           => 'outdated',
		'confirmed'          => 'confirmed',
		'proposal'           => 'proposals',
		'filled'             => 'filled',
		'created'            => 'created',
		'fill'               => 'fills',
		'empty'              => 'empty',
		'missing'            => 'missing',
		'skipped_decided'    => 'skipped',
		'target_gone'        => 'skipped',
		'no_source'          => 'skipped',
		'out_of_scope'       => 'skipped',
		'skipped_permission' => 'skipped_permission',
		'forbidden'          => 'skipped_permission',
		'no_base'            => 'no_base',
		'unsupported'        => 'unsupported',
		'compose_error'      => 'unsupported',
		'needs_estimate'     => 'needs_estimate',
		'mt_unavailable'     => 'needs_mt',
		'limit'              => 'failed',
		'failed'             => 'failed',
		'busy'               => 'busy',
		'stale'              => 'busy',
		'unavailable'        => 'failed',
		'deferred'           => 'deferred',
		'too_slow'           => 'too_slow',
	];

	/** Statuses whose pair was not processed (running the button again picks them up). */
	private const NOT_PROCESSED = [ 'busy', 'stale', 'failed', 'limit', 'unavailable', 'needs_estimate', 'mt_unavailable', 'deferred', 'too_slow' ];

	/** Statuses whose characters were never sent. */
	private const NOT_SENT = [ 'needs_estimate', 'limit' ];

	/**
	 * The run's normalised arguments.
	 *
	 * @var array<string, mixed>
	 * @phpstan-var Args
	 */
	private array $args;

	/**
	 * The result so far.
	 *
	 * @var array<string, mixed>
	 */
	private array $result = [];

	/**
	 * The run's state (the run option for a background run).
	 *
	 * @var array<string, mixed>
	 * @phpstan-var RunState
	 */
	private array $run;

	/**
	 * The job id of a background run, '' inline.
	 *
	 * @var string
	 */
	private string $job_id = '';

	/**
	 * The progress callback.
	 *
	 * @var callable
	 */
	private $progress;

	/**
	 * When this call started (microtime).
	 *
	 * @var float
	 */
	private float $began = 0.0;

	/**
	 * Real memory the request held when this call began (bytes).
	 *
	 * @var int
	 */
	private int $began_memory = 0;

	/**
	 * The call's budget in seconds; 0 = none.
	 *
	 * @var float
	 */
	private float $budget = 0.0;

	/**
	 * Most pairs this call runs; 0 = no cap.
	 *
	 * @var int
	 */
	private int $max_pairs = 0;

	/**
	 * Pairs this call finished.
	 *
	 * @var int
	 */
	private int $pairs_done = 0;

	/**
	 * Sources this call finished or passed, with or without a pair.
	 *
	 * @var int
	 */
	private int $scanned = 0;

	/**
	 * Provider requests this call made.
	 *
	 * @var int
	 */
	private int $requests_made = 0;

	/**
	 * When progress was last reported (microtime).
	 *
	 * @var float
	 */
	private float $ticked = 0.0;

	/**
	 * A cancel seen inside a pair's clock: the pair stops and keeps its outputs, then the cancel goes on.
	 *
	 * @var ?JobCanceledException
	 */
	private ?JobCanceledException $canceled = null;

	/**
	 * Set when the off switch stopped the run before a pair.
	 *
	 * @var bool
	 */
	private bool $off = false;

	/**
	 * When this call last read the switch past the caches (microtime; 0: not yet).
	 *
	 * @var float
	 */
	private float $switch_read = 0.0;

	/**
	 * Sources finished, for progress.
	 *
	 * @var int
	 */
	private int $processed = 0;

	/**
	 * The counts of the run's earlier slices (from the run option).
	 *
	 * @var array<string, mixed>
	 */
	private array $prior = [];

	/**
	 * The run options of the run (without the per-pair clock).
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Whether a read of the run's next page failed in this call.
	 *
	 * @var bool
	 */
	private bool $read_error = false;

	/**
	 * The links of the page in progress, as page_links() read them.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 * @phpstan-var array<int, array<int, PageLink>>
	 */
	private array $page_read = [];

	/**
	 * The page's sources and translations with a state row, by post id. Held for the call so a runtime cache
	 * release does not read them again; a pair past step 4 drops its own.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $page_posts = [];

	/**
	 * Base key lists and `seen` maps the page read, by base id (a base row never changes under its id).
	 *
	 * @var array<int, array{keys: list<string>, seen: array<string, string>|null}>
	 */
	private array $page_bases = [];

	/**
	 * Meta bytes of the page's sources, by source id, read once per page.
	 *
	 * @var array<int, int>
	 */
	private array $page_meta = [];

	/**
	 * The posts each source of the page is read with (source plus step-4 candidate translations), by source id.
	 *
	 * @var array<int, list<int>>
	 */
	private array $page_groups = [];

	/**
	 * Text bytes of the page's sources and translations by post id, null when not known.
	 *
	 * @var array<int, int|null>
	 */
	private array $page_sizes = [];

	/**
	 * Type, status, author and `protected` of the page's posts by id, from the read that brings their bytes: the
	 * rights of a pair too large to read come from them.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $page_fields = [];

	/**
	 * The most text bytes a post of the page may hold for its pairs to read it; larger ones are refused unread.
	 *
	 * @var int
	 */
	private int $page_bound = PHP_INT_MAX;

	/**
	 * Sources of the page whose group was not all held: their pairs release the translation they read.
	 *
	 * @var array<int, true>
	 */
	private array $page_split = [];

	/**
	 * Whether the page in progress takes a start record for every pair (an earlier read-only pass did not finish).
	 *
	 * @var bool
	 */
	private bool $page_fallback = false;

	/** {@inheritDoc} */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The lowest capability: every pair is checked for the acting user; a query run also needs
	 * `perflocale_manage_translations`, checked every slice.
	 */
	public function get_required_capability(): string {
		return 'perflocale_translate';
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		return self::DEFAULT_THRESHOLD;
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function get_lock_ttl(): int {
		return self::LOCK_TTL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Worker args.
	 */
	protected function args_size( array $args ): int {
		return self::size( self::normalize_args( $args ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A query run always runs in the background, also under "Never"; a sources run follows the setting.
	 *
	 * @param array<string, mixed> $args     Worker args.
	 * @param Settings             $settings Plugin settings.
	 */
	public function should_run_async( array $args, Settings $settings ): bool {
		if ( self::MODE_QUERY === self::normalize_args( $args )['mode'] ) {
			return true;
		}

		return parent::should_run_async( $args, $settings );
	}

	/**
	 * The arguments in their one shape (fixed key order, sorted unique ids and names, unknown keys dropped),
	 * so an identical dispatch folds into the job already queued or running. `own_post` is the post a per-post
	 * "Resync from source" was pressed on ({@see per_post()}, {@see own_lock_post()}).
	 *
	 * @param array<mixed> $args Raw arguments.
	 * @return array<string, mixed>
	 * @phpstan-return Args
	 */
	public static function normalize_args( array $args ): array {
		$mode   = self::MODE_QUERY === ( $args['mode'] ?? '' ) ? self::MODE_QUERY : self::MODE_SOURCES;
		$query  = is_array( $args['query'] ?? null ) ? $args['query'] : [];
		$fill   = in_array( $args['fill'] ?? '', [ self::FILL_MACHINE, self::FILL_EMPTY ], true ) ? (string) $args['fill'] : '';
		$budget = $args['mt_budget'] ?? null;
		$run_id = is_string( $args['run_id'] ?? null ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $args['run_id'] ) ? $args['run_id'] : '';
		$is_q   = self::MODE_QUERY === $mode;

		$trigger = substr( sanitize_key( Scalar::str( $args['trigger'] ?? '' ) ), 0, 32 );

		return [
			'mode'          => $mode,
			'source_ids'    => $is_q ? [] : self::ids( $args['source_ids'] ?? [] ),
			'language_ids'  => self::ids( $args['language_ids'] ?? [] ),
			'query'         => [
				'post_types'     => $is_q ? self::names( $query['post_types'] ?? [] ) : [],
				'outdated'       => $is_q && ! empty( $query['outdated'] ),
				'missing'        => $is_q && ! empty( $query['missing'] ),
				'record_in_sync' => $is_q && ! empty( $query['record_in_sync'] ),
			],
			'fill'          => $fill,
			'only_outdated' => ! $is_q && ! empty( $args['only_outdated'] ),
			'machine'       => ! empty( $args['machine'] ),
			'trigger'       => '' === $trigger ? 'resync' : $trigger,
			'run_id'        => $run_id,
			'dry_run'       => ! empty( $args['dry_run'] ),
			'mt_budget'     => null === $budget ? null : max( 0, Scalar::int( $budget ) ),
			'own_post'      => $is_q ? 0 : max( 0, Scalar::int( $args['own_post'] ?? 0 ) ),
		];
	}

	/**
	 * Whether a run is an explicit per-post "Resync from source": one source whose `own_post` is that source or
	 * one of its translations. The trigger name does not decide it.
	 *
	 * @param array<string, mixed> $args  Normalised arguments.
	 * @phpstan-param Args $args
	 * @param array<mixed>         $group The source's translation ids (read only when per_post_needs_group() says so).
	 * @return bool
	 */
	public static function per_post( array $args, array $group = [] ): bool {
		if ( ! self::per_post_shape( $args ) ) {
			return false;
		}

		return $args['own_post'] === $args['source_ids'][0] || in_array( $args['own_post'], array_map( Scalar::int( ... ), $group ), true );
	}

	/**
	 * Whether per_post() needs the source's translations: one source whose `own_post` is another post.
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 * @phpstan-param Args $args
	 * @return bool
	 */
	public static function per_post_needs_group( array $args ): bool {
		return self::per_post_shape( $args ) && $args['own_post'] !== $args['source_ids'][0];
	}

	/**
	 * A sources run of exactly one source with an `own_post`.
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 * @phpstan-param Args $args
	 * @return bool
	 */
	private static function per_post_shape( array $args ): bool {
		return self::MODE_SOURCES === $args['mode'] && $args['own_post'] > 0 && 1 === count( $args['source_ids'] );
	}

	/**
	 * The post whose fresh edit lock by the acting user does not hold its pair: the per-post button's post,
	 * in an inline per-post call only. In a background slice every fresh lock holds (0).
	 *
	 * @param array<string, mixed> $args       Normalised arguments.
	 * @phpstan-param Args $args
	 * @param bool                 $background Whether the call is a worker slice.
	 * @param bool                 $per_post   Whether the run is per post ({@see per_post()}).
	 * @return int
	 */
	public static function own_lock_post( array $args, bool $background, bool $per_post ): int {
		return $background || ! $per_post ? 0 : $args['own_post'];
	}

	/**
	 * The size of a run for the Auto decision: sources x languages, fills included; a query run is unbounded.
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 * @phpstan-param Args $args
	 * @return int
	 */
	public static function size( array $args ): int {
		if ( self::MODE_QUERY === $args['mode'] ) {
			return PHP_INT_MAX;
		}

		return count( $args['source_ids'] ) * count( $args['language_ids'] );
	}

	/**
	 * A background slice's budget before its filter: 15 s, or half a smaller `max_execution_time`, never below 5 s;
	 * none under WP-CLI.
	 *
	 * @param bool $cli                Whether the run is a WP-CLI process.
	 * @param int  $max_execution_time The PHP limit, 0 when none.
	 * @return int
	 */
	public static function default_slice_seconds( bool $cli, int $max_execution_time ): int {
		if ( $cli ) {
			return 0;
		}

		if ( $max_execution_time > 0 ) {
			return max( self::MIN_SLICE_SECONDS, min( self::SLICE_SECONDS, intdiv( $max_execution_time, 2 ) ) );
		}

		return self::SLICE_SECONDS;
	}

	/**
	 * A filtered budget in seconds. On a web request 0 or less, or a non-number, keeps the default, so a filter
	 * cannot switch off the guard against the host's time limit; under WP-CLI 0 or less means no budget.
	 *
	 * @param mixed $value    The filtered value.
	 * @param float $fallback The default.
	 * @param bool  $cli      Whether the run is a WP-CLI process.
	 * @return float
	 */
	public static function filtered_seconds( mixed $value, float $fallback, bool $cli ): float {
		$seconds = is_numeric( $value ) ? (float) $value : 0.0;

		if ( $seconds > 0 ) {
			return $seconds;
		}

		return $cli ? 0.0 : $fallback;
	}

	/**
	 * A filtered pair cap: on a web request 0 or less keeps the default; under WP-CLI it means no cap.
	 *
	 * @param mixed $value    The filtered value.
	 * @param int   $fallback The default.
	 * @param bool  $cli      Whether the run is a WP-CLI process.
	 * @return int
	 */
	public static function filtered_pairs( mixed $value, int $fallback, bool $cli ): int {
		$pairs = Scalar::int( $value );

		if ( $pairs > 0 ) {
			return $pairs;
		}

		return $cli ? 0 : $fallback;
	}

	/**
	 * Whether a pair stops before its next provider request: under one request of time left, and only once the
	 * call made progress (so every slice moves on).
	 *
	 * @param float $left       Seconds left in the call's budget.
	 * @param bool  $progressed Whether the call already finished a pair or made a request.
	 * @return bool
	 */
	public static function defers( float $left, bool $progressed ): bool {
		return $progressed && $left < self::REQUEST_SECONDS;
	}

	/**
	 * The run option of a background job.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public static function run_option( string $job_id ): string {
		return self::RUN_OPTION_PREFIX . $job_id;
	}

	/**
	 * The stage a run reports (the Jobs page labels it).
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 * @phpstan-param Args $args
	 * @return string
	 */
	public static function stage_of( array $args ): string {
		if ( $args['dry_run'] ) {
			return self::STAGE_ESTIMATE;
		}

		if ( self::MODE_QUERY === $args['mode'] && ! $args['query']['outdated'] ) {
			if ( $args['query']['missing'] ) {
				return self::STAGE_FILL;
			}

			if ( $args['query']['record_in_sync'] ) {
				return self::STAGE_CONFIRM;
			}
		}

		return self::STAGE_RESYNC;
	}

	/**
	 * A result with every key at its start value.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_result(): array {
		return [
			'run_id'             => '',
			'stage'              => self::STAGE_RESYNC,
			'pairs'              => 0,
			'applied'            => 0,
			'held'               => 0,
			'unchanged'          => 0,
			'outdated'           => 0,
			'confirmed'          => 0,
			'proposals'          => 0,
			'filled'             => 0,
			'created'            => 0,
			'filled_existing'    => 0,
			'fills'              => 0,
			'empty'              => 0,
			'skipped'            => 0,
			'skipped_permission' => 0,
			'busy'               => 0,
			'deferred'           => 0,
			'too_slow'           => 0,
			'no_base'            => 0,
			'unsupported'        => 0,
			'missing'            => 0,
			'failed'             => 0,
			'not_processed'      => 0,
			'same_as_source'     => 0,
			'reused'             => 0,
			'left_source'        => 0,
			'machine_translated' => 0,
			'mt_units'           => 0,
			'mt_requests'        => 0,
			'mt_chars'           => 0,
			'fill_chars'         => 0,
			'needs_estimate'     => 0,
			'needs_mt'           => 0,
			'source_changed'     => 0,
			'mt_unavailable'     => '',
			'stopped'            => '',
			'continued'          => '',
			'first_error'        => '',
			'items'              => [],
		];
	}

	/**
	 * Count one pair's outcome into a result; `items` (capped) lists [source, language, translation, status,
	 * reason, change].
	 *
	 * @param array<string, mixed> $result      The result.
	 * @param Outcome              $outcome     The pair's outcome.
	 * @param int                  $source_id   Source id.
	 * @param int                  $language_id Language id.
	 * @return array<string, mixed>
	 */
	public static function tally( array $result, Outcome $outcome, int $source_id, int $language_id ): array {
		$key = self::STATUS_KEYS[ $outcome->status ] ?? 'skipped';

		$result['pairs'] = Scalar::int( $result['pairs'] ?? 0 ) + 1;
		$result[ $key ]  = Scalar::int( $result[ $key ] ?? 0 ) + 1;

		// A machine fill of a missing translation also created it; an existing empty one is counted apart.
		if ( 'filled' === $outcome->status ) {
			$also            = Scalar::int( $outcome->counts['created'] ?? 0 ) > 0 ? 'created' : 'filled_existing';
			$result[ $also ] = Scalar::int( $result[ $also ] ?? 0 ) + 1;
		}

		if ( in_array( $outcome->status, self::NOT_PROCESSED, true ) ) {
			$result['not_processed'] = Scalar::int( $result['not_processed'] ?? 0 ) + 1;
		}

		foreach ( [ 'same_as_source', 'reused', 'left_source', 'machine_translated', 'mt_units' ] as $count ) {
			$result[ $count ] = Scalar::int( $result[ $count ] ?? 0 ) + Scalar::int( $outcome->counts[ $count ] ?? 0 );
		}

		if ( ! in_array( $outcome->status, self::NOT_SENT, true ) ) {
			$chars_key            = 'fill' === $outcome->status ? 'fill_chars' : 'mt_chars';
			$result[ $chars_key ] = Scalar::int( $result[ $chars_key ] ?? 0 ) + $outcome->mt_chars;
		}

		$result['mt_requests'] = Scalar::int( $result['mt_requests'] ?? 0 ) + $outcome->mt_requests;

		if ( '' !== $outcome->mt_unavailable && '' === Scalar::str( $result['mt_unavailable'] ?? '' ) ) {
			$result['mt_unavailable'] = $outcome->mt_unavailable;
		}

		$items = is_array( $result['items'] ?? null ) ? $result['items'] : [];

		if ( count( $items ) < self::ITEMS_MAX ) {
			$items[] = [ $source_id, $language_id, $outcome->translation_id, $outcome->status, $outcome->reason, $outcome->change_id ];
		}

		$result['items'] = $items;

		return $result;
	}

	/**
	 * Run (a slice of) the job.
	 *
	 * @param array<string, mixed> $args     Arguments (normalised here).
	 * @param callable             $progress `function( int $processed, int $total, array $detail = [] ): void`.
	 * @return array<string, mixed> The result; `__perflocale_continue` when a background slice stopped early.
	 * @throws JobCanceledException When the operator cancels (the counts are attached).
	 */
	public function execute( array $args, callable $progress ): array {
		$slice = is_array( $args['__perflocale_slice'] ?? null ) ? $args['__perflocale_slice'] : null;

		return $this->run_call( self::normalize_args( $args ), $progress, $slice, null );
	}

	/**
	 * An estimate's dry run inline with its own budget: nothing written or sent; the result counts what a run
	 * would do (`stopped` = `budget` when the budget ran out).
	 *
	 * @param array<string, mixed> $args   Arguments (normalised here).
	 * @param float                $budget Seconds.
	 * @return array<string, mixed>
	 */
	public function dry_run( array $args, float $budget ): array {
		$args            = self::normalize_args( $args );
		$args['dry_run'] = true;

		return $this->run_call( $args, static function (): void {}, null, $budget );
	}

	/**
	 * One call of the job: inline, a background slice, or a dry run.
	 *
	 * @param array<string, mixed> $args     Normalised arguments.
	 * @phpstan-param Args $args
	 * @param callable             $progress Progress callback.
	 * @param array<mixed>|null    $slice    The worker's slice key, null inline.
	 * @param float|null           $budget   A dry run's budget, null otherwise.
	 * @return array<string, mixed>
	 * @throws JobCanceledException When the operator cancels.
	 */
	private function run_call( array $args, callable $progress, ?array $slice, ?float $budget ): array {
		$this->args     = $args;
		$this->progress = $progress;
		$this->job_id   = null !== $slice && class_exists( \PerfLocale\Background\WorkerRunner::class, false ) ? \PerfLocale\Background\WorkerRunner::current_job_id( self::TYPE ) : '';
		$this->result   = self::empty_result();
		$this->began    = microtime( true );
		$this->ticked   = 0.0;

		$this->read_error  = false;
		$this->off         = false;
		$this->switch_read = 0.0;

		$this->began_memory = memory_get_usage( true );

		$this->result['run_id'] = '' !== $args['run_id'] ? $args['run_id'] : ( '' !== $this->job_id ? $this->job_id : wp_generate_uuid4() );
		$this->result['stage']  = self::stage_of( $args );

		if ( ! Store::available() ) {
			return $this->failed( Store::unavailable_text() );
		}

		if ( ! \PerfLocale\Util\ResyncSwitch::on() ) {
			return $this->failed( \PerfLocale\Util\ResyncOff::refused_text() );
		}

		// A query run re-checks the manager capability every slice: the worker re-checks only the job capability.
		if ( self::MODE_QUERY === $args['mode'] && ! current_user_can( 'perflocale_manage_translations' ) ) {
			return $this->failed( __( 'You are not allowed to update translations across the site.', 'perflocale' ) );
		}

		$settings = \PerfLocale\Plugin::get_instance()->settings();

		// MT switched off while a background machine-mode run waited: stop, do not retry.
		if ( null !== $slice && $args['machine'] && ! $args['dry_run'] && ! $settings->mt_enabled() ) {
			return $this->failed( __( 'Machine translation is disabled in settings.', 'perflocale' ) );
		}

		$this->run = $this->load_run( null !== $slice ? $slice : [] );
		$this->clock( null !== $slice, $budget );
		$per_post      = self::per_post( $args, self::per_post_needs_group( $args ) ? self::group_of( $args['source_ids'][0] ) : [] );
		$this->options = [
			'machine'         => $args['machine'],
			'threshold'       => \PerfLocale\Resync\ResyncSettings::match_threshold( $settings ),
			'keep_exceptions' => \PerfLocale\Resync\ResyncSettings::keep_exceptions( $settings ),
			'per_post'        => $per_post,
			'own_lock'        => self::own_lock_post( $args, null !== $slice, $per_post ),
			'query_mode'      => self::MODE_QUERY === $args['mode'],
			'trigger'         => $args['trigger'],
			'job_id'          => Scalar::str( $this->result['run_id'] ),
			'mt_budget'       => $args['mt_budget'],
			'dry_run'         => $args['dry_run'],
		];

		if ( [] === $args['language_ids'] ) {
			return $this->finish( false );
		}

		// A data import or a migration is running: nothing runs until it is done (a URL-slug write is no import).
		if ( null === \PerfLocale\Automation\ChangeService::import_fence() ) {
			$this->result['stopped']     = 'import';
			$this->result['first_error'] = __( 'An import is running, so the run stopped before any translation. Run it again when the import has finished.', 'perflocale' );

			$this->count_rest( $this->run['current'] > 0 ? $this->run['current'] : PHP_INT_MAX );

			return $this->finish( false );
		}

		TranslationGroupRepository::suspend_eager_link_map();
		\PerfLocale\Database\Repository\TranslationGroupWrites::begin_deferred_link_invalidation();
		$more = false;

		try {
			$more = $this->loop();
		} catch ( JobCanceledException $e ) {
				// The worker raises this for a queue pause too and re-queues the job as running: keep the run option
				// so the resumed slice continues at the cursor.
			if ( $this->canceled_for_good() ) {
				$this->forget_run();
			} else {
				$this->save_run();
			}

			$e->set_result( $this->public_result() );

			throw $e;
		} finally {
			$this->page_read   = [];
			$this->page_posts  = [];
			$this->page_bases  = [];
			$this->page_meta   = [];
			$this->page_groups = [];
			$this->page_sizes  = [];
			$this->page_fields = [];
			$this->page_split  = [];
			$this->page_bound  = PHP_INT_MAX;

			\PerfLocale\Database\Repository\TranslationGroupWrites::end_deferred_link_invalidation();
			TranslationGroupRepository::resume_eager_link_map();

			if ( null !== $slice ) {
				MigrationCacheHelper::release_batch_memory( true );
			}
		}

		if ( $this->off ) {
			return $this->failed( \PerfLocale\Util\ResyncOff::refused_text() );
		}

		return $this->finish( $more );
	}

	/**
	 * The budget and pair cap of this call.
	 *
	 * @param bool       $background Whether the call is a worker slice.
	 * @param float|null $budget     A dry run's budget, null otherwise.
	 * @return void
	 */
	private function clock( bool $background, ?float $budget ): void {
		$cli = 'cli' === PHP_SAPI;

		if ( null !== $budget ) {
			$this->budget    = max( 0.0, $budget );
			$this->max_pairs = 0;

			return;
		}

		if ( $this->args['dry_run'] ) {
			$this->budget    = (float) self::ESTIMATE_SECONDS;
			$this->max_pairs = 0;

			return;
		}

		if ( ! $background ) {
			/**
			 * Filters the budget of an inline resync run, in seconds (the rest continues in the background).
			 * On a web request 0 or less keeps the default; under WP-CLI it means no budget.
			 *
			 * @hook perflocale/jobs/content_resync/inline_seconds
			 * @since 1.0.8
			 *
			 * @param float $seconds Default 20.
			 */
			$this->budget    = self::filtered_seconds( apply_filters( 'perflocale/jobs/content_resync/inline_seconds', (float) self::INLINE_SECONDS ), (float) self::INLINE_SECONDS, $cli );
			$this->max_pairs = 0;

			return;
		}

		$met     = (int) ini_get( 'max_execution_time' );
		$seconds = (float) self::default_slice_seconds( $cli, $met );
		$pairs   = $cli ? 0 : self::SLICE_MAX_PAIRS;

		/**
		 * Filters the budget of one background slice of a resync run, in seconds.
		 * On a web request 0 or less keeps the default; under WP-CLI it means no budget.
		 *
		 * @hook perflocale/jobs/content_resync/slice_seconds
		 * @since 1.0.8
		 *
		 * @param float $seconds Default 15, or half of a smaller max_execution_time, never below 5; 0 under WP-CLI.
		 */
		$this->budget = self::filtered_seconds( apply_filters( 'perflocale/jobs/content_resync/slice_seconds', $seconds ), $seconds, $cli );

		/**
		 * Filters the most pairs one background slice of a resync run runs.
		 * On a web request 0 or less keeps the default; under WP-CLI it means no cap.
		 *
		 * @internal Not public: the slice's seconds budget already bounds it.
		 * @hook perflocale/jobs/content_resync/slice_max_pairs
		 * @since 1.0.8
		 *
		 * @param int $pairs Default 200 on web requests, 0 under WP-CLI.
		 */
		$this->max_pairs = self::filtered_pairs( apply_filters( 'perflocale/jobs/content_resync/slice_max_pairs', $pairs ), $pairs, $cli );
	}

	/**
	 * The pairs of the run, page by page and source by source; a spent call stops before the next page.
	 *
	 * @return bool True when the call stopped with pairs left.
	 */
	private function loop(): bool {
		$this->report( true );

		if ( self::MODE_SOURCES === $this->args['mode'] ) {
			$sources = array_values( array_filter( $this->args['source_ids'], fn ( int $id ): bool => $id > $this->run['cursor'] ) );

			foreach ( array_chunk( $sources, self::PAGE_SOURCES ) as $i => $page ) {
				if ( $i > 0 ) {
					$this->page_done();

					if ( $this->spent() ) {
						return $this->stop( $page[0] );
					}
				}

				$stop = $this->page( $page );

				if ( null !== $stop ) {
					return $stop;
				}
			}

			return false;
		}

		$boundary = false;

		while ( true ) {
			$page = $this->query_page( $this->run['cursor'], $boundary );

			if ( null === $page ) {
				return $this->read_error ? $this->read_failed( $this->run['cursor'] ) : $this->stop( $this->run['cursor'] );
			}

			if ( [] === $page ) {
				return false;
			}

			$stop = $this->page( $page );

			if ( null !== $stop ) {
				return $stop;
			}

			$boundary = true;
		}
	}

	/**
	 * A page is done and another follows: save the cursor once and, in a background call, release the runtime
	 * object cache. Inline and dry runs (admin requests, bounded by budget and ceiling) keep the cache.
	 *
	 * @return void
	 */
	private function page_done(): void {
		$this->save_run();

		if ( '' !== $this->job_id ) {
			MigrationCacheHelper::release_batch_memory( true );
		}
	}

	/**
	 * One page of sources, read once for its pairs' step-4 decision, in byte-bounded chunks of whole sources
	 * ({@see PostReads}); posts too large for a pair are never read and their pairs refused. A background run records
	 * the page start; a page begun while that record is set (an unfinished pass) takes a start record per pair.
	 *
	 * @param array<int, int> $page Source ids, ascending.
	 * @return bool|null True: stopped with pairs left; false: stopped, nothing more to run; null: go on.
	 */
	private function page( array $page ): ?bool {
		$page = array_values( $page );

		$links = $this->page_links( $page );
		$drift = null === $links ? null : $this->mark_drift( $links );

		if ( null === $links || null === $drift ) {
			return $this->read_failed( $page[0] ?? $this->run['cursor'] );
		}

		if ( ! $drift ) {
			return $this->stop( $page[0] ?? $this->run['cursor'] );
		}

		if ( self::MODE_QUERY !== $this->args['mode'] ) {
			$this->page_bound = PostReads::post_bound( $this->ceiling() );
		}

		$candidates = $this->hand_down( $page, $links );
		$chunks     = PostReads::chunks( $this->page_groups, $this->page_sizes, PostReads::budget( $this->ceiling() ) );
		$read       = $this->hold( $chunks[0] ?? [] );

		$this->page_fallback = Scalar::int( $this->run['starts'][ self::PAGE_START ] ?? 0 ) > 0;

		if ( '' !== $this->job_id && $candidates > 0 ) {
			$this->run['starts'][ self::PAGE_START ] = Scalar::int( $this->run['starts'][ self::PAGE_START ] ?? 0 ) + 1;
			$this->save_run();
		}

		$stop = $this->page_pairs( $page, $links, $chunks, $read );

		unset( $this->run['starts'][ self::PAGE_START ] );

		return $stop;
	}

	/**
	 * Hand the page's read to its pairs: each source with the translations of selected pairs that have a state row
	 * (those that may stop at step 4).
	 *
	 * @param array<int, int>                              $page  Source ids.
	 * @param array<int, array<int, array<string, mixed>>> $links The page's links.
	 * @phpstan-param array<int, array<int, PageLink>> $links
	 * @return int How many of the page's pairs may stop at step 4 on the page's read.
	 */
	private function hand_down( array $page, array $links ): int {
		$groups     = [];
		$candidates = 0;

		foreach ( $page as $source_id ) {
			$groups[ $source_id ] = [ $source_id ];

			foreach ( $links[ $source_id ] ?? [] as $language_id => $link ) {
				if ( null !== $link['narrow'] && ! $link['empty'] && $link['translation_id'] > 0 && in_array( $language_id, $this->args['language_ids'], true ) && 'resync' === $this->kind( $link ) ) {
					$groups[ $source_id ][] = $link['translation_id'];
					++$candidates;
				}
			}
		}

		$this->page_read   = $links;
		$this->page_groups = $groups;
		$this->page_posts  = [];
		$this->page_bases  = [];
		$this->page_meta   = [];
		$this->page_split  = [];

		return $candidates;
	}

	/**
	 * Read one chunk's uncached posts in one statement and hold them in place of the chunk before.
	 * A single group over the read budget holds the posts that fit, source first.
	 *
	 * @param array<int, int> $sources The chunk's source ids.
	 * @return list<int> The ids read.
	 */
	private function hold( array $sources ): array {
		$ids = [];

		foreach ( $sources as $source_id ) {
			foreach ( $this->page_groups[ $source_id ] ?? [ $source_id ] as $id ) {
				if ( ! PostReads::too_large( $this->page_sizes, $id, $this->page_bound ) ) {
					$ids[ $id ] = $id;
				}
			}
		}

		$ids = PostReads::fitting( array_values( $ids ), $this->page_sizes, PostReads::budget( $this->ceiling() ) );

		foreach ( $sources as $source_id ) {
			if ( [] !== array_diff( $this->page_groups[ $source_id ] ?? [ $source_id ], $ids ) ) {
				$this->page_split[ $source_id ] = true;
			}
		}

		$read = [] === $ids ? [] : array_values( array_map( Scalar::int( ... ), _get_non_cached_ids( $ids, 'posts' ) ) );

		if ( [] !== $read ) {
			_prime_post_caches( $read, false, false );
		}

		$this->page_posts = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post instanceof \WP_Post ) {
				$this->page_posts[ $id ] = $post;
			}
		}

		return $read;
	}

	/**
	 * Between two chunks: a background call releases its runtime cache; inline and dry runs drop what the chunk read.
	 *
	 * @param array<int, int> $read The ids the chunk read.
	 * @return void
	 */
	private function chunk_done( array $read ): void {
		$held             = array_keys( $this->page_posts );
		$this->page_posts = [];

		if ( '' !== $this->job_id ) {
			MigrationCacheHelper::release_batch_memory( true );

			return;
		}

		PostReads::release( $read, $held );
	}

	/**
	 * The sources of one page after its read; each later chunk is read when its first source is reached.
	 *
	 * @param array<int, int>                              $page   Source ids.
	 * @param array<int, array<int, array<string, mixed>>> $links  The page's links.
	 * @phpstan-param array<int, array<int, PageLink>> $links
	 * @param array<int, array<int, int>>                  $chunks The page's chunks of source ids.
	 * @param array<int, int>                              $read   The ids the first chunk read.
	 * @return bool|null See page().
	 */
	private function page_pairs( array $page, array $links, array $chunks, array $read ): ?bool {
		$last   = count( $page ) - 1;
		$starts = [];

		foreach ( $chunks as $c => $chunk ) {
			if ( $c > 0 && [] !== $chunk ) {
				$starts[ $chunk[0] ] = $chunk;
			}
		}

		foreach ( $page as $i => $source_id ) {
			if ( isset( $starts[ $source_id ] ) ) {
				$this->chunk_done( $read );

				$read = $this->hold( $starts[ $source_id ] );
			}

			$stop = $this->source( $source_id, $links[ $source_id ] ?? [] );

			if ( null !== $stop ) {
				return $stop;
			}

			$this->run['cursor']  = $source_id;
			$this->run['current'] = 0;
			$this->run['done']    = [];
			++$this->processed;
			++$this->scanned;
			$this->report( false );

			if ( $i < $last && $this->spent() ) {
				return $this->stop( $page[ $i + 1 ] );
			}
		}

		return null;
	}

	/**
	 * The pairs of one source.
	 *
	 * @param int                              $source_id Source id.
	 * @param array<int, array<string, mixed>> $links     Its translations in the run's languages, by language.
	 * @phpstan-param array<int, PageLink> $links
	 * @return bool|null See page().
	 * @throws JobCanceledException When a pair's clock saw a cancel.
	 */
	private function source( int $source_id, array $links ): ?bool {
		if ( $this->run['current'] !== $source_id ) {
			$this->run['current'] = $source_id;
			$this->run['done']    = [];
		}

		$source = $this->page_posts[ $source_id ] ?? ( PostReads::too_large( $this->page_sizes, $source_id, $this->page_bound ) ? null : get_post( $source_id ) );

		foreach ( $this->args['language_ids'] as $language_id ) {
			if ( in_array( $language_id, $this->run['done'], true ) ) {
				continue;
			}

			$kind = $this->kind( $links[ $language_id ] ?? null );

			if ( '' === $kind ) {
				$this->run['done'][] = $language_id;
				continue;
			}

			// Switched off since the run began (another request, the filter): no further pair, also under WP-CLI.
			$on = $this->still_on( false );

			if ( null === $on ) {
				return $this->read_failed( $source_id );
			}

			if ( ! $on ) {
				$this->off = true;

				return false;
			}

			if ( $this->spent() ) {
				return $this->stop( $source_id );
			}

			$outcome = $this->pair( $source, $source_id, $language_id, $kind );

			if ( self::OFF === $outcome->status ) {
				$this->off = true;

				return false;
			}

			if ( self::UNREAD === $outcome->status ) {
				return $this->read_failed( $source_id );
			}

			if ( 'deferred' === $outcome->status ) {
				// Stopped before a provider request and any write; it keeps its outputs and runs first next call.
				$this->result['deferred']    = Scalar::int( $this->result['deferred'] ) + 1;
				$this->result['mt_chars']    = Scalar::int( $this->result['mt_chars'] ) + $outcome->mt_chars;
				$this->result['mt_requests'] = Scalar::int( $this->result['mt_requests'] ) + $outcome->mt_requests;

				$canceled = $this->canceled;

				// A cancel the pair's clock saw stops the run here.
				if ( null !== $canceled ) {
					throw $canceled;
				}

				return $this->stop( $source_id );
			}

			$this->result = self::tally( $this->result, $outcome, $source_id, $language_id );
			++$this->pairs_done;

			$this->run['done'][] = $language_id;

			// A cancel stops the run here too when the pair did not defer (outputs not kept).
			if ( null !== $this->canceled ) {
				throw $this->canceled;
			}

			if ( 'limit' === $outcome->status ) {
				$this->result['stopped']     = 'limit';
				$this->result['first_error'] = __( 'The monthly machine translation limit was reached; the run stopped before this pair, and nothing of it was written.', 'perflocale' );
				$this->count_rest( $source_id );

				return false;
			}

			if ( 'failed' === $outcome->status && 'provider_unavailable' === $outcome->reason ) {
				$this->result['stopped']     = 'provider_unavailable';
				$this->result['first_error'] = __( 'The machine translation provider is not available right now; the run stopped. Run it again later.', 'perflocale' );
				$this->count_rest( $source_id );

				return false;
			}

			// Nothing of the pair was written: an import ran after it read its data.
			if ( 'busy' === $outcome->status && 'import' === $outcome->reason && '' === Scalar::str( $this->result['first_error'] ) ) {
				$this->result['first_error'] = $outcome->translation_id > 0
					/* translators: %d: translation post id */
					? sprintf( __( 'Translation %d was not updated: an import ran during this run. Run Update translations again.', 'perflocale' ), $outcome->translation_id )
					/* translators: %d: source post id */
					: sprintf( __( 'The translation of post %d was not written: an import ran during this run. Run Update translations again.', 'perflocale' ), $source_id );
			}

			if ( 'failed' === $outcome->status && '' === Scalar::str( $this->result['first_error'] ) ) {
				$this->result['first_error'] = $outcome->translation_id > 0
					? sprintf(
						/* translators: 1: translation post id, 2: reason code */
						__( 'Translation %1$d could not be updated (%2$s).', 'perflocale' ),
						$outcome->translation_id,
						$outcome->reason
					)
					: sprintf(
						/* translators: 1: source post id, 2: reason code */
						__( 'The translation of post %1$d could not be updated (%2$s).', 'perflocale' ),
						$source_id,
						$outcome->reason
					);
			}

			if ( 'cli' === PHP_SAPI && '' !== $this->job_id && 0 === $this->pairs_done % self::RELEASE_EVERY_PAIRS ) {
				MigrationCacheHelper::release_batch_memory( true );
				$this->restore_page_posts();
			}

			$this->report( false );
		}

		return null;
	}

	/**
	 * What the run does with a pair: `resync`, `fill` (a missing or empty translation), or '' (not selected).
	 *
	 * @param array<string, mixed>|null $link The pair's translation, null when missing.
	 * @phpstan-param PageLink|null $link
	 * @return string
	 */
	private function kind( ?array $link ): string {
		$fill    = '' !== $this->args['fill'];
		$missing = null === $link || $link['empty'];

		if ( self::MODE_SOURCES === $this->args['mode'] ) {
			if ( $missing ) {
				return $fill ? 'fill' : 'resync';
			}

			if ( $this->args['only_outdated'] && 'needs_update' !== $link['status'] && ! $link['drift'] ) {
				return '';
			}

			return 'resync';
		}

		$q = $this->args['query'];

		if ( $missing ) {
			return $q['missing'] ? ( $fill ? 'fill' : 'resync' ) : '';
		}

		// A field-only source change marks no flag: the scan found it (mark_drift()).
		if ( 'needs_update' === $link['status'] || $link['drift'] ) {
			return $q['outdated'] ? 'resync' : '';
		}

		return $q['record_in_sync'] && ! $link['state'] ? 'resync' : '';
	}

	/**
	 * Run one pair: skip a too-slow one; else the runner (once more after a stale answer, both MT spends counted)
	 * or the filler. A throw counts `failed` (`error`) and the run goes on; a write that changed the source rereads it.
	 *
	 * @param \WP_Post|null $source      The source; replaced by its fresh read after a write.
	 * @param int           $source_id   Source id.
	 * @param int           $language_id Language id.
	 * @param string        $kind        resync or fill.
	 * @return Outcome
	 * @throws JobCanceledException When the operator cancels or pauses.
	 */
	private function pair( ?\WP_Post &$source, int $source_id, int $language_id, string $kind ): Outcome {
		$key            = $source_id . ':' . $language_id;
		$translation_id = Scalar::int( $this->page_read[ $source_id ][ $language_id ]['translation_id'] ?? 0 );

		// Too large to read: refused as the engine refuses a pair that would not fit, after the runner's rights.
		foreach ( [
			'source'      => $source_id,
			'translation' => $translation_id,
		] as $side => $id ) {
			if ( $id > 0 && PostReads::too_large( $this->page_sizes, $id, $this->page_bound ) ) {
				return PostReads::may_pair( $kind, $source_id, $translation_id, $this->page_fields )
					? new Outcome( 'unsupported', $side . ': ' . Engine::ERROR_MEMORY, $translation_id )
					: new Outcome( 'skipped_permission', '', $translation_id );
			}
		}

		if ( ( $this->run['starts'][ $key ] ?? 0 ) >= self::MAX_STARTS ) {
			unset( $this->run['starts'][ $key ] );
			$this->save_run();

			return new Outcome( 'too_slow' );
		}

		$options = $this->pair_options();

		// Step 4 on the page's read: no write, no start record (the page's record covers it).
		if ( 'resync' === $kind && ! $this->page_fallback ) {
			$outcome = $this->on_page( $source_id, $language_id, $options );

			if ( null !== $outcome ) {
				return $outcome;
			}
		}

		// Past step 4 a pair may write: switched off or unread, it stops here.
		$on = $this->still_on( true );

		if ( true !== $on ) {
			return new Outcome( null === $on ? self::UNREAD : self::OFF );
		}

		// The start is recorded first, so a pair whose process dies in it is counted and its second start skips it.
		$started = '' !== $this->job_id;

		if ( $started ) {
			$this->run['starts'][ $key ] = ( $this->run['starts'][ $key ] ?? 0 ) + 1;
			$this->save_run();
		}

		$before = '';

		try {
			$outcome = 'resync' === $kind && $this->page_fallback ? $this->on_page( $source_id, $language_id, $options ) : null;

			if ( null !== $outcome ) {
				return $outcome;
			}

			// Past step 4 the pair uses nothing of the page's read: run() reads it all again.
			$this->drop_page_read( $source, $source_id, $language_id );

			$before = null !== $source ? self::source_mark( $source ) : '';

			if ( 'fill' === $kind ) {
				$outcome = null === $source ? new Outcome( 'no_source', 'source' ) : Filler::fill( $source, $language_id, $this->args['fill'], $options );

				if ( 'linked' === $outcome->status ) {
					$outcome = PairRunner::run( $source_id, $language_id, $options );
				}
			} else {
				$outcome = PairRunner::run( $source_id, $language_id, $options );

				if ( 'stale' === $outcome->status ) {
					$first   = $outcome;
					$outcome = self::with_spend( PairRunner::run( $source_id, $language_id, $options ), $first );
				}

				if ( 'stale' === $outcome->status ) {
					$outcome = new Outcome( 'busy', 'stale', $outcome->translation_id, 0, '', [], [], $outcome->mt_chars, $outcome->mt_requests );
				}
			}
		} catch ( JobCanceledException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			\PerfLocale\Automation\ChangeService::log( sprintf( 'the pair of source %d in language %d failed: %s', $source_id, $language_id, $e->getMessage() ) );

			$outcome = new Outcome( 'failed', 'error' );
		} finally {
			if ( $started ) {
				unset( $this->run['starts'][ $key ] );
				$this->save_run();
			}

			// A source whose group was not held whole: its translation leaves memory before the next pair.
			if ( $translation_id > 0 && isset( $this->page_split[ $source_id ] ) ) {
				PostReads::release( [ $translation_id ], [ $translation_id ] );
			}
		}

		if ( '' !== $before && in_array( $outcome->status, [ 'applied', 'filled' ], true ) ) {
			wp_cache_delete( $source_id, 'posts' );
			wp_cache_delete( $source_id, 'post_meta' );
			$after = $this->fresh_source( $source_id );

			if ( ! $after instanceof \WP_Post || self::source_mark( $after ) !== $before ) {
				$this->result['source_changed'] = Scalar::int( $this->result['source_changed'] ) + 1;
				\PerfLocale\Automation\ChangeService::log( sprintf( 'the source %d changed while translation %d was written (a save filter or another plugin re-saved it, or wrote its menu order or featured image)', $source_id, $outcome->translation_id ) );

				$source = $after instanceof \WP_Post ? $after : null;
			}
		}

		return $outcome;
	}

	/**
	 * Step 4 of a pair on the page's read: the outcome when the pair stops there without a write, else null.
	 * A throw in this read-only pass is null too: run() then retakes the pair and counts what it throws.
	 *
	 * @param int        $source_id   Source id.
	 * @param int        $language_id Language id.
	 * @param RunOptions $options     The pair's options.
	 * @return Outcome|null
	 * @throws JobCanceledException When the operator cancels.
	 */
	private function on_page( int $source_id, int $language_id, RunOptions $options ): ?Outcome {
		$link   = $this->page_read[ $source_id ][ $language_id ] ?? null;
		$source = $this->page_posts[ $source_id ] ?? null;

		if ( null === $link || null === $link['narrow'] || $link['empty'] || ! $source instanceof \WP_Post ) {
			return null;
		}

		try {
			if ( $options->fields && Scope::fields_included(
				[
					'trigger'      => $options->trigger,
					'language_ids' => [ $language_id ],
					'user_id'      => get_current_user_id(),
				]
			) ) {
				$this->prime_source_meta( $source_id );
			}

			return PairRunner::recorded_on_page(
				$source_id,
				$language_id,
				$options,
				$source,
				$this->page_posts[ $link['translation_id'] ] ?? null,
				$link['narrow'],
				fn ( int $base_id ): ?array => $this->page_base_keys( $base_id )
			);
		} catch ( JobCanceledException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * A base's key list and `seen` map, read once for the page.
	 *
	 * @param int $base_id Base id.
	 * @return array{keys: list<string>, seen: array<string, string>|null}|null Null when the base could not be read.
	 */
	private function page_base_keys( int $base_id ): ?array {
		if ( ! isset( $this->page_bases[ $base_id ] ) ) {
			$keys = ( new \PerfLocale\Database\Repository\ResyncRepository() )->base_keys( $base_id );

			if ( null === $keys ) {
				return null;
			}

			$this->page_bases[ $base_id ] = $keys;
		}

		return $this->page_bases[ $base_id ];
	}

	/**
	 * Prime a source's meta together with the page's next sources lacking it, as many as one WP-CLI release
	 * interval covers, so the meta held at once stays near one source's.
	 *
	 * @param int $source_id Source id.
	 * @return void
	 */
	private function prime_source_meta( int $source_id ): void {
		if ( false !== wp_cache_get( $source_id, 'post_meta' ) ) {
			return;
		}

		$chunk = max( 1, intdiv( self::RELEASE_EVERY_PAIRS, max( 1, count( $this->args['language_ids'] ) ) ) );
		$ids   = [];
		$seen  = false;

		foreach ( array_keys( $this->page_read ) as $id ) {
			$seen = $seen || $id === $source_id;

			if ( $seen && false === wp_cache_get( $id, 'post_meta' ) ) {
				$ids[] = $id;
			}

			if ( count( $ids ) >= $chunk ) {
				break;
			}
		}

		if ( [] !== $ids ) {
			update_meta_cache( 'post', $this->meta_fitting( $ids ) );
		}
	}

	/**
	 * The sources whose meta one read holds within the read budget; the first (the current source) always.
	 *
	 * @param array<int, int> $ids Source ids, the current source first.
	 * @phpstan-param list<int> $ids
	 * @return list<int>
	 */
	private function meta_fitting( array $ids ): array {
		if ( count( $ids ) < 2 ) {
			return $ids;
		}

		$unread = array_values( array_diff( $ids, array_keys( $this->page_meta ) ) );

		if ( [] !== $unread ) {
			$wpdb  = self::db();
			$bytes = PostReads::meta_bytes_sql();
			$in    = implode( ',', array_fill( 0, count( $unread ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list is built from placeholders and the byte expression from fixed column names.
			$rows = self::read_rows( $wpdb->prepare( "SELECT post_id, SUM( $bytes ) AS bytes, COUNT(*) AS n FROM %i WHERE post_id IN ($in) GROUP BY post_id", array_merge( [ $wpdb->postmeta ], $unread ) ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			if ( null === $rows ) {
				return [ $ids[0] ];
			}

			foreach ( $unread as $id ) {
				$this->page_meta[ $id ] = 0;
			}

			foreach ( $rows as $row ) {
				$this->page_meta[ Scalar::int( $row['post_id'] ?? 0 ) ] = Scalar::int( $row['bytes'] ?? 0 ) + Scalar::int( $row['n'] ?? 0 ) * PostReads::META_ROW_BYTES;
			}
		}

		$first  = array_shift( $ids );
		$budget = PostReads::budget( $this->ceiling() ) - ( $this->page_meta[ $first ] ?? 0 ) - PostReads::POST_BYTES;

		return array_merge( [ $first ], $budget > 0 ? PostReads::fitting( $ids, $this->page_meta, $budget ) : [] );
	}

	/**
	 * A pair past step 4 drops what the page read for it from the runtime cache and the page's posts, so run()
	 * and the source mark read them fresh; the fresh source serves its later languages.
	 *
	 * @param \WP_Post|null $source      The source; replaced by its fresh read.
	 * @param int           $source_id   Source id.
	 * @param int           $language_id Language id.
	 * @return void
	 */
	private function drop_page_read( ?\WP_Post &$source, int $source_id, int $language_id ): void {
		$link = $this->page_read[ $source_id ][ $language_id ] ?? null;

		unset( $this->page_read[ $source_id ][ $language_id ] );

		if ( null !== $link && $link['translation_id'] > 0 ) {
			unset( $this->page_posts[ $link['translation_id'] ] );
			wp_cache_delete( $link['translation_id'], 'posts' );
		}

		if ( ! isset( $this->page_posts[ $source_id ] ) ) {
			return;
		}

		wp_cache_delete( $source_id, 'posts' );
		wp_cache_delete( $source_id, 'post_meta' );

		$source = $this->fresh_source( $source_id );
	}

	/**
	 * After a runtime cache release, put the page's held posts back, so later pairs do not read them one by one.
	 * Only without a persistent object cache: an add there would reach other requests.
	 *
	 * @return void
	 */
	private function restore_page_posts(): void {
		if ( [] === $this->page_posts || wp_using_ext_object_cache() ) {
			return;
		}

		wp_cache_add_multiple( $this->page_posts, 'posts', HOUR_IN_SECONDS );
	}

	/**
	 * The source read again, held for the page's later pairs.
	 *
	 * @param int $source_id Source id.
	 * @return \WP_Post|null
	 */
	private function fresh_source( int $source_id ): ?\WP_Post {
		$fresh = get_post( $source_id );

		if ( $fresh instanceof \WP_Post ) {
			$this->page_posts[ $source_id ] = $fresh;

			return $fresh;
		}

		unset( $this->page_posts[ $source_id ] );

		return null;
	}

	/**
	 * What a pair's write must leave unchanged on its source: the title, content and excerpt hash, menu order and
	 * featured image (the Sync Fields a translation's save would push).
	 *
	 * @param \WP_Post $source Source.
	 * @return string
	 */
	private static function source_mark( \WP_Post $source ): string {
		return Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt )
			. '|' . $source->menu_order
			. '|' . Scalar::str( get_post_meta( $source->ID, '_thumbnail_id', true ) );
	}

	/**
	 * An outcome with an earlier run's MT spend added (a re-run after a stale read already paid for its requests).
	 *
	 * @param Outcome $outcome The outcome.
	 * @param Outcome $earlier The earlier run's outcome.
	 * @return Outcome
	 */
	private static function with_spend( Outcome $outcome, Outcome $earlier ): Outcome {
		if ( 0 === $earlier->mt_chars && 0 === $earlier->mt_requests ) {
			return $outcome;
		}

		return new Outcome(
			$outcome->status,
			$outcome->reason,
			$outcome->translation_id,
			$outcome->change_id,
			$outcome->decision,
			$outcome->counts,
			$outcome->flags,
			$outcome->mt_chars + $earlier->mt_chars,
			$outcome->mt_requests + $earlier->mt_requests,
			$outcome->mt_unavailable
		);
	}

	/**
	 * One pair's run options, with a clock that checks the deadline and reports progress before each provider request.
	 *
	 * @return RunOptions
	 */
	private function pair_options(): RunOptions {
		$clock = function ( int $done ): bool {
			unset( $done );

			if ( $this->budget > 0 && self::defers( $this->began + $this->budget - microtime( true ), $this->pairs_done > 0 || $this->requests_made > 0 ) ) {
				return false;
			}

			try {
				$this->report( false );
			} catch ( JobCanceledException $e ) {
				$this->canceled = $e;

				return false;
			}

			++$this->requests_made;

			return true;
		};

		$o = $this->options;

		return new RunOptions(
			machine: (bool) $o['machine'],
			threshold: Scalar::int( $o['threshold'] ),
			keep_exceptions: (bool) $o['keep_exceptions'],
			per_post: (bool) $o['per_post'],
			query_mode: (bool) $o['query_mode'],
			ignore_own_lock: Scalar::int( $o['own_lock'] ),
			trigger: Scalar::str( $o['trigger'] ),
			job_id: Scalar::str( $o['job_id'] ),
			mt_budget: null === $o['mt_budget'] ? null : Scalar::int( $o['mt_budget'] ),
			dry_run: (bool) $o['dry_run'],
			before_request: $clock,
			post_bound: $this->page_bound
		);
	}

	/**
	 * Whether ReSync is still on: read past the caches before a pair that may write, else at most every TICK_SECONDS
	 * (as cancel), so unchanged pairs cost no settings read each.
	 *
	 * @param bool $writes Whether the next step may write.
	 * @return bool|null Null when the switch could not be read.
	 */
	private function still_on( bool $writes ): ?bool {
		$now = microtime( true );

		if ( ! $writes && $now - $this->switch_read < self::TICK_SECONDS ) {
			return true;
		}

		$this->switch_read = $now;

		return \PerfLocale\Util\ResyncSwitch::fresh();
	}

	/**
	 * Whether this call stops before its next source or pair: budget, pair cap or memory ceiling reached.
	 * Never before the run moved on, so every call passes at least one source or runs at least one pair.
	 *
	 * @return bool
	 */
	private function spent(): bool {
		if ( $this->pairs_done < 1 && $this->scanned < 1 ) {
			return false;
		}

		if ( $this->max_pairs > 0 && $this->pairs_done >= $this->max_pairs ) {
			return true;
		}

		if ( $this->budget > 0 && microtime( true ) - $this->began >= $this->budget ) {
			return true;
		}

		return $this->memory_high();
	}

	/**
	 * Whether the request's memory reached this call's ceiling; never when `memory_limit` is unbounded.
	 *
	 * @return bool
	 */
	private function memory_high(): bool {
		$ceiling = $this->ceiling();

		return $ceiling > 0 && memory_get_usage( true ) >= $ceiling;
	}

	/**
	 * This call's memory ceiling below WorkBudget::work_limit(), not `memory_limit`: the continuation's cron write and
	 * other option writes fetch the autoloaded options again past it; 0 when `memory_limit` is unbounded.
	 *
	 * @return int Bytes.
	 */
	private function ceiling(): int {
		$limit = \PerfLocale\Util\WorkBudget::work_limit();

		return null === $limit ? 0 : self::memory_ceiling( $limit, $this->began_memory );
	}

	/**
	 * The real memory use at which a call stops: MEMORY_CEILING of the limit, or half the headroom for a call
	 * that began above it, so memory held before the call never stops it alone and it always stops below the limit.
	 *
	 * @param int $limit The limit in bytes (WorkBudget::work_limit()).
	 * @param int $began Real memory the request held when the call began.
	 * @return int Bytes.
	 */
	public static function memory_ceiling( int $limit, int $began ): int {
		return max( (int) ( $limit * self::MEMORY_CEILING ), $began + intdiv( max( 0, $limit - $began ), 2 ) );
	}

	/**
	 * Stop this call with pairs left: a background slice continues; an inline run hands the rest to the background
	 * (not under "Never"); a dry run reports its budget ran out.
	 *
	 * @param int $source_id The source in progress.
	 * @return bool True when the job continues in a next slice.
	 */
	private function stop( int $source_id ): bool {
		if ( $this->args['dry_run'] ) {
			$this->result['stopped'] = 'budget';

			return false;
		}

		if ( '' !== $this->job_id ) {
			return true;
		}

		$rest = self::MODE_SOURCES === $this->args['mode']
			? array_values( array_filter( $this->args['source_ids'], static fn ( int $id ): bool => $id >= $source_id ) )
			: [];

		$settings = \PerfLocale\Plugin::get_instance()->settings();

		if ( 'never' === Scalar::str( $settings->get( 'background_processing', 'auto' ) ) || [] === $rest ) {
			$this->result['not_processed'] = Scalar::int( $this->result['not_processed'] ) + $this->pairs_left( $rest, $source_id );
			$this->result['first_error']   = __( 'Not every translation was processed in this request. Run it again to continue.', 'perflocale' );

			return false;
		}

		$args               = $this->args;
		$args['source_ids'] = $rest;
		$args['run_id']     = Scalar::str( $this->result['run_id'] );
		$queued             = Dispatcher::enqueue( new self(), self::normalize_args( $args ) );

		if ( isset( $queued['job_id'] ) && '' !== $queued['job_id'] ) {
			$this->result['continued'] = $queued['job_id'];
		} else {
			$this->result['not_processed'] = Scalar::int( $this->result['not_processed'] ) + $this->pairs_left( $rest, $source_id );
			$this->result['first_error']   = Scalar::str( $queued['error'] ?? '' );
		}

		return false;
	}

	/**
	 * A read the run decides on failed: stop before the unread sources, cursor kept. A background slice saves its
	 * run and throws so the worker retries from the cursor; inline hands the rest on as stop(); dry run = `db_error`.
	 *
	 * @param int $source_id The first source not processed.
	 * @return bool See stop().
	 * @throws \RuntimeException In a background slice.
	 */
	private function read_failed( int $source_id ): bool {
		$text = self::read_failed_text( __( 'A database read failed, so the run stopped before the translations it could not read. Run it again to continue.', 'perflocale' ) );

		if ( '' !== $this->job_id && ! $this->args['dry_run'] ) {
			$this->save_run();

			throw new \RuntimeException( esc_html( $text ) );
		}

		$this->result['stopped'] = 'db_error';

		if ( $this->args['dry_run'] ) {
			$this->result['first_error'] = $text;

			return false;
		}

		$more = $this->stop( $source_id );

		if ( '' === Scalar::str( $this->result['continued'] ) ) {
			$this->result['first_error'] = $text;
		}

		return $more;
	}

	/**
	 * A failed ReSync read's text: the cause and its fix when the database names a missing table (a site move or restore
	 * that left tables out: running again cannot help), else $fallback.
	 *
	 * @param string $fallback The text for any other failure.
	 * @return string
	 */
	public static function read_failed_text( string $fallback ): string {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb || 'missing_table' !== DbErrorCode::of( $wpdb->last_error ) ) {
			return $fallback;
		}

		return __( 'Content ReSync\'s database tables are missing. To create them, an administrator uses Retry now under Tools > Site Health or runs wp perflocale db-migrate.', 'perflocale' );
	}

	/**
	 * Count the pairs a stopped sources run leaves as not processed (a query run cannot know them).
	 *
	 * @param int $source_id The source in progress (PHP_INT_MAX: none started).
	 * @return void
	 */
	private function count_rest( int $source_id ): void {
		if ( self::MODE_SOURCES !== $this->args['mode'] ) {
			return;
		}

		$rest = array_values( array_filter( $this->args['source_ids'], fn ( int $id ): bool => $id > $this->run['cursor'] && ( $id >= $source_id || PHP_INT_MAX === $source_id ) ) );

		$this->result['not_processed'] = Scalar::int( $this->result['not_processed'] ) + $this->pairs_left( $rest, $source_id );
	}

	/**
	 * Pairs left of the given sources: undone languages of the one in progress, every language of the rest.
	 *
	 * @param array<int, int> $rest      Sources from the one in progress on.
	 * @param int             $source_id The source in progress.
	 * @return int
	 */
	private function pairs_left( array $rest, int $source_id ): int {
		$langs = count( $this->args['language_ids'] );
		$left  = 0;

		foreach ( $rest as $id ) {
			$left += $id === $source_id ? max( 0, $langs - count( $this->run['done'] ) ) : $langs;
		}

		return $left;
	}

	/**
	 * Report progress at most every TICK_SECONDS, always when forced: the only place cancel and pause are probed
	 * and the locks refreshed.
	 *
	 * @param bool $force Report whatever the time.
	 * @return void
	 */
	private function report( bool $force ): void {
		$now = microtime( true );

		if ( ! $force && $now - $this->ticked < self::TICK_SECONDS ) {
			return;
		}

		$this->ticked = $now;

		( $this->progress )(
			$this->processed,
			$this->run['total'] < 0 ? 0 : max( $this->processed, $this->run['total'] ),
			[
				'stage' => Scalar::str( $this->result['stage'] ),
			]
		);
	}

	/**
	 * The result of this call; a finished background run forgets its run option.
	 *
	 * @param bool $more Whether the job continues in a next slice.
	 * @return array<string, mixed>
	 */
	private function finish( bool $more ): array {
		$result = $this->public_result();

		if ( $more ) {
			$this->save_run();
			$result['__perflocale_continue'] = true;

			return $result;
		}

		$this->forget_run();

		// A finished run re-read what it covered: later estimates stop saying the sources are read again.
		if ( ! $this->args['dry_run'] ) {
			\PerfLocale\Util\ResyncOff::reread_done();
		}

		if ( '' === Scalar::str( $result['first_error'] ?? '' ) ) {
			$result['first_error'] = self::not_processed_text( $result );
		}

		return $result;
	}

	/**
	 * Why a finished run left pairs not processed, by cause (needs MT, needs an estimate, busy or failed); ''
	 * when none. The Jobs page shows it.
	 *
	 * @param array<string, mixed> $result The result.
	 * @return string
	 */
	public static function not_processed_text( array $result ): string {
		$left     = Scalar::int( $result['not_processed'] ?? 0 );
		$needs_mt = Scalar::int( $result['needs_mt'] ?? 0 );
		$why_mt   = $needs_mt > 0 ? \PerfLocale\Admin\ResyncNotices::mt_unavailable_text( Scalar::str( $result['mt_unavailable'] ?? '' ) ) : '';
		$needs_mt = '' === $why_mt ? 0 : $needs_mt;
		$waiting  = Scalar::int( $result['needs_estimate'] ?? 0 );
		$rest     = $left - $needs_mt - $waiting;
		$notes    = '' === $why_mt ? [] : [ $why_mt ];

		if ( $waiting > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of translations */
				_n( '%d translation now needs machine translation, so it waits for an estimate. Run it again to see the estimate.', '%d translations now need machine translation, so they wait for an estimate. Run it again to see the estimate.', $waiting, 'perflocale' ),
				$waiting
			);
		}

		if ( $rest > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of translations */
				_n( '%d translation was not processed (busy or failed). Run it again to pick it up.', '%d translations were not processed (busy or failed). Run it again to pick them up.', $rest, 'perflocale' ),
				$rest
			);
		}

		return implode( ' ', $notes );
	}

	/**
	 * A run that ends at once with a reason (`run_failed`): the worker records it failed and does not retry.
	 *
	 * @param string $reason The reason.
	 * @return array<string, mixed>
	 */
	private function failed( string $reason ): array {
		$this->forget_run();

		$result                = $this->result;
		$result['run_failed']  = true;
		$result['first_error'] = $reason;

		return $result;
	}

	/**
	 * The result with the counts of earlier slices of the run added.
	 *
	 * @return array<string, mixed>
	 */
	private function public_result(): array {
		$result = $this->result;
		$prior  = $this->prior;

		foreach ( $result as $key => $value ) {
			if ( is_int( $value ) && is_int( $prior[ $key ] ?? null ) ) {
				$result[ $key ] = $value + $prior[ $key ];
			}
		}

		if ( '' === Scalar::str( $result['first_error'] ) && is_string( $prior['first_error'] ?? null ) ) {
			$result['first_error'] = $prior['first_error'];
		}

		if ( '' === Scalar::str( $result['mt_unavailable'] ) && is_string( $prior['mt_unavailable'] ?? null ) ) {
			$result['mt_unavailable'] = $prior['mt_unavailable'];
		}

		$items = is_array( $prior['items'] ?? null ) ? $prior['items'] : [];

		$result['items'] = array_slice( array_merge( $items, is_array( $result['items'] ) ? $result['items'] : [] ), 0, self::ITEMS_MAX );

		return $result;
	}

	/**
	 * The run's state: a background job's run option (created at its first slice with an insert that never
	 * overwrites), or a fresh state inline.
	 *
	 * @param array<mixed> $slice The worker's slice key ([] inline).
	 * @return array<string, mixed>
	 * @phpstan-return RunState
	 * @throws \RuntimeException When the run option cannot be read.
	 */
	private function load_run( array $slice ): array {
		$fresh = [
			'started_at' => time(),
			'cursor'     => 0,
			'current'    => 0,
			'done'       => [],
			'starts'     => [],
			'counts'     => [],
			'total'      => 0,
		];

		if ( '' === $this->job_id ) {
			$fresh['total'] = $this->total() ?? -1;

			return $fresh;
		}

		unset( $slice );
		$name = self::run_option( $this->job_id );
		$read = self::read_run( $name );

		if ( null !== $read && ! $read[0] ) {
			$fresh['total'] = $this->total() ?? -1;
			$created        = self::create_run( $name, $fresh );

			if ( true === $created ) {
				return $fresh;
			}

			// Another process stored the row in between: read it again.
			$read = false === $created ? self::read_run( $name ) : null;
		}

			// A failed read is not "no run stored": stop before any pair; the worker retries from the stored run.
		if ( null === $read || ! $read[0] ) {
			throw new \RuntimeException( esc_html__( 'The stored progress of the run could not be read, so this slice stopped before any translation. It is retried from where the run was.', 'perflocale' ) );
		}

		$stored = $read[1];

		if ( ! is_array( $stored ) ) {
			$fresh['total'] = $this->total() ?? -1;

			return $fresh;
		}

		$starts = [];

		foreach ( is_array( $stored['starts'] ?? null ) ? $stored['starts'] : [] as $key => $count ) {
			$starts[ (string) $key ] = Scalar::int( $count );
		}

		$this->prior     = self::string_keyed( $stored['counts'] ?? null );
		$this->processed = Scalar::int( $this->prior['sources'] ?? 0 );

		return [
			'started_at' => Scalar::int( $stored['started_at'] ?? time() ),
			'cursor'     => Scalar::int( $stored['cursor'] ?? 0 ),
			'current'    => Scalar::int( $stored['current'] ?? 0 ),
			'done'       => self::ids( $stored['done'] ?? [] ),
			'starts'     => $starts,
			'counts'     => $this->prior,
			'total'      => Scalar::int( $stored['total'] ?? 0 ) < 0 ? ( $this->total() ?? -1 ) : Scalar::int( $stored['total'] ?? 0 ),
		];
	}

	/**
	 * The run option's row from the table, not the cache: [ exists, value ], null when the read failed
	 * (get_option() answers its default for both).
	 *
	 * @param string $name Option name.
	 * @return array{0: bool, 1: mixed}|null
	 */
	private static function read_run( string $name ): ?array {
		$wpdb = self::db();
		$rows = self::read_rows( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $name ) );

		if ( null === $rows ) {
			return null;
		}

		return [] === $rows ? [ false, null ] : [ true, maybe_unserialize( Scalar::str( $rows[0]['option_value'] ?? '' ) ) ];
	}

	/**
	 * Create the run option without overwriting a stored run (add_option() is INSERT ... ON DUPLICATE KEY UPDATE).
	 *
	 * @param string               $name Option name.
	 * @param array<string, mixed> $run  The fresh run.
	 * @return bool|null True when this call inserted it, false when a row exists, null when the insert failed.
	 */
	private static function create_run( string $name, array $run ): ?bool {
		$wpdb = self::db();
		$sql  = $wpdb->prepare( 'INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )', $wpdb->options, $name, maybe_serialize( $run ), 'no' );

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the prepare() result; the options cache is dropped below.
		$result = $wpdb->query( $sql );

		if ( ! is_int( $result ) ) {
			return null;
		}

		wp_cache_delete( $name, 'options' );
		\PerfLocale\Database\NotOptions::unlist( [ $name ] );

		return 1 === $result;
	}

	/**
	 * Write a background job's run option, counts included so a retry keeps them.
	 *
	 * @return void
	 */
	private function save_run(): void {
		if ( '' === $this->job_id ) {
			return;
		}

		$run                      = $this->run;
		$run['counts']            = $this->public_result();
		$run['counts']['sources'] = $this->processed;
		$name                     = self::run_option( $this->job_id );

		if ( ! self::write_run( $name, $run ) ) {
			update_option( $name, $run, false );
		}
	}

	/**
	 * Write the stored run option's row in place, as update_option() caches a row not autoloaded. Not update_option():
	 * it first fetches alloptions from the persistent cache, twice the autoloaded bytes at its peak, once per page.
	 *
	 * @param string               $name Option name.
	 * @param array<string, mixed> $run  The run.
	 * @return bool False when no row changed (none stored, the same value, or the write failed).
	 */
	private static function write_run( string $name, array $run ): bool {
		$wpdb  = self::db();
		$value = maybe_serialize( $run );
		$sql   = $wpdb->prepare( "UPDATE %i SET option_value = %s WHERE option_name = %s AND autoload NOT IN ( 'yes', 'on', 'auto-on', 'auto' )", $wpdb->options, $value, $name );

		if ( ! is_string( $sql ) || '' === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the prepare() result; the option's cache is set below.
		if ( 1 !== $wpdb->query( $sql ) ) {
			return false;
		}

		wp_cache_set( $name, $value, 'options' );

		return true;
	}

	/**
	 * Delete the run option of a background job that is done.
	 *
	 * @return void
	 */
	private function forget_run(): void {
		if ( '' !== $this->job_id ) {
			delete_option( self::run_option( $this->job_id ) );
		}
	}

	/**
	 * Whether a cancel seen by the progress callback ends the run: the job row is canceled or gone (paused stays
	 * running). A failed read is "unknown", not "gone": the run option is kept for housekeeping's sweep.
	 *
	 * @return bool
	 */
	private function canceled_for_good(): bool {
		if ( '' === $this->job_id ) {
			return true;
		}

		$row = JobState::get_fresh( $this->job_id );

			// get_fresh() answers null for no row and for a failed read; last_error tells them apart.
		if ( null === $row ) {
			return '' === self::db()->last_error;
		}

		return 'canceled' === Scalar::str( $row['status'] ?? '' );
	}

	/**
	 * The run's source count for progress; null when the count could not be read (no total shown; retried next slice).
	 *
	 * @return int|null
	 */
	private function total(): ?int {
		if ( self::MODE_SOURCES === $this->args['mode'] ) {
			return count( $this->args['source_ids'] );
		}

		$wpdb = self::db();

		$types   = $this->query_types();
		$default = $this->default_language();

		if ( [] === $types || $default <= 0 ) {
			return 0;
		}

		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One counted read per run; the IN list is built from placeholders.
		$count = self::read_ids(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i l INNER JOIN %i p ON p.ID = l.object_id
				 WHERE l.type = 'post' AND l.language_id = %d AND p.post_status = 'publish' AND p.post_type IN ($in)",
				array_merge( [ Schema::table( 'translation_links' ), $wpdb->posts, $default ], $types )
			)
		);
		// phpcs:enable

		return null === $count ? null : ( $count[0] ?? 0 );
	}

	/**
	 * The next page of a query run: published default-language sources after the cursor, by id, in scope.
	 * A page with nothing in scope is passed. The page before is marked done once the next ids are read, and a
	 * spent call stops before priming them, so a long excluded range never holds one call.
	 *
	 * @param int  $after    The last finished source id.
	 * @param bool $boundary Whether a page of this call is done before this one.
	 * @return array<int, int>|null The page; [] when no source is left; null when the call stopped (`read_error` set when the read failed).
	 */
	private function query_page( int $after, bool $boundary ): ?array {
		$wpdb = self::db();

		$types   = $this->query_types();
		$default = $this->default_language();

		if ( [] === $types || $default <= 0 ) {
			return [];
		}

		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args = [
			'trigger'      => $this->args['trigger'],
			'language_ids' => $this->args['language_ids'],
			'user_id'      => get_current_user_id(),
		];

		$bytes = PostReads::bytes_sql();

		while ( true ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Keyset page; the IN list is built from placeholders and the byte expression from fixed column names.
			$rows = self::read_rows(
				$wpdb->prepare(
					"SELECT l.object_id, $bytes AS bytes FROM %i l INNER JOIN %i p ON p.ID = l.object_id
					 WHERE l.type = 'post' AND l.language_id = %d AND l.object_id > %d AND p.post_status = 'publish' AND p.post_type IN ($in)
					 ORDER BY l.object_id ASC LIMIT %d",
					array_merge( [ Schema::table( 'translation_links' ), $wpdb->posts, $default, $after ], $types, [ self::PAGE_SOURCES ] )
				)
			);
			// phpcs:enable

			// A failed read is not "no source left": the run stops at its cursor.
			if ( null === $rows ) {
				$this->read_error = true;

				return null;
			}

			if ( [] === $rows ) {
				return [];
			}

			$ids    = [];
			$sizes  = [];
			$groups = [];

			foreach ( $rows as $row ) {
				$id = Scalar::int( $row['object_id'] ?? 0 );

				$ids[]         = $id;
				$groups[ $id ] = [ $id ];
				$sizes[ $id ]  = null === ( $row['bytes'] ?? null ) ? null : Scalar::int( $row['bytes'] );
			}

			if ( $boundary ) {
				$this->page_done();
			}

			if ( $this->spent() ) {
				return null;
			}

				// The sources that fit one read; one larger than a pair can hold joins unread and its pairs are refused.
			$ids              = PostReads::chunks( $groups, $sizes, PostReads::budget( $this->ceiling() ) )[0] ?? $ids;
			$this->page_bound = PostReads::post_bound( $this->ceiling() );
			$large            = array_values( array_filter( $ids, fn ( int $id ): bool => PostReads::too_large( $sizes, $id, $this->page_bound ) ) );
			$read             = array_values( array_diff( $ids, $large ) );

			if ( [] !== $read ) {
				_prime_post_caches( $read, false, false );
			}

			$page = [];

			foreach ( $ids as $id ) {
				if ( in_array( $id, $large, true ) ) {
					$page[] = $id;
					continue;
				}

				$post = get_post( $id );

				if ( $post instanceof \WP_Post && Scope::includes( $post, $args ) ) {
					$page[] = $id;
				}
			}

			if ( [] !== $page ) {
				return $page;
			}

			// A page with nothing in scope: the cursor passes it.
			$after               = (int) end( $ids );
			$this->run['cursor'] = $after;
			$this->processed    += count( $ids );
			$this->scanned      += count( $ids );
			$boundary            = true;
			$this->report( false );
		}
	}

	/**
	 * The translations of a page's sources in the run's languages with status, emptiness and state hashes, in three
	 * reads; the first also brings the text bytes (kept in page_sizes). Null when a read failed.
	 *
	 * @param array<int, int> $page Source ids.
	 * @return array<int, array<int, array<string, mixed>>>|null
	 * @phpstan-return array<int, array<int, PageLink>>|null
	 */
	private function page_links( array $page ): ?array {
		$wpdb = self::db();

		$default = $this->default_language();

		if ( [] === $page || $default <= 0 ) {
			return [];
		}

		$links_table = Schema::table( 'translation_links' );
		$src_in      = implode( ',', array_fill( 0, count( $page ), '%d' ) );
		$lang_in     = implode( ',', array_fill( 0, count( $this->args['language_ids'] ), '%d' ) );
		$bytes       = PostReads::bytes_sql();
		$fields      = PostReads::FIELDS_SQL;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Reads of one page; the IN lists are built from placeholders and the byte expression from fixed column names.
		$rows = self::read_rows(
			$wpdb->prepare(
				"SELECT s.object_id AS source_id, t.object_id AS translation_id, t.language_id, t.status, $bytes AS bytes, $fields
				 FROM %i s INNER JOIN %i t ON t.group_id = s.group_id AND t.type = 'post'
				 LEFT JOIN %i p ON p.ID = t.object_id
				 WHERE s.type = 'post' AND s.language_id = %d AND s.object_id IN ($src_in) AND t.language_id IN ($lang_in)
				 UNION ALL
				 SELECT p.ID AS source_id, 0 AS translation_id, 0 AS language_id, '' AS status, $bytes AS bytes, $fields
				 FROM %i p WHERE p.ID IN ($src_in)",
				array_merge( [ $links_table, $links_table, $wpdb->posts, $default ], $page, $this->args['language_ids'], [ $wpdb->posts ], $page )
			)
		);

		if ( null === $rows ) {
			return null;
		}

		$ids    = [];
		$sizes  = [];
		$fields = [];
		$links  = [];

		foreach ( $rows as $row ) {
			$translation_id = Scalar::int( $row['translation_id'] ?? 0 );
			$id             = $translation_id > 0 ? $translation_id : Scalar::int( $row['source_id'] ?? 0 );

			$sizes[ $id ] = null === ( $row['bytes'] ?? null ) ? null : Scalar::int( $row['bytes'] );

			$fields[ $id ] = PostReads::fields( $row );

			if ( $translation_id > 0 ) {
				$ids[]   = $translation_id;
				$links[] = $row;
			}
		}

		$rows              = $links;
		$this->page_sizes  = $sizes;
		$this->page_fields = $fields;

		$ids   = array_values( array_unique( $ids ) );
		$empty = [];
		$state = [];

		if ( [] !== $ids ) {
			$id_in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$read  = self::read_ids( $wpdb->prepare( "SELECT ID FROM %i WHERE ID IN ($id_in) AND post_content = ''", array_merge( [ $wpdb->posts ], $ids ) ) );

			if ( null === $read ) {
				return null;
			}

			$empty = array_flip( $read );
			$state = ( new \PerfLocale\Database\Repository\ResyncRepository() )->state_hashes_of( $ids );

			if ( null === $state ) {
				return null;
			}
		}
		// phpcs:enable

		$out = [];

		foreach ( $rows as $row ) {
			$translation_id = Scalar::int( $row['translation_id'] ?? 0 );

			$out[ Scalar::int( $row['source_id'] ?? 0 ) ][ Scalar::int( $row['language_id'] ?? 0 ) ] = [
				'translation_id' => $translation_id,
				'status'         => Scalar::str( $row['status'] ?? '' ),
				'empty'          => isset( $empty[ $translation_id ] ),
				'state'          => isset( $state[ $translation_id ] ),
				'narrow'         => $state[ $translation_id ] ?? null,
				'drift'          => false,
			];
		}

		return $out;
	}

	/**
	 * Mark the page's current recorded pairs whose source fields changed since their state, for an Outdated selection.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $links The page's links.
	 * @phpstan-param array<int, array<int, PageLink>> $links
	 * @phpstan-param-out array<int, array<int, PageLink>> $links
	 * @return bool|null True when done; false when the call's budget stopped it; null when a read failed.
	 */
	private function mark_drift( array &$links ): ?bool {
		$outdated = self::MODE_SOURCES === $this->args['mode'] ? $this->args['only_outdated'] : $this->args['query']['outdated'];
		$scope    = [
			'trigger'      => $this->args['trigger'],
			'language_ids' => $this->args['language_ids'],
			'user_id'      => get_current_user_id(),
		];

		if ( ! $outdated || ! Scope::fields_included( $scope ) ) {
			return true;
		}

		$pairs = [];

		foreach ( $links as $source_id => $by_language ) {
			foreach ( $by_language as $link ) {
				if ( null !== $link['narrow'] && ! $link['empty'] && $link['translation_id'] > 0 && 'needs_update' !== $link['status'] ) {
					$pairs[ $link['translation_id'] ] = [
						'source_id'   => $source_id,
						'base_id'     => $link['narrow']['base_id'],
						'fields_hash' => $link['narrow']['fields_hash'],
					];
				}
			}
		}

		$changed = \PerfLocale\Resync\FieldDrift::changed( $pairs, fn (): bool => $this->spent() );

		if ( ! is_array( $changed ) ) {
			return false === $changed ? false : null;
		}

		foreach ( $links as $source_id => $by_language ) {
			foreach ( $by_language as $language_id => $link ) {
				$link['drift']                       = isset( $changed[ $link['translation_id'] ] );
				$links[ $source_id ][ $language_id ] = $link;
			}
		}

		return true;
	}

	/**
	 * The post types of a query run: the asked ones within the scope list.
	 *
	 * @return list<string>
	 */
	private function query_types(): array {
		$scope = Scope::object_types(
			[
				'trigger'      => $this->args['trigger'],
				'language_ids' => $this->args['language_ids'],
				'user_id'      => get_current_user_id(),
			]
		);

		return array_values( array_intersect( $this->args['query']['post_types'], array_diff( $scope, [ Scope::CUSTOM_FIELDS ] ) ) );
	}

	/**
	 * The post ids of a source's translation group.
	 *
	 * @param int $source Source post id.
	 * @return array<int|string, int>
	 */
	private static function group_of( int $source ): array {
		return \PerfLocale\Resync\GroupLinks::of( $source );
	}

	/**
	 * The default language's id.
	 *
	 * @return int
	 */
	private function default_language(): int {
		return Scalar::int( Scalar::prop( \PerfLocale\Plugin::get_instance()->lang_repo()->get_default(), 'id' ) );
	}

	/**
	 * The rows of a read through query(), or null when it failed. get_results() answers [] for a failed read too;
	 * query() returns an int only on success, with the rows in last_result.
	 *
	 * @param string|null $sql The prepare() result.
	 * @return list<array<string, mixed>>|null
	 */
	private static function read_rows( ?string $sql ): ?array {
		$wpdb = self::db();

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the prepare() result.
		$read = $wpdb->query( $sql );

		if ( ! is_int( $read ) ) {
			return null;
		}

		$rows = [];

		foreach ( (array) $wpdb->last_result as $row ) {
			$rows[] = self::string_keyed( (array) $row );
		}

		return $rows;
	}

	/**
	 * The first column of a read as integers, or null when the read failed.
	 *
	 * @param string|null $sql The prepare() result.
	 * @return list<int>|null
	 */
	private static function read_ids( ?string $sql ): ?array {
		$rows = self::read_rows( $sql );

		if ( null === $rows ) {
			return null;
		}

		$ids = [];

		foreach ( $rows as $row ) {
			$ids[] = Scalar::int( array_values( $row )[0] ?? 0 );
		}

		return $ids;
	}

	/**
	 * WordPress's database object.
	 *
	 * @return \wpdb
	 * @throws \RuntimeException When it is missing.
	 */
	private static function db(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}

		return $wpdb;
	}

	/**
	 * A stored map with its string keys only.
	 *
	 * @param mixed $value Stored value.
	 * @return array<string, mixed>
	 */
	private static function string_keyed( mixed $value ): array {
		$out = [];

		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $item;
			}
		}

		return $out;
	}

	/**
	 * Positive integer ids, unique and sorted.
	 *
	 * @param mixed $value Raw list.
	 * @return list<int>
	 */
	private static function ids( mixed $value ): array {
		$ids = [];

		foreach ( is_array( $value ) ? $value : [] as $id ) {
			$id = Scalar::int( is_numeric( $id ) ? (int) $id : 0 );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		ksort( $ids );

		return array_values( $ids );
	}

	/**
	 * Sanitised names, unique and sorted.
	 *
	 * @param mixed $value Raw list.
	 * @return list<string>
	 */
	private static function names( mixed $value ): array {
		$names = [];

		foreach ( is_array( $value ) ? $value : [] as $name ) {
			$name = sanitize_key( Scalar::str( $name ) );

			if ( '' !== $name ) {
				$names[ $name ] = $name;
			}
		}

		ksort( $names );

		return array_values( $names );
	}
}
