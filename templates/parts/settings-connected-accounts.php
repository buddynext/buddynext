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
	$bn_social_any    = ! empty( (array) apply_filters( 'buddynext_auth_social_providers', array() ) ) || array_filter( $bn_social_linked );
	if ( ! empty( $bn_social_labels ) && $bn_social_any ) {
		$bn_sc_html = '';
		foreach ( $bn_social_labels as $bn_sp_id => $bn_sp_label ) {
			$bn_linked   = ! empty( $bn_social_linked[ $bn_sp_id ] );
			$bn_sc_html .= '<div class="bn-social-link" data-provider="' . esc_attr( $bn_sp_id ) . '">';
			$bn_sc_html .= '<span class="bn-social-link__name">' . esc_html( $bn_sp_label ) . '</span>';
			if ( $bn_linked ) {
				$bn_sc_html .= '<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-user-id="' . esc_attr( (string) $user_id ) . '" data-provider="' . esc_attr( $bn_sp_id ) . '" data-wp-on--click="actions.unlinkSocial">' . esc_html__( 'Unlink', 'buddynext' ) . '</button>';
			} else {
				$bn_sc_html .= '<a class="bn-btn" data-variant="ghost" data-size="sm" href="' . esc_url( home_url( '/oauth/' . $bn_sp_id . '/' ) ) . '">' . esc_html__( 'Connect', 'buddynext' ) . '</a>';
			}
			$bn_sc_html .= '</div>';
		}
		buddynext_get_template(
			'parts/profile-edit-section.php',
			array(
				'title'     => __( 'Connected accounts', 'buddynext' ),
				'subtitle'  => __( 'Link a social account for one-tap sign-in, or unlink it.', 'buddynext' ),
				'title_id'  => 'bn-ep-social-title',
				'body_html' => $bn_sc_html,
			)
		);
	}
}
