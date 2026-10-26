<?php
/**
 * Translator user role and capabilities.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Translator role and the PerfLocale capabilities of each role.
 *
 * `perflocale_approve_translations` is never a Translator capability: translators submit, reviewers approve.
 */
final class TranslatorRole {

	/**
	 * Role slug.
	 */
	public const ROLE_SLUG = 'perflocale_translator';

	/**
	 * All custom capabilities.
	 */
	public const CAPABILITIES = [
		'perflocale_translate',
		'perflocale_manage_translations',
		'perflocale_approve_translations',
		'perflocale_manage_languages',
		'perflocale_manage_addons',
		'perflocale_use_mt',
		'perflocale_import_export',
	];

	/**
	 * Retired capabilities, still stripped on deactivation and uninstall.
	 *
	 * Retire a capability by moving it here, never by deleting it: remove_roles() strips only listed ones.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_CAPABILITIES = [
		// Not granted by PerfLocale; stripped on deactivation and uninstall.
		'perflocale_manage_glossary',
	];

	/**
	 * Core capabilities of the Translator role: edit others' posts and pages, upload media; no publish or delete.
	 */
	private const TRANSLATOR_BASE_CAPS = [
		'read'                 => true,
		'edit_posts'           => true,
		'edit_others_posts'    => true,
		'edit_published_posts' => true,
		'edit_pages'           => true,
		'edit_others_pages'    => true,
		'edit_published_pages' => true,
		'upload_files'         => true,
	];

	/**
	 * Capabilities granted to the Translator role.
	 */
	private const TRANSLATOR_CAPS = [
		'perflocale_translate' => true,
		'perflocale_use_mt'    => true,
	];

	/**
	 * The Translator capabilities an absent ledger reads as (capability version 4).
	 *
	 * Never edit it with the defaults: it is what every site without a ledger holds.
	 */
	private const TRANSLATOR_CAPS_V4 = [
		'read'                 => true,
		'edit_posts'           => true,
		'edit_others_posts'    => true,
		'edit_published_posts' => true,
		'edit_pages'           => true,
		'edit_others_pages'    => true,
		'edit_published_pages' => true,
		'upload_files'         => true,
		'perflocale_translate' => true,
		'perflocale_use_mt'    => true,
	];

	/**
	 * Option holding the Translator capability ledger (`granted`, `removed`, `denied`); not autoloaded.
	 *
	 * It only narrows the role: docs-ai/11-code-rationale.md, "TranslatorRole: capability ledger".
	 */
	private const TRANSLATOR_LEDGER_OPTION = 'perflocale_translator_caps';

	/**
	 * Capabilities granted once to WooCommerce's Shop Manager role.
	 *
	 * No `perflocale_use_mt`: it spends the site's MT budget.
	 */
	private const SHOP_MANAGER_CAPS = [
		'perflocale_translate' => true,
	];

	/**
	 * WooCommerce's Shop Manager role slug.
	 */
	private const SHOP_MANAGER_ROLE = 'shop_manager';

	/**
	 * Option recording the Shop Manager grant (`granted`, and `removed` after a deactivation).
	 *
	 * Autoloaded: read on every admin_init of a WooCommerce site. While it exists the grant is never repeated.
	 */
	private const SHOP_MANAGER_OPTION = 'perflocale_shop_manager_caps';

	/**
	 * PerfLocale capabilities granted to Editors.
	 */
	private const EDITOR_CAPS = [
		'perflocale_translate'            => true,
		'perflocale_manage_translations'  => true,
		'perflocale_approve_translations' => true,
		'perflocale_use_mt'               => true,
	];

	/**
	 * PerfLocale capabilities granted to Administrators (all of them).
	 */
	private const ADMIN_CAPS = [
		'perflocale_translate'            => true,
		'perflocale_manage_translations'  => true,
		'perflocale_approve_translations' => true,
		'perflocale_manage_languages'     => true,
		'perflocale_manage_addons'        => true,
		'perflocale_use_mt'               => true,
		'perflocale_import_export'        => true,
	];

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'ensure_roles_exist' ] );

		// The user-deletion hooks live in Bootstrap (front-end deletes skip this class): adding them here runs them twice.
	}

	/**
	 * Capability version: install_caps() reruns (idempotently) while `perflocale_caps_version` is lower.
	 *
	 * Bump it when CAPABILITIES, TRANSLATOR_BASE_CAPS, TRANSLATOR_CAPS, EDITOR_CAPS or ADMIN_CAPS change.
	 */
	public const CAPS_VERSION = 4;

	/**
	 * Create the Translator role and add capabilities to existing roles.
	 *
	 * Activation calls it too: without that the first wp-admin redirect 403s on a stale allcaps cache.
	 * Runs on the current site only; network callers switch blogs.
	 *
	 * @return void
	 */
	public static function install_caps(): void {
		$version = (int) get_option( 'perflocale_caps_version', 0 );

		if ( $version >= self::CAPS_VERSION ) {
			return;
		}

		self::sync_translator_role();

		/**
		 * Filter the capabilities granted to the Editor role on plugin activation.
		 *
		 * Return [] or a subset to grant fewer; Administrators are not affected.
		 *
		 * @hook perflocale/roles/editor_caps
		 *
		 * @param array<string, bool> $caps Map of capability => grant. Default: all editor caps.
		 */
		$editor_caps = (array) apply_filters( 'perflocale/roles/editor_caps', self::EDITOR_CAPS );

		// Grant capabilities to Editors.
		$editor = get_role( 'editor' );

		if ( $editor && ! empty( $editor_caps ) ) {
			foreach ( $editor_caps as $cap => $grant ) {
				$editor->add_cap( sanitize_key( $cap ), (bool) $grant );
			}
		}

		// Grant all capabilities to Administrators.
		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::ADMIN_CAPS as $cap => $grant ) {
				$admin->add_cap( $cap, $grant );
			}
		}

		// Autoloaded: the guard above reads it on every admin_init.
		update_option( 'perflocale_caps_version', self::CAPS_VERSION, true );
	}

	/**
	 * Create the Translator role, or apply only the plugin's own capability changes to it.
	 *
	 * Owner edits to the role survive: docs-ai/11-code-rationale.md, "TranslatorRole: capability ledger".
	 *
	 * @return void
	 */
	private static function sync_translator_role(): void {
		$defaults = self::translator_caps();
		$ledger   = self::translator_ledger();
		$role     = get_role( self::ROLE_SLUG );

		if ( null === $role ) {
			$caps = $defaults;

			// Only a default grant is subtracted: dropping a `false` lifts a deny for users with another role.
			foreach ( $ledger['removed'] as $cap ) {
				if ( true === ( $caps[ $cap ] ?? null ) ) {
					unset( $caps[ $cap ] );
				}
			}

			// An explicit `false`, not an absence: only that denies it to a user whose other role grants it.
			foreach ( $ledger['denied'] as $cap ) {
				if ( true === ( $defaults[ $cap ] ?? null ) ) {
					$caps[ $cap ] = false;
				}
			}

			add_role( self::ROLE_SLUG, __( 'Translator', 'perflocale' ), $caps );
		} else {
			foreach ( $defaults as $cap => $grant ) {
				if ( ! array_key_exists( $cap, $ledger['granted'] ) || $ledger['granted'][ $cap ] !== $grant ) {
					$role->add_cap( $cap, $grant );
				}
			}

			foreach ( array_keys( $ledger['granted'] ) as $cap ) {
				if ( ! array_key_exists( $cap, $defaults ) && ! empty( $role->capabilities[ $cap ] ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		update_option(
			self::TRANSLATOR_LEDGER_OPTION,
			[
				'granted' => $defaults,
				'removed' => [],
				'denied'  => [],
			],
			false
		);
	}

	/**
	 * The Translator role's default capabilities, after the
	 * `perflocale/roles/translator_caps` filter.
	 *
	 * @return array<string, bool>
	 */
	private static function translator_caps(): array {
		/**
		 * Filter the capabilities of the Translator role.
		 *
		 * Changes apply on the next rebuild (activation, or a capability version update); owner edits are kept.
		 *
		 * @hook perflocale/roles/translator_caps
		 *
		 * @param array<string, bool> $caps Map of capability => grant. Default: TRANSLATOR_BASE_CAPS plus translate and use_mt.
		 */
		$caps = apply_filters( 'perflocale/roles/translator_caps', array_merge( self::TRANSLATOR_BASE_CAPS, self::TRANSLATOR_CAPS ) );

		return self::normalise_caps( $caps );
	}

	/**
	 * Read the Translator role's capability ledger.
	 *
	 * An unreadable `granted` map reads as TRANSLATOR_CAPS_V4, and an unreadable list as empty.
	 *
	 * @return array{granted: array<string, bool>, removed: array<int, string>, denied: array<int, string>}
	 */
	private static function translator_ledger(): array {
		$stored = get_option( self::TRANSLATOR_LEDGER_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$granted = isset( $stored['granted'] ) && is_array( $stored['granted'] )
			? self::normalise_caps( $stored['granted'] )
			: self::TRANSLATOR_CAPS_V4;

		return [
			'granted' => $granted,
			'removed' => self::normalise_cap_list( $stored['removed'] ?? null ),
			'denied'  => self::normalise_cap_list( $stored['denied'] ?? null ),
		];
	}

	/**
	 * Normalise a stored list of capability names: unique `sanitize_key()` strings, others dropped.
	 *
	 * @param mixed $caps Candidate list of capability names.
	 * @return array<int, string>
	 */
	private static function normalise_cap_list( $caps ): array {
		if ( ! is_array( $caps ) ) {
			return [];
		}

		$out = [];

		foreach ( $caps as $cap ) {
			$cap = is_string( $cap ) ? sanitize_key( $cap ) : '';

			if ( '' !== $cap ) {
				$out[ $cap ] = $cap;
			}
		}

		return array_values( $out );
	}

	/**
	 * Normalise a capability map: `sanitize_key()` string keys, boolean values, others dropped.
	 *
	 * @param mixed $caps Candidate map of capability => grant.
	 * @return array<string, bool>
	 */
	private static function normalise_caps( $caps ): array {
		$out = [];

		foreach ( (array) $caps as $cap => $grant ) {
			if ( ! is_string( $cap ) ) {
				continue;
			}

			$cap = sanitize_key( $cap );

			if ( '' !== $cap ) {
				$out[ $cap ] = (bool) $grant;
			}
		}

		return $out;
	}

	/**
	 * Admin_init: install the role and capabilities, and the Shop Manager grant.
	 *
	 * @return void
	 */
	public function ensure_roles_exist(): void {
		self::install_caps();
		self::install_shop_manager_caps();
	}

	/**
	 * Grant WooCommerce's Shop Manager role `perflocale_translate`, once per site.
	 *
	 * Once only, so an owner's removal sticks: docs-ai/11-code-rationale.md, "TranslatorRole: Shop Manager grant".
	 *
	 * @return void
	 */
	public static function install_shop_manager_caps(): void {
		if ( ! class_exists( 'WooCommerce' ) || \PerfLocale\Addon\AddonRegistry::is_disabled( 'woocommerce' ) ) {
			return;
		}

		$role = get_role( self::SHOP_MANAGER_ROLE );

		if ( null === $role ) {
			return;
		}

		/**
		 * Filter the PerfLocale capabilities granted once to WooCommerce's Shop Manager role.
		 *
		 * Return [] to grant nothing; delete the `perflocale_shop_manager_caps` option to grant again.
		 *
		 * @hook perflocale/roles/shop_manager_caps
		 *
		 * @param array<string, bool> $caps Map of capability => grant. Default: perflocale_translate.
		 */
		$caps = self::normalise_caps( apply_filters( 'perflocale/roles/shop_manager_caps', self::SHOP_MANAGER_CAPS ) );

		if ( [] === $caps ) {
			return;
		}

		$stored = get_option( self::SHOP_MANAGER_OPTION, false );

		if ( false === $stored ) {
			$added = [];

			// An entry the role already has is the owner's: a deny stays a deny.
			foreach ( $caps as $cap => $grant ) {
				if ( ! array_key_exists( $cap, $role->capabilities ) ) {
					$role->add_cap( $cap, $grant );
					$added[ $cap ] = $grant;
				}
			}

			update_option( self::SHOP_MANAGER_OPTION, [ 'granted' => $added ], true );

			return;
		}

		if ( ! is_array( $stored ) || ! isset( $stored['removed'] ) ) {
			return;
		}

		// After a deactivation: restore only what the filter still grants with the same value (never widens the
		// role), and only where the role has no entry (an owner's deny stays).
		$granted = isset( $stored['granted'] ) && is_array( $stored['granted'] ) ? self::normalise_caps( $stored['granted'] ) : [];
		$removed = is_array( $stored['removed'] ) ? array_filter( $stored['removed'], 'is_string' ) : [];

		foreach ( $caps as $cap => $grant ) {
			if ( ( $granted[ $cap ] ?? null ) === $grant && ! in_array( $cap, $removed, true ) && ! array_key_exists( $cap, $role->capabilities ) ) {
				$role->add_cap( $cap, $grant );
			}
		}

		update_option( self::SHOP_MANAGER_OPTION, [ 'granted' => $granted ], true );
	}

	/**
	 * On deactivation, take the recorded Shop Manager grant off and note what the owner had already removed.
	 *
	 * A record that already has `removed` (a second deactivation) is left as it is.
	 *
	 * @return void
	 */
	private static function strip_shop_manager_caps(): void {
		$stored = get_option( self::SHOP_MANAGER_OPTION, false );

		if ( ! is_array( $stored ) || isset( $stored['removed'] ) || ! isset( $stored['granted'] ) || ! is_array( $stored['granted'] ) ) {
			return;
		}

		$role = get_role( self::SHOP_MANAGER_ROLE );

		if ( null === $role ) {
			return;
		}

		$granted = self::normalise_caps( $stored['granted'] );
		$removed = [];

		foreach ( $granted as $cap => $grant ) {
			if ( array_key_exists( $cap, $role->capabilities ) && (bool) $role->capabilities[ $cap ] === $grant ) {
				$role->remove_cap( $cap );
			} else {
				$removed[] = $cap;
			}
		}

		update_option(
			self::SHOP_MANAGER_OPTION,
			[
				'granted' => $granted,
				'removed' => $removed,
			],
			true
		);
	}

	/**
	 * Remove all custom roles and capabilities on plugin deactivation.
	 *
	 * First records the owner's removed and denied defaults in the ledger, so reactivation restores them.
	 *
	 * @return void
	 */
	public static function remove_roles(): void {
		$translator = get_role( self::ROLE_SLUG );

		if ( null !== $translator ) {
			$granted = self::translator_ledger()['granted'];
			$removed = [];
			$denied  = [];

			foreach ( self::translator_caps() as $cap => $grant ) {
				if ( true === $grant && true === ( $granted[ $cap ] ?? null ) && empty( $translator->capabilities[ $cap ] ) ) {
					if ( array_key_exists( $cap, $translator->capabilities ) ) {
						$denied[] = $cap;
					} else {
						$removed[] = $cap;
					}
				}
			}

			update_option(
				self::TRANSLATOR_LEDGER_OPTION,
				[
					'granted' => $granted,
					'removed' => $removed,
					'denied'  => $denied,
				],
				false
			);
		}

		remove_role( self::ROLE_SLUG );

		self::strip_shop_manager_caps();

		/**
		 * Filter which WordPress roles have PerfLocale capabilities removed on plugin deactivation.
		 *
		 * Remove a role slug from this array to keep its PerfLocale capabilities after deactivation.
		 *
		 * @hook perflocale/roles/cap_roles
		 *
		 * @param string[] $roles Role slugs to strip. Default: ['administrator', 'editor'].
		 */
		$roles = (array) apply_filters( 'perflocale/roles/cap_roles', [ 'administrator', 'editor' ] );

		foreach ( $roles as $role_slug ) {
			$role = get_role( sanitize_key( (string) $role_slug ) );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPABILITIES as $cap ) {
				$role->remove_cap( $cap );
			}

			// Retired capabilities too, or they stay on the role forever.
			foreach ( self::LEGACY_CAPABILITIES as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		// Orphan user-level PerfLocale capabilities; one sweep shared with SiteCleanup so the two cannot drift.
		if ( class_exists( \PerfLocale\Database\SiteCleanup::class ) ) {
			\PerfLocale\Database\SiteCleanup::sweep_orphan_user_caps();
		}

		delete_option( 'perflocale_caps_version' );
	}
}
