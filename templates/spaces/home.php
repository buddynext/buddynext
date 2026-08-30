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

// ── Services ──────────────────────────────────────────────────────────────────

$bn_space_service  = new \BuddyNext\Spaces\SpaceService();
$bn_member_service = new \BuddyNext\Spaces\SpaceMemberService();

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ), '', array( 'response' => 404 ) );
}

// $space is the shared object shape (bare row + resolved category) every part
// reads (hero, about, members, feed, sidebar). SpaceService::get_object() is the
// single loader, also used by each space panel render, so the hub and a panel
// never resolve the row two different ways.
$space = $bn_space_service->get_object( $space_id );

if ( null === $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ), '', array( 'response' => 404 ) );
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
// isn't a site admin) never confirms the slug exists. PageRouter::dispatch_hub_template()
// enforces this at template_redirect — the ONLY place a real 404 status header can
// still be sent (this template runs after get_header() has flushed the document, so
// a status_header() call here arrives too late and yields a soft "200 OK" 404 page).
// This is the belt-and-braces guard for a direct template include, and it asks the
// SAME canonical resolver, so the two can never drift.
$bn_space_row = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
if ( ! \BuddyNext\Spaces\SpaceVisibility::can_view_space( $bn_space_row, $current_user_id ) ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	include get_404_template();
	exit;
}

// Access gate: private + secret CONTENT (posts, members, media). Open spaces never
// gate, but guests still see a "Join to participate" CTA instead of the composer.
// The feed data itself is fetched by the feed panel's render
// (SpaceNav::render_feed_panel), so it runs only when the Feed tab is active.
//
// This flag says "may this viewer read the space's CONTENT", NOT "may this viewer
// see the space at all" — a private space's identity (name, description, house
// rules, moderators) stays public by contract. Which tabs that exempts is decided
// at the tab body below; do not reintroduce a blanket gate around the whole body.
$gate_feed = ! \BuddyNext\Spaces\SpaceVisibility::can_view_content( $bn_space_row, $current_user_id );

// Clean-URL active tab: /spaces/{slug}/{tab}/ → bn_space_action. Defaults to feed.
$active_tab = (string) get_query_var( 'bn_space_action', '' );
$active_tab = '' !== $active_tab ? sanitize_key( $active_tab ) : 'feed';

$rest_nonce = wp_create_nonce( 'wp_rest' );

// ── Right sidebar (uniform across every space tab) ─────────────────────────────
// SpaceSidebarProvider registers the space rail cards on buddynext_sidebar_widgets
// for the `space` surface; the registry renders them on buddynext_right_sidebar.
// Every space template (home + members + moderation) sets this same context, so
// switching tabs keeps the same rail instead of dropping it on the dedicated pages.
\BuddyNext\Sidebar\Surface::set(
	'space',
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

// Normalize the active tab to a panel the registry can actually render. A tab that
// is hidden for this viewer/space (Media when the option is off, Discussions when
// Jetonomy is inactive) is absent from the resolved nav, and an unknown/stale URL
// matches nothing — both need a fallback.
//
// The fallback USED TO BE the literal 'feed'. That bricked the space whenever the
// owner hid the Feed tab in Settings → Navigation: 'feed' is then not in the
// resolved nav either, so render_panels() matched nothing and the body painted
// ZERO BYTES. A documented, supported setting blanked every space on the site.
//
// Fall back to the first tab that can ACTUALLY render, whatever it is. Feed is
// normally first, so the common path is unchanged — but when it is hidden the space
// now opens on About (or Media, or whatever the owner left enabled) instead of
// nothing. This also drives the header's active-tab highlight, so the two agree.
$bn_active_renderable = false;
$bn_first_renderable  = '';
foreach ( $bn_nav_items as $bn_pi ) {
	if ( ! $bn_pi->has_render() ) {
		continue;
	}
	if ( '' === $bn_first_renderable ) {
		$bn_first_renderable = (string) $bn_pi->id;
	}
	if ( $bn_pi->id === $active_tab ) {
		$bn_active_renderable = true;
		break;
	}
}
if ( ! $bn_active_renderable ) {
	$active_tab = '' !== $bn_first_renderable ? $bn_first_renderable : 'feed';
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
		<?php
		// The gate is about CONTENT — posts, members, media. It is not about the
		// space's IDENTITY. SpaceVisibility's own contract (see its class docblock)
		// says a private space keeps its name, description, house rules, avatar,
		// cover, category and moderator list PUBLIC, because a stranger legitimately
		// needs to know who is in charge and what the rules are BEFORE deciding to
		// request to join. The About panel is precisely that surface.
		//
		// This used to gate the WHOLE body, so a non-member got the lock card on
		// every tab including About — contradicting both that contract and the
		// comment ~100 lines above, which claims the feed query "runs only when the
		// Feed tab is the active panel, never when viewing About". It never got the
		// chance to: About never rendered at all.
		//
		// Safe to paint for a non-member: SpaceNav::render_about_panel() already
		// visibility-filters its custom fields against $see_member.
		$bn_public_tabs = (array) apply_filters( 'buddynext_space_public_tabs', array( 'about' ), $bn_space_row );
		$bn_show_gate   = $gate_feed && ! in_array( $active_tab, $bn_public_tabs, true );
		?>
		<?php if ( $bn_show_gate ) : ?>

			<?php
			// TWO different gates wear the same lock, and until now they wore the
			// same words too.
			//
			// A member who has already JOINED can still be gated - by a space that
			// requires a membership plan. Telling them "join to read" is telling
			// them to do the one thing they have already done, while their own hero
			// badge says "Joined" and the sidebar lists them under MEMBERS. Three
			// statements on one screen, two contradicting the third.
			//
			// So the copy branches on WHY they cannot read it, not on the fact that
			// they cannot. The plan name is asked for through a filter rather than
			// read from `required_ability` here: gating is a Pro feature, the ability
			// string is its private vocabulary, and Free has no business parsing
			// "tier:pro" into a plan name. Pro answers; Free degrades to the generic
			// line when it is absent.
			$bn_gate_plan = '';
			if ( null !== $bn_member_role_now ) {
				/**
				 * Name the plan a gated space requires, for the gate card's copy.
				 *
				 * @since 1.1.6
				 *
				 * @param string $name  Plan name, '' when unknown or not gated by a plan.
				 * @param array  $space The space row.
				 * @param int    $user  Viewing member.
				 */
				$bn_gate_plan = (string) apply_filters( 'buddynext_space_gate_plan_name', '', $bn_space_row, $current_user_id );
			}
			$bn_gate_is_plan = '' !== $bn_gate_plan;
			?>
			<div class="bn-card bn-sh-gate">
				<div class="bn-sh-gate__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
				<h2 class="bn-sh-gate__title">
					<?php
					echo $bn_gate_is_plan
						? esc_html__( 'This space needs a plan', 'buddynext' )
						: esc_html__( 'This is a private space', 'buddynext' );
					?>
				</h2>
				<p class="bn-sh-gate__lede">
					<?php
					if ( $bn_gate_is_plan ) {
						printf(
							/* translators: %s: membership plan name. */
							esc_html__( 'You are a member of this space. Reading and posting here is included with %s.', 'buddynext' ),
							esc_html( $bn_gate_plan )
						);
					} elseif ( $is_invited ) {
						esc_html_e( 'Accept the invitation above to read posts and participate.', 'buddynext' );
					} else {
						esc_html_e( 'Join to read posts and participate in discussions.', 'buddynext' );
					}
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
