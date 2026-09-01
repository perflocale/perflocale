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
 * perflocale_translate - Create and edit translations (Translator, Editor, Admin)
 * perflocale_manage_translations - Manage translations and translation settings (Editor, Admin)
 * perflocale_approve_translations - Approve/reject pending translations in review (Editor, Admin)
 * perflocale_manage_languages - Add/edit/delete languages (Admin only)
 * perflocale_manage_addons - Install/activate/deactivate addons (Admin only)
 * perflocale_use_mt - Use machine translation (Translator, Editor, Admin)
 * perflocale_import_export - Import/export translations (Admin only)
 *
 * `perflocale_approve_translations` gates the review-workflow approve/reject
 * action (consumed by the Visual Editor's user_can_approve()). It is granted
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
		// Retired with the Glossary feature.
		'perflocale_manage_glossary',
	];

	/**
	 * Capabilities granted to the Translator role.
	 */
	private const TRANSLATOR_CAPS = [
		'perflocale_translate' => true,
		'perflocale_use_mt'    => true,
	];

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

		// Anonymise background-job rows when a user is deleted. Wire BOTH
		// hooks: WP fires `delete_user` from the single-site flow and
		// from `wp_delete_user`, but `wpmu_delete_user` (network-admin
		// delete) does NOT fire `delete_user` — so without the second
		// hook, background-job rows would keep created_by pointing at a
		// since-deleted user, and the worker's cap re-check would later
		// silently fail.
		add_action( 'delete_user', [ $this, 'on_user_deleted' ] );
		add_action( 'wpmu_delete_user', [ $this, 'on_user_deleted' ] );
		// `remove_user_from_blog` passes ($user_id, $blog_id) — when a
		// network admin revokes a user's access to a specific subsite we
		// need to scrub THAT blog's tables/options, not the blog the
		// network-admin request happens to be served from. The dedicated
		// callback below switches into the target blog before delegating.
		add_action( 'remove_user_from_blog', [ $this, 'on_user_removed_from_blog' ], 10, 2 );
	}

	/**
	 * Bridge for the `remove_user_from_blog` action. Switches into the
	 * target blog so the JobState scrub touches the right blog's data,
	 * then delegates to {@see on_user_deleted}.
	 *
	 * @param int $user_id User being removed from the blog.
	 * @param int $blog_id Blog the user is being removed from.
	 * @return void
	 */
	public function on_user_removed_from_blog( int $user_id, int $blog_id ): void {
		// Site teardown: core's wp_uninitialize_site() removes every member
		// of a blog being permanently deleted — AFTER SiteCleanup (hooked at
		// priority 5) has already dropped that blog's plugin tables. There is
		// nothing left to anonymize, and running would only log a spurious
		// "Table doesn't exist" per deleted subsite.
		if ( $blog_id > 0 && \PerfLocale\Database\SiteCleanup::was_site_purged( $blog_id ) ) {
			return;
		}

		$switched = false;
		if ( $blog_id > 0
			&& function_exists( 'is_multisite' ) && is_multisite()
			&& function_exists( 'get_current_blog_id' ) && $blog_id !== (int) get_current_blog_id()
		) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			$this->on_user_deleted( $user_id );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
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
	 * routine is fully idempotent (remove_role + add_role rebuilds
	 * the translator role; add_cap is a no-op when the cap already
	 * exists), so a single "trigger" flag is enough.
	 *
	 * Bump this whenever CAPABILITIES, TRANSLATOR_CAPS, EDITOR_CAPS,
	 * or ADMIN_CAPS change. The next admin request on every existing
	 * install will see `db_version < CAPS_VERSION` and re-run the
	 * install, picking up the new cap set.
	 *
	 * v2: added `perflocale_manage_addons` consumption on the AdminController
	 *     handlers + AddonsPage UI gates. Existing administrators need this
	 *     cap re-applied or the per-card Save/Enable/Disable buttons stop
	 *     working silently.
	 * v3: added `perflocale_approve_translations` (Editor + Admin) — the
	 *     dedicated review-workflow approve/reject cap consumed by the Visual
	 *     Editor's user_can_approve(). Formerly the approve gate relied solely
	 *     on `perflocale_manage_translations`; existing Editors/Admins need the
	 *     new cap granted so a dedicated reviewer role can be given approve
	 *     rights without the broader manage cap.
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

		// Create or update the Translator role.
		remove_role( self::ROLE_SLUG );

		add_role(
			self::ROLE_SLUG,
			__( 'Translator', 'perflocale' ),
			array_merge(
				[
					'read'                 => true,
					'edit_posts'           => true,
					'edit_others_posts'    => true,
					'edit_published_posts' => true,
					'edit_pages'           => true,
					'edit_others_pages'    => true,
					'edit_published_pages' => true,
					'upload_files'         => true,
				],
				self::TRANSLATOR_CAPS
			)
		);

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
	 * Hook callback for admin_init. Thin wrapper around install_caps() that
	 * keeps the existing public API intact for any callers that already
	 * relied on the instance method.
	 *
	 * @return void
	 */
	public function ensure_roles_exist(): void {
		self::install_caps();
	}

	/**
	 * Remove all custom roles and capabilities on plugin deactivation.
	 *
	 * @return void
	 */
	public static function remove_roles(): void {
		remove_role( self::ROLE_SLUG );

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
