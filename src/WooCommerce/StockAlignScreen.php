<?php
/**
 * WooCommerce stock alignment: the "Stock across languages" settings block, its ajax actions and jobs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Background\Dispatcher;
use PerfLocale\Background\JobState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Check stock" (StockCheckJob), "Align" one group and "Align all" (StockAlignJob), inline or as polled jobs.
 * Loaded only on its own ajax actions and the settings tab. Every action checks the
 * nonce first, then the capability; no stock value comes from a request (`expected` is only a precondition).
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockAlignScreen {

	/**
	 * Nonce action.
	 */
	public const NONCE = 'perflocale_wc_stock';

	/**
	 * Capability of every action.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Largest `expected` request body read, in bytes.
	 */
	private const MAX_EXPECTED_BYTES = 65536;

	/**
	 * Attach the block's ajax actions (on its own admin-ajax requests; the add-on registers the job types).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_perflocale_wc_stock_check', [ self::class, 'ajax_check' ] );
		add_action( 'wp_ajax_perflocale_wc_stock_status', [ self::class, 'ajax_status' ] );
	}

	/**
	 * Print the block (a settings-table row). Controls are nameless `type="button"`s, so the settings
	 * form around it never posts any of them.
	 *
	 * @param bool $sync_stock Whether Inventory Sync is on.
	 * @return void
	 */
	public static function render( bool $sync_stock ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Stock across languages', 'perflocale' ); ?></th>
			<td>
				<?php if ( ! $sync_stock ) : ?>
					<p class="description"><?php echo esc_html__( 'Turn on Inventory Sync above to check stock across languages.', 'perflocale' ); ?></p>
				<?php else : ?>
					<?php self::enqueue(); ?>
					<div class="perflocale-wc-stock" data-perflocale-wc-stock="<?php echo esc_attr( (string) wp_json_encode( self::client_data() ) ); ?>">
						<p class="description"><?php echo esc_html__( 'Lists products whose language copies hold different stock. Copies set to "Independent across languages" or that do not manage stock are left out.', 'perflocale' ); ?></p>
						<p class="perflocale-wc-stock__actions">
							<button type="button" class="button perflocale-wc-stock__check"><?php echo esc_html__( 'Check stock', 'perflocale' ); ?></button>
						</p>
						<progress class="perflocale-wc-stock__progress" max="100" value="0" aria-label="<?php echo esc_attr__( 'Checking stock...', 'perflocale' ); ?>" hidden></progress>
						<div class="perflocale-wc-stock__status" role="status" aria-live="polite"></div>
						<div class="perflocale-wc-stock__result"></div>
						<div class="perflocale-wc-stock__confirm" tabindex="-1" hidden>
							<p class="perflocale-wc-stock__confirm-text"></p>
							<p>
								<button type="button" class="button button-primary perflocale-wc-stock__confirm-yes"><?php echo esc_html__( 'Align now', 'perflocale' ); ?></button>
								<button type="button" class="button perflocale-wc-stock__confirm-no"><?php echo esc_html__( 'Cancel', 'perflocale' ); ?></button>
							</p>
						</div>
						<div class="perflocale-wc-stock__left"></div>
					</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Enqueue the block's script and stylesheet (Helper::register_rtl_styles() adds the `-rtl.css` twin).
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		wp_enqueue_style( 'perflocale-admin-wc-stock', PERFLOCALE_URL . 'assets/css/admin-wc-stock.css', [], PERFLOCALE_VERSION );
		wp_enqueue_script( 'perflocale-wc-stock', PERFLOCALE_URL . 'assets/js/wc-stock.js', [ 'wp-a11y' ], PERFLOCALE_VERSION, true );
	}

	/**
	 * What the script needs: the ajax URL, the nonce, the texts it shows.
	 *
	 * @return array<string, mixed>
	 */
	private static function client_data(): array {
		return [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
			'i18n'  => [
				'checking'    => __( 'Checking stock...', 'perflocale' ),
				'aligning'    => __( 'Aligning stock...', 'perflocale' ),
				'queued'      => __( 'The check runs in the background. Keep this page open to see the result here; it is also on the Jobs page.', 'perflocale' ),
				'caption'     => __( 'Stock per language', 'perflocale' ),
				'product'     => __( 'Product', 'perflocale' ),
				'differs'     => __( 'differs', 'perflocale' ),
				'notCompared' => __( 'not compared', 'perflocale' ),
				'align'       => __( 'Align', 'perflocale' ),
				'alignAll'    => __( 'Align all to the default language', 'perflocale' ),
				'expired'     => __( 'This page expired. Reload it and try again.', 'perflocale' ),
				'failed'      => __( 'The request failed. Check your connection and try again.', 'perflocale' ),
			],
		];
	}

	// ---------------------------------------------------------------------
	// Ajax
	// ---------------------------------------------------------------------

	/**
	 * Ajax: check stock (StockCheckJob, inline or queued).
	 *
	 * @return void
	 */
	public static function ajax_check(): void {
		self::guard();

		if ( self::job_running() ) {
			self::fail( __( 'A stock check or alignment is already running. Wait until it finishes, then try again.', 'perflocale' ), 409 );
		}

		self::respond_dispatch( Dispatcher::dispatch( new StockCheckJob(), [ 'from' => 'default' ] ), 'check' );
	}

	/**
	 * Ajax: align one listed group (inline), or every drifting group (StockAlignJob, inline or queued).
	 *
	 * @return void
	 */
	public static function ajax_align(): void {
		$sync = self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked in guard().
		$scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$raw   = isset( $_POST['expected'] ) && is_string( $_POST['expected'] ) ? wp_unslash( $_POST['expected'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, decoded and validated by StockAlignJob::expected() (positive ids, finite numbers, bounded counts).
		$group = isset( $_POST['group'] ) && is_string( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$expected = strlen( $raw ) <= self::MAX_EXPECTED_BYTES ? StockAlignJob::expected( json_decode( $raw, true, 3 ) ) : null;

		if ( ! in_array( $scope, [ 'group', 'all' ], true ) || null === $expected ) {
			self::fail( __( 'The request failed. Check your connection and try again.', 'perflocale' ), 400 );
		}

		if ( 'all' === $scope ) {
			if ( self::job_running() ) {
				self::fail( __( 'A stock check or alignment is already running. Wait until it finishes, then try again.', 'perflocale' ), 409 );
			}

			self::respond_dispatch(
				Dispatcher::dispatch(
					new StockAlignJob(),
					[
						'from'     => 'default',
						'expected' => $expected,
					]
				),
				'align'
			);
		}

		$anchor = \PerfLocale\Helper::is_ascii_digits( $group ) ? (int) $group : 0;

		if ( $anchor < 1 || [ $anchor ] !== array_keys( $expected ) ) {
			self::fail( __( 'The request failed. Check your connection and try again.', 'perflocale' ), 400 );
		}

		$result = [
			'done'        => true,
			'copies'      => 0,
			'groups'      => 0,
			'left'        => 0,
			'left_groups' => [],
		];

		try {
			$site         = new StockAlignSite( $sync );
			$translation  = $site->group_of( $anchor );
			$changed_here = false;

			if ( $translation > 0 ) {
				$site->engine()->run(
					[
						'from'      => $site->reference( 'default' ),
						'dry_run'   => false,
						'group_ids' => [ $translation ],
						'anchors'   => [ $anchor ],
						'expected'  => $expected,
					],
					static function ( array $row ) use ( &$result, &$changed_here ): void {
						if ( 'changed' === $row['status'] ) {
							++$result['copies'];
							$changed_here = true;
							return;
						}

						++$result['left'];
						$result['left_groups'][] = [
							'group'     => $row['group'],
							'parent_id' => $row['parent_id'],
							'status'    => $row['status'],
							'code'      => $row['code'] ?? '',
							'reason'    => $row['reason'] ?? '',
						];
					}
				);
			}

			$result['groups'] = $changed_here ? 1 : 0;
		} catch ( \RuntimeException $e ) {
			self::fail( __( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ), 500 );
		}

		// Same shape as the block's other answers: assets/js/wc-stock.js reads answer.view.
		wp_send_json_success( [ 'view' => self::align_view( $result ) ] );
	}

	/**
	 * Ajax: the status of a queued check or alignment of the current user.
	 *
	 * @return void
	 */
	public static function ajax_status(): void {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in guard().
		$job_id = isset( $_POST['job'] ) && is_string( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$state  = '' !== $job_id && JobState::is_safe_id( $job_id ) ? JobState::get( $job_id ) : null;

		// "Not yours" and "not found" are the same answer.
		if ( ! is_array( $state )
			|| ! in_array( $state['type'] ?? '', [ StockCheckJob::TYPE, StockAlignJob::TYPE ], true )
			|| self::int_of( $state['created_by'] ?? null ) !== (int) get_current_user_id() ) {
			self::fail( __( 'Job not found.', 'perflocale' ), 404 );
		}

		$status = self::str_of( $state['status'] ?? null );
		$result = is_array( $state['result'] ?? null ) ? $state['result'] : [];

		if ( 'complete' === $status ) {
			wp_send_json_success(
				[
					'status' => $status,
					'view'   => StockCheckJob::TYPE === $state['type'] ? self::check_view( $result ) : self::align_view( $result ),
				]
			);
		}

		if ( 'failed' === $status || 'canceled' === $status ) {
			wp_send_json_success(
				[
					'status'  => $status,
					'message' => sprintf(
						/* translators: %s: why the job stopped. */
						__( 'The stock job stopped: %s Check stock again to continue; no copy is changed twice.', 'perflocale' ),
						self::str_of( $state['error'] ?? null )
					),
				]
			);
		}

		wp_send_json_success(
			[
				'status'   => $status,
				'progress' => max( 0, min( 100, self::int_of( $state['progress'] ?? null ) ) ),
			]
		);
	}

	/**
	 * The checks every action runs first: nonce (a bad one ends the request), capability (403), API (409).
	 *
	 * @return InventorySync
	 */
	private static function guard(): InventorySync {
		check_ajax_referer( self::NONCE, '_nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			self::fail( __( 'You do not have permission to change WooCommerce stock.', 'perflocale' ), 403 );
		}

		$sync = Api::ready();

		if ( $sync instanceof \WP_Error ) {
			self::fail( $sync->get_error_message(), 409 );
		}

		return $sync;
	}

	/**
	 * Whether a stock check or alignment job of this site is queued or running.
	 *
	 * @return bool
	 */
	private static function job_running(): bool {
		foreach ( JobState::list_active_summary() as $row ) {
			if ( in_array( $row['type'] ?? '', [ StockCheckJob::TYPE, StockAlignJob::TYPE ], true ) && in_array( $row['status'] ?? '', [ 'queued', 'running' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Answer a dispatch: the view of an inline run, or the queued job's id.
	 *
	 * @param array<string, mixed> $dispatch Dispatcher::dispatch() result.
	 * @param string               $kind     check or align.
	 * @return never
	 */
	private static function respond_dispatch( array $dispatch, string $kind ): never {
		$mode = self::str_of( $dispatch['mode'] ?? null );

		if ( 'async' === $mode && is_string( $dispatch['job_id'] ?? null ) ) {
			wp_send_json_success(
				[
					'job'     => $dispatch['job_id'],
					'message' => 'check' === $kind ? __( 'The check runs in the background. Keep this page open to see the result here; it is also on the Jobs page.', 'perflocale' ) : __( 'Aligning stock...', 'perflocale' ),
				]
			);
		}

		if ( 'sync' === $mode && is_array( $dispatch['result'] ?? null ) ) {
			wp_send_json_success( [ 'view' => 'check' === $kind ? self::check_view( $dispatch['result'] ) : self::align_view( $dispatch['result'] ) ] );
		}

		if ( 'denied' === $mode ) {
			self::fail( __( 'You do not have permission to change WooCommerce stock.', 'perflocale' ), 403 );
		}

		self::fail(
			sprintf(
				/* translators: %s: why the job stopped. */
				__( 'The stock job stopped: %s Check stock again to continue; no copy is changed twice.', 'perflocale' ),
				self::str_of( $dispatch['error'] ?? null )
			),
			500
		);
	}

	/**
	 * End the request with an error message.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return never
	 */
	private static function fail( string $message, int $status ): never {
		wp_send_json_error( [ 'message' => $message ], $status );
	}

	// ---------------------------------------------------------------------
	// Views (what the script shows; built for the current user only)
	// ---------------------------------------------------------------------

	/**
	 * The check result as the block shows it: counts, texts, one row per group with a cell per active language.
	 *
	 * @param array<mixed> $result StockCheckJob result.
	 * @return array<string, mixed>
	 */
	public static function check_view( array $result ): array {
		$drifting = max( 0, self::int_of( $result['drifting'] ?? null ) );
		$copies   = max( 0, self::int_of( $result['copies'] ?? null ) );
		$skipped  = max( 0, self::int_of( $result['skipped'] ?? null ) );
		$groups   = array_values( array_filter( is_array( $result['groups'] ?? null ) ? $result['groups'] : [], 'is_array' ) );
		$aligning = max( 0, $drifting - $skipped );
		[ $names, $default ] = self::languages();

		if ( 0 === $drifting ) {
			return [
				'kind'    => 'check',
				'summary' => __( 'All language copies hold the same stock.', 'perflocale' ),
				'groups'  => [],
			];
		}

		$columns = [];

		foreach ( $names as $slug => $name ) {
			$columns[] = [
				'slug'      => $slug,
				'label'     => $slug === $default
					/* translators: %s: language name. */
					? sprintf( __( '%s (reference)', 'perflocale' ), $name )
					: $name,
				'reference' => $slug === $default,
			];
		}

		$ids = [];

		foreach ( $groups as $group ) {
			$ids[] = self::int_of( $group['group'] ?? null );
			$ids[] = self::int_of( $group['parent_id'] ?? null );
		}

		$ids = array_values( array_filter( array_unique( $ids ) ) );

		if ( [] !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		$rows = [];

		foreach ( $groups as $group ) {
			$rows[] = self::check_row( $group, array_keys( $names ) );
		}

		return [
			'kind'     => 'check',
			'summary'  => sprintf(
				/* translators: %d: number of products and variations. */
				_n( '%d product or variation has language copies with different stock.', '%d products or variations have language copies with different stock.', $drifting, 'perflocale' ),
				$drifting
			),
			'more'     => $drifting > count( $groups )
				? sprintf(
					/* translators: 1: number listed, 2: number found. */
					__( 'Showing the first %1$d of %2$d. For the full list, run wp perflocale stock-drift.', 'perflocale' ),
					count( $groups ),
					$drifting
				)
				: '',
			'confirm'  => sprintf(
				/* translators: 1: number of products and variations, 2: number of copies. */
				_n( 'This aligns %1$d product or variation to its default-language copy (copies to change: %2$d). Orders placed meanwhile are kept.', 'This aligns %1$d products or variations to their default-language copies (copies to change: %2$d). Orders placed meanwhile are kept.', $aligning, 'perflocale' ),
				$aligning,
				$copies
			),
			'canAlign' => false,
			'columns'  => $columns,
			'groups'   => $rows,
		];
	}

	/**
	 * One listed group's row.
	 *
	 * @param array<mixed> $group A StockCheckJob listed group.
	 * @param list<string> $slugs Active language slugs (the columns).
	 * @return array<string, mixed>
	 */
	private static function check_row( array $group, array $slugs ): array {
		$anchor    = self::int_of( $group['group'] ?? null );
		$parent    = self::int_of( $group['parent_id'] ?? null );
		$reference = self::int_of( $group['reference'] ?? null );
		$code      = self::str_of( $group['code'] ?? null );
		$members   = is_array( $group['members'] ?? null ) ? $group['members'] : [];
		$by_slug   = [];
		$expected  = [];
		$ref_stock = null;

		foreach ( $members as $member ) {
			[ $id, $slug, $stock ] = array_values( (array) $member ) + [ 0, '', 0.0 ];

			$by_slug[ self::str_of( $slug ) ] = self::float_of( $stock );
			$expected[ self::str_of( $id ) ]  = self::float_of( $stock );

			if ( self::int_of( $id ) === $reference ) {
				$ref_stock = self::float_of( $stock );
			}
		}

		$cells = [];

		foreach ( $slugs as $slug ) {
			$stock   = $by_slug[ $slug ] ?? null;
			$cells[] = [
				'stock'   => null === $stock ? '' : (string) $stock,
				'differs' => null !== $stock && null !== $ref_stock && ! StockDriftAlign::same( $stock, $ref_stock ),
				'empty'   => null === $stock,
			];
		}

		$name = html_entity_decode( get_the_title( $anchor ), ENT_QUOTES, 'UTF-8' );
		$link = get_edit_post_link( $parent > 0 ? $parent : $anchor, 'raw' );

		return [
			'group'      => $anchor,
			'name'       => '' !== $name ? $name : '#' . $anchor,
			'link'       => is_string( $link ) ? $link : '',
			'cells'      => $cells,
			'expected'   => [ (string) $anchor => $expected ],
			'alignLabel' => sprintf(
				/* translators: %s: product name. */
				__( 'Align %s to the default language', 'perflocale' ),
				'' !== $name ? $name : '#' . $anchor
			),
			'alignable'  => false,
			'reason'     => '' === $code ? '' : StockDriftAlign::reason( $code ),
		];
	}

	/**
	 * The alignment result as the block shows it.
	 *
	 * @param array<mixed> $result StockAlignJob result (or the inline one-group result).
	 * @return array<string, mixed>
	 */
	public static function align_view( array $result ): array {
		$copies = max( 0, self::int_of( $result['copies'] ?? null ) );
		$groups = max( 0, self::int_of( $result['groups'] ?? null ) );
		$left   = max( 0, self::int_of( $result['left'] ?? null ) );
		$listed = is_array( $result['left_groups'] ?? null ) ? $result['left_groups'] : [];
		$ids    = array_values( array_filter( array_map( static fn( $row ): int => is_array( $row ) ? self::int_of( $row['group'] ?? null ) : 0, $listed ) ) );

		if ( [] !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		$rows = [];

		foreach ( $listed as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$anchor = self::int_of( $row['group'] ?? null );
			$name   = html_entity_decode( get_the_title( $anchor ), ENT_QUOTES, 'UTF-8' );
			$rows[] = [
				'group'  => $anchor,
				'name'   => '' !== $name ? $name : '#' . $anchor,
				'reason' => self::str_of( $row['reason'] ?? null ),
			];
		}

		return [
			'kind'    => 'align',
			'summary' => sprintf(
				/* translators: 1: number of products and variations, 2: number of copies. */
				_n( 'Aligned %1$d product or variation (copies changed: %2$d).', 'Aligned %1$d products or variations (copies changed: %2$d).', $groups, 'perflocale' ),
				$groups,
				$copies
			),
			'left'    => $left > 0
				? sprintf(
					/* translators: %d: number of products and variations. */
					_n( '%d product or variation was left as it was; the reason is listed below. Check stock again to retry.', '%d products or variations were left as they were; the reasons are listed below. Check stock again to retry.', $left, 'perflocale' ),
					$left
				)
				: '',
			'rows'    => $rows,
		];
	}

	/**
	 * The active languages (slug => name) and the default slug ('' when none), from the languages table.
	 *
	 * @return array{0: array<string, string>, 1: string}
	 */
	private static function languages(): array {
		$names   = [];
		$default = '';

		try {
			foreach ( StockDriftScan::language_table() as $language ) {
				if ( $language['active'] ) {
					$names[ $language['slug'] ] = $language['name'];
				}

				if ( $language['default'] ) {
					$default = $language['slug'];
				}
			}
		} catch ( \RuntimeException $e ) {
			return [ [], '' ];
		}

		return [ $names, $default ];
	}

	/**
	 * A stored number as an int (0 when it is none).
	 *
	 * @param mixed $value Value read from a job row.
	 * @return int
	 */
	private static function int_of( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A stored number as a float (0 when it is none).
	 *
	 * @param mixed $value Value read from a job row.
	 * @return float
	 */
	private static function float_of( mixed $value ): float {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * A stored scalar as a string ('' when it is none).
	 *
	 * @param mixed $value Value read from a job row.
	 * @return string
	 */
	private static function str_of( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
