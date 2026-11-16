<?php
/**
 * Settings > URL & Routing > URL Bases tab.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin\UrlBases;

use PerfLocale\Admin\Typed;
use PerfLocale\Router\UrlBases\Lifecycle;
use PerfLocale\Router\UrlBases\Publication;
use PerfLocale\Router\UrlBases\Registry;
use PerfLocale\Router\UrlBases\Validator;
use PerfLocale\Router\UrlBases\Values;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The URL Bases settings tab: global, group and per-key switches, one value per non-default language, history.
 *
 * Saves through admin-post (`perflocale_save_url_bases`) and compiles the map in the same request.
 *
 * @phpstan-import-type Report from \PerfLocale\Router\UrlBases\Compiler
 * @phpstan-import-type KeyRow from \PerfLocale\Router\UrlBases\Compiler
 * @phpstan-import-type ValueSet from \PerfLocale\Router\UrlBases\Values
 * @phpstan-type KeptRow array{key: string, keys: list<string>, label: string, lang: string, from: string, to: string, entry: string, entries: list<string>, stop: bool, why: string}
 *
 * @internal
 */
final class Tab {

	public const ACTION = 'perflocale_save_url_bases';

	/**
	 * Refused inputs carried to the next render: at most this many.
	 */
	private const MAX_REFUSED = 20;

	/**
	 * Result statuses a redirect may carry.
	 */
	private const STATUSES = [ 'published', 'unchanged', 'busy', 'stale', 'lease_lost', 'read_failed', 'failed', 'too_large', 'notsaved', 'no_rules', 'truncated', 'forget_unconfirmed', 'unknown_key', 'forget_stale', 'forget_invalid', 'forget_too_many', 'forget_none' ];

	/**
	 * Render the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		// The screen an operator opens after a restore: a marker beside restored values goes here at the latest.
		Lifecycle::validate_none();
		$this->render_page( Values::get(), Publication::dry_run(), self::languages() );
	}

	/**
	 * Render the tab from its data: the settings form (Save is its only submit) and, when old addresses are kept, the
	 * separate form that stops their redirects.
	 *
	 * @param ValueSet                                                 $values Values.
	 * @param array{report: Report}                                    $build  Dry-run build.
	 * @param array<string, string>                                    $langs  Slug => language name.
	 * @return void
	 */
	public function render_page( array $values, array $build, array $langs ): void {
		$report = $build['report'];
		$keys   = $report['keys'];

		$this->notices( $keys, $langs );
		?>
		<p><?php echo esc_html__( 'Translate the URL bases of content types and taxonomies, for example /de/produkte/ instead of /de/product/. The default language keeps the bases of Settings > Permalinks.', 'perflocale' ); ?></p>
		<?php if ( [] === $langs ) : ?>
			<p class="perflocale-ub-empty">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ); ?>"><?php echo esc_html__( 'Add a language to translate URL bases', 'perflocale' ); ?></a>
			</p>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="ub[form]" value="1">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'URL bases', 'perflocale' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ub[on]" value="1" <?php checked( $values['on'] ); ?>>
							<?php echo esc_html__( 'Use the translated URL bases', 'perflocale' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Tick to use the bases you type.', 'perflocale' ); ?></p>
						<?php if ( self::has_empty_field( $keys, $report, $values, $langs ) ) : ?>
							<p class="description"><?php echo esc_html__( 'Type the word for each language, for example produkte.', 'perflocale' ); ?></p>
						<?php endif; ?>
						<p class="description"><?php echo esc_html__( 'When off, links use the bases of Settings > Permalinks and translated addresses that were in use redirect to them.', 'perflocale' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Old addresses', 'perflocale' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ub[redirect]" value="1" <?php checked( $values['redirect'] ); ?>>
							<?php echo esc_html__( 'Redirect old and other-language bases to the current base', 'perflocale' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'Applies to addresses with an old base, another language\'s base, or the base from Settings > Permalinks. When off, such addresses still show the page, with a canonical link to the current address.', 'perflocale' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			$this->site_problems( $report );

			foreach ( $report['groups'] as $grp => $label ) {
				$this->group( $grp, $label, $keys, $report, $values, $langs );
			}
			?>

			<?php // Last field: PHP drops the end of a form over max_input_vars, and a save without it is refused. ?>
			<input type="hidden" name="ub[end]" value="1">
			<p class="submit">
				<?php submit_button( __( 'Save Changes', 'perflocale' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
		<?php endif; ?>
		<?php
		$this->forget_form( self::kept_redirects( $keys, $report, $values, $langs ) );
	}

	/**
	 * Whether a value field the table prints is empty (not derived, not set by code).
	 *
	 * @param array<string, KeyRow> $keys   Reported keys.
	 * @param Report                $report Report.
	 * @param ValueSet              $values Values.
	 * @param array<string, string> $langs  Languages.
	 * @return bool
	 */
	private static function has_empty_field( array $keys, array $report, array $values, array $langs ): bool {
		foreach ( $keys as $key => $k ) {
			if ( 'undeclared' === $k['status'] || $k['derived'] ) {
				continue;
			}

			foreach ( array_keys( $langs ) as $slug ) {
				if ( 'ok' !== ( $report['code'][ $key ][ $slug ]['status'] ?? '' ) && '' === ( $values['keys'][ $key ]['tr'][ $slug ] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The redirects "Stop these redirects" ends, one row per redirect the runtime has: a key (a single/archive pair on one
	 * base is one) and an old address, with every language whose history or off typed value it covers. `entries` are what
	 * a tick forgets (Values::forget()); a row whose address another alias still needs (a current value, the registered
	 * base) has `stop` false and the reason in `why`.
	 *
	 * @param array<string, KeyRow> $keys   Reported keys.
	 * @param Report                $report Report.
	 * @param ValueSet              $values Values.
	 * @param array<string, string> $langs  Slug => language name.
	 * @return list<KeptRow>
	 */
	public static function kept_redirects( array $keys, array $report, array $values, array $langs ): array {
		// Read from a failed read: no row can be confirmed, so none is offered.
		if ( in_array( 'read_failed', $report['errors'], true ) ) {
			return [];
		}

		$off    = self::off_keys( $keys, $values );
		$groups = [];

		foreach ( $keys as $key => $k ) {
			$on      = ! ( $off[ $key ] ?? true );
			$lead    = self::lead_key( $key, $keys );
			$current = static function ( string $slug ) use ( $key, $k, $on, $report ): string {
				$cell = $report['entries'][ $key ][ $slug ] ?? null;

				return $on && null !== $cell && 'ok' === $cell['status'] && '' !== $cell['value'] ? $cell['value'] : $k['registered'];
			};
			$add     = static function ( string $slug, string $from, string $to ) use ( &$groups, $key, $lead ): void {
				$groups[ $lead . '|' . $from ]['from']                                    = $from;
				$groups[ $lead . '|' . $from ]['lead']                                    = $lead;
				$groups[ $lead . '|' . $from ]['parts'][ Values::entry_id( $key, $slug, $from ) ] = [ $slug, $to ];
			};

			foreach ( $report['history'][ $key ] ?? [] as $slug => $rows ) {
				foreach ( $rows as $row ) {
					if ( 'ok' === $row['status'] ) {
						$add( (string) $slug, $row['value'], '*' === $slug ? $k['registered'] : $current( (string) $slug ) );
					}
				}
			}

			// Off, a typed value that was live redirects too (one never live compiles `unused`), and forgetting drops it
			// (Values::forget()); a value set by code stays.
			if ( ! $on && ! $k['derived'] ) {
				foreach ( $report['entries'][ $key ] ?? [] as $slug => $cell ) {
					if ( 'ok' === $cell['status'] && '' !== $cell['value'] && $cell['value'] !== $k['registered'] && ( $values['keys'][ $key ]['tr'][ $slug ] ?? null ) === $cell['value'] ) {
						$add( (string) $slug, $cell['value'], $k['registered'] );
					}
				}
			}
		}

		$out = [];

		foreach ( $groups as $g ) {
			$out[] = self::redirect_row( $g['lead'], $g['from'], $g['parts'], self::still_needed( $g['lead'], $g['from'], $keys, $report, $values, $off, $langs ), $keys, $langs );
		}

		return $out;
	}

	/**
	 * The key that names a redirect: the single key of a single/archive pair on one base, else the key itself.
	 *
	 * @param string                $key  Key.
	 * @param array<string, KeyRow> $keys Reported keys.
	 * @return string
	 */
	private static function lead_key( string $key, array $keys ): string {
		$single = 'pt:' . ( explode( ':', $key, 2 )[1] ?? '' );

		return 'archive' === $keys[ $key ]['role'] && isset( $keys[ $single ] ) && '' !== $keys[ $key ]['eff'] && $keys[ $single ]['eff'] === $keys[ $key ]['eff'] && $keys[ $single ]['registered'] === $keys[ $key ]['registered'] ? $single : $key;
	}

	/**
	 * Why a redirect stays whatever is forgotten, '' when nothing else needs it: another alias of the same key and address
	 * that a forget cannot remove (a current value while on or set by code, the registered base).
	 *
	 * @param string                $lead   Lead key.
	 * @param string                $from   Address.
	 * @param array<string, KeyRow> $keys   Reported keys.
	 * @param Report                $report Report.
	 * @param ValueSet              $values Values.
	 * @param array<string, bool>   $off    Off keys.
	 * @param array<string, string> $langs  Slug => language name.
	 * @return string
	 */
	private static function still_needed( string $lead, string $from, array $keys, array $report, array $values, array $off, array $langs ): string {
		foreach ( $keys as $key => $k ) {
			if ( self::lead_key( $key, $keys ) !== $lead ) {
				continue;
			}

			if ( $from === $k['registered'] ) {
				return __( 'Not stopped here: it is the base from Settings > Permalinks.', 'perflocale' );
			}

			$on = ! ( $off[ $key ] ?? true );

			foreach ( $report['entries'][ $key ] ?? [] as $slug => $cell ) {
				$typed_off = ! $on && ( $values['keys'][ $key ]['tr'][ $slug ] ?? null ) === $cell['value'];

				// An off shop-derived value is checked for the screen only, never published.
				if ( 'ok' === $cell['status'] && $from === $cell['value'] && ! $typed_off && ( $on || ! $k['derived'] ) ) {
					/* translators: %s: language name, e.g. "Deutsch" */
					return sprintf( __( 'Not stopped here: it is still the %s base. Change that base first.', 'perflocale' ), $langs[ (string) $slug ] ?? (string) $slug );
				}
			}
		}

		return '';
	}

	/**
	 * One kept redirect, its addresses as a visitor types them; '*' is an earlier registered base (every language). The id
	 * is the entry itself for a lone earlier base, else a row id (Values::row_id()).
	 *
	 * @param string                                   $lead  Lead key.
	 * @param string                                   $from  Old address.
	 * @param array<string, array{0: string, 1: string}> $parts Entry id => [ language slug, where it redirects ].
	 * @param string                                   $why   Why it cannot be stopped, '' when it can.
	 * @param array<string, KeyRow>                    $keys  Reported keys.
	 * @param array<string, string>                    $langs Slug => language name.
	 * @return KeptRow
	 */
	private static function redirect_row( string $lead, string $from, array $parts, string $why, array $keys, array $langs ): array {
		$names = [];
		$to    = [];

		foreach ( $parts as [ $slug, $target ] ) {
			$names[] = '*' === $slug ? __( 'All languages', 'perflocale' ) : ( $langs[ $slug ] ?? $slug );
			$to[]    = '/' . rawurldecode( $target ) . '/';
		}

		$entries = array_keys( $parts );
		$lone    = 1 === count( $entries ) && '*' === ( Values::entry( $entries[0] )[1] ?? '' );

		return [
			'key'     => $lead,
			'keys'    => array_values( array_unique( array_map( static fn( string $e ): string => explode( '|', $e, 2 )[0], $entries ) ) ),
			'label'   => $keys[ $lead ]['label'] ?? $lead,
			'lang'    => implode( ', ', array_unique( $names ) ),
			'from'    => '/' . rawurldecode( $from ) . '/',
			'to'      => implode( ', ', array_unique( $to ) ),
			'entry'   => $lone ? $entries[0] : Values::row_id( $lead, $from ),
			'entries' => $entries,
			'stop'    => '' === $why,
			'why'     => $why,
		];
	}

	/**
	 * Off keys as the compiler sees them (Compiler::collect_keys()): the list and the forget share it.
	 *
	 * An undeclared key is left out: it is not compiled, so nothing proves its values may go.
	 *
	 * @param array<string, KeyRow> $keys   Reported keys.
	 * @param ValueSet              $values Values.
	 * @return array<string, bool> Key => off.
	 */
	private static function off_keys( array $keys, array $values ): array {
		$off = [];

		foreach ( $keys as $key => $k ) {
			if ( 'undeclared' !== $k['status'] ) {
				$off[ $key ] = ! $values['on'] || false === ( $values['groups'][ $k['grp'] ] ?? true ) || false === ( $values['keys'][ $key ]['on'] ?? true ) || ! $k['declared'];
			}
		}

		// A single/archive pair on one base is one switch: either off turns both off.
		foreach ( $off as $key => $is_off ) {
			$single = 'pt:' . ( explode( ':', $key, 2 )[1] ?? '' );

			if ( 'archive' === $keys[ $key ]['role'] && isset( $off[ $single ] ) && '' !== $keys[ $key ]['eff'] && $keys[ $single ]['eff'] === $keys[ $key ]['eff'] && $keys[ $single ]['registered'] === $keys[ $key ]['registered'] && ( $is_off || $off[ $single ] ) ) {
				$off[ $key ]    = true;
				$off[ $single ] = true;
			}
		}

		return $off;
	}

	/**
	 * The form that stops the kept redirects: one unticked checkbox per row that can be stopped (its id), the reason on a
	 * row that cannot, "Select All" shown by admin-settings.js, and a required confirmation. Nothing when none.
	 *
	 * @param list<KeptRow> $kept Kept redirects.
	 * @return void
	 */
	private function forget_form( array $kept ): void {
		if ( [] === $kept ) {
			return;
		}
		?>
		<h2><?php echo esc_html__( 'Stop redirecting old addresses', 'perflocale' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="ub_forget" value="1">
			<p class="description"><?php echo esc_html__( 'When you change a translated base, its old address keeps redirecting to the new one, so saved links and search results keep working. Stop these redirects only when you no longer need them, for example to use the old word for something else. The old addresses then show "not found".', 'perflocale' ); ?></p>
			<div class="perflocale-table-responsive perflocale-ub-table-wrap">
			<table class="widefat striped perflocale-ub-kept">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Old addresses that redirect', 'perflocale' ); ?></caption>
				<thead>
					<tr>
						<th scope="col" class="check-column">
							<span class="screen-reader-text"><?php echo esc_html__( 'Stop redirect', 'perflocale' ); ?></span>
							<span class="perflocale-ub-forget-all" hidden>
								<label class="screen-reader-text" for="perflocale-ub-forget-all"><?php echo esc_html__( 'Select All', 'perflocale' ); ?></label>
								<input type="checkbox" id="perflocale-ub-forget-all">
							</span>
						</th>
						<th scope="col"><?php echo esc_html__( 'Base', 'perflocale' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Language', 'perflocale' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Old address', 'perflocale' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Redirects to', 'perflocale' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $kept as $i => $row ) : ?>
						<tr>
							<td class="check-column">
								<?php if ( $row['stop'] ) : ?>
									<input type="checkbox" id="<?php echo esc_attr( 'perflocale-ub-forget-' . $i ); ?>" name="ub_forget_entries[]" value="<?php echo esc_attr( $row['entry'] ); ?>">
									<label class="screen-reader-text" for="<?php echo esc_attr( 'perflocale-ub-forget-' . $i ); ?>">
										<?php
										/* translators: 1: URL base label, 2: language names, 3: old address, e.g. "/produkt/" */
										echo esc_html( sprintf( __( '%1$s, %2$s: %3$s', 'perflocale' ), $row['label'], $row['lang'], $row['from'] ) );
										?>
									</label>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td><?php echo esc_html( $row['lang'] ); ?></td>
							<td><code><?php echo esc_html( $row['from'] ); ?></code></td>
							<td><code><?php echo esc_html( $row['to'] ); ?></code><?php if ( ! $row['stop'] ) : ?><p class="description"><?php echo esc_html( $row['why'] ); ?></p><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p>
				<label>
					<input type="checkbox" name="ub_forget_confirm" value="1">
					<?php echo esc_html__( 'Yes, these old addresses may show "not found" from now on', 'perflocale' ); ?>
				</label>
			</p>
			<p class="submit"><button type="submit" class="button"><?php echo esc_html__( 'Stop these redirects', 'perflocale' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * One group: its switch (not for core) and a row per key.
	 *
	 * @param string                $grp    Group id.
	 * @param string                $label  Group label.
	 * @param array<string, KeyRow> $keys   Reported keys.
	 * @param Report                $report Report.
	 * @param ValueSet              $values Values.
	 * @param array<string, string> $langs  Slug => language name.
	 * @return void
	 */
	private function group( string $grp, string $label, array $keys, array $report, array $values, array $langs ): void {
		$mine = array_filter( $keys, static fn( array $k ): bool => $k['grp'] === $grp && 'undeclared' !== $k['status'] );

		if ( [] === $mine ) {
			return;
		}
		?>
		<h2><?php echo esc_html( $label ); ?></h2>
		<?php if ( 'core' !== $grp ) : ?>
			<input type="hidden" name="ub[rendered_groups][]" value="<?php echo esc_attr( $grp ); ?>">
			<p>
				<label>
					<input type="checkbox" name="ub[groups][<?php echo esc_attr( $grp ); ?>]" value="1" <?php checked( $values['groups'][ $grp ] ?? true ); ?>>
					<?php
					/* translators: %s: add-on or plugin name, e.g. "WooCommerce" */
					echo esc_html( sprintf( __( 'Translate the URL bases of %s', 'perflocale' ), $label ) );
					?>
				</label>
			</p>
		<?php endif; ?>
		<div class="perflocale-table-responsive perflocale-ub-table-wrap">
		<table class="widefat striped perflocale-ub-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Base', 'perflocale' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'On', 'perflocale' ); ?></th>
					<?php foreach ( $langs as $slug => $name ) : ?>
						<th scope="col"><?php echo esc_html( $name ); ?> <code><?php echo esc_html( $slug ); ?></code></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $mine as $key => $k ) {
					$this->row( $key, $k, $report, $values, $langs );
				}
				?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * One key's row.
	 *
	 * @param string                $key    Key.
	 * @param KeyRow                $k      Reported key.
	 * @param Report                $report Report.
	 * @param ValueSet              $values Values.
	 * @param array<string, string> $langs  Languages.
	 * @return void
	 */
	private function row( string $key, array $k, array $report, array $values, array $langs ): void {
		$entry   = $values['keys'][ $key ] ?? [];
		$derived = $k['derived'];
		$id      = 'perflocale-ub-' . sanitize_html_class( str_replace( ':', '-', $key ) );
		$history = $report['history'][ $key ] ?? [];
		?>
		<tr>
			<td>
				<input type="hidden" name="ub[rendered][]" value="<?php echo esc_attr( $key ); ?>">
				<strong><?php echo esc_html( $k['label'] ); ?></strong><br>
				<code>/<?php echo esc_html( $k['registered'] ); ?>/</code>
				<?php if ( 'ok' !== $k['status'] ) : ?>
					<p class="description"><?php echo esc_html( self::status_text( $k['status'], $k['reason'], $k['detail'] ) ); ?></p>
				<?php elseif ( $k['stale'] ) : ?>
					<p class="description"><?php echo esc_html__( 'The stored permalinks differ from this setup: open Settings > Permalinks and save.', 'perflocale' ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $k['bare_by'] ) : ?>
					<p class="description">
						<?php
						/* translators: %s: why the base's own address keeps its route */
						echo esc_html( sprintf( __( 'Note: %s.', 'perflocale' ), Health::bare_note( $k['bare_by'], $k['eff'] ) ) );
						?>
					</p>
				<?php endif; ?>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id . '-on' ); ?>">
					<?php
					/* translators: %s: URL base label, e.g. "Product" */
					echo esc_html( sprintf( __( 'Translate the %s base', 'perflocale' ), $k['label'] ) );
					?>
				</label>
				<input type="checkbox" id="<?php echo esc_attr( $id . '-on' ); ?>" name="ub[keys][<?php echo esc_attr( $key ); ?>][on]" value="1" <?php checked( $entry['on'] ?? true ); ?>>
			</td>
			<?php foreach ( $langs as $slug => $name ) : ?>
				<?php
				$cell  = $report['entries'][ $key ][ $slug ] ?? null;
				$value = $derived ? ( $cell['value'] ?? '' ) : ( $entry['tr'][ $slug ] ?? '' );
				$field = $id . '-' . sanitize_html_class( $slug );
				$code  = $report['code'][ $key ][ $slug ] ?? null;
				?>
				<td>
					<?php if ( $derived ) : ?>
						<code><?php echo esc_html( '' !== $value ? '/' . $value . '/' : '-' ); ?></code>
						<p class="description"><?php echo esc_html__( 'Follows the translated Shop page.', 'perflocale' ); ?></p>
					<?php elseif ( 'ok' === ( $code['status'] ?? '' ) ) : ?>
						<code><?php echo esc_html( '/' . rawurldecode( '' !== $code['value'] ? $code['value'] : $k['registered'] ) . '/' ); ?></code>
						<p class="description"><?php echo esc_html__( 'Set by code.', 'perflocale' ); ?></p>
						<?php if ( '' !== $value ) : ?>
							<p class="description">
								<?php
								/* translators: %s: address, e.g. "/produkte/" */
								echo esc_html( sprintf( __( 'Typed value %s is used when the code sets none.', 'perflocale' ), '/' . rawurldecode( $value ) . '/' ) );
								?>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<label class="screen-reader-text" for="<?php echo esc_attr( $field ); ?>">
							<?php
							/* translators: 1: URL base label, 2: language name */
							echo esc_html( sprintf( __( '%1$s base in %2$s', 'perflocale' ), $k['label'], $name ) );
							?>
						</label>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $field ); ?>" name="ub[keys][<?php echo esc_attr( $key ); ?>][tr][<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( rawurldecode( $value ) ); ?>" placeholder="<?php echo esc_attr( $k['registered'] ); ?>">
					<?php endif; ?>
					<?php if ( 'refused' === ( $code['status'] ?? '' ) ) : ?>
						<p class="description">
							<?php
							$why = Validator::reason_text( $code['reason'], $code['detail'] );
							echo esc_html(
								'' !== $code['value']
									/* translators: 1: value, 2: reason */
									? sprintf( __( 'Set by code: "%1$s" is not used: %2$s.', 'perflocale' ), rawurldecode( $code['value'] ), $why )
									/* translators: %s: reason */
									: sprintf( __( 'Set by code: not used: %s.', 'perflocale' ), $why )
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( 'suspended' === ( $cell['status'] ?? '' ) ) : ?>
						<p class="description">
							<?php
							/* translators: %s: reason, e.g. "a published page uses the address \"produkte\"" */
							echo esc_html( sprintf( __( 'Not in use: %s.', 'perflocale' ), Validator::reason_text( $cell['reason'], $cell['detail'] ) ) );
							?>
						</p>
					<?php endif; ?>
					<?php
					$old = [];

					foreach ( $history[ $slug ] ?? [] as $h ) {
						$old[] = '/' . rawurldecode( $h['value'] ) . '/';
					}

					if ( [] !== $old ) :
						?>
						<p class="description">
							<?php
							/* translators: %s: comma-separated old addresses, e.g. "/produkt/, /artikel/" */
							echo esc_html( sprintf( __( 'Old: %s (redirected)', 'perflocale' ), implode( ', ', $old ) ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			<?php endforeach; ?>
		</tr>
		<?php
	}

	/**
	 * Site-wide problems: no rules, too many unverified routes, caps, refused providers.
	 *
	 * @param Report $report Report.
	 * @return void
	 */
	private function site_problems( array $report ): void {
		$lines = [];

		foreach ( $report['errors'] as $code ) {
			$lines[] = Validator::reason_text( $code, 'cap_size' === $code ? number_format_i18n( $report['caps']['max_bytes'] / 1024 ) . ' KB' : '' );
		}

		foreach ( $report['providers'] as $issue ) {
			/* translators: 1: provider class or group, 2: reason */
			$lines[] = sprintf( __( 'URL bases provider %1$s ignored: %2$s.', 'perflocale' ), $issue['provider'], Validator::reason_text( $issue['code'], $issue['detail'] ) );
		}

		foreach ( $report['keys'] as $key => $k ) {
			if ( 'undeclared' === $k['status'] ) {
				/* translators: %s: key, e.g. "pt:event" */
				$lines[] = sprintf( __( 'Saved values of %s are not used: the content type is not registered.', 'perflocale' ), $key );
			}
		}

		if ( [] === $lines ) {
			return;
		}
		?>
		<div class="notice notice-warning inline">
			<?php foreach ( $lines as $line ) : ?>
				<p><?php echo esc_html( $line ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The notices after a save (redirect arguments).
	 *
	 * @param array<string, KeyRow> $keys  Reported keys.
	 * @param array<string, string> $langs Languages.
	 * @return void
	 */
	private function notices( array $keys, array $langs ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flash arguments of the save redirect.
		$status  = isset( $_GET['ub-status'] ) && is_string( $_GET['ub-status'] ) ? sanitize_key( wp_unslash( $_GET['ub-status'] ) ) : '';
		$refused = isset( $_GET['ub-refused'] ) && is_string( $_GET['ub-refused'] ) ? sanitize_text_field( wp_unslash( $_GET['ub-refused'] ) ) : '';
		// phpcs:enable

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}

		$texts = [
			'published'  => [ 'success', __( 'URL bases saved. Links and redirects use the new bases now.', 'perflocale' ) ],
			'unchanged'  => [ 'success', __( 'URL bases saved.', 'perflocale' ) ],
			'busy'       => [ 'warning', __( 'URL bases saved. Another update was running: the new bases go live on the next admin page load.', 'perflocale' ) ],
			'stale'      => [ 'warning', __( 'URL bases saved. Other settings changed at the same time: the new bases go live on the next admin page load.', 'perflocale' ) ],
			'lease_lost' => [ 'warning', __( 'URL bases saved. The update took too long: it is retried on the next admin page load.', 'perflocale' ) ],
			'read_failed' => [ 'error', __( 'URL bases saved, but a database read failed while publishing them. The previous bases stay in use; it is retried on the next admin page load.', 'perflocale' ) ],
			'failed'     => [ 'error', __( 'URL bases saved, but the database refused the update. The previous bases stay in use; it is retried on the next admin page load.', 'perflocale' ) ],
			'too_large'  => [ 'error', __( 'URL bases saved, but they are larger than the map limit. The previous bases stay in use, without the values this save refused: remove old addresses or values.', 'perflocale' ) ],
			'notsaved'   => [ 'error', __( 'URL bases not saved: another save was running. Try again.', 'perflocale' ) ],
			'no_rules'   => [ 'warning', __( 'URL bases saved. WordPress has no rewrite rules stored right now: the previous bases stay in use until Settings > Permalinks is saved.', 'perflocale' ) ],
			/* translators: %d: the PHP max_input_vars setting */
			'truncated'  => [ 'error', sprintf( __( 'URL bases not saved: the server dropped the end of the form (PHP max_input_vars is %d). Raise max_input_vars and save again.', 'perflocale' ), (int) ini_get( 'max_input_vars' ) ) ],
			'forget_unconfirmed' => [ 'warning', __( 'Redirects not stopped: tick the confirmation first.', 'perflocale' ) ],
			'forget_none'        => [ 'warning', __( 'Redirects not stopped: tick the addresses to stop first.', 'perflocale' ) ],
			'unknown_key' => [ 'error', __( 'Redirects not stopped: that URL base does not exist on this site.', 'perflocale' ) ],
			'forget_stale' => [ 'warning', __( 'Redirects not stopped: the list changed since this page loaded. Check the list and try again.', 'perflocale' ) ],
			'forget_invalid' => [ 'error', __( 'Redirects not stopped: one address could not be read. Reload the page and try again.', 'perflocale' ) ],
			'forget_too_many' => [ 'warning', __( 'Redirects not stopped: too many at once. Stop them one base at a time.', 'perflocale' ) ],
		];

		$lines = [];

		foreach ( array_slice( explode( ',', $refused ), 0, self::MAX_REFUSED ) as $item ) {
			$parts = explode( '|', $item );

			if ( 3 !== count( $parts ) || ! isset( $keys[ $parts[0] ] ) || ( '*' !== $parts[1] && ! isset( $langs[ $parts[1] ] ) ) ) {
				continue;
			}

			if ( 'cap_provenance' === $parts[2] ) {
				/* translators: 1: URL base label, e.g. "Product", 2: language name, 3: number of addresses, 4: key, e.g. "pt:product" */
				$lines[] = sprintf( __( 'Not saved: the %1$s base in %2$s already has %3$d addresses that were in use (the current one and old ones). Stop redirecting the old ones you no longer need under "Stop redirecting old addresses" below (or with wp perflocale url-bases forget %4$s), then save again.', 'perflocale' ), $keys[ $parts[0] ]['label'], $langs[ $parts[1] ] ?? $parts[1], Values::PUB_CAP, $parts[0] );
				continue;
			}

			/* translators: 1: URL base label, 2: language name, 3: reason */
			$lines[] = sprintf( __( 'Not saved: %1$s in %2$s - %3$s.', 'perflocale' ), $keys[ $parts[0] ]['label'], $langs[ $parts[1] ] ?? $parts[1], Validator::reason_text( sanitize_key( $parts[2] ) ) );
		}

		// A save that refused a value never reads as plain success.
		if ( [] !== $lines ) {
			$texts['published'][1] = __( 'Other URL bases saved. Links and redirects use the new bases now.', 'perflocale' );
			$texts['unchanged'][1] = __( 'Other URL bases saved.', 'perflocale' );
		}

		[ $type, $text ] = $texts[ $status ];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $text ); ?></p></div>
		<?php

		if ( [] !== $lines ) {
			?>
			<div class="notice notice-error is-dismissible">
				<?php foreach ( $lines as $line ) : ?>
					<p><?php echo esc_html( $line ); ?></p>
				<?php endforeach; ?>
			</div>
			<?php
		}
	}

	/**
	 * Words for a key's status.
	 *
	 * @param string $status Status.
	 * @param string $reason Reason code.
	 * @param string $detail Detail.
	 * @return string
	 */
	private static function status_text( string $status, string $reason, string $detail ): string {
		if ( 'suspended' === $status || 'no_rules' === $status ) {
			/* translators: %s: reason */
			return sprintf( __( 'Not in use: %s.', 'perflocale' ), Validator::reason_text( $reason, $detail ) );
		}

		return '';
	}

	/**
	 * Active non-default languages: slug => name.
	 *
	 * @return array<string, string>
	 */
	public static function languages(): array {
		$out = [];

		/** @var \PerfLocale\Database\Repository\LanguageRepository $repo */
		$repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );

		foreach ( $repo->get_active() as $lang ) {
			$row  = get_object_vars( $lang );
			$slug = Typed::str( $row['slug'] ?? null );

			if ( '' !== $slug && empty( $row['is_default'] ) ) {
				$name         = Typed::str( $row['native_name'] ?? null );
				$out[ $slug ] = '' !== $name ? $name : ( '' !== Typed::str( $row['name'] ?? null ) ? Typed::str( $row['name'] ) : $slug );
			}
		}

		return $out;
	}

	/**
	 * The default language's slug, '' when none is active.
	 *
	 * @return string
	 */
	private static function default_language(): string {
		/** @var \PerfLocale\Database\Repository\LanguageRepository $repo */
		$repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );

		foreach ( $repo->get_active() as $lang ) {
			$row = get_object_vars( $lang );

			if ( ! empty( $row['is_default'] ) ) {
				return Typed::str( $row['slug'] ?? null );
			}
		}

		return '';
	}

	/**
	 * The admin-post handler: store the posted switches and values, compile, redirect back.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change the URL bases.', 'perflocale' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized where save() reads it.
		$result = self::save( Typed::map( wp_unslash( $_POST ) ) );

		$args = [
			'page'      => 'perflocale-settings',
			'tab'       => 'url-routing',
			'subtab'    => 'url-bases',
			'ub-status' => $result['status'],
		];

		if ( [] !== $result['refused'] ) {
			$args['ub-refused'] = implode( ',', array_slice( $result['refused'], 0, self::MAX_REFUSED ) );
		}

		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * One posted form (unslashed): the settings form, or the forget form, which needs a ticked row and its confirmation
	 * and forgets only the ticked entries it listed.
	 *
	 * @param array<string, mixed> $post Posted fields.
	 * @return array{status: string, refused: list<string>}
	 */
	public static function save( array $post ): array {
		$forget = ! empty( $post['ub_forget'] );
		// Entries are norm-form values (sanitize_text_field() would strip their %xx): Values::entry() validates each.
		$entries = $forget ? Typed::strs( $post['ub_forget_entries'] ?? [] ) : [];

		if ( $forget && ( [] === $entries || empty( $post['ub_forget_confirm'] ) ) ) {
			return [
				'status'  => [] === $entries ? 'forget_none' : 'forget_unconfirmed',
				'refused' => [],
			];
		}

		return self::apply( Typed::map( $post['ub'] ?? [] ), $forget, null, $entries );
	}

	/**
	 * Whether a key exists here: declared, configured, or in the published map.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function known_key( string $key ): bool {
		$map = get_option( Publication::MAP );

		return isset( Values::get()['keys'][ $key ] ) || ( is_array( $map ) && isset( Typed::map( $map['keys'] ?? null )[ $key ] ) ) || isset( Registry::build()->keys()[ $key ] );
	}

	/**
	 * Store a posted form and compile; also the body of the WP-CLI `set` and `forget` paths.
	 *
	 * @param array<string, mixed> $posted  Posted `ub` fields (unslashed).
	 * @param bool                 $forget  Forget old URLs.
	 * @param string|null          $only    Forget for this key only.
	 * @param list<string>         $entries The listed rows the user confirmed (kept_redirects() `entry`).
	 * @return array{status: string, refused: list<string>}
	 */
	public static function apply( array $posted, bool $forget = false, ?string $only = null, array $entries = [] ): array {
		$refused = [];

		// A form without its last field lost fields to max_input_vars: switches would read as off.
		if ( ! empty( $posted['form'] ) && empty( $posted['end'] ) ) {
			return [
				'status'  => 'truncated',
				'refused' => $refused,
			];
		}

		// Never widen a one-key forget to all keys.
		if ( null !== $only && ! self::known_key( $only ) ) {
			return [
				'status'  => 'unknown_key',
				'refused' => $refused,
			];
		}

		$dry      = Publication::dry_run()['report'];
		$registry = Registry::build();
		$keys     = $registry->keys();
		$langs    = self::languages();

		// An entry that is no entry is refused, never skipped: what the user ticked must be what is stopped.
		if ( $forget && [] !== array_filter( $entries, static fn( string $e ): bool => strlen( $e ) > Values::MAX_ENTRY_BYTES || ( null === Values::entry( $e ) && null === Values::row( $e ) ) ) ) {
			return [
				'status'  => 'forget_invalid',
				'refused' => $refused,
			];
		}

		$fresh = $forget ? Values::fresh() : null;

		// A forget from a failed read confirms nothing: nothing is stopped, try again.
		if ( $forget && ( null === $fresh || in_array( 'read_failed', $dry['errors'], true ) ) ) {
			return [
				'status'  => 'notsaved',
				'refused' => $refused,
			];
		}

		// Bounded before the lock: never more entries than listed.
		if ( null !== $fresh && count( $entries ) > count( array_filter( self::kept_redirects( $dry['keys'], $dry, $fresh, $langs ), static fn( array $r ): bool => $r['stop'] ) ) ) {
			return [
				'status'  => 'forget_stale',
				'refused' => $refused,
			];
		}

		$rendered        = array_values( array_intersect( Typed::strs( $posted['rendered'] ?? [] ), array_keys( $keys ) ) );
		$rendered_groups = array_values( array_intersect( Typed::strs( $posted['rendered_groups'] ?? [] ), array_keys( $registry->groups() ) ) );
		$groups_in       = Typed::map( $posted['groups'] ?? [] );
		$keys_in         = Typed::map( $posted['keys'] ?? [] );
		$has_form        = ! empty( $posted['form'] );
		$slugs           = array_merge( array_keys( $langs ), [ self::default_language() ] );
		$refusal         = '';
		$unread          = in_array( 'read_failed', $dry['errors'], true );

		$stored = Values::update(
			static function ( array $v, array $now ) use ( $posted, $has_form, $rendered, $rendered_groups, $groups_in, $keys_in, $keys, $langs, $slugs, $dry, $unread, $forget, $only, $entries, &$refused, &$refusal ): array {
				// The change may run again on a newer row (Values::update()): nothing from an earlier run carries over.
				$refused = [];
				$refusal = '';

				// $now: the language slugs locked in this transaction; a value for a slug changed since the form was read
				// would be stranded.
				// The published map, read under the values lock once a replaced value needs it: only a live value becomes history.
				$served = false;

				if ( $has_form ) {
					$v['on']       = ! empty( $posted['on'] );
					$v['redirect'] = ! empty( $posted['redirect'] );
				}

				foreach ( $rendered_groups as $grp ) {
					$v['groups'][ $grp ] = ! empty( $groups_in[ $grp ] );
				}

				$configured = count( array_filter( $v['keys'], static fn( array $e ): bool => ! empty( $e['tr'] ) ) );

				foreach ( $rendered as $key ) {
					$in                       = Typed::map( $keys_in[ $key ] ?? [] );
					$grp                      = $keys[ $key ]['grp'];
					$v['keys'][ $key ]['on']  = ! empty( $in['on'] );
					$v['keys'][ $key ]['grp'] = $grp;
					$registered               = $dry['keys'][ $key ]['registered'] ?? '';

					if ( ! empty( $dry['keys'][ $key ]['derived'] ) ) {
						continue;
					}

					foreach ( Typed::map( $in['tr'] ?? [] ) as $lang => $text ) {
						if ( ! isset( $langs[ $lang ] ) || ! in_array( $lang, $now, true ) || ! is_string( $text ) ) {
							continue;
						}

						[ $value, $code ] = Validator::from_input( $text );

						if ( null === $value ) {
							$refused[] = $key . '|' . $lang . '|' . $code;
							continue;
						}

						if ( $value === $registered ) {
							$value = '';
						}

						$is_new = '' !== $value && empty( $v['keys'][ $key ]['tr'] );

						if ( $is_new && $configured >= Values::MAX_KEYS ) {
							$refused[] = $key . '|' . $lang . '|cap_keys';
							continue;
						}

						if ( $is_new ) {
							++$configured;
						}

						$old = $v['keys'][ $key ]['tr'][ $lang ] ?? '';
						$pub = $v['pub'][ $key ][ $lang ] ?? [];

						// A full published record is never trimmed: the owner frees a slot with the forget form first.
						if ( '' !== $value && $value !== $old && 2 === $v['v'] && count( $pub ) >= Values::PUB_CAP && ! in_array( $value, $pub, true ) ) {
							$refused[] = $key . '|' . $lang . '|cap_provenance';
							continue;
						}

						if ( '' !== $old && $old !== $value && false === $served ) {
							$served = Values::published();
						}

						$v = Values::with_value( $v, $key, $lang, $value, $grp, Values::was_live( Values::witnesses( $v, $unread ? null : ( false === $served ? [] : $served ) ), $key, $lang, $old, $slugs ) );
					}
				}

				// Only rows this site lists now, from the values read under the lock, that a tick can stop (of the one key
				// `$only` names); any other refuses the whole forget. Each row stops every alias of its redirect.
				if ( $forget ) {
					Publication::reset_dry_run();
					$now  = Publication::dry_run()['report'];
					$rows = [];

					if ( in_array( 'read_failed', $now['errors'], true ) ) {
						$refusal = 'notsaved';

						return $v;
					}

					foreach ( self::kept_redirects( $now['keys'], $now, $v, $langs ) as $row ) {
						if ( $row['stop'] && ( null === $only || in_array( $only, $row['keys'], true ) ) ) {
							$rows[ $row['entry'] ] = $row['entries'];
						}
					}

					if ( [] === $entries || [] !== array_diff( $entries, array_keys( $rows ) ) ) {
						$refusal = 'forget_stale';

						return $v;
					}

					$expanded = array_values( array_unique( array_merge( ...array_map( static fn( string $e ): array => $rows[ $e ], $entries ) ) ) );

					// The pending list keeps MAX_KEYS + MAX_FORGET_ENTRIES (Values::shape()): refuse rather than truncate.
					if ( ! in_array( '*', $v['forget'] ?? [], true ) && count( array_unique( array_merge( $v['forget'] ?? [], $expanded ) ) ) > Values::MAX_KEYS + Values::MAX_FORGET_ENTRIES ) {
						$refusal = 'forget_too_many';

						return $v;
					}

					$v = Values::forget( $v, $expanded, self::off_keys( $now['keys'], $v ), self::default_language() );
				}

				return $v;
			}
		);

		if ( null === $stored || '' !== $refusal ) {
			return [
				'status'  => '' !== $refusal ? $refusal : 'notsaved',
				'refused' => $refused,
			];
		}

		$result = Lifecycle::compile_now( 'settings' );

		return [
			'status'  => $result['status'],
			'refused' => $refused,
		];
	}
}
