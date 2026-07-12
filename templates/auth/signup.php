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

// What this owner's front door asks for. The same payload GET /auth/register/config serves,
// so the native app renders exactly the form the web does instead of guessing.
$bn_requirements = buddynext_service( 'registration_policy' )->requirements();

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
		// Dead end guard: the visitor has NO account (that's the whole point of this
		// screen), so "Back to sign in" is not a way out — it is the exit door. Give
		// them something they can actually do: ask for an invitation. The default is a
		// mailto to the site's admin_email with the request pre-written; owners who run
		// a waiting-list page or a form point the filter at it instead.
		$bn_admin_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		$bn_site_name   = wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES );

		$bn_request_url = '';
		if ( '' !== $bn_admin_email && is_email( $bn_admin_email ) ) {
			$bn_request_url = 'mailto:' . $bn_admin_email . '?subject=' . rawurlencode(
				sprintf(
					/* translators: %s: community (site) name. */
					__( 'Invitation request for %s', 'buddynext' ),
					$bn_site_name
				)
			) . '&body=' . rawurlencode(
				sprintf(
					/* translators: %s: community (site) name. */
					__( "Hello,\n\nI'd like to join %s but I don't have a valid invitation link. Could you send me one?\n\nThank you.", 'buddynext' ),
					$bn_site_name
				)
			);
		}

		/**
		 * Filter where the "Request an invitation" button on the invite-only signup
		 * screen points.
		 *
		 * Defaults to a mailto: link to the site's admin_email with the request
		 * pre-written. Return a page/form URL to route requests somewhere else, or an
		 * empty string to hide the button entirely (the screen then falls back to the
		 * contact line below, so the visitor is never left with nothing to do).
		 *
		 * @since 1.0.8
		 *
		 * @param string $bn_request_url  Default mailto URL ('' when the site has no valid admin email).
		 * @param string $bn_invite_token The invitation token that failed, if any ('' when none was supplied).
		 */
		$bn_request_url = (string) apply_filters( 'buddynext_invite_request_url', $bn_request_url, $bn_invite_token );

		// An expired / already-used / bogus link is a different situation from arriving
		// with no link at all — say which one happened so the visitor knows whether to
		// hunt for their email or ask for a first invite.
		$bn_invite_title = '' !== $bn_invite_token
			? __( 'This invitation link is no longer valid', 'buddynext' )
			: __( 'Registration is invite-only', 'buddynext' );
		$bn_invite_sub   = '' !== $bn_invite_token
			? __( 'Invitation links expire and can only be used once. Ask for a fresh invitation and you can join right away.', 'buddynext' )
			: __( 'This community is invite-only. You need a valid invitation link to create an account.', 'buddynext' );
		?>
		<div class="bn-auth-page">
			<div class="bn-auth-shell" data-panel="<?php echo (bool) get_option( 'buddynext_auth_panel_show', true ) ? 'on' : 'off'; ?>">
			<?php buddynext_get_template( 'auth/parts/auth-aside.php', array() ); ?>
			<div class="bn-auth-card" data-variant="register">
				<div class="bn-auth-body">
					<section class="bn-auth-panel" data-active>
						<h1 class="bn-auth-title"><?php echo esc_html( $bn_invite_title ); ?></h1>
						<p class="bn-auth-sub"><?php echo esc_html( $bn_invite_sub ); ?></p>

						<?php if ( '' !== $bn_request_url ) : ?>
							<a class="bn-btn" data-variant="primary" data-size="lg" href="<?php echo esc_url( $bn_request_url, array( 'http', 'https', 'mailto' ) ); ?>">
								<?php buddynext_icon( 'mail' ); ?>
								<?php esc_html_e( 'Request an invitation', 'buddynext' ); ?>
							</a>
						<?php endif; ?>

						<?php if ( '' !== $bn_admin_email ) : ?>
							<p class="bn-auth-sub">
								<?php
								printf(
									/* translators: %s: linked admin email address of the community. */
									esc_html__( 'Already know someone here? Ask them to invite you, or contact the community admin at %s.', 'buddynext' ),
									'<a href="' . esc_url( 'mailto:' . $bn_admin_email, array( 'mailto' ) ) . '">' . esc_html( $bn_admin_email ) . '</a>'
								);
								?>
							</p>
						<?php else : ?>
							<p class="bn-auth-sub"><?php esc_html_e( 'Ask a member of this community to send you an invitation.', 'buddynext' ); ?></p>
						<?php endif; ?>

						<a class="bn-btn" data-variant="ghost" data-size="lg" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() ); ?>">
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
				// Approval-mode registration succeeds but issues NO session: the account
				// is held for an admin. Redirecting such a user to /onboarding/ (which is
				// login-required) bounces them to a login they cannot pass. So we hold
				// them here and say so, instead of congratulating them into a dead end.
				'pendingMessage'   => '',
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
				<?php
				/**
				 * Filter the signup screen's sub-heading.
				 *
				 * "Free forever. No credit card required." is a promise, and it is a lie
				 * on a site where the visitor arrived here by choosing a paid plan. Pro's
				 * membership layer rewrites it when there is a paid plan intent, so the
				 * screen never contradicts the plan summary printed a line below it.
				 *
				 * @since 1.0.8
				 *
				 * @param string $subtitle Default sub-heading.
				 */
				$bn_signup_sub = (string) apply_filters(
					'buddynext_signup_subtitle',
					__( 'Free forever. No credit card required.', 'buddynext' )
				);
				?>
				<?php if ( '' !== $bn_signup_sub ) : ?>
					<p class="bn-auth-sub"><?php echo esc_html( $bn_signup_sub ); ?></p>
				<?php endif; ?>

				<div class="bn-auth-field__msg" role="alert" aria-live="polite"
					data-wp-bind--hidden="!state.error"
					data-wp-text="state.error"></div>

				<?php
				/*
				 * Approval-mode confirmation. Shown instead of the form once registration
				 * returns pending:true — the account exists but carries no session, so
				 * there is nothing to redirect them to and nothing more for them to fill in.
				 */
				?>
				<div class="bn-auth-notice bn-auth-notice--pending" role="status" aria-live="polite"
					data-wp-bind--hidden="!state.pending">
					<p class="bn-auth-notice__title"><?php esc_html_e( 'Your account is awaiting approval', 'buddynext' ); ?></p>
					<p class="bn-auth-notice__body" data-wp-text="state.pendingMessage"></p>
					<p class="bn-auth-notice__body"><?php esc_html_e( 'We will email you as soon as an administrator approves it. You do not need to do anything else.', 'buddynext' ); ?></p>
				</div>

				<?php
				// The fastest way in goes FIRST.
				//
				// These buttons used to sit BELOW the form — after three fields, a human check
				// and a consent box. Someone who would happily have clicked "Continue with
				// GitHub" had to read past the entire thing they were trying to avoid before
				// discovering they could skip it. Every mainstream signup (Google, Facebook,
				// LinkedIn, Slack, Notion) leads with the one-click path, because the member's
				// goal is to be inside the community, not to fill in a form.
				//
				// The divider below now reads "or sign up with your email", so the email form
				// reads as the alternative it has become rather than the main event.
				?>
				<?php if ( ! empty( $social_providers ) ) : ?>
					<div class="bn-auth-divider"><?php esc_html_e( 'or sign up with your email', 'buddynext' ); ?></div>
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

				<form class="bn-auth-form"
					novalidate
					data-wp-bind--hidden="state.pending"
					data-wp-on--submit="actions.submitSignup">

					<?php
					/**
					 * Fires at the top of the signup form, inside the <form>.
					 *
					 * The seam that lets signup know about anything that happened BEFORE
					 * it. Pro's membership layer uses it to show the plan the visitor
					 * picked on the pricing page and to carry a signed plan-intent token
					 * through registration, so a visitor who clicked "Get Pro" while
					 * logged out is handed back into that purchase afterwards instead
					 * of being dumped on a pricing table to start again.
					 *
					 * Any <input> printed here that is tagged `data-bn-signup-extra` is
					 * forwarded verbatim into the POST /auth/register body by the signup
					 * store — a listener needs no JavaScript of its own. Everything it
					 * sends must be validated server-side; nothing here is trusted.
					 *
					 * @since 1.0.8
					 */
					do_action( 'buddynext_signup_form_fields' );
					?>

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

					<?php
					// NAME — what the community actually shows other people.
					//
					// This is the field Facebook and LinkedIn ask for, and the one we never
					// did. Members were asked to invent a username instead, so they appeared
					// to each other as @jsmith — unless they signed up with a social provider,
					// which captured their real name and derived the handle silently. The
					// slower door asked more and captured less.
					if ( ! empty( $bn_requirements['ask_name'] ) ) :
						?>
						<div class="bn-auth-field">
							<label class="bn-auth-label" for="bn-signup-name">
								<?php esc_html_e( 'Your name', 'buddynext' ); ?>
							</label>
							<input class="bn-input"
								type="text"
								id="bn-signup-name"
								name="name"
								autocomplete="name"
								placeholder="<?php esc_attr_e( 'Jane Doe', 'buddynext' ); ?>"
								aria-describedby="bn-signup-name-hint"
								data-wp-bind--disabled="state.submitting" />
							<span class="bn-auth-hint" id="bn-signup-name-hint">
								<?php esc_html_e( 'This is how other members will see you.', 'buddynext' ); ?>
							</span>
						</div>
					<?php endif; ?>

					<?php
					// USERNAME — off by default.
					//
					// Nobody should have to invent a handle to join a community. When this is
					// off we derive one from the email (the same unique_login() social signup
					// has always used) and the member can change it later in settings. An
					// owner whose community wants handles chosen at the door turns it on.
					if ( ! empty( $bn_requirements['ask_username'] ) ) :
						?>
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
					<?php endif; ?>

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
						$bn_rf_name  = 'bn_field_' . $bn_rf_key;
						$bn_rf_req   = ! empty( $bn_reg_field['is_required'] );
						$bn_rf_input = \BuddyNext\Profile\FieldType::render_input( $bn_reg_field, '', $bn_rf_name );

						// FieldType::render_input already gives every control its own id
						// ('bn-field-{name}') and carries `required` from the field row, so
						// the only thing to inject is the tag the signup store collects on.
						// The old blanket id injection put ONE id on a radio / checkbox
						// group's N inputs — duplicate ids and an ambiguous label-for.
						$bn_rf_ctrl_id = 'bn-field-' . sanitize_html_class( $bn_rf_name );
						$bn_rf_group   = ( false !== strpos( $bn_rf_input, '<fieldset' ) );
						$bn_rf_label_id = 'bn-signup-field-' . sanitize_html_class( $bn_rf_key ) . '-label';

						$bn_rf_input = str_replace(
							array( '<input ', '<select ', '<textarea ' ),
							array( '<input data-bn-reg-field ', '<select data-bn-reg-field ', '<textarea data-bn-reg-field ' ),
							$bn_rf_input
						);

						if ( $bn_rf_group ) {
							// A group has no single labellable control — point the fieldset
							// at the visible label instead of a broken <label for>.
							$bn_rf_input = str_replace(
								'<fieldset ',
								'<fieldset aria-labelledby="' . esc_attr( $bn_rf_label_id ) . '" ',
								$bn_rf_input
							);
							// Radios only: `required` on each radio of a group means "pick
							// one" natively. On a checkbox group it would mean "tick them
							// ALL", so multiselect is validated server-side instead.
							if ( $bn_rf_req && false !== strpos( $bn_rf_input, 'bn-field-radio-group' ) ) {
								$bn_rf_input = str_replace( '<input data-bn-reg-field ', '<input data-bn-reg-field required ', $bn_rf_input );
							}
						}
						?>
						<div class="bn-auth-field">
							<?php if ( $bn_rf_group ) : ?>
							<span class="bn-auth-label" id="<?php echo esc_attr( $bn_rf_label_id ); ?>">
								<?php echo esc_html( (string) $bn_reg_field['label'] ); ?>
								<?php if ( $bn_rf_req ) : ?>
									<span class="bn-auth-required" aria-hidden="true">*</span>
								<?php endif; ?>
							</span>
							<?php else : ?>
							<label class="bn-auth-label" for="<?php echo esc_attr( $bn_rf_ctrl_id ); ?>">
								<?php echo esc_html( (string) $bn_reg_field['label'] ); ?>
								<?php if ( $bn_rf_req ) : ?>
									<span class="bn-auth-required" aria-hidden="true">*</span>
								<?php endif; ?>
							</label>
							<?php endif; ?>
							<?php if ( ! empty( $bn_reg_field['description'] ) ) : ?>
								<?php // G1: owner-authored help text; empty renders nothing. ?>
								<p class="bn-auth-hint bn-auth-field-hint"><?php echo esc_html( (string) $bn_reg_field['description'] ); ?></p>
							<?php endif; ?>
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
