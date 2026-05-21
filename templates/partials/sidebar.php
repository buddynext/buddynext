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

global $wpdb;

$sidebar_user_id = isset( $sidebar_user_id ) ? absint( $sidebar_user_id ) : get_current_user_id();

/*
 * Scale contract — docs/specs/SCALE-CONTRACT.md.
 *
 * The sidebar fires on every BN hub page render. At 100k sites × 100k
 * members × every page load that's billions of queries per day without
 * caching. Each of the three widgets goes through wp_cache_get first.
 *
 * Cache keys are user-scoped (suggested + spaces depend on user) or
 * global (trending hashtags). TTLs: 60s for fast-moving aggregates,
 * 300s for joined-space lists that change less often.
 *
 * Cache invalidation hooks (registered in PageRouter::init):
 *   - buddynext_post_created / _hashtag_indexed → delete trending key
 *   - buddynext_user_followed / _unfollowed → delete suggested:{user} key
 *   - buddynext_space_member_joined / _left → delete spaces:{user} key
 */

// ── Trending hashtags (top 5 by post count) — 60s TTL ─────────────────────
$sbar_trending = wp_cache_get( 'sidebar:trending:v1', 'buddynext_widgets' );
if ( false === $sbar_trending ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sbar_trending = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT slug, post_count FROM ' . $wpdb->prefix . 'bn_hashtags ORDER BY post_count DESC LIMIT %d',
			5
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	wp_cache_set( 'sidebar:trending:v1', $sbar_trending, 'buddynext_widgets', 60 );
}

// ── Suggested people to follow (up to 3) — 300s TTL ─────────────────────
// ORDER BY RAND() is expensive on large user tables. At scale, replace
// with a precomputed candidate pool (P2 AI signals or static
// "most-followed-not-yet-followed" table). For now the cache absorbs
// the worst case so the query runs ~1×/300s/user, not per page load.
$sbar_suggested = array();
if ( $sidebar_user_id ) {
	$sug_key       = 'sidebar:suggested:v1:' . (int) $sidebar_user_id;
	$sbar_suggested = wp_cache_get( $sug_key, 'buddynext_user_meta' );
	if ( false === $sbar_suggested ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_set( $sug_key, $sbar_suggested, 'buddynext_user_meta', 300 );
	}
}

// ── Active spaces (joined for members, open for guests) — 300s TTL ──────
$spaces_key  = 'sidebar:spaces:v1:' . (int) $sidebar_user_id;
$sbar_spaces = wp_cache_get( $spaces_key, 'buddynext_user_meta' );
if ( false === $sbar_spaces ) {
	if ( $sidebar_user_id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	} else {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	wp_cache_set( $spaces_key, $sbar_spaces, 'buddynext_user_meta', 300 );
}

$sbar_spaces_url  = home_url( '/spaces/' );
$sbar_members_url = home_url( '/members/' );
?>

<?php if ( ! empty( $sbar_trending ) ) : ?>
	<div class="bn-sidebar-card">
		<div class="bn-sidebar-card__header"><?php esc_html_e( 'Trending Topics', 'buddynext' ); ?></div>
		<div class="bn-sidebar-card__body">
			<?php foreach ( $sbar_trending as $sbar_tag ) : ?>
				<div class="bn-sbar-row">
					<a href="<?php echo esc_url( home_url( '/activity/hashtag/' . rawurlencode( $sbar_tag->slug ) . '/' ) ); ?>"
						class="bn-sbar-row__name">
						#<?php echo esc_html( $sbar_tag->slug ); ?>
					</a>
					<span class="bn-sbar-row__meta">
						<?php echo esc_html( number_format_i18n( (int) $sbar_tag->post_count ) ); ?>
						<?php esc_html_e( 'posts', 'buddynext' ); ?>
					</span>
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
				$sbar_sug_colour = function_exists( 'buddynext_avatar_colour' ) ? buddynext_avatar_colour( (int) $sbar_sug->ID ) : '#cccccc';
				$sbar_sug_url    = home_url( '/members/' . $sbar_sug->user_login . '/' );
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
					</span>
					<button class="bn-sbar-row__action"
						type="button"
						data-user-id="<?php echo esc_attr( (string) $sbar_sug->ID ); ?>"
						aria-label="<?php /* translators: %s: display name */ printf( esc_attr__( 'Follow %s', 'buddynext' ), esc_attr( $sbar_sug->display_name ) ); ?>">
						<?php esc_html_e( 'Follow', 'buddynext' ); ?>
					</button>
				</div>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_members_url ); ?>" class="bn-sidebar-see-all">
				<?php esc_html_e( 'See all members', 'buddynext' ); ?>
			</a>
		</div>
	</div>
<?php endif; ?>

<?php if ( ! empty( $sbar_spaces ) ) : ?>
	<div class="bn-sidebar-card">
		<div class="bn-sidebar-card__header">
			<?php echo esc_html( $sidebar_user_id ? __( 'Your Spaces', 'buddynext' ) : __( 'Discover Spaces', 'buddynext' ) ); ?>
		</div>
		<div class="bn-sidebar-card__body">
			<?php foreach ( $sbar_spaces as $sbar_sp ) : ?>
				<?php
				$sbar_sp_url      = home_url( '/spaces/' . $sbar_sp->slug . '/' );
				$sbar_sp_initials = strtoupper( mb_substr( (string) $sbar_sp->name, 0, 2 ) );
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
							<?php echo esc_html( number_format_i18n( (int) $sbar_sp->member_count ) ); ?>
							<?php esc_html_e( 'members', 'buddynext' ); ?>
						</span>
					</span>
				</a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( $sbar_spaces_url ); ?>" class="bn-sidebar-see-all">
				<?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?>
			</a>
		</div>
	</div>
<?php endif; ?>
