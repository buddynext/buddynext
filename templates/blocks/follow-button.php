<?php
/**
 * Block template: Follow Button (v2 design system).
 *
 * Primary CTA when not following, secondary toggle when following. Hover text
 * swap ("Following" → "Unfollow") is handled in JS via the Interactivity
 * `state.label` binding — no inline conditional markup beyond the initial SSR
 * render.
 *
 * Variables:
 *   int $user_id WordPress user ID to follow / unfollow.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id   = $user_id ?? 0;
$viewer_id = get_current_user_id();

if ( ! $user_id || ! $viewer_id || $viewer_id === $user_id ) {
	return;
}

$bn_fb_follows = buddynext_service( 'follows' );
$is_following  = $bn_fb_follows->is_following( $viewer_id, $user_id );
// A follow on a private account lands pending until approved — surface the
// "Requested" state so the button is honest. Mirrors the partial follow-button.
$private_follow = $bn_fb_follows->is_private_account( $user_id );
$is_pending     = ! $is_following && $bn_fb_follows->has_pending_request( $viewer_id, $user_id );

// Pass the @handle (user_nicename) so toasts read "Now following @jane" rather
// than the "#<id>" fallback the follow store uses when targetName is absent.
$bn_fb_user = get_userdata( $user_id );
$bn_fb_name = $bn_fb_user ? $bn_fb_user->user_nicename : '';

$context_json = (string) wp_json_encode(
	array(
		'userId'        => $user_id,
		'targetName'    => $bn_fb_name,
		'isFollowing'   => $is_following,
		'isPending'     => $is_pending,
		'privateFollow' => $private_follow,
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'restUrl'       => rest_url( 'buddynext/v1' ),
	)
);

if ( $is_pending ) {
	$bn_fb_initial_label = esc_html__( 'Requested', 'buddynext' );
} elseif ( $is_following ) {
	$bn_fb_initial_label = esc_html__( 'Following', 'buddynext' );
} else {
	$bn_fb_initial_label = esc_html__( 'Follow', 'buddynext' );
}
$bn_fb_is_active = $is_following || $is_pending;
?>
<div
	class="bn-block-follow-button"
	data-wp-interactive="buddynext/follow-button"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context="<?php echo esc_attr( $context_json ); ?>"
>
	<button
		type="button"
		class="bn-btn bn-block-follow-button__cta<?php echo $is_following && ! $is_pending ? ' bn-following' : ''; ?>"
		data-variant="<?php echo $bn_fb_is_active ? 'secondary' : 'primary'; ?>"
		data-size="sm"
		data-action="bn-toggle-follow"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-on--click="actions.toggleFollow"
		data-wp-bind--class="state.buttonClass"
		data-wp-bind--data-variant="state.followVariant"
		data-wp-bind--data-state="state.btnState"
		data-wp-text="state.label"
		aria-pressed="<?php echo $bn_fb_is_active ? 'true' : 'false'; ?>"
		aria-label="<?php echo $is_pending ? esc_attr__( 'Cancel follow request', 'buddynext' ) : ( $is_following ? esc_attr__( 'Unfollow user', 'buddynext' ) : esc_attr__( 'Follow user', 'buddynext' ) ); ?>"
	>
		<?php echo $bn_fb_initial_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html__ applied above. ?>
	</button>
</div>
