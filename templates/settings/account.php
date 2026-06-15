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
			// The danger-zone part renders nothing without an `actions` descriptor
			// (it early-returns), so pass the same Delete-account action the editor did.
			buddynext_get_template(
				'parts/profile-edit-danger-zone.php',
				array(
					'actions' => array(
						array(
							'id'       => 'delete-account',
							'label'    => __( 'Delete account', 'buddynext' ),
							'tone'     => 'danger',
							'size'     => 'md',
							'action'   => 'actions.openDelete',
							'modal_id' => 'bn-ep-delete',
						),
					),
				)
			);
			?>
		</div>

		<!--
			Delete-account modal — moved here with its owner (the danger-zone).
			It lives inside the interactive wrapper (so actions.openDelete /
			confirmDelete / updateDeleteText and data-wp-bind--hidden bind) but
			outside any form — this page has none.
		-->
		<div class="bn-modal-backdrop bn-ep-delete-backdrop" role="dialog" aria-modal="true"
			aria-labelledby="bn-ep-delete-title" data-wp-bind--hidden="!context.deleteOpen">
			<div class="bn-modal__panel" data-tone="danger" data-size="sm">
				<header class="bn-modal__head">
					<h2 class="bn-modal__title" id="bn-ep-delete-title"><?php esc_html_e( 'Delete account?', 'buddynext' ); ?></h2>
					<button class="bn-modal__close" type="button"
						aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
						data-wp-on--click="actions.closeDelete"><?php buddynext_icon( 'x' ); ?></button>
				</header>
				<div class="bn-modal__body">
					<p><?php esc_html_e( 'This permanently deletes your profile, posts, replies, follows, and uploaded media. This cannot be undone.', 'buddynext' ); ?></p>
					<div class="bn-ep-field">
						<label class="bn-ep-label" for="bn-ep-delete-confirm">
							<?php
							printf(
								/* translators: %s: required confirmation word */
								esc_html__( 'Type %s to confirm', 'buddynext' ),
								'<strong>DELETE</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							);
							?>
						</label>
						<input class="bn-input" type="text" id="bn-ep-delete-confirm" autocomplete="off" spellcheck="false"
							data-wp-on--input="actions.updateDeleteText" />
					</div>
				</div>
				<footer class="bn-modal__foot">
					<button class="bn-btn" type="button" data-variant="ghost" data-size="md"
						data-wp-on--click="actions.closeDelete"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
					<button class="bn-btn" type="button" data-variant="danger" data-size="md"
						data-wp-bind--disabled="context.deleteText !== 'DELETE'"
						data-wp-on--click="actions.confirmDelete"><?php esc_html_e( 'Delete account', 'buddynext' ); ?></button>
				</footer>
			</div>
		</div>
	</div>
</div>
