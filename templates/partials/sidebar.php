<?php
/**
 * BuddyNext — Community sidebar partial (widgets only).
 *
 * Renders community-discovery widgets that hub templates inject into the
 * shell right-sidebar slot via the `buddynext_right_sidebar` action.
 * The shell (`templates/shell/right-sidebar.php`) already provides the
 * outer `<aside class="bn-app__right">` wrapper — this partial only
 * emits the `.bn-sidebar-card` widget blocks inside it.
 *
 * Three cards: trending hashtags, suggested people-to-follow, joined/
 * suggested spaces.
 *
 * Context variables:
 *   int $sidebar_user_id  Current user ID (0 for guest).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$sidebar_user_id = isset( $sidebar_user_id ) ? absint( $sidebar_user_id ) : get_current_user_id();

/*
 * Sidebar widget data — consumed via Sidebar\WidgetService when the
 * feature is enabled (default), otherwise inline-queried for back-compat.
 *
 * MODULAR-ARCHITECTURE.md Layer 2 / Layer 3 boundary: this template
 * (Layer 3 UI) calls the Service (Layer 2 Feature). The Service owns
 * caching + cache-bust hooks via WidgetCache + WidgetListener.
 *
 * Scale contract (docs/specs/SCALE-CONTRACT.md): caching is mandatory
 * here because the sidebar fires on every BN hub page render. The
 * service-backed path uses object cache; the fallback path runs raw
 * queries every render and is only used when buddynext_feature_sidebar
 * filter disables the feature.
 */
$bn_sidebar_widgets = function_exists( 'buddynext_service' ) && \BuddyNext\Core\Container::instance()->has( 'sidebar_widgets' )
	? buddynext_service( 'sidebar_widgets' )
	: null;

// Spaces widgets are only relevant when the Spaces feature is enabled; when the
// site owner turns it off the "Your Spaces" card is hidden entirely (and its
// query skipped) so the activity sidebar doesn't render dead links to /spaces/
// (which the router redirects back to /activity/).
$bn_spaces_on = function_exists( 'buddynext_service' )
	&& is_object( buddynext_service( 'features' ) )
	&& buddynext_service( 'features' )->is_enabled( 'spaces' );

if ( null !== $bn_sidebar_widgets ) {
	$sbar_trending  = $bn_sidebar_widgets->trending_hashtags( 5 );
	$sbar_suggested = $bn_sidebar_widgets->suggested_follows( $sidebar_user_id, 3 );
	$sbar_spaces    = $bn_spaces_on ? $bn_sidebar_widgets->joined_spaces( $sidebar_user_id, 4 ) : array();
} else {
	// Plug-and-play fallback — sidebar feature disabled or service unavailable.
	$sbar_trending  = array();
	$sbar_suggested = array();
	$sbar_spaces    = array();
}

$sbar_spaces_url  = home_url( '/spaces/' );
$sbar_members_url = home_url( '/members/' );
?>

<?php
// Personalized greeting + activity-streak card (v2 prototype right-sidebar
// opener). The part returns silently for anonymous viewers, so the
// existing three discovery cards still lead for guests.
if ( $sidebar_user_id > 0 ) {
	buddynext_get_template(
		'parts/sidebar-greeting-streak.php',
		array(
			'user_id' => $sidebar_user_id,
		)
	);
}

// "By role" member-summary card — surfaces total members + per-role
// counts. Scoped to the member-directory hub (matches the v2 prototype
// placement) so feed / messages / notifications keep their leaner
// sidebars. Filterable so site owners can broaden the scope.
// The member-directory composer wires its own sidebar (online-now +
// member-types) and consequently doesn't include this partial. The BY
// ROLE card lives next to those member-directory-specific cards, so it
// registers directly from templates/directory/members.php rather than
// here. This partial intentionally does NOT add it — that would
// double-render on any future surface that ever opts both paths in.
?>

<div class="bn-sidebar-card">
	<div class="bn-sidebar-card__header">
		<?php esc_html_e( 'Trending Topics', 'buddynext' ); ?>
		<span class="bn-sidebar-card__caption"><?php esc_html_e( 'This week', 'buddynext' ); ?></span>
	</div>
	<div class="bn-sidebar-card__body">
		<?php if ( ! empty( $sbar_trending ) ) : ?>
			<?php foreach ( $sbar_trending as $sbar_tag ) : ?>
				<div class="bn-sbar-row">
					<a href="<?php echo esc_url( home_url( '/activity/hashtag/' . rawurlencode( $sbar_tag->slug ) . '/' ) ); ?>"
						class="bn-sbar-row__name">
						#<?php echo esc_html( $sbar_tag->slug ); ?>
					</a>
					<span class="bn-sbar-row__meta">
						<?php
						$sbar_tag_count = (int) $sbar_tag->post_count;
						printf(
							/* translators: %s: formatted post count */
							esc_html( _n( '%s post', '%s posts', $sbar_tag_count, 'buddynext' ) ),
							esc_html( number_format_i18n( $sbar_tag_count ) )
						);
						?>
					</span>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="bn-sidebar-card__empty">
				<?php esc_html_e( 'No trending topics yet.', 'buddynext' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

<div class="bn-sidebar-card">
	<div class="bn-sidebar-card__header">
		<?php esc_html_e( 'People to Follow', 'buddynext' ); ?>
	</div>
	<div class="bn-sidebar-card__body">
		<?php if ( ! empty( $sbar_suggested ) ) : ?>
			<?php foreach ( $sbar_suggested as $sbar_sug ) : ?>
				<?php
				$sbar_sug_id     = (int) ( $sbar_sug->ID ?? 0 );
				$sbar_sug_avatar = get_avatar_url( $sbar_sug_id, array( 'size' => 40 ) );
				$sbar_sug_url    = home_url( '/members/' . $sbar_sug->user_login . '/' );
				$sbar_sug_status = (string) ( $sbar_sug->follow_status ?? 'unfollowed' );
				?>
				<div class="bn-sbar-row">
					<a href="<?php echo esc_url( $sbar_sug_url ); ?>"
						class="bn-sbar-row__avatar"
						aria-label="<?php echo esc_attr( $sbar_sug->display_name ); ?>">
						<img src="<?php echo esc_url( $sbar_sug_avatar ); ?>"
							alt="<?php echo esc_attr( $sbar_sug->display_name ); ?>"
							width="36"
							height="36"
							loading="lazy">
					</a>
					<span class="bn-sbar-row__info">
						<a href="<?php echo esc_url( $sbar_sug_url ); ?>" class="bn-sbar-row__name">
							<?php echo esc_html( $sbar_sug->display_name ); ?>
						</a>
						<?php if ( 'requested' === $sbar_sug_status ) : ?>
							<span class="bn-sbar-row__meta"><?php esc_html_e( 'Request sent', 'buddynext' ); ?></span>
						<?php endif; ?>
					</span>
					<?php
					$follow_user_id = $sbar_sug_id;
					buddynext_get_template(
						'partials/follow-button.php',
						array( 'user_id' => $follow_user_id )
					);
					?>
				</div>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_members_url ); ?>" class="bn-sidebar-see-all">
				<?php esc_html_e( 'See all members', 'buddynext' ); ?>
			</a>
		<?php else : ?>
			<p class="bn-sidebar-card__empty">
				<?php esc_html_e( "We'll suggest people once you've completed onboarding.", 'buddynext' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>

<?php if ( $bn_spaces_on ) : ?>
<div class="bn-sidebar-card">
	<div class="bn-sidebar-card__header">
		<?php echo esc_html( $sidebar_user_id ? __( 'Your Spaces', 'buddynext' ) : __( 'Discover Spaces', 'buddynext' ) ); ?>
	</div>
	<div class="bn-sidebar-card__body">
		<?php if ( ! empty( $sbar_spaces ) ) : ?>
			<?php foreach ( $sbar_spaces as $sbar_sp ) : ?>
				<?php
				$sbar_sp_url      = home_url( '/spaces/' . $sbar_sp->slug . '/' );
				$sbar_sp_initials = strtoupper( mb_substr( (string) $sbar_sp->name, 0, 2 ) );
				$sbar_sp_unread   = isset( $sbar_sp->unread_count ) ? (int) $sbar_sp->unread_count : 0;
				?>
				<a href="<?php echo esc_url( $sbar_sp_url ); ?>" class="bn-sbar-row bn-sbar-row--link">
					<span class="bn-sbar-row__icon" aria-hidden="true">
						<?php if ( ! empty( $sbar_sp->avatar_url ) ) : ?>
							<img src="<?php echo esc_url( $sbar_sp->avatar_url ); ?>" alt="" width="32" height="32" loading="lazy">
						<?php else : ?>
							<?php echo esc_html( $sbar_sp_initials ); ?>
						<?php endif; ?>
					</span>
					<span class="bn-sbar-row__info">
						<span class="bn-sbar-row__name"><?php echo esc_html( $sbar_sp->name ); ?></span>
						<span class="bn-sbar-row__meta">
							<?php $bn_sbar_mc = (int) $sbar_sp->member_count; /* translators: %s: formatted member count */ printf( esc_html( _n( '%s member', '%s members', $bn_sbar_mc, 'buddynext' ) ), esc_html( number_format_i18n( $bn_sbar_mc ) ) ); ?>
							<?php // Count + "members" rendered together above via _n(). ?>
						</span>
					</span>
					<?php if ( $sbar_sp_unread > 0 ) : ?>
						<span class="bn-sbar-row__unread"
							aria-label="
							<?php
							/* translators: %d: unread space posts count */
							echo esc_attr( sprintf( _n( '%d unread post', '%d unread posts', $sbar_sp_unread, 'buddynext' ), $sbar_sp_unread ) );
							?>
							"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_spaces_url ); ?>" class="bn-sidebar-see-all">
				<?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?>
			</a>
		<?php else : ?>
			<p class="bn-sidebar-card__empty">
				<?php esc_html_e( 'Join your first space.', 'buddynext' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
<?php endif; /* $bn_spaces_on */ ?>
