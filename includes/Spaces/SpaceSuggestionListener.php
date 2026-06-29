<?php
/**
 * BuddyNext — Suggested-spaces cache invalidation.
 *
 * Flushes a viewer's cached space suggestions when the signals behind them change:
 * the viewer joins/leaves a space (changes the exclude set) or follows/unfollows
 * someone (changes the social-proof set). A short TTL is the backstop; these busts
 * keep a just-joined space from lingering in the rail.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Busts per-viewer suggestion caches on membership / follow changes.
 */
final class SpaceSuggestionListener implements ListenerInterface {

	/**
	 * Hook the invalidation triggers.
	 *
	 * @return void
	 */
	public function register(): void {
		// buddynext_space_member_joined fires ( space_id, user_id, role ).
		add_action( 'buddynext_space_member_joined', array( $this, 'on_joined' ), 10, 2 );
		// buddynext_space_member_left fires ( space_id, user_id ).
		add_action( 'buddynext_space_member_left', array( $this, 'on_left' ), 10, 2 );
		// follow hooks fire ( follower_id, following_id ) — the follower's view changes.
		add_action( 'buddynext_user_followed', array( $this, 'on_follow_change' ), 10, 1 );
		add_action( 'buddynext_user_unfollowed', array( $this, 'on_follow_change' ), 10, 1 );
	}

	/**
	 * Flush suggestions for a member who just joined a space.
	 *
	 * @param int $space_id Space ID (unused).
	 * @param int $user_id  Member who joined.
	 * @return void
	 */
	public function on_joined( int $space_id, int $user_id ): void {
		unset( $space_id );
		$this->flush( $user_id );
	}

	/**
	 * Flush suggestions for a member who just left a space.
	 *
	 * @param int $space_id Space left (unused).
	 * @param int $user_id  Member who left.
	 * @return void
	 */
	public function on_left( int $space_id, int $user_id ): void {
		unset( $space_id );
		$this->flush( $user_id );
	}

	/**
	 * Flush suggestions for a viewer whose follow set changed.
	 *
	 * @param int $follower_id The viewer whose follow set changed.
	 * @return void
	 */
	public function on_follow_change( int $follower_id ): void {
		$this->flush( $follower_id );
	}

	/**
	 * Flush one viewer's suggestion cache.
	 *
	 * @param int $user_id Viewer user ID.
	 * @return void
	 */
	private function flush( int $user_id ): void {
		if ( $user_id > 0 ) {
			( new SpaceSuggestionService() )->flush_for_user( $user_id );
		}
	}
}
