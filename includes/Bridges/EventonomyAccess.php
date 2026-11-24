<?php
/**
 * Answers Eventonomy's "is this person a member of this event?" from the event's Space.
 *
 * Eventonomy 2.0.0 asks a host plugin one question, evnm_user_is_event_member, and derives from it the
 * Members-only level, the "Public, members register" audience and the member ticket price. Without an
 * answer every signed-in user counts as a member. This class gives the answer: the event's home Space
 * decides, through SpaceVisibility::has_member_access(), so Space type, roster and (when BuddyNext Pro is
 * active) plan gating are all honoured by the one rule the Space forum already follows.
 *
 * Free owns it because it needs only Space types and membership, so a site without BuddyNext Pro gets
 * correct members-only events too.
 *
 * @package BuddyNext\Bridges
 * @since   1.3.0
 */

declare(strict_types=1);

namespace BuddyNext\Bridges;

use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Event membership answer for Eventonomy.
 */
class EventonomyAccess {

	/**
	 * First Eventonomy hook-contract version that has the hook this class answers.
	 */
	private const MIN_CONTRACT = 2;

	/**
	 * Answers kept for the request, keyed "eventId:userId".
	 *
	 * @var array<string, bool>
	 */
	private static array $memo = array();

	/**
	 * Register the answer when the installed Eventonomy has the 2.0.0 hook contract.
	 *
	 * An older Eventonomy has no such hook and never asks, so nothing is registered for it.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! defined( 'EVENTONOMY_HOOK_CONTRACT' ) || (int) EVENTONOMY_HOOK_CONTRACT < self::MIN_CONTRACT ) {
			return;
		}
		add_filter( 'evnm_user_is_event_member', array( $this, 'answer' ), 10, 3 );
		add_action( 'evnm_after_update_event', array( $this, 'forget' ), 10, 1 );
		add_action( 'evnm_after_delete_event', array( $this, 'forget' ), 10, 1 );
	}

	/**
	 * Whether the user is a member of the event.
	 *
	 * Eventonomy's own default is passed in: any signed-in user. An event with no Space keeps it. An event
	 * whose Space cannot be found is closed, so deleting or losing the Space never opens a members-only
	 * event to everyone. Asked with no event (Eventonomy's site-wide list check) the answer is no: there is
	 * no Space to ask, and a Space's members find its events on the Space's own Events tab.
	 *
	 * @param mixed $is_member Eventonomy's default answer.
	 * @param mixed $user_id   User id (0 = guest).
	 * @param mixed $event     Event row, or an empty array for the list check.
	 * @return bool
	 */
	public function answer( $is_member, $user_id, $event = array() ): bool {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! is_array( $event ) || ! array_key_exists( 'space_id', $event ) ) {
			return false;
		}

		$space_id = (int) $event['space_id'];
		if ( $space_id <= 0 ) {
			return (bool) $is_member;
		}

		$key = (int) ( $event['id'] ?? 0 ) . ':' . $user_id . ':' . $space_id;
		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = SpaceVisibility::has_member_access( ( new SpaceService() )->get( $space_id ), $user_id );
		}
		return self::$memo[ $key ];
	}

	/**
	 * Drop the remembered answers after an event changes or goes, so a moved Space is asked again.
	 *
	 * @param mixed $event Event row or id.
	 * @return void
	 */
	public function forget( $event = null ): void {
		unset( $event );
		self::$memo = array();
	}
}
