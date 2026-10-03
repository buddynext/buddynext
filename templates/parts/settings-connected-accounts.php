<?php
/**
 * BuddyNext template part: settings → connected social accounts.
 *
 * Self-contained relocation of the "Connected social accounts" block from the
 * profile editor. Renders one row per configured social provider, linking or
 * unlinking it via the buddynext/profile store (DELETE /me/social/{provider}).
 * Only the owner edits here. Renders nothing when the SocialLogin auth class is
 * absent, when no provider labels exist, or when no provider is configured or
 * already linked.
 *
 * Computes every variable it needs from the current user, so it requires no
 * variables from the caller.
 *
 * Overridable: copy to {theme}/buddynext/parts/settings-connected-accounts.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id = get_current_user_id();

// Connected social accounts — link/unlink configured providers.
// Only the owner edits here; wired to the buddynext/profile store's
// unlinkSocial action (DELETE /me/social/{provider}).
if ( class_exists( '\BuddyNext\Auth\SocialLogin' ) ) {
	// Rows from the same SocialLogin::account_rows() GET /me/social returns.
	$bn_social_rows = \BuddyNext\Auth\SocialLogin::account_rows( $user_id );
	if ( ! empty( $bn_social_rows ) ) {
		$bn_sc_html = '';
		foreach ( $bn_social_rows as $bn_row ) {
			$bn_sp_id  = $bn_row['id'];
			$bn_linked = $bn_row['linked'];
			// Unlinking THIS one strands them when it is their last credential
			// (the REST endpoint refuses it: bn_last_credential), so it looks refused.
			$bn_is_last = $bn_row['only_credential'];

			$bn_icon_html = '';
			if ( '' !== $bn_row['icon']
				&& class_exists( '\BuddyNext\Core\IconService' )
				&& \BuddyNext\Core\IconService::has( $bn_row['icon'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService::render() returns wp_kses()-sanitized SVG.
				$bn_icon_html = '<span class="bn-social-row__icon" aria-hidden="true">' . \BuddyNext\Core\IconService::render( $bn_row['icon'] ) . '</span>';
			}

			// Say what state they are actually IN. Before, the only clue was which
			// button happened to be showing — the member had to infer their own account
			// state from the verb on a control.
			if ( $bn_is_last ) {
				$bn_status = __( 'Connected. This is your only way to sign in - set a password before unlinking it.', 'buddynext' );
			} elseif ( $bn_linked ) {
				$bn_status = __( 'Connected. You can sign in with one tap.', 'buddynext' );
			} else {
				$bn_status = __( 'Not connected.', 'buddynext' );
			}

			$bn_sc_html .= '<div class="bn-ep-account-row bn-social-row" data-provider="' . esc_attr( $bn_sp_id ) . '">';
			$bn_sc_html .= $bn_icon_html;
			$bn_sc_html .= '<div class="bn-ep-account-copy">';
			$bn_sc_html .= '<span class="bn-ep-account-label">' . esc_html( $bn_row['label'] ) . '</span>';
			$bn_sc_html .= '<span class="bn-ep-account-value">' . esc_html( $bn_status ) . '</span>';
			$bn_sc_html .= '</div>';

			if ( $bn_linked ) {
				$bn_sc_html .= '<button type="button" class="bn-btn" data-variant="ghost" data-size="sm"'
					. ( $bn_is_last ? ' disabled aria-disabled="true"' : '' )
					. ' data-user-id="' . esc_attr( (string) $user_id ) . '"'
					. ' data-provider="' . esc_attr( $bn_sp_id ) . '"'
					. ' data-wp-on--click="actions.unlinkSocial">'
					. esc_html__( 'Unlink', 'buddynext' ) . '</button>';
			} else {
				$bn_sc_html .= '<a class="bn-btn" data-variant="secondary" data-size="sm" href="' . esc_url( $bn_row['connect_url'] ) . '">' . esc_html__( 'Connect', 'buddynext' ) . '</a>';
			}
			$bn_sc_html .= '</div>';
		}
		buddynext_get_template(
			'parts/profile-edit-section.php',
			array(
				'title'     => __( 'Connected accounts', 'buddynext' ),
				'subtitle'  => __( 'Sign in with one tap. Connect an account, or unlink one you no longer use.', 'buddynext' ),
				'title_id'  => 'bn-ep-social-title',
				'body_html' => '<div class="bn-ep-account-rows">' . $bn_sc_html . '</div>',
			)
		);
	}
}
