<?php
/**
 * Template: Space Home (v2 inner).
 *
 * Renders the space hero (cover + identity + stats + actions) + tab nav
 * (Feed / Members / Media / About) + tab body, inside the shell main
 * column (`<main class="bn-app__main">` — see templates/shell/hub-shell.php).
 * This inner template does NOT own the rail or the
 * 2-column page grid. Sidebar widgets (about, members, top contributors)
 * are registered on the `buddynext_right_sidebar` action; the shell
 * auto-renders the right column when callbacks are present.
 *
 * v2 prototype: docs/v2 Plans/v2/space-home.html.
 *
 * Expected context var (set by template loader):
 *   $space_id (int) — the current space's primary key.
 *
 * Overridable: copy to {theme}/buddynext/spaces/home.php.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bn_space_category_icon' ) ) {
	/**
	 * Return inline SVG for a space category slug.
	 *
	 * @param string|null $cat_slug Category slug.
	 * @return string SVG markup.
	 */
	function bn_space_category_icon( ?string $cat_slug ): string {
		$map  = array(
			'technology'  => 'cpu',
			'design'      => 'image',
			'marketing'   => 'megaphone',
			'startups'    => 'rocket',
			'ai-ml'       => 'cpu',
			'data'        => 'bar-chart',
			'product'     => 'target',
			'writing'     => 'edit',
			'open-source' => 'globe',
			'business'    => 'briefcase',
			'creative'    => 'star',
		);
		$slug = $map[ (string) $cat_slug ] ?? 'home';
		return buddynext_get_icon( $slug );
	}
}

// ── Services ──────────────────────────────────────────────────────────────────

$bn_space_service  = new \BuddyNext\Spaces\SpaceService();
$bn_member_service = new \BuddyNext\Spaces\SpaceMemberService();
$bn_feed_service   = buddynext_service( 'feed' );

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// $space is the shared object shape (bare row + resolved category) every part
// reads (hero, about, members, feed, sidebar). SpaceService::get_object() is the
// single loader, also used by each space panel render, so the hub and a panel
// never resolve the row two different ways.
$space = $bn_space_service->get_object( $space_id );

if ( null === $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

$current_user_id = get_current_user_id();

// ── Current user's membership ─────────────────────────────────────────────────

$bn_member_role_now   = $current_user_id ? $bn_member_service->get_role( $space_id, $current_user_id ) : null;
$bn_member_status_now = $current_user_id ? $bn_member_service->get_status( $space_id, $current_user_id ) : null;

$membership = ( null !== $bn_member_status_now )
	? (object) array(
		'role'   => (string) $bn_member_role_now,
		'status' => (string) $bn_member_status_now,
	)
	: null;

$is_member  = $membership && 'active' === $membership->status;
$is_pending = $membership && 'pending' === $membership->status;
$is_invited = $membership && 'invited' === $membership->status;
$is_guest   = ( 0 === (int) $current_user_id );

// Posting permission (Permissions panel → "Who can post"): members | mods | owner.
// A site admin, or any member whose role meets the configured threshold, may post.
// This drives whether the composer is rendered in the feed panel; the rank rule
// lives in SpacePostGuard::can_post(), which the REST create path enforces too,
// so the visible composer and the server gate stay in lockstep.
$bn_can_post = $is_member && \BuddyNext\Spaces\SpacePostGuard::can_post( $space_id, $current_user_id );

// An archived space is read-only: no composer for anyone (mirrors the
// PostService/CommentService/join guards). A banner explains the state.
$bn_space_archived = ! empty( $space->is_archived );
if ( $bn_space_archived ) {
	$bn_can_post = false;
}

// Secret spaces are leak-proof: a logged-out visitor (or any non-member who
// isn't a site admin) reaches the canonical 404 surface so we never confirm
// the slug exists. Mirrors the visibility gate enforced by
// SpaceService::search() and the directory's `type != 'secret'` filter.
if ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_hidden_from_non_members( (string) $space->type ) && ! $is_member && ! current_user_can( 'manage_options' ) ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	include get_404_template();
	exit;
}

// Access gate: private + secret feeds. Open spaces never gate the feed, but
// guests still see a "Join to participate" CTA instead of the composer.
$gate_feed = ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->content_requires_membership( (string) $space->type ) && ! $is_member && ! current_user_can( 'manage_options' ) );

// ── Fetch posts for the feed ──────────────────────────────────────────────────
// All post data flows through FeedService, which hydrates each row via
// PostService::hydrate() — the same path the space-feed REST controller uses.

$feed_posts  = array();
$pinned_post = null;

if ( ! $gate_feed ) {
	// Pinned announcement (hydrated post array). The feed panel renders it as an
	// object and shows the author's name, which hydrate() does not carry, so we
	// enrich a single display_name onto the object before handing it over.
	$bn_pinned_arr = $bn_feed_service->space_pinned_post( $space_id );
	if ( is_array( $bn_pinned_arr ) ) {
		$bn_pinned_author             = get_userdata( (int) ( $bn_pinned_arr['user_id'] ?? 0 ) );
		$bn_pinned_arr['author_name'] = $bn_pinned_author ? $bn_pinned_author->display_name : __( 'Admin', 'buddynext' );
		$pinned_post                  = (object) $bn_pinned_arr;
	}

	// Regular feed posts (hydrated arrays; pinned post excluded by FeedService).
	$bn_space_feed = $bn_feed_service->space_feed( $space_id, $current_user_id, null, 20 );
	$feed_posts    = array_values(
		array_filter(
			(array) ( $bn_space_feed['items'] ?? array() ),
			static function ( $bn_p ) {
				// The pinned post leads as its own card above the feed, so drop it
				// from the regular list to avoid showing it twice.
				return empty( $bn_p['is_pinned'] );
			}
		)
	);
}

// Clean-URL active tab: /spaces/{slug}/{tab}/ → bn_space_action. Defaults to feed.
$active_tab = (string) get_query_var( 'bn_space_action', '' );
$active_tab = '' !== $active_tab ? sanitize_key( $active_tab ) : 'feed';

$bn_current_user = $current_user_id ? get_userdata( $current_user_id ) : null;
$rest_nonce      = wp_create_nonce( 'wp_rest' );

// ── Right sidebar (uniform across every space tab) ─────────────────────────────
// The shared part registers the space rail cards on buddynext_right_sidebar; the
// hub shell renders the right column when anything is hooked there. Every space
// template (home + members + moderation) calls this same part, so switching tabs
// keeps the same rail instead of dropping it on the dedicated pages.
buddynext_get_template(
	'parts/space-sidebar.php',
	array(
		'space_id'   => $space_id,
		'viewer_id'  => $current_user_id,
		'active_tab' => $active_tab,
	)
);

/**
 * Fires before the space-home inner content.
 *
 * @param int $space_id Current space ID.
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_space_home_before', $space_id, $current_user_id );

// ── Render ───────────────────────────────────────────────────────────────────

// Media tab availability mirrors SpaceNav's media gate; here it only guards the
// active-tab fallback (hitting /media/ while the space's media tab is off → Feed,
// so the gallery body branch never renders for a hidden tab).
$bn_media_tab_on = \BuddyNext\Media\MediaClient::available() && (bool) get_option( 'bn_space_' . $space->id . '_mvs_media_tab', 0 );
if ( 'media' === $active_tab && ! $bn_media_tab_on ) {
	$active_tab = 'feed';
}

// Discussions tab (Jetonomy) — mirrors the profile's in-hub Discussions panel.
// The bridge owns all jt_* access and maps the BN space to its linked forum.
// Hitting /discussions/ while Jetonomy is inactive falls back to Feed so the
// panel branch never renders for a hidden tab.
$bn_discussions_on = class_exists( 'Jetonomy\\Models\\Post' );
if ( 'discussions' === $active_tab && ! $bn_discussions_on ) {
	$active_tab = 'feed';
}
$bn_space_discussions = array();
$bn_forum_ctx         = array(
	'forum_url'     => '',
	'linked'        => false,
	'provision_url' => '',
);
if ( 'discussions' === $active_tab && ! $gate_feed ) {
	$bn_jt_bridge         = new \BuddyNext\Bridges\JetonomyBridge();
	$bn_space_discussions = $bn_jt_bridge->space_discussions( $space_id, 20 );
	$bn_forum_ctx         = $bn_jt_bridge->space_forum_context( $space_id );
}

// Space navigation comes from the unified registry (SpaceNav + bridges), gated,
// counted and ordered for THIS viewer's role — the same nav system + renderer the
// member profile uses. Rendered as clean-URL tabs by parts/nav-bar.php.
$bn_space_role = $is_member && isset( $membership->role ) ? (string) $membership->role : '';
$bn_space_ctx  = new \BuddyNext\Nav\NavContext( 'space', (int) $space_id, (int) $current_user_id, $bn_space_role );
$bn_space_nav  = buddynext_nav( $bn_space_ctx );
$bn_nav_items  = $bn_space_nav->layer( 'primary' );

// Registry-driven panel for the active tab: when its nav item carries a render,
// PanelRenderer paints it (the content seam). Tabs not yet migrated to a render
// fall through to the legacy branches in the tab body below.
$bn_panel_item = null;
foreach ( $bn_nav_items as $bn_pi ) {
	if ( $bn_pi->id === $active_tab ) {
		$bn_panel_item = $bn_pi;
		break;
	}
}
?>
<div class="bn-sh-stack"
	data-wp-interactive="buddynext/spaces"
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'restNonce' => $rest_nonce,
				'restUrl'   => rest_url( 'buddynext/v1' ),
			)
		)
	);
	?>
	'
>

	<!-- Hero + tab nav -->
	<?php
	// The one uniform header every space template renders. space-header.php
	// resolves membership, stats and the registry tabs from just the space id +
	// viewer, then delegates to space-hero.php — so home, members, moderation all
	// share a single header/nav instead of each hand-rolling its own copy. The
	// nav it resolves is the same NavContext the body resolves below, so
	// NavRegistry memoizes it (no double count query).
	buddynext_get_template(
		'parts/space-header.php',
		array(
			'space_id'   => $space_id,
			'active_tab' => $active_tab,
		)
	);
	?>

	<?php if ( $is_invited ) : ?>
		<!-- Pending space invitation for the current user -->
		<div class="bn-card bn-sh-invite" role="region" aria-label="<?php esc_attr_e( 'Space invitation', 'buddynext' ); ?>">
			<div class="bn-sh-invite__text">
				<span class="bn-sh-invite__icon" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
				<span><?php esc_html_e( "You've been invited to join this space.", 'buddynext' ); ?></span>
			</div>
			<div class="bn-sh-invite__actions">
				<button class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.acceptInvite">
					<?php esc_html_e( 'Accept', 'buddynext' ); ?>
				</button>
				<button class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.declineInvite">
					<?php esc_html_e( 'Decline', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	<?php endif; ?>

	<!-- Tab body -->
	<div class="bn-sh-body">
		<?php if ( $gate_feed ) : ?>

			<div class="bn-card bn-sh-gate">
				<div class="bn-sh-gate__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
				<h2 class="bn-sh-gate__title"><?php esc_html_e( 'This is a private space', 'buddynext' ); ?></h2>
				<p class="bn-sh-gate__lede">
					<?php
					echo $is_invited
						? esc_html__( 'Accept the invitation above to read posts and participate.', 'buddynext' )
						: esc_html__( 'Join to read posts and participate in discussions.', 'buddynext' );
					?>
				</p>
				<?php if ( $is_invited ) : ?>
					<?php // The invitation banner above owns Accept/Decline; the gate shows no join CTA. ?>
				<?php elseif ( $is_guest ) : ?>
					<a
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() . '?redirect_to=' . rawurlencode( buddynext_space_url( $space->slug ) ) ); ?>"
						class="bn-btn"
						data-variant="primary"
						data-size="md"
					>
						<?php esc_html_e( 'Log in to request access', 'buddynext' ); ?>
					</a>
				<?php elseif ( $is_pending ) : ?>
					<button
						class="bn-btn"
						data-variant="secondary"
						data-size="md"
						data-current-state="pending"
						data-wp-on--click="actions.cancelJoinRequest"
					>
						<?php esc_html_e( 'Request pending', 'buddynext' ); ?>
					</button>
				<?php else : ?>
					<button
						class="bn-btn"
						data-variant="primary"
						data-size="md"
						data-current-state="request"
						data-wp-on--click="actions.requestJoin"
					>
						<?php esc_html_e( 'Request to join', 'buddynext' ); ?>
					</button>
				<?php endif; ?>
			</div>

		<?php elseif ( null !== $bn_panel_item && $bn_panel_item->has_render() ) : ?>

			<?php ( new \BuddyNext\Nav\PanelRenderer() )->render_panels( $bn_space_nav, $bn_space_ctx, $active_tab ); ?>

		<?php elseif ( 'discussions' === $active_tab && $bn_discussions_on ) : ?>

			<?php
			buddynext_get_template(
				'parts/space-discussions-panel.php',
				array(
					'space'         => $space,
					'discussions'   => $bn_space_discussions,
					'forum_url'     => (string) $bn_forum_ctx['forum_url'],
					'forum_linked'  => (bool) $bn_forum_ctx['linked'],
					'provision_url' => (string) $bn_forum_ctx['provision_url'],
					'can_post'      => $bn_can_post,
				)
			);
			?>

		<?php else : ?>

			<?php if ( $bn_space_archived ) : ?>
				<div class="bn-notice" role="status">
					<?php esc_html_e( 'This space is archived. You can still read past activity, but new posts, comments, and joins are disabled.', 'buddynext' ); ?>
				</div>
			<?php endif; ?>

			<?php
			buddynext_get_template(
				'parts/space-feed-panel.php',
				array(
					'space'        => $space,
					'space_id'     => $space_id,
					'viewer_id'    => $current_user_id,
					'is_member'    => $is_member,
					'can_post'     => $bn_can_post,
					'is_guest'     => $is_guest,
					'is_pending'   => $is_pending,
					'posts'        => $feed_posts,
					'pinned_post'  => $pinned_post,
					'current_user' => $bn_current_user,
				)
			);
			?>

		<?php endif; ?>
	</div>

	<?php
	// Share modal — the space feed renders post cards whose Share action
	// dispatches bn-open-share-modal, but without this include there is no modal
	// island to receive it, so Share did nothing inside a space.
	buddynext_get_template(
		'partials/share-modal.php',
		array( 'current_user_id' => $current_user_id )
	);
	?>

</div>
<?php
/**
 * Fires after the space-home inner content.
 *
 * @param int $space_id Current space ID.
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_space_home_after', $space_id, $current_user_id );
