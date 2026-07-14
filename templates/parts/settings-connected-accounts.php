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
	$bn_social_labels = \BuddyNext\Auth\SocialLogin::labels();
	$bn_social_linked = \BuddyNext\Auth\SocialLogin::linked_for( $user_id );

	// Ready (enabled + credentialed) provider ids, from the same seam the auth
	// flow uses. Only these may render a Connect button — otherwise an unconfigured
	// provider is a dead-end ("That sign-in method is not available.").
	$bn_ready_ids = array();
	foreach ( (array) apply_filters( 'buddynext_auth_social_providers', array() ) as $bn_ready_provider ) {
		if ( is_array( $bn_ready_provider ) && isset( $bn_ready_provider['id'] ) ) {
			$bn_ready_ids[ (string) $bn_ready_provider['id'] ] = true;
		}
	}

	$bn_social_any = ! empty( $bn_ready_ids ) || array_filter( $bn_social_linked );
	if ( ! empty( $bn_social_labels ) && $bn_social_any ) {
		// Provider definitions carry the icon slug (google, github, facebook…).
		$bn_sp_defs = \BuddyNext\Auth\SocialLogin::get_providers();

		// Is a social account the member's ONLY way in? Then unlinking it would lock
		// them out of their own account, and the REST endpoint refuses it
		// (bn_last_credential). Say so HERE, next to the button, rather than letting
		// them press it and collect an error — a control that is going to refuse should
		// look refused.
		$bn_has_password = class_exists( '\BuddyNext\Auth\AuthController' )
			&& \BuddyNext\Auth\AuthController::has_known_password( $user_id );
		$bn_linked_count = count( array_filter( $bn_social_linked ) );

		$bn_sc_html = '';
		foreach ( $bn_social_labels as $bn_sp_id => $bn_sp_label ) {
			$bn_linked = ! empty( $bn_social_linked[ $bn_sp_id ] );

			// Show a provider only when the member has it linked (always) or it is
			// configured + ready to connect. Skip unconfigured, unlinked providers.
			if ( ! $bn_linked && ! isset( $bn_ready_ids[ $bn_sp_id ] ) ) {
				continue;
			}

			// Unlinking THIS one strands them when it is their last credential.
			$bn_is_last = $bn_linked && ! $bn_has_password && 1 === $bn_linked_count;

			$bn_icon_slug = isset( $bn_sp_defs[ $bn_sp_id ]['icon'] ) ? (string) $bn_sp_defs[ $bn_sp_id ]['icon'] : '';
			$bn_icon_html = '';
			if ( '' !== $bn_icon_slug
				&& class_exists( '\BuddyNext\Core\IconService' )
				&& \BuddyNext\Core\IconService::has( $bn_icon_slug ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService::render() returns wp_kses()-sanitized SVG.
				$bn_icon_html = '<span class="bn-social-row__icon" aria-hidden="true">' . \BuddyNext\Core\IconService::render( $bn_icon_slug ) . '</span>';
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
			$bn_sc_html .= '<span class="bn-ep-account-label">' . esc_html( $bn_sp_label ) . '</span>';
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
				$bn_sc_html .= '<a class="bn-btn" data-variant="secondary" data-size="sm" href="' . esc_url( home_url( '/oauth/' . $bn_sp_id . '/' ) ) . '">' . esc_html__( 'Connect', 'buddynext' ) . '</a>'; // bn-route-ok: plugin-registered fixed /oauth/ rewrite.
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
