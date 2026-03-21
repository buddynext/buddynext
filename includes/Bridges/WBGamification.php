<?php
/**
 * WBGamification bridge.
 *
 * Translates BuddyNext social actions into WBGamification events so the
 * WBGam rules engine can award points, badges, and levels.
 *
 * BuddyNext fires buddynext_* actions → bridge fires wb_gamification_event
 * with a typed event slug. WBGam admin configures rules per event type.
 * Nothing is hard-coded — all points/badge logic lives in WBGam.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

/**
 * WBGamification ↔ BuddyNext event bridge.
 */
class WBGamification {

	/**
	 * Supported BuddyNext action → WBGam event type mappings.
	 * Each handler below translates one BuddyNext action into a wb_gamification_event call.
	 */

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		add_action( 'buddynext_user_followed', array( $this, 'on_user_followed' ), 10, 2 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_connection_accepted' ), 10, 2 );
		add_action( 'buddynext_post_created', array( $this, 'on_post_created' ), 10, 3 );
		add_action( 'buddynext_member_joined_space', array( $this, 'on_space_joined' ), 10, 2 );
		add_action( 'buddynext_strike_issued', array( $this, 'on_strike_issued' ), 10, 3 );
	}

	/**
	 * Translate buddynext_user_followed → bn_followed.
	 *
	 * @param int $follower_id  User who followed.
	 * @param int $following_id User who was followed (receives the points).
	 */
	public function on_user_followed( int $follower_id, int $following_id ): void {
		$this->fire( 'bn_followed', $following_id, array( 'follower_id' => $follower_id ) );
	}

	/**
	 * Translate buddynext_connection_accepted → bn_connected.
	 *
	 * @param int $user_a Initiating user.
	 * @param int $user_b Accepting user.
	 */
	public function on_connection_accepted( int $user_a, int $user_b ): void {
		$this->fire( 'bn_connected', $user_a, array( 'peer_id' => $user_b ) );
		$this->fire( 'bn_connected', $user_b, array( 'peer_id' => $user_a ) );
	}

	/**
	 * Translate buddynext_post_created → bn_post_created.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author.
	 * @param string $type    Post type.
	 */
	public function on_post_created( int $post_id, int $user_id, string $type ): void {
		$this->fire(
			'bn_post_created',
			$user_id,
			array(
				'post_id' => $post_id,
				'type'    => $type,
			)
		);
	}

	/**
	 * Translate buddynext_member_joined_space → bn_space_joined.
	 *
	 * @param int $user_id  Joining user.
	 * @param int $space_id Space joined.
	 */
	public function on_space_joined( int $user_id, int $space_id ): void {
		$this->fire( 'bn_space_joined', $user_id, array( 'space_id' => $space_id ) );
	}

	/**
	 * Translate buddynext_strike_issued → bn_strike_issued (for point deduction).
	 *
	 * @param int $strike_id Strike record ID.
	 * @param int $user_id   Struck user.
	 * @param int $actor_id  Admin who issued the strike.
	 */
	public function on_strike_issued( int $strike_id, int $user_id, int $actor_id ): void {
		$this->fire(
			'bn_strike_issued',
			$user_id,
			array(
				'strike_id' => $strike_id,
				'actor_id'  => $actor_id,
			)
		);
	}

	/**
	 * Fire the WBGam event action.
	 *
	 * @param string $event_type WBGam event slug.
	 * @param int    $user_id    User receiving the event.
	 * @param array  $context    Additional event context.
	 */
	private function fire( string $event_type, int $user_id, array $context = array() ): void {
		/**
		 * WBGamification event — rules engine entry point.
		 *
		 * @param string $event_type Event slug (e.g. 'bn_followed').
		 * @param int    $user_id    User the event applies to.
		 * @param array  $context    Event context (source IDs, type, etc.).
		 */
		do_action( 'wb_gamification_event', $event_type, $user_id, $context );
	}
}
