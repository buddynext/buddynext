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
		add_filter( 'evnm_user_can_manage_event', array( $this, 'can_manage' ), 10, 4 );
		add_action( 'evnm_after_update_event', array( $this, 'forget' ), 10, 1 );
		add_action( 'evnm_after_delete_event', array( $this, 'forget' ), 10, 1 );
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
		$hints                       = (array) $hints;
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
