<?php
/**
 * Settings → Privacy.
 *
 * Audience/gate selects, preference toggles, and the blocked/restricted/muted
 * manager — relocated here from the profile editor. The audience SELECTS save
 * on form submit (via the sticky save bar), so the section is wrapped in a
 * buddynext/profile Interactivity form. The boolean TOGGLES save immediately
 * through actions.togglePref.
 *
 * `profileUrl` is intentionally empty so actions.saveProfile does NOT redirect
 * to the profile after saving — the member stays on this settings page.
 *
 * Overridable: copy to {theme}/buddynext/settings/privacy.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id = get_current_user_id();
?>
<div class="bn-settings">
	<?php buddynext_get_template( 'parts/settings-nav.php', array( 'bn_settings_active' => 'privacy' ) ); ?>
	<div data-wp-interactive="buddynext/profile"
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_interactivity_data_wp_context(
			array(
				'userId'     => $user_id,
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'saved'      => false,
				'saving'     => false,
				'isDirty'    => false,
				'errors'     => (object) array(),
				'profileUrl' => '',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	>
		<form class="bn-ep-form-shell"
			data-wp-on--submit="actions.saveProfile"
			data-wp-on--input="actions.markDirty"
			data-wp-on--change="actions.markDirty"
			novalidate>
			<div class="bn-settings__section">
				<?php
				buddynext_get_template( 'parts/settings-privacy-fields.php', array() );
				buddynext_get_template( 'parts/settings-relations.php', array() );
				?>
			</div>
			<?php buddynext_get_template( 'parts/profile-edit-save-bar.php', array() ); ?>
		</form>
	</div>
</div>
