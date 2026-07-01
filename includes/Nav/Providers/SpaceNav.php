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
					$can_manage = $c->role_at_least( 'moderator' ) || current_user_can( 'manage_options' );
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
		$role       = ( new SpaceMemberService() )->get_role( $space_id, get_current_user_id() );
		$can_manage = in_array( $role, array( 'owner', 'moderator' ), true ) || current_user_can( 'manage_options' );
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
		$ids = MediaClient::available()
			? (array) buddynext_service( 'feed' )->space_media_ids( $space_id, 24 )
			: array();

		if ( ! empty( $ids ) ) {
			echo \BuddyNext\Media\MediaRenderer::gallery( $ids, array( 'space_id' => $space_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer::gallery() returns escaped markup.
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
