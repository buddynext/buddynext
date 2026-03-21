<?php
/**
 * Block template: Member Card
 *
 * Variables:
 *   int $user_id WordPress user ID to display
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id = $user_id ?? 0;

if ( ! $user_id ) {
	$user_id = get_current_user_id();
}

$user = $user_id ? get_userdata( $user_id ) : false;

if ( ! $user ) {
	return;
}

$viewer_id      = get_current_user_id();
$follower_count = buddynext_service( 'follows' )->follower_count( $user_id );
$is_following   = $viewer_id && $viewer_id !== $user_id
	? buddynext_service( 'follows' )->is_following( $viewer_id, $user_id )
	: false;
?>
<div class="bn-block-member-card" data-user-id="<?php echo absint( $user_id ); ?>">
	<div class="bn-member-card__avatar">
		<?php echo get_avatar( $user_id, 64, '', '', array( 'class' => 'bn-avatar bn-avatar--lg' ) ); ?>
	</div>
	<div class="bn-member-card__body">
		<a href="<?php echo esc_url( get_author_posts_url( $user_id ) ); ?>" class="bn-member-card__name">
			<?php echo esc_html( $user->display_name ); ?>
		</a>
		<span class="bn-member-card__followers">
			<?php
			printf(
				/* translators: %d: follower count */
				esc_html( _n( '%d follower', '%d followers', $follower_count, 'buddynext' ) ),
				absint( $follower_count )
			);
			?>
		</span>
	</div>
	<?php if ( $viewer_id && $viewer_id !== $user_id ) : ?>
		<button class="bn-btn bn-btn--sm <?php echo $is_following ? 'bn-btn--secondary bn-following' : 'bn-btn--primary'; ?>"
			data-action="bn-toggle-follow"
			data-user-id="<?php echo absint( $user_id ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'buddynext_follow_' . $user_id ) ); ?>">
			<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
		</button>
	<?php endif; ?>
</div>
