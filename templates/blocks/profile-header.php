<?php
/**
 * Block template: Profile Header
 *
 * Variables:
 *   int $user_id WordPress user ID (0 = viewed profile from URL context, falls back to current user)
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id   = $user_id ?? 0;
$viewer_id = get_current_user_id();

if ( ! $user_id ) {
	$user_id = (int) get_query_var( 'author' );
}

if ( ! $user_id ) {
	$user_id = $viewer_id;
}

if ( ! $user_id ) {
	return;
}

$user    = get_userdata( $user_id );
$profile = buddynext_service( 'profiles' )->get_profile( $user_id, $viewer_id );

if ( ! $user ) {
	return;
}

$follow_svc      = buddynext_service( 'follows' );
$follower_count  = $follow_svc->follower_count( $user_id );
$following_count = $follow_svc->following_count( $user_id );
$is_following    = $viewer_id && $viewer_id !== $user_id
	? $follow_svc->is_following( $viewer_id, $user_id )
	: false;

$bio = $profile['fields']['bio'] ?? get_user_meta( $user_id, 'description', true );
?>
<div class="bn-block-profile-header" data-user-id="<?php echo absint( $user_id ); ?>">
	<div class="bn-profile-header__cover"></div>
	<div class="bn-profile-header__body">
		<?php echo get_avatar( $user_id, 80, '', '', array( 'class' => 'bn-avatar bn-avatar--xl bn-profile-header__avatar' ) ); ?>
		<div class="bn-profile-header__info">
			<h2 class="bn-profile-header__name"><?php echo esc_html( $user->display_name ); ?></h2>
			<?php if ( ! empty( $bio ) ) : ?>
				<p class="bn-profile-header__bio"><?php echo esc_html( $bio ); ?></p>
			<?php endif; ?>
			<div class="bn-profile-header__stats">
				<span class="bn-profile-stat">
					<strong><?php echo absint( $follower_count ); ?></strong>
					<?php esc_html_e( 'followers', 'buddynext' ); ?>
				</span>
				<span class="bn-profile-stat">
					<strong><?php echo absint( $following_count ); ?></strong>
					<?php esc_html_e( 'following', 'buddynext' ); ?>
				</span>
			</div>
		</div>
		<?php if ( $viewer_id && $viewer_id !== $user_id ) : ?>
			<div class="bn-profile-header__actions">
				<button class="bn-btn bn-btn--sm <?php echo $is_following ? 'bn-btn--secondary bn-following' : 'bn-btn--primary'; ?>"
					data-action="bn-toggle-follow"
					data-user-id="<?php echo absint( $user_id ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'buddynext_follow_' . $user_id ) ); ?>">
					<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
				</button>
			</div>
		<?php elseif ( $viewer_id === $user_id ) : ?>
			<div class="bn-profile-header__actions">
				<a href="<?php echo esc_url( home_url( '/profile/edit/' ) ); ?>" class="bn-btn bn-btn--sm bn-btn--secondary">
					<?php esc_html_e( 'Edit profile', 'buddynext' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</div>
