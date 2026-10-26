<?php
/**
 * Renders and saves an addon's settings inside its Integration Settings card.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

use BuddyNext\Admin\AdminPageBase;
use BuddyNext\Admin\Settings\Field;

/**
 * Reuses the admin row helpers every other settings page uses, so an addon's
 * fields look and behave like BuddyNext's own. Internal.
 */
final class AddonSettingsForm extends AdminPageBase {

	/**
	 * Page title (unused: this renders rows, not a page).
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return '';
	}

	/**
	 * Page subtitle (unused).
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return '';
	}

	/**
	 * Page body (unused).
	 *
	 * @return void
	 */
	protected function render_content(): void {}

	/**
	 * Print the rows for an addon's fields.
	 *
	 * @param string $slug Addon slug.
	 * @return void
	 */
	public function render( string $slug ): void {
		foreach ( AddonRegistry::instance()->settings_fields( $slug ) as $field ) {
			$value = $field->display_value();
			switch ( $field->type ) {
				case 'toggle':
					$this->render_toggle_row( $field->key, $field->label, $field->hint(), (bool) $value, $field->disabled() );
					break;
				case 'number':
					$this->render_number_row( $field->key, $field->label, (int) $value, $field->hint(), (int) ( $field->min ?? 0 ), $field->max );
					break;
				case 'textarea':
					$this->render_textarea_row( $field->key, $field->label, (string) $value, $field->hint() );
					break;
				case 'select':
					$this->render_select_row( $field->key, $field->label, (string) $value, $field->choices(), $field->hint(), $field->disabled() );
					break;
				default:
					$this->render_text_row( $field->key, $field->label, (string) $value, $field->hint() );
			}
		}
	}

	/**
	 * Save the posted values for an addon's fields, each through its own sanitiser.
	 *
	 * Called after the nonce and capability checks of the Integration Settings save.
	 *
	 * @param string $slug Addon slug.
	 * @return bool False when a posted value could not be stored.
	 */
	public function save( string $slug ): bool {
		$ok = true;
		foreach ( AddonRegistry::instance()->settings_fields( $slug ) as $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by IntegrationControlsAdmin::handle_save().
			if ( ! isset( $_POST[ $field->key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by the field's own callback next.
			$raw   = wp_unslash( $_POST[ $field->key ] );
			$clean = call_user_func( $field->sanitizer(), $raw );
			// Absent is not the same as stored-false: a default-on toggle switched off must be written.
			$current = get_option( $field->key, "\0absent" );
			if ( "\0absent" === $current || (string) $current !== (string) $clean ) {
				$ok = update_option( $field->key, $clean ) && $ok;
			}
		}
		return $ok;
	}
}
