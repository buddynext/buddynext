<?php
/**
 * Answers Eventonomy's access questions about an event, from BuddyNext's own data.
 *
 * Eventonomy 2.0.0 owns the event and asks a host plugin who may see it, register for it and manage it.
 * This class is that host answer. It stores nothing about events: it reads members, Spaces and roles.
 *
 * Two event levels reach a site owner:
 *  - Members only (Eventonomy's own level): any active community member. Held, suspended and
 *    host-blocked people are not members, so they get the preview.
 *  - Space members (a level BuddyNext adds): only members of the event's home Space, through
 *    SpaceVisibility::has_member_access(), so Space type, roster and plan gating all apply. An event at
 *    this level whose Space is gone is closed to everyone but its host and admins.
 * "Public, members register" and the member ticket price use the Members-only answer. Space moderators
 * and managers may manage the events of their Space.
 *
 * Free owns it because it is protection: without it a members-only event opens to every signed-in user,
 * with or without BuddyNext Pro. Pro's plan gating joins in through the Space rule by itself.
 *
 * @package BuddyNext\Bridges
 * @since   1.3.0
 */

declare(strict_types=1);

namespace BuddyNext\Bridges;

use BuddyNext\Auth\MemberHold;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Access answers for Eventonomy.
 */
class EventonomyAccess {

	/**
	 * First Eventonomy hook-contract version that has the hooks this class answers.
	 */
	private const MIN_CONTRACT = 2;

	/**
	 * The level BuddyNext adds to Eventonomy's own four.
	 */
	public const SPACE_LEVEL = 'space';

	/**
	 * Answers kept for the request, keyed by what was asked.
	 *
	 * @var array<string, bool>
	 */
	private static array $memo = array();

	/**
	 * The Space ids a viewer may list, kept for the request.
	 *
	 * @var array<string, int[]>
	 */
	private static array $ids_memo = array();

	/**
	 * Register the answers when the installed Eventonomy has the 2.0.0 hook contract.
	 *
	 * An older Eventonomy has none of these hooks and never asks, so nothing is registered for it.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! defined( 'EVENTONOMY_HOOK_CONTRACT' ) || (int) EVENTONOMY_HOOK_CONTRACT < self::MIN_CONTRACT ) {
			return;
		}
		add_filter( 'evnm_user_is_event_member', array( $this, 'answer' ), 10, 3 );
		add_filter( 'evnm_visibility_levels', array( $this, 'add_space_level' ), 10, 2 );
		add_filter( 'evnm_visibility_hints', array( $this, 'add_space_hint' ) );
		add_filter( 'evnm_event_can_view', array( $this, 'can_view' ), 10, 4 );
		add_filter( 'evnm_visible_levels_for_viewer', array( $this, 'visible_levels' ), 10, 2 );
		// Someone BuddyNext holds out of the community (an unverified email, a plan hold, a suspension) cannot register
		// or buy through Eventonomy either, whether or not the community is private.
		add_filter( 'evnm_before_create_rsvp', array( $this, 'refuse_held' ), 10, 2 );
		add_filter( 'evnm_before_create_order', array( $this, 'refuse_held' ), 10, 2 );
		add_filter( 'evnm_visible_space_ids_for_viewer', array( $this, 'visible_space_ids' ), 10, 2 );
		add_filter( 'evnm_user_can_manage_event', array( $this, 'can_manage' ), 10, 4 );
		add_action( 'evnm_after_update_event', array( $this, 'forget' ), 10, 1 );
		add_action( 'evnm_after_delete_event', array( $this, 'forget' ), 10, 1 );
		// A person who joins or leaves a Space changes which Spaces' events they may list, in this same request too.
		foreach ( array( 'buddynext_space_member_joined', 'buddynext_space_member_left', 'buddynext_space_member_removed' ) as $bn_hook ) {
			add_action( $bn_hook, array( $this, 'forget' ), 10, 0 );
		}
	}

	/**
	 * Whether the user is a member for the Members-only level, "members register" and the member price.
	 *
	 * An active community member: signed in, not held for approval, not suspended, and not blocked by the
	 * event's host. The Space plays no part here; the Space members level has its own answer. Asked with no
	 * event (Eventonomy's list check) there is no host to check, so it is the first three.
	 *
	 * @param mixed $is_member Eventonomy's default answer (signed in).
	 * @param mixed $user_id   User id (0 = guest).
	 * @param mixed $event     Event row, or an empty array for the list check.
	 * @return bool
	 */
	public function answer( $is_member, $user_id, $event = array() ): bool {
		unset( $is_member );
		$host_id = is_array( $event ) ? (int) ( $event['author_id'] ?? 0 ) : 0;
		return $this->is_active_member( (int) $user_id, $host_id );
	}

	/**
	 * Add the Space members level to the levels a host may choose.
	 *
	 * Offered while editing only when the event has a Space, and always for the REST write check (which
	 * passes no fields), so a saved event keeps validating.
	 *
	 * @param mixed $levels Slug => label.
	 * @param mixed $fields Editor field values (empty for REST).
	 * @return array<string, string>
	 */
	public function add_space_level( $levels, $fields = array() ): array {
		$levels = (array) $levels;
		$fields = (array) $fields;
		if ( array() === $fields || (int) ( $fields['space_id'] ?? 0 ) > 0 ) {
			$levels[ self::SPACE_LEVEL ] = __( 'Space members', 'buddynext' );
		}
		return $levels;
	}

	/**
	 * The one-line explanation under the Space members level.
	 *
	 * @param mixed $hints Slug => hint.
	 * @return array<string, string>
	 */
	public function add_space_hint( $hints ): array {
		$hints                      = (array) $hints;
		$hints[ self::SPACE_LEVEL ] = __( 'Only members of this event\'s Space see the whole event. Everyone else sees the title, date, city and cover, and a way to join.', 'buddynext' );
		return $hints;
	}

	/**
	 * Whether the viewer may see an event at the Space members level.
	 *
	 * Other levels pass through untouched. The viewer must be an active member AND a member of the home
	 * Space (SpaceVisibility::has_member_access). An event with no Space, or whose Space no longer exists,
	 * is closed: the answer is no, and Eventonomy still lets its host and admins in.
	 *
	 * @param mixed $can     Answer so far (null for a host-added level).
	 * @param mixed $event   Event row.
	 * @param mixed $user_id Viewer.
	 * @param mixed $level   The event's visibility level.
	 * @return mixed
	 */
	public function can_view( $can, $event, $user_id, $level = '' ) {
		if ( self::SPACE_LEVEL !== (string) $level ) {
			return $can;
		}
		$user_id  = (int) $user_id;
		$space_id = is_array( $event ) ? (int) ( $event['space_id'] ?? 0 ) : 0;
		$host_id  = is_array( $event ) ? (int) ( $event['author_id'] ?? 0 ) : 0;
		if ( $space_id <= 0 || ! $this->is_active_member( $user_id, $host_id ) ) {
			return false;
		}

		$key = 'space:' . $space_id . ':' . $user_id;
		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = SpaceVisibility::has_member_access( ( new SpaceService() )->get( $space_id ), $user_id );
		}
		return self::$memo[ $key ];
	}

	/**
	 * Refuse a registration or an order from someone the community holds out, before anything is written.
	 *
	 * The person acting is judged, not the person the seat is for, so an organizer can still add an attendee. The
	 * hold is the one BuddyNext applies everywhere (RestHoldGate::current_member_is_held): an unverified email, a
	 * forced 2FA enrolment, an add-on's plan hold, and a suspension on a write. Administrators are never held.
	 *
	 * @param mixed $data    The prepared registration or order (returned untouched when nobody is held).
	 * @param mixed $context Caller context (unused).
	 * @return mixed The data, or a 403 WP_Error.
	 */
	public function refuse_held( $data, $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_wp_error( $data ) && is_user_logged_in() && ( new \BuddyNext\Auth\RestHoldGate() )->current_member_is_held() ) {
			$hold    = \BuddyNext\Auth\MemberHold::get( get_current_user_id() );
			$message = '' !== (string) ( $hold['message'] ?? '' )
				? (string) $hold['message']
				: ( buddynext_service( 'moderation' )->is_suspended( get_current_user_id() )
					? __( 'Your account is suspended, so you cannot register for events right now.', 'buddynext' )
					: __( 'Finish setting up your account before you register for events.', 'buddynext' ) );
			return new \WP_Error( 'buddynext_member_held', $message, array( 'status' => 403 ) );
		}
		return $data;
	}

	/**
	 * Add the Space members level to the levels an active member's event lists may show.
	 *
	 * Which Spaces' events appear is narrowed by visible_space_ids(); a guest and a person who is not an active
	 * member never get the level.
	 *
	 * @param mixed $levels Level slugs the viewer may list.
	 * @param mixed $uid    Viewer (0 = guest).
	 * @return array<int,string>
	 */
	public function visible_levels( $levels, $uid ): array {
		$levels = array_values( (array) $levels );
		if ( (int) $uid > 0 && $this->is_active_member( (int) $uid, 0 ) ) {
			$levels[] = self::SPACE_LEVEL;
		}
		return $levels;
	}

	/**
	 * The Spaces whose members-only events this viewer may see in a list: the Spaces they belong to and may open.
	 *
	 * One indexed read of the viewer's active memberships, cut to Eventonomy's 500, once per request. A Space that
	 * needs a plan is kept only while the plan is valid, so a list never shows what the event page then refuses.
	 * Admins are not narrowed (Eventonomy already lets managers see every level).
	 *
	 * @param mixed $space_ids Eventonomy's default (null = every Space).
	 * @param mixed $uid       Viewer (0 = guest).
	 * @return mixed
	 */
	public function visible_space_ids( $space_ids, $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return array();
		}
		$key = 'spaces:' . $uid;
		if ( ! isset( self::$ids_memo[ $key ] ) ) {
			$service = new SpaceService();
			$ids     = array();
			foreach ( array_slice( ( new \BuddyNext\Spaces\SpaceMemberService() )->spaces_for_user( $uid ), 0, 500 ) as $space_id ) {
				$space = $service->get( (int) $space_id );
				if ( is_array( $space ) && ( '' === (string) ( $space['required_ability'] ?? '' ) || SpaceVisibility::has_member_access( $space, $uid ) ) ) {
					$ids[] = (int) $space_id;
				}
			}
			self::$ids_memo[ $key ] = $ids;
		}
		return self::$ids_memo[ $key ];
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
		self::$memo     = array();
		self::$ids_memo = array();
	}

	/**
	 * Whether a user is an active community member, optionally against one host.
	 *
	 * Signed in, not held (a membership plan or approval the community requires), not suspended, and not
	 * blocked by the host. Site admins are never held.
	 *
	 * @param int $user_id User to check (0 = guest).
	 * @param int $host_id Event host to check blocks against (0 = none).
	 * @return bool
	 */
	private function is_active_member( int $user_id, int $host_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$key = 'member:' . $user_id . ':' . $host_id;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$active = null === MemberHold::get( $user_id )
			&& ! buddynext_service( 'moderation' )->is_suspended( $user_id )
			&& ( $host_id <= 0 || $host_id === $user_id || ! buddynext_service( 'blocks' )->has_blocked( $host_id, $user_id ) );

		self::$memo[ $key ] = $active;
		return $active;
	}
}
