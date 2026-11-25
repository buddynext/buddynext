<?php
/**
 * The access answers BuddyNext gives Eventonomy 2.0.0, for the two event levels.
 *
 * Members only: any active community member (signed in, not held, not suspended, not blocked by the host),
 * whatever Space the event sits in. Space members (a level BuddyNext adds): only the event's home Space,
 * through one rule: owner or admin, a holder of the plan that gates the Space while it is valid (joined or
 * not, never a member whose plan lapsed), otherwise an active member. A Space that is gone closes the event.
 *
 * @package BuddyNext\Tests
 */

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\EventonomyAccess;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

if ( ! defined( 'EVENTONOMY_HOOK_CONTRACT' ) ) {
	define( 'EVENTONOMY_HOOK_CONTRACT', 2 );
}

/**
 * @covers \BuddyNext\Bridges\EventonomyAccess
 * @covers \BuddyNext\Spaces\SpaceVisibility::has_member_access
 */
class EventonomyAccessTest extends \WP_UnitTestCase {

	private int $owner;
	private int $member;
	private int $outsider;
	private EventonomyAccess $access;

	public function set_up(): void {
		parent::set_up();
		$this->owner    = self::factory()->user->create();
		$this->member   = self::factory()->user->create();
		$this->outsider = self::factory()->user->create();
		$this->access   = new EventonomyAccess();
		$this->access->init();
	}

	private function space( string $type, string $ability = '' ): int {
		global $wpdb;
		$id = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Ev ' . wp_generate_password( 5, false, false ),
				'slug' => 'ev-' . strtolower( wp_generate_password( 8, false, false ) ),
				'type' => $type,
			)
		);
		if ( '' !== $ability ) {
			$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'required_ability' => $ability ), array( 'id' => $id ) );
			wp_cache_flush();
		}
		return $id;
	}

	/**
	 * Make a user an active member: join an open Space, or request and be approved into a closed one.
	 */
	private function join( int $space, int $user ): void {
		$members = new SpaceMemberService();
		$members->join( $space, $user );
		$members->request_join( $space, $user );
		$members->approve_request( $space, $this->owner, $user );
	}

	/**
	 * Ask the way Eventonomy does.
	 *
	 * @param int                 $user  Viewer.
	 * @param array<string,mixed> $event Event row.
	 * @return bool
	 */
	private function is_member( int $user, array $event ): bool {
		return (bool) apply_filters( 'evnm_user_is_event_member', $user > 0, $user, $event );
	}

	public function test_members_only_is_any_active_member_whatever_the_space(): void {
		$space = $this->space( 'private' );
		$event = array( 'id' => 11, 'author_id' => $this->owner, 'space_id' => $space );

		$this->assertTrue( $this->is_member( $this->outsider, $event ), 'The Space plays no part at the community level.' );
		$this->assertTrue( $this->is_member( $this->outsider, array( 'id' => 12, 'author_id' => $this->owner, 'space_id' => 0 ) ) );
		$this->assertFalse( $this->is_member( 0, $event ), 'A guest never counts.' );
	}

	public function test_held_suspended_and_host_blocked_people_are_not_members(): void {
		$event = array( 'id' => 13, 'author_id' => $this->owner, 'space_id' => 0 );

		$held = self::factory()->user->create();
		add_filter( 'buddynext_member_hold', static fn( $h, $uid ) => (int) $uid === $held ? array( 'url' => home_url( '/hold/' ) ) : $h, 10, 2 );
		$this->assertFalse( $this->is_member( $held, $event ), 'A held member is a guest for events.' );

		$suspended = self::factory()->user->create();
		$suspension = buddynext_service( 'moderation' )->suspend_user( $suspended, self::factory()->user->create( array( 'role' => 'administrator' ) ), 'test' );
		$this->assertIsInt( $suspension, 'Precondition: the suspension was recorded.' );
		$this->assertFalse( $this->is_member( $suspended, $event ), 'A suspended member is not a member.' );

		$blocked = self::factory()->user->create();
		buddynext_service( 'blocks' )->block( $this->owner, $blocked );
		$this->assertFalse( $this->is_member( $blocked, $event ), 'Someone the host blocked is refused.' );
		$this->assertTrue( $this->is_member( $blocked, array( 'id' => 14, 'author_id' => $this->member, 'space_id' => 0 ) ), 'A different host did not block them.' );
	}

	public function test_a_site_admin_is_always_a_member(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( $this->is_member( $admin, array( 'id' => 15, 'author_id' => $this->owner, 'space_id' => 0 ) ) );
	}

	public function test_the_list_question_without_an_event_is_answered_for_active_members(): void {
		// Eventonomy asks this with no event to decide whether to list Members-only events site-wide.
		$this->assertTrue( (bool) apply_filters( 'evnm_user_is_event_member', true, $this->member, array() ) );
		$this->assertFalse( (bool) apply_filters( 'evnm_user_is_event_member', true, 0, array() ) );
	}

	/**
	 * Ask the way Eventonomy does for the Space members level.
	 */
	private function can_view( int $user, array $event ): bool {
		return true === apply_filters( 'evnm_event_can_view', null, $event, $user, EventonomyAccess::SPACE_LEVEL );
	}

	public function test_space_members_level_needs_membership_of_the_home_space(): void {
		$space = $this->space( 'open' );
		$event = array( 'id' => 21, 'author_id' => $this->owner, 'space_id' => $space );
		$this->join( $space, $this->member );

		$this->assertTrue( $this->can_view( $this->member, $event ), 'A member counts.' );
		$this->assertTrue( $this->can_view( $this->owner, $event ), 'The Space owner counts.' );
		$this->assertFalse( $this->can_view( $this->outsider, $event ), 'Signed in is not enough, even on an open Space.' );
		$this->assertFalse( $this->can_view( 0, $event ) );
	}

	public function test_private_and_secret_spaces_count_members_only(): void {
		foreach ( array( 'private', 'secret' ) as $type ) {
			$space = $this->space( $type );
			$event = array( 'id' => 22, 'author_id' => $this->owner, 'space_id' => $space );
			$this->join( $space, $this->member );

			$this->assertTrue( $this->can_view( $this->member, $event ), "$type: a member counts." );
			$this->assertFalse( $this->can_view( $this->outsider, $event ), "$type: an outsider does not." );
		}
	}

	public function test_a_site_admin_sees_a_space_members_event(): void {
		$space = $this->space( 'private' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( $this->can_view( $admin, array( 'id' => 23, 'author_id' => $this->owner, 'space_id' => $space ) ) );
	}

	public function test_a_plan_holder_who_has_not_joined_counts_on_a_plan_gated_space(): void {
		$space = $this->space( 'open', 'tier:gold' );
		$event = array( 'id' => 24, 'author_id' => $this->owner, 'space_id' => $space );
		$plan  = $this->outsider;
		$gate  = static fn( $can, $space_id, $viewer ) => (int) $viewer === $plan ? $can : false;
		add_filter( 'buddynext_can_view_space_content', $gate, 10, 3 );

		$this->assertTrue( $this->can_view( $plan, $event ), 'A holder of the gating plan counts, joined or not.' );
		$this->assertFalse( $this->can_view( self::factory()->user->create(), $event ), 'Someone without the plan does not.' );
		remove_filter( 'buddynext_can_view_space_content', $gate, 10 );
	}

	public function test_a_member_whose_plan_lapsed_no_longer_counts(): void {
		// Owner decision: the same live rule as the Space's own posts, roster and leaderboard.
		$space = $this->space( 'open', 'tier:gold' );
		$this->join( $space, $this->member );
		$gate = static fn() => false;
		add_filter( 'buddynext_can_view_space_content', $gate );

		$this->assertFalse( $this->can_view( $this->member, array( 'id' => 25, 'author_id' => $this->owner, 'space_id' => $space ) ) );
		$this->assertTrue( $this->can_view( $this->owner, array( 'id' => 26, 'author_id' => $this->owner, 'space_id' => $space ) ), 'The owner is never locked out.' );
		remove_filter( 'buddynext_can_view_space_content', $gate );
	}

	public function test_a_gated_space_with_no_plan_evaluator_falls_back_to_its_roster(): void {
		// Pro absent: nothing answers the plan gate, so an open gated Space must not make every login a member.
		$space = $this->space( 'open', 'tier:gold' );
		$this->join( $space, $this->member );
		remove_all_filters( 'buddynext_can_view_space_content' );
		$event = array( 'id' => 27, 'author_id' => $this->owner, 'space_id' => $space );

		$this->assertTrue( $this->can_view( $this->member, $event ) );
		$this->assertFalse( $this->can_view( $this->outsider, $event ) );
	}

	public function test_a_members_event_lists_include_only_the_spaces_the_member_can_open(): void {
		$mine   = $this->space( 'open' );
		$other  = $this->space( 'open' );
		$gated  = $this->space( 'open', 'tier:gold' );
		$this->join( $mine, $this->member );
		$this->join( $gated, $this->member );
		$gate = static fn() => false; // The plan has lapsed.
		add_filter( 'buddynext_can_view_space_content', $gate );

		$this->assertContains( 'space', $this->access->visible_levels( array( 'public' ), $this->member ) );
		$ids = $this->access->visible_space_ids( null, $this->member );
		$this->assertSame( array( $mine ), $ids, 'Not the Space they are not in, and not the one whose plan lapsed.' );
		$this->assertNotContains( $other, $ids );
		remove_filter( 'buddynext_can_view_space_content', $gate );
	}

	public function test_a_guest_and_a_held_member_list_no_space_events(): void {
		$space = $this->space( 'open' );
		$this->join( $space, $this->member );
		$member = $this->member;
		add_filter( 'buddynext_member_hold', static fn( $h, $uid ) => (int) $uid === $member ? array( 'url' => home_url( '/hold/' ) ) : $h, 10, 2 );

		$this->assertNotContains( 'space', $this->access->visible_levels( array( 'public' ), 0 ) );
		$this->assertSame( array(), $this->access->visible_space_ids( null, 0 ) );
		$this->assertNotContains( 'space', $this->access->visible_levels( array( 'public' ), $this->member ) );
	}

	public function test_joining_a_space_updates_the_list_in_the_same_request(): void {
		$space = $this->space( 'open' );
		$this->assertSame( array(), $this->access->visible_space_ids( null, $this->member ) );

		$this->join( $space, $this->member );

		$this->assertSame( array( $space ), $this->access->visible_space_ids( null, $this->member ) );
	}

	public function test_a_member_who_joins_sees_the_event_at_once(): void {
		$space = $this->space( 'private' );
		$event = array( 'id' => 34, 'author_id' => $this->owner, 'space_id' => $space );
		$this->assertFalse( $this->can_view( $this->outsider, $event ) );

		$this->join( $space, $this->outsider );

		$this->assertTrue( $this->can_view( $this->outsider, $event ), 'Joining clears the remembered answer.' );
	}

	public function test_a_space_that_is_gone_or_missing_closes_the_event(): void {
		$this->assertFalse( $this->can_view( $this->member, array( 'id' => 28, 'author_id' => 1, 'space_id' => 987654321 ) ), 'Space deleted.' );
		$this->assertFalse( $this->can_view( $this->member, array( 'id' => 29, 'author_id' => 1, 'space_id' => 0 ) ), 'No Space at all.' );
	}

	public function test_a_held_space_member_cannot_see_a_space_members_event(): void {
		$space = $this->space( 'open' );
		$this->join( $space, $this->member );
		$member = $this->member;
		add_filter( 'buddynext_member_hold', static fn( $h, $uid ) => (int) $uid === $member ? array( 'url' => home_url( '/hold/' ) ) : $h, 10, 2 );

		$this->assertFalse( $this->can_view( $this->member, array( 'id' => 30, 'author_id' => $this->owner, 'space_id' => $space ) ) );
	}

	public function test_other_levels_pass_through_untouched(): void {
		$this->assertNull( apply_filters( 'evnm_event_can_view', null, array( 'id' => 31, 'space_id' => 5 ), $this->member, 'members_only' ) );
		$this->assertTrue( apply_filters( 'evnm_event_can_view', true, array( 'id' => 32 ), $this->member, 'public' ) );
	}

	public function test_the_space_level_is_offered_with_a_space_and_for_rest_validation(): void {
		$base = array( 'public' => 'Public', 'members_only' => 'Members only' );

		$this->assertArrayHasKey( 'space', apply_filters( 'evnm_visibility_levels', $base, array( 'space_id' => 7 ) ), 'Editing an event that has a Space.' );
		$this->assertArrayHasKey( 'space', apply_filters( 'evnm_visibility_levels', $base, array() ), 'REST write validation passes no fields.' );
		$this->assertArrayNotHasKey( 'space', apply_filters( 'evnm_visibility_levels', $base, array( 'space_id' => 0 ) ), 'No Space, no Space level.' );
		$this->assertArrayHasKey( 'space', apply_filters( 'evnm_visibility_hints', array() ) );
	}

	public function test_the_answer_is_remembered_for_the_request_and_cleared_when_the_event_changes(): void {
		$space = $this->space( 'private' );
		$event = array( 'id' => 33, 'author_id' => $this->owner, 'space_id' => $space );
		$this->assertFalse( $this->can_view( $this->outsider, $event ) );

		// A row written without the join hooks, so nothing tells the answer to be asked again.
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id'  => $space,
				'user_id'   => $this->outsider,
				'role'      => 'member',
				'status'    => 'active',
				'joined_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		wp_cache_flush();
		$this->assertFalse( $this->can_view( $this->outsider, $event ), 'Same request: the first answer stands.' );

		do_action( 'evnm_after_update_event', $event, array( 'space_id' => $space ), array() );
		$this->assertTrue( $this->can_view( $this->outsider, $event ), 'The event changed, so it is asked again.' );
	}

	/**
	 * Ask the way Eventonomy does for the right to manage an event.
	 */
	private function can_manage( int $user, array $event, string $capability ): bool {
		return (bool) apply_filters( 'evnm_user_can_manage_event', false, $user, $event, $capability );
	}

	public function test_a_space_moderator_edits_and_sees_attendees_of_an_event_in_their_space(): void {
		$space     = $this->space( 'open' );
		$moderator = self::factory()->user->create();
		$this->join( $space, $moderator );
		( new SpaceMemberService() )->change_role( $space, $moderator, 'moderator', $this->owner );
		$event = array( 'id' => 21, 'author_id' => $this->outsider, 'space_id' => $space );

		$this->assertTrue( $this->can_manage( $moderator, $event, 'edit' ) );
		$this->assertTrue( $this->can_manage( $moderator, $event, 'manage_rsvps' ) );
		$this->assertTrue( $this->can_manage( $this->owner, $event, 'edit' ), 'The Space owner can too.' );
	}

	public function test_a_moderator_never_gets_the_money_or_delete_rights(): void {
		$space     = $this->space( 'open' );
		$moderator = self::factory()->user->create();
		$this->join( $space, $moderator );
		( new SpaceMemberService() )->change_role( $space, $moderator, 'moderator', $this->owner );
		$event = array( 'id' => 22, 'author_id' => $this->outsider, 'space_id' => $space );

		$this->assertFalse( $this->can_manage( $moderator, $event, 'manage_orders' ), 'Orders and refunds stay with the event owner and admins.' );
		$this->assertFalse( $this->can_manage( $moderator, $event, 'delete' ) );
	}

	public function test_the_right_stops_at_the_home_space_and_needs_the_role(): void {
		$home      = $this->space( 'open' );
		$other     = $this->space( 'open' );
		$moderator = self::factory()->user->create();
		$this->join( $home, $moderator );
		( new SpaceMemberService() )->change_role( $home, $moderator, 'moderator', $this->owner );
		$this->join( $other, $this->member );

		$this->assertFalse( $this->can_manage( $moderator, array( 'id' => 23, 'author_id' => 1, 'space_id' => $other ), 'edit' ), 'Another Space\'s event is not theirs.' );
		$this->assertFalse( $this->can_manage( $this->member, array( 'id' => 24, 'author_id' => 1, 'space_id' => $other ), 'edit' ), 'A plain member is not a moderator.' );
		$this->assertFalse( $this->can_manage( $moderator, array( 'id' => 25, 'author_id' => 1, 'space_id' => 0 ), 'edit' ), 'An event with no Space has no Space moderator.' );
	}

	public function test_demoting_a_moderator_removes_the_right_at_once(): void {
		$space     = $this->space( 'open' );
		$moderator = self::factory()->user->create();
		$this->join( $space, $moderator );
		$members = new SpaceMemberService();
		$members->change_role( $space, $moderator, 'moderator', $this->owner );
		$event = array( 'id' => 26, 'author_id' => 1, 'space_id' => $space );
		$this->assertTrue( $this->can_manage( $moderator, $event, 'edit' ) );

		$members->change_role( $space, $moderator, 'member', $this->owner );
		$this->assertFalse( $this->can_manage( $moderator, $event, 'edit' ) );
	}

	public function test_an_existing_yes_is_never_taken_away(): void {
		$this->assertTrue( (bool) apply_filters( 'evnm_user_can_manage_event', true, $this->outsider, array( 'id' => 27, 'space_id' => 0 ), 'edit' ) );
	}

	public function test_someone_the_community_holds_out_cannot_register_or_order(): void {
		$held = $this->member;
		add_filter( 'buddynext_member_hold', static fn( $hold, $uid ) => (int) $uid === $held ? array( 'url' => home_url( '/hold/' ), 'code' => 'x', 'message' => 'Renew your plan first.' ) : $hold, 10, 2 );
		$data = array( 'event_id' => 1, 'user_id' => $held );

		wp_set_current_user( $held );
		foreach ( array( 'evnm_before_create_rsvp', 'evnm_before_create_order' ) as $hook ) {
			$result = apply_filters( $hook, $data, array() );
			$this->assertWPError( $result, $hook );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( 'Renew your plan first.', $result->get_error_message() );
		}

		wp_set_current_user( $this->outsider );
		$this->assertSame( $data, apply_filters( 'evnm_before_create_rsvp', $data, array() ), 'Not held: untouched.' );

		wp_set_current_user( 1 );
		$this->assertSame( $data, apply_filters( 'evnm_before_create_order', $data, array() ), 'An administrator is never held.' );
		wp_set_current_user( 0 );
		$this->assertSame( $data, apply_filters( 'evnm_before_create_rsvp', $data, array() ), 'A guest is Eventonomy\'s question, not this one.' );
	}
}
