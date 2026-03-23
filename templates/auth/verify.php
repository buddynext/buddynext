<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Email verification page template.
 *
 * Renders three states:
 *   success  — user arrived via ?bn_verified=1 (token processed by VerificationListener)
 *   error    — user arrived via ?bn_verified=0 (invalid or expired token)
 *   pending  — logged-in unverified user waiting to click their link
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

// Redirect already-verified or logged-out-but-not-verifying users to home.
$bn_verify_current_user = get_current_user_id();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_verified_param = isset( $_GET['bn_verified'] ) ? sanitize_key( $_GET['bn_verified'] ) : '';

// Determine state.
if ( '1' === $bn_verified_param ) {
	$bn_verify_state = 'success';
} elseif ( '0' === $bn_verified_param ) {
	$bn_verify_state = 'error';
} elseif ( $bn_verify_current_user > 0 ) {
	// Check if already verified.
	$bn_already_verified = (bool) get_user_meta( $bn_verify_current_user, 'buddynext_email_verified', true );
	if ( $bn_already_verified ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	$bn_verify_state = 'pending';
} else {
	// Guest with no verification context — redirect to login.
	wp_safe_redirect( \BuddyNext\Core\PageRouter::auth_url() );
	exit;
}

// User data for personalisation (best-effort — may be null).
$bn_verify_user  = $bn_verify_current_user > 0 ? get_userdata( $bn_verify_current_user ) : false;
$bn_verify_name  = ( $bn_verify_user && '' !== $bn_verify_user->display_name )
	? $bn_verify_user->display_name
	: '';
$bn_verify_email = $bn_verify_user ? $bn_verify_user->user_email : '';

// Feed URL for post-verification redirect.
$bn_feed_url = \BuddyNext\Core\PageRouter::activity_url();

// REST endpoint for resend requests.
$bn_resend_url   = rest_url( 'buddynext/v1/auth/verify/resend' );
$bn_resend_nonce = wp_create_nonce( 'wp_rest' );

get_header();
?>

<style id="bn-verify-css">
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--bg:          #ffffff;
	--bg-subtle:   #f8f8f7;
	--bg-hover:    #f1f1f0;
	--surface:     #ffffff;
	--border:      #e8e8e5;
	--text-1:      #37352f;
	--text-2:      #787774;
	--text-3:      #aeaca8;
	--brand:       #0073aa;
	--brand-light: #e8f4fb;
	--brand-hover: #005f8e;
	--green:       #059669;
	--green-bg:    #ecfdf5;
	--red:         #dc2626;
	--red-bg:      #fef2f2;
	--amber:       #d97706;
	--amber-bg:    #fffbeb;
	--s1: 4px;  --s2: 8px;  --s3: 12px;  --s4: 16px;
	--s5: 20px; --s6: 24px; --s8: 32px;  --s10: 40px; --s12: 48px;
	--r-md: 8px; --r-lg: 12px; --r-xl: 16px;
}
[data-theme="dark"] {
	--bg:          #191919;
	--bg-subtle:   #202020;
	--bg-hover:    #2a2a2a;
	--surface:     #252525;
	--border:      #333330;
	--text-1:      #e8e8e6;
	--text-2:      #9b9b97;
	--text-3:      #6b6b67;
	--brand:       #4dabdb;
	--brand-light: #1a2e3a;
	--brand-hover: #5fbfe8;
	--green:       #34d399;
	--green-bg:    #0d2420;
	--red:         #f87171;
	--red-bg:      #2d0f0f;
	--amber:       #fbbf24;
	--amber-bg:    #2a2000;
}

.bn-verify-page {
	min-height: 100vh;
	background: var(--bg-subtle);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: var(--s8);
	font-family: var(--font-body);
}
.bn-verify-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--r-xl);
	padding: var(--s10) var(--s12);
	max-width: 440px;
	width: 100%;
	text-align: center;
}
.bn-verify-icon {
	width: 64px;
	height: 64px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto var(--s6);
}
.bn-verify-icon--pending {
	background: var(--brand-light);
	color: var(--brand);
}
.bn-verify-icon--success {
	background: var(--green-bg);
	color: var(--green);
}
.bn-verify-icon--error {
	background: var(--red-bg);
	color: var(--red);
}
.bn-verify-title {
	font-family: var(--font-display);
	font-size: 22px;
	font-weight: 700;
	color: var(--text-1);
	margin: 0 0 var(--s3);
	line-height: 1.25;
}
.bn-verify-desc {
	font-size: 14px;
	color: var(--text-2);
	line-height: 1.6;
	margin: 0 0 var(--s8);
}
.bn-verify-email-chip {
	display: inline-flex;
	align-items: center;
	gap: var(--s1);
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	border-radius: var(--r-md);
	padding: var(--s1) var(--s3);
	font-size: 13px;
	font-weight: 500;
	color: var(--text-1);
	margin-bottom: var(--s8);
}
.bn-verify-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: var(--s2);
	padding: var(--s3) var(--s8);
	border-radius: var(--r-md);
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.12s, opacity 0.12s;
	text-decoration: none;
	border: none;
	width: 100%;
}
.bn-verify-btn--primary {
	background: var(--brand);
	color: #fff;
}
.bn-verify-btn--primary:hover { background: var(--brand-hover); }
.bn-verify-btn--primary:disabled { opacity: 0.55; cursor: not-allowed; }
.bn-verify-btn--ghost {
	background: var(--bg-subtle);
	color: var(--text-2);
	border: 1px solid var(--border);
}
.bn-verify-btn--ghost:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-verify-divider {
	height: 1px;
	background: var(--border);
	margin: var(--s6) 0;
}
.bn-verify-footer {
	font-size: 12px;
	color: var(--text-3);
}
.bn-verify-footer a {
	color: var(--brand);
	text-decoration: none;
}
.bn-verify-footer a:hover { text-decoration: underline; }
.bn-verify-feedback {
	font-size: 13px;
	font-weight: 500;
	padding: var(--s2) var(--s4);
	border-radius: var(--r-md);
	margin-bottom: var(--s4);
	display: none;
}
.bn-verify-feedback.is-success {
	background: var(--green-bg);
	color: var(--green);
	display: block;
}
.bn-verify-feedback.is-error {
	background: var(--red-bg);
	color: var(--red);
	display: block;
}
@media (max-width: 640px) {
	.bn-verify-page { padding: var(--s4); align-items: flex-start; padding-top: var(--s10); }
	.bn-verify-card { padding: var(--s8) var(--s6); }
	.bn-verify-title { font-size: 18px; }
}
</style>

<div class="bn-verify-page">
	<div class="bn-verify-card">

		<?php if ( 'success' === $bn_verify_state ) : ?>

			<div class="bn-verify-icon bn-verify-icon--success" aria-hidden="true">
				<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
			</div>
			<h1 class="bn-verify-title"><?php esc_html_e( 'Email Verified!', 'buddynext' ); ?></h1>
			<p class="bn-verify-desc">
				<?php esc_html_e( "Your email address has been verified. You're all set to start using the community.", 'buddynext' ); ?>
			</p>
			<a href="<?php echo esc_url( $bn_feed_url ); ?>" class="bn-verify-btn bn-verify-btn--primary">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
				<?php esc_html_e( 'Go to Community Feed', 'buddynext' ); ?>
			</a>

		<?php elseif ( 'error' === $bn_verify_state ) : ?>

			<div class="bn-verify-icon bn-verify-icon--error" aria-hidden="true">
				<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
			</div>
			<h1 class="bn-verify-title"><?php esc_html_e( 'Verification Link Invalid', 'buddynext' ); ?></h1>
			<p class="bn-verify-desc">
				<?php esc_html_e( 'This verification link has expired or is no longer valid. Request a new link below.', 'buddynext' ); ?>
			</p>

			<?php if ( $bn_verify_current_user > 0 ) : ?>
				<div id="bn-verify-feedback" class="bn-verify-feedback" role="alert" aria-live="polite"></div>
				<button
					id="bn-resend-btn"
					type="button"
					class="bn-verify-btn bn-verify-btn--primary"
					data-url="<?php echo esc_url( $bn_resend_url ); ?>"
					data-nonce="<?php echo esc_attr( $bn_resend_nonce ); ?>">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.35-5.2"/></svg>
					<?php esc_html_e( 'Resend Verification Email', 'buddynext' ); ?>
				</button>
				<div class="bn-verify-divider"></div>
			<?php endif; ?>

			<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() ); ?>" class="bn-verify-btn bn-verify-btn--ghost">
				<?php esc_html_e( 'Back to Login', 'buddynext' ); ?>
			</a>

		<?php else : ?>

			<div class="bn-verify-icon bn-verify-icon--pending" aria-hidden="true">
				<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
			</div>
			<h1 class="bn-verify-title"><?php esc_html_e( 'Check Your Email', 'buddynext' ); ?></h1>
			<p class="bn-verify-desc">
				<?php
				if ( '' !== $bn_verify_name ) {
					printf(
						/* translators: %s: user display name */
						esc_html__( "Hi %s! We've sent a verification link to:", 'buddynext' ),
						esc_html( $bn_verify_name )
					);
				} else {
					esc_html_e( "We've sent a verification link to:", 'buddynext' );
				}
				?>
			</p>
			<?php if ( '' !== $bn_verify_email ) : ?>
				<div class="bn-verify-email-chip">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
					<?php echo esc_html( $bn_verify_email ); ?>
				</div>
			<?php endif; ?>
			<p class="bn-verify-desc">
				<?php esc_html_e( 'Click the link in the email to verify your address. The link expires in 24 hours.', 'buddynext' ); ?>
			</p>

			<div id="bn-verify-feedback" class="bn-verify-feedback" role="alert" aria-live="polite"></div>
			<button
				id="bn-resend-btn"
				type="button"
				class="bn-verify-btn bn-verify-btn--ghost"
				data-url="<?php echo esc_url( $bn_resend_url ); ?>"
				data-nonce="<?php echo esc_attr( $bn_resend_nonce ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.35-5.2"/></svg>
				<?php esc_html_e( "Didn't receive it? Resend", 'buddynext' ); ?>
			</button>

		<?php endif; ?>

		<div class="bn-verify-divider"></div>
		<p class="bn-verify-footer">
			<?php
			printf(
				/* translators: %s: support email link */
				esc_html__( 'Having trouble? %s', 'buddynext' ),
				'<a href="mailto:' . esc_attr( (string) get_option( 'admin_email' ) ) . '">' . esc_html__( 'Contact support', 'buddynext' ) . '</a>'
			);
			?>
		</p>

	</div>
</div>

<script id="bn-verify-js">
(function () {
	var btn = document.getElementById( 'bn-resend-btn' );
	if ( ! btn ) { return; }
	btn.addEventListener( 'click', function () {
		var feedback = document.getElementById( 'bn-verify-feedback' );
		btn.disabled = true;
		btn.textContent = <?php echo wp_json_encode( __( 'Sending…', 'buddynext' ) ); ?>;
		fetch( btn.dataset.url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   btn.dataset.nonce,
			},
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( feedback ) {
					feedback.textContent = data.message || <?php echo wp_json_encode( __( 'Verification email sent.', 'buddynext' ) ); ?>;
					feedback.className   = 'bn-verify-feedback ' + ( data.success ? 'is-success' : 'is-error' );
				}
				btn.disabled    = false;
				btn.textContent = <?php echo wp_json_encode( __( "Didn't receive it? Resend", 'buddynext' ) ); ?>;
			} )
			.catch( function () {
				if ( feedback ) {
					feedback.textContent = <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'buddynext' ) ); ?>;
					feedback.className   = 'bn-verify-feedback is-error';
				}
				btn.disabled    = false;
				btn.textContent = <?php echo wp_json_encode( __( "Didn't receive it? Resend", 'buddynext' ) ); ?>;
			} );
	} );
} )();
</script>

<?php get_footer(); ?>
