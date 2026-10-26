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
 * Settings storage for addons: the fields of get_settings_fields(), keyed by addon id in one option.
 *
 * Read with AddonSettings::get( 'woocommerce', 'wc_email_translation', true ).
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class AddonSettings {

	/**
	 * Option name: one row for every addon, read once per request.
	 */
	private const OPTION = 'perflocale_addon_settings';

	/**
	 * Serialised size cap per addon entry; an addon with more state keeps it in its own option or table.
	 */
	private const MAX_ADDON_BYTES = 16384;

	/**
	 * Lock around every write: two saves to different addons share one option, so the second would drop the first.
	 */
	private const LOCK_NAME = 'addon_settings_write';

	/**
	 * Lock lifetime in seconds; the work inside is one update_option().
	 */
	private const LOCK_TTL = 10;

	/**
	 * Returned by sanitize_field() for a 'custom' field without sanitize_callback: the caller keeps the stored value.
	 */
	public const CUSTOM_NO_SANITIZE = "\0perflocale_custom_no_sanitize\0";

	/**
	 * Request memo of the whole option.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Read a single setting for an addon; a malformed id returns $default.
	 *
	 * @param string $addon_id The addon's get_id() value.
	 * @param string $key      The settings field key declared in get_settings_fields().
	 * @param mixed  $default  Fallback when nothing is stored; match the field's `default`.
	 * @return mixed
	 */
	public static function get( string $addon_id, string $key, $default = null ) {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			return $default;
		}

		$all   = self::all();
		$entry = $all[ $addon_id ] ?? null;

		// Not ??: a stored null is returned, not $default.
		if ( is_array( $entry ) && array_key_exists( $key, $entry ) ) {
			return $entry[ $key ];
		}

		return $default;
	}

	/**
	 * Read every stored setting for one addon; [] for a malformed id.
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

		// A non-array entry is corrupt and reads as none; (array) would turn 'x' into [0 => 'x'].
		return is_array( $entry ) ? $entry : [];
	}

	/**
	 * Read several settings of one addon; a key without a stored value or a default reads null.
	 *
	 * @param string               $addon_id The addon's get_id() value.
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
	 * Write a single setting for an addon; set_addon() writes several in one write.
	 *
	 * @param string $addon_id The addon's get_id() value.
	 * @param string $key      Setting key.
	 * @param mixed  $value    Value to store.
	 * @return bool True when stored or unchanged; false for a bad id, too large, a refused write or a held lock.
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
	 * Overwrite all settings of one addon in one write.
	 *
	 * @param string               $addon_id The addon's get_id() value.
	 * @param array<string, mixed> $values   Every setting of the addon.
	 * @return bool True when stored or unchanged; false for a bad id, too large, a refused write or a held lock.
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
	 * Drop everything stored for an addon.
	 *
	 * @param string $addon_id The addon's get_id() value.
	 * @return bool True when gone (or never stored); false for a bad id, a refused write or a held lock.
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
	 * Coerce a raw POST value into the type declared by the field.
	 *
	 * @param array<string, mixed> $field     Field definition from get_settings_fields().
	 * @param mixed                $raw       Raw POST value (always string or array of strings).
	 * @param string               $addon_id  Addon the field belongs to; a 'password' field
	 *                                        needs it to keep its stored value.
	 * @param string               $field_key The field's key in get_settings_fields().
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

			case 'password':
				// The form renders a password field empty, so an empty submission
				// keeps the stored value (the field's default when none is stored).
				$value = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';

				if ( $value === '' && $addon_id !== '' && $field_key !== '' ) {
					return self::get( $addon_id, $field_key, $field['default'] ?? '' );
				}

				return $value;

			case 'select':
				$options = (array) ( $field['options'] ?? [] );
				$value   = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
				// Only a declared option: the POST value is untrusted.
				return array_key_exists( $value, $options ) ? $value : ( $field['default'] ?? '' );

			case 'custom':
				// The addon's callback reads its own POST keys (a field can span several); without one the stored value stays.
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
	 * Field types the generic admin form renders and saves; any other type is addon-managed state it leaves alone.
	 *
	 * @return array<int, string>
	 */
	public static function user_editable_types(): array {
		// `custom` fields render and sanitize through their own callbacks.
		return [ 'checkbox', 'text', 'textarea', 'number', 'select', 'password', 'custom' ];
	}

	/**
	 * True if the generic Addons form edits the field; it skips the rest, so addon state survives a form save.
	 *
	 * A `'storage' => 'global'` field is never one: the addon's render_settings_subtab() owns it.
	 *
	 * @param array<string, mixed> $field Field definition.
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
	 * True if a field is stored in the main `perflocale_settings` option (`'storage' => 'global'`).
	 *
	 * Such a field gets no seeded add-on default or auto-form; WP-CLI reads the main option and writes it
	 * through Settings::update().
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return bool
	 */
	public static function is_global_storage( array $field ): bool {
		return isset( $field['storage'] ) && $field['storage'] === 'global';
	}

	/**
	 * Evaluate a field's `show_if` against the current values; an empty spec is visible.
	 *
	 * A spec is `[ key => expected, ... ]` (all must match) or `[ 'op' => 'OR'|'AND', 'rules' => [ spec, ... ] ]`.
	 *
	 * @param mixed                $show_if   The show_if spec.
	 * @param array<string, mixed> $values    Current addon values.
	 * @return bool
	 */
	public static function evaluate_show_if( $show_if, array $values ): bool {
		if ( empty( $show_if ) || ! is_array( $show_if ) ) {
			return true;
		}

		// Nested operator form.
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

			// Any other operator means all rules must match.
			foreach ( $rules as $rule ) {
				if ( ! self::evaluate_show_if( $rule, $values ) ) {
					return false;
				}
			}
			return true;
		}

		// Simple form: field name => expected value, all must match.
		foreach ( $show_if as $field_key => $expected ) {
			if ( ! is_string( $field_key ) ) {
				continue;
			}
			$actual = $values[ $field_key ] ?? null;

			// Loose, so '1' matches true for checkbox values.
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			if ( $actual != $expected ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Render the auto-generated settings form of one addon; `show_if` is evaluated here and again by the enqueued script.
	 *
	 * A `password` field never prints its stored value: it renders empty, masked when set, and an empty submission keeps it.
	 *
	 * @param string                              $addon_id        Addon id.
	 * @param array<string, array<string, mixed>> $editable_fields Fields to render.
	 * @param array<string, mixed>                $values     Currently stored values.
	 * @param string                              $form_action URL to post to (admin-post.php).
	 * @return void
	 */
	public static function render_form( string $addon_id, array $editable_fields, array $values, string $form_action ): void {
		// Defaults fill unstored values, or a new addon would judge every show_if against null and hide each dependent row.
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
					// Callers pre-filter, but a non-editable field must never render as a text input.
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

					// Initial visibility; the script updates it as fields change.
					$visible = self::evaluate_show_if( $field_def['show_if'] ?? null, $eval_values );

					// A custom field's render_callback prints its whole row markup.
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
								<input type="password" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" data-perflocale-field-name="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo esc_attr( isset( $values[ $field_key ] ) && is_scalar( $values[ $field_key ] ) && (string) $values[ $field_key ] !== '' ? str_repeat( '*', 20 ) : '' ); ?>" class="regular-text" autocomplete="off">
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
	 * Reset the request memo; the blog-switch reset list calls it, since the option is per blog.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$cache = null;
	}

	/**
	 * Load the full settings array, memoised.
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
	 * Apply a mutator to the stored value and persist it, read and write under one lock.
	 *
	 * The read inside the lock bypasses the memo and the object cache, or a stale copy would drop another request's write.
	 *
	 * @param callable $mutator   Takes the current array, returns the new one.
	 * @param string   $addon_id  The addon whose entry is mutated (size cap, log line).
	 * @return bool True when written or unchanged; false for the size cap, a refused write or a held lock.
	 */
	private static function persist_with_mutator( callable $mutator, string $addon_id ): bool {
		$result = Lock::with(
			self::LOCK_NAME,
			self::LOCK_TTL,
			static function () use ( $mutator, $addon_id ): bool {
				// Read the committed row, not a cached copy.
				wp_cache_delete( self::OPTION, 'options' );

				// A `notoptions` entry from before another request created the row would hide it; unlist it.
				$notoptions = wp_cache_get( 'notoptions', 'options' );

				if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
					\PerfLocale\Database\NotOptions::unlist( [ self::OPTION ] );
				}

				$current   = (array) get_option( self::OPTION, [] );
				$old_entry = (array) ( $current[ $addon_id ] ?? [] );

				$next      = (array) $mutator( $current );
				$new_entry = (array) ( $next[ $addon_id ] ?? [] );

				// Per-addon cap: an oversized entry is refused whole, not truncated.
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
				 * Fires inside the storage lock, right before the option is written.
				 *
				 * Listeners must not call the AddonSettings writers: the lock is held, so that write fails; defer it instead.
				 *
				 * @hook perflocale/addon/settings/before_save
				 * @param string               $addon_id  Addon id being saved.
				 * @param array<string, mixed> $new_entry The values about to commit.
				 * @param array<string, mixed> $old_entry The pre-save values.
				 */
				do_action( 'perflocale/addon/settings/before_save', $addon_id, $new_entry, $old_entry );

				// Not autoloaded: entries of many addons can add up, and all() memoises the one read.
				// update_option() is false for an unchanged value too: only a changed one that did not commit is a refusal.
				if ( ! update_option( self::OPTION, $next, false ) && maybe_serialize( $next ) !== maybe_serialize( $current ) ) {
					self::$cache = null;
					self::log_rejection( 'persist', $addon_id, 'write_refused' );
					return false;
				}
				self::$cache = $next;

				/**
				 * Fires inside the storage lock after the option is written or found unchanged; not after a refused write.
				 *
				 * Listeners must not call the AddonSettings writers: the lock is held, so that write fails.
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

		// Null: the lock was held (Lock::with() does not wait).
		if ( $result === null ) {
			self::log_rejection( 'persist', $addon_id, 'lock_contention' );
			return false;
		}

		return (bool) $result;
	}

	/**
	 * Log a rejected write under WP_DEBUG only, so a misbehaving addon cannot fill the log.
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
				'PerfLocale AddonSettings::%s rejected for addon_id=%s - %s',
				$op,
				$addon_id === '' ? '(empty)' : substr( $addon_id, 0, 64 ),
				$reason
			)
		);
	}
}
