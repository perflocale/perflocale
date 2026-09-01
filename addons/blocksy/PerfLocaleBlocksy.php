<?php
/**
 * PerfLocale Blocksy theme addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocksy theme integration for PerfLocale.
 *
 * Registers the language switcher as a draggable header/footer builder
 * element in Blocksy's panel builder.
 */
final class PerfLocaleBlocksy implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'blocksy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Blocksy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return 'blocksy' === get_template();
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		add_filter( 'blocksy:header:items-paths', [ $this, 'add_header_item_path' ] );
		add_filter( 'blocksy:footer:items-paths', [ $this, 'add_footer_item_path' ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add the language switcher directory to Blocksy's header item paths.
	 *
	 * @param array<int, string> $paths Item directory paths.
	 * @return array<int, string>
	 */
	public function add_header_item_path( array $paths ): array {
		$paths[] = PERFLOCALE_DIR . 'addons/blocksy/header';

		return $paths;
	}

	/**
	 * Add the language switcher directory to Blocksy's footer item paths.
	 *
	 * @param array<int, string> $paths Item directory paths.
	 * @return array<int, string>
	 */
	public function add_footer_item_path( array $paths ): array {
		$paths[] = PERFLOCALE_DIR . 'addons/blocksy/footer';

		return $paths;
	}
}
