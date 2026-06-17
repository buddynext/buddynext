<?php
/**
 * BuddyNext template part: settings → account fields.
 *
 * Self-contained relocation of the "Section: Account" card from the profile
 * editor. Renders the profile-URL slug field plus the email-change,
 * password-change, two-factor-authentication, notification-digest cross-link,
 * and sign-out rows. Every interactive control keeps its original
 * data-wp-on--* / data-wp-bind--* / id / name attributes so the
 * buddynext/profile store's actions bind exactly as before.
 *
 * Computes every variable it needs from the current user, so it requires no
 * variables from the caller. The host page must provide the interactive
 * wrapper (data-wp-interactive="buddynext/profile") and its context.
 *
 * Overridable: copy to {theme}/buddynext/parts/settings-account-fields.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id = get_current_user_id();

// Account-section setup vars, computed locally so this part is self-contained.
$twofa_enabled     = \BuddyNext\Auth\TwoFactorService::is_enabled( $user_id );
$profile_slug      = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
$profile_url       = \BuddyNext\Core\PageRouter::profile_url( $user_id );
$pending_email     = (string) get_user_meta( $user_id, 'bn_pending_email', true );
$rest_nonce        = wp_create_nonce( 'wp_rest' );
$people_url_base   = rtrim( \BuddyNext\Core\PageRouter::people_url(), '/' );
$prefs_url         = \BuddyNext\Core\PageRouter::notification_prefs_url();
$profile_email_raw = wp_get_current_user()->user_email;
?>

<!-- Section: Account -->
<section class="bn-card bn-ep-card">
	<header class="bn-ep-card-header">
		<h2 class="bn-ep-card-title"><?php esc_html_e( 'Account', 'buddynext' ); ?></h2>
	</header>
	<div class="bn-ep-card-body bn-ep-account-rows">
		<!-- Profile URL row (slug field — too composite to fit the generic account-row part) -->
		<div class="bn-ep-field bn-ep-field--full bn-ep-slug-row">
			<label class="bn-ep-label" for="bn-ep-slug">
				<?php esc_html_e( 'Profile URL', 'buddynext' ); ?>
			</label>
			<div class="bn-ep-slug-field">
				<span class="bn-ep-slug-base">
					<?php echo esc_html( $people_url_base ); ?>/
				</span>
				<div class="bn-ep-slug-input-wrap">
					<input class="bn-input bn-ep-slug-input"
						type="text"
						id="bn-ep-slug"
						autocomplete="off"
						spellcheck="false"
						value="<?php echo esc_attr( $profile_slug ); ?>"
						placeholder="<?php esc_attr_e( 'your-custom-url', 'buddynext' ); ?>"
						aria-describedby="bn-ep-slug-status"
						data-wp-on--input="actions.checkSlug" />
					<span class="bn-ep-slug-indicator"
						id="bn-ep-slug-status"
						data-wp-bind--hidden="state.slugStatusHidden"
						data-wp-class--bn-ep-slug-ok="state.slugIsOk"
						data-wp-class--bn-ep-slug-err="state.slugIsTaken">
						<span data-wp-bind--hidden="!state.slugIsOk"><?php buddynext_icon( 'check' ); ?></span>
						<span data-wp-bind--hidden="!state.slugIsTaken"><?php esc_html_e( 'Taken', 'buddynext' ); ?></span>
					</span>
				</div>
				<button class="bn-btn"
					type="button"
					data-variant="secondary"
					data-size="md"
					data-wp-on--click="actions.saveSlug"
					data-wp-bind--disabled="state.slugSaveDisabled">
					<span data-wp-bind--hidden="context.slugSaved"><?php esc_html_e( 'Update URL', 'buddynext' ); ?></span>
					<span data-wp-bind--hidden="!context.slugSaved"><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Saved', 'buddynext' ); ?></span>
				</button>
			</div>
		</div>

		<?php
		// Pending-email notice block (only when there's a pending change).
		$pending_html = '';
		if ( '' !== $pending_email ) {
			$pending_html = '<div class="bn-ep-account-pending">' . sprintf(
				/* translators: %s: pending email address */
				esc_html__( 'Pending verification: %s', 'buddynext' ),
				'<strong>' . esc_html( $pending_email ) . '</strong>'
			) . '</div>';
		}

		// Build the email inline form via output-buffered HTML.
		ob_start();
		?>
		<div class="bn-ep-field bn-ep-field--full">
			<label class="bn-ep-label" for="bn-ep-new-email"><?php esc_html_e( 'New email address', 'buddynext' ); ?></label>
			<input class="bn-input" type="email" id="bn-ep-new-email" autocomplete="email" data-wp-bind--aria-invalid="!!context.errors.email" data-wp-class--bn-input--error="!!context.errors.email" />
			<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.email" data-wp-bind--hidden="!context.errors.email"></span>
		</div>
		<div class="bn-ep-account-form-actions">
			<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.requestEmailChange" data-wp-bind--disabled="context.emailChangeSubmitting"><?php esc_html_e( 'Send verification email', 'buddynext' ); ?></button>
			<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.closeEmailChange"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
		</div>
		<?php
		$email_inline_form = (string) ob_get_clean();

		buddynext_get_template(
			'parts/profile-edit-account-row.php',
			array(
				'row_id'                   => 'email',
				'label'                    => __( 'Email address', 'buddynext' ),
				'value'                    => $profile_email_raw,
				'pending_html'             => $pending_html,
				'cta_label'                => __( 'Change', 'buddynext' ),
				'cta_action'               => 'actions.openEmailChange',
				'inline_form_html'         => $email_inline_form,
				'inline_form_visible_when' => 'context.emailChangeOpen',
			)
		);

		// Build the password inline form via output-buffered HTML.
		ob_start();
		?>
		<div class="bn-ep-field bn-ep-field--full">
			<label class="bn-ep-label" for="bn-ep-current-password"><?php esc_html_e( 'Current password', 'buddynext' ); ?></label>
			<input class="bn-input" type="password" id="bn-ep-current-password" autocomplete="current-password" data-wp-bind--aria-invalid="!!context.errors.current_password" data-wp-class--bn-input--error="!!context.errors.current_password" />
			<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.current_password" data-wp-bind--hidden="!context.errors.current_password"></span>
		</div>
		<div class="bn-ep-field bn-ep-field--full">
			<label class="bn-ep-label" for="bn-ep-new-password"><?php esc_html_e( 'New password', 'buddynext' ); ?></label>
			<input class="bn-input" type="password" id="bn-ep-new-password" autocomplete="new-password" data-wp-on--input="actions.measurePasswordStrength" data-wp-bind--aria-invalid="!!context.errors.new_password" data-wp-class--bn-input--error="!!context.errors.new_password" />
			<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.new_password" data-wp-bind--hidden="!context.errors.new_password"></span>
			<div class="bn-ep-strength" aria-live="polite" data-wp-bind--data-strength="context.passwordStrength"><span class="bn-ep-strength-bar"></span><span class="bn-ep-strength-label" data-wp-text="context.passwordStrengthLabel"></span></div>
		</div>
		<div class="bn-ep-field bn-ep-field--full">
			<label class="bn-ep-label" for="bn-ep-confirm-password"><?php esc_html_e( 'Confirm new password', 'buddynext' ); ?></label>
			<input class="bn-input" type="password" id="bn-ep-confirm-password" autocomplete="new-password" data-wp-bind--aria-invalid="!!context.errors.confirm_password" data-wp-class--bn-input--error="!!context.errors.confirm_password" />
			<span class="bn-ep-field-error" role="alert" data-wp-text="context.errors.confirm_password" data-wp-bind--hidden="!context.errors.confirm_password"></span>
		</div>
		<div class="bn-ep-account-form-actions">
			<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.changePassword" data-wp-bind--disabled="context.passwordChangeSubmitting"><?php esc_html_e( 'Update password', 'buddynext' ); ?></button>
			<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.closePasswordChange"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
		</div>
		<?php
		$pw_inline = (string) ob_get_clean();

		buddynext_get_template(
			'parts/profile-edit-account-row.php',
			array(
				'row_id'                   => 'password',
				'label'                    => __( 'Password', 'buddynext' ),
				'value'                    => __( 'Change your account password.', 'buddynext' ),
				'cta_label'                => __( 'Change', 'buddynext' ),
				'cta_action'               => 'actions.openPasswordChange',
				'inline_form_html'         => $pw_inline,
				'inline_form_visible_when' => 'context.passwordChangeOpen',
			)
		);

		// Two-factor authentication row (optional, opt-in). The inline
		// panel is a small reactive state machine: set-up → confirm →
		// backup codes, or manage (regenerate / turn off) when already on.
		ob_start();
		?>
		<div class="bn-2fa">
			<?php /* Stage: not enrolled — offer set-up. */ ?>
			<div class="bn-2fa-stage" data-wp-bind--hidden="!state.twofaShowStart">
				<p class="bn-2fa-desc"><?php esc_html_e( 'Use an authenticator app (Google Authenticator, Authy, 1Password, …) to generate a one-time code at sign-in. Backup codes cover you if you lose your device.', 'buddynext' ); ?></p>
				<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.startTwofaSetup" data-wp-bind--disabled="context.twofaBusy"><?php esc_html_e( 'Set up two-factor authentication', 'buddynext' ); ?></button>
			</div>

			<?php /* Stage: enrolling — show secret + confirm a code. */ ?>
			<div class="bn-2fa-stage" data-wp-bind--hidden="!state.twofaShowSetup">
				<p class="bn-2fa-desc"><?php esc_html_e( 'In your authenticator app, add an account by entering this setup key:', 'buddynext' ); ?></p>
				<code class="bn-2fa-secret" data-wp-text="context.twofaSecret"></code>
				<p class="bn-2fa-desc"><a data-wp-bind--href="context.twofaUri"><?php esc_html_e( 'Or tap here on your phone to add it automatically.', 'buddynext' ); ?></a></p>
				<div class="bn-ep-field bn-ep-field--full">
					<label class="bn-ep-label" for="bn-2fa-confirm-code"><?php esc_html_e( 'Enter the 6-digit code to confirm', 'buddynext' ); ?></label>
					<input class="bn-input" type="text" id="bn-2fa-confirm-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" data-wp-on--input="actions.setTwofaCode" data-wp-bind--value="context.twofaCode" />
					<span class="bn-ep-field-error" role="alert" data-wp-text="context.twofaError" data-wp-bind--hidden="!context.twofaError"></span>
				</div>
				<div class="bn-ep-account-form-actions">
					<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.confirmTwofa" data-wp-bind--disabled="context.twofaBusy"><?php esc_html_e( 'Verify and turn on', 'buddynext' ); ?></button>
					<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.cancelTwofa"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
				</div>
			</div>

			<?php /* Stage: show one-time backup codes. */ ?>
			<div class="bn-2fa-stage" data-wp-bind--hidden="!state.twofaShowBackup">
				<p class="bn-2fa-desc"><strong><?php esc_html_e( 'Save your backup codes.', 'buddynext' ); ?></strong> <?php esc_html_e( 'Each works once if you cannot use your authenticator. Store them somewhere safe — they will not be shown again.', 'buddynext' ); ?></p>
				<ul class="bn-2fa-codes">
					<template data-wp-each="context.twofaBackupCodes">
						<li data-wp-text="context.item"></li>
					</template>
				</ul>
				<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.finishTwofa"><?php esc_html_e( 'I have saved my codes', 'buddynext' ); ?></button>
			</div>

			<?php /* Stage: enabled — manage. */ ?>
			<div class="bn-2fa-stage" data-wp-bind--hidden="!state.twofaShowManage">
				<p class="bn-2fa-desc"><?php esc_html_e( 'Two-factor authentication is on.', 'buddynext' ); ?> <span data-wp-text="state.twofaBackupText"></span></p>
				<div class="bn-ep-field bn-ep-field--full">
					<label class="bn-ep-label" for="bn-2fa-password"><?php esc_html_e( 'Confirm your password to change these settings', 'buddynext' ); ?></label>
					<input class="bn-input" type="password" id="bn-2fa-password" autocomplete="current-password" data-wp-on--input="actions.setTwofaPassword" data-wp-bind--value="context.twofaPassword" />
					<span class="bn-ep-field-error" role="alert" data-wp-text="context.twofaError" data-wp-bind--hidden="!context.twofaError"></span>
				</div>
				<div class="bn-ep-account-form-actions">
					<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-wp-on--click="actions.regenerateBackup" data-wp-bind--disabled="context.twofaBusy"><?php esc_html_e( 'Regenerate backup codes', 'buddynext' ); ?></button>
					<button type="button" class="bn-btn" data-variant="danger" data-size="sm" data-wp-on--click="actions.disableTwofa" data-wp-bind--disabled="context.twofaBusy"><?php esc_html_e( 'Turn off', 'buddynext' ); ?></button>
				</div>
			</div>
		</div>
		<?php
		$twofa_inline = (string) ob_get_clean();

		buddynext_get_template(
			'parts/profile-edit-account-row.php',
			array(
				'row_id'                   => 'twofa',
				'label'                    => __( 'Two-factor authentication', 'buddynext' ),
				'value'                    => __( 'Require a one-time code at sign-in, in addition to your password.', 'buddynext' ),
				'cta_label'                => __( 'Manage', 'buddynext' ),
				'cta_action'               => 'actions.toggleTwofaPanel',
				'inline_form_html'         => $twofa_inline,
				'inline_form_visible_when' => 'context.twofaPanelOpen',
			)
		);

		// Notification digest cross-link row (anchor CTA) + Sign-out row.
		$account_rows_tail = array(
			array( 'notif_digest', __( 'Notification email schedule', 'buddynext' ), __( 'Configure how often we email you.', 'buddynext' ), __( 'Open notification preferences', 'buddynext' ), '', $prefs_url, '' ),
			array( 'sign_out', __( 'Active sessions', 'buddynext' ), __( 'Sign out of every browser and device this account is signed in on.', 'buddynext' ), __( 'Sign out everywhere', 'buddynext' ), 'actions.signOutEverywhere', '', 'context.signOutSubmitting' ),
		);
		foreach ( $account_rows_tail as $a ) {
			buddynext_get_template(
				'parts/profile-edit-account-row.php',
				array(
					'row_id'       => $a[0],
					'label'        => $a[1],
					'value'        => $a[2],
					'cta_label'    => $a[3],
					'cta_action'   => $a[4],
					'cta_href'     => $a[5],
					'cta_disabled' => $a[6],
				)
			);
		}
		?>
	</div>
</section><!-- /Account -->
