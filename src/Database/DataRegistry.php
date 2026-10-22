<?php
/**
 * The data registry: every piece of data PerfLocale stores, and what each
 * stored-data path does with it.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One declaration per stored item: the tables with their columns, options
 * (exact names and name patterns), network options, transients, post, term,
 * user and order meta, files under uploads/perflocale/, cron hooks and the
 * keys of `perflocale_settings`. Each entry says what the item is, who owns
 * it, which export section carries it or why it stays out, how an import
 * treats it, what uninstall does with it, and whether a new translation
 * copies it and Sync Fields may mirror it.
 *
 * Pure data. The class holds literal constants and static query methods that
 * use PHP core functions only: it references no other class and calls no
 * WordPress function, apart from the guarded `apply_filters()` calls that read
 * the extension entries ({@see self::extension_entries()}); the check of an
 * extension name against WordPress core names reads the table prefix from
 * `$GLOBALS['wpdb']` when it is there. `uninstall.php`
 * loads this file by hand, without the autoloader, and the release gate's
 * static check (tools/release-gate/gate-exim.php) loads it in a plain PHP
 * process; both rely on that.
 *
 * Never loaded on a visitor request: only the export, import, uninstall,
 * deactivation, translation-create and Sync Fields save paths read it, and
 * the autoloader compiles a class only on its first reference. The gate's
 * rule E8 keeps the list of files that may reference it.
 *
 * Entry shape (every key required, `note` and `gap` optional):
 *
 *   kind        table, option, network_option, transient, site_transient,
 *               post_meta, term_meta, user_meta, order_meta, file, cron.
 *               The keys of `perflocale_settings` are in SETTINGS.
 *   name        Exact name, or a pattern with placeholders (PLACEHOLDERS).
 *   owner       core, wc (src/WooCommerce), wp-core (WordPress writes it for
 *               the plugin), addon:<dir> (a bundled add-on); extension
 *               entries also pro or ext:<slug>.
 *   class       USER, CONFIG, SECRET, DERIVED, STATE, DIAG, LEGACY (only read
 *               or deleted, written by earlier builds), FOREIGN (a name the
 *               plugin writes that another system owns), NAMESPACE (the
 *               uninstall sweep of a whole prefix, never a stored item).
 *   export      The export section that carries it, or null.
 *   not_exported  Why it stays out of the export (NOT_EXPORTED), or null.
 *               Exactly one of export and not_exported is set.
 *   import      null, or merge / replace / ids / object (see IMPORT_*).
 *               Required when export is set.
 *   uninstall   data (deleted by a full-data uninstall), always (deleted in
 *               both modes), last (deleted last, only by a clean full-data
 *               purge), own_step (deleted by a dedicated step of the purge,
 *               not by the generic sweep), keep.
 *   scope       blog, network, network_final (user meta: swept once after
 *               the network walk, never on a single subsite teardown).
 *   copy        post_meta: yes, no, if_synced (copied onto a new translation
 *               only when Sync Fields lists it); n/a for other kinds.
 *   sync        post_meta: never, if_listed (Sync Fields may list it); n/a
 *               for other kinds.
 *   deactivate  cron: clear (Deactivator unschedules it) or keep. option:
 *               clear (deactivation deletes it; WordPress or the plugin
 *               writes it again when next needed) or n/a. n/a for other
 *               kinds.
 *   fixture     The step of the export/import lane fixture that produces it,
 *               or none:<reason>.
 *   since       Plugin version that introduced it.
 *   gap         The audit gap id (G-n) while the export does not carry it
 *               yet: the registry already names the section, and the
 *               exporter skips the entry until the commit that closes the
 *               gap removes this key.
 *   note        Free text.
 *   sanitize_import  Extension entries only: the callable the importer
 *               passes each imported value through before it stores it,
 *               `fn( mixed $value, string $name ): mixed`. Required for an
 *               extension entry that is exported (into Add-on data).
 *   group       Extension entries exported into Add-on data only: the
 *               add-on's own checkbox on the export and import screens,
 *               `[ 'id' => <GROUP_ID_PATTERN>, 'label' => <text> ]`. The
 *               entries of one owner and group id travel and are chosen
 *               together, apart from the shared "Add-on data" checkbox;
 *               they share one label. PerfLocale's own entries have none.
 *
 * Entry ids are `<kind>:<name>`.
 */
final class DataRegistry {

	/**
	 * Version of the entry shape. Bumped when a key is added, removed or
	 * changes meaning, so a tool reading the registry can refuse a shape it
	 * does not know.
	 */
	public const REGISTRY_VERSION = 2;

	/**
	 * The filter through which add-ons and extensions declare their stored
	 * data. See {@see self::extension_entries()}.
	 */
	public const EXTENSION_FILTER = 'perflocale/data_registry/entries';

	public const KINDS = [ 'table', 'option', 'network_option', 'transient', 'site_transient', 'post_meta', 'term_meta', 'user_meta', 'order_meta', 'file', 'cron' ];

	public const META_KINDS = [ 'post_meta', 'term_meta', 'user_meta', 'order_meta' ];

	public const CLASSES = [ 'USER', 'CONFIG', 'SECRET', 'DERIVED', 'STATE', 'DIAG', 'LEGACY', 'FOREIGN', 'NAMESPACE' ];

	public const NOT_EXPORTED = [ 'derived', 'secret', 'site_specific', 'transient_state', 'per_user', 'foreign', 'legacy', 'namespace' ];

	public const IMPORT_MERGE = [ 'insert_if_absent', 'union_keys', 'natural_key', 'never' ];

	public const IMPORT_REPLACE = [ 'overwrite', 'wipe_and_insert', 'apply_config' ];

	public const IMPORT_IDS = [ 'none', 'same_site', 'fk_map' ];

	public const IMPORT_OBJECTS = [ 'attachment', 'nav_menu', 'shop_order', 'translatable_post', 'product', 'gf_form' ];

	public const UNINSTALL = [ 'data', 'always', 'last', 'own_step', 'keep' ];

	public const SCOPES = [ 'blog', 'network', 'network_final' ];

	public const COPY = [ 'yes', 'no', 'if_synced' ];

	public const SYNC = [ 'never', 'if_listed' ];

	public const DEACTIVATE = [ 'clear', 'keep' ];

	/**
	 * What CacheManager::flush_all() does with a transient of each class:
	 * keeps a STATE one, deletes a DERIVED one (rebuilt when next needed)
	 * and a LEGACY one (read by nothing); a NAMESPACE entry is the uninstall
	 * sweep, not a stored item. A transient of another class has no rule
	 * (the release gate's rule E13).
	 */
	public const FLUSH_TRANSIENT_CLASSES = [
		'STATE'     => 'keep',
		'DERIVED'   => 'delete',
		'LEGACY'    => 'delete',
		'NAMESPACE' => 'none',
	];

	/**
	 * The name prefix of the transients CacheManager::flush_all() sweeps.
	 */
	public const FLUSH_SWEEP_PREFIX = 'perflocale_';

	public const REQUIRED_KEYS = [ 'kind', 'name', 'owner', 'class', 'export', 'not_exported', 'import', 'uninstall', 'scope', 'copy', 'sync', 'deactivate', 'fixture', 'since' ];

	public const OPTIONAL_KEYS = [ 'note', 'gap', 'sanitize_import', 'group' ];

	/**
	 * The export section that carries add-on data, and in which an extension
	 * entry may declare a group.
	 */
	public const ADDON_SECTION = 'addon_data';

	/**
	 * A group id: lowercase letters, digits, `-` and `_`, 1 to 40 characters.
	 */
	public const GROUP_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

	/**
	 * Longest group label, in bytes.
	 */
	public const GROUP_LABEL_MAX_BYTES = 200;

	/**
	 * Where add-on data sits in the file. Each add-on's data is a part,
	 * keyed `<owner>` (the shared "Add-on data" checkbox) or
	 * `<owner>/<group id>` (its own checkbox), and each part travels as its
	 * own row lists inside `data`, one member per row list:
	 * `addon_data/<part>/<row list>` (ROW_LISTS values). The envelope member
	 * ADDON_GROUPS_KEY, next to `sections`, maps each exported group part to
	 * its label; it is written only when the file carries a group.
	 */
	public const ADDON_MEMBER_PREFIX = 'addon_data/';

	/**
	 * Envelope member that names the group parts a file carries: part =>
	 * label.
	 */
	public const ADDON_GROUPS_KEY = 'addon_groups';

	/**
	 * Column classes of TABLES. `fk:<table>` and `object_ref:<type column>`
	 * carry their target after the colon.
	 */
	public const COLUMN_CLASSES = [ 'pk', 'user', 'natural_key', 'timestamp', 'derived', 'state' ];

	/**
	 * How a Merge import treats a table's rows: natural_key (look the row up
	 * by its natural key, insert when absent, map its id), fk_map (a new row
	 * per source row, ids mapped), skip_on_unique (insert, skip a row that
	 * hits a unique key).
	 */
	public const TABLE_MERGE = [ 'natural_key', 'fk_map', 'skip_on_unique' ];

	/**
	 * Classes of the keys of `perflocale_settings`: portable (exported,
	 * applied by a Replace import), secret (removed from the file; the target
	 * keeps its own value), site_specific (exported; describes this site, a
	 * Replace keeps the target's value), state (exported, ignored on import).
	 */
	public const SETTING_CLASSES = [ 'portable', 'secret', 'site_specific', 'state' ];

	/**
	 * Name placeholders and the regular expression each one stands for.
	 */
	public const PLACEHOLDERS = [
		'{id}'     => '[0-9]+',
		'{user}'   => '[0-9]+',
		'{lang}'   => '[a-z]{2,3}(?:-[a-z]{2,3})?',
		'{md5}'    => '[0-9a-f]{32}',
		'{group}'  => 'perflocale(?:_[a-z]+)*',
		'{type}'   => '[a-z0-9_]+',
		'{ym}'     => '[0-9]{4}_[0-9]{2}',
		'{token}'  => '[A-Za-z0-9_-]+',
		'{key}'    => '[A-Za-z0-9_:-]+',
		'{addon}'  => '[a-z0-9-]+',
		'{locale}' => '[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z]+)?',
		'{prefix}' => '[A-Za-z0-9_]+',
	];

	/**
	 * Two sample values per placeholder: the overlap check expands every
	 * pattern with them and tests the result against every other entry.
	 */
	public const PLACEHOLDER_SAMPLES = [
		'{id}'     => [ '7', '1234' ],
		'{user}'   => [ '3', '42' ],
		'{lang}'   => [ 'de', 'pt-br' ],
		'{md5}'    => [ '0123456789abcdef0123456789abcdef', 'fedcba9876543210fedcba9876543210' ],
		'{group}'  => [ 'perflocale', 'perflocale_trans' ],
		'{type}'   => [ 'post', 'data_import' ],
		'{ym}'     => [ '2026_10', '1999_01' ],
		'{token}'  => [ 'abc', 'Z9-x_1' ],
		'{key}'    => [ 'translations_post_12', 'fr:5:0123456789abcdef0123456789abcdef' ],
		'{addon}'  => [ 'gravity-forms', 'woocommerce' ],
		'{locale}' => [ 'de_DE', 'pt_BR_formal' ],
		'{prefix}' => [ 'wp_', 'wp_2_' ],
	];

	/**
	 * Kinds an extension entry may declare. Tables, files and the settings
	 * keys belong to the core schema; an add-on with its own table declares
	 * it through HasSchema and HasUninstallTargets.
	 */
	public const EXTENSION_KINDS = [ 'option', 'network_option', 'transient', 'site_transient', 'post_meta', 'term_meta', 'user_meta', 'order_meta', 'cron' ];

	/**
	 * Names that look like a credential: the exporter's own redaction
	 * pattern (DataExporter::CREDENTIAL_KEY_PATTERN, kept equal by the
	 * release gate's rule E7). An extension entry exported into Add-on data
	 * may not have such a name unless perflocale/data_registry/is_secret
	 * declares it not secret.
	 */
	public const CREDENTIAL_NAME_PATTERN = '/_api_?key|_secret|_password|_credentials?|_encrypted|_token(?:_|$)|_auth(?:_|$)|_key$/i';

	/**
	 * WordPress core option names an exported extension entry may not take:
	 * the options populate_options() installs, the role list, the cron
	 * array, the keys and salts wp_salt() and recovery mode store, core's
	 * one-time tokens, the theme, widget, menu and update state core keeps
	 * per site, and the core widgets' instance options. `{token}` stands for
	 * a theme slug, a taxonomy name, or what core's auto_update_ options
	 * cover. Compared without regard to case: the option_name column's
	 * collation is case-insensitive.
	 */
	public const WP_CORE_OPTIONS = [
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'users_can_register',
		'admin_email',
		'start_of_week',
		'use_balanceTags',
		'use_smilies',
		'require_name_email',
		'comments_notify',
		'posts_per_rss',
		'rss_use_excerpt',
		'mailserver_url',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'default_category',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'posts_per_page',
		'date_format',
		'time_format',
		'links_updated_date_format',
		'comment_moderation',
		'moderation_notify',
		'permalink_structure',
		'rewrite_rules',
		'hack_file',
		'blog_charset',
		'moderation_keys',
		'active_plugins',
		'category_base',
		'ping_sites',
		'comment_max_links',
		'gmt_offset',
		'default_email_category',
		'recently_edited',
		'template',
		'stylesheet',
		'comment_registration',
		'html_type',
		'use_trackback',
		'default_role',
		'db_version',
		'uploads_use_yearmonth_folders',
		'upload_path',
		'blog_public',
		'default_link_category',
		'show_on_front',
		'tag_base',
		'show_avatars',
		'avatar_rating',
		'upload_url_path',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'avatar_default',
		'large_size_w',
		'large_size_h',
		'image_default_link_type',
		'image_default_size',
		'image_default_align',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'sticky_posts',
		'widget_categories',
		'widget_text',
		'widget_rss',
		'widget_archives',
		'widget_block',
		'widget_calendar',
		'widget_custom_html',
		'widget_links',
		'widget_meta',
		'widget_nav_menu',
		'widget_pages',
		'widget_recent-comments',
		'widget_recent-posts',
		'widget_search',
		'widget_tag_cloud',
		'widget_media_audio',
		'widget_media_gallery',
		'widget_media_image',
		'widget_media_video',
		'widget_recent_entries',
		'widget_recent_comments',
		'uninstall_plugins',
		'timezone_string',
		'page_for_posts',
		'page_on_front',
		'default_post_format',
		'link_manager_enabled',
		'finished_splitting_shared_terms',
		'site_icon',
		'medium_large_size_w',
		'medium_large_size_h',
		'wp_page_for_privacy_policy',
		'show_comments_cookies_opt_in',
		'admin_email_lifespan',
		'disallowed_keys',
		'comment_previously_approved',
		'auto_plugin_theme_update_emails',
		'auto_update_core_dev',
		'auto_update_core_minor',
		'auto_update_core_major',
		'wp_force_deactivated_plugins',
		'wp_attachment_pages_enabled',
		'wp_notes_notify',
		'initial_db_version',
		'{wpdb}user_roles',
		'cron',
		'fresh_site',
		'auth_key',
		'auth_salt',
		'secure_auth_key',
		'secure_auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secret_key',
		'recovery_keys',
		'recovery_mode_auth_key',
		'recovery_mode_auth_salt',
		'adminhash',
		'delete_blog_hash',
		'WPLANG',
		'new_admin_email',
		'sidebars_widgets',
		'theme_mods_{token}',
		'current_theme',
		'theme_switched',
		'stylesheet_root',
		'template_root',
		'nav_menu_options',
		'theme_switch_menu_locations',
		'customize_stashed_theme_mods',
		'site_logo',
		'recently_activated',
		'auto_update_{token}',
		'db_upgraded',
		'can_compress_scripts',
		'default_term_{token}',
	];

	/**
	 * Meta keys WordPress core owns, which an exported extension entry may
	 * not take (post, term, user or order meta). `{wpdb}` stands for the
	 * table prefix; the other placeholders are those of PLACEHOLDERS.
	 */
	public const WP_CORE_META = [
		'_wp_{type}',
		'_edit_lock',
		'_edit_last',
		'_thumbnail_id',
		'_menu_item_{type}',
		'_encloseme',
		'_pingme',
		'enclosure',
		'_oembed_{md5}',
		'_oembed_time_{md5}',
		'{wpdb}capabilities',
		'{wpdb}{id}_capabilities',
		'{wpdb}user_level',
		'{wpdb}{id}_user_level',
		'{wpdb}user-settings',
		'{wpdb}user-settings-time',
		'{wpdb}persisted_preferences',
		'{wpdb}dashboard_quick_press_last_post_id',
		'user_level',
		'session_tokens',
		'_application_passwords',
		'nickname',
		'first_name',
		'last_name',
		'description',
		'rich_editing',
		'syntax_highlighting',
		'comment_shortcuts',
		'admin_color',
		'use_ssl',
		'show_admin_bar_front',
		'locale',
		'dismissed_wp_pointers',
		'default_password_nag',
		'primary_blog',
		'source_domain',
		'show_welcome_panel',
	];

	/**
	 * Export sections: label (as the export screen shows it), live (the
	 * exporter writes it; a planned section is declared ahead of the commit
	 * that builds its writer), id_keyed (its rows hold post, term,
	 * attachment, order or form ids, so the importer's site-address gate
	 * applies), extensible (an extension entry may name it). Order is the
	 * order of the export screen.
	 *
	 * Where a section's data sits in the file: {@see self::section_transports()}.
	 */
	public const EXPORT_SECTIONS = [
		'settings'     => [
			'label'      => 'Settings',
			'live'       => true,
			'id_keyed'   => false,
			'extensible' => false,
		],
		'roles'        => [
			'label'      => 'Roles & Capabilities',
			'live'       => true,
			'id_keyed'   => false,
			'extensible' => false,
		],
		'languages'    => [
			'label'      => 'Languages',
			'live'       => true,
			'id_keyed'   => false,
			'extensible' => false,
		],
		'translations' => [
			'label'      => 'Translation Links',
			'live'       => true,
			'id_keyed'   => true,
			'extensible' => false,
		],
		'strings'      => [
			'label'      => 'String Translations',
			'live'       => true,
			'id_keyed'   => false,
			'extensible' => false,
		],
		'slugs'        => [
			'label'      => 'Slug Translations',
			'live'       => true,
			'id_keyed'   => true,
			'extensible' => false,
		],
		'hashes'       => [
			'label'      => 'Content Hashes',
			'live'       => true,
			'id_keyed'   => true,
			'extensible' => false,
		],
		'media'        => [
			'label'      => 'Media',
			'live'       => true,
			'id_keyed'   => true,
			'extensible' => false,
		],
		'menus'        => [
			'label'      => 'Menus',
			'live'       => true,
			'id_keyed'   => true,
			'extensible' => false,
		],
		'item_flags'   => [
			'label'      => 'Item flags',
			'live'       => true,
			'id_keyed'   => true,
			'extensible' => false,
		],
		'addon_data'   => [
			'label'      => 'Add-on data',
			'live'       => true,
			'id_keyed'   => true,
			'extensible' => true,
		],
	];

	/**
	 * The file's layout: everything a section carries travels inside the
	 * envelope's `data` object, as one row list per table (`data.<table>`)
	 * and, for options and meta, as one of these row lists next to them,
	 * whatever the section: entry kind => row list. Option rows are
	 * `{ "name", "value" }`; meta rows `{ "object_id", "meta_key",
	 * "meta_value", "_fp" }` (the object's fingerprint). An importer that
	 * does not know a row list skips it.
	 */
	public const ROW_LISTS = [
		'option'     => 'options',
		'post_meta'  => 'post_meta',
		'term_meta'  => 'term_meta',
		'order_meta' => 'order_meta',
	];

	/**
	 * Exported entries that travel under their own top-level envelope key
	 * instead (each a key of DataExporter::RESERVED_SECTION_KEYS): entry id
	 * => envelope key. `roles` is the role snapshot (caps_version, the
	 * Translator role's caps, the perflocale_* grants of the cap roles).
	 */
	public const ENVELOPE_KEYS = [
		'option:perflocale_settings'        => 'settings',
		'option:perflocale_addon_settings'  => 'addon_settings',
		'option:perflocale_disabled_addons' => 'disabled_addons',
		'option:perflocale_caps_version'    => 'roles',
		'option:{prefix}user_roles'         => 'roles',
	];

	/**
	 * The plugin's tables, one row per table (short name, without the
	 * `{prefix}perflocale_` part).
	 *
	 * Fields of each row:
	 *
	 * import_order  Position in the import (parents first); null when the
	 *               table is not exported.
	 * sections      Export section => the type_scope that section owns of
	 *               the table (null: the whole table). A table claimed
	 *               without a scope by any selected section exports whole.
	 * columns       Column => class (COLUMN_CLASSES, `fk:<table>`,
	 *               `object_ref:<type column>`), in DDL order.
	 * unique        Every unique key, the primary key first, as column lists.
	 * natural_key   Columns that identify a row across sites (fk and
	 *               object_ref columns mapped through their target's natural
	 *               key); `@members` for a group (its sorted member tuples).
	 * merge         TABLE_MERGE value, null when not imported.
	 * replace       wipe_and_insert (scoped to the section), null when not
	 *               imported.
	 * compare       Column => exact, natural or ignore, where the round-trip
	 *               comparison differs from the column class default.
	 */
	public const TABLES = [
		'languages'            => [
			'import_order' => 1,
			'sections'     => [
				'languages' => null,
			],
			'columns'      => [
				'id'             => 'pk',
				'slug'           => 'natural_key',
				'locale'         => 'natural_key',
				'name'           => 'user',
				'native_name'    => 'user',
				'flag'           => 'user',
				'is_default'     => 'user',
				'is_active'      => 'user',
				'sort_order'     => 'user',
				'date_format'    => 'user',
				'time_format'    => 'user',
				'text_direction' => 'user',
			],
			'unique'       => [ [ 'id' ], [ 'slug' ], [ 'locale' ] ],
			'natural_key'  => [ 'slug' ],
			'merge'        => 'natural_key',
			'replace'      => 'wipe_and_insert',
			'compare'      => [],
		],
		'translation_groups'   => [
			'import_order' => 2,
			'sections'     => [
				'translations' => null,
				'strings'      => 'string',
			],
			'columns'      => [
				'id'         => 'pk',
				'type'       => 'user',
				'created_at' => 'timestamp',
			],
			'unique'       => [ [ 'id' ] ],
			'natural_key'  => [ '@members' ],
			'merge'        => 'fk_map',
			'replace'      => 'wipe_and_insert',
			'compare'      => [
				'created_at' => 'ignore',
			],
		],
		'translation_links'    => [
			'import_order' => 5,
			'sections'     => [
				'translations' => null,
			],
			'columns'      => [
				'id'          => 'pk',
				'group_id'    => 'fk:translation_groups',
				'object_id'   => 'object_ref:type',
				'language_id' => 'fk:languages',
				'type'        => 'user',
				'status'      => 'user',
				'source'      => 'user',
				'created_at'  => 'timestamp',
				'updated_at'  => 'timestamp',
			],
			'unique'       => [ [ 'id' ], [ 'group_id', 'language_id' ], [ 'type', 'object_id', 'language_id' ] ],
			'natural_key'  => [ 'type', 'object_id', 'language_id' ],
			'merge'        => 'skip_on_unique',
			'replace'      => 'wipe_and_insert',
			'compare'      => [],
		],
		'strings'              => [
			'import_order' => 3,
			'sections'     => [
				'strings' => null,
			],
			'columns'      => [
				'id'            => 'pk',
				'domain'        => 'user',
				'context'       => 'user',
				'original'      => 'user',
				'original_hash' => 'natural_key',
				'group_id'      => 'fk:translation_groups',
				'file_path'     => 'derived',
				'line_number'   => 'derived',
				'created_at'    => 'timestamp',
				'last_seen_at'  => 'timestamp',
			],
			'unique'       => [ [ 'id' ], [ 'original_hash' ] ],
			'natural_key'  => [ 'original_hash' ],
			'merge'        => 'natural_key',
			'replace'      => 'wipe_and_insert',
			'compare'      => [],
		],
		'string_translations'  => [
			'import_order' => 4,
			'sections'     => [
				'strings' => null,
			],
			'columns'      => [
				'string_id'   => 'fk:strings',
				'language_id' => 'fk:languages',
				'translation' => 'user',
				'extra_forms' => 'user',
				'updated_at'  => 'timestamp',
			],
			'unique'       => [ [ 'string_id', 'language_id' ] ],
			'natural_key'  => [ 'string_id', 'language_id' ],
			'merge'        => 'skip_on_unique',
			'replace'      => 'wipe_and_insert',
			'compare'      => [],
		],
		'slug_translations'    => [
			'import_order' => 6,
			'sections'     => [
				'slugs' => null,
			],
			'columns'      => [
				'id'             => 'pk',
				'object_type'    => 'user',
				'object_subtype' => 'user',
				'object_id'      => 'object_ref:object_type',
				'language_id'    => 'fk:languages',
				'slug'           => 'user',
			],
			'unique'       => [ [ 'id' ], [ 'object_type', 'object_id', 'language_id' ], [ 'language_id', 'object_type', 'object_subtype', 'slug' ] ],
			'natural_key'  => [ 'object_type', 'object_subtype', 'object_id', 'language_id' ],
			'merge'        => 'skip_on_unique',
			'replace'      => 'wipe_and_insert',
			'compare'      => [],
		],
		'content_hashes'       => [
			'import_order' => 7,
			'sections'     => [
				'hashes' => null,
			],
			'columns'      => [
				'id'           => 'pk',
				'object_id'    => 'object_ref:object_type',
				'object_type'  => 'user',
				'content_hash' => 'derived',
				'updated_at'   => 'timestamp',
			],
			'unique'       => [ [ 'id' ], [ 'object_type', 'object_id' ] ],
			'natural_key'  => [ 'object_type', 'object_id' ],
			'merge'        => 'skip_on_unique',
			'replace'      => 'wipe_and_insert',
			'compare'      => [],
		],
		'jobs'                 => [
			'import_order' => null,
			'sections'     => [],
			'columns'      => [
				'id'           => 'pk',
				'uuid'         => 'state',
				'type'         => 'state',
				'hook'         => 'state',
				'engine'       => 'state',
				'status'       => 'state',
				'progress'     => 'state',
				'total'        => 'state',
				'processed'    => 'state',
				'attempts'     => 'state',
				'created_by'   => 'state',
				'blog_id'      => 'state',
				'version'      => 'state',
				'created_at'   => 'timestamp',
				'started_at'   => 'timestamp',
				'completed_at' => 'timestamp',
				'updated_at'   => 'timestamp',
				'args'         => 'state',
				'result'       => 'state',
				'error'        => 'state',
				'log'          => 'state',
			],
			'unique'       => [ [ 'id' ], [ 'uuid' ] ],
			'natural_key'  => [ 'uuid' ],
			'merge'        => null,
			'replace'      => null,
			'compare'      => [],
		],
		'migration_source_map' => [
			'import_order' => null,
			'sections'     => [],
			'columns'      => [
				'id'             => 'pk',
				'migration_type' => 'state',
				'source_key'     => 'state',
				'group_id'       => 'fk:translation_groups',
				'created_at'     => 'timestamp',
			],
			'unique'       => [ [ 'id' ], [ 'migration_type', 'source_key' ] ],
			'natural_key'  => [ 'migration_type', 'source_key' ],
			'merge'        => null,
			'replace'      => null,
			'compare'      => [],
		],
	];

	/**
	 * Every key of `perflocale_settings` (Settings::DEFAULTS) => its class.
	 */
	public const SETTINGS = [
		'url_mode'                       => 'portable',
		'url_prefix_type'                => 'portable',
		'hide_default_prefix'            => 'portable',
		'language_domains'               => 'site_specific',
		'language_detection_order'       => 'portable',
		'cookie_lifetime'                => 'portable',
		'disable_language_cookie'        => 'portable',
		'redirect_browser_lang'          => 'portable',
		'redirect_geo_enabled'           => 'portable',
		'redirect_edge_hint_enabled'     => 'portable',
		'redirect_priority_order'        => 'portable',
		'geo_provider'                   => 'portable',
		'geo_country_map'                => 'portable',
		'geo_cache_hours'                => 'portable',
		'missing_translation_action'     => 'portable',
		'language_fallbacks'             => 'portable',
		'excluded_paths'                 => 'portable',
		'translatable_post_types'        => 'portable',
		'translatable_taxonomies'        => 'portable',
		'translatable_meta_keys'         => 'portable',
		'default_translation_status'     => 'portable',
		'auto_create_stubs'              => 'portable',
		'abilities_enabled'              => 'portable',
		'abilities_write_enabled'        => 'portable',
		'sync_fields'                    => 'portable',
		'sync_term_hierarchy'            => 'portable',
		'translate_slugs'                => 'portable',
		'mt_enabled'                     => 'portable',
		'mt_provider'                    => 'portable',
		'mt_deepl_api_key'               => 'secret',
		'mt_deepl_formality'             => 'portable',
		'mt_google_api_key'              => 'secret',
		'mt_microsoft_api_key'           => 'secret',
		'mt_microsoft_region'            => 'portable',
		'mt_libre_url'                   => 'site_specific',
		'mt_libre_api_key'               => 'secret',
		'mt_agency_url'                  => 'site_specific',
		'mt_agency_api_key'              => 'secret',
		'mt_auto_translate_on_publish'   => 'portable',
		'mt_auto_translate_languages'    => 'portable',
		'mt_auto_translate_on_create'    => 'portable',
		'mt_monthly_char_limit'          => 'portable',
		'mt_bulk_strings_enabled'        => 'portable',
		'mt_enforce_cap_on_bulk'         => 'portable',
		'mt_meta_seo'                    => 'portable',
		'mt_meta_custom_fields'          => 'portable',
		'seo_hreflang_enabled'           => 'portable',
		'seo_hreflang_placement'         => 'portable',
		'seo_x_default'                  => 'portable',
		'seo_hreflang_include_fallbacks' => 'portable',
		'seo_sitemap_enabled'            => 'portable',
		'seo_sitemap_source'             => 'portable',
		'seo_plugin'                     => 'portable',
		'seo_og_locale'                  => 'portable',
		'switcher_template'              => 'portable',
		'switcher_menu_locations'        => 'portable',
		'switcher_display'               => 'portable',
		'switcher_layout'                => 'portable',
		'switcher_name_format'           => 'portable',
		'switcher_class'                 => 'portable',
		'switcher_flag_style'            => 'portable',
		'switcher_show_untranslated'     => 'portable',
		'switcher_hide_current'          => 'portable',
		'switcher_untranslated_link'     => 'portable',
		'switcher_arrow_style'           => 'portable',
		'switcher_trigger_format'        => 'portable',
		'admin_bar_switcher'             => 'portable',
		'switcher_auto_insert'           => 'portable',
		'wc_email_translation'           => 'portable',
		'wc_sync_stock'                  => 'portable',
		'wc_sync_prices'                 => 'portable',
		'wc_currency_per_lang'           => 'portable',
		'wc_currencies'                  => 'portable',
		'wc_exchange_rate_auto'          => 'portable',
		'wc_exchange_rate_provider'      => 'portable',
		'wc_exchange_rate_interval'      => 'portable',
		'delete_data_on_uninstall'       => 'site_specific',
		'cache_object_enabled'           => 'portable',
		'cache_preload_slugs'            => 'portable',
		'string_translation_mode'        => 'portable',
		'background_processing'          => 'portable',
		'background_engine'              => 'portable',
		'background_thresholds'          => 'portable',
		'background_paused'              => 'site_specific',
		'edge_integration_enabled'       => 'portable',
		'cdn_cache_tags_enabled'         => 'portable',
		'seo_schema_enrichment_enabled'  => 'portable',
		'content_language_header'        => 'portable',
		'fallback_nosnippet'             => 'portable',
		'prerender_on_hover'             => 'portable',
		'view_transitions_enabled'       => 'portable',
		'dashboard_widget_enabled'       => 'portable',
		'schema_version'                 => 'state',
	];

	/**
	 * Every stored item, entry id => entry. See the class docblock for the
	 * entry shape.
	 */
	public const ENTRIES = [
		'table:languages'                                  => [
			'kind'         => 'table',
			'name'         => 'languages',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'languages',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'natural_key',
				'replace' => 'wipe_and_insert',
				'ids'     => 'fk_map',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F1',
			'since'        => '1.0.0',
			'note'         => 'Merge never sets a second default language (I-1).',
		],
		'table:translation_groups'                         => [
			'kind'         => 'table',
			'name'         => 'translation_groups',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'translations',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'natural_key',
				'replace' => 'wipe_and_insert',
				'ids'     => 'fk_map',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F4',
			'since'        => '1.0.0',
			'note'         => 'Also carried by the strings section, scoped to type string.',
		],
		'table:translation_links'                          => [
			'kind'         => 'table',
			'name'         => 'translation_links',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'translations',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'natural_key',
				'replace' => 'wipe_and_insert',
				'ids'     => 'same_site',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F4',
			'since'        => '1.0.0',
		],
		'table:strings'                                    => [
			'kind'         => 'table',
			'name'         => 'strings',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'strings',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'natural_key',
				'replace' => 'wipe_and_insert',
				'ids'     => 'fk_map',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
		],
		'table:string_translations'                        => [
			'kind'         => 'table',
			'name'         => 'string_translations',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'strings',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'natural_key',
				'replace' => 'wipe_and_insert',
				'ids'     => 'fk_map',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
		],
		'table:slug_translations'                          => [
			'kind'         => 'table',
			'name'         => 'slug_translations',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'slugs',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'natural_key',
				'replace' => 'wipe_and_insert',
				'ids'     => 'same_site',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F4',
			'since'        => '1.0.0',
		],
		'table:content_hashes'                             => [
			'kind'         => 'table',
			'name'         => 'content_hashes',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => 'hashes',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'natural_key',
				'replace' => 'wipe_and_insert',
				'ids'     => 'same_site',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F4',
			'since'        => '1.0.0',
		],
		'table:jobs'                                       => [
			'kind'         => 'table',
			'name'         => 'jobs',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F12',
			'since'        => '1.0.0',
		],
		'table:migration_source_map'                       => [
			'kind'         => 'table',
			'name'         => 'migration_source_map',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F12m',
			'since'        => '1.0.0',
			'note'         => 'A Replace of translations must cascade to it (I-9).',
		],
		'option:perflocale_settings'                       => [
			'kind'         => 'option',
			'name'         => 'perflocale_settings',
			'owner'        => 'core',
			'class'        => 'CONFIG',
			'export'       => 'settings',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'apply_config',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'last',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F2',
			'since'        => '1.0.0',
			'note'         => 'Secrets removed on export, kept from the target on import; per-key classes in SETTINGS.',
		],
		'option:perflocale_addon_settings'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_addon_settings',
			'owner'        => 'core',
			'class'        => 'CONFIG',
			'export'       => 'settings',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'apply_config',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F3',
			'since'        => '1.0.0',
			'note'         => 'Password fields removed on export, kept from the target on import.',
		],
		'option:perflocale_disabled_addons'                => [
			'kind'         => 'option',
			'name'         => 'perflocale_disabled_addons',
			'owner'        => 'core',
			'class'        => 'CONFIG',
			'export'       => 'settings',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'apply_config',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F3',
			'since'        => '1.0.0',
		],
		'option:perflocale_caps_version'                   => [
			'kind'         => 'option',
			'name'         => 'perflocale_caps_version',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => 'roles',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'apply_config',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
			'note'         => 'Deleted on deactivation with the Translator role (TranslatorRole::remove_roles()); activation installs both again.',
		],
		'option:perflocale_translator_caps'                => [
			'kind'         => 'option',
			'name'         => 'perflocale_translator_caps',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => 'roles',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'overwrite',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
		],
		'option:perflocale_shop_manager_caps'              => [
			'kind'         => 'option',
			'name'         => 'perflocale_shop_manager_caps',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => 'roles',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'overwrite',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F4',
			'since'        => '1.0.0',
		],
		'option:perflocale_slug_redirects'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_slug_redirects',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'languages',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'union_keys',
				'replace' => 'overwrite',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F1',
			'since'        => '1.0.0',
		],
		'option:perflocale_webhooks'                       => [
			'kind'         => 'option',
			'name'         => 'perflocale_webhooks',
			'owner'        => 'core',
			'class'        => 'CONFIG',
			'export'       => 'settings',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'apply_config',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F11',
			'since'        => '1.0.0',
			'note'         => 'Each secret removed on export; the target keeps its own secret for the same url.',
		],
		'option:perflocale_gf_translations_{id}'           => [
			'kind'         => 'option',
			'name'         => 'perflocale_gf_translations_{id}',
			'owner'        => 'addon:gravity-forms',
			'class'        => 'USER',
			'export'       => 'addon_data',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'gf_form',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F15',
			'since'        => '1.0.0',
		],
		'option:perflocale_webhook_queue'                  => [
			'kind'         => 'option',
			'name'         => 'perflocale_webhook_queue',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WP-Cron engine only: deliveries are coalesced here only when the background engine is not Action Scheduler',
			'since'        => '1.0.0',
		],
		'option:perflocale_webhook_failures'               => [
			'kind'         => 'option',
			'name'         => 'perflocale_webhook_failures',
			'owner'        => 'core',
			'class'        => 'DIAG',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F11',
			'since'        => '1.0.0',
		],
		'option:perflocale_exchange_rates'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_exchange_rates',
			'owner'        => 'wc',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
			'note'         => 'Re-synced from the provider; manual rates travel in the settings.',
		],
		'option:perflocale_exchange_rates_last_sync'       => [
			'kind'         => 'option',
			'name'         => 'perflocale_exchange_rates_last_sync',
			'owner'        => 'wc',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
		],
		'option:perflocale_exchange_rate_last_error'       => [
			'kind'         => 'option',
			'name'         => 'perflocale_exchange_rate_last_error',
			'owner'        => 'wc',
			'class'        => 'DIAG',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
		],
		'option:perflocale_migration_state'                => [
			'kind'         => 'option',
			'name'         => 'perflocale_migration_state',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F12m',
			'since'        => '1.0.0',
		],
		'option:perflocale_trp_import_post_checkpoint'     => [
			'kind'         => 'option',
			'name'         => 'perflocale_trp_import_post_checkpoint',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:needs TranslatePress source data',
			'since'        => '1.0.0',
		],
		'option:perflocale_trp_import_post_checkpoint_fp'  => [
			'kind'         => 'option',
			'name'         => 'perflocale_trp_import_post_checkpoint_fp',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:needs TranslatePress source data',
			'since'        => '1.0.0',
		],
		'option:perflocale_template_refs_backfill'         => [
			'kind'         => 'option',
			'name'         => 'perflocale_template_refs_backfill',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F12b',
			'since'        => '1.0.7.3',
		],
		'option:perflocale_sqlite_string_groups_repaired'  => [
			'kind'         => 'option',
			'name'         => 'perflocale_sqlite_string_groups_repaired',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:SQLite only, produced in the SQLite lane',
			'since'        => '1.0.7.3',
			'note'         => 'Deleted on a full-data uninstall from 1.0.7.3 (F-1).',
		],
		'option:perflocale_sqlite_string_groups_repair'    => [
			'kind'         => 'option',
			'name'         => 'perflocale_sqlite_string_groups_repair',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:SQLite only, produced in the SQLite lane',
			'since'        => '1.0.7.3',
			'note'         => 'Phase, cursor and counts of an unfinished SQLite string-group repair; deleted when the repair finishes. Kept on deactivation so the repair resumes.',
		],
		'option:perflocale_sqlite_string_groups_repair_job' => [
			'kind'         => 'option',
			'name'         => 'perflocale_sqlite_string_groups_repair_job',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:SQLite only, produced in the SQLite lane',
			'since'        => '1.0.7.3',
			'note'         => 'Dispatch state of the SQLite string-group repair job (claim, queued job id, failed, canceled, done); done once the repair finishes. After reactivation a job that is gone is queued again from an admin view within 10 minutes.',
		],
		'option:perflocale_strings_last_full_scan'         => [
			'kind'         => 'option',
			'name'         => 'perflocale_strings_last_full_scan',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
		],
		'option:perflocale_gc_protected_domains'           => [
			'kind'         => 'option',
			'name'         => 'perflocale_gc_protected_domains',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
		],
		'option:perflocale_l10n_manifest'                  => [
			'kind'         => 'option',
			'name'         => 'perflocale_l10n_manifest',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
		],
		'option:perflocale_has_any_groups'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_has_any_groups',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F18',
			'since'        => '1.0.0',
		],
		'option:perflocale_has_any_slugs'                  => [
			'kind'         => 'option',
			'name'         => 'perflocale_has_any_slugs',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F18',
			'since'        => '1.0.0',
		],
		'option:perflocale_rewrites_verified'              => [
			'kind'         => 'option',
			'name'         => 'perflocale_rewrites_verified',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F18',
			'since'        => '1.0.0',
		],
		'option:perflocale_tables_exist'                   => [
			'kind'         => 'option',
			'name'         => 'perflocale_tables_exist',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F18',
			'since'        => '1.0.0',
		],
		'option:perflocale_db_version'                     => [
			'kind'         => 'option',
			'name'         => 'perflocale_db_version',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
		],
		'option:perflocale_version'                        => [
			'kind'         => 'option',
			'name'         => 'perflocale_version',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
		],
		'option:perflocale_flush_rules'                    => [
			'kind'         => 'option',
			'name'         => 'perflocale_flush_rules',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
			'note'         => 'Deleted on deactivation (Deactivator); set again when a rewrite flush is due.',
		],
		'option:perflocale_active_engine'                  => [
			'kind'         => 'option',
			'name'         => 'perflocale_active_engine',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
		],
		'option:perflocale_recurring_last_run'             => [
			'kind'         => 'option',
			'name'         => 'perflocale_recurring_last_run',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
		],
		'option:perflocale_schedules_verified_at'          => [
			'kind'         => 'option',
			'name'         => 'perflocale_schedules_verified_at',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
		],
		'option:perflocale_addon_schema_versions'          => [
			'kind'         => 'option',
			'name'         => 'perflocale_addon_schema_versions',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F3',
			'since'        => '1.0.0',
		],
		'option:perflocale_addon_failures'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_addon_failures',
			'owner'        => 'core',
			'class'        => 'DIAG',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:only after an add-on fails to boot',
			'since'        => '1.0.0',
		],
		'option:perflocale_addon_migration_errors'         => [
			'kind'         => 'option',
			'name'         => 'perflocale_addon_migration_errors',
			'owner'        => 'core',
			'class'        => 'DIAG',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:only after an add-on migration fails',
			'since'        => '1.0.0',
		],
		'option:perflocale_breakers_index'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_breakers_index',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F11',
			'since'        => '1.0.0',
		],
		'option:perflocale_cgen__changes'                  => [
			'kind'         => 'option',
			'name'         => 'perflocale_cgen__changes',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F18',
			'since'        => '1.0.7.3',
			'note'         => 'Change counter of the cache generations.',
		],
		'option:perflocale_cgen_{group}'                   => [
			'kind'         => 'option',
			'name'         => 'perflocale_cgen_{group}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F18',
			'since'        => '1.0.0',
			'note'         => 'Written by raw SQL as well as the option API (F-6).',
		],
		'option:perflocale_eager_links_{type}'             => [
			'kind'         => 'option',
			'name'         => 'perflocale_eager_links_{type}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F18',
			'since'        => '1.0.0',
			'note'         => 'Deleted on deactivation by the cache flush (CacheManager::flush_all()); rebuilt when next read.',
		],
		'option:perflocale_addon_manifest_{addon}'         => [
			'kind'         => 'option',
			'name'         => 'perflocale_addon_manifest_{addon}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:no bundled add-on implements HasUninstallTargets',
			'since'        => '1.0.0',
			'note'         => 'Read by the uninstall before the option sweep (purge_addons).',
		],
		'option:perflocale_mt_usage_{ym}'                  => [
			'kind'         => 'option',
			'name'         => 'perflocale_mt_usage_{ym}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F10',
			'since'        => '1.0.0',
			'note'         => 'Monthly counter; a restore that starts the month at 0 is harmless.',
		],
		'option:perflocale_job_lock_{token}'               => [
			'kind'         => 'option',
			'name'         => 'perflocale_job_lock_{token}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F10',
			'since'        => '1.0.0',
		],
		'option:perflocale_type_lock_{type}'               => [
			'kind'         => 'option',
			'name'         => 'perflocale_type_lock_{type}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F10',
			'since'        => '1.0.0',
		],
		'option:perflocale_link_lock_{id}'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_link_lock_{id}',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy, only deleted',
			'since'        => '1.0.0',
			'note'         => 'Written by earlier builds; link locks are perflocale_lock_{token} rows now. Only deleted.',
		],
		'option:perflocale_lock_{token}'                   => [
			'kind'         => 'option',
			'name'         => 'perflocale_lock_{token}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F4',
			'since'        => '1.0.0',
		],
		'option:perflocale_import_uploads'                 => [
			'kind'         => 'option',
			'name'         => 'perflocale_import_uploads',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:held only while the import screen sends a file in pieces',
			'since'        => '1.0.7.3',
			'note'         => 'Uploads of import files in progress (ImportUpload quota): token => user, announced bytes, expiry. Dropped when the upload is imported, cancelled, fails, is cleaned up or expires.',
		],
		'option:perflocale_breaker_n_{token}'              => [
			'kind'         => 'option',
			'name'         => 'perflocale_breaker_n_{token}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F11',
			'since'        => '1.0.0',
		],
		'option:perflocale_active_jobs'                    => [
			'kind'         => 'option',
			'name'         => 'perflocale_active_jobs',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy, only deleted',
			'since'        => '1.0.0',
			'note'         => 'Written by earlier builds; only deleted.',
		],
		'option:perflocale_currencies'                     => [
			'kind'         => 'option',
			'name'         => 'perflocale_currencies',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy, only deleted',
			'since'        => '1.0.0',
			'note'         => 'Written by earlier builds; only deleted.',
		],
		'option:perflocale_bulk_string_translate_threshold' => [
			'kind'         => 'option',
			'name'         => 'perflocale_bulk_string_translate_threshold',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy, only deleted',
			'since'        => '1.0.0',
			'note'         => 'Written by earlier builds; only deleted.',
		],
		'option:perflocale_settings_autoload_migrated'     => [
			'kind'         => 'option',
			'name'         => 'perflocale_settings_autoload_migrated',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy, only deleted',
			'since'        => '1.0.0',
			'note'         => 'Written by earlier builds; only deleted.',
		],
		'option:perflocale_str_{token}'                    => [
			'kind'         => 'option',
			'name'         => 'perflocale_str_{token}',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy, only deleted',
			'since'        => '1.0.0',
			'note'         => 'Written by earlier builds; only deleted.',
		],
		'option:widget_perflocale_switcher'                => [
			'kind'         => 'option',
			'name'         => 'widget_perflocale_switcher',
			'owner'        => 'wp-core',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:written by WordPress when a site places the widget',
			'since'        => '1.0.0',
			'note'         => 'The Language Switcher widget instances (WordPress stores them under widget_<id_base>).',
		],
		'option:page_on_front'                             => [
			'kind'         => 'option',
			'name'         => 'page_on_front',
			'owner'        => 'wp-core',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WordPress owns it',
			'since'        => '1.0.0',
			'note'         => 'Written when the translated front page is set.',
		],
		'option:page_for_posts'                            => [
			'kind'         => 'option',
			'name'         => 'page_for_posts',
			'owner'        => 'wp-core',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WordPress owns it',
			'since'        => '1.0.0',
			'note'         => 'Written when the translated posts page is set.',
		],
		'option:rewrite_rules'                             => [
			'kind'         => 'option',
			'name'         => 'rewrite_rules',
			'owner'        => 'wp-core',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'own_step',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:WordPress regenerates it',
			'since'        => '1.0.0',
			'note'         => 'Deleted by its own step of a full-data uninstall, not on a single-subsite teardown. Deleted on deactivation (Deactivator); WordPress writes it again on the next request that needs it.',
		],
		'option:oxygen_vsb_css_files_state'                => [
			'kind'         => 'option',
			'name'         => 'oxygen_vsb_css_files_state',
			'owner'        => 'addon:oxygen',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:needs Oxygen',
			'since'        => '1.0.0',
			'note'         => 'Oxygen\'s own CSS cache flag, reset by the add-on.',
		],
		'option:oxygen_vsb_access_role_perflocale_translator' => [
			'kind'         => 'option',
			'name'         => 'oxygen_vsb_access_role_perflocale_translator',
			'owner'        => 'core',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'own_step',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:needs Oxygen',
			'since'        => '1.0.0',
			'note'         => 'Oxygen\'s access level for the Translator role; Oxygen adds one such option for every editable role and never deletes it. Deleted with the role by a full-data uninstall (SiteCleanup::strip_role_and_caps()); deactivation and a preserve-mode uninstall keep it.',
		],
		'option:{prefix}user_roles'                        => [
			'kind'         => 'option',
			'name'         => '{prefix}user_roles',
			'owner'        => 'wp-core',
			'class'        => 'FOREIGN',
			'export'       => 'roles',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'never',
				'replace' => 'apply_config',
				'ids'     => 'none',
				'object'  => null,
			],
			'uninstall'    => 'own_step',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
			'note'         => 'Roles and capabilities through WP_Roles. The roles section carries only the perflocale_* caps: the Translator role\'s and those of administrator, editor, every role of perflocale/roles/cap_roles and the Shop Manager (G-8); strip_role_and_caps() removes them on uninstall.',
		],
		'network_option:perflocale_bootable_gen'           => [
			'kind'         => 'network_option',
			'name'         => 'perflocale_bootable_gen',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F16',
			'since'        => '1.0.0',
		],
		'network_option:perflocale_uninstall_resume'       => [
			'kind'         => 'network_option',
			'name'         => 'perflocale_uninstall_resume',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:only during an interrupted network uninstall',
			'since'        => '1.0.0',
			'note'         => 'Deleted only by the pass that completes the network sweep.',
		],
		'transient:perflocale_{group}_{key}'               => [
			'kind'         => 'transient',
			'name'         => 'perflocale_{group}_{key}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F18',
			'since'        => '1.0.0',
			'note'         => 'Persistent cache rows (CacheManager::derive_transient_key: the group, then the cache key, which may hold colons: a found-rows key is <kind>:<generation>:<md5>); found-rows rows are written by raw SQL on visitor requests.',
		],
		'transient:perflocale_{md5}'                       => [
			'kind'         => 'transient',
			'name'         => 'perflocale_{md5}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:written only without a persistent object cache, for a cache key longer than 160 characters; no fixture step makes one',
			'since'        => '1.0.0',
			'note'         => 'Hashed form of a cache key longer than 160 characters.',
		],
		'transient:perflocale_site_health_counts'          => [
			'kind'         => 'transient',
			'name'         => 'perflocale_site_health_counts',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F13',
			'since'        => '1.0.0',
		],
		'transient:perflocale_site_health_hreflang'        => [
			'kind'         => 'transient',
			'name'         => 'perflocale_site_health_hreflang',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:needs the Site Health loopback request, which a lab without outbound HTTP blocks',
			'since'        => '1.0.0',
		],
		'transient:perflocale_site_health_stuck'           => [
			'kind'         => 'transient',
			'name'         => 'perflocale_site_health_stuck',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F13',
			'since'        => '1.0.0',
		],
		'transient:perflocale_site_health_orphans'         => [
			'kind'         => 'transient',
			'name'         => 'perflocale_site_health_orphans',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F13',
			'since'        => '1.0.0',
		],
		'transient:perflocale_site_health_mt_scoring'      => [
			'kind'         => 'transient',
			'name'         => 'perflocale_site_health_mt_scoring',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy: no release writes it; 1.0.7.3 only deletes it (SiteHealth)',
			'since'        => '1.0.0',
		],
		'transient:perflocale_site_health_files'           => [
			'kind'         => 'transient',
			'name'         => 'perflocale_site_health_files',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F13',
			'since'        => '1.0.0',
		],
		'transient:perflocale_exports_exposed'             => [
			'kind'         => 'transient',
			'name'         => 'perflocale_exports_exposed',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F13',
			'since'        => '1.0.0',
		],
		'transient:perflocale_mt_wp_ai_ready'              => [
			'kind'         => 'transient',
			'name'         => 'perflocale_mt_wp_ai_ready',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WordPress AI client provider only: its readiness check result',
			'since'        => '1.0.0',
		],
		'transient:perflocale_mt_rl_{user}'                => [
			'kind'         => 'transient',
			'name'         => 'perflocale_mt_rl_{user}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F10',
			'since'        => '1.0.0',
		],
		'transient:perflocale_mt_rl_site'                  => [
			'kind'         => 'transient',
			'name'         => 'perflocale_mt_rl_site',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F10',
			'since'        => '1.0.0',
		],
		'transient:perflocale_dns_{md5}'                   => [
			'kind'         => 'transient',
			'name'         => 'perflocale_dns_{md5}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:needs an IPv4 DNS answer for the checked host; the IPv6 answer is perflocale_dns6_{md5}',
			'since'        => '1.0.0',
		],
		'transient:perflocale_dns6_{md5}'                  => [
			'kind'         => 'transient',
			'name'         => 'perflocale_dns6_{md5}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F10',
			'since'        => '1.0.0',
		],
		'transient:perflocale_sh_mt_dns_{md5}'             => [
			'kind'         => 'transient',
			'name'         => 'perflocale_sh_mt_dns_{md5}',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F13',
			'since'        => '1.0.0',
		],
		'transient:perflocale_bootable_addons'             => [
			'kind'         => 'transient',
			'name'         => 'perflocale_bootable_addons',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy: 1.0.0 cached the bootable add-on list here; 1.0.7.3 only deletes it (AddonRegistry)',
			'since'        => '1.0.0',
		],
		'transient:perflocale_breaker_{token}'             => [
			'kind'         => 'transient',
			'name'         => 'perflocale_breaker_{token}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F11',
			'since'        => '1.0.0',
		],
		'transient:perflocale_bulk_mt_error_{user}'        => [
			'kind'         => 'transient',
			'name'         => 'perflocale_bulk_mt_error_{user}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:failure path: written only when a bulk machine translation dispatch fails',
			'since'        => '1.0.0',
		],
		'transient:perflocale_bulk_mt_warning_{user}'      => [
			'kind'         => 'transient',
			'name'         => 'perflocale_bulk_mt_warning_{user}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:failure path: written only when a bulk machine translation item reports a warning',
			'since'        => '1.0.0',
		],
		'transient:perflocale_scan_queued_notice_{user}'   => [
			'kind'         => 'transient',
			'name'         => 'perflocale_scan_queued_notice_{user}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:background job only: the notice of a string scan queued as a job',
			'since'        => '1.0.0',
		],
		'transient:perflocale_scan_result_{user}'          => [
			'kind'         => 'transient',
			'name'         => 'perflocale_scan_result_{user}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
		],
		'transient:perflocale_po_import_result_{user}'     => [
			'kind'         => 'transient',
			'name'         => 'perflocale_po_import_result_{user}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
		],
		'transient:perflocale_lang_save_error_{user}'      => [
			'kind'         => 'transient',
			'name'         => 'perflocale_lang_save_error_{user}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:failure path: written only when the post or term language box cannot set the language',
			'since'        => '1.0.0',
		],
		'transient:perflocale_strings_regenerating'        => [
			'kind'         => 'transient',
			'name'         => 'perflocale_strings_regenerating',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:written only while the string storage is switched to files and the files are regenerated',
			'since'        => '1.0.0',
		],
		'transient:perflocale_string_blob_max'             => [
			'kind'         => 'transient',
			'name'         => 'perflocale_string_blob_max',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:database string mode only: the largest string blob seen while strings are stored in the database',
			'since'        => '1.0.0',
		],
		'transient:perflocale_mt_create_notice_{user}_{id}' => [
			'kind'         => 'transient',
			'name'         => 'perflocale_mt_create_notice_{user}_{id}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:written only when the machine translation guard refuses a Create',
			'since'        => '1.0.7.3',
			'note'         => 'Why a new translation was created without machine translation.',
		],
		'transient:perflocale_mt_publisher_{id}'           => [
			'kind'         => 'transient',
			'name'         => 'perflocale_mt_publisher_{id}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:written by a publish with auto-translate, taken by its job within a day',
			'since'        => '1.0.7.3',
			'note'         => 'The user who published a post, for the auto-translate job that runs later without a user (MtCreateNotice).',
		],
		'transient:perflocale_import_notice_{user}_{token}' => [
			'kind'         => 'transient',
			'name'         => 'perflocale_import_notice_{user}_{token}',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:written by an Export & Import form redirect, taken by the tab it lands on within ten minutes',
			'since'        => '1.0.7.3',
			'note'         => 'The outcome notice of an Export & Import form, for the tab its redirect lands on (ImportNotice).',
		],
		'transient:perflocale_vsig_{id}'                   => [
			'kind'         => 'transient',
			'name'         => 'perflocale_vsig_{id}',
			'owner'        => 'wc',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WooCommerce variable product with local attributes created through /wc/v3/products (no lane driver yet)',
			'since'        => '1.0.0',
		],
		'transient:perflocale_vsig_epoch'                  => [
			'kind'         => 'transient',
			'name'         => 'perflocale_vsig_epoch',
			'owner'        => 'wc',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WooCommerce variable product with local attributes created through /wc/v3/products (no lane driver yet)',
			'since'        => '1.0.0',
		],
		'transient:perflocale_vcopy_{id}'                  => [
			'kind'         => 'transient',
			'name'         => 'perflocale_vcopy_{id}',
			'owner'        => 'wc',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WooCommerce variable product with local attributes created through /wc/v3/products (no lane driver yet)',
			'since'        => '1.0.0',
		],
		'transient:perflocale_vcopy_epoch'                 => [
			'kind'         => 'transient',
			'name'         => 'perflocale_vcopy_epoch',
			'owner'        => 'wc',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WooCommerce variable product with local attributes created through /wc/v3/products (no lane driver yet)',
			'since'        => '1.0.0',
		],
		'transient:perflocale_{token}'                     => [
			'kind'         => 'transient',
			'name'         => 'perflocale_{token}',
			'owner'        => 'core',
			'class'        => 'NAMESPACE',
			'export'       => null,
			'not_exported' => 'namespace',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:namespace sweep',
			'since'        => '1.0.0',
			'note'         => 'Uninstall sweep of every perflocale_ transient (value and timeout rows).',
		],
		'site_transient:perflocale_{token}'                => [
			'kind'         => 'site_transient',
			'name'         => 'perflocale_{token}',
			'owner'        => 'core',
			'class'        => 'NAMESPACE',
			'export'       => null,
			'not_exported' => 'namespace',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:namespace sweep',
			'since'        => '1.0.0',
			'note'         => 'Uninstall sweep of every perflocale_ site transient (value and timeout rows).',
		],
		'post_meta:_perflocale_alt_{lang}'                 => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_alt_{lang}',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'media',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'attachment',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F5',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_caption_{lang}'             => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_caption_{lang}',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'media',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'attachment',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F5',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_description_{lang}'         => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_description_{lang}',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'media',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'attachment',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F5',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_thumbnail_{lang}'           => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_thumbnail_{lang}',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'media',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'translatable_post',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F5',
			'since'        => '1.0.0',
			'note'         => 'The value is an attachment id.',
		],
		'post_meta:_perflocale_sync_optout'                => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_sync_optout',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'item_flags',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'translatable_post',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F8',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_seo_exclude'                => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_seo_exclude',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'item_flags',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'translatable_post',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'if_synced',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'F8',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_ref_fallback'               => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_ref_fallback',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F6b',
			'since'        => '1.0.7.3',
		],
		'post_meta:_perflocale_ref_rewritten'              => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_ref_rewritten',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F6b',
			'since'        => '1.0.7.3',
		],
		'post_meta:_perflocale_seed_cleared'               => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_seed_cleared',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:a person empties a seeded field on a translation through a host form (Meta Box) (no lane driver yet)',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_sync_stale'                 => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_sync_stale',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F8b',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_sync_held'                  => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_sync_held',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'F8b',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_local_attr_source'          => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_local_attr_source',
			'owner'        => 'wc',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:WooCommerce variable product with local attributes created through /wc/v3/products (no lane driver yet)',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_wc_page_mt_attempt'         => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_wc_page_mt_attempt',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'site_specific',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:the Create WooCommerce pages button with machine translation on (no lane driver yet)',
			'since'        => '1.0.0',
			'note'         => 'Not copied onto a new translation from 1.0.7.3 (D4).',
		],
		'post_meta:_perflocale_meta_copy_errors'           => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_meta_copy_errors',
			'owner'        => 'core',
			'class'        => 'DIAG',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:failure path: written only when copying a meta value onto a new translation fails',
			'since'        => '1.0.0',
		],
		'post_meta:_perflocale_meta_mt_errors'             => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_meta_mt_errors',
			'owner'        => 'core',
			'class'        => 'DIAG',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:failure path: written only when machine translation of a meta field fails',
			'since'        => '1.0.0',
			'note'         => 'Not copied onto a new translation from 1.0.7.3 (D4).',
		],
		'post_meta:_perflocale_{token}'                    => [
			'kind'         => 'post_meta',
			'name'         => '_perflocale_{token}',
			'owner'        => 'core',
			'class'        => 'NAMESPACE',
			'export'       => null,
			'not_exported' => 'namespace',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'never',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:namespace sweep',
			'since'        => '1.0.0',
			'note'         => 'Uninstall sweep of every _perflocale_ post meta key; Sync Fields never mirrors a key of this namespace that no entry lists as if_listed.',
		],
		'order_meta:_perflocale_language'                  => [
			'kind'         => 'order_meta',
			'name'         => '_perflocale_language',
			'owner'        => 'wc',
			'class'        => 'USER',
			'export'       => 'item_flags',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'shop_order',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F9',
			'since'        => '1.0.0',
			'note'         => 'wc_orders_meta under HPOS, postmeta of shop_order posts otherwise.',
		],
		'order_meta:_perflocale_{token}'                   => [
			'kind'         => 'order_meta',
			'name'         => '_perflocale_{token}',
			'owner'        => 'core',
			'class'        => 'NAMESPACE',
			'export'       => null,
			'not_exported' => 'namespace',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:namespace sweep',
			'since'        => '1.0.0',
			'note'         => 'Uninstall sweep of every _perflocale_ key in wc_orders_meta.',
		],
		'term_meta:_perflocale_language'                   => [
			'kind'         => 'term_meta',
			'name'         => '_perflocale_language',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'menus',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'nav_menu',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F6',
			'since'        => '1.0.0',
			'note'         => 'A classic menu\'s language slug.',
		],
		'term_meta:_perflocale_menu_{lang}'                => [
			'kind'         => 'term_meta',
			'name'         => '_perflocale_menu_{lang}',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => 'menus',
			'not_exported' => null,
			'import'       => [
				'merge'   => 'insert_if_absent',
				'replace' => 'overwrite',
				'ids'     => 'same_site',
				'object'  => 'nav_menu',
			],
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F6',
			'since'        => '1.0.0',
			'note'         => 'The term id of the menu linked in that language.',
		],
		'term_meta:_perflocale_{token}'                    => [
			'kind'         => 'term_meta',
			'name'         => '_perflocale_{token}',
			'owner'        => 'core',
			'class'        => 'NAMESPACE',
			'export'       => null,
			'not_exported' => 'namespace',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:namespace sweep',
			'since'        => '1.0.0',
			'note'         => 'Uninstall sweep of every _perflocale_ term meta key.',
		],
		'user_meta:perflocale_strings_hidden_langs'        => [
			'kind'         => 'user_meta',
			'name'         => 'perflocale_strings_hidden_langs',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => null,
			'not_exported' => 'per_user',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network_final',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
		],
		'user_meta:perflocale_translations_hidden_langs'   => [
			'kind'         => 'user_meta',
			'name'         => 'perflocale_translations_hidden_langs',
			'owner'        => 'core',
			'class'        => 'USER',
			'export'       => null,
			'not_exported' => 'per_user',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network_final',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
		],
		'user_meta:perflocale_strings_per_page'            => [
			'kind'         => 'user_meta',
			'name'         => 'perflocale_strings_per_page',
			'owner'        => 'wp-core',
			'class'        => 'USER',
			'export'       => null,
			'not_exported' => 'per_user',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network_final',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
			'note'         => 'Written by core\'s set-screen-option.',
		],
		'user_meta:perflocale_translations_per_page'       => [
			'kind'         => 'user_meta',
			'name'         => 'perflocale_translations_per_page',
			'owner'        => 'wp-core',
			'class'        => 'USER',
			'export'       => null,
			'not_exported' => 'per_user',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network_final',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
			'note'         => 'Written by core\'s set-screen-option.',
		],
		'user_meta:perflocale_languages_per_page'          => [
			'kind'         => 'user_meta',
			'name'         => 'perflocale_languages_per_page',
			'owner'        => 'wp-core',
			'class'        => 'USER',
			'export'       => null,
			'not_exported' => 'per_user',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network_final',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
			'note'         => 'Written by core\'s set-screen-option.',
		],
		'transient:perflocale_rewrites_verified'           => [
			'kind'         => 'transient',
			'name'         => 'perflocale_rewrites_verified',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:legacy, only deleted',
			'since'        => '1.0.0',
			'note'         => 'The rewrite check flag in its transient form from earlier builds (the flag is the option perflocale_rewrites_verified now); deleted with it.',
		],
		'post_meta:_stock'                                 => [
			'kind'         => 'post_meta',
			'name'         => '_stock',
			'owner'        => 'wc',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:meta of another plugin',
			'since'        => '1.0.0',
			'note'         => 'The WooCommerce stock quantity; Inventory Sync writes it on every member of a product translation group.',
		],
		'post_meta:_stock_status'                          => [
			'kind'         => 'post_meta',
			'name'         => '_stock_status',
			'owner'        => 'wc',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:meta of another plugin',
			'since'        => '1.0.0',
			'note'         => 'The WooCommerce stock status; Inventory Sync writes it with the stock.',
		],
		'post_meta:_elementor_css'                         => [
			'kind'         => 'post_meta',
			'name'         => '_elementor_css',
			'owner'        => 'addon:elementor',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:meta of another plugin',
			'since'        => '1.0.0',
			'note'         => 'The Elementor generated-CSS record; never copied to a new translation (the Elementor add-on excludes it through perflocale/translation/excluded_meta_keys), so Elementor rebuilds it.',
		],
		'post_meta:_elementor_page_assets'                 => [
			'kind'         => 'post_meta',
			'name'         => '_elementor_page_assets',
			'owner'        => 'addon:elementor',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:meta of another plugin',
			'since'        => '1.0.0',
			'note'         => 'The Elementor page asset cache; deleted with the CSS record.',
		],
		'post_meta:_elementor_element_cache'               => [
			'kind'         => 'post_meta',
			'name'         => '_elementor_element_cache',
			'owner'        => 'addon:elementor',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'no',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:meta of another plugin',
			'since'        => '1.0.0',
			'note'         => 'The Elementor element cache; deleted with the CSS record.',
		],
		'post_meta:{prefix}css_file_paths_cache'           => [
			'kind'         => 'post_meta',
			'name'         => '{prefix}css_file_paths_cache',
			'owner'        => 'addon:oxygen6',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:meta of another plugin',
			'since'        => '1.0.0',
			'note'         => 'The Oxygen 6 / Breakdance CSS path cache (_oxygen_ or _breakdance_); the add-on deletes it so the builder rebuilds it.',
		],
		'post_meta:{prefix}dependency_cache'               => [
			'kind'         => 'post_meta',
			'name'         => '{prefix}dependency_cache',
			'owner'        => 'addon:oxygen6',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'keep',
			'scope'        => 'blog',
			'copy'         => 'yes',
			'sync'         => 'if_listed',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:meta of another plugin',
			'since'        => '1.0.0',
			'note'         => 'The Oxygen 6 / Breakdance dependency cache; deleted with the CSS path cache.',
		],
		'user_meta:{prefix}capabilities'                   => [
			'kind'         => 'user_meta',
			'name'         => '{prefix}capabilities',
			'owner'        => 'wp-core',
			'class'        => 'FOREIGN',
			'export'       => null,
			'not_exported' => 'foreign',
			'import'       => null,
			'uninstall'    => 'own_step',
			'scope'        => 'network_final',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F14',
			'since'        => '1.0.0',
			'note'         => 'Per-user role and caps; strip_role_and_caps() removes the perflocale_* ones.',
		],
		'user_meta:perflocale_{token}'                     => [
			'kind'         => 'user_meta',
			'name'         => 'perflocale_{token}',
			'owner'        => 'core',
			'class'        => 'NAMESPACE',
			'export'       => null,
			'not_exported' => 'namespace',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'network_final',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:namespace sweep',
			'since'        => '1.0.0',
			'note'         => 'Uninstall sweep of every perflocale_ user meta key, once after the network walk.',
		],
		'file:perflocale/translations/{token}.l10n.php'    => [
			'kind'         => 'file',
			'name'         => 'perflocale/translations/{token}.l10n.php',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F7',
			'since'        => '1.0.0',
			'note'         => 'Regenerated from string_translations (files mode); includes the _combined-<locale> files.',
		],
		'file:perflocale/temp/import-{token}.json'         => [
			'kind'         => 'file',
			'name'         => 'perflocale/temp/import-{token}.json',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F12',
			'since'        => '1.0.0',
		],
		'file:perflocale/exports/perflocale-export-{token}.json' => [
			'kind'         => 'file',
			'name'         => 'perflocale/exports/perflocale-export-{token}.json',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:written by the export job and deleted once downloaded, so it is gone before discovery',
			'since'        => '1.0.0',
		],
		'file:perflocale/index.php'                        => [
			'kind'         => 'file',
			'name'         => 'perflocale/index.php',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
			'note'         => 'Guard file of the uploads folder, written on every activation and by the translation file generator when missing (Helper::harden_directory()); a full-data uninstall removes the whole folder.',
		],
		'file:perflocale/.htaccess'                        => [
			'kind'         => 'file',
			'name'         => 'perflocale/.htaccess',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
			'note'         => 'Guard file of the uploads folder, written on every activation and by the translation file generator when missing (Helper::harden_directory()); a full-data uninstall removes the whole folder.',
		],
		'file:perflocale/{type}/index.php'                 => [
			'kind'         => 'file',
			'name'         => 'perflocale/{type}/index.php',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F12',
			'since'        => '1.0.0',
			'note'         => 'Guard file, written again when missing.',
		],
		'file:perflocale/{type}/.htaccess'                 => [
			'kind'         => 'file',
			'name'         => 'perflocale/{type}/.htaccess',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'F12',
			'since'        => '1.0.0',
			'note'         => 'Guard file, written again when missing.',
		],
		'file:perflocale/{type}/web.config'                => [
			'kind'         => 'file',
			'name'         => 'perflocale/{type}/web.config',
			'owner'        => 'core',
			'class'        => 'DERIVED',
			'export'       => null,
			'not_exported' => 'derived',
			'import'       => null,
			'uninstall'    => 'data',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'n/a',
			'fixture'      => 'none:never written: Helper::harden_directory() writes .htaccess and index.php, no web.config',
			'since'        => '1.0.0',
			'note'         => 'Guard file, written again when missing.',
		],
		'cron:perflocale_exchange_rate_sync'               => [
			'kind'         => 'cron',
			'name'         => 'perflocale_exchange_rate_sync',
			'owner'        => 'wc',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
		],
		'cron:perflocale_lock_cleanup'                     => [
			'kind'         => 'cron',
			'name'         => 'perflocale_lock_cleanup',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
		],
		'cron:perflocale_auto_translate_post'              => [
			'kind'         => 'cron',
			'name'         => 'perflocale_auto_translate_post',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:auto-translate on publish only: scheduled when a published post is machine-translated automatically',
			'since'        => '1.0.0',
		],
		'cron:perflocale_regenerate_translation_files'     => [
			'kind'         => 'cron',
			'name'         => 'perflocale_regenerate_translation_files',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:files string mode only, after a plugin update (Bootstrap::queue_files_regeneration_after_update())',
			'since'        => '1.0.0',
		],
		'cron:perflocale_deliver_webhook'                  => [
			'kind'         => 'cron',
			'name'         => 'perflocale_deliver_webhook',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F11',
			'since'        => '1.0.0',
		],
		'cron:perflocale_retry_webhook'                    => [
			'kind'         => 'cron',
			'name'         => 'perflocale_retry_webhook',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F11',
			'since'        => '1.0.0',
		],
		'cron:perflocale_drain_webhooks'                   => [
			'kind'         => 'cron',
			'name'         => 'perflocale_drain_webhooks',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:WP-Cron engine only: scheduled only when the background engine is not Action Scheduler',
			'since'        => '1.0.0',
		],
		'cron:perflocale_cleanup_temp_import'              => [
			'kind'         => 'cron',
			'name'         => 'perflocale_cleanup_temp_import',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F12',
			'since'        => '1.0.0',
		],
		'cron:perflocale_jobs_gc'                          => [
			'kind'         => 'cron',
			'name'         => 'perflocale_jobs_gc',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
		],
		'cron:perflocale_jobs_watchdog'                    => [
			'kind'         => 'cron',
			'name'         => 'perflocale_jobs_watchdog',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
		],
		'cron:perflocale_mt_usage_gc'                      => [
			'kind'         => 'cron',
			'name'         => 'perflocale_mt_usage_gc',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F17',
			'since'        => '1.0.0',
		],
		'cron:perflocale_tm_gc'                            => [
			'kind'         => 'cron',
			'name'         => 'perflocale_tm_gc',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:legacy, only cleared',
			'since'        => '1.0.0',
			'note'         => 'Scheduled by earlier builds; cleared by name.',
		],
		'cron:perflocale_mt_quality_score'                 => [
			'kind'         => 'cron',
			'name'         => 'perflocale_mt_quality_score',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:legacy, only cleared',
			'since'        => '1.0.0',
			'note'         => 'Scheduled by earlier builds; cleared by name.',
		],
		'cron:perflocale_glossary_import'                  => [
			'kind'         => 'cron',
			'name'         => 'perflocale_glossary_import',
			'owner'        => 'core',
			'class'        => 'LEGACY',
			'export'       => null,
			'not_exported' => 'legacy',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:legacy, only cleared',
			'since'        => '1.0.0',
			'note'         => 'Scheduled by earlier builds; cleared by name.',
		],
		'cron:perflocale_job_run_data_import'              => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_data_import',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F12',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_data_export'              => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_data_export',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F12',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_wpml_migration'           => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_wpml_migration',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:needs WPML source data',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_polylang_migration'       => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_polylang_migration',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F12m',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_translatepress_migration' => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_translatepress_migration',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:needs TranslatePress source data',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_string_scan'              => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_string_scan',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:background job only: scheduled when a string scan runs as a job (above the Auto threshold or Always)',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_bulk_translate'           => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_bulk_translate',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:background job only: scheduled when a bulk translation runs as a job (above the Auto threshold or Always)',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_bulk_string_translate'    => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_bulk_string_translate',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:background job only: scheduled when a bulk string translation runs as a job (above the Auto threshold or Always)',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_site_translate'           => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_site_translate',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:background job only: scheduled when a whole-site translation runs as a job',
			'since'        => '1.0.0',
		],
		'cron:perflocale_resume_jobs'                      => [
			'kind'         => 'cron',
			'name'         => 'perflocale_resume_jobs',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F0',
			'since'        => '1.0.0',
		],
		'cron:perflocale_job_run_template_refs_backfill'   => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_template_refs_backfill',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'F12b',
			'since'        => '1.0.7.3',
			'note'         => 'Cleared on deactivation like every job hook (F-7); after reactivation the job is run again by the resume handler, or dispatched again on the next admin view when its job is gone.',
		],
		'cron:perflocale_job_run_template_refs_fanout'     => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_template_refs_fanout',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:background job only: scheduled when a template change reaches more posts than one request handles',
			'since'        => '1.0.7.3',
			'note'         => 'Cleared on deactivation like every job hook; after reactivation the job is run again by the resume handler. The containers it had not reached keep naming what they named (the Site Editor notice shows them).',
		],
		'cron:perflocale_job_run_sqlite_group_repair'      => [
			'kind'         => 'cron',
			'name'         => 'perflocale_job_run_sqlite_group_repair',
			'owner'        => 'core',
			'class'        => 'STATE',
			'export'       => null,
			'not_exported' => 'transient_state',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'clear',
			'fixture'      => 'none:SQLite only, produced in the SQLite lane',
			'since'        => '1.0.7.3',
			'note'         => 'Cleared on deactivation like every job hook; after reactivation the job is run again by the resume handler, or queued again on the next admin view when its job is gone.',
		],
		'cron:perflocale_{token}'                          => [
			'kind'         => 'cron',
			'name'         => 'perflocale_{token}',
			'owner'        => 'core',
			'class'        => 'NAMESPACE',
			'export'       => null,
			'not_exported' => 'namespace',
			'import'       => null,
			'uninstall'    => 'always',
			'scope'        => 'blog',
			'copy'         => 'n/a',
			'sync'         => 'n/a',
			'deactivate'   => 'keep',
			'fixture'      => 'none:namespace sweep',
			'since'        => '1.0.0',
			'note'         => 'Uninstall clears every perflocale_ hook in WP-Cron and the perflocale Action Scheduler group.',
		],
	];

	/**
	 * Per-request results of the query methods, one store per result type:
	 * every entry (`entries`), the entries of a kind (`kind`, kind =>
	 * entries), entry-name regular expressions (`regex`, name => regex),
	 * the export sections (`sections`, '0' or '1' => sections), the import
	 * order (`tables`) and the uninstall LIKE patterns (`likes`, mode =>
	 * patterns).
	 *
	 * @var array{entries?: array<string, array<string, mixed>>, kind?: array<string, array<string, array<string, mixed>>>, regex?: array<string, string>, sections?: array<string, array<string, array{label: string, tables: list<string>, type_scope?: array<string, string>}>>, tables?: list<string>, likes?: array<string, list<string>>, parts?: array<string, array{owner: string, group: string|null, label: string|null, entries: array<string, array<string, mixed>>}>}
	 */
	private static array $memo = [];

	/**
	 * Accepted extension entries, null until first read.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $extensions = null;

	/**
	 * Extension entries that were refused, entry id => reasons.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $rejected = [];

	/* ------------------------------------------------------------ entries */

	/**
	 * Every entry: the core entries, then the accepted extension entries.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function entries(): array {
		if ( ! isset( self::$memo['entries'] ) ) {
			self::$memo['entries'] = self::ENTRIES + self::extension_entries();
		}

		return self::$memo['entries'];
	}

	/**
	 * One entry by id, or null.
	 *
	 * @param string $id Entry id (`<kind>:<name>`).
	 * @return array<string, mixed>|null
	 */
	public static function entry( string $id ): ?array {
		return self::entries()[ $id ] ?? null;
	}

	/**
	 * The entries of one kind, in declaration order.
	 *
	 * @param string $kind A KINDS value.
	 * @return array<string, array<string, mixed>>
	 */
	public static function entries_of_kind( string $kind ): array {
		if ( ! isset( self::$memo['kind'][ $kind ] ) ) {
			self::$memo['kind'][ $kind ] = array_filter(
				self::entries(),
				static function ( array $e ) use ( $kind ): bool {
					return $e['kind'] === $kind;
				}
			);
		}

		return self::$memo['kind'][ $kind ];
	}

	/**
	 * Entries an add-on or extension declares, after validation.
	 *
	 * Read once per request, on the first call after `init`-time hooks are in
	 * place (the registry is read only on admin, cron and CLI paths). Without
	 * WordPress loaded (the release gate's static check, a bare PHP process)
	 * there are none.
	 *
	 * An extension entry may only ADD: it is refused when its id is a core
	 * id, when its name overlaps a core entry of the same kind, when its kind
	 * is not in EXTENSION_KINDS, when it would export into a section that is
	 * not live and extensible, when a SECRET entry names an export section,
	 * when an exported entry's name looks like a credential (unless
	 * perflocale/data_registry/is_secret declares it not secret) or is a
	 * WordPress core option or meta key (WP_CORE_OPTIONS, WP_CORE_META), or
	 * when any field fails {@see self::validate_entry()}. A refused entry
	 * is left out and listed by {@see self::rejected_extensions()}; it never
	 * changes how core data is exported, imported, copied, synced or
	 * deleted.
	 *
	 * Uninstall runs with the plugin's files only (uninstall.php loads no
	 * add-on), so it never sees extension entries: an add-on that needs its
	 * data removed on uninstall also declares it through HasUninstallTargets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function extension_entries(): array {
		if ( null !== self::$extensions ) {
			return self::$extensions;
		}

		self::$extensions = [];
		self::$rejected   = [];

		if ( ! function_exists( 'apply_filters' ) ) {
			return self::$extensions;
		}

		/**
		 * Filter the stored data an add-on, Pro or another extension declares
		 * to the data registry, so the export, import, translation-copy and
		 * Sync Fields rules treat it as declared.
		 *
		 * Each entry is keyed `<kind>:<name>` and has every key of a core
		 * entry (see the DataRegistry class docblock): kind (option,
		 * network_option, transient, site_transient, post_meta, term_meta,
		 * user_meta, order_meta or cron), name, owner (`addon:<id>`, `pro` or
		 * `ext:<slug>`), class, export, not_exported, import, uninstall (data or
		 * keep, always for cron), scope, copy, sync, deactivate, fixture and
		 * since. Entries are only added: one that collides with or overlaps a
		 * PerfLocale entry, or fails validation, is ignored.
		 *
		 * Sync 'never' for keys outside the _perflocale_ namespace is enforced
		 * when Settings are saved; to keep such a key out of a sync started
		 * another way, also remove it in the perflocale/sync_fields filter.
		 *
		 * An entry that is exported (only into the Add-on data section) must
		 * also declare `sanitize_import`: a callable the importer passes each
		 * imported value through before it stores it,
		 * `fn( mixed $value, string $name ): mixed` (the value as the file
		 * holds it, the stored name). An exporting entry without one, or with
		 * one that is not callable, is ignored and listed with that reason.
		 * So is an exporting entry whose name is a WordPress core option or
		 * meta key, or looks like a credential (see
		 * perflocale/data_registry/is_secret).
		 *
		 * By default every add-on's exported data is chosen with the one
		 * "Add-on data" checkbox of the export and import screens. An entry
		 * exported into Add-on data may instead declare `group`,
		 * `[ 'id' => 'my-addon', 'label' => __( 'My add-on', 'my-addon' ) ]`:
		 * the entries of one owner and group id then get their own checkbox
		 * with that label and leave the shared one. The id is 1 to 40
		 * lowercase letters, digits, - or _; every entry of a group gives the
		 * same label.
		 *
		 * @hook perflocale/data_registry/entries
		 * @param array<string, array<string, mixed>> $entries Entry id => entry. Default: [].
		 */
		$raw        = apply_filters( 'perflocale/data_registry/entries', [] );
		$not_secret = [];

		foreach ( self::credential_named_exports( $raw ) as $id => $name ) {
			/**
			 * Filters whether an extension entry exported into Add-on data
			 * holds a secret, for an entry whose name looks like a credential
			 * (the names the export redaction strips: `_api_key`, `_secret`,
			 * `_password`, `_token`, a name ending in `_key`, ...). Such an
			 * entry is refused, so its value never travels in an export file.
			 * Return false only for a name that holds no secret (for example
			 * a `_token_count` counter); the entry is then exported and
			 * imported like any other.
			 *
			 * @hook  perflocale/data_registry/is_secret
			 * @since 1.0.7.3
			 *
			 * @param bool   $is_secret Default true.
			 * @param string $name      The entry's name (an option or meta key, or a name pattern).
			 * @param string $id        The entry id (`<kind>:<name>`).
			 * @return bool
			 */
			if ( false === apply_filters( 'perflocale/data_registry/is_secret', true, $name, $id ) ) {
				$not_secret[] = $id;
			}
		}

		$sorted = self::sort_extensions( $raw, $not_secret );

		self::$extensions = $sorted['accepted'];
		self::$rejected   = $sorted['rejected'];

		return self::$extensions;
	}

	/**
	 * The entries of a filter result that export and whose names look like a
	 * credential: entry id => name.
	 *
	 * @param mixed $raw What the filter returned.
	 * @return array<string, string>
	 */
	private static function credential_named_exports( $raw ): array {
		$out = [];

		foreach ( is_array( $raw ) ? $raw : [] as $id => $entry ) {
			if ( is_array( $entry ) && null !== ( $entry['export'] ?? null ) && is_string( $entry['name'] ?? null ) && 1 === preg_match( self::CREDENTIAL_NAME_PATTERN, $entry['name'] ) ) {
				$out[ (string) $id ] = $entry['name'];
			}
		}

		return $out;
	}

	/**
	 * Split a filter result into accepted and refused extension entries.
	 * Holds no state: {@see self::extension_entries()} stores the result.
	 *
	 * @param mixed        $raw        What the filter returned.
	 * @param list<string> $not_secret Entry ids whose credential-shaped names
	 *                                 perflocale/data_registry/is_secret
	 *                                 declared not secret.
	 * @return array{accepted: array<string, array<string, mixed>>, rejected: array<string, list<string>>}
	 */
	public static function sort_extensions( $raw, array $not_secret = [] ): array {
		$out = [
			'accepted' => [],
			'rejected' => [],
		];

		if ( ! is_array( $raw ) ) {
			$out['rejected']['(filter)'] = [ 'the filter returned ' . gettype( $raw ) . ', not an array' ];

			return $out;
		}

		$labels = [];

		foreach ( $raw as $id => $entry ) {
			$id     = (string) $id;
			$fields = is_array( $entry ) ? self::string_keyed( $entry ) : null;

			if ( null === $fields ) {
				$reasons = [ 'the entry is not an array' ];
			} elseif ( count( $fields ) !== count( (array) $entry ) ) {
				$reasons = [ 'every field name must be a string' ];
			} else {
				$reasons = self::extension_problems( $id, $fields, in_array( $id, $not_secret, true ) );
			}

			// The entries of one group share one label: the first accepted
			// entry sets it.
			$part = null !== $fields && [] === $reasons ? self::part_key( $fields ) : null;

			if ( null !== $part && isset( $fields['group'] ) && is_array( $fields['group'] ) ) {
				$label = self::text( $fields['group']['label'] ?? null );

				if ( isset( $labels[ $part ] ) && $labels[ $part ] !== $label ) {
					$reasons[] = 'group ' . $part . ' already has the label "' . $labels[ $part ] . '"';
				} else {
					$labels[ $part ] = $label;
				}
			}

			if ( null !== $fields && [] === $reasons ) {
				$out['accepted'][ $id ] = $fields;
			} else {
				$out['rejected'][ $id ] = $reasons;
			}
		}

		return $out;
	}

	/**
	 * Extension entries that were refused, entry id => reasons.
	 *
	 * @return array<string, list<string>>
	 */
	public static function rejected_extensions(): array {
		self::extension_entries();

		return self::$rejected;
	}

	/**
	 * Forget every memoised result and the extension entries.
	 *
	 * @internal For tests and for a caller that registered extension entries
	 *     after the first read.
	 * @return void
	 */
	public static function reset(): void {
		self::$memo       = [];
		self::$extensions = null;
		self::$rejected   = [];
	}

	/**
	 * Why an extension entry is refused (empty when it is accepted).
	 *
	 * @param string               $id         Entry id.
	 * @param array<string, mixed> $entry      Entry.
	 * @param bool                 $not_secret Its credential-shaped name was
	 *                                         declared not secret.
	 * @return list<string>
	 */
	private static function extension_problems( string $id, array $entry, bool $not_secret = false ): array {
		if ( isset( self::ENTRIES[ $id ] ) ) {
			return [ 'the id is a PerfLocale entry' ];
		}

		$problems = self::validate_entry( $id, $entry );

		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $entry ) ) {
				return $problems;
			}
		}

		if ( ! in_array( $entry['kind'], self::KINDS, true ) || ! is_string( $entry['name'] ) ) {
			return $problems;
		}

		if ( ! in_array( $entry['kind'], self::EXTENSION_KINDS, true ) ) {
			$problems[] = 'kind ' . $entry['kind'] . ' is not open to extensions';
		}

		if ( 1 !== preg_match( '/^(?:pro|addon:[a-z0-9-]+|ext:[a-z0-9_-]+)$/', self::text( $entry['owner'] ) ) ) {
			$problems[] = 'owner must be pro, addon:<id> or ext:<slug>';
		}

		if ( in_array( $entry['class'], [ 'NAMESPACE', 'FOREIGN', 'LEGACY' ], true ) ) {
			$problems[] = 'class ' . $entry['class'] . ' is not open to extensions';
		}

		$allowed_uninstall = 'cron' === $entry['kind'] ? [ 'always', 'keep' ] : [ 'data', 'keep' ];

		if ( ! in_array( $entry['uninstall'], $allowed_uninstall, true ) ) {
			$problems[] = 'uninstall ' . self::text( $entry['uninstall'] ) . ' is not open to extensions';
		}

		$core_post_meta = array_filter(
			self::ENTRIES,
			static function ( array $e ): bool {
				return 'post_meta' === $e['kind'];
			}
		);

		if ( 'post_meta' === $entry['kind'] && 'never' !== $entry['sync'] && self::never_synced_by( $core_post_meta, self::text( $entry['name'] ) ) ) {
			$problems[] = 'a key of the plugin\'s own meta namespace is never synced (sync must be never)';
		}

		if ( null !== $entry['export'] ) {
			$section = self::section_flags()[ self::text( $entry['export'] ) ] ?? null;

			if ( null === $section || ! $section['live'] || ! $section['extensible'] ) {
				$problems[] = 'section ' . self::text( $entry['export'] ) . ' does not take extension entries';
			}

			if ( ! array_key_exists( 'sanitize_import', $entry ) ) {
				$problems[] = 'an exported extension entry needs sanitize_import, the callable the importer passes each value through';
			}

			if ( ! $not_secret && 1 === preg_match( self::CREDENTIAL_NAME_PATTERN, self::text( $entry['name'] ) ) ) {
				$problems[] = 'the name looks like a credential, and a secret is never exported (not_exported secret; or perflocale/data_registry/is_secret declares the name not secret)';
			}

			if ( self::is_wp_core_name( self::text( $entry['kind'] ), self::text( $entry['name'] ) ) ) {
				$problems[] = 'the name is a WordPress core ' . ( str_ends_with( self::text( $entry['kind'] ), 'option' ) ? 'option' : 'meta key' ) . ', which an extension never exports';
			}
		}

		foreach ( self::ENTRIES as $core_id => $core ) {
			if ( $core['kind'] !== $entry['kind'] || 'NAMESPACE' === $core['class'] ) {
				continue;
			}

			if ( self::patterns_overlap( self::text( $core['name'] ), self::text( $entry['name'] ) ) ) {
				$problems[] = 'the name overlaps ' . $core_id;
			}
		}

		return $problems;
	}

	/**
	 * Whether a name can stand for a WordPress core option (kinds option and
	 * network_option) or a core meta key (the meta kinds), compared without
	 * regard to case, as the database compares option names and meta keys.
	 *
	 * @param string $kind Entry kind.
	 * @param string $name Entry name.
	 * @return bool
	 */
	private static function is_wp_core_name( string $kind, string $name ): bool {
		if ( str_ends_with( $kind, 'option' ) ) {
			$core = self::WP_CORE_OPTIONS;
		} elseif ( str_ends_with( $kind, '_meta' ) ) {
			$core = self::WP_CORE_META;
		} else {
			return false;
		}

		// The site's own table prefix when WordPress is loaded, and the
		// default one.
		$db       = $GLOBALS['wpdb'] ?? null;
		$prefix   = is_object( $db ) ? ( get_object_vars( $db )['base_prefix'] ?? null ) : null;
		$prefixes = array_unique( [ 'wp_', is_string( $prefix ) && '' !== $prefix ? $prefix : 'wp_' ] );

		foreach ( $core as $core_name ) {
			foreach ( $prefixes as $p ) {
				if ( self::patterns_overlap( strtolower( str_replace( '{wpdb}', $p, $core_name ) ), strtolower( $name ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Problems of one entry's shape and values (empty when it is valid).
	 * Shared by the extension filter and the release gate's rule E3.
	 *
	 * @param string               $id    Entry id.
	 * @param array<string, mixed> $entry Entry.
	 * @return list<string>
	 */
	public static function validate_entry( string $id, array $entry ): array {
		$p = [];

		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $entry ) ) {
				$p[] = "missing key $key";
			}
		}

		foreach ( array_keys( $entry ) as $key ) {
			if ( ! in_array( $key, self::REQUIRED_KEYS, true ) && ! in_array( $key, self::OPTIONAL_KEYS, true ) ) {
				$p[] = "unknown key $key";
			}
		}

		if ( [] !== $p ) {
			return $p;
		}

		$kind = $entry['kind'];
		$name = $entry['name'];

		if ( ! is_string( $kind ) || ! in_array( $kind, self::KINDS, true ) ) {
			return [ 'unknown kind ' . ( is_string( $kind ) ? $kind : gettype( $kind ) ) ];
		}

		if ( ! is_string( $name ) || '' === $name ) {
			return [ 'name must be a non-empty string' ];
		}

		if ( $id !== $kind . ':' . $name ) {
			$p[] = "id must be $kind:$name";
		}

		if ( preg_match_all( '/\{[a-z0-9]+\}/', $name, $m ) ) {
			foreach ( $m[0] as $ph ) {
				if ( ! isset( self::PLACEHOLDERS[ $ph ] ) ) {
					$p[] = "unknown placeholder $ph";
				}
			}
		}

		if ( ! is_string( $entry['owner'] ) || 1 !== preg_match( '/^(?:core|wc|wp-core|pro|addon:[a-z0-9-]+|ext:[a-z0-9_-]+)$/', $entry['owner'] ) ) {
			$p[] = 'unknown owner';
		}

		$class = $entry['class'];

		if ( ! in_array( $class, self::CLASSES, true ) ) {
			$p[] = 'unknown class';
		}

		$export  = $entry['export'];
		$not_exported = $entry['not_exported'];

		if ( null !== $export && ! isset( self::EXPORT_SECTIONS[ self::text( $export ) ] ) ) {
			$p[] = 'export names no section';
		}

		if ( null !== $not_exported && ! in_array( $not_exported, self::NOT_EXPORTED, true ) ) {
			$p[] = 'unknown not_exported reason';
		}

		if ( ( null === $export ) === ( null === $not_exported ) ) {
			$p[] = 'exactly one of export and not_exported must be set';
		}

		if ( 'SECRET' === $class && null !== $export ) {
			$p[] = 'a SECRET entry is never exported';
		}

		if ( ( 'NAMESPACE' === $class ) !== ( 'namespace' === $not_exported ) ) {
			$p[] = 'class NAMESPACE and not_exported namespace go together';
		}

		$import = $entry['import'];

		if ( null === $export && null !== $import ) {
			$p[] = 'import must be null when the entry is not exported';
		}

		if ( null !== $export ) {
			$p = array_merge( $p, self::import_problems( $import, self::text( $export ) ) );
		}

		if ( ! in_array( $entry['uninstall'], self::UNINSTALL, true ) ) {
			$p[] = 'unknown uninstall value';
		} elseif ( 'last' === $entry['uninstall'] && 'option' !== $kind ) {
			$p[] = 'uninstall last is for options only';
		} elseif ( 'always' === $entry['uninstall'] && 'cron' !== $kind ) {
			$p[] = 'uninstall always is for cron hooks only';
		}

		$want_scope = 'network_option' === $kind ? [ 'network' ] : ( 'user_meta' === $kind ? [ 'network_final' ] : [ 'blog' ] );

		if ( ! in_array( $entry['scope'], $want_scope, true ) ) {
			$p[] = 'scope must be ' . $want_scope[0] . " for kind $kind";
		}

		if ( 'post_meta' === $kind ) {
			if ( ! in_array( $entry['copy'], self::COPY, true ) ) {
				$p[] = 'post_meta needs copy yes, no or if_synced';
			}

			if ( ! in_array( $entry['sync'], self::SYNC, true ) ) {
				$p[] = 'post_meta needs sync never or if_listed';
			}
		} else {
			if ( 'n/a' !== $entry['copy'] ) {
				$p[] = 'copy is n/a for kind ' . $kind;
			}

			if ( 'n/a' !== $entry['sync'] ) {
				$p[] = 'sync is n/a for kind ' . $kind;
			}
		}

		if ( 'cron' === $kind ) {
			if ( ! in_array( $entry['deactivate'], self::DEACTIVATE, true ) ) {
				$p[] = 'cron needs deactivate clear or keep';
			}
		} elseif ( 'option' === $kind ) {
			if ( ! in_array( $entry['deactivate'], [ 'clear', 'n/a' ], true ) ) {
				$p[] = 'option needs deactivate clear or n/a';
			}
		} elseif ( 'n/a' !== $entry['deactivate'] ) {
			$p[] = 'deactivate is n/a for kind ' . $kind;
		}

		if ( ! is_string( $entry['fixture'] ) || 1 !== preg_match( '/^(?:F[0-9]+[a-z]?|none:\S.*)$/', $entry['fixture'] ) ) {
			$p[] = 'fixture must be a step id (F<n>) or none:<reason>';
		}

		if ( ! is_string( $entry['since'] ) || 1 !== preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9x]+)*$/', $entry['since'] ) ) {
			$p[] = 'since must be a version';
		}

		if ( array_key_exists( 'note', $entry ) && ! is_string( $entry['note'] ) ) {
			$p[] = 'note must be a string';
		}

		if ( array_key_exists( 'sanitize_import', $entry ) && ! is_callable( $entry['sanitize_import'] ) ) {
			$p[] = 'sanitize_import must be callable';
		}

		if ( array_key_exists( 'group', $entry ) ) {
			$p = array_merge( $p, self::group_problems( $entry['group'], $export, $entry['owner'] ) );
		}

		if ( array_key_exists( 'gap', $entry ) && ( ! is_string( $entry['gap'] ) || 1 !== preg_match( '/^G-[0-9]+$/', $entry['gap'] ) || null === $export ) ) {
			$p[] = 'gap must be G-<n> on an exported entry';
		}

		if ( 'table' === $kind && ! isset( self::TABLES[ $name ] ) ) {
			$p[] = 'a table entry needs a TABLES row';
		}

		return $p;
	}

	/**
	 * Problems of an entry's group.
	 *
	 * @param mixed $group  The entry's group value.
	 * @param mixed $export The entry's export section.
	 * @param mixed $owner  The entry's owner.
	 * @return list<string>
	 */
	private static function group_problems( $group, $export, $owner ): array {
		if ( ! is_array( $group ) || [ 'id', 'label' ] !== array_keys( $group ) ) {
			return [ 'group needs exactly id, label' ];
		}

		$p = [];

		if ( ! is_string( $group['id'] ) || 1 !== preg_match( self::GROUP_ID_PATTERN, $group['id'] ) ) {
			$p[] = 'group id must be 1 to 40 lowercase letters, digits, - or _, starting with a letter or digit';
		}

		if ( ! is_string( $group['label'] ) || '' === trim( $group['label'] ) || strlen( $group['label'] ) > self::GROUP_LABEL_MAX_BYTES ) {
			$p[] = 'group label must be a non-empty string of at most ' . self::GROUP_LABEL_MAX_BYTES . ' bytes';
		}

		if ( self::ADDON_SECTION !== $export ) {
			$p[] = 'a group is only for an entry exported into ' . self::ADDON_SECTION;
		}

		if ( ! is_string( $owner ) || 1 !== preg_match( '/^(?:pro|addon:[a-z0-9-]+|ext:[a-z0-9_-]+)$/', $owner ) ) {
			$p[] = 'a group is only for an add-on or extension entry (owner pro, addon:<id> or ext:<slug>)';
		}

		return $p;
	}

	/**
	 * Problems of an exported entry's import rules.
	 *
	 * @param mixed  $import  The entry's import value.
	 * @param string $section The entry's export section.
	 * @return list<string>
	 */
	private static function import_problems( $import, string $section ): array {
		if ( ! is_array( $import ) ) {
			return [ 'an exported entry needs import rules' ];
		}

		$p = [];

		if ( array_keys( $import ) !== [ 'merge', 'replace', 'ids', 'object' ] ) {
			return [ 'import needs exactly merge, replace, ids, object' ];
		}

		if ( ! in_array( $import['merge'], self::IMPORT_MERGE, true ) ) {
			$p[] = 'unknown import merge';
		}

		if ( ! in_array( $import['replace'], self::IMPORT_REPLACE, true ) ) {
			$p[] = 'unknown import replace';
		}

		if ( ! in_array( $import['ids'], self::IMPORT_IDS, true ) ) {
			$p[] = 'unknown import ids';
		}

		if ( null !== $import['object'] && ! in_array( $import['object'], self::IMPORT_OBJECTS, true ) ) {
			$p[] = 'unknown import object';
		}

		if ( ! empty( self::EXPORT_SECTIONS[ $section ]['id_keyed'] ) && 'none' === $import['ids'] ) {
			$p[] = "section $section holds ids: import ids cannot be none";
		}

		return $p;
	}

	/* ------------------------------------------------------------ patterns */

	/**
	 * Whether a name holds a placeholder.
	 *
	 * @param string $pattern Entry name.
	 * @return bool
	 */
	public static function has_placeholder( string $pattern ): bool {
		return 1 === preg_match( '/\{[a-z0-9]+\}/', $pattern );
	}

	/**
	 * EXPORT_SECTIONS as its declared shape: the flags are booleans a
	 * section may set either way (a planned section is not live), whatever
	 * the current list holds.
	 *
	 * @return array<string, array{label: string, live: bool, id_keyed: bool, extensible: bool}>
	 */
	private static function section_flags(): array {
		return self::EXPORT_SECTIONS;
	}

	/**
	 * A string field of an entry: the value when it is a string, '' otherwise
	 * (every field the query methods read as text is a string in an entry
	 * that passed validate_entry()).
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * An array's members under string keys (an entry's fields).
	 *
	 * @param array<array-key, mixed> $value Array.
	 * @return array<string, mixed>
	 */
	private static function string_keyed( array $value ): array {
		$out = [];

		foreach ( $value as $key => $member ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $member;
			}
		}

		return $out;
	}

	/**
	 * Anchored regular expression for an entry name.
	 *
	 * @param string $pattern Entry name.
	 * @return string
	 */
	public static function to_regex( string $pattern ): string {
		if ( ! isset( self::$memo['regex'][ $pattern ] ) ) {
			$parts = preg_split( '/(\{[a-z0-9]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE );
			$re    = '';

			foreach ( (array) $parts as $part ) {
				$re .= self::PLACEHOLDERS[ $part ] ?? preg_quote( (string) $part, '/' );
			}

			self::$memo['regex'][ $pattern ] = '/^' . $re . '$/';
		}

		return self::$memo['regex'][ $pattern ];
	}

	/**
	 * The literal text of a name up to its first placeholder (the whole
	 * name when it has none). Unescaped: a SQL caller applies esc_like().
	 *
	 * @param string $pattern Entry name.
	 * @return string
	 */
	public static function to_like_prefix( string $pattern ): string {
		$at = strpos( $pattern, '{' );

		return false === $at ? $pattern : substr( $pattern, 0, $at );
	}

	/**
	 * Names that stand for a pattern: two expansions with the fixed samples
	 * of PLACEHOLDER_SAMPLES (the name itself when it has no placeholder).
	 *
	 * @param string $pattern Entry name.
	 * @return list<string>
	 */
	public static function sample_names( string $pattern ): array {
		$out = [];

		foreach ( [ 0, 1 ] as $i ) {
			$out[] = (string) preg_replace_callback(
				'/\{[a-z0-9]+\}/',
				static function ( array $m ) use ( $i ): string {
					return self::PLACEHOLDER_SAMPLES[ $m[0] ][ $i ] ?? $m[0];
				},
				$pattern
			);
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether two entry names can stand for the same stored name.
	 *
	 * @param string $a Entry name.
	 * @param string $b Entry name.
	 * @return bool
	 */
	public static function patterns_overlap( string $a, string $b ): bool {
		foreach ( self::sample_names( $b ) as $s ) {
			if ( 1 === preg_match( self::to_regex( $a ), $s ) ) {
				return true;
			}
		}

		foreach ( self::sample_names( $a ) as $s ) {
			if ( 1 === preg_match( self::to_regex( $b ), $s ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The entry a stored name belongs to, or null. NAMESPACE entries never
	 * match: a name only they cover is an undeclared item.
	 *
	 * @param string $kind A KINDS value, or `setting` for a key of
	 *     `perflocale_settings`.
	 * @param string $name Stored name (a transient without its `_transient_`
	 *     prefix, a file relative to the uploads base directory).
	 * @return string|null Entry id.
	 */
	public static function match( string $kind, string $name ): ?string {
		if ( 'setting' === $kind ) {
			return isset( self::SETTINGS[ $name ] ) ? 'setting:' . $name : null;
		}

		foreach ( self::entries_of_kind( $kind ) as $id => $e ) {
			if ( 'NAMESPACE' !== $e['class'] && 1 === preg_match( self::to_regex( self::text( $e['name'] ) ), $name ) ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * Pairs of entries of one kind that can stand for the same name, and
	 * uninstall LIKE prefixes that reach an entry uninstall must keep. Empty
	 * on a sound registry (the release gate's rule E10).
	 *
	 * @param array<string, array<string, mixed>>|null $entries Entries to
	 *     check, default the core entries.
	 * @return list<string>
	 */
	public static function overlap_problems( ?array $entries = null ): array {
		$entries = $entries ?? self::ENTRIES;
		$p       = [];
		$ids     = array_keys( $entries );
		$n       = count( $ids );

		for ( $i = 0; $i < $n; $i++ ) {
			$a = $entries[ $ids[ $i ] ];

			if ( 'NAMESPACE' === $a['class'] ) {
				continue;
			}

			for ( $j = $i + 1; $j < $n; $j++ ) {
				$b = $entries[ $ids[ $j ] ];

				if ( $a['kind'] === $b['kind'] && 'NAMESPACE' !== $b['class'] && self::patterns_overlap( self::text( $a['name'] ), self::text( $b['name'] ) ) ) {
					$p[] = $ids[ $i ] . ' overlaps ' . $ids[ $j ];
				}
			}
		}

		foreach ( $entries as $id => $e ) {
			if ( ! in_array( $e['uninstall'], [ 'data', 'always' ], true ) || ! self::has_placeholder( self::text( $e['name'] ) ) ) {
				continue;
			}

			$prefix = self::to_like_prefix( self::text( $e['name'] ) );

			foreach ( $entries as $kid => $k ) {
				if ( 'keep' !== $k['uninstall'] || $k['kind'] !== $e['kind'] ) {
					continue;
				}

				foreach ( self::sample_names( self::text( $k['name'] ) ) as $s ) {
					if ( 0 === strpos( $s, $prefix ) ) {
						$p[] = "the uninstall sweep of $id reaches $kid, which uninstall keeps";
						break;
					}
				}
			}
		}

		return $p;
	}

	/* ------------------------------------------------------------ cache flush */

	/**
	 * The transients a cache flush keeps, as LIKE prefixes (unescaped): the
	 * literal head of every core transient entry of class STATE under
	 * FLUSH_SWEEP_PREFIX, sorted, leaving out a prefix that another one
	 * already covers. CacheManager::FLUSH_KEPT_TRANSIENTS holds this list.
	 *
	 * Core entries only: an extension's transient under FLUSH_SWEEP_PREFIX
	 * is flushed whatever its class.
	 *
	 * @param array<string, array<string, mixed>>|null $entries Entries, default
	 *     the core entries.
	 * @return list<string>
	 */
	public static function flush_kept_transient_prefixes( ?array $entries = null ): array {
		$found = [];

		foreach ( $entries ?? self::ENTRIES as $e ) {
			$prefix = self::to_like_prefix( self::text( $e['name'] ?? null ) );

			if ( 'transient' === ( $e['kind'] ?? null ) && 'STATE' === ( $e['class'] ?? null ) && 0 === strpos( $prefix, self::FLUSH_SWEEP_PREFIX ) ) {
				$found[] = $prefix;
			}
		}

		$found = array_values( array_unique( $found ) );
		$out   = [];

		foreach ( $found as $prefix ) {
			foreach ( $found as $other ) {
				if ( $other !== $prefix && 0 === strpos( $prefix, $other ) ) {
					continue 2;
				}
			}

			$out[] = $prefix;
		}

		sort( $out );

		return $out;
	}

	/**
	 * The transients a cache flush deletes by name under a persistent object
	 * cache: every core transient entry under FLUSH_SWEEP_PREFIX whose class
	 * FLUSH_TRANSIENT_CLASSES deletes and whose name has no placeholder,
	 * sorted. CacheManager::FLUSH_DELETED_TRANSIENTS holds this list.
	 *
	 * @param array<string, array<string, mixed>>|null $entries Entries, default
	 *     the core entries.
	 * @return list<string>
	 */
	public static function flush_deleted_transient_names( ?array $entries = null ): array {
		$out = [];

		foreach ( $entries ?? self::ENTRIES as $e ) {
			$name = self::text( $e['name'] ?? null );

			if ( 'transient' === ( $e['kind'] ?? null )
				&& 'delete' === ( self::FLUSH_TRANSIENT_CLASSES[ self::text( $e['class'] ?? null ) ] ?? null )
				&& 0 === strpos( $name, self::FLUSH_SWEEP_PREFIX )
				&& ! self::has_placeholder( $name )
			) {
				$out[] = $name;
			}
		}

		$out = array_values( array_unique( $out ) );
		sort( $out );

		return $out;
	}

	/**
	 * What is wrong with a list of kept flush prefixes, one line each; empty
	 * when it is sound (the release gate's rule E13):
	 *   - a transient entry of a class FLUSH_TRANSIENT_CLASSES does not name;
	 *   - a prefix the registry keeps that the list leaves out, or one the
	 *     list keeps that the registry does not;
	 *   - a kept prefix that reaches a transient a flush deletes (one of its
	 *     sample names starts with the prefix, or the prefix followed by a
	 *     sample token is one of its names).
	 *
	 * @param array<mixed>                             $kept    The list
	 *     (CacheManager::FLUSH_KEPT_TRANSIENTS).
	 * @param array<string, array<string, mixed>>|null $entries Entries, default
	 *     the core entries.
	 * @return list<string>
	 */
	public static function flush_keep_problems( array $kept, ?array $entries = null ): array {
		$entries = $entries ?? self::ENTRIES;
		$want    = self::flush_kept_transient_prefixes( $entries );
		$have    = [];
		$p       = [];

		foreach ( $kept as $prefix ) {
			if ( is_string( $prefix ) ) {
				$have[] = $prefix;
			}
		}

		foreach ( $entries as $id => $e ) {
			if ( 'transient' === ( $e['kind'] ?? null ) && ! isset( self::FLUSH_TRANSIENT_CLASSES[ self::text( $e['class'] ?? null ) ] ) ) {
				$p[] = "$id has class " . self::text( $e['class'] ?? null ) . ', for which a cache flush has no rule';
			}
		}

		foreach ( array_diff( $want, $have ) as $prefix ) {
			$p[] = "a cache flush deletes $prefix*, which the registry classes STATE: CacheManager::FLUSH_KEPT_TRANSIENTS leaves it out";
		}

		foreach ( array_diff( $have, $want ) as $prefix ) {
			$p[] = "a cache flush keeps $prefix*, which no STATE transient entry declares";
		}

		$all = array_values( array_unique( array_merge( $want, $have ) ) );

		foreach ( $entries as $id => $e ) {
			if ( 'transient' !== ( $e['kind'] ?? null ) || 'delete' !== ( self::FLUSH_TRANSIENT_CLASSES[ self::text( $e['class'] ?? null ) ] ?? null ) ) {
				continue;
			}

			$name = self::text( $e['name'] ?? null );
			$re   = self::to_regex( $name );

			foreach ( $all as $prefix ) {
				$reached = false;

				foreach ( self::sample_names( $name ) as $s ) {
					$reached = $reached || 0 === strpos( $s, $prefix );
				}

				foreach ( array_merge( [ $prefix ], self::sample_names( $prefix . '{token}' ) ) as $s ) {
					$reached = $reached || 1 === preg_match( $re, $s );
				}

				if ( $reached ) {
					$p[] = "the kept prefix $prefix* reaches $id, which a cache flush deletes";
				}
			}
		}

		return $p;
	}

	/* ------------------------------------------------------------ export sections and tables */

	/**
	 * Export sections in screen order: key => label, tables and, where a
	 * section owns only a slice of a shared table, type_scope (table =>
	 * value of its `type` column). The shape of the exporter's section list.
	 *
	 * @param bool $include_planned Also the sections whose writer is not
	 *     built yet.
	 * @return array<string, array{label: string, tables: list<string>, type_scope?: array<string, string>}>
	 */
	public static function sections( bool $include_planned = false ): array {
		$key = $include_planned ? 'all' : 'live';

		if ( isset( self::$memo['sections'][ $key ] ) ) {
			return self::$memo['sections'][ $key ];
		}

		$out = [];

		foreach ( self::section_flags() as $section => $s ) {
			if ( ! $s['live'] && ! $include_planned ) {
				continue;
			}

			$row   = [
				'label'  => $s['label'],
				'tables' => self::section_tables( $section ),
			];
			$scope = [];

			foreach ( $row['tables'] as $table ) {
				$ts = self::TABLES[ $table ]['sections'][ $section ] ?? null;

				if ( null !== $ts ) {
					$scope[ $table ] = $ts;
				}
			}

			if ( [] !== $scope ) {
				$row['type_scope'] = $scope;
			}

			$out[ $section ] = $row;
		}

		self::$memo['sections'][ $key ] = $out;

		return $out;
	}

	/**
	 * Tables a section carries, in import order.
	 *
	 * @param string $section Section key.
	 * @return list<string>
	 */
	public static function section_tables( string $section ): array {
		$out = [];

		foreach ( self::import_tables() as $table ) {
			if ( array_key_exists( $section, self::TABLES[ $table ]['sections'] ) ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * The slice of a shared table a section owns (value of the table's
	 * `type` column), or null when the section takes the whole table.
	 *
	 * @param string $section Section key.
	 * @param string $table   Table short name.
	 * @return string|null
	 */
	public static function type_scope( string $section, string $table ): ?string {
		return self::TABLES[ $table ]['sections'][ $section ] ?? null;
	}

	/**
	 * Imported tables in import order (dependencies first).
	 *
	 * @return list<string>
	 */
	public static function import_tables(): array {
		if ( ! isset( self::$memo['tables'] ) ) {
			$order = [];

			foreach ( self::TABLES as $table => $t ) {
				if ( null !== $t['import_order'] ) {
					$order[ $table ] = (int) $t['import_order'];
				}
			}

			asort( $order );
			self::$memo['tables'] = array_keys( $order );
		}

		return self::$memo['tables'];
	}

	/**
	 * Every unique key of a table, the primary key first.
	 *
	 * @param string $table Table short name.
	 * @return list<list<string>>
	 */
	public static function unique_keys( string $table ): array {
		return self::TABLES[ $table ]['unique'] ?? [];
	}

	/**
	 * Foreign-key columns: column => parent table, for one table, or for
	 * every imported table that has any (table => column => parent).
	 *
	 * @param string|null $table Table short name, or null for all.
	 * @return array<string, string>|array<string, array<string, string>>
	 * @phpstan-return ($table is null ? array<string, array<string, string>> : array<string, string>)
	 */
	public static function fk_refs( ?string $table = null ): array {
		if ( null !== $table ) {
			$out = [];

			foreach ( self::TABLES[ $table ]['columns'] ?? [] as $col => $class ) {
				if ( 0 === strpos( $class, 'fk:' ) ) {
					$out[ $col ] = substr( $class, 3 );
				}
			}

			return $out;
		}

		$all = [];

		foreach ( self::import_tables() as $t ) {
			$refs = self::fk_refs( $t );

			if ( [] !== $refs ) {
				$all[ $t ] = $refs;
			}
		}

		return $all;
	}

	/**
	 * A table's natural key columns.
	 *
	 * @param string $table Table short name.
	 * @return list<string>
	 */
	public static function natural_key( string $table ): array {
		return self::TABLES[ $table ]['natural_key'] ?? [];
	}

	/**
	 * A table's columns => class, in DDL order.
	 *
	 * @param string $table Table short name.
	 * @return array<string, string>
	 */
	public static function table_columns( string $table ): array {
		return self::TABLES[ $table ]['columns'] ?? [];
	}

	/**
	 * Imported tables with a column that holds a post, term or string id
	 * (object_ref): a bundle with rows in them is gated by the site address.
	 *
	 * @return list<string>
	 */
	public static function object_id_tables(): array {
		return self::tables_with_column_class( 'object_ref:' );
	}

	/**
	 * Imported tables, other than languages, that name a language by id.
	 *
	 * @return list<string>
	 */
	public static function language_ref_tables(): array {
		return self::tables_with_column_class( 'fk:languages' );
	}

	/**
	 * Imported tables with a column whose class starts with $prefix.
	 *
	 * @param string $prefix Column class prefix.
	 * @return list<string>
	 */
	private static function tables_with_column_class( string $prefix ): array {
		$out = [];

		foreach ( self::import_tables() as $table ) {
			foreach ( self::TABLES[ $table ]['columns'] as $class ) {
				if ( 0 === strpos( $class, $prefix ) ) {
					$out[] = $table;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Entries a section carries.
	 *
	 * @param string      $section      Section key.
	 * @param string|null $kind         Only this kind, or null for every kind.
	 * @param bool        $include_gaps Also the entries the exporter does not
	 *     carry yet (`gap`).
	 * @return array<string, array<string, mixed>>
	 */
	public static function section_entries( string $section, ?string $kind = null, bool $include_gaps = false ): array {
		$out = [];

		foreach ( self::entries() as $id => $e ) {
			if ( $e['export'] !== $section || ( null !== $kind && $e['kind'] !== $kind ) ) {
				continue;
			}

			if ( ! $include_gaps && isset( $e['gap'] ) ) {
				continue;
			}

			$out[ $id ] = $e;
		}

		return $out;
	}

	/**
	 * Where an exported entry sits in the file: its envelope key
	 * (ENVELOPE_KEYS), `data.<table>` for a table, `data.<row list>`
	 * (ROW_LISTS) for an option or meta entry. Null for an entry that is not
	 * exported, or whose kind has no place in the file.
	 *
	 * @param string $id Entry id.
	 * @return string|null
	 */
	public static function transport( string $id ): ?string {
		$e = self::entry( $id );

		if ( null === $e || null === $e['export'] ) {
			return null;
		}

		if ( isset( self::ENVELOPE_KEYS[ $id ] ) ) {
			return self::ENVELOPE_KEYS[ $id ];
		}

		if ( 'table' === $e['kind'] ) {
			return 'data.' . self::text( $e['name'] );
		}

		$kind = self::text( $e['kind'] );

		if ( ! isset( self::ROW_LISTS[ $kind ] ) ) {
			return null;
		}

		$part = self::part_key( $e );

		return 'data.' . ( null === $part ? self::ROW_LISTS[ $kind ] : self::part_member( $part, self::ROW_LISTS[ $kind ] ) );
	}

	/**
	 * The callable an imported value of an entry passes through before the
	 * importer stores it: the extension entry's `sanitize_import`, or null
	 * (core entries are sanitized by the importer's own rules).
	 *
	 * @param string $id Entry id.
	 * @return callable|null
	 */
	public static function import_sanitizer( string $id ): ?callable {
		$fn = self::entry( $id )['sanitize_import'] ?? null;

		return is_callable( $fn ) ? $fn : null;
	}

	/**
	 * The places of the file a section fills, in file order: its envelope
	 * keys, then `data.<table>` per table in import order, then
	 * `data.<row list>` in ROW_LISTS order.
	 *
	 * @param string $section Section key.
	 * @return list<string>
	 */
	public static function section_transports( string $section ): array {
		$envelope = [];

		foreach ( self::section_entries( $section ) as $id => $e ) {
			if ( isset( self::ENVELOPE_KEYS[ $id ] ) ) {
				$envelope[] = self::ENVELOPE_KEYS[ $id ];
			}
		}

		$data = array_map(
			static function ( string $table ): string {
				return 'data.' . $table;
			},
			self::section_tables( $section )
		);

		foreach ( self::section_row_lists( $section ) as $list ) {
			$data[] = 'data.' . $list;
		}

		if ( self::ADDON_SECTION === $section ) {
			foreach ( self::addon_parts() as $part => $p ) {
				if ( null === $p['group'] ) {
					$data = array_merge( $data, self::part_transports( $part ) );
				}
			}
		}

		return array_values( array_unique( array_merge( $envelope, $data ) ) );
	}

	/**
	 * The places of the file one add-on part fills:
	 * `data.addon_data/<part>/<row list>`, in ROW_LISTS order.
	 *
	 * @param string $part Part key (addon_parts()).
	 * @return list<string>
	 */
	public static function part_transports( string $part ): array {
		$out = [];

		foreach ( self::part_row_lists( $part ) as $list ) {
			$out[] = 'data.' . self::part_member( $part, $list );
		}

		return $out;
	}

	/**
	 * The shared option and meta row lists a set of sections writes inside
	 * `data`, in ROW_LISTS order (envelope-key entries and add-on parts
	 * excluded: an add-on part has its own row lists, part_row_lists()).
	 *
	 * @param string ...$sections Section keys.
	 * @return list<string>
	 */
	public static function section_row_lists( string ...$sections ): array {
		$kinds = [];

		foreach ( $sections as $section ) {
			foreach ( self::section_entries( $section ) as $id => $e ) {
				if ( ! isset( self::ENVELOPE_KEYS[ $id ] ) && isset( self::ROW_LISTS[ self::text( $e['kind'] ) ] ) && null === self::part_key( $e ) ) {
					$kinds[ self::text( $e['kind'] ) ] = true;
				}
			}
		}

		return self::lists_of_kinds( $kinds );
	}

	/**
	 * ROW_LISTS values of a set of kinds, in ROW_LISTS order.
	 *
	 * @param array<string, bool> $kinds Kind => true.
	 * @return list<string>
	 */
	private static function lists_of_kinds( array $kinds ): array {
		$out = [];

		foreach ( self::ROW_LISTS as $kind => $list ) {
			if ( isset( $kinds[ $kind ] ) ) {
				$out[] = $list;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------ add-on parts */

	/**
	 * The add-on part an entry travels in: `<owner>`, or `<owner>/<group
	 * id>` when it declares a group. Null for an entry outside the Add-on
	 * data section.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return string|null
	 */
	public static function part_key( array $entry ): ?string {
		if ( self::ADDON_SECTION !== ( $entry['export'] ?? null ) ) {
			return null;
		}

		$owner = self::text( $entry['owner'] ?? null );
		$group = is_array( $entry['group'] ?? null ) ? self::text( $entry['group']['id'] ?? null ) : '';

		return '' === $group ? $owner : $owner . '/' . $group;
	}

	/**
	 * Every add-on part, in entry order: part => owner, group id (null for
	 * the shared "Add-on data" checkbox), label (null likewise) and its
	 * entries.
	 *
	 * @return array<string, array{owner: string, group: string|null, label: string|null, entries: array<string, array<string, mixed>>}>
	 */
	public static function addon_parts(): array {
		if ( isset( self::$memo['parts'] ) ) {
			return self::$memo['parts'];
		}

		$out = [];

		foreach ( self::section_entries( self::ADDON_SECTION ) as $id => $e ) {
			$part = self::part_key( $e );

			if ( null === $part || ! isset( self::ROW_LISTS[ self::text( $e['kind'] ) ] ) ) {
				continue;
			}

			if ( ! isset( $out[ $part ] ) ) {
				$grouped      = is_array( $e['group'] ?? null );
				$out[ $part ] = [
					'owner'   => self::text( $e['owner'] ),
					'group'   => $grouped ? self::text( $e['group']['id'] ?? null ) : null,
					'label'   => $grouped ? self::text( $e['group']['label'] ?? null ) : null,
					'entries' => [],
				];
			}

			$out[ $part ]['entries'][ $id ] = $e;
		}

		self::$memo['parts'] = $out;

		return $out;
	}

	/**
	 * The parts that have their own checkbox: part => label.
	 *
	 * @return array<string, string>
	 */
	public static function addon_groups(): array {
		$out = [];

		foreach ( self::addon_parts() as $part => $p ) {
			if ( null !== $p['group'] ) {
				$out[ $part ] = (string) $p['label'];
			}
		}

		return $out;
	}

	/**
	 * The row lists one part writes, in ROW_LISTS order.
	 *
	 * @param string $part Part key.
	 * @return list<string>
	 */
	public static function part_row_lists( string $part ): array {
		$kinds = [];

		foreach ( self::addon_parts()[ $part ]['entries'] ?? [] as $e ) {
			$kinds[ self::text( $e['kind'] ) ] = true;
		}

		return self::lists_of_kinds( $kinds );
	}

	/**
	 * The `data` member that holds one row list of one part.
	 *
	 * @param string $part Part key.
	 * @param string $list ROW_LISTS value.
	 * @return string
	 */
	public static function part_member( string $part, string $list ): string {
		return self::ADDON_MEMBER_PREFIX . $part . '/' . $list;
	}

	/**
	 * A `data` member read as an add-on part's row list: [ part, row list ],
	 * or null when the name is not one (the part's owner must have an
	 * owner's shape, the group id GROUP_ID_PATTERN, the list a ROW_LISTS
	 * value).
	 *
	 * @param string $member Member name.
	 * @return array{0: string, 1: string}|null
	 */
	public static function parse_part_member( string $member ): ?array {
		if ( 1 !== preg_match( '#^' . preg_quote( self::ADDON_MEMBER_PREFIX, '#' ) . '((?:core|wc|wp-core|pro|addon:[a-z0-9-]+|ext:[a-z0-9_-]+)(?:/[a-z0-9][a-z0-9_-]{0,39})?)/([a-z_]+)$#', $member, $m ) ) {
			return null;
		}

		return in_array( $m[2], self::ROW_LISTS, true ) ? [ $m[1], $m[2] ] : null;
	}

	/**
	 * Sections whose rows hold post, term, attachment or form ids.
	 *
	 * @param bool $include_planned Also the planned sections.
	 * @return list<string>
	 */
	public static function id_keyed_sections( bool $include_planned = false ): array {
		$out = [];

		foreach ( self::section_flags() as $section => $s ) {
			if ( $s['id_keyed'] && ( $s['live'] || $include_planned ) ) {
				$out[] = $section;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * Every key of `perflocale_settings`.
	 *
	 * @return list<string>
	 */
	public static function settings_keys(): array {
		return array_keys( self::SETTINGS );
	}

	/**
	 * Class of one settings key, or null for a key the registry does not
	 * know.
	 *
	 * @param string $key Settings key.
	 * @return string|null
	 */
	public static function setting_class( string $key ): ?string {
		return self::SETTINGS[ $key ] ?? null;
	}

	/**
	 * Settings keys that hold a credential.
	 *
	 * @return list<string>
	 */
	public static function secret_settings(): array {
		return array_keys( self::SETTINGS, 'secret', true );
	}

	/**
	 * Settings keys that describe this site rather than its content.
	 *
	 * @return list<string>
	 */
	public static function site_specific_settings(): array {
		return array_keys( self::SETTINGS, 'site_specific', true );
	}

	/* ------------------------------------------------------------ uninstall */

	/**
	 * Uninstall values that apply in a mode.
	 *
	 * @param string $mode delete (a full-data uninstall) or preserve.
	 * @return list<string>
	 */
	private static function uninstall_values( string $mode ): array {
		return 'preserve' === $mode ? [ 'always' ] : [ 'data', 'always' ];
	}

	/**
	 * Exact option names the generic option sweep deletes, leaving out the
	 * ones an option LIKE of {@see self::uninstall_option_likes()} already
	 * reaches and `perflocale_settings` (deleted last, see
	 * {@see self::uninstall_last_options()}).
	 *
	 * @param string $mode delete or preserve.
	 * @return list<string>
	 */
	public static function uninstall_options( string $mode = 'delete' ): array {
		$likes = self::uninstall_option_likes( $mode );
		$out   = [];

		foreach ( self::entries_of_kind( 'option' ) as $e ) {
			$name = self::text( $e['name'] );

			if ( ! in_array( $e['uninstall'], self::uninstall_values( $mode ), true ) || self::has_placeholder( $name ) ) {
				continue;
			}

			if ( ! self::like_covers_any( $likes, $name ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Options a clean full-data purge deletes last.
	 *
	 * @return list<string>
	 */
	public static function uninstall_last_options(): array {
		$out = [];

		foreach ( self::entries_of_kind( 'option' ) as $e ) {
			if ( 'last' === $e['uninstall'] ) {
				$out[] = self::text( $e['name'] );
			}
		}

		return $out;
	}

	/**
	 * LIKE patterns over the options table that the uninstall sweep deletes:
	 * `%` is the wildcard, every other character literal (the caller escapes
	 * each piece between the `%` with esc_like()). Transient entries give
	 * their value and timeout rows. A pattern another pattern already covers
	 * is left out.
	 *
	 * @param string $mode delete or preserve.
	 * @return list<string>
	 */
	public static function uninstall_option_likes( string $mode = 'delete' ): array {
		if ( isset( self::$memo['likes'][ $mode ] ) ) {
			return self::$memo['likes'][ $mode ];
		}

		$likes = [];

		foreach ( self::entries() as $e ) {
			if ( ! in_array( $e['uninstall'], self::uninstall_values( $mode ), true ) ) {
				continue;
			}

			$name = self::text( $e['name'] );
			$tail = self::has_placeholder( $name ) ? self::to_like_prefix( $name ) . '%' : $name;

			if ( 'option' === $e['kind'] && self::has_placeholder( $name ) ) {
				$likes[] = $tail;
			} elseif ( 'transient' === $e['kind'] ) {
				$likes[] = '_transient_' . $tail;
				$likes[] = '_transient_timeout_' . $tail;
			} elseif ( 'site_transient' === $e['kind'] ) {
				$likes[] = '_site_transient_' . $tail;
				$likes[] = '_site_transient_timeout_' . $tail;
			}
		}

		self::$memo['likes'][ $mode ] = self::drop_covered_likes( $likes );

		return self::$memo['likes'][ $mode ];
	}

	/**
	 * Literal key prefixes the uninstall sweep deletes from one meta table
	 * (`meta_key LIKE <esc_like(prefix)>%`). A prefix another one covers is
	 * left out.
	 *
	 * @param string $meta_type post, term, user or order.
	 * @return list<string>
	 */
	public static function uninstall_meta_likes( string $meta_type ): array {
		$prefixes = [];

		foreach ( self::entries_of_kind( $meta_type . '_meta' ) as $e ) {
			if ( in_array( $e['uninstall'], [ 'data', 'always' ], true ) && self::has_placeholder( self::text( $e['name'] ) ) ) {
				$prefixes[] = self::to_like_prefix( self::text( $e['name'] ) ) . '%';
			}
		}

		return array_map(
			static function ( string $like ): string {
				return substr( $like, 0, -1 );
			},
			self::drop_covered_likes( $prefixes )
		);
	}

	/**
	 * Network options (wp_sitemeta) the network uninstall deletes once the
	 * sweep completes.
	 *
	 * @return list<string>
	 */
	public static function network_options(): array {
		$out = [];

		foreach ( self::entries_of_kind( 'network_option' ) as $e ) {
			if ( in_array( $e['uninstall'], [ 'data', 'always' ], true ) ) {
				$out[] = self::text( $e['name'] );
			}
		}

		return $out;
	}

	/**
	 * The user-meta keys of the plugin's admin preferences: the fixed list
	 * the personal-data export and erase use.
	 *
	 * @return list<string>
	 */
	public static function user_meta_keys(): array {
		$out = [];

		foreach ( self::entries_of_kind( 'user_meta' ) as $e ) {
			if ( 'USER' === $e['class'] && ! self::has_placeholder( self::text( $e['name'] ) ) ) {
				$out[] = self::text( $e['name'] );
			}
		}

		return $out;
	}

	/**
	 * Cron hooks cleared on deactivation, or on uninstall.
	 *
	 * @param string $when deactivate or uninstall.
	 * @return list<string>
	 */
	public static function cron_hooks( string $when ): array {
		$out = [];

		foreach ( self::entries_of_kind( 'cron' ) as $e ) {
			if ( self::has_placeholder( self::text( $e['name'] ) ) ) {
				continue;
			}

			if ( 'deactivate' === $when ? 'clear' === $e['deactivate'] : 'always' === $e['uninstall'] ) {
				$out[] = self::text( $e['name'] );
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------ translation copy and Sync Fields */

	/**
	 * Post-meta keys never copied onto a new translation.
	 *
	 * @return list<string>
	 */
	public static function copy_excluded_post_meta(): array {
		return self::post_meta_names( 'copy', 'no', false );
	}

	/**
	 * Post-meta key prefixes never copied onto a new translation (entries
	 * with a placeholder, up to it).
	 *
	 * @return list<string>
	 */
	public static function copy_excluded_post_meta_prefixes(): array {
		return self::post_meta_names( 'copy', 'no', true );
	}

	/**
	 * Post-meta keys copied onto a new translation only when Sync Fields
	 * lists them.
	 *
	 * @return list<string>
	 */
	public static function copy_if_synced_post_meta(): array {
		return self::post_meta_names( 'copy', 'if_synced', false );
	}

	/**
	 * Post-meta keys Sync Fields never mirrors.
	 *
	 * @return list<string>
	 */
	public static function never_sync_meta(): array {
		return self::post_meta_names( 'sync', 'never', false );
	}

	/**
	 * Whether Sync Fields must refuse a meta key: a declared entry with sync
	 * never, or any key of the plugin's own post-meta namespace that no entry
	 * lets Sync Fields list.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_never_sync_meta( string $key ): bool {
		return self::never_synced_by( self::entries_of_kind( 'post_meta' ), $key );
	}

	/**
	 * The is_never_sync_meta() check over a given list of post-meta entries.
	 *
	 * @param array<string, array<string, mixed>> $post_meta Post-meta entries.
	 * @param string                              $key       Meta key.
	 * @return bool
	 */
	private static function never_synced_by( array $post_meta, string $key ): bool {
		foreach ( $post_meta as $e ) {
			if ( 'NAMESPACE' !== $e['class'] && 1 === preg_match( self::to_regex( self::text( $e['name'] ) ), $key ) ) {
				return 'never' === $e['sync'];
			}
		}

		// Any other key that starts with a namespace's literal prefix: the
		// namespace's rule (SyncMeta::is_never_synced() is the same check).
		foreach ( $post_meta as $e ) {
			if ( 'NAMESPACE' === $e['class'] && str_starts_with( $key, self::to_like_prefix( self::text( $e['name'] ) ) ) ) {
				return 'never' === $e['sync'];
			}
		}

		return false;
	}

	/**
	 * Post-meta entry names (or prefixes) with one value of a field.
	 *
	 * @param string $field    copy or sync.
	 * @param string $value    Wanted value.
	 * @param bool   $prefixes True: the prefixes of pattern entries; false:
	 *     the exact names.
	 * @return list<string>
	 */
	private static function post_meta_names( string $field, string $value, bool $prefixes ): array {
		$out = [];

		foreach ( self::entries_of_kind( 'post_meta' ) as $e ) {
			$name = self::text( $e['name'] );

			if ( 'NAMESPACE' === $e['class'] || $e[ $field ] !== $value || self::has_placeholder( $name ) !== $prefixes ) {
				continue;
			}

			$out[] = $prefixes ? self::to_like_prefix( $name ) : $name;
		}

		return $out;
	}

	/* ------------------------------------------------------------ round trip */

	/**
	 * Entries a restore through export and import does not bring back:
	 * not exported (or not yet, `gap`) and not regenerated (DERIVED). The
	 * round-trip report prints the list so each loss is visible and owned.
	 *
	 * @return list<string> Entry ids.
	 */
	public static function expected_loss(): array {
		$out = [];

		foreach ( self::entries() as $id => $e ) {
			if ( in_array( $e['class'], [ 'DERIVED', 'NAMESPACE', 'FOREIGN', 'LEGACY' ], true ) ) {
				continue;
			}

			if ( null === $e['export'] || isset( $e['gap'] ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * Whether one of the LIKE patterns matches a name (LIKE semantics with
	 * `%` as the only wildcard).
	 *
	 * @param array<int, string> $likes LIKE patterns.
	 * @param string             $name  Name.
	 * @return bool
	 */
	private static function like_covers_any( array $likes, string $name ): bool {
		foreach ( $likes as $like ) {
			$re = '/^' . str_replace( '%', '.*', preg_quote( $like, '/' ) ) . '$/s';

			if ( 1 === preg_match( $re, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop duplicates and the LIKE patterns another trailing-`%` pattern
	 * already covers, keeping the first-seen order.
	 *
	 * @param array<int, string> $likes LIKE patterns.
	 * @return list<string>
	 */
	private static function drop_covered_likes( array $likes ): array {
		$likes = array_values( array_unique( $likes ) );
		$out   = [];

		foreach ( $likes as $like ) {
			$covered = false;

			foreach ( $likes as $other ) {
				if ( $other === $like || '%' !== substr( $other, -1 ) ) {
					continue;
				}

				$stem = substr( $other, 0, -1 );

				if ( false === strpos( $stem, '%' ) && 0 === strpos( $like, $stem ) ) {
					$covered = true;
					break;
				}
			}

			if ( ! $covered ) {
				$out[] = $like;
			}
		}

		return $out;
	}
}
