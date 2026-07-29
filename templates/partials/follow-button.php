<?php
/**
 * Partial: Follow / Unfollow button.
 *
 * Renders a single follow-toggle button wired to the buddynext/follow-button
 * WP Interactivity API store. Designed for direct PHP include inside loops
 * (member cards, profile widgets, suggested-follow sidebar).
 *
 * The button surfaces five reactive states driven entirely by
 * `data-wp-bind--data-state` so styling can target each state via CSS:
 *
 *   unfollowed | following | pending | blocked | self
 *
 * `blocked` and `self` short-circuit on the PHP side (the partial returns
 * before output) so the bound element is never rendered.
 *
 * Expected variables (set by the caller before including this file):
 *   int  $user_id        ID of the user to follow / unfollow.
 *   bool $private_follow Optional. When true, the click action transitions to
 *                       `pending` instead of `following`. Defaults to false.
 *   bool $known_following Optional. Precomputed follow state from the caller;
 *                       set it to skip the per-render is_following() query.
 *   bool $known_blocked  Optional. Precomputed block state (either direction);
 *                       set it to skip the per-render is_blocking_either() query.
 *   bool $known_pending  Optional. Precomputed pending-request state; set it to
 *                       skip the per-render has_pending_request() query.
 *
 * The three `known_*` arguments exist for one reason: this partial runs THREE
 * queries per render, and callers that draw it in a loop multiply all three. A
 * caller with the full ID list up front can resolve each in one query via
 * BlockService::blocking_either_map(), FollowService::following_map() and
 * FollowService::pending_map(), then pass the answers in. Only `known_following`
 * existed before, so a bulk caller could batch one of the three and still pay
 * an N+1 for the other two - which is what the leaderboard was doing, at 147
 * queries for 50 rows.
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

// Block guard — render nothing if either party has blocked the other. A bulk
// caller can resolve this for the whole list in one query (blocking_either_map)
// and pass the answer, exactly as with $known_following below.
$bn_fb_blocked = isset( $known_blocked )
	? (bool) $known_blocked
	: buddynext_service( 'blocks' )->is_blocking_either( $viewer_id, $user_id );
if ( $bn_fb_blocked ) {
	return;
}

// Privacy guard — render nothing when the target does not accept follows from
// this viewer (who_can_follow = 'nobody'). FollowService enforces the same rule
// server-side; hiding the button keeps the UI honest instead of surfacing a
// generic error only after the click.
$privacy = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
if ( $privacy && method_exists( $privacy, 'can_follow' ) && ! $privacy->can_follow( $viewer_id, $user_id ) ) {
	return;
}

$follows        = buddynext_service( 'follows' );
$private_follow = isset( $private_follow ) ? (bool) $private_follow : $follows->is_private_account( $user_id );
// Callers that already know the follow state (e.g. a feed loop that resolved
// it per-author) can pass $known_following to skip the per-render query.
$is_following = isset( $known_following ) ? (bool) $known_following : $follows->is_following( $viewer_id, $user_id );
if ( $is_following ) {
	$is_pending = false;
} elseif ( isset( $known_pending ) ) {
	$is_pending = (bool) $known_pending;
} else {
	$is_pending = $follows->has_pending_request( $viewer_id, $user_id );
}

// The follow store builds its toasts as "@" . targetName, so pass the @handle
// (user_nicename). Without it the store falls back to "#<id>" and toasts read
// "Now following #8" instead of "Now following @jane".
$target_user = get_userdata( $user_id );
$target_name = $target_user ? $target_user->user_nicename : '';

// Build the WP Interactivity API context object (esc_attr-escaped JSON string).
$context_attr = esc_attr(
	(string) wp_json_encode(
		array(
			'userId'        => $user_id,
			'targetName'    => $target_name,
			'isFollowing'   => $is_following,
			'isPending'     => $is_pending,
			'privateFollow' => $private_follow,
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'restUrl'       => rest_url( 'buddynext/v1' ),
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
		class="bn-btn bn-follow-btn"
		data-size="sm"
		data-variant="<?php echo esc_attr( $is_following ? 'secondary' : 'primary' ); ?>"
		data-action="bn-toggle-follow"
		data-user-id="<?php echo absint( $user_id ); ?>"
		data-wp-on--click="actions.toggleFollow"
		data-wp-bind--class="state.buttonClass"
		data-wp-bind--data-variant="state.followVariant"
		data-wp-bind--data-state="state.btnState"
		data-wp-bind--aria-pressed="state.ariaPressed"
		data-wp-bind--aria-label="state.ariaLabel"
		data-wp-bind--aria-busy="context.busy"
		data-wp-bind--disabled="context.busy"
		data-wp-text="state.label"
	>
	<?php
	if ( $is_following ) {
		esc_html_e( 'Following', 'buddynext' );
	} else {
		esc_html_e( 'Follow', 'buddynext' );
	}
	?>
	</button>
</div>
<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
