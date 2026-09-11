<?php
/**
 * Settings → Privacy.
 *
 * Audience/gate selects, preference toggles, and the blocked/restricted/muted
 * manager — relocated here from the profile editor. ONE save model: every
 * control persists the instant it changes — the audience selects through
 * actions.savePrivacyField, the boolean toggles through actions.togglePref —
 * so there is no Save/Cancel bar (matching the notifications per-space card's
 * "Saves immediately"). The wrapper stays a buddynext/profile Interactivity
 * region because those actions read its context (rest nonce, user id).
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
				'userId'    => $user_id,
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	>
		<div class="bn-settings__section">
			<?php
			buddynext_get_template( 'parts/settings-privacy-fields.php', array() );
			buddynext_get_template( 'parts/settings-relations.php', array() );
			?>
		</div>
	</div>
</div>
