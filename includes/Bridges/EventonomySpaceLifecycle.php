<?php
/**
 * Tells Eventonomy when a Space goes away or a member leaves it.
 *
 * BuddyNext owns Spaces and who is in them; Eventonomy owns the events. So BuddyNext only says what happened and
 * Eventonomy decides what that means for its events:
 *  - a Space is deleted: Eventonomy closes the events that were homed in it, quietly;
 *  - a member leaves, is removed or is banned: Eventonomy cancels that member's registrations to the Space-members
 *    events homed in that Space (public events and events of other Spaces are never touched).
 *
 * Free owns it because it is protection: without it a member who left a Space could still hold a place at that
 * Space's members-only events. It stores nothing.
 *
 * @package BuddyNext\Bridges
 * @since   1.3.0
 */

declare(strict_types=1);

namespace BuddyNext\Bridges;

defined( 'ABSPATH' ) || exit;

/**
 * Space and member changes, passed on to Eventonomy.
 */
class EventonomySpaceLifecycle {

	/**
	 * First Eventonomy hook-contract version that has evnm_space_removed and evnm_cancel_user_registrations.
	 */
	private const MIN_CONTRACT = 4;

	/**
	 * Spaces already announced in this request, so a big Space (queued, then deleted) is announced once.
	 *
	 * @var array<int,true>
	 */
	private static array $announced = array();

	/**
	 * Register the listeners when the installed Eventonomy has the hooks; an older one has nothing to tell.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! defined( 'EVENTONOMY_HOOK_CONTRACT' ) || (int) EVENTONOMY_HOOK_CONTRACT < self::MIN_CONTRACT ) {
			return;
		}
		add_action( 'buddynext_space_delete_queued', array( $this, 'space_removed' ), 10, 1 );
		add_action( 'buddynext_space_deleted', array( $this, 'space_removed' ), 10, 1 );
		add_action( 'buddynext_space_member_left', array( $this, 'member_gone' ), 10, 2 );
		add_action( 'buddynext_space_member_removed', array( $this, 'member_gone' ), 10, 2 );
	}

	/**
	 * A Space was deleted, or hidden and queued for deletion.
	 *
	 * @param mixed $space_id Space ID.
	 * @return void
	 */
	public function space_removed( $space_id ): void {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 || isset( self::$announced[ $space_id ] ) ) {
			return;
		}
		self::$announced[ $space_id ] = true;

		/**
		 * A Space is gone. Eventonomy closes the events that were homed in it.
		 *
		 * @param int $space_id The deleted Space.
		 */
		do_action( 'evnm_space_removed', $space_id );
	}

	/**
	 * A member left a Space, was removed from it, or was banned from it.
	 *
	 * @param mixed $space_id Space ID.
	 * @param mixed $user_id  The member.
	 * @return void
	 */
	public function member_gone( $space_id, $user_id ): void {
		if ( function_exists( 'evnm_cancel_user_registrations' ) ) {
			evnm_cancel_user_registrations( (int) $user_id, (int) $space_id );
		}
	}
}
