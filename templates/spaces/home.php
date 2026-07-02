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
$is_invited = $membership && 'invited' === $membership->status;

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
// guests still see a "Join to participate" CTA instead of the composer. The feed
// data itself is fetched by the feed panel's render (SpaceNav::render_feed_panel),
// so it runs only when the Feed tab is the active panel — never when viewing About.
$gate_feed = ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->content_requires_membership( (string) $space->type ) && ! $is_member && ! current_user_can( 'manage_options' ) );

// Clean-URL active tab: /spaces/{slug}/{tab}/ → bn_space_action. Defaults to feed.
$active_tab = (string) get_query_var( 'bn_space_action', '' );
$active_tab = '' !== $active_tab ? sanitize_key( $active_tab ) : 'feed';

$rest_nonce = wp_create_nonce( 'wp_rest' );

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

// Space navigation comes from the unified registry (SpaceNav + bridges), gated,
// counted and ordered for THIS viewer's role — the same nav system + renderer the
// member profile uses. Rendered as clean-URL tabs by parts/nav-bar.php.
$bn_space_role = $is_member && isset( $membership->role ) ? (string) $membership->role : '';
$bn_space_ctx  = new \BuddyNext\Nav\NavContext( 'space', (int) $space_id, (int) $current_user_id, $bn_space_role );
$bn_space_nav  = buddynext_nav( $bn_space_ctx );
$bn_nav_items  = $bn_space_nav->layer( 'primary' );

// Normalize the active tab to a panel the registry can actually render. Every
// in-hub tab (feed/about/media/discussions) carries a render; a tab that is hidden
// for this viewer/space (e.g. Media when the option is off, Discussions when
// Jetonomy is inactive) is absent from the resolved nav, and an unknown/stale URL
// matches nothing — both fall back to Feed, the space's home panel. This also sets
// the header's active-tab highlight (rendered just below), so the two agree.
$bn_active_renderable = false;
foreach ( $bn_nav_items as $bn_pi ) {
	if ( $bn_pi->id === $active_tab && $bn_pi->has_render() ) {
		$bn_active_renderable = true;
		break;
	}
}
if ( ! $bn_active_renderable ) {
	$active_tab = 'feed';
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
				<?php
				// The gate card is purely informational. The space hero (always
				// rendered in the header) owns the single primary CTA for every
				// state — guest "Log in to join", pending "Request pending", and
				// "Request to join" — so repeating it here produced two identical
				// buttons on one page. One primary CTA per page, matching how
				// Facebook/LinkedIn present a gated group.
				?>
			</div>

		<?php else : ?>

			<?php
			// Every in-hub tab renders through the registry content seam now — the
			// active panel (feed/about/media/discussions), and only that one, paints
			// itself from the registry. The active tab is normalized above, so this
			// always resolves to a panel (feed is the floor).
			( new \BuddyNext\Nav\PanelRenderer() )->render_panels( $bn_space_nav, $bn_space_ctx, $active_tab );
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
