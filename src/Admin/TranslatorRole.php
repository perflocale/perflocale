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
 * Comprehensive permissions system for PerfLocale.
 *
 * Capabilities:
 * perflocale_translate - Create and edit translations (Translator, Editor, Admin; WooCommerce's Shop Manager)
 * perflocale_manage_translations - Manage translations and translation settings (Editor, Admin)
 * perflocale_approve_translations - Approve/reject pending translations in review (Editor, Admin)
 * perflocale_manage_languages - Add/edit/delete languages (Admin only)
 * perflocale_manage_addons - Install/activate/deactivate addons (Admin only)
 * perflocale_use_mt - Use machine translation (Translator, Editor, Admin)
 * perflocale_import_export - Import/export translations (Admin only)
 *
 * `perflocale_approve_translations` gates the review-workflow approve/reject
 * action of review workflows. It is granted
 * to Editors + Admins but NOT the base Translator role, so translators submit
 * and supervisors approve; it can also be granted on its own to a dedicated
 * reviewer role without the broader perflocale_manage_translations.
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
	 * Capabilities this plugin granted in EARLIER versions and no longer uses.
	 *
	 * `remove_roles()` strips caps by iterating {@see self::CAPABILITIES}, so a
	 * capability dropped from that list becomes unremovable: it stays on every
	 * role that was ever granted it, surviving deactivation AND a full
	 * uninstall. Retiring a capability therefore means MOVING it here, not
	 * deleting the line. Same reasoning as the legacy cron-hook names swept by
	 * raw string in Deactivator::cron_hooks().
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_CAPABILITIES = [
		// Not granted by PerfLocale; stripped on deactivation and uninstall.
		'perflocale_manage_glossary',
	];

	/**
	 * Core capabilities granted to the Translator role: open, edit and save
	 * posts and pages written by others, and upload media. No publish or
	 * delete capability.
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
	 * The Translator role's capabilities at capability version 4: the set
	 * every install had before the ledger existed. An absent ledger reads as
	 * this map. Fixed: it does not follow later changes to the defaults.
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
	 * Non-autoloaded option holding the Translator role's capability ledger:
	 * `granted`, the capability map install_caps() last applied; `removed`,
	 * the granted defaults the role had no entry for when remove_roles()
	 * deleted it; and `denied`, the granted defaults it held as an explicit
	 * deny (`false`) at that point.
	 *
	 * The ledger only narrows the role: `removed` subtracts from the
	 * defaults, `denied` turns a default grant into a deny, and a `granted`
	 * entry that is not a default is taken off. Nothing in it adds a
	 * capability outside the filtered defaults or lifts an explicit deny.
	 *
	 * Read on rebuilds and deactivation only, never on the admin_init
	 * short-circuit. Kept on a preserve-mode uninstall, deleted on a full one.
	 */
	private const TRANSLATOR_LEDGER_OPTION = 'perflocale_translator_caps';

	/**
	 * Capabilities granted once to WooCommerce's Shop Manager role, so shop
	 * managers can create product translations. No machine translation
	 * (`perflocale_use_mt` spends the site's MT budget).
	 */
	private const SHOP_MANAGER_CAPS = [
		'perflocale_translate' => true,
	];

	/**
	 * WooCommerce's Shop Manager role slug.
	 */
	private const SHOP_MANAGER_ROLE = 'shop_manager';

	/**
	 * Autoloaded per-site option recording the Shop Manager grant: `granted`,
	 * the capabilities install_shop_manager_caps() added to the role, and,
	 * between a deactivation and the next grant pass, `removed`, the recorded
	 * capabilities the role no longer held when remove_roles() took the
	 * others off. While the option exists the grant is never repeated.
	 *
	 * Autoloaded because install_shop_manager_caps() reads it on every
	 * admin_init of a WooCommerce site. Kept on a preserve-mode uninstall,
	 * deleted on a full one.
	 */
	private const SHOP_MANAGER_OPTION = 'perflocale_shop_manager_caps';

	/**
	 * Capabilities granted to Editors (in addition to Translator caps).
	 */
	private const EDITOR_CAPS = [
		'perflocale_translate'            => true,
		'perflocale_manage_translations'  => true,
		'perflocale_approve_translations' => true,
		'perflocale_use_mt'               => true,
	];

	/**
	 * Capabilities granted to Administrators (all caps).
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

		// The user-deletion hooks (delete_user, wpmu_delete_user,
		// remove_user_from_blog) are wired in Bootstrap for every request
		// context, because account plugins delete users from a front-end
		// POST where this service is never booted. Hooking them here too
		// would run each anonymisation twice in admin.
	}

	/**
	 * Anonymise background-job attribution on the site a user is removed
	 * from. Delegates to {@see \PerfLocale\Bootstrap::anonymize_jobs_on_blog()},
	 * which the `remove_user_from_blog` action calls directly.
	 *
	 * @param int $user_id User being removed from the blog.
	 * @param int $blog_id Blog the user is being removed from.
	 * @return void
	 */
	public function on_user_removed_from_blog( int $user_id, int $blog_id ): void {
		\PerfLocale\Bootstrap::anonymize_jobs_on_blog( $user_id, $blog_id );
	}

	/**
	 * Anonymise background-job attribution when a user is deleted.
	 *
	 * Mirrors the GDPR erasure flow on user delete: zeroes `created_by`
	 * on the user's active jobs so job rows never point at a
	 * since-deleted user.
	 *
	 * @param int $user_id Deleted user ID.
	 * @return void
	 */
	public function on_user_deleted( int $user_id ): void {
		\PerfLocale\Background\JobState::anonymize_for_user( $user_id );
	}

	/**
	 * Current capability-schema version. Compared against the stored
	 * `perflocale_caps_version` option; install_caps() runs whenever
	 * the stored value is lower.
	 *
	 * Unlike PERFLOCALE_DB_VERSION, this is NOT a migration system —
	 * there are no per-version cap migration methods. The install
	 * routine is idempotent (the Translator role gets only the delta
	 * between its filtered defaults and the ledger of what was last
	 * granted; add_cap is a no-op when the cap already exists), so a
	 * single "trigger" flag is enough.
	 *
	 * Bump this whenever CAPABILITIES, TRANSLATOR_BASE_CAPS,
	 * TRANSLATOR_CAPS, EDITOR_CAPS, or ADMIN_CAPS change (never
	 * TRANSLATOR_CAPS_V4). The next admin request on every existing install
	 * will see `db_version < CAPS_VERSION` and re-run the install, picking
	 * up the new cap set. Capabilities a site owner removed from or added to
	 * the Translator role stay as the owner left them.
	 *
	 * v2: added `perflocale_manage_addons` consumption on the AdminController
	 *     handlers + AddonsPage UI gates. Existing administrators need this
	 *     cap re-applied or the per-card Save/Enable/Disable buttons stop
	 *     working silently.
	 * v3: added `perflocale_approve_translations` (Editor + Admin), the
	 *     approve/reject capability of review workflows, so a dedicated
	 *     reviewer role can be given approve rights without the broader
	 *     `perflocale_manage_translations` cap.
	 */
	private const CAPS_VERSION = 4;

	/**
	 * Create the Translator role and add capabilities to existing roles.
	 *
	 * Idempotent: short-circuits when the stored caps version is already
	 * current. Callable in two contexts:
	 *
	 *   - Activation: Activator::activate() calls this so a fresh install
	 *     has the caps in place before any request is served. Required —
	 *     without it, the very first /wp-admin redirect after activation
	 *     hits a stale `WP_User->$allcaps` cache and 403s.
	 *   - admin_init: covers plugin upgrades (new caps in a later
	 *     version) and self-heals if a role-managing plugin wipes them.
	 *
	 * The Translator role is created when it is missing and otherwise
	 * changed only by the delta described in {@see sync_translator_role()},
	 * so capabilities a site owner removed from it, denied on it or added to
	 * it survive a rebuild, and default grants the owner removed or denied
	 * survive a deactivate/activate cycle.
	 *
	 * Multisite: caller is responsible for `switch_to_blog()` when running
	 * across sites — see Activator::activate_for_network() and the
	 * Bootstrap `wp_initialize_site` handler.
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
		 * Return an empty array to prevent the Editor role from receiving any
		 * PerfLocale capabilities. Return a subset to grant only specific ones.
		 * The Administrator role is not affected by this filter.
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

		// Autoloaded: the install_caps() guard reads this on every admin_init,
		// so a non-autoloaded row costs one options SELECT per admin request
		// on sites without a persistent object cache.
		update_option( 'perflocale_caps_version', self::CAPS_VERSION, true );
	}

	/**
	 * Create the Translator role, or apply the plugin's own capability
	 * changes to the existing one.
	 *
	 * Role missing (first install, or deleted by remove_roles() on
	 * deactivation): created with the filtered defaults minus the ledger's
	 * `removed` list, the grants the site owner had taken off before the role
	 * was deleted, and with each default grant on the ledger's `denied` list
	 * as an explicit deny, as the owner had set it.
	 *
	 * Role present: only the delta between the filtered defaults and the
	 * ledger's `granted` map is applied. A default that is new, or whose
	 * grant changed, is added; a `granted` entry that is no longer a default
	 * (retired by the plugin, or dropped by the filter) is removed while the
	 * role still grants it, and an explicit deny is left in place. Every
	 * other capability on the role, removed or added by the site owner, is
	 * left alone.
	 *
	 * The ledger then records the defaults as granted and empty `removed`
	 * and `denied` lists.
	 *
	 * @return void
	 */
	private static function sync_translator_role(): void {
		$defaults = self::translator_caps();
		$ledger   = self::translator_ledger();
		$role     = get_role( self::ROLE_SLUG );

		if ( null === $role ) {
			$caps = $defaults;

			// Only a default grant can be subtracted: dropping an explicit
			// `false` would lift a denial for users who also hold another role.
			foreach ( $ledger['removed'] as $cap ) {
				if ( true === ( $caps[ $cap ] ?? null ) ) {
					unset( $caps[ $cap ] );
				}
			}

			// A denied default comes back as an explicit `false`, not as an
			// absence: for a user who also holds another role that grants
			// it, only the `false` keeps the capability denied. Only a
			// default grant is turned into a deny.
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
		 * Keys are capability names, values grant (true) or deny (false). A
		 * capability added here is granted on the next rebuild, and one taken
		 * out here is removed from the role on the next rebuild. Capabilities
		 * a site owner removed from or added to the role by hand are kept.
		 *
		 * A rebuild runs when `perflocale_caps_version` is behind the plugin's
		 * capability version: after a plugin update that changes capabilities,
		 * after activation, or after that option is deleted.
		 *
		 * @hook perflocale/roles/translator_caps
		 *
		 * @param array<string, bool> $caps Map of capability => grant. Default: read, the edit
		 *                                  capabilities for posts and pages (own, others' and
		 *                                  published), upload_files, perflocale_translate and
		 *                                  perflocale_use_mt.
		 */
		$caps = apply_filters( 'perflocale/roles/translator_caps', array_merge( self::TRANSLATOR_BASE_CAPS, self::TRANSLATOR_CAPS ) );

		return self::normalise_caps( $caps );
	}

	/**
	 * Read the Translator role's capability ledger.
	 *
	 * An absent or unreadable `granted` map reads as TRANSLATOR_CAPS_V4, the
	 * capability set every install had before the ledger existed, so the
	 * first rebuild on an existing site changes only what differs from it
	 * (a changed default, or the filter). An absent or unreadable `removed`
	 * or `denied` list reads as empty.
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
	 * Normalise a stored list of capability names: `sanitize_key()` values,
	 * without duplicates. A value that is not a string, or that sanitises to
	 * '', is dropped; anything other than an array reads as an empty list.
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
	 * Normalise a capability map from a filter or a stored option:
	 * `sanitize_key()` keys, boolean values. Entries without a string key,
	 * or whose key sanitises to '', are dropped.
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
	 * Hook callback for admin_init. Thin wrapper around install_caps() that
	 * keeps the existing public API intact for any callers that already
	 * relied on the instance method.
	 *
	 * @return void
	 */
	public function ensure_roles_exist(): void {
		self::install_caps();
		self::install_shop_manager_caps();
	}

	/**
	 * Grant WooCommerce's Shop Manager role `perflocale_translate`, once per
	 * site, so shop managers (who already edit every product) can create
	 * product translations.
	 *
	 * Runs on admin_init and on activation. Does nothing unless WooCommerce is
	 * loaded, its PerfLocale add-on is not disabled and the `shop_manager`
	 * role exists, so a site that installs WooCommerce later gets the grant on
	 * its next admin request.
	 *
	 * The first pass adds the filtered capabilities the role has no entry for
	 * and records them in the SHOP_MANAGER_OPTION option; every later pass
	 * returns at that record, so a capability the site owner removes is never
	 * granted again. A pass after a deactivation restores the recorded
	 * capabilities remove_roles() took off, except the ones the owner had
	 * already removed. A capability the role already has an entry for, a
	 * grant or an explicit deny, is never changed by either pass; one the
	 * role held before the first pass is not recorded, so a deactivation
	 * leaves it alone.
	 *
	 * After the first pass the cost is a class check and two autoloaded
	 * option reads; without WooCommerce, a class check.
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
		 * Filter the PerfLocale capabilities granted once to WooCommerce's Shop
		 * Manager role.
		 *
		 * Return an empty array to grant nothing. The grant runs once per site;
		 * to run it again, delete the `perflocale_shop_manager_caps` option.
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

			// An entry the role already has is the owner's: an explicit deny
			// set before this pass stays a deny.
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

		// The first pass after a deactivation: put back what remove_roles()
		// took off. Only a capability the filter still grants, with the same
		// value, is restored, so a stored record can never widen the role;
		// and only where the role has no entry for it, so a deny the owner
		// set while the plugin was inactive stays a deny.
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
	 * Take the recorded Shop Manager grant off the role on deactivation, and
	 * record which of those capabilities the role no longer held (removed by
	 * the site owner) so the next grant pass leaves them out.
	 *
	 * Does nothing when nothing is recorded, when the record already carries
	 * a `removed` list (a second deactivation before any grant pass), or when
	 * the role is gone.
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
	 * Before the Translator role is deleted, the default grants it no longer
	 * holds are stored in the ledger: in `denied` when the role holds the
	 * capability as an explicit deny, in `removed` when it has no entry for
	 * it. The role install_caps() creates on the next activation denies the
	 * first and leaves out the second, as the owner had them. A default the
	 * ledger has not recorded as granted yet (added by the filter since the
	 * last rebuild) is listed in neither. When the role is already gone, the
	 * ledger is left as it is.
	 *
	 * The Shop Manager grant is taken off the same way (see
	 * strip_shop_manager_caps()).
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
		 * Remove a role slug from this array to preserve its PerfLocale capabilities
		 * after deactivation - useful when re-activating frequently during development.
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

			// Retired capabilities too — otherwise a site that installed an
			// older build keeps them on the role forever.
			foreach ( self::LEGACY_CAPABILITIES as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		// Sweep user_meta for orphan `perflocale_*` capability entries left
		// behind by direct add_cap() grants or by removing a role users were
		// assigned to. Without this, the next deactivate/reactivate cycle
		// inherits stale entries that bypass the canonical role+cap install
		// path. Shared with SiteCleanup::strip_role_and_caps so the two
		// codepaths can't drift.
		if ( class_exists( \PerfLocale\Database\SiteCleanup::class ) ) {
			\PerfLocale\Database\SiteCleanup::sweep_orphan_user_caps();
		}

		delete_option( 'perflocale_caps_version' );
	}
}
