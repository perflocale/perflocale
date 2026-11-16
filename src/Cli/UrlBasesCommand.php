<?php
/**
 * WP-CLI `wp perflocale url-bases`.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cli;

use PerfLocale\Admin\UrlBases\Health;
use PerfLocale\Admin\UrlBases\Tab;
use PerfLocale\Router\UrlBases\Lifecycle;
use PerfLocale\Router\UrlBases\Publication;
use PerfLocale\Router\UrlBases\Registry;
use PerfLocale\Router\UrlBases\Validator;
use PerfLocale\Router\UrlBases\Values;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Body of `wp perflocale url-bases list|set|compile|forget` (PerfLocaleCommand::url_bases() forwards here).
 *
 * @phpstan-import-type KeyRow from \PerfLocale\Router\UrlBases\Compiler
 *
 * @internal
 */
final class UrlBasesCommand {

	/**
	 * Run a subcommand.
	 *
	 * @param array<int, string>    $args       Positional args (subcommand first).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		$sub = (string) array_shift( $args );

		switch ( $sub ) {
			case 'list':
				$this->list_bases( $assoc_args );
				break;
			case 'set':
				$this->set( $args, $assoc_args );
				break;
			case 'compile':
				$this->compile();
				break;
			case 'forget':
				$this->forget( $args, $assoc_args );
				break;
			default:
				\WP_CLI::error( sprintf( 'Unknown subcommand "%s". Use list, set, compile or forget.', $sub ) );
		}
	}

	/**
	 * One row per key and language, with its status.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function list_bases( array $assoc_args ): void {
		$report = Publication::dry_run()['report'];
		$rows   = [];

		foreach ( $report['keys'] as $key => $k ) {
			$cells = $report['entries'][ $key ] ?? [];

			if ( [] === $cells ) {
				$rows[] = $this->row( $key, $k, '-', '', $k['status'], $k['reason'], $k['detail'] );
				continue;
			}

			$own = 'ok' === $k['status'];

			foreach ( $cells as $lang => $cell ) {
				$rows[] = $this->row( $key, $k, $lang, rawurldecode( $cell['value'] ), $own ? $cell['status'] : $k['status'], $own ? $cell['reason'] : $k['reason'], $own ? $cell['detail'] : $k['detail'] );
			}
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'key', 'group', 'base', 'language', 'value', 'on', 'status', 'reason' ] );

		foreach ( Health::issues(
			[
				'keys'    => [],
				'entries' => [],
			] + $report
		) as $line ) {
			\WP_CLI::warning( $line );
		}
	}

	/**
	 * A list row.
	 *
	 * @param string $key    Key.
	 * @param KeyRow $k      Reported key.
	 * @param string $lang   Language.
	 * @param string $value  Value.
	 * @param string $status Status.
	 * @param string $reason Reason code.
	 * @param string $detail Detail.
	 * @return array<string, string>
	 */
	private function row( string $key, array $k, string $lang, string $value, string $status, string $reason, string $detail ): array {
		return [
			'key'      => $key,
			'group'    => $k['grp'],
			'base'     => $k['registered'],
			'language' => $lang,
			'value'    => $value,
			'on'       => $k['on'] ? 'yes' : 'no',
			'status'   => $status,
			'reason'   => '' !== $reason ? Validator::reason_text( $reason, $detail ) : '',
		];
	}

	/**
	 * `set <key> <lang> <value>` or `set <target> --enabled=on|off` (target: global, redirect, group:<id>, a key).
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function set( array $args, array $assoc_args ): void {
		$target = (string) ( $args[0] ?? '' );

		if ( isset( $assoc_args['enabled'] ) ) {
			$this->set_switch( $target, (string) $assoc_args['enabled'] );
			return;
		}

		if ( 3 !== count( $args ) ) {
			\WP_CLI::error( 'Usage: wp perflocale url-bases set <key> <language> <value>, or set <target> --enabled=on|off.' );
		}

		[ $key, $lang, $text ] = $args;
		$keys                  = Registry::build()->keys();
		$values                = Values::fresh();

		// The key's switch is kept as stored: a failed read must not turn an off key on.
		if ( null === $values ) {
			\WP_CLI::error( 'A database read failed: nothing was saved. Run the command again.' );
			return;
		}

		if ( ! isset( $keys[ $key ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown key "%s": run `wp perflocale url-bases list` for the keys of this site.', $key ) );
		}

		if ( ! isset( Tab::languages()[ $lang ] ) ) {
			\WP_CLI::error( sprintf( 'Language "%s" is not an active non-default language of this site.', $lang ) );
		}

		$result = Tab::apply(
			[
				'rendered' => [ $key ],
				'keys'     => [
					$key => [
						'on' => false !== ( $values['keys'][ $key ]['on'] ?? true ),
						'tr' => [ $lang => $text ],
					],
				],
			]
		);

		$this->report( $result );
	}

	/**
	 * Switch the feature, the redirects, a group or a key on or off.
	 *
	 * @param string $target Target.
	 * @param string $state  on or off.
	 * @return void
	 */
	private function set_switch( string $target, string $state ): void {
		if ( ! in_array( $state, [ 'on', 'off' ], true ) ) {
			\WP_CLI::error( '--enabled takes on or off.' );
		}

		$on       = 'on' === $state;
		$registry = Registry::build();

		if ( str_starts_with( $target, 'group:' ) && ! isset( $registry->groups()[ substr( $target, 6 ) ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown group "%s".', substr( $target, 6 ) ) );
		} elseif ( ! in_array( $target, [ 'global', 'redirect' ], true ) && ! str_starts_with( $target, 'group:' ) && ! isset( $registry->keys()[ $target ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown target "%s": use global, redirect, group:<id> or a key.', $target ) );
		}

		$keys   = $registry->keys();
		$stored = Values::update(
			static function ( array $v ) use ( $target, $on, $keys ): array {
				if ( 'global' === $target ) {
					$v['on'] = $on;
				} elseif ( 'redirect' === $target ) {
					$v['redirect'] = $on;
				} elseif ( str_starts_with( $target, 'group:' ) ) {
					$v['groups'][ substr( $target, 6 ) ] = $on;
				} else {
					$v['keys'][ $target ]['on']  = $on;
					$v['keys'][ $target ]['grp'] = $keys[ $target ]['grp'];
				}

				return $v;
			}
		);

		if ( null === $stored ) {
			\WP_CLI::error( 'Not saved: another save of the URL bases was running. Try again.' );
		}

		$this->report(
			[
				'status'  => Lifecycle::compile_now( 'cli' )['status'],
				'refused' => [],
			]
		);
	}

	/**
	 * Compile and publish now.
	 *
	 * @return void
	 */
	private function compile(): void {
		$result = Lifecycle::compile_now( 'cli' );

		foreach ( [] !== $result['report'] ? Health::issues( $result['report'] ) : [] as $line ) {
			\WP_CLI::warning( $line );
		}

		$this->report(
			[
				'status'  => $result['status'],
				'refused' => [],
			]
		);
	}

	/**
	 * `forget [<key>] [--yes]`: stops the redirects the settings screen lists (one key's with <key>).
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function forget( array $args, array $assoc_args ): void {
		$key = isset( $args[0] ) ? (string) $args[0] : null;

		if ( null !== $key && ! Tab::known_key( $key ) ) {
			\WP_CLI::error( sprintf( 'Unknown key "%s": run `wp perflocale url-bases list` for the keys of this site.', $key ) );
		}

		// The old addresses the settings screen lists: only these are forgotten (unlisted history stays).
		$build  = Publication::dry_run();
		$values = Values::fresh();

		// A failed read lists nothing, which is not "no redirects".
		if ( null === $values || in_array( 'read_failed', $build['report']['errors'], true ) ) {
			\WP_CLI::error( 'A database read failed: no redirect was stopped. Run the command again.' );
			return;
		}

		$kept = array_values( array_filter( Tab::kept_redirects( $build['report']['keys'], $build['report'], $values, Tab::languages() ), static fn( array $r ): bool => null === $key || in_array( $key, $r['keys'], true ) ) );
		$stop  = array_values( array_filter( $kept, static fn( array $r ): bool => $r['stop'] ) );

		if ( [] === $kept ) {
			\WP_CLI::success( 'No old URL base redirects: nothing to forget.' );
			return;
		}

		foreach ( $kept as $row ) {
			\WP_CLI::log( sprintf( '%s, %s: %s -> %s', $row['label'], $row['lang'], $row['from'], $row['to'] ) . ( $row['stop'] ? '' : ' (' . $row['why'] . ')' ) );
		}

		if ( [] === $stop ) {
			\WP_CLI::success( 'None of these redirects can be stopped now: nothing to forget.' );
			return;
		}

		\WP_CLI::confirm( 'Stop these redirects? These old addresses then show "not found".', $assoc_args );

		$this->report( Tab::apply( [], true, $key, array_column( $stop, 'entry' ) ) );
	}

	/**
	 * Print a save or compile result.
	 *
	 * @param array{status: string, refused: array<int, string>} $result Result.
	 * @return void
	 */
	private function report( array $result ): void {
		foreach ( $result['refused'] as $item ) {
			$parts = explode( '|', $item );

			if ( 'cap_provenance' === ( $parts[2] ?? '' ) ) {
				\WP_CLI::warning( sprintf( 'Not saved: the %s base in %s already has %d addresses that were in use (the current one and old ones). Stop redirecting the old ones you no longer need (wp perflocale url-bases forget %s), then set it again.', $parts[0], $parts[1], Values::PUB_CAP, $parts[0] ) );
				continue;
			}
			\WP_CLI::warning( sprintf( 'Not saved: %s in %s - %s.', $parts[0], $parts[1] ?? '', Validator::reason_text( $parts[2] ?? '' ) ) );
		}

		$texts = [
			'published'  => 'URL bases published.',
			'unchanged'  => 'URL bases unchanged.',
			'busy'       => 'Another compile is running: the update runs on the next admin page load.',
			'stale'      => 'Inputs changed during the compile: the update runs on the next admin page load.',
			'lease_lost' => 'The compile took longer than its lease: nothing was written; run compile again.',
			'read_failed' => 'A database read failed: nothing was written and the previous URL bases stay in use; run compile again.',
			'failed'     => 'The database refused the map: the previous URL bases stay in use.',
			'too_large'  => 'The URL bases are larger than the map limit: the previous URL bases stay in use, without the values this compile refused.',
			'notsaved'   => 'Not saved: another save of the URL bases was running. Try again.',
			'no_rules'   => 'WordPress has no rewrite rules stored: the previous URL bases stay in use (run `wp rewrite flush`).',
			'forget_stale' => 'Redirects not stopped: the list changed while the command ran. Run it again.',
			'forget_invalid' => 'Redirects not stopped: one address could not be read. Run the command again.',
			'forget_too_many' => 'Redirects not stopped: too many at once. Stop them one base at a time: wp perflocale url-bases forget <key>.',
		];

		$text = $texts[ $result['status'] ] ?? $result['status'];

		if ( in_array( $result['status'], [ 'failed', 'read_failed', 'too_large', 'notsaved', 'no_rules', 'forget_stale', 'forget_invalid', 'forget_too_many' ], true ) ) {
			\WP_CLI::error( $text );
		}

		if ( [] !== $result['refused'] ) {
			\WP_CLI::warning( $text );
			return;
		}

		\WP_CLI::success( $text );
	}
}
