<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Sidebar widget cache-bust hook registrar.
 *
 * Implements docs/specs/MODULAR-ARCHITECTURE.md Layer 2 Listener pattern:
 * registers WordPress action hooks that bust the relevant cache entries
 * whenever the underlying data changes.
 *
 * @package BuddyNext\Sidebar
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace BuddyNext\Sidebar;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Wires cache-bust hooks for the sidebar widget data sets.
 */
class WidgetListener implements ListenerInterface {

	/**
	 * Cache layer.
	 *
	 * @var WidgetCache
	 */
	private WidgetCache $cache;

	/**
	 * Inject dependencies.
	 *
	 * @param WidgetCache $cache Cache layer.
	 */
	public function __construct( WidgetCache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Trending hashtags: any new post may shift counts.
		add_action( 'buddynext_post_created', array( $this, 'bust_trending' ) );
		add_action( 'buddynext_post_deleted', array( $this, 'bust_trending' ) );

		// Suggested follows: changes when the user follows/unfollows or blocks/unblocks.
		add_action( 'buddynext_user_followed', array( $this, 'bust_follower' ), 10, 2 );
		add_action( 'buddynext_user_unfollowed', array( $this, 'bust_follower' ), 10, 2 );
		add_action( 'buddynext_block', array( $this, 'bust_blocker' ), 10, 2 );
		add_action( 'buddynext_unblock', array( $this, 'bust_blocker' ), 10, 2 );

		// Joined spaces: changes on join/leave/remove.
		add_action( 'buddynext_space_member_joined', array( $this, 'bust_user' ), 10, 2 );
		add_action( 'buddynext_space_member_left', array( $this, 'bust_user' ), 10, 2 );
		add_action( 'buddynext_space_member_removed', array( $this, 'bust_user' ), 10, 2 );
	}

	/**
	 * Invalidate global trending-hashtag cache.
	 *
	 * @return void
	 */
	public function bust_trending(): void {
		$this->cache->invalidate_trending();
	}

	/**
	 * Invalidate the follower's suggested-follows entry on follow / unfollow.
	 *
	 * @param int $follower_id  The user doing the (un)follow.
	 * @param int $following_id The user being (un)followed.
	 * @return void
	 */
	public function bust_follower( int $follower_id, int $following_id ): void {
		$this->cache->invalidate_user( $follower_id );
		$this->cache->invalidate_user( $following_id );
	}

	/**
	 * Invalidate both sides on block / unblock.
	 *
	 * @param int $blocker_id The user doing the (un)block.
	 * @param int $blocked_id The user being (un)blocked.
	 * @return void
	 */
	public function bust_blocker( int $blocker_id, int $blocked_id ): void {
		$this->cache->invalidate_user( $blocker_id );
		$this->cache->invalidate_user( $blocked_id );
	}

	/**
	 * Invalidate the user's joined-spaces cache on membership change.
	 *
	 * The space membership hooks fire space-first — ( space_id, user_id, ... ) — so
	 * the param order here matches the firing order, not the (stale) doc.
	 *
	 * @param int $space_id Affected space (unused — kept for hook arg shape).
	 * @param int $user_id  Member whose status changed.
	 * @return void
	 */
	public function bust_user( int $space_id, int $user_id ): void {
		unset( $space_id ); // shape-only param, no per-space cache today.
		$this->cache->invalidate_user( $user_id );
	}
}
