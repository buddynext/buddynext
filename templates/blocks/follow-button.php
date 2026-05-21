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

$is_following = buddynext_service( 'follows' )->is_following( $viewer_id, $user_id );

$context_json = (string) wp_json_encode(
	array(
		'userId'      => $user_id,
		'isFollowing' => $is_following,
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'restUrl'     => rest_url( 'buddynext/v1' ),
	)
);
?>
<div
	class="bn-block-follow-button"
	data-wp-interactive="buddynext/follow-button"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context="<?php echo esc_attr( $context_json ); ?>"
>
	<button
		type="button"
		class="bn-btn bn-block-follow-button__cta<?php echo $is_following ? ' bn-following' : ''; ?>"
		data-variant="<?php echo $is_following ? 'secondary' : 'primary'; ?>"
		data-size="sm"
		data-action="bn-toggle-follow"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-on--click="actions.toggleFollow"
		data-wp-bind--class="state.buttonClass"
		data-wp-text="state.label"
		aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>"
		aria-label="<?php echo $is_following ? esc_attr__( 'Unfollow user', 'buddynext' ) : esc_attr__( 'Follow user', 'buddynext' ); ?>"
	>
		<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
	</button>
</div>
