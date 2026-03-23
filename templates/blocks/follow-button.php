<?php
/**
 * Block template: Follow Button
 *
 * Variables:
 *   int $user_id WordPress user ID to follow/unfollow
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
?>
<div
	class="bn-block-follow-button"
	data-wp-interactive="buddynext/follow"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context='{"userId":<?php echo absint( $user_id ); ?>,"isFollowing":<?php echo $is_following ? 'true' : 'false'; ?>}'
>
	<button
		class="bn-btn bn-btn--sm <?php echo $is_following ? 'bn-btn--secondary bn-following' : 'bn-btn--primary'; ?>"
		data-wp-on--click="actions.toggleFollow"
		data-wp-bind--class="state.buttonClass"
		data-action="bn-toggle-follow"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'bn-follow' ) ); ?>"
		data-wp-text="state.label"
		aria-label="<?php echo $is_following ? esc_attr__( 'Unfollow user', 'buddynext' ) : esc_attr__( 'Follow user', 'buddynext' ); ?>"
	>
		<?php if ( $is_following ) : ?>
			<?php esc_html_e( 'Following', 'buddynext' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Follow', 'buddynext' ); ?>
		<?php endif; ?>
	</button>
</div>
