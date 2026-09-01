<?php
/**
 * Settings → Account.
 *
 * The account-management hub: connected social accounts, the account fields
 * card (profile URL, email change, password change, two-factor authentication,
 * notification schedule, sign out everywhere), and the danger zone (delete
 * account). Every action is a self-saving REST modal driven by the
 * buddynext/profile Interactivity store, so this page needs no <form> or
 * save-bar — just the interactive wrapper and its full context. Relocated here
 * from the profile editor.
 *
 * Overridable: copy to {theme}/buddynext/settings/account.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id       = get_current_user_id();
$twofa_enabled = \BuddyNext\Auth\TwoFactorService::is_enabled( $user_id );

// Is this member being HELD here? TwoFactorService::enforce_enrolment() bounces
// anyone whose role requires 2FA to this screen until they enrol. Without this
// banner they simply arrive: they click Activity, land on Settings, and nothing
// says why - which reads as a broken link, not a security hold.
//
// Derived from state rather than a query arg on purpose: it survives a refresh,
// shows however they reached the page, and cannot be spoofed by pasting a URL.
$bn_user_obj  = wp_get_current_user();
$bn_2fa_held  = ! $twofa_enabled
	&& $bn_user_obj instanceof \WP_User
	&& \BuddyNext\Auth\TwoFactorService::is_required_for( $bn_user_obj );
$profile_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
$profile_url  = \BuddyNext\Core\PageRouter::profile_url( $user_id );
?>
<div class="bn-settings">
	<?php buddynext_get_template( 'parts/settings-nav.php', array( 'bn_settings_active' => 'account' ) ); ?>
	<?php if ( $bn_2fa_held ) : ?>
		<div class="bn-settings-hold" role="status">
			<span class="bn-settings-hold__icon" aria-hidden="true"><?php buddynext_icon( 'shield' ); ?></span>
			<div class="bn-settings-hold__body">
				<strong class="bn-settings-hold__title"><?php esc_html_e( 'Two-factor authentication is required', 'buddynext' ); ?></strong>
				<p class="bn-settings-hold__text">
					<?php esc_html_e( 'Your account needs a second sign-in step before you can use the rest of the community. Set it up under Two-factor authentication below.', 'buddynext' ); ?>
				</p>
			</div>
		</div>
	<?php endif; ?>
	<div data-wp-interactive="buddynext/profile"
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() returns an escaped data-wp-context attribute string built by WP core.
		echo wp_interactivity_data_wp_context(
			array(
				'userId'                   => $user_id,
				'restNonce'                => wp_create_nonce( 'wp_rest' ),
				'saved'                    => false,
				'saving'                   => false,
				'isDirty'                  => false,
				'errors'                   => (object) array(),
				'profileSlug'              => $profile_slug,
				// The normalised value as typed, so the @handle preview updates live.
				'slugDraft'                => $profile_slug,
				'profileUrl'               => $profile_url,
				'slugAvailable'            => null,
				'slugChecking'             => false,
				'slugSaved'                => false,
				'slugSaving'               => false,
				'deleteOpen'               => false,
				'deleteText'               => '',
				'emailChangeOpen'          => false,
				'emailChangeSubmitting'    => false,
				'passwordChangeOpen'       => false,
				'passwordChangeSubmitting' => false,
				'passwordStrength'         => 0,
				'passwordStrengthLabel'    => '',
				'signOutSubmitting'        => false,
				'twofaEnabled'             => $twofa_enabled,
				'twofaBackupRemaining'     => \BuddyNext\Auth\TwoFactorService::backup_codes_remaining( $user_id ),
				'twofaStage'               => 'idle',
				'twofaSecret'              => '',
				'twofaUri'                 => '',
				'twofaCode'                => '',
				'twofaPassword'            => '',
				'twofaError'               => '',
				'twofaBusy'                => false,
				'twofaBackupCodes'         => array(),
				'twofaPanelOpen'           => false,
			)
		);
		?>
	>
		<div class="bn-settings__section">
			<?php
			buddynext_get_template( 'parts/settings-connected-accounts.php', array() );
			buddynext_get_template( 'parts/settings-account-fields.php', array() );
			// Account deletion lives on Settings → Privacy (the single, gated
			// "Delete my account" surface). The old danger-zone here was wired to
			// actions that no longer exist and was ungated, so it has been removed.
			?>
		</div>

	</div>
</div>
