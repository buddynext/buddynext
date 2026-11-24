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
 * It also answers who may manage an event: a moderator or manager of the event's home Space may edit it and
 * see its attendees. Free owns both because they need only Space types, roles and membership, so a site
 * without BuddyNext Pro gets correct members-only events too.
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
		add_filter( 'evnm_user_can_manage_event', array( $this, 'can_manage' ), 10, 4 );
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
	 * Whether a Space moderator or manager may manage an event of their Space.
	 *
	 * Owner decision: a Space's moderators manage every event in that Space, home Space only, and the right
	 * goes the moment the role does (it is read live, never kept). It covers editing the event and seeing and
	 * managing its attendees. Orders and refunds (money) and deleting stay with the event's owner and site
	 * admins. A yes that Eventonomy already reached is never taken away.
	 *
	 * @param mixed $base       Eventonomy's own answer.
	 * @param mixed $user_id    User id.
	 * @param mixed $event      Event row.
	 * @param mixed $capability One of edit, delete, manage_rsvps, manage_orders.
	 * @return bool
	 */
	public function can_manage( $base, $user_id, $event = array(), $capability = 'edit' ): bool {
		if ( (bool) $base ) {
			return true;
		}
		$user_id  = (int) $user_id;
		$space_id = is_array( $event ) ? (int) ( $event['space_id'] ?? 0 ) : 0;
		if ( $user_id <= 0 || $space_id <= 0 || ! in_array( $capability, array( 'edit', 'manage_rsvps' ), true ) || ! function_exists( 'buddynext_can' ) ) {
			return false;
		}
		$context = array( 'space_id' => $space_id );
		return (bool) buddynext_can( $user_id, 'buddynext-manage-space', $context )
			|| (bool) buddynext_can( $user_id, 'buddynext-moderate-space', $context );
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
