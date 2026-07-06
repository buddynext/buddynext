<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Hub shell left navigation rail.
 *
 * Renders the persistent vertical navigation column inside .bn-app__shell.
 * The mobile bottom-bar nav (.bn-mobile-nav) lives in partials/nav.php
 * and is rendered by hub-shell.php on every BN hub — at <= 768px the
 * rail hides and the mobile bottom tab bar takes over (matching the
 * Home / Spaces / + / Alerts / Profile pattern in
 * docs/v2 Plans/v2/mobile.html).
 *
 * Context variables (all optional):
 *   $hub  string  Current hub slug (feed / people / spaces / messages / …).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

if ( ! isset( $hub ) ) {
	$hub = (string) get_query_var( 'bn_hub', '' );
}

$bn_rail_current_user = get_current_user_id();
$bn_unread_notifs     = 0;
$bn_unread_messages   = 0;

// Canonical shell item list (Feed / Explore / Members / Spaces / Notifications /
// Messages + the personal "You" group), with show-flags + unread badges, built once
// in Nav\ShellNavService so the rail and the GET /shell-nav REST endpoint (native
// app) can never drift. Owner overrides are applied by the buddynext_rail_items
// filter below, exactly as before.
$bn_rail_items = buddynext_service( 'shell_nav' )->base_items( $bn_rail_current_user );

// Sub-section active states the base list can't infer (they depend on the current
// page, not just the hub): light up Bookmarks / Settings when their sub-view is open.
if ( $bn_rail_current_user ) {
	$bn_bookmarks_active = ( 'feed' === $hub && 'bookmarks' === (string) get_query_var( 'bn_feed_section', '' ) );
	$bn_settings_active  = ( 'settings' === $hub || ( 'notifications' === $hub && 'prefs' === (string) get_query_var( 'bn_notif_section', '' ) ) );
	foreach ( $bn_rail_items as &$bn_ri ) {
		$bn_ri_key = (string) ( $bn_ri['key'] ?? '' );
		if ( 'bookmarks' === $bn_ri_key ) {
			$bn_ri['active'] = $bn_bookmarks_active;
		} elseif ( 'settings' === $bn_ri_key ) {
			$bn_ri['active'] = $bn_settings_active;
		}
	}
	unset( $bn_ri );
}

/**
 * Filter the left-rail navigation items.
 *
 * Bridge plugins use this to inject extra surface links. Each item is an
 * array with keys: key (string id), label (string), url (string), icon
 * (BuddyNext icon slug), show (bool), badge (int optional), active (bool
 * optional — set true to force the highlighted state when the link points
 * at a surface outside BuddyNext's own hubs, e.g. a bridged forum).
 *
 * @param array<int,array<string,mixed>> $items Rail item definitions.
 * @param string                         $hub   Current hub slug.
 */
$bn_rail_items = apply_filters( 'buddynext_rail_items', $bn_rail_items, $hub );

// Active item — match current hub.
$bn_rail_active = '';
foreach ( $bn_rail_items as $bn_item ) {
	if ( ! empty( $bn_item['key'] ) && $bn_item['key'] === $hub ) {
		$bn_rail_active = $bn_item['key'];
		break;
	}
}
if ( '' === $bn_rail_active && 'feed' === $hub ) {
	$bn_rail_active = 'feed';
}
// Explore shares the feed hub but is the public/discovery feed (distinct from the
// personal Activity feed), so it owns the rail's active state on /activity/explore/
// instead of the Activity row.
if ( 'feed' === $hub && 'explore' === (string) get_query_var( 'bn_activity_action', '' ) ) {
	$bn_rail_active = 'explore';
}

// When any item declares an explicit `active` flag (a person-specific bridged
// surface like "my media" / "my discussions", or a bookmarks/settings sub-route),
// it wins outright: the generic hub-match fallback is suppressed so we never light
// up two rows at once. E.g. on /members/{me}/discussions/ the explicit Discussions
// item is active and the people-hub "Members" row is not.
$bn_has_explicit_active = false;
foreach ( $bn_rail_items as $bn_item ) {
	if ( ! empty( $bn_item['active'] ) ) {
		$bn_has_explicit_active = true;
		break;
	}
}

// Single renderer for one rail link, shared by the community group and the "You"
// group below so both stay byte-identical. An item is active when it carries an
// explicit `active` flag (bridged surfaces, bookmarks/settings sub-routes) or its
// key matches the resolved active hub (only when no explicit-active item claims it).
$bn_render_rail_item = static function ( array $bn_item ) use ( $bn_rail_active, $bn_has_explicit_active ): void {
	$bn_is_active = ! empty( $bn_item['active'] )
		|| ( ! $bn_has_explicit_active && ! empty( $bn_item['key'] ) && $bn_item['key'] === $bn_rail_active );
	$bn_icon_slug = ! empty( $bn_item['icon'] ) ? (string) $bn_item['icon'] : 'home';
	$bn_badge     = isset( $bn_item['badge'] ) ? (int) $bn_item['badge'] : 0;
	?>
	<a
		href="<?php echo esc_url( (string) $bn_item['url'] ); ?>"
		class="bn-rail__item"
		title="<?php echo esc_attr( (string) $bn_item['label'] ); ?>"
		<?php echo $bn_is_active ? 'aria-current="page"' : ''; ?>
	>
		<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( $bn_icon_slug ); ?></span>
		<span class="bn-rail__label"><?php echo esc_html( (string) $bn_item['label'] ); ?></span>
		<?php if ( $bn_badge > 0 ) : ?>
			<span class="bn-rail__badge"><?php echo esc_html( $bn_badge > 99 ? '99+' : (string) $bn_badge ); ?></span>
		<?php endif; ?>
	</a>
	<?php
};

// Split the override-applied items into the community group (top, no heading) and
// the personal "You" group (bottom, under its heading). Hidden items (show=false,
// set by the admin override) drop out here.
$bn_main_items = array();
$bn_you_items  = array();
foreach ( $bn_rail_items as $bn_item ) {
	if ( empty( $bn_item['show'] ) ) {
		continue;
	}
	if ( 'you' === (string) ( $bn_item['group'] ?? '' ) ) {
		$bn_you_items[] = $bn_item;
	} else {
		$bn_main_items[] = $bn_item;
	}
}
?>
<nav class="bn-app__rail" data-bn-nav aria-label="<?php esc_attr_e( 'Community navigation', 'buddynext' ); ?>">
	<?php
	// Community logo (Settings → Appearance → buddynext_logo_url). Rendered at
	// the top of the rail, linking home, so an uploaded logo actually appears in
	// the site navigation — previously the option was only consumed by the auth
	// page and emails.
	$bn_rail_logo = (string) get_option( 'buddynext_logo_url', '' );
	// Header row: optional community logo on the left, compact collapse toggle on
	// the right. Grouping them in one flex row keeps spacing consistent and stops
	// the logo and the control from overlapping. The collapse toggle shrinks the
	// rail to an icon-only panel; state is persisted in localStorage and stamped
	// on <html data-bn-rail> before paint by assets/js/shell/font-scale.js.
	?>
	<div class="bn-rail__head">
		<?php if ( '' !== $bn_rail_logo ) : ?>
			<a class="bn-rail__logo" href="<?php echo esc_url( PageRouter::activity_url() ); ?>" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<img src="<?php echo esc_url( $bn_rail_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			</a>
		<?php endif; ?>
		<button
			type="button"
			class="bn-rail__toggle"
			data-bn-action="toggle-rail"
			aria-label="<?php esc_attr_e( 'Collapse navigation', 'buddynext' ); ?>"
			title="<?php esc_attr_e( 'Collapse navigation', 'buddynext' ); ?>"
		>
			<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( 'chevron-left' ); ?></span>
		</button>
	</div>
	<div class="bn-rail__group">
		<?php
		foreach ( $bn_main_items as $bn_item ) :
			$bn_render_rail_item( $bn_item );

			// My-spaces switcher: a JS-free expandable flyout under the Spaces item so
			// a member of many spaces can jump straight to one without opening the full
			// directory (the sidebar widget caps at a handful). Only when they belong
			// to at least one space.
			if ( 'spaces' === (string) ( $bn_item['key'] ?? '' ) && $bn_rail_current_user ) :
				// Preview only the most-recently-joined spaces in the rail; the count
				// badge shows the true total and a "See all" link opens /spaces/mine/
				// so a member of hundreds of spaces gets a short flyout, not a wall.
				$bn_spaces_preview = 5;
				$bn_my_spaces      = buddynext_service( 'space_members' )->membership_rows( $bn_rail_current_user, $bn_spaces_preview );
				$bn_spaces_total   = buddynext_service( 'space_members' )->count_memberships( $bn_rail_current_user );
				if ( ! empty( $bn_my_spaces ) ) :
					$bn_spaces_mine_url = trailingslashit( PageRouter::spaces_url() ) . 'mine/';
					?>
					<details class="bn-rail__spaces">
						<summary class="bn-rail__spaces-toggle">
							<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( 'layers' ); ?></span>
							<span class="bn-rail__spaces-title"><?php esc_html_e( 'My spaces', 'buddynext' ); ?></span>
							<span class="bn-rail__spaces-count"><?php echo esc_html( (string) $bn_spaces_total ); ?></span>
							<span class="bn-rail__spaces-chevron" aria-hidden="true"><?php buddynext_icon( 'chevron-down' ); ?></span>
						</summary>
						<ul class="bn-rail__spaces-list">
							<?php foreach ( $bn_my_spaces as $bn_sp ) : ?>
								<li>
									<a class="bn-rail__spaces-link" href="<?php echo esc_url( PageRouter::space_url( (int) $bn_sp->id ) ); ?>" title="<?php echo esc_attr( (string) $bn_sp->name ); ?>">
										<?php echo esc_html( (string) $bn_sp->name ); ?>
									</a>
								</li>
							<?php endforeach; ?>
							<?php if ( $bn_spaces_total > count( $bn_my_spaces ) ) : ?>
								<li>
									<a class="bn-rail__spaces-link bn-rail__spaces-all" href="<?php echo esc_url( $bn_spaces_mine_url ); ?>">
										<?php
										/* translators: %d: total number of spaces the member belongs to. */
										echo esc_html( sprintf( __( 'See all %d spaces', 'buddynext' ), $bn_spaces_total ) );
										?>
									</a>
								</li>
							<?php endif; ?>
						</ul>
					</details>
					<?php
				endif;
			endif;
		endforeach;
		?>
	</div>

	<?php if ( ! empty( $bn_you_items ) ) : ?>
		<div class="bn-rail__group">
			<div class="bn-rail__heading"><?php esc_html_e( 'You', 'buddynext' ); ?></div>
			<?php foreach ( $bn_you_items as $bn_item ) : ?>
				<?php $bn_render_rail_item( $bn_item ); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</nav>
