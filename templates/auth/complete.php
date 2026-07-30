<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Finish sign-up template (v2 design system).
 *
 * Where a social sign-up lands when the provider could not supply everything the
 * owner requires. OAuth gives us an email and a name; it cannot give us terms
 * consent or the profile fields marked required at registration.
 *
 * No account exists at this point. That is deliberate: creating the member first
 * and patching the gaps afterwards is exactly what let a visitor defeat
 * admin-approval mode by clicking the social button twice. The provider profile
 * is parked in a short-lived PendingSignup and only becomes a member when this
 * form is submitted.
 *
 * Posts to POST /buddynext/v1/auth/register/complete.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Auth\PendingSignup;
use BuddyNext\Auth\RegistrationPolicy;
use BuddyNext\Profile\FieldType;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token lookup; the token is the credential.
$bn_pending_token = isset( $_GET['bn_pending'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['bn_pending'] ) ) : '';
$bn_pending       = PendingSignup::get( $bn_pending_token );

$rest_root  = esc_url_raw( rest_url( 'buddynext/v1/' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
$signup_url = \BuddyNext\Core\PageRouter::signup_url();
?>

<div class="bn-auth-page">
	<div class="bn-auth-shell" data-panel="<?php echo (bool) get_option( 'buddynext_auth_panel_show', true ) ? 'on' : 'off'; ?>">
	<?php buddynext_get_template( 'auth/parts/auth-aside.php', array() ); ?>

	<?php if ( null === $bn_pending ) : ?>
		<div class="bn-auth-card" data-variant="register">
			<div class="bn-auth-body">
				<section class="bn-auth-panel" data-active>
					<?php buddynext_get_template( 'auth/parts/auth-form-logo.php', array() ); ?>
					<h1 class="bn-auth-title"><?php esc_html_e( 'This sign-up link has expired', 'buddynext' ); ?></h1>
					<p class="bn-auth-sub">
						<?php esc_html_e( 'Sign-up links are short-lived for your security. Start again and it will only take a moment.', 'buddynext' ); ?>
					</p>
					<a class="bn-btn bn-btn--primary" href="<?php echo esc_url( $signup_url ); ?>">
						<?php esc_html_e( 'Back to sign-up', 'buddynext' ); ?>
					</a>
				</section>
			</div>
		</div>
		</div>
	</div>
		<?php
		return;
	endif;

	$bn_policy   = buddynext_service( 'registration_policy' );
	$bn_required = $bn_policy->requirements();
	$bn_missing  = $bn_policy->missing( array( 'email' => $bn_pending['email'] ) );

	// Only ask for what is actually outstanding — never re-ask for what the provider
	// already gave us, and never show a terms box the owner switched off.
	$bn_needs_terms  = in_array( 'terms', $bn_missing, true );
	$bn_needs_fields = array();
	foreach ( $bn_required['fields'] as $bn_field ) {
		if ( in_array( 'bn_field_' . $bn_field['field_key'], $bn_missing, true ) ) {
			$bn_needs_fields[] = $bn_field;
		}
	}

	$bn_terms_page = (int) get_option( 'buddynext_terms_page_id', 0 );
	$bn_terms_url  = $bn_terms_page > 0 ? (string) get_permalink( $bn_terms_page ) : '';

	$bn_privacy_url = (string) get_privacy_policy_url();
	if ( '' === $bn_privacy_url ) {
		$bn_privacy_page = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$bn_privacy_url  = $bn_privacy_page > 0 ? (string) get_permalink( $bn_privacy_page ) : '';
	}
	?>

		<div class="bn-auth-card"
			data-variant="register"
			data-wp-interactive="buddynext/auth-signup"
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_interactivity_data_wp_context(
				array(
					'mode'         => 'complete',
					'pendingToken' => $bn_pending_token,
					'email'        => (string) $bn_pending['email'],
					'termsAgreed'  => false,
					'submitting'   => false,
					'error'        => '',
					'fieldErrors'  => array(),
					'restNonce'    => $rest_nonce,
					'restUrl'      => $rest_root,
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			>

			<div class="bn-auth-body">
				<section class="bn-auth-panel" data-active>
					<?php buddynext_get_template( 'auth/parts/auth-form-logo.php', array() ); ?>

					<h1 class="bn-auth-title"><?php esc_html_e( 'Almost there', 'buddynext' ); ?></h1>
					<p class="bn-auth-sub">
						<?php
						printf(
							/* translators: %s: the member's email address from their social provider. */
							esc_html__( 'We just need a little more before we create your account for %s.', 'buddynext' ),
							'<strong>' . esc_html( (string) $bn_pending['email'] ) . '</strong>'
						);
						?>
					</p>

					<div class="bn-auth-field__msg" role="alert" aria-live="polite"
						data-wp-bind--hidden="!state.error"
						data-wp-text="state.error"></div>

					<form class="bn-auth-form"
						novalidate
						data-wp-on--submit="actions.submitComplete">

						<?php foreach ( $bn_needs_fields as $bn_rf ) : ?>
							<?php
							// Skip fields inactive for the not-yet-created account (e.g.
							// plan-gated advanced types). This form force-injects
							// `required` below, so an inactive field here would trap the
							// prospect both ways: empty fails the client-side required,
							// filled fails the server's entitlement gate.
							if ( ! FieldType::is_profile_field_active( $bn_rf, 0 ) ) {
								continue;
							}
							$bn_rf_key   = (string) $bn_rf['field_key'];
							$bn_rf_name  = 'bn_field_' . $bn_rf_key;
							$bn_rf_id    = 'bn-complete-' . $bn_rf_key;
							$bn_rf_input = FieldType::render_input( $bn_rf, '', $bn_rf_name );

							// Tag the control so the shared signup store collects it, and
							// mark it required for assistive tech. Radio groups already
							// carry their own ids from render_input, so only inject an id
							// where the control is a single input.
							$bn_rf_input = preg_replace(
								'/<(input|select|textarea)\s/',
								'<$1 data-bn-reg-field="' . esc_attr( $bn_rf_key ) . '" required aria-required="true" ',
								$bn_rf_input,
								1
							);
							?>
							<div class="bn-auth-field">
								<label class="bn-auth-label" for="<?php echo esc_attr( $bn_rf_id ); ?>">
									<?php echo esc_html( (string) $bn_rf['label'] ); ?>
									<span class="bn-auth-required" aria-hidden="true">*</span>
								</label>
								<?php
								// FieldType::render_input returns escaped, type-safe markup.
								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $bn_rf_input;
								// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
								<?php if ( '' !== (string) ( $bn_rf['description'] ?? '' ) ) : ?>
									<span class="bn-auth-hint"><?php echo esc_html( (string) $bn_rf['description'] ); ?></span>
								<?php endif; ?>
								<span class="bn-auth-field__msg"
									data-wp-bind--hidden="!state.fieldError_<?php echo esc_attr( $bn_rf_name ); ?>"
									data-wp-text="state.fieldError_<?php echo esc_attr( $bn_rf_name ); ?>"></span>
							</div>
						<?php endforeach; ?>

						<?php if ( $bn_needs_terms ) : ?>
							<div class="bn-auth-field bn-auth-field--check">
								<label class="bn-auth-check">
									<input type="checkbox"
										name="terms_agreed"
										data-wp-bind--checked="context.termsAgreed"
										data-wp-bind--disabled="state.submitting"
										data-wp-on--change="actions.toggleTerms" />
									<span>
										<?php
										$bn_terms_label   = esc_html__( 'Terms of Service', 'buddynext' );
										$bn_privacy_label = esc_html__( 'Privacy Policy', 'buddynext' );
										$bn_terms_html    = '' !== $bn_terms_url
											? '<a href="' . esc_url( $bn_terms_url ) . '" target="_blank" rel="noopener">' . $bn_terms_label . '</a>'
											: $bn_terms_label;
										$bn_privacy_html  = '' !== $bn_privacy_url
											? '<a href="' . esc_url( $bn_privacy_url ) . '" target="_blank" rel="noopener">' . $bn_privacy_label . '</a>'
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
													'href' => array(),
													'target' => array(),
													'rel'  => array(),
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
						<?php endif; ?>

						<button class="bn-btn bn-btn--primary bn-btn--block"
							type="submit"
							data-wp-bind--disabled="state.submitting">
							<span data-wp-bind--hidden="state.submitting"><?php esc_html_e( 'Create my account', 'buddynext' ); ?></span>
							<span data-wp-bind--hidden="!state.submitting"><?php esc_html_e( 'Creating account…', 'buddynext' ); ?></span>
						</button>
					</form>
				</section>
			</div>
		</div>
	</div>
</div>
