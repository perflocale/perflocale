<?php
/**
 * Addon settings storage + read/write helper.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

use PerfLocale\Concurrency\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent settings storage for addons.
 *
 * Each addon's `get_settings_fields()` defines the fields it wants
 * configurable from the PerfLocale Addons admin page. The values are
 * persisted in a single `perflocale_addon_settings` option (one DB row,
 * autoloaded) keyed by addon ID:
 *
 *   [
 *     'woocommerce' => [ 'wc_email_translation' => true, 'wc_sync_stock' => false ],
 *     'acf'         => [ 'acf_auto_detect' => true ],
 *   ]
 *
 * Addons read their own settings via {@see get()} from anywhere in the
 * codebase:
 *
 *   AddonSettings::get( 'woocommerce', 'wc_email_translation', true );
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class AddonSettings {

	/**
	 * Option name. Single autoloaded option keyed by addon ID so addons
	 * reading their own setting incur exactly one cache lookup, not one
	 * per addon-setting pair.
	 */
	private const OPTION = 'perflocale_addon_settings';

	/**
	 * Maximum serialised size per addon entry. Bounds the worst case so a
	 * single buggy addon can't grow the autoloaded option to multi-megabyte
	 * sizes that slow every request's bootstrap deserialise.
	 *
	 * 16 KiB comfortably fits ~300 typical text-field settings. Addons that
	 * need to store more should keep that state in their own option/table
	 * and use AddonSettings only for the user-editable configuration.
	 */
	private const MAX_ADDON_BYTES = 16384;

	/**
	 * Lock name for serialised writes. Wraps every persist() so two
	 * concurrent saves to different addon entries can't race on the shared
	 * option — without it the second write would clobber the first.
	 */
	private const LOCK_NAME = 'addon_settings_write';

	/**
	 * Lock TTL — short because persist() is essentially a single
	 * update_option call. 10 s leaves headroom for slow DBs but won't
	 * deadlock a misbehaving caller for long.
	 */
	private const LOCK_TTL = 10;

	/**
	 * Sentinel returned by sanitize_field() for a 'custom' field with no
	 * sanitize_callback declared, so the caller can preserve the existing
	 * stored value instead of overwriting it with null/default. A class
	 * constant string is safe to compare with === without colliding with
	 * any legitimate user-supplied value.
	 */
	public const CUSTOM_NO_SANITIZE = "\0perflocale_custom_no_sanitize\0";

	/**
	 * In-memory cache of the full option value. Populated lazily on first
	 * read so the option only deserialises once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Read a single setting for an addon.
	 *
	 * Returns `$default` when the addon id is malformed — same return path
	 * as "not stored", so callers don't have to special-case invalid ids.
	 *
	 * @param string $addon_id The addon's get_id() value.
	 * @param string $key      The settings field key declared in get_settings_fields().
	 * @param mixed  $default  Fallback if the value isn't stored. Should match
	 *                         the field's `default` from get_settings_fields().
	 * @return mixed
	 */
	public static function get( string $addon_id, string $key, $default = null ) {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			return $default;
		}

		$all   = self::all();
		$entry = $all[ $addon_id ] ?? null;

		// array_key_exists (not ??) so a stored null value round-trips
		// instead of being shadowed by $default. Matches get_many().
		if ( is_array( $entry ) && array_key_exists( $key, $entry ) ) {
			return $entry[ $key ];
		}

		return $default;
	}

	/**
	 * Read every stored setting for one addon.
	 *
	 * Returns an empty array for malformed addon ids.
	 *
	 * @param string $addon_id The addon's get_id() value.
	 * @return array<string, mixed>
	 */
	public static function get_addon( string $addon_id ): array {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			return [];
		}

		$all   = self::all();
		$entry = $all[ $addon_id ] ?? null;

		// (array) coerces 'string' → [0 => 'string'], which would expose
		// corrupted DB state as a partial-looking array. Explicit guard:
		// only an actual array shape is a legitimate entry; everything
		// else (scalar, object, null) is treated as "no entry".
		return is_array( $entry ) ? $entry : [];
	}

	/**
	 * Read multiple settings for one addon in a single call.
	 *
	 * Cleaner than chained {@see get()} calls when an addon needs
	 * several of its own settings at once — and slightly cheaper because
	 * the in-memory cache is looked up once instead of once per key.
	 *
	 * Defaults are matched per-key from the supplied `$defaults` map; if
	 * a key is in `$keys` but not in `$defaults`, the returned value is
	 * the stored value or `null` when nothing is stored.
	 *
	 * Example:
	 *
	 *   $settings = AddonSettings::get_many(
	 *       'my-addon',
	 *       [ 'enable_sync', 'endpoint', 'cache_ttl' ],
	 *       [ 'enable_sync' => true, 'cache_ttl' => 3600 ]
	 *   );
	 *   // $settings === [ 'enable_sync' => …, 'endpoint' => …, 'cache_ttl' => … ]
	 *
	 * @param string               $addon_id
	 * @param array<int, string>   $keys     List of setting keys to read.
	 * @param array<string, mixed> $defaults Optional per-key fallbacks.
	 * @return array<string, mixed> Map keyed by `$keys` (in input order).
	 */
	public static function get_many( string $addon_id, array $keys, array $defaults = [] ): array {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			$out = [];
			foreach ( $keys as $key ) {
				if ( is_string( $key ) && $key !== '' ) {
					$out[ $key ] = $defaults[ $key ] ?? null;
				}
			}
			return $out;
		}

		$all   = self::all();
		$entry = (array) ( $all[ $addon_id ] ?? [] );
		$out   = [];

		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}
			$out[ $key ] = array_key_exists( $key, $entry ) ? $entry[ $key ] : ( $defaults[ $key ] ?? null );
		}

		return $out;
	}

	/**
	 * Write a single setting for an addon. Use sparingly — the admin form
	 * handler writes the whole addon group at once via {@see set_addon()},
	 * which is cheaper.
	 *
	 * @param string $addon_id
	 * @param string $key
	 * @param mixed  $value
	 * @return bool True on success; false if rejected (bad id, too large,
	 *              lock contention).
	 */
	public static function set( string $addon_id, string $key, $value ): bool {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			self::log_rejection( 'set', $addon_id, 'invalid_addon_id' );
			return false;
		}

		return self::persist_with_mutator(
			static function ( array $all ) use ( $addon_id, $key, $value ): array {
				if ( ! isset( $all[ $addon_id ] ) ) {
					$all[ $addon_id ] = [];
				}
				$all[ $addon_id ][ $key ] = $value;
				return $all;
			},
			$addon_id
		);
	}

	/**
	 * Overwrite all settings for a single addon. Used by the admin form
	 * handler so a single save call updates every checkbox/text field at
	 * once, in one DB write.
	 *
	 * @param string               $addon_id
	 * @param array<string, mixed> $values
	 * @return bool True on success; false if rejected.
	 */
	public static function set_addon( string $addon_id, array $values ): bool {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			self::log_rejection( 'set_addon', $addon_id, 'invalid_addon_id' );
			return false;
		}

		return self::persist_with_mutator(
			static function ( array $all ) use ( $addon_id, $values ): array {
				$all[ $addon_id ] = $values;
				return $all;
			},
			$addon_id
		);
	}

	/**
	 * Drop everything stored for an addon. Called by the uninstall path
	 * when an addon's parent plugin is removed.
	 *
	 * @param string $addon_id
	 * @return bool True on success (including the no-op case); false if
	 *              the id is malformed.
	 */
	public static function forget( string $addon_id ): bool {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			self::log_rejection( 'forget', $addon_id, 'invalid_addon_id' );
			return false;
		}

		return self::persist_with_mutator(
			static function ( array $all ) use ( $addon_id ): array {
				unset( $all[ $addon_id ] );
				return $all;
			},
			$addon_id
		);
	}

	/**
	 * Coerce a raw POST value into the type declared by the field. Centralised
	 * so the admin form handler doesn't have to special-case each type.
	 *
	 * @param array<string, mixed> $field Field definition from get_settings_fields().
	 * @param mixed                $raw   Raw POST value (always string or array of strings).
	 * @return mixed
	 */
	public static function sanitize_field( array $field, $raw, string $addon_id = '', string $field_key = '' ) {
		$type = (string) ( $field['type'] ?? 'text' );

		switch ( $type ) {
			case 'checkbox':
				return ! empty( $raw );

			case 'number':
				return is_numeric( $raw ) ? (int) $raw : 0;

			case 'textarea':
				return is_string( $raw ) ? sanitize_textarea_field( wp_unslash( $raw ) ) : '';

			case 'select':
				$options = (array) ( $field['options'] ?? [] );
				$value   = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
				// Only allow values declared in the options list to defend
				// against tampering with hidden form fields.
				return array_key_exists( $value, $options ) ? $value : ( $field['default'] ?? '' );

			case 'custom':
				// Custom fields delegate sanitization to the addon's own
				// callback because their POST data can span multiple keys
				// (e.g. a per-language matrix uses nested input names).
				// Callback signature mirrors render_callback:
				// ($addon_id, $field_key, $field_def, $raw)
				// — the callback is responsible for reading from $_POST
				// itself when its POST shape doesn't fit the canonical
				// settings[$field_key] slot. Missing callback → signal
				// "no sanitize" so the caller can preserve existing
				// addon-managed state instead of overwriting it.
				$cb = $field['sanitize_callback'] ?? null;
				if ( is_callable( $cb ) ) {
					return $cb( $addon_id, $field_key, $field, $raw );
				}
				return self::CUSTOM_NO_SANITIZE;

			case 'text':
			default:
				return is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
		}
	}

	/**
	 * Field types that the generic admin form renders + writes. Anything
	 * else (currently 'hidden', but the list is forward-compatible) is
	 * addon-managed state and the generic save handler leaves it alone.
	 *
	 * @return array<int, string>
	 */
	public static function user_editable_types(): array {
		// `custom` is included so the save handler iterates over the
		// field and gives it a chance to extract its own POST values
		// via the field's sanitize_callback. Render is delegated to
		// the field's render_callback so the addon emits its own rows.
		return [ 'checkbox', 'text', 'textarea', 'number', 'select', 'password', 'custom' ];
	}

	/**
	 * True if the field is user-editable from the generic Addons-page form.
	 * The handler skips fields that fail this check so addons can store
	 * arbitrary state (e.g. WooCommerce wc_currencies) without losing it on
	 * a form save.
	 *
	 * Fields tagged with `'storage' => 'global'` are NEVER user-editable
	 * via the auto-form: their storage is the main `perflocale_settings`
	 * option, not `perflocale_addon_settings`, and the addon's
	 * {@see AddonInterface::render_settings_subtab()} takeover renders
	 * and saves them itself.
	 *
	 * @param array<string, mixed> $field
	 * @return bool
	 */
	public static function is_user_editable_field( array $field ): bool {
		if ( self::is_global_storage( $field ) ) {
			return false;
		}
		$type = (string) ( $field['type'] ?? 'text' );
		return in_array( $type, self::user_editable_types(), true );
	}

	/**
	 * True if a field declares `'storage' => 'global'` — its value is
	 * stored in the main `perflocale_settings` option, not in
	 * `perflocale_addon_settings`. The framework MUST NOT seed defaults
	 * for, render via auto-form, save through the auto-form handler, or
	 * accept `wp perflocale addon settings set` writes for global-storage
	 * fields. `settings get` / `settings list` redirect their reads to
	 * the main settings option.
	 *
	 * @param array<string, mixed> $field
	 * @return bool
	 */
	public static function is_global_storage( array $field ): bool {
		return isset( $field['storage'] ) && $field['storage'] === 'global';
	}

	/**
	 * Evaluate a field's `show_if` against the current values map.
	 *
	 * Supported spec shapes:
	 *
	 *   1. Simple equality (implicit AND across keys):
	 *        'show_if' => [ 'wc_currency_per_lang' => true ]
	 *        'show_if' => [ 'mode' => 'advanced', 'logging' => true ]
	 *
	 *   2. Nested with operator:
	 *        'show_if' => [
	 *            'op'    => 'OR' | 'AND',
	 *            'rules' => [
	 *                [ 'wc_currency_per_lang' => true ],
	 *                [ 'op' => 'AND', 'rules' => [
	 *                    [ 'mode' => 'pro' ],
	 *                    [ 'beta' => true ],
	 *                ]],
	 *            ],
	 *        ]
	 *
	 * Empty / missing show_if returns true (field always visible).
	 *
	 * @param mixed                $show_if   The show_if spec.
	 * @param array<string, mixed> $values    Current addon values.
	 * @return bool
	 */
	public static function evaluate_show_if( $show_if, array $values ): bool {
		if ( empty( $show_if ) || ! is_array( $show_if ) ) {
			return true;
		}

		// Nested operator form
		if ( isset( $show_if['op'], $show_if['rules'] ) && is_array( $show_if['rules'] ) ) {
			$op    = strtoupper( (string) $show_if['op'] );
			$rules = $show_if['rules'];

			if ( $op === 'OR' ) {
				foreach ( $rules as $rule ) {
					if ( self::evaluate_show_if( $rule, $values ) ) {
						return true;
					}
				}
				return false;
			}

			// Default AND
			foreach ( $rules as $rule ) {
				if ( ! self::evaluate_show_if( $rule, $values ) ) {
					return false;
				}
			}
			return true;
		}

		// Simple form: keys are field names, values are expected values.
		// Implicit AND across all keys.
		foreach ( $show_if as $field_key => $expected ) {
			if ( ! is_string( $field_key ) ) {
				continue;
			}
			$actual = $values[ $field_key ] ?? null;

			// Loose comparison so '1' == true for checkbox-ish values.
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			if ( $actual != $expected ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Render the auto-generated settings form for one addon. Used by the
	 * per-addon subtab on PerfLocale → Settings → Addons. Supports the
	 * `show_if` conditional-display spec on individual fields, evaluated
	 * server-side for the initial render AND client-side as the user
	 * checks/unchecks driver fields (via the enqueued
	 * `perflocale-addon-conditional-fields` script).
	 *
	 * @param string                              $addon_id
	 * @param array<string, array<string, mixed>> $editable_fields
	 * @param array<string, mixed>                $values     Currently stored values.
	 * @param string                              $form_action URL to post to (admin-post.php).
	 * @return void
	 */
	public static function render_form( string $addon_id, array $editable_fields, array $values, string $form_action ): void {
		// Pre-build the value map used for show_if evaluation. Stored
		// values win, but any field with a declared `default` falls back
		// to that default when no stored value exists — otherwise a
		// freshly-installed addon with no auto-seed yet would evaluate
		// every show_if against null and hide every dependent row.
		$eval_values = $values;
		foreach ( $editable_fields as $key => $field ) {
			if ( ! array_key_exists( $key, $eval_values )
				&& is_array( $field )
				&& array_key_exists( 'default', $field )
			) {
				$eval_values[ $key ] = $field['default'];
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( $form_action ); ?>" data-perflocale-addon-form="<?php echo esc_attr( $addon_id ); ?>">
			<input type="hidden" name="action" value="perflocale_save_addon_settings">
			<input type="hidden" name="addon_id" value="<?php echo esc_attr( $addon_id ); ?>">
			<?php wp_nonce_field( 'perflocale_save_addon_settings_' . $addon_id ); ?>

			<table class="form-table" role="presentation">
				<tbody>
				<?php
				foreach ( $editable_fields as $field_key => $field_def ) :
					// Defensive: callers are expected to pre-filter via
					// is_user_editable_field(), but skipping here avoids
					// rendering a stray 'hidden' (or future non-editable)
					// field as a text input if the filter is bypassed.
					if ( ! is_array( $field_def ) || ! self::is_user_editable_field( $field_def ) ) {
						continue;
					}

					$field_type    = (string) ( $field_def['type'] ?? 'text' );
					$field_label   = (string) ( $field_def['label'] ?? $field_key );
					$field_default = $field_def['default'] ?? '';
					$field_value   = $values[ $field_key ] ?? $field_default;
					$field_desc    = (string) ( $field_def['description'] ?? '' );
					$input_id      = 'perflocale-addon-' . $addon_id . '-' . $field_key;
					$input_name    = 'settings[' . $field_key . ']';

					// Server-side show_if evaluation. JS keeps the
					// initial state in sync as the user toggles fields.
					$visible = self::evaluate_show_if( $field_def['show_if'] ?? null, $eval_values );

					// Custom-type fields delegate the entire row(s) to
					// the addon's render_callback. The callback owns the
					// full markup including any <tr> / <td> structure,
					// any custom input naming (e.g. nested arrays for a
					// matrix), and any show_if attrs it wants on its
					// rows. The framework just provides initial show_if
					// visibility metadata via the call args so the
					// callback can mirror it.
					if ( $field_type === 'custom' ) {
						$render = $field_def['render_callback'] ?? null;
						if ( is_callable( $render ) ) {
							$render( $addon_id, $field_key, $field_def, $field_value, $values );
						}
						continue;
					}
					?>
					<tr data-perflocale-field-key="<?php echo esc_attr( $field_key ); ?>"
						<?php if ( ! empty( $field_def['show_if'] ) ) : ?> data-perflocale-show-if="<?php echo esc_attr( (string) wp_json_encode( $field_def['show_if'] ) ); ?>"<?php endif; ?>
						<?php if ( ! $visible ) : ?> style="display:none;"<?php endif; ?>>
						<th scope="row">
							<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field_label ); ?></label>
						</th>
						<td>
							<?php if ( $field_type === 'checkbox' ) : ?>
								<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" value="1" data-perflocale-field-name="<?php echo esc_attr( $field_key ); ?>" <?php checked( ! empty( $field_value ) ); ?>>
							<?php elseif ( $field_type === 'textarea' ) : ?>
								<textarea id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" data-perflocale-field-name="<?php echo esc_attr( $field_key ); ?>" rows="3" class="large-text"><?php echo esc_textarea( (string) $field_value ); ?></textarea>
							<?php elseif ( $field_type === 'number' ) : ?>
								<input type="number" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" data-perflocale-field-name="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( (string) $field_value ); ?>" class="small-text">
								<?php
							elseif ( $field_type === 'select' ) :
								$opts = (array) ( $field_def['options'] ?? [] );
								?>
								<select id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" data-perflocale-field-name="<?php echo esc_attr( $field_key ); ?>">
									<?php foreach ( $opts as $opt_value => $opt_label ) : ?>
										<option value="<?php echo esc_attr( (string) $opt_value ); ?>" <?php selected( (string) $field_value, (string) $opt_value ); ?>><?php echo esc_html( (string) $opt_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( $field_type === 'password' ) : ?>
								<input type="password" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" data-perflocale-field-name="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( (string) $field_value ); ?>" class="regular-text" autocomplete="off">
							<?php else : ?>
								<input type="text" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" data-perflocale-field-name="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( (string) $field_value ); ?>" class="regular-text">
							<?php endif; ?>
							<?php if ( $field_desc !== '' ) : ?>
								<p class="description"><?php echo esc_html( $field_desc ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Changes', 'perflocale' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Reset the in-memory cache. Called from invalidate-on-write paths and
	 * the test suite's reset_static_caches sweep.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$cache = null;
	}

	/**
	 * Load the full settings array, populating the in-memory cache on
	 * first read.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all(): array {
		if ( self::$cache === null ) {
			self::$cache = (array) get_option( self::OPTION, [] );
		}

		return self::$cache;
	}

	/**
	 * Apply a mutator to the latest stored value and persist the result —
	 * the WHOLE thing under a Lock::with() so two concurrent writes to
	 * different addons can't race on the shared option (the second one
	 * would otherwise read a stale snapshot from cache and clobber the
	 * first one's commit). Inside the lock we re-read the option from the
	 * database (bypassing the in-memory cache and WP's object cache) so
	 * the mutator works on a guaranteed-fresh snapshot.
	 *
	 * @param callable $mutator   Takes the current array, returns the new one.
	 * @param string   $addon_id  The addon whose entry is being mutated; used
	 *                            for the size-cap check + the rejection log line.
	 * @return bool True if the write committed; false if rejected.
	 */
	private static function persist_with_mutator( callable $mutator, string $addon_id ): bool {
		$result = Lock::with(
			self::LOCK_NAME,
			self::LOCK_TTL,
			static function () use ( $mutator, $addon_id ): bool {
				// Re-read from the DB inside the lock. The in-memory cache
				// could be stale from a snapshot taken before another worker
				// committed; WP's object cache could be too. Re-querying the
				// raw option is the only way to guarantee we mutate the
				// latest-committed state.
				wp_cache_delete( self::OPTION, 'options' );
				$current   = (array) get_option( self::OPTION, [] );
				$old_entry = (array) ( $current[ $addon_id ] ?? [] );

				$next      = (array) $mutator( $current );
				$new_entry = (array) ( $next[ $addon_id ] ?? [] );

				// Cap PER-ADDON to bound worst-case autoloaded-option growth.
				// Reject the write entirely on overflow — better to drop the
				// commit (and surface it to the operator via log) than to
				// silently grow an option that slows every request forever.
				if ( isset( $next[ $addon_id ] ) ) {
					$serialised = (string) maybe_serialize( $next[ $addon_id ] );

					if ( strlen( $serialised ) > self::MAX_ADDON_BYTES ) {
						self::log_rejection(
							'persist',
							$addon_id,
							sprintf(
								'entry_too_large (%d bytes > %d cap)',
								strlen( $serialised ),
								self::MAX_ADDON_BYTES
							)
						);
						return false;
					}
				}

				/**
				 * Fires inside the storage lock, immediately before the
				 * autoloaded option commits. Use to react to (or audit) a
				 * pending save with both old and proposed values in hand.
				 *
				 * IMPORTANT: do NOT call AddonSettings::set / set_addon /
				 * forget from this listener — the write lock is non-
				 * reentrant and your write will time out at 10 s. For
				 * cross-addon write reactions, hook the after-save action
				 * (which still fires inside the lock but represents work
				 * that should also bypass the writer path) OR defer with
				 * wp_schedule_single_event.
				 *
				 * @hook perflocale/addon/settings/before_save
				 * @param string               $addon_id  Addon id being saved.
				 * @param array<string, mixed> $new_entry The values about to commit.
				 * @param array<string, mixed> $old_entry The pre-save values.
				 */
				do_action( 'perflocale/addon/settings/before_save', $addon_id, $new_entry, $old_entry );

				// autoload=false: per-addon entries can accumulate (16 KiB cap
				// × N addons), so paying a single SELECT on first read is
				// cheaper than carrying the full option in alloptions on every
				// request whether read or not. AddonSettings::all() memoises
				// the result statically, so reads after the first are free.
				update_option( self::OPTION, $next, false );
				self::$cache = $next;

				/**
				 * Fires inside the storage lock, immediately after the
				 * autoloaded option commits. Same reentrancy caveat as
				 * before_save — listeners must not call AddonSettings
				 * writers synchronously.
				 *
				 * @hook perflocale/addon/settings/after_save
				 * @param string               $addon_id  Addon id that was saved.
				 * @param array<string, mixed> $new_entry The values that were written.
				 * @param array<string, mixed> $old_entry The pre-save values.
				 */
				do_action( 'perflocale/addon/settings/after_save', $addon_id, $new_entry, $old_entry );

				return true;
			}
		);

		// Lock::with() returns null when the lock couldn't be acquired
		// within the TTL — treat that as a transient failure so the
		// caller can decide to retry / report.
		if ( $result === null ) {
			self::log_rejection( 'persist', $addon_id, 'lock_contention' );
			return false;
		}

		return (bool) $result;
	}

	/**
	 * Log a rejected write to the PHP error log when WP_DEBUG is on.
	 * Quiet in production so a misbehaving addon can't fill the log.
	 *
	 * @param string $op       Method name that rejected the write.
	 * @param string $addon_id Addon id involved (may be malformed).
	 * @param string $reason   Short reason code.
	 * @return void
	 */
	private static function log_rejection( string $op, string $addon_id, string $reason ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'PerfLocale AddonSettings::%s rejected for addon_id=%s — %s',
				$op,
				$addon_id === '' ? '(empty)' : substr( $addon_id, 0, 64 ),
				$reason
			)
		);
	}
}
