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
	 * @return array<int,array<string,mixed>>
	 */
	private function primary_tabs(): array {
		return array(
			array(
				'id'       => 'feed',
				'surface'  => 'space',
				'layer'    => 'primary',
				'label'    => __( 'Feed', 'buddynext' ),
				'tab'      => 'feed',
				'priority' => 10,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'feed' ),
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'feed' )->space_post_count( $c->subject_id ),
			),
			array(
				'id'       => 'members',
				'surface'  => 'space',
				'layer'    => 'primary',
				'label'    => __( 'Members', 'buddynext' ),
				'tab'      => 'members',
				'priority' => 20,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'members' ),
				'count'    => static fn( NavContext $c ): int => (int) ( ( new SpaceService() )->get( $c->subject_id )['member_count'] ?? 0 ),
			),
			array(
				'id'        => 'media',
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => __( 'Media', 'buddynext' ),
				'tab'       => 'media',
				'priority'  => 30,
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'media' ),
				'condition' => static fn( NavContext $c ): bool => MediaClient::available()
					&& (bool) get_option( 'bn_space_' . $c->subject_id . '_mvs_media_tab', 0 ),
			),
			array(
				'id'       => 'about',
				'surface'  => 'space',
				'layer'    => 'primary',
				'label'    => __( 'About', 'buddynext' ),
				'tab'      => 'about',
				'priority' => 40,
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'about' ),
			),
			array(
				'id'        => 'moderation',
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => __( 'Moderation', 'buddynext' ),
				'tab'       => 'moderation',
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
}
