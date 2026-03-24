<?php
/**
 * Partial: Follow / Unfollow button.
 *
 * Renders a single follow-toggle button wired to the buddynext/follow-button
 * WP Interactivity API store. Designed for direct PHP include inside loops
 * (member cards, profile widgets).
 *
 * Expected variables (set by the caller before including this file):
 *   int $user_id  ID of the user to follow / unfollow.
 *
 * The partial silently returns when:
 *   - $user_id is not set or zero.
 *   - No viewer is logged in.
 *   - The viewer IS the subject (cannot follow yourself).
 *   - Either party has blocked the other.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$user_id = isset( $user_id ) ? (int) $user_id : 0;
if ( $user_id <= 0 ) {
	return;
}

$viewer_id = get_current_user_id();
if ( ! $viewer_id || $viewer_id === $user_id ) {
	return;
}

// Block guard — render nothing if either party has blocked the other.
if ( buddynext_service( 'blocks' )->is_blocking_either( $viewer_id, $user_id ) ) {
	return;
}

$is_following   = buddynext_service( 'follows' )->is_following( $viewer_id, $user_id );
$btn_class      = 'bn-btn bn-btn--sm ' . ( $is_following ? 'bn-btn--secondary bn-following' : 'bn-btn--primary' );
$btn_label      = $is_following ? __( 'Following', 'buddynext' ) : __( 'Follow', 'buddynext' );
$btn_aria_label = $is_following ? __( 'Unfollow this user', 'buddynext' ) : __( 'Follow this user', 'buddynext' );

// Build the WP Interactivity API context object (esc_attr-escaped JSON string).
$context_attr = esc_attr(
	(string) wp_json_encode(
		array(
			'userId'      => $user_id,
			'isFollowing' => $is_following,
			'nonce'       => wp_create_nonce( 'bn-follow' ),
		)
	)
);
?>
<div
	class="bn-follow-btn-wrap"
	data-wp-interactive="buddynext/follow-button"
	data-user-id="<?php echo absint( $user_id ); ?>"
	data-wp-context="<?php echo $context_attr; // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() applied to $context_attr. ?>"
>
	<button
		type="button"
		class="<?php echo esc_attr( $btn_class ); ?>"
		data-wp-on--click="actions.toggleFollow"
		data-wp-bind--class="state.buttonClass"
		data-wp-text="state.label"
		data-action="bn-toggle-follow"
		data-user-id="<?php echo absint( $user_id ); ?>"
		aria-label="<?php echo esc_attr( $btn_aria_label ); ?>"
	>
		<?php echo esc_html( $btn_label ); ?>
	</button>
</div>
<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
