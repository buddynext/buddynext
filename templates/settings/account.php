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
$profile_slug  = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
$profile_url   = \BuddyNext\Core\PageRouter::profile_url( $user_id );
?>
<div class="bn-settings">
	<?php buddynext_get_template( 'parts/settings-nav.php', array( 'bn_settings_active' => 'account' ) ); ?>
	<div data-wp-interactive="buddynext/profile"
		<?php
		echo wp_interactivity_data_wp_context(
			array(
				'userId'                 => $user_id,
				'restNonce'              => wp_create_nonce( 'wp_rest' ),
				'saved'                  => false,
				'saving'                 => false,
				'isDirty'                => false,
				'errors'                 => (object) array(),
				'profileSlug'            => $profile_slug,
				'profileUrl'             => $profile_url,
				'slugAvailable'          => null,
				'slugChecking'           => false,
				'slugSaved'              => false,
				'slugSaving'             => false,
				'deleteOpen'             => false,
				'deleteText'             => '',
				'emailChangeOpen'        => false,
				'emailChangeSubmitting'  => false,
				'passwordChangeOpen'     => false,
				'passwordChangeSubmitting' => false,
				'passwordStrength'       => 0,
				'passwordStrengthLabel'  => '',
				'signOutSubmitting'      => false,
				'twofaEnabled'           => $twofa_enabled,
				'twofaBackupRemaining'   => \BuddyNext\Auth\TwoFactorService::backup_codes_remaining( $user_id ),
				'twofaStage'             => 'idle',
				'twofaSecret'            => '',
				'twofaUri'               => '',
				'twofaCode'              => '',
				'twofaPassword'          => '',
				'twofaError'             => '',
				'twofaBusy'              => false,
				'twofaBackupCodes'       => array(),
				'twofaPanelOpen'         => false,
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
