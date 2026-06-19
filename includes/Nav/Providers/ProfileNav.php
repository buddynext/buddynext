<?php
/**
 * BuddyNext — Profile nav provider.
 *
 * Registers the built-in navigation for the member-profile surface into the
 * NavRegistry: the relationship metric row (Followers / Following / Connections)
 * and the primary content tabs (Posts / Scheduled / Replies / Media / Likes).
 * Integration-owned items (Jetonomy Discussions, Gamification Achievements) are
 * registered by their own bridges through the same `buddynext_register_nav` seam,
 * so this provider owns ONLY the core surface — no partner coupling.
 *
 * Counts and visibility are lazy callables resolved against the live NavContext,
 * so one declaration serves every profile (self or viewer) without per-view
 * branching in the template.
 *
 * @package BuddyNext\Nav\Providers
 */

declare( strict_types=1 );

namespace BuddyNext\Nav\Providers;

use BuddyNext\Media\Galleries;
use BuddyNext\Media\MediaClient;
use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;

/**
 * Core nav provider for the `profile` surface.
 */
final class ProfileNav {

	/**
	 * Hook the provider onto the one-time registration action.
	 */
	public function register(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_items' ) );
	}

	/**
	 * Register the core profile metrics + primary tabs.
	 *
	 * @param NavRegistry $registry The shared registry.
	 */
	public function register_items( NavRegistry $registry ): void {
		foreach ( $this->metrics() as $item ) {
			$registry->register( $item );
		}
		foreach ( $this->primary_tabs() as $item ) {
			$registry->register( $item );
		}
		foreach ( $this->network() as $item ) {
			$registry->register( $item );
		}
	}

	/**
	 * The relationship metric row — display counts that deep-link to their list
	 * panel (the panel reveal is driven by the same `activeTab` the tab sets).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function metrics(): array {
		return array(
			array(
				'id'       => 'followers',
				'surface'  => 'profile',
				'layer'    => 'metric',
				'label'    => __( 'Followers', 'buddynext' ),
				'tab'      => 'followers',
				'priority' => 10,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->follower_count( $c->subject_id ),
			),
			array(
				'id'       => 'following',
				'surface'  => 'profile',
				'layer'    => 'metric',
				'label'    => __( 'Following', 'buddynext' ),
				'tab'      => 'following',
				'priority' => 20,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->following_count( $c->subject_id ),
			),
			array(
				'id'       => 'connections',
				'surface'  => 'profile',
				'layer'    => 'metric',
				'label'    => __( 'Connections', 'buddynext' ),
				'tab'      => 'connections',
				'priority' => 30,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'connections' )->connection_count( $c->subject_id ),
			),
		);
	}

	/**
	 * The primary content tabs. Posts owns the post count (so the dedupe rule
	 * drops any metric that would duplicate it). Scheduled is owner-only; Media
	 * is gated on the media engine being active.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function primary_tabs(): array {
		return array(
			array(
				'id'       => 'posts',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Posts', 'buddynext' ),
				'tab'      => 'posts',
				'priority' => 10,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->user_post_count( $c->subject_id ),
			),
			array(
				'id'        => 'scheduled',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Scheduled', 'buddynext' ),
				'tab'       => 'scheduled',
				'priority'  => 15,
				'after'     => 'posts',
				'condition' => static fn( NavContext $c ): bool => $c->is_self(),
				'count'     => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->user_scheduled_count( $c->subject_id ),
			),
			array(
				'id'       => 'replies',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Replies', 'buddynext' ),
				'tab'      => 'replies',
				'priority' => 30,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->reply_count( $c->subject_id ),
			),
			array(
				'id'        => 'media',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Media', 'buddynext' ),
				'tab'       => 'media',
				'priority'  => 40,
				'condition' => static fn(): bool => MediaClient::available(),
				'count'     => static fn( NavContext $c ): int => (int) Galleries::user_media_count( $c->subject_id, $c->viewer_id ),
			),
			array(
				'id'       => 'likes',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Likes', 'buddynext' ),
				'tab'      => 'likes',
				'priority' => 50,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->reaction_count( $c->subject_id ),
			),
		);
	}

	/**
	 * The "Network" primary tab and its one-level sub-nav (Connections /
	 * Followers / Following). The parent defaults to the Connections sub-tab; the
	 * relationship metric pills in the hero deep-link to the same sub-tab targets,
	 * so the hero counts and this section stay in lockstep. The list panels these
	 * sub-tabs reveal already exist (rendered by profile-tab-panel.php).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function network(): array {
		return array(
			array(
				'id'       => 'network',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Network', 'buddynext' ),
				'tab'      => 'connections',
				'icon'     => 'users',
				'priority' => 55,
			),
			array(
				'id'       => 'connections',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => __( 'Connections', 'buddynext' ),
				'tab'      => 'connections',
				'url'      => static fn( NavContext $c ): string => \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) . 'connections/',
				'priority' => 10,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'connections' )->connection_count( $c->subject_id ),
			),
			array(
				'id'       => 'followers',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => __( 'Followers', 'buddynext' ),
				'tab'      => 'followers',
				'url'      => static fn( NavContext $c ): string => \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) . 'followers/',
				'priority' => 20,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->follower_count( $c->subject_id ),
			),
			array(
				'id'       => 'following',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => __( 'Following', 'buddynext' ),
				'tab'      => 'following',
				'url'      => static fn( NavContext $c ): string => \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) . 'following/',
				'priority' => 30,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->following_count( $c->subject_id ),
			),
		);
	}
}
