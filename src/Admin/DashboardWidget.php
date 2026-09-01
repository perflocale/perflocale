<?php
/**
 * WP-admin Dashboard widget: translation overview at a glance.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Database\Repository\TranslationLinkRepository;
use PerfLocale\Helper;
use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a compact "PerfLocale" widget to the WordPress Dashboard showing
 * active languages and content-translation coverage.
 *
 * Loads ONLY on the Dashboard: it hooks `wp_dashboard_setup`, which core
 * fires solely while building the dashboard screen. Gated on the
 * `dashboard_widget_enabled` setting and the `perflocale_manage_languages`
 * capability. All numbers come from a cached stats array (short-circuited
 * by the autoloaded `perflocale_has_any_groups` sentinel), so a dashboard
 * load never triggers a live COUNT query while the cache is warm.
 */
final class DashboardWidget {

	private const WIDGET_ID = 'perflocale_dashboard_overview';

	/**
	 * Cache key + TTL for the computed stats. Dashboard glance data does
	 * not need to be real-time; a short TTL caps recompute to at most once
	 * per window per blog.
	 */
	private const CACHE_KEY = 'dashboard_widget_stats';
	private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Register the dashboard-setup hook. Cheap: a single add_action whose
	 * callback only fires on the Dashboard screen.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'maybe_add_widget' ] );
	}

	/**
	 * Register the widget if it's enabled and the user may manage
	 * translations. Runs only on the Dashboard (wp_dashboard_setup).
	 *
	 * @return void
	 */
	public function maybe_add_widget(): void {
		if ( ! (bool) $this->settings->get( 'dashboard_widget_enabled', false ) ) {
			return;
		}

		if ( ! current_user_can( 'perflocale_manage_languages' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'PerfLocale', 'perflocale' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the widget body from cached stats.
	 *
	 * @return void
	 */
	public function render(): void {
		$s          = $this->get_stats();
		$manage_url = admin_url( 'admin.php?page=perflocale-translations' );
		$lang_url   = admin_url( 'admin.php?page=perflocale-languages' );

		echo '<div class="perflocale-dashwidget">';

		if ( ! $s['has_languages'] ) {
			printf(
				'<p class="perflocale-dashwidget__empty">%s</p><p><a class="button button-primary" href="%s">%s</a></p>',
				esc_html__( 'No languages yet. Add one to start serving a multilingual site.', 'perflocale' ),
				esc_url( $lang_url ),
				esc_html__( 'Add a language', 'perflocale' )
			);
			echo '</div>';
			return;
		}

		// ── Metrics row ──────────────────────────────────────────────
		echo '<div class="perflocale-dashwidget__stats">';
		$this->metric( (string) $s['languages'], __( 'Languages', 'perflocale' ) );
		$this->metric( (string) $s['translated'], __( 'Translated', 'perflocale' ) );
		$this->metric( $s['percent'] . '%', __( 'Coverage', 'perflocale' ) );
		echo '</div>';

		// ── Coverage bar (pure CSS; width is data-driven) ────────────
		if ( ! $s['has_targets'] ) {
			// Only the default language exists — nothing to translate into.
			printf(
				'<p class="perflocale-dashwidget__caption">%s</p>',
				esc_html__( 'Add a second language to start tracking translation coverage.', 'perflocale' )
			);
		} else {
			printf(
				'<div class="perflocale-dashwidget__bar" role="progressbar" aria-valuenow="%1$d" aria-valuemin="0" aria-valuemax="100">'
					. '<span class="perflocale-dashwidget__bar-fill" style="width:%1$d%%;"></span></div>',
				(int) $s['percent']
			);

			if ( (int) $s['target'] > 0 ) {
				$remaining = max( 0, (int) $s['target'] - (int) $s['translated'] );
				printf(
					'<p class="perflocale-dashwidget__caption">%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: translated count, 2: total target count, 3: remaining count */
							__( '%1$d of %2$d translations done · %3$d to go', 'perflocale' ),
							(int) $s['translated'],
							(int) $s['target'],
							$remaining
						)
					)
				);
			} else {
				// 2+ languages but no published source content yet.
				printf(
					'<p class="perflocale-dashwidget__caption">%s</p>',
					esc_html__( 'No published content to translate yet.', 'perflocale' )
				);
			}
		}

		// ── Language chips (each links to its edit screen) ───────────
		if ( ! empty( $s['lang_chips'] ) ) {
			echo '<div class="perflocale-dashwidget__langs">';
			foreach ( $s['lang_chips'] as $chip ) {
				$id = (int) ( $chip['id'] ?? 0 );

				if ( $id > 0 ) {
					printf(
						'<a class="perflocale-dashwidget__chip" href="%s">',
						esc_url( admin_url( 'admin.php?page=perflocale-languages&action=edit&language_id=' . $id ) )
					);
				} else {
					echo '<span class="perflocale-dashwidget__chip">';
				}

				if ( $chip['flag'] !== '' ) {
					printf( '<span class="perflocale-dashwidget__flag">%s</span> ', esc_html( $chip['flag'] ) );
				}

				echo esc_html( $chip['name'] );
				echo $id > 0 ? '</a>' : '</span>';
			}
			echo '</div>';
		}

		// ── Actions ──────────────────────────────────────────────────
		printf(
			'<p class="perflocale-dashwidget__actions"><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
			esc_url( $manage_url ),
			esc_html__( 'Manage translations', 'perflocale' ),
			esc_url( $lang_url ),
			esc_html__( 'Languages', 'perflocale' )
		);

		echo '</div>';
	}

	/**
	 * Output one metric cell.
	 *
	 * @param string $value Pre-formatted value.
	 * @param string $label Human label.
	 * @return void
	 */
	private function metric( string $value, string $label ): void {
		printf(
			'<div class="perflocale-dashwidget__metric"><span class="perflocale-dashwidget__value">%s</span><span class="perflocale-dashwidget__label">%s</span></div>',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	/**
	 * Compute (and cache) the widget stats.
	 *
	 * Cheap by construction: the active-language list is already cached in
	 * the language bootstrap; the coverage matrix query is skipped entirely
	 * when the autoloaded `perflocale_has_any_groups` sentinel says there
	 * are no translation links yet. The whole result is memoized via the
	 * CacheManager cascade for {@see CACHE_TTL}.
	 *
	 * @return array{
	 *     has_languages: bool,
	 *     has_targets: bool,
	 *     languages: int,
	 *     translated: int,
	 *     target: int,
	 *     percent: int,
	 *     lang_chips: array<int, array{name: string, flag: string, slug: string, id: int}>,
	 * }
	 */
	private function get_stats(): array {
		$plugin = Plugin::get_instance();
		$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		$loader = function () use ( $plugin, $cache ): array {
			$lang_repo = $plugin->get( 'lang_repo' );
			$languages = (array) $lang_repo->get_active();
			$default   = $lang_repo->get_default();

			$chips = [];
			foreach ( array_slice( $languages, 0, 8 ) as $lang ) {
				$chips[] = [
					'name' => (string) ( $lang->name ?? $lang->slug ?? '' ),
					'flag' => Helper::get_flag_emoji( $lang ),
					'slug' => (string) ( $lang->slug ?? '' ),
					'id'   => (int) ( $lang->id ?? 0 ),
				];
			}

			$stats = [
				'has_languages' => ! empty( $languages ),
				'has_targets'   => false,
				'languages'     => count( $languages ),
				'translated'    => 0,
				'target'        => 0,
				'percent'       => 0,
				'lang_chips'    => $chips,
			];

			$default_id        = $default ? (int) $default->id : 0;
			$non_default_langs = $default
				? array_values( array_filter( $languages, static fn( $l ) => $l->slug !== $default->slug ) )
				: [];

			$stats['has_targets'] = $default_id > 0 && ! empty( $non_default_langs );

			// No targets, or no translation links exist yet → coverage is 0
			// and we skip every count query (sentinel short-circuit).
			if ( ! $stats['has_targets'] || get_option( 'perflocale_has_any_groups', '' ) !== '1' ) {
				return $stats;
			}

			$post_types = (array) $this->settings->get_translatable_post_types();
			if ( empty( $post_types ) ) {
				return $stats;
			}

			$link_repo    = new TranslationLinkRepository( $cache );
			$total_source = 0;
			foreach ( $post_types as $pt ) {
				$total_source += $link_repo->count_source_published( $default_id, (string) $pt );
			}

			$matrix = $link_repo->count_status_matrix(
				array_map( static fn( $l ) => (int) $l->id, $non_default_langs ),
				$post_types
			);

			$translated = 0;
			foreach ( $non_default_langs as $lang ) {
				foreach ( $post_types as $pt ) {
					$translated += (int) ( $matrix[ (int) $lang->id ][ $pt ]['published'] ?? 0 );
				}
			}

			$target              = $total_source * count( $non_default_langs );
			$stats['translated'] = $translated;
			$stats['target']     = $target;
			$stats['percent']    = $target > 0 ? min( 100, (int) round( ( $translated / $target ) * 100 ) ) : 0;

			return $stats;
		};

		if ( $cache !== null ) {
			return (array) $cache->get( self::CACHE_KEY, $loader, self::CACHE_TTL );
		}

		return $loader();
	}
}
