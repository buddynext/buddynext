<?php
/**
 * BuddyNext — Space nav provider.
 *
 * Registers the built-in navigation for the space surface into the NavRegistry,
 * the SAME way ProfileNav does — so member + space share one nav system, one
 * renderer, one active convention. Each tab carries a reactive `tab` slug AND a
 * lazy clean-URL `url` (e.g. /spaces/{slug}/members/) as the deep-link + no-JS
 * fallback, so spaces are consistent with profiles (clean URLs, no ?bn_tab=).
 *
 * Role-gated items (Moderation) resolve against NavContext->role, which the
 * caller (spaces/home.php) populates with the viewer's space role.
 *
 * @package BuddyNext\Nav\Providers
 */

declare( strict_types=1 );

namespace BuddyNext\Nav\Providers;

use BuddyNext\Core\PageRouter;
use BuddyNext\Media\MediaClient;
use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;
use BuddyNext\Spaces\SpaceFieldRegistry;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpacePostGuard;
use BuddyNext\Spaces\SpaceService;

/**
 * Core nav provider for the `space` surface.
 */
final class SpaceNav {

	/**
	 * Matches per page for in-space search.
	 *
	 * @var int
	 */
	private const SEARCH_PER_PAGE = 20;

	/**
	 * Hook the provider onto the one-time registration action.
	 */
	public function register(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_items' ) );
		// Owner-promoted custom fields become first-class space tabs. The promotion
		// set is per-space, so they are injected per nav build (which carries the
		// space context) via the registry's contextual filter, not at registration.
		add_filter( 'buddynext_nav_items', array( $this, 'inject_field_tabs' ), 20, 2 );
	}

	/**
	 * Inject a tab for each custom field an owner has promoted on THIS space.
	 *
	 * Hooked on `buddynext_nav_items` (runs per nav build with the live context).
	 * A field tab is visibility-gated like its field; an empty tab is hidden from
	 * regular members but shown to managers (with an "add content" nudge) so they
	 * can tell it is promoted. Reuses the clean-URL tab seam (/spaces/{slug}/field-{key}/).
	 *
	 * @param array<int,array<string,mixed>> $items   Raw nav-item definitions.
	 * @param NavContext                     $context Active nav context.
	 * @return array<int,array<string,mixed>>
	 */
	public function inject_field_tabs( array $items, NavContext $context ): array {
		if ( 'space' !== $context->surface || $context->subject_id <= 0 ) {
			return $items;
		}

		$fields = SpaceFieldRegistry::instance()->promoted_tab_fields( $context->subject_id );
		if ( empty( $fields ) ) {
			return $items;
		}

		// Slot promoted tabs just after About (40), before Moderation (50).
		$priority = 41;
		foreach ( $fields as $field ) {
			$key        = (string) $field['key'];
			$visibility = (string) ( $field['visibility'] ?? 'public' );
			$is_url     = 'url' === ( $field['type'] ?? '' );

			$items[] = array(
				'id'        => 'field-' . $key,
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => (string) $field['label'],
				'icon'      => $is_url ? 'link' : 'file-text',
				'priority'  => $priority++,
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'field-' . $key ),
				'condition' => static function ( NavContext $c ) use ( $key, $visibility ): bool {
					// buddynext-moderate-space IS "owner or moderator, admins aside".
					// Hand-rolling it here is what let the nav and the capability
					// layer answer differently; ask the canonical gate instead.
					$can_manage = buddynext_can(
						get_current_user_id(),
						'buddynext-moderate-space',
						array( 'space_id' => (int) $c->subject_id )
					);
					// Members-only fields are hidden from non-members (managers aside).
					if ( 'members' === $visibility && ! $c->role_at_least( 'member' ) && ! $can_manage ) {
						return false;
					}
					// Hide an empty tab from regular members; managers still see it.
					$has_value = '' !== (string) get_space_meta( $c->subject_id, $key, true );
					return $has_value || $can_manage;
				},
				'render'    => function ( NavContext $c ) use ( $field ): void {
					$this->render_field_tab_panel( $c->subject_id, $field );
				},
			);
		}

		return $items;
	}

	/**
	 * Render a promoted custom field as a space tab body.
	 *
	 * @param int                 $space_id Space ID.
	 * @param array<string,mixed> $field    Field definition.
	 * @return void
	 */
	private function render_field_tab_panel( int $space_id, array $field ): void {
		// Same canonical gate as the tab-visibility condition above, so what the
		// panel offers and what the tab shows can never disagree.
		$can_manage = buddynext_can( get_current_user_id(), 'buddynext-moderate-space', array( 'space_id' => $space_id ) );
		$space      = ( new SpaceService() )->get( $space_id );

		buddynext_get_template(
			'parts/space-field-tab.php',
			array(
				'field'      => $field,
				'value'      => get_space_meta( $space_id, (string) $field['key'], true ),
				'space_id'   => $space_id,
				'space_slug' => (string) ( $space['slug'] ?? '' ),
				'can_manage' => $can_manage,
			)
		);
	}

	/**
	 * Register the core space primary tabs.
	 *
	 * @param NavRegistry $registry The shared registry.
	 */
	public function register_items( NavRegistry $registry ): void {
		foreach ( $this->primary_tabs() as $item ) {
			$registry->register( $item );
		}
	}

	/**
	 * Clean-URL builder for a space tab — /spaces/{slug}/{tab}/ (feed = the base).
	 *
	 * @param int    $space_id Space ID.
	 * @param string $tab      Tab slug ('' = the feed/base URL).
	 * @return string
	 */
	private function tab_url( int $space_id, string $tab ): string {
		$base = trailingslashit( PageRouter::space_url( $space_id ) );
		return '' === $tab || 'feed' === $tab ? $base : $base . $tab . '/';
	}

	/**
	 * The core space tabs: Feed, Members, Media (gated), About, Moderation (gated).
	 *
	 * Space tabs are URL-only (clean /spaces/{slug}/{tab}/ links, rendered by
	 * nav-bar.php as real `<a>` tabs with aria-current), NOT reactive in-page tabs:
	 * the space panels (feed stream, member grid, media gallery, mod queue) are
	 * heavy, so each tab server-renders only its own panel per clean URL rather
	 * than pre-rendering all of them. Same shared components as profile; the URL
	 * is a lazy callable so it resolves against the live space.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function primary_tabs(): array {
		return array(
			array(
				'id'       => 'feed',
				'surface'  => 'space',
				'layer'    => 'primary',
				'label'    => __( 'Feed', 'buddynext' ),
				'priority' => 10,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'feed' ),
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'feed' )->space_post_count( $c->subject_id ),
				'render'   => function ( NavContext $c ): void {
					$this->render_feed_panel( $c->subject_id, $c->viewer_id );
				},
			),
			array(
				'id'                => 'members',
				'surface'           => 'space',
				'layer'             => 'primary',
				'label'             => __( 'Members', 'buddynext' ),
				'priority'          => 20,
				'url'               => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'members' ),
				// Denormalized member_count column — inexpensive to read, and a space's member
				// count is worth surfacing, so keep this badge on at scale.
				'lightweight_count' => true,
				'count'             => static fn( NavContext $c ): int => (int) ( ( new SpaceService() )->get( $c->subject_id )['member_count'] ?? 0 ),
			),
			// Sub-spaces got a first-class tab because their ONLY navigation used to be
			// a card in the right sidebar — and the sidebar is `display: none` below
			// 1024px. On every phone AND every tablet a parent space therefore offered
			// no path at all to its own children, and a manager could not even create
			// one (the "Add sub-space" CTA lives in that same hidden card). The
			// directory does not rescue it either: it is roots-only by default.
			array(
				'id'                => 'subspaces',
				'surface'           => 'space',
				'layer'             => 'primary',
				'label'             => __( 'Sub-spaces', 'buddynext' ),
				'priority'          => 25,
				'url'               => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'subspaces' ),
				// Shown when there is something to see, or someone able to add the
				// first one. A leaf space (depth is capped at 2) never shows it.
				'condition'         => fn( NavContext $c ): bool => $this->shows_subspaces( $c ),
				'lightweight_count' => true,
				'count'             => fn( NavContext $c ): int => $this->subspace_count( $c ),
				'render'            => function ( NavContext $c ): void {
					$this->render_subspaces_panel( $c->subject_id, $c->viewer_id );
				},
			),
			array(
				'id'        => 'media',
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => __( 'Media', 'buddynext' ),
				'priority'  => 30,
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'media' ),
				'condition' => static fn( NavContext $c ): bool => MediaClient::available()
					&& buddynext_integration_enabled( 'media', 'nav' )
					&& (bool) buddynext_get_space_field( (int) $c->subject_id, 'mvs_media_tab' ),
				'render'    => function ( NavContext $c ): void {
					$this->render_media_panel( $c->subject_id );
				},
			),
			array(
				'id'        => 'files',
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => __( 'Files', 'buddynext' ),
				'priority'  => 35,
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'files' ),
				// Same integration gate as Media (both are WPMediaVerse surfaces),
				// but keyed on the documents feature + the per-space Files toggle.
				// Per-viewer drive access (read/none, 403 vs 404) is settled by the
				// drive filters when the panel asks MVS, so the tab shows for any
				// space member and the panel renders the right state.
				'condition' => static fn( NavContext $c ): bool => \BuddyNext\Bridges\WPMediaVerseBridge::documents_available()
					&& buddynext_integration_enabled( 'media', 'nav' )
					&& (bool) buddynext_get_space_field( (int) $c->subject_id, 'mvs_documents_tab' ),
				'render'    => function ( NavContext $c ): void {
					$this->render_files_panel( $c->subject_id );
				},
			),
			array(
				'id'       => 'about',
				'surface'  => 'space',
				'layer'    => 'primary',
				'label'    => __( 'About', 'buddynext' ),
				'priority' => 40,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'about' ),
				'render'   => function ( NavContext $c ): void {
					$this->render_about_panel( $c->subject_id );
				},
			),
			array(
				'id'        => 'moderation',
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => __( 'Moderation', 'buddynext' ),
				'priority'  => 50,
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'moderation' ),
				'condition' => static fn( NavContext $c ): bool => $c->role_at_least( 'moderator' ),
				'count'     => static function ( NavContext $c ): int {
					$reports = (int) buddynext_service( 'moderation' )->count_open_reports_for_space( $c->subject_id );
					$pending = (int) ( new SpaceMemberService() )->count_pending_requests( $c->subject_id );
					return $reports + $pending;
				},
			),
		);
	}

	/**
	 * Render the Feed panel for a space — the registry content seam for the Feed
	 * tab (the space's home panel). Self-contained: it resolves the viewer's
	 * membership, posting permission and archived state, then the pinned
	 * announcement + the hydrated feed posts (the same FeedService path the space
	 * feed REST controller uses), and renders the shared feed part. The caller
	 * (spaces/home.php) still owns the private/secret access gate, so this only
	 * runs for a viewer allowed to read the feed.
	 *
	 * @param int $space_id  Space ID.
	 * @param int $viewer_id Current viewer user ID (0 = logged out).
	 * @return void
	 */
	private function render_feed_panel( int $space_id, int $viewer_id ): void {
		$space = ( new SpaceService() )->get_object( $space_id );
		if ( null === $space ) {
			return;
		}

		$status     = $viewer_id > 0 ? (string) ( new SpaceMemberService() )->get_status( $space_id, $viewer_id ) : '';
		$is_member  = 'active' === $status;
		$is_pending = 'pending' === $status;
		$is_guest   = 0 === $viewer_id;
		$archived   = ! empty( $space->is_archived );
		// An archived space is read-only for everyone (mirrors the post/comment/join
		// guards); otherwise the composer follows the space's "who can post" rule.
		$can_post = $is_member && ! $archived && SpacePostGuard::can_post( $space_id, $viewer_id );

		$feed = buddynext_service( 'feed' );

		/*
		 * ── In-space search ──────────────────────────────────────────────────
		 * A space's own posts become unfindable exactly as the space succeeds:
		 * past the first screenful the only options were scrolling or a
		 * community-wide search whose results are mostly other spaces. `bn_sf_q`
		 * swaps the chronological list for matches inside THIS space, keeping the
		 * same post cards, the same panel and the same tab.
		 *
		 * Two gates, both pre-existing, and deliberately no third:
		 *
		 *   1. SearchService's own visibility gate scopes the index query — a
		 *      viewer who is not an active member of a private space matches no
		 *      rows in it, so an unauthorised scope returns empty rather than
		 *      leaking.
		 *   2. PostService::filter_visible() — the feed's per-post gate — then
		 *      re-checks every hit. Belt and braces, and it earns its keep: on the
		 *      dev site 186 of 332 indexed post rows pointed at posts that no
		 *      longer existed, so without this the panel would have counted and
		 *      promised results that cannot be rendered.
		 *
		 * Hand-rolling a third rule here is what shipped the leak on the sibling
		 * card; the scope narrows what the gates already allow, it never widens.
		 */
		// Offered only to a viewer who can actually READ this space's content. On a
		// private space a non-member still reaches this panel — it renders their
		// join CTA — and a search box there would be a control that can only ever
		// answer "0 results": it advertises a capability the viewer does not have
		// and, worse, invites them to read the count as evidence about what the
		// space contains. Short-circuited before the query, not just hidden in the
		// template, so an appended ?bn_sf_q= costs nothing either.
		$can_search = \BuddyNext\Spaces\SpaceVisibility::can_view_content(
			(array) $space,
			$viewer_id
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search over a public GET form, no state change.
		$search_query = ( $can_search && isset( $_GET['bn_sf_q'] ) ) ? sanitize_text_field( wp_unslash( $_GET['bn_sf_q'] ) ) : '';
		$search_total = 0;

		// Page number for the paged search. A space that is big enough to need
		// search is big enough to overflow one page of it, so the twentieth match
		// cannot be the last one a member can reach.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination on a GET form.
		$search_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$has_prev    = false;
		$has_next    = false;

		if ( '' !== $search_query ) {
			$scope = static function ( array $args ) use ( $space_id ): array {
				$args['scope_space_id'] = $space_id;
				return $args;
			};

			add_filter( 'buddynext_search_query_args', $scope, 5 );
			$hits = buddynext_service( 'search' )->search( $search_query, 'post', self::SEARCH_PER_PAGE, $search_page, $viewer_id );
			remove_filter( 'buddynext_search_query_args', $scope, 5 );

			$hit_ids = array_values(
				array_filter(
					array_map(
						static fn( $row ): int => (int) ( $row['object_id'] ?? 0 ),
						(array) ( $hits['items'] ?? array() )
					)
				)
			);

			$posts_service = buddynext_service( 'post_service' );
			$visible_ids   = $posts_service->filter_visible( $hit_ids, $viewer_id );
			$results       = $posts_service->get_many( $visible_ids );

			// The reported total is what the viewer can actually see on THIS page,
			// not what the index matched. Showing the index count would promise
			// rows that gate 2 then removed, and "12 results" above 9 cards reads
			// as a bug — which on this data it would, constantly: a majority of
			// indexed post rows can point at posts that no longer exist.
			$search_total = count( $results );

			// Prev/next rather than a page count, and for the same reason the total
			// is per-page: gate 2 removes an unknown number of rows per page, so any
			// "page 3 of 9" we printed would be a guess. What IS knowable is whether
			// the INDEX had a full page — if it did, there is another page to fetch.
			// The service's own 1000-row ceiling ends the sequence naturally, since
			// a bounded page past it comes back short.
			$has_prev = $search_page > 1;
			$has_next = count( (array) ( $hits['items'] ?? array() ) ) >= self::SEARCH_PER_PAGE;

			$feed->prime_viewer_state( $results, $viewer_id );

			buddynext_get_template(
				'parts/space-feed-panel.php',
				array(
					'space'        => $space,
					'space_id'     => $space_id,
					'viewer_id'    => $viewer_id,
					'is_member'    => $is_member,
					'can_post'     => $can_post,
					'is_guest'     => $is_guest,
					'is_pending'   => $is_pending,
					'is_archived'  => $archived,
					'posts'        => $results,
					'pinned_posts' => array(),
					'current_user' => $viewer_id > 0 ? get_userdata( $viewer_id ) : null,
					'search_query' => $search_query,
					'search_total' => $search_total,
					'can_search'   => $can_search,
					'search_page'  => $search_page,
					'has_prev'     => $has_prev,
					'has_next'     => $has_next,
				)
			);

			return;
		}

		/*
		 * Pinned posts, as hydrated ARRAYS - the shape partials/post-card.php consumes.
		 *
		 * They used to be cast to objects and enriched with author_name for a hand-rolled stub
		 * card that carried no React / Comment / Share / Save. Since the pinned post is also
		 * dropped from the chronological list below, that stub was the ONLY place it appeared -
		 * so pinning a post silently removed every way to engage with it. The panel now renders
		 * the real post card, which needs the array and does its own author lookup.
		 *
		 * Pro allows up to 10 pins per space; the panel bounds how many show at once.
		 */
		$pinned_posts = array_values(
			array_filter(
				(array) $feed->space_pinned_posts( $space_id, 10 ),
				'is_array'
			)
		);

		// Regular feed (hydrated arrays). The pinned post leads as its own card, so
		// drop it from the list to avoid showing it twice.
		$space_feed = $feed->space_feed( $space_id, $viewer_id, null, 20 );
		$posts      = array_values(
			array_filter(
				(array) ( $space_feed['items'] ?? array() ),
				static fn( $p ): bool => empty( $p['is_pinned'] )
			)
		);

		// A space announcement leads the feed as its own (dismissible) card and is
		// dropped from the chronological list to avoid showing twice.
		$space_ann    = $feed->space_announcement( $space_id, $viewer_id );
		$space_ann_id = is_array( $space_ann ) ? (int) ( $space_ann['id'] ?? 0 ) : 0;
		if ( $space_ann_id > 0 ) {
			$posts = array_values(
				array_filter( $posts, static fn( $p ): bool => (int) ( $p['id'] ?? 0 ) !== $space_ann_id )
			);
			array_unshift( $posts, $space_ann );
		}

		// Batch-prime per-viewer state for the chronological post-cards before the
		// SSR loop renders them (C8.3). The pinned strip is a compact title/author
		// card with no reaction/vote/report state, so it needs no priming.
		$feed->prime_viewer_state( $posts, $viewer_id );

		buddynext_get_template(
			'parts/space-feed-panel.php',
			array(
				'space'        => $space,
				'space_id'     => $space_id,
				'viewer_id'    => $viewer_id,
				'is_member'    => $is_member,
				'can_post'     => $can_post,
				'is_guest'     => $is_guest,
				'is_pending'   => $is_pending,
				'is_archived'  => $archived,
				'posts'        => $posts,
				'pinned_posts' => $pinned_posts,
				'current_user' => $viewer_id > 0 ? get_userdata( $viewer_id ) : null,
				'search_query' => '',
				'search_total' => 0,
				'can_search'   => $can_search,
				'search_page'  => 1,
				'has_prev'     => false,
				'has_next'     => false,
			)
		);
	}

	/**
	 * Whether a manager may add a sub-space to this space.
	 *
	 * Mirrors the sidebar CTA gate: only a ROOT space can hold children (depth is
	 * capped at two levels), the community-level toggle must allow them, and the
	 * viewer must hold manage rights on the parent.
	 *
	 * @param NavContext $context Nav context for the space being viewed.
	 * @return bool True when the viewer may add a sub-space here.
	 */
	private function can_add_subspace( NavContext $context ): bool {
		$space = ( new SpaceService() )->get( $context->subject_id );

		if ( null === $space || ! empty( $space['parent_id'] ) ) {
			return false;
		}

		if ( '0' === (string) get_option( 'buddynext_space_allow_sub', '1' ) ) {
			return false;
		}

		/*
		 * BOTH rights are required, and this checked only the first.
		 *
		 *   buddynext-manage-space   per-space: are you this space's owner or a moderator?
		 *   buddynext-spaces/create  site-wide: are you allowed to create a space at all?
		 *
		 * The create route enforces the SECOND (SpaceController: require_cap( 'buddynext-spaces/create' )),
		 * and an owner can restrict that capability to admins in Settings -> Roles. On such a site a
		 * space owner who is not a site admin was shown "Add sub-space", clicked it, and got a 403.
		 * The UI promised what the endpoint refuses.
		 */
		return $context->viewer_id > 0
			&& buddynext_can(
				$context->viewer_id,
				'buddynext-manage-space',
				array( 'space_id' => $context->subject_id )
			)
			&& buddynext_can( $context->viewer_id, 'buddynext-spaces/create' );
	}

	/**
	 * Number of sub-spaces this viewer may actually see.
	 *
	 * Visibility-scoped, so a secret child never shows up in the badge for someone
	 * who cannot see it.
	 *
	 * @param NavContext $context Nav context for the space being viewed.
	 * @return int Count of visible children.
	 */
	private function subspace_count( NavContext $context ): int {
		return ( new SpaceService() )->count_visible_subspaces(
			$context->subject_id,
			$context->viewer_id,
			user_can( $context->viewer_id, 'manage_options' )
		);
	}

	/**
	 * Whether the Sub-spaces tab should appear at all.
	 *
	 * Visible when the viewer can see at least one child, or when they are able to
	 * create the first one — so a childless space does not show an empty tab to an
	 * ordinary member, but its manager can still find the entry point.
	 *
	 * @param NavContext $context Nav context for the space being viewed.
	 * @return bool True when the tab should render.
	 */
	private function shows_subspaces( NavContext $context ): bool {
		return $this->subspace_count( $context ) > 0 || $this->can_add_subspace( $context );
	}

	/**
	 * Render the Sub-spaces panel.
	 *
	 * The viewport-independent home for a space's children. The sidebar card that
	 * used to be their only entry point is hidden below 1024px, which made
	 * sub-spaces unreachable — and uncreatable — on phones and tablets.
	 *
	 * @param int $space_id Parent space id.
	 * @param int $viewer_id Viewer user id (0 = logged out).
	 * @return void
	 */
	private function render_subspaces_panel( int $space_id, int $viewer_id ): void {
		$context = new NavContext( 'space', $space_id, $viewer_id );
		$sub_max = (int) get_option( 'buddynext_space_max_sub_spaces', 0 );

		buddynext_get_template(
			'parts/space-subspaces-panel.php',
			array(
				'space_id'   => $space_id,
				'viewer_id'  => $viewer_id,
				'subspaces'  => ( new SpaceService() )->get_subspaces(
					$space_id,
					24,
					0,
					$viewer_id,
					user_can( $viewer_id, 'manage_options' )
				),
				'can_manage' => $this->can_add_subspace( $context ),
				// The per-parent cap, so the panel can say "2 of 3 used" and disable the
				// button AT the limit instead of letting the manager fill in the whole
				// modal and then be refused by the server. Counted with count_subspaces()
				// (every child), never the visibility-scoped list, so a secret child the
				// viewer cannot see still counts against the cap - exactly as the create
				// path enforces it.
				'sub_max'    => $sub_max,
				'sub_used'   => $sub_max > 0 ? ( new SpaceService() )->count_subspaces( $space_id ) : 0,
			)
		);
	}

	/**
	 * Render the About panel for a space — the registry content seam for the
	 * About tab. Self-contained: it loads the space object + its display meta
	 * through SpaceService (the shared loaders the hub shell also uses) and
	 * renders the existing about part, so the panel owns its data and the hub
	 * template no longer special-cases About.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	private function render_about_panel( int $space_id ): void {
		$space = ( new SpaceService() )->get_object( $space_id );
		if ( null === $space ) {
			return;
		}

		// Custom (non-core) field values to surface on About: visibility-filtered
		// for the viewer, with a value, and NOT already promoted to their own tab
		// (those render on the tab instead, so About never duplicates them).
		$viewer_id  = get_current_user_id();
		$see_member = ( $viewer_id > 0 && ( new SpaceMemberService() )->is_member( $space_id, $viewer_id ) )
			|| current_user_can( 'manage_options' );
		$registry   = SpaceFieldRegistry::instance();
		$promoted   = array();
		foreach ( $registry->promoted_tab_fields( $space_id ) as $bn_pf ) {
			$promoted[] = (string) $bn_pf['key'];
		}
		$about_fields = array();
		foreach ( $registry->resolve_for_space( $space_id, $see_member ) as $bn_field ) {
			if ( empty( $bn_field['core'] )
				&& '' !== (string) $bn_field['display']
				&& ! in_array( (string) $bn_field['key'], $promoted, true ) ) {
				$about_fields[] = $bn_field;
			}
		}

		buddynext_get_template(
			'parts/space-about-panel.php',
			array(
				'space'         => $space,
				'meta'          => SpaceService::display_meta( $space ),
				'custom_fields' => $about_fields,
			)
		);
	}

	/**
	 * Render the Media panel for a space — the space's own shared media, gathered
	 * from its posts (FeedService::space_media_ids) and shown through MediaRenderer,
	 * with an empty state when there is none. The content seam for the Media tab.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	private function render_media_panel( int $space_id ): void {
		if ( ! MediaClient::available() ) {
			buddynext_get_template(
				'parts/empty-state.php',
				array(
					'icon'  => 'camera',
					'title' => __( 'No media in this space yet', 'buddynext' ),
					'body'  => __( 'Share a photo to get started.', 'buddynext' ),
				)
			);
			return;
		}

		$viewer = get_current_user_id();

		// The same gallery the profile uses, scoped to this space: one template
		// and one Interactivity store for both, so the two cannot drift.
		// space_media_ids() supplies the flat grid (media shared in posts here);
		// the Albums view loads itself from /spaces/{id}/albums.
		buddynext_get_template(
			'partials/media-tab.php',
			array(
				'bn_mt_space_id'  => $space_id,
				'bn_mt_owner_id'  => 0,
				'bn_mt_is_owner'  => \BuddyNext\Media\Galleries::can_create_space_album( $space_id, $viewer ),
				'bn_mt_media_ids' => (array) buddynext_service( 'feed' )->space_media_ids( $space_id, 24 ),
			)
		);
	}

	/**
	 * Render the Files panel for a space — the space's document drive, browsed +
	 * downloaded through BuddyNext's own UI (WPMediaVerse ships no space-drive UI;
	 * we own the tabs and views). The bridge asks MVS's REST internally for this
	 * drive + folder, so every access decision routes through our drive filters.
	 * A null view means the viewer may not see this drive (MVS answered 403/404)
	 * or the feature is gone — show the neutral empty state, never a broken shell.
	 *
	 * Read-only view controls (folder, page) come off the GET query, mirroring
	 * MediaVerse's own drive; nothing is written, so no nonce is involved.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	private function render_files_panel( int $space_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET view controls.
		$doc_id = isset( $_GET['bn_doc'] ) ? absint( wp_unslash( $_GET['bn_doc'] ) ) : 0;
		$folder = isset( $_GET['bn_folder'] ) ? absint( wp_unslash( $_GET['bn_folder'] ) ) : 0;
		$page   = isset( $_GET['bn_files_page'] ) ? max( 1, absint( wp_unslash( $_GET['bn_files_page'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Single-file view — a real page, not a modal: it deep-links, it handles
		// every type (PDF inline, the rest metadata + download), and it matches
		// the URL-driven folder navigation above it.
		if ( $doc_id > 0 ) {
			$this->render_file_single_panel( $space_id, $doc_id );
			return;
		}

		$view = \BuddyNext\Bridges\WPMediaVerseBridge::space_drive_view( $space_id, $folder, $page );

		if ( null === $view ) {
			buddynext_get_template(
				'parts/empty-state.php',
				array(
					'icon'  => 'folder',
					'title' => __( 'No files to show', 'buddynext' ),
					'body'  => __( 'Files shared with this space will appear here.', 'buddynext' ),
				)
			);
			return;
		}

		buddynext_get_template(
			'partials/space-files-tab.php',
			array(
				'bn_sf_space_id'    => $space_id,
				'bn_sf_base_url'    => $this->tab_url( $space_id, 'files' ),
				'bn_sf_folders'     => $view['folders'],
				'bn_sf_documents'   => $view['documents'],
				'bn_sf_breadcrumbs' => $view['breadcrumbs'],
				'bn_sf_folder'      => $view['folder'],
				'bn_sf_page'        => $view['page'],
				'bn_sf_pages'       => $view['pages'],
				'bn_sf_total'       => $view['total'],
				'bn_sf_can_write'   => $view['can_write'],
			)
		);
	}

	/**
	 * Render the single-file view for one space document — its details plus an
	 * inline preview where the type allows one (PDF), and always a download. A
	 * cross-drive or unreadable id resolves to null and shows "file not found",
	 * never another drive's document under this tab.
	 *
	 * @param int $space_id Space ID.
	 * @param int $doc_id   Document ID.
	 * @return void
	 */
	private function render_file_single_panel( int $space_id, int $doc_id ): void {
		$doc = \BuddyNext\Bridges\WPMediaVerseBridge::space_drive_document( $space_id, $doc_id );

		if ( null === $doc ) {
			buddynext_get_template(
				'parts/empty-state.php',
				array(
					'icon'  => 'file-text',
					'title' => __( 'File not found', 'buddynext' ),
					'body'  => __( 'This file may have been moved or removed, or it is not shared with you.', 'buddynext' ),
				)
			);
			return;
		}

		$folder = isset( $doc['folder'] ) ? (int) $doc['folder'] : 0;

		buddynext_get_template(
			'partials/space-file-single.php',
			array(
				'bn_fs_doc'      => $doc,
				'bn_fs_base_url' => $this->tab_url( $space_id, 'files' ),
				'bn_fs_folder'   => $folder,
			)
		);
	}
}
