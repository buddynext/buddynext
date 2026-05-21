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

if ( null !== $bn_sidebar_widgets ) {
	$sbar_trending  = $bn_sidebar_widgets->trending_hashtags( 5 );
	$sbar_suggested = $bn_sidebar_widgets->suggested_follows( $sidebar_user_id, 3 );
	$sbar_spaces    = $bn_sidebar_widgets->joined_spaces( $sidebar_user_id, 4 );
} else {
	// Plug-and-play fallback — sidebar feature disabled or service unavailable.
	$sbar_trending  = array();
	$sbar_suggested = array();
	$sbar_spaces    = array();
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
