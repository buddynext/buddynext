<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Signup template (v2 design system).
 *
 * Dedicated signup surface — posts to POST /buddynext/v1/auth/register.
 * Inline validation per field. Password strength meter. On success the
 * server returns the redirect target (verify-email page when email
 * verification is enabled, otherwise the onboarding wizard).
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Closed-registration redirect is enforced upstream in
// PageRouter::dispatch_hub_template() so it fires before wp_head().

$rest_root  = esc_url_raw( rest_url( 'buddynext/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
$login_url  = \BuddyNext\Core\PageRouter::auth_url();
// Terms links to an admin-chosen page (Settings → Registration → Legal Pages) —
// never a guessed slug. Privacy reuses WordPress core's Privacy Policy page
// (Settings → Privacy), so it works out of the box. Either link, when its page
// is not configured, renders as plain text in the consent line, not a broken
// link.
$bn_terms_page = (int) get_option( 'buddynext_terms_page_id', 0 );
$terms_url     = $bn_terms_page > 0 ? (string) get_permalink( $bn_terms_page ) : '';

// get_privacy_policy_url() only returns a URL for a PUBLISHED page. WordPress
// creates the Privacy Policy page as a draft, so fall back to the mapped page's
// permalink — a page the owner has mapped should link even before it is
// published (they will publish it) rather than silently dropping to plain text.
$privacy_url = (string) get_privacy_policy_url();
if ( '' === $privacy_url ) {
	$bn_privacy_page = (int) get_option( 'wp_page_for_privacy_policy', 0 );
	$privacy_url     = $bn_privacy_page > 0 ? (string) get_permalink( $bn_privacy_page ) : '';
}

// In-house spam guard fields (no third-party captcha): a signed time-trap
// token, a rotating honeypot field name, and an optional human-check question.
// Social sign-in providers (same seam the login screen uses); shown when the
// admin has enabled and configured at least one provider.
$social_providers = (array) apply_filters( 'buddynext_auth_social_providers', array() );

$bn_honeypot_name = \BuddyNext\Auth\RegistrationGuard::honeypot_field();
$bn_reg_token     = \BuddyNext\Auth\RegistrationGuard::issue_token();
$bn_challenge_on  = \BuddyNext\Auth\RegistrationGuard::challenge_enabled();
$bn_challenge     = $bn_challenge_on
	? \BuddyNext\Auth\RegistrationGuard::issue_challenge()
	: array(
		'question' => '',
		'token'    => '',
	);

// Invite-only mode: the REST submit already 403s without a valid invite, but
// the form should not even render — show an invite-required notice unless the
// visitor arrived with a valid, unconsumed invitation token. Mirrors the
// AuthController::register() gate so the two never disagree.
$bn_reg_mode = (string) get_option( 'buddynext_reg_mode', 'open' );
if ( 'invite' === $bn_reg_mode ) {
	$bn_invite_token = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bn_invite       = '' !== $bn_invite_token ? ( new \BuddyNext\Onboarding\InviteService() )->get_by_token( $bn_invite_token ) : null;
	if ( null === $bn_invite ) {
		?>
		<div class="bn-auth-page">
			<div class="bn-auth-shell" data-panel="<?php echo (bool) get_option( 'buddynext_auth_panel_show', true ) ? 'on' : 'off'; ?>">
			<?php buddynext_get_template( 'auth/parts/auth-aside.php', array() ); ?>
			<div class="bn-auth-card" data-variant="register">
				<div class="bn-auth-body">
					<section class="bn-auth-panel" data-active>
						<h1 class="bn-auth-title"><?php esc_html_e( 'Registration is invite-only', 'buddynext' ); ?></h1>
						<p class="bn-auth-sub"><?php esc_html_e( 'This community is invite-only. You need a valid invitation link to create an account.', 'buddynext' ); ?></p>
						<a class="bn-btn" data-variant="primary" data-size="lg" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() ); ?>">
							<?php esc_html_e( 'Back to sign in', 'buddynext' ); ?>
						</a>
					</section>
				</div>
			</div>
			</div>
		</div>
		<?php
		return;
	}
}
?>

<div class="bn-auth-page">
	<div class="bn-auth-shell" data-panel="<?php echo (bool) get_option( 'buddynext_auth_panel_show', true ) ? 'on' : 'off'; ?>">
	<?php buddynext_get_template( 'auth/parts/auth-aside.php', array() ); ?>
	<div class="bn-auth-card"
		data-variant="register"
		data-wp-interactive="buddynext/auth-signup"
		<?php
		// Pre-fill the email for an invited signup so the member doesn't re-type the
		// address the invitation was sent to (already known + validated). $bn_invite is
		// only set in invite-only mode; default to empty otherwise. Seeded into BOTH the
		// store context (below) and the input's value attribute (the field is an
		// uncontrolled data-wp-on--input, so the context alone won't paint it).
		$bn_prefill_email = ( isset( $bn_invite ) && is_array( $bn_invite ) && ! empty( $bn_invite['email'] ) )
			? (string) $bn_invite['email']
			: '';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_interactivity_data_wp_context(
			array(
				'email'            => $bn_prefill_email,
				'userLogin'        => '',
				'password'         => '',
				'termsAgreed'      => false,
				'passwordStrength' => 0,
				'strengthLabel'    => '',
				'submitting'       => false,
				'error'            => '',
				'fieldErrors'      => array(),
				'restNonce'        => $rest_nonce,
				'restUrl'          => $rest_root,
				'honeypotName'     => $bn_honeypot_name,
				'honeypot'         => '',
				'regToken'         => $bn_reg_token,
				'challengeEnabled' => (bool) $bn_challenge_on,
				'challengeToken'   => (string) $bn_challenge['token'],
				'challengeAnswer'  => '',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		>

		<div class="bn-auth-body">
			<section class="bn-auth-panel" data-active>
				<?php buddynext_get_template( 'auth/parts/auth-form-logo.php', array() ); ?>
				<h1 class="bn-auth-title"><?php esc_html_e( 'Join the community', 'buddynext' ); ?></h1>
				<p class="bn-auth-sub"><?php esc_html_e( 'Free forever. No credit card required.', 'buddynext' ); ?></p>

				<div class="bn-auth-field__msg" role="alert" aria-live="polite"
					data-wp-bind--hidden="!state.error"
					data-wp-text="state.error"></div>

				<form class="bn-auth-form"
					novalidate
					data-wp-on--submit="actions.submitSignup">

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-signup-email">
							<?php esc_html_e( 'Email', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="email"
							id="bn-signup-email"
							name="email"
							autocomplete="email"
							placeholder="you@example.com"
							value="<?php echo esc_attr( $bn_prefill_email ); ?>"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-bind--aria-invalid="state.emailInvalid"
							data-wp-on--input="actions.setEmail" />
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.emailError"
							data-wp-text="state.emailError"></span>
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-signup-username">
							<?php esc_html_e( 'Username', 'buddynext' ); ?>
						</label>
						<input class="bn-input"
							type="text"
							id="bn-signup-username"
							name="user_login"
							autocomplete="username"
							placeholder="@username"
							aria-describedby="bn-signup-username-hint"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-bind--aria-invalid="state.usernameInvalid"
							data-wp-on--input="actions.setUserLogin" />
						<span class="bn-auth-hint" id="bn-signup-username-hint">
							<?php esc_html_e( '3–24 characters: letters, numbers, underscore.', 'buddynext' ); ?>
						</span>
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.usernameError"
							data-wp-text="state.usernameError"></span>
					</div>

					<div class="bn-auth-field">
						<label class="bn-auth-label" for="bn-signup-password">
							<?php esc_html_e( 'Password', 'buddynext' ); ?>
						</label>
						<div class="bn-auth-pw">
						<input class="bn-input bn-auth-pw__input"
							type="password"
							id="bn-signup-password"
							name="password"
							autocomplete="new-password"
							placeholder="<?php esc_attr_e( 'Choose a strong password', 'buddynext' ); ?>"
							aria-describedby="bn-signup-password-meter"
							required
							data-wp-bind--disabled="state.submitting"
							data-wp-bind--aria-invalid="state.passwordInvalid"
							data-wp-on--input="actions.setPassword" />
						<button type="button" class="bn-auth-pw__toggle"
							data-bn-pw-toggle
							aria-controls="bn-signup-password"
							aria-pressed="false"
							aria-label="<?php esc_attr_e( 'Show password', 'buddynext' ); ?>"
							data-show-label="<?php esc_attr_e( 'Show', 'buddynext' ); ?>"
							data-hide-label="<?php esc_attr_e( 'Hide', 'buddynext' ); ?>"
							data-show-aria="<?php esc_attr_e( 'Show password', 'buddynext' ); ?>"
							data-hide-aria="<?php esc_attr_e( 'Hide password', 'buddynext' ); ?>"><?php esc_html_e( 'Show', 'buddynext' ); ?></button>
						</div>
						<div class="bn-auth-strength" id="bn-signup-password-meter" aria-live="polite">
							<div class="bn-progress"
								role="progressbar"
								aria-valuemin="0"
								aria-valuemax="4"
								data-wp-bind--aria-valuenow="context.passwordStrength">
								<div class="bn-progress__fill"
									data-wp-style--width="state.strengthWidth"></div>
							</div>
							<span class="bn-auth-strength__label"
								data-wp-text="state.strengthLabelText"></span>
						</div>
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.passwordError"
							data-wp-text="state.passwordError"></span>
					</div>

					<?php
					// Custom profile fields the owner opted into the registration form
					// (Profile Fields -> "Ask on registration") plus any registered
					// programmatically via buddynext_register_profile_field(). Rendered
					// through the field-type engine; collected + validated server-side
					// in AuthController::register(). The signup store forwards every
					// [name^="bn_field_"] input in the form to the REST body.
					$bn_reg_fields = array();
					if ( function_exists( 'buddynext_service' ) ) {
						try {
							$bn_pf_service = buddynext_service( 'profiles' );
							if ( is_object( $bn_pf_service ) && method_exists( $bn_pf_service, 'get_registration_fields' ) ) {
								$bn_reg_fields = $bn_pf_service->get_registration_fields();
							}
						} catch ( \Throwable $bn_e ) {
							$bn_reg_fields = array();
						}
					}
					foreach ( $bn_reg_fields as $bn_reg_field ) :
						$bn_rf_key   = (string) $bn_reg_field['field_key'];
						$bn_rf_id    = 'bn-signup-field-' . sanitize_html_class( $bn_rf_key );
						$bn_rf_name  = 'bn_field_' . $bn_rf_key;
						$bn_rf_req   = ! empty( $bn_reg_field['is_required'] );
						$bn_rf_input = \BuddyNext\Profile\FieldType::render_input( $bn_reg_field, '', $bn_rf_name );
						// Tag the rendered control so the store can find + forward it,
						// and carry id/required for label association + native validation.
						$bn_rf_input = str_replace(
							'<input ',
							'<input id="' . esc_attr( $bn_rf_id ) . '" data-bn-reg-field ' . ( $bn_rf_req ? 'required ' : '' ),
							$bn_rf_input
						);
						$bn_rf_input = str_replace(
							array( '<select ', '<textarea ' ),
							array(
								'<select id="' . esc_attr( $bn_rf_id ) . '" data-bn-reg-field ' . ( $bn_rf_req ? 'required ' : '' ),
								'<textarea id="' . esc_attr( $bn_rf_id ) . '" data-bn-reg-field ' . ( $bn_rf_req ? 'required ' : '' ),
							),
							$bn_rf_input
						);
						?>
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="<?php echo esc_attr( $bn_rf_id ); ?>">
								<?php echo esc_html( (string) $bn_reg_field['label'] ); ?>
								<?php if ( $bn_rf_req ) : ?>
									<span class="bn-auth-required" aria-hidden="true">*</span>
								<?php endif; ?>
							</label>
							<?php
							// FieldType::render_input returns escaped, type-safe markup.
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
							echo $bn_rf_input;
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					<?php endforeach; ?>

					<div class="bn-auth-field bn-auth-field--check">
						<label class="bn-auth-check">
							<input type="checkbox"
								name="terms_agreed"
								data-wp-bind--checked="context.termsAgreed"
								data-wp-bind--disabled="state.submitting"
								data-wp-on--change="actions.toggleTerms" />
							<span>
								<?php
								// Link each legal page only when configured; otherwise show its
								// label as plain text so the consent reads correctly with no
								// broken links.
								$bn_terms_label   = esc_html__( 'Terms of Service', 'buddynext' );
								$bn_privacy_label = esc_html__( 'Privacy Policy', 'buddynext' );
								$bn_terms_html    = '' !== $terms_url
									? '<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . $bn_terms_label . '</a>'
									: $bn_terms_label;
								$bn_privacy_html  = '' !== $privacy_url
									? '<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . $bn_privacy_label . '</a>'
									: $bn_privacy_label;
								echo wp_kses(
									sprintf(
										/* translators: 1: Terms of Service (link or text), 2: Privacy Policy (link or text) */
										__( 'I agree to the %1$s and %2$s.', 'buddynext' ),
										$bn_terms_html,
										$bn_privacy_html
									),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								);
								?>
							</span>
						</label>
						<span class="bn-auth-field__msg"
							data-wp-bind--hidden="!state.termsError"
							data-wp-text="state.termsError"></span>
					</div>

					<?php if ( $bn_challenge_on ) : ?>
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-signup-challenge">
								<?php echo esc_html( (string) $bn_challenge['question'] ); ?>
							</label>
							<input class="bn-input"
								type="text"
								id="bn-signup-challenge"
								name="challenge_answer"
								inputmode="numeric"
								autocomplete="off"
								required
								aria-describedby="bn-signup-challenge-hint"
								data-wp-bind--disabled="state.submitting"
								data-wp-bind--aria-invalid="state.challengeInvalid"
								data-wp-on--input="actions.setChallengeAnswer" />
							<span class="bn-auth-hint" id="bn-signup-challenge-hint">
								<?php esc_html_e( 'A quick check to keep out automated sign-ups.', 'buddynext' ); ?>
							</span>
							<span class="bn-auth-field__msg"
								data-wp-bind--hidden="!state.challengeError"
								data-wp-text="state.challengeError"></span>
						</div>
					<?php endif; ?>

					<?php /* Honeypot: hidden from people, irresistible to bots. */ ?>
					<div class="bn-auth-hp" aria-hidden="true">
						<label for="bn-signup-<?php echo esc_attr( $bn_honeypot_name ); ?>"><?php esc_html_e( 'Leave this field empty', 'buddynext' ); ?></label>
						<input type="text"
							id="bn-signup-<?php echo esc_attr( $bn_honeypot_name ); ?>"
							name="<?php echo esc_attr( $bn_honeypot_name ); ?>"
							tabindex="-1"
							autocomplete="off"
							data-wp-on--input="actions.setHoneypot" />
					</div>

					<button class="bn-btn"
						data-variant="primary"
						data-size="lg"
						data-full
						type="submit"
						data-wp-bind--disabled="state.submitDisabled">
						<span data-wp-bind--hidden="state.submitting"><?php esc_html_e( 'Create account', 'buddynext' ); ?></span>
						<span data-wp-bind--hidden="!state.submitting"><?php esc_html_e( 'Creating account...', 'buddynext' ); ?></span>
						<?php buddynext_icon( 'arrow-right' ); ?>
					</button>
				</form>

				<?php if ( ! empty( $social_providers ) ) : ?>
					<div class="bn-auth-divider"><?php esc_html_e( 'or sign up with', 'buddynext' ); ?></div>
					<div class="bn-auth-social">
						<?php
						foreach ( $social_providers as $provider ) :
							$pid    = isset( $provider['id'] ) ? sanitize_key( $provider['id'] ) : '';
							$plabel = isset( $provider['label'] ) ? (string) $provider['label'] : '';
							$picon  = isset( $provider['icon'] ) ? sanitize_key( $provider['icon'] ) : 'globe';
							$purl   = isset( $provider['url'] ) ? esc_url_raw( $provider['url'] ) : '';
							if ( '' === $pid || '' === $purl ) {
								continue;
							}
							?>
							<a class="bn-btn" data-variant="secondary" data-size="lg"
								href="<?php echo esc_url( $purl ); ?>"
								aria-label="
								<?php
								/* translators: %s: provider name (e.g. Google). */
								echo esc_attr( sprintf( __( 'Continue with %s', 'buddynext' ), $plabel ) );
								?>
								">
								<?php buddynext_icon( $picon ); ?>
								<span><?php echo esc_html( $plabel ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="bn-auth-foot">
					<?php esc_html_e( 'Already have an account?', 'buddynext' ); ?>
					<a href="<?php echo esc_url( $login_url ); ?>">
						<?php esc_html_e( 'Sign in', 'buddynext' ); ?>
					</a>
				</div>
			</section>
		</div>
	</div>
	</div>
</div>
