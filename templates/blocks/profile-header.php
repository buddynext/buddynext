<?php
/**
 * Block template: Profile Header (v2 design system).
 *
 * Mini hero composition derived from v2/user-profile.html — avatar + identity
 * + stat grid + primary action. Built on the v2 attribute API:
 *   .bn-card[data-interactive], .bn-avatar[data-size], .bn-btn[data-variant],
 *   .bn-stat / .bn-stat-grid.
 *
 * Variables:
 *   int  $user_id      WordPress user ID (0 = viewed profile from URL context, falls back to current user).
 *   bool $show_stats   Whether to render follower/following counts (default true).
 *   bool $show_actions Whether to render follow / edit profile actions (default true).
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id      = $user_id ?? 0;
$show_stats   = isset( $show_stats ) ? (bool) $show_stats : true;
$show_actions = isset( $show_actions ) ? (bool) $show_actions : true;
$viewer_id    = get_current_user_id();

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

$bio_fields = array_filter( $profile['fields'] ?? array(), static fn( $f ) => 'bio' === ( $f['field_key'] ?? '' ) );
$bio_field  = reset( $bio_fields );
$bio        = $bio_field ? ( $bio_field['value'] ?? '' ) : get_user_meta( $user_id, 'bn_bio', true );
$avatar_url = (string) get_avatar_url( $user_id, array( 'size' => 144 ) );
?>
<section class="bn-card bn-block-profile-header" data-user-id="<?php echo absint( $user_id ); ?>">
	<div class="bn-profile-header__cover" aria-hidden="true"></div>
	<div class="bn-profile-header__body">
		<span class="bn-avatar bn-profile-header__avatar" data-size="2xl">
			<?php if ( '' !== $avatar_url ) : ?>
				<img
					src="<?php echo esc_url( $avatar_url ); ?>"
					alt=""
					width="96"
					height="96"
					loading="lazy"
					decoding="async"
				>
			<?php endif; ?>
		</span>
		<div class="bn-profile-header__info">
			<h2 class="bn-profile-header__name"><?php echo esc_html( $user->display_name ); ?></h2>
			<?php if ( ! empty( $bio ) ) : ?>
				<p class="bn-profile-header__bio"><?php echo esc_html( $bio ); ?></p>
			<?php endif; ?>

			<?php if ( $show_stats ) : ?>
				<div class="bn-stat-grid bn-profile-header__stats">
					<div class="bn-stat">
						<span class="bn-stat__label"><?php esc_html_e( 'Followers', 'buddynext' ); ?></span>
						<span class="bn-stat__value"><?php echo esc_html( number_format_i18n( $follower_count ) ); ?></span>
					</div>
					<div class="bn-stat">
						<span class="bn-stat__label"><?php esc_html_e( 'Following', 'buddynext' ); ?></span>
						<span class="bn-stat__value"><?php echo esc_html( number_format_i18n( $following_count ) ); ?></span>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $show_actions ) : ?>
			<?php if ( $viewer_id && $viewer_id !== $user_id ) : ?>
				<div class="bn-profile-header__actions">
					<?php
					// Reuse the canonical Interactivity-API follow button — the only
					// correctly hydrated implementation — instead of a bespoke
					// data-action button no JS binds. Hydrated off-hub via the block's
					// @buddynext/social-buttons viewScriptModule.
					buddynext_get_template( 'blocks/follow-button.php', array( 'user_id' => $user_id ) );
					?>
				</div>
			<?php elseif ( $viewer_id === $user_id ) : ?>
				<div class="bn-profile-header__actions">
					<a
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"
						class="bn-btn bn-profile-header__edit"
						data-variant="secondary"
						data-size="sm"
					>
						<?php esc_html_e( 'Edit profile', 'buddynext' ); ?>
					</a>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</section>
