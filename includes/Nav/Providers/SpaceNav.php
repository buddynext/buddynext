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
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpacePostGuard;
use BuddyNext\Spaces\SpaceService;

/**
 * Core nav provider for the `space` surface.
 */
final class SpaceNav {

	/**
	 * Hook the provider onto the one-time registration action.
	 */
	public function register(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_items' ) );
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
				'id'       => 'members',
				'surface'  => 'space',
				'layer'    => 'primary',
				'label'    => __( 'Members', 'buddynext' ),
				'priority' => 20,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'members' ),
				'count'    => static fn( NavContext $c ): int => (int) ( ( new SpaceService() )->get( $c->subject_id )['member_count'] ?? 0 ),
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
					&& (bool) get_option( 'bn_space_' . $c->subject_id . '_mvs_media_tab', 0 ),
				'render'    => function ( NavContext $c ): void {
					$this->render_media_panel( $c->subject_id );
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

		// Pinned announcement (hydrated array). The part renders it as an object and
		// shows the author name, which hydrate() does not carry, so enrich it here.
		$pinned     = null;
		$pinned_arr = $feed->space_pinned_post( $space_id );
		if ( is_array( $pinned_arr ) ) {
			$author                    = get_userdata( (int) ( $pinned_arr['user_id'] ?? 0 ) );
			$pinned_arr['author_name'] = $author ? $author->display_name : __( 'Admin', 'buddynext' );
			$pinned                    = (object) $pinned_arr;
		}

		// Regular feed (hydrated arrays). The pinned post leads as its own card, so
		// drop it from the list to avoid showing it twice.
		$space_feed = $feed->space_feed( $space_id, $viewer_id, null, 20 );
		$posts      = array_values(
			array_filter(
				(array) ( $space_feed['items'] ?? array() ),
				static fn( $p ): bool => empty( $p['is_pinned'] )
			)
		);

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
				'pinned_post'  => $pinned,
				'current_user' => $viewer_id > 0 ? get_userdata( $viewer_id ) : null,
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
		buddynext_get_template(
			'parts/space-about-panel.php',
			array(
				'space' => $space,
				'meta'  => SpaceService::display_meta( $space ),
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
		$ids = MediaClient::available()
			? (array) buddynext_service( 'feed' )->space_media_ids( $space_id, 24 )
			: array();

		if ( ! empty( $ids ) ) {
			echo \BuddyNext\Media\MediaRenderer::gallery( $ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer::gallery() returns escaped markup.
			return;
		}

		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'camera',
				'title' => __( 'No media in this space yet', 'buddynext' ),
				'body'  => __( 'Share a photo to get started.', 'buddynext' ),
			)
		);
	}
}
