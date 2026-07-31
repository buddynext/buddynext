<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Connect-app approve screen.
 *
 * The last hop of the native-app connect bridge. The member arrived here
 * signed in (PageRouter routes logged-out visitors through the site's own
 * sign-in first), and this screen asks ONE question — connect the app to your
 * account? — then mints the Application Password over REST and hands it back
 * to the app on its custom scheme.
 *
 * The credential is delivered by CLIENT-SIDE navigation plus a visible
 * tap-to-continue link, never a server 302: a Location header carrying a
 * password lands in proxy and access logs, and some in-app browsers suppress
 * scripted custom-scheme navigation — the manual link covers those.
 *
 * @package BuddyNext
 * @since   1.1.1
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\App\AppConnectService;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only render params; the one-time bridge token (minted below) gates the actual mint.
$bn_app_name = isset( $_GET['app_name'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['app_name'] ) ) : '';
$bn_app_id   = isset( $_GET['app_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['app_id'] ) ) : '';
$bn_scheme   = isset( $_GET['scheme'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['scheme'] ) ) : '';
$bn_state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$bn_scheme_ok = AppConnectService::allowed_scheme( $bn_scheme );
$bn_user      = wp_get_current_user();
$bn_app_label = '' !== $bn_app_name ? $bn_app_name : __( 'the app', 'buddynext' );
?>

<div class="bn-auth-page">
	<div class="bn-auth-shell" data-panel="<?php echo (bool) get_option( 'buddynext_auth_panel_show', true ) ? 'on' : 'off'; ?>">
	<?php buddynext_get_template( 'auth/parts/auth-aside.php', array() ); ?>

	<?php if ( ! $bn_scheme_ok ) : ?>
		<div class="bn-auth-card" data-variant="login">
			<div class="bn-auth-body">
				<section class="bn-auth-panel" data-active>
					<?php buddynext_get_template( 'auth/parts/auth-form-logo.php', array() ); ?>
					<h1 class="bn-auth-title"><?php esc_html_e( 'This connection link is not valid', 'buddynext' ); ?></h1>
					<p class="bn-auth-sub">
						<?php esc_html_e( 'The link that opened this page did not come from an app this site recognises. Go back to the app and try connecting again.', 'buddynext' ); ?>
					</p>
					<a class="bn-btn bn-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php esc_html_e( 'Back to the site', 'buddynext' ); ?>
					</a>
				</section>
			</div>
		</div>
		</div>
	</div>
		<?php
		return;
	endif;

	$bn_rest_root    = esc_url_raw( rest_url( 'buddynext/v1/' ) );
	$bn_rest_nonce   = wp_create_nonce( 'wp_rest' );
	$bn_bridge_token = AppConnectService::issue_bridge_token();
	?>

		<div class="bn-auth-card"
			data-variant="login"
			data-wp-interactive="buddynext/auth-connect-app"
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_interactivity_data_wp_context(
				array(
					'restUrl'     => $bn_rest_root,
					'restNonce'   => $bn_rest_nonce,
					'bridgeToken' => $bn_bridge_token,
					'appName'     => $bn_app_name,
					'appId'       => $bn_app_id,
					'scheme'      => $bn_scheme,
					'state'       => $bn_state,
					'busy'        => false,
					'connected'   => false,
					'deepLink'    => '',
					'error'       => '',
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			>

			<div class="bn-auth-body">
				<section class="bn-auth-panel" data-active>
					<?php buddynext_get_template( 'auth/parts/auth-form-logo.php', array() ); ?>

					<h1 class="bn-auth-title">
						<?php
						printf(
							/* translators: %s: the connecting app's name. */
							esc_html__( 'Connect %s to your account?', 'buddynext' ),
							'<strong>' . esc_html( $bn_app_label ) . '</strong>'
						);
						?>
					</h1>
					<p class="bn-auth-sub">
						<?php
						printf(
							/* translators: 1: member display name, 2: member login. */
							esc_html__( 'You are signed in as %1$s (%2$s). The app gets its own access key for this account — you can see and revoke it any time from your device settings.', 'buddynext' ),
							'<strong>' . esc_html( $bn_user->display_name ) . '</strong>',
							esc_html( $bn_user->user_login )
						);
						?>
					</p>

					<div class="bn-auth-field__msg" role="alert" aria-live="polite"
						data-wp-bind--hidden="!state.error"
						data-wp-text="state.error"></div>

					<div data-wp-bind--hidden="state.connected">
						<button class="bn-btn bn-btn--primary bn-btn--block"
							type="button"
							data-wp-on--click="actions.approve"
							data-wp-bind--disabled="state.busy">
							<span data-wp-bind--hidden="state.busy"><?php esc_html_e( 'Yes, connect the app', 'buddynext' ); ?></span>
							<span data-wp-bind--hidden="!state.busy"><?php esc_html_e( 'Connecting…', 'buddynext' ); ?></span>
						</button>

						<p class="bn-auth-sub bn-auth-switch-user">
							<a href="<?php echo esc_url( wp_logout_url( add_query_arg( array() ) ) ); ?>">
								<?php esc_html_e( 'Not you? Sign in as someone else', 'buddynext' ); ?>
							</a>
						</p>
					</div>

					<div data-wp-bind--hidden="!state.connected">
						<p class="bn-auth-sub"><?php esc_html_e( 'Connected. Opening the app…', 'buddynext' ); ?></p>
						<a class="bn-btn bn-btn--primary bn-btn--block"
							data-wp-bind--href="state.deepLink">
							<?php esc_html_e( 'Open the app', 'buddynext' ); ?>
						</a>
						<p class="bn-auth-sub">
							<?php esc_html_e( 'If nothing happens, tap the button above to return to the app.', 'buddynext' ); ?>
						</p>
					</div>
				</section>
			</div>
		</div>
	</div>
</div>
