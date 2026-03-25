<?php
/**
 * BuddyNext — Community sidebar partial.
 *
 * Renders community discovery widgets shared across all hub pages:
 * trending hashtags, people to follow, and active/suggested spaces.
 * Rendered inside .bn-hub-shell as the right 300px sidebar column.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$sidebar_user_id = get_current_user_id();

// ── Trending hashtags (top 5 by post count) ──────────────────────────────
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$sbar_trending = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT slug, post_count FROM ' . $wpdb->prefix . 'bn_hashtags ORDER BY post_count DESC LIMIT %d',
		5
	)
);

// ── Suggested people to follow (up to 3) ─────────────────────────────────
$sbar_suggested = array();
if ( $sidebar_user_id ) {
	$sbar_suggested = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT u.ID, u.display_name, u.user_login
			 FROM ' . $wpdb->users . ' u
			 WHERE u.ID != %d
			   AND NOT EXISTS (
			       SELECT 1 FROM ' . $wpdb->prefix . 'bn_follows f
			       WHERE f.follower_id = %d AND f.following_id = u.ID
			   )
			   AND NOT EXISTS (
			       SELECT 1 FROM ' . $wpdb->prefix . 'bn_blocks bl
			       WHERE ( bl.blocker_id = %d AND bl.blocked_id = u.ID )
			          OR ( bl.blocker_id = u.ID AND bl.blocked_id = %d )
			   )
			 ORDER BY RAND()
			 LIMIT %d',
			$sidebar_user_id,
			$sidebar_user_id,
			$sidebar_user_id,
			$sidebar_user_id,
			3
		)
	);
}

// ── Active spaces (joined spaces, or open spaces for guests) ──────────────
if ( $sidebar_user_id ) {
	$sbar_spaces = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT s.id, s.name, s.slug, s.member_count, s.avatar_url
			 FROM ' . $wpdb->prefix . 'bn_spaces s
			 INNER JOIN ' . $wpdb->prefix . 'bn_space_members sm
			   ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = %s
			 ORDER BY s.member_count DESC
			 LIMIT %d',
			$sidebar_user_id,
			'active',
			4
		)
	);
} else {
	$sbar_spaces = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, name, slug, member_count, avatar_url
			 FROM ' . $wpdb->prefix . 'bn_spaces
			 WHERE type = %s
			 ORDER BY member_count DESC
			 LIMIT %d',
			'open',
			4
		)
	);
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

$sbar_spaces_url  = home_url( '/spaces/' );
$sbar_members_url = home_url( '/members/' );
?>
<aside class="bn-hub-sidebar" aria-label="<?php esc_attr_e( 'Community', 'buddynext' ); ?>">

	<?php if ( ! empty( $sbar_trending ) ) : ?>
	<div class="bn-sidebar-card">
		<div class="bn-sidebar-card__header"><?php esc_html_e( 'Trending Topics', 'buddynext' ); ?></div>
		<div class="bn-sidebar-card__body">
			<?php foreach ( $sbar_trending as $sbar_tag ) : ?>
			<div class="bn-sbar-row" style="justify-content:space-between">
				<a href="<?php echo esc_url( home_url( '/activity/hashtag/' . rawurlencode( $sbar_tag->slug ) . '/' ) ); ?>" class="bn-sbar-row__name" style="color:var(--brand)">#<?php echo esc_html( $sbar_tag->slug ); ?></a>
				<span class="bn-sbar-row__meta"><?php echo esc_html( number_format_i18n( (int) $sbar_tag->post_count ) ); ?> <?php esc_html_e( 'posts', 'buddynext' ); ?></span>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $sbar_suggested ) ) : ?>
	<div class="bn-sidebar-card">
		<div class="bn-sidebar-card__header"><?php esc_html_e( 'People to Follow', 'buddynext' ); ?></div>
		<div class="bn-sidebar-card__body">
			<?php foreach ( $sbar_suggested as $sbar_sug ) : ?>
				<?php
				$sbar_sug_avatar = get_avatar_url( (int) $sbar_sug->ID, array( 'size' => 40 ) );
				$sbar_sug_colour = buddynext_avatar_colour( (int) $sbar_sug->ID );
				$sbar_sug_url    = home_url( '/members/' . $sbar_sug->user_login . '/' );
				?>
			<div class="bn-sbar-row" style="gap:var(--s3)">
				<a href="<?php echo esc_url( $sbar_sug_url ); ?>" class="bn-sbar-row__avatar" style="background-color:<?php echo esc_attr( $sbar_sug_colour ); ?>" aria-label="<?php echo esc_attr( $sbar_sug->display_name ); ?>">
					<img src="<?php echo esc_attr( $sbar_sug_avatar ); ?>" alt="<?php echo esc_attr( $sbar_sug->display_name ); ?>" width="36" height="36" loading="lazy">
				</a>
				<span style="flex:1;min-width:0">
					<a href="<?php echo esc_url( $sbar_sug_url ); ?>" class="bn-sbar-row__name" style="display:block"><?php echo esc_html( $sbar_sug->display_name ); ?></a>
				</span>
				<button class="bn-sbar-row__action" type="button" data-user-id="<?php echo esc_attr( (string) $sbar_sug->ID ); ?>" aria-label="<?php /* translators: %s: display name */ printf( esc_attr__( 'Follow %s', 'buddynext' ), esc_attr( $sbar_sug->display_name ) ); ?>"><?php esc_html_e( 'Follow', 'buddynext' ); ?></button>
			</div>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_members_url ); ?>" class="bn-sidebar-see-all"><?php esc_html_e( 'See all members', 'buddynext' ); ?></a>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $sbar_spaces ) ) : ?>
	<div class="bn-sidebar-card">
		<div class="bn-sidebar-card__header"><?php echo esc_html( $sidebar_user_id ? __( 'Your Spaces', 'buddynext' ) : __( 'Discover Spaces', 'buddynext' ) ); ?></div>
		<div class="bn-sidebar-card__body">
			<?php foreach ( $sbar_spaces as $sbar_sp ) : ?>
				<?php
				$sbar_sp_url      = home_url( '/spaces/' . $sbar_sp->slug . '/' );
				$sbar_sp_initials = strtoupper( mb_substr( (string) $sbar_sp->name, 0, 2 ) );
				?>
			<a href="<?php echo esc_url( $sbar_sp_url ); ?>" class="bn-sbar-row" style="gap:var(--s2);text-decoration:none">
				<span class="bn-sbar-row__icon" aria-hidden="true">
					<?php if ( ! empty( $sbar_sp->avatar_url ) ) : ?>
						<img src="<?php echo esc_attr( $sbar_sp->avatar_url ); ?>" alt="" width="32" height="32" loading="lazy">
					<?php else : ?>
						<?php echo esc_html( $sbar_sp_initials ); ?>
					<?php endif; ?>
				</span>
				<span style="flex:1;min-width:0">
					<span class="bn-sbar-row__name" style="display:block"><?php echo esc_html( $sbar_sp->name ); ?></span>
					<span class="bn-sbar-row__meta"><?php echo esc_html( number_format_i18n( (int) $sbar_sp->member_count ) ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
				</span>
			</a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_spaces_url ); ?>" class="bn-sidebar-see-all"><?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?></a>
		</div>
	</div>
	<?php endif; ?>

</aside>
