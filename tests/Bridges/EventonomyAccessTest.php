<?php
/**
 * Who counts as a member of an event: the one answer BuddyNext gives Eventonomy 2.0.0.
 *
 * Owner rule: a member is someone who can see inside the event's home Space (its owner, an active
 * member, a site admin, or a holder of the plan that gates it); an event with no Space keeps
 * Eventonomy's default (any signed-in user). Nobody else counts, and a Space that no longer exists
 * closes the event. Eventonomy derives the members-only level, "public, members register" and the
 * member ticket price from this one answer.
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

	public function test_an_event_with_no_space_keeps_the_default_signed_in_answer(): void {
		$event = array( 'id' => 11, 'space_id' => 0 );

		$this->assertTrue( $this->is_member( $this->outsider, $event ) );
		$this->assertFalse( $this->is_member( 0, $event ), 'A guest is never a member.' );
	}

	public function test_an_open_space_needs_membership_not_just_a_login(): void {
		$space = $this->space( 'open' );
		$event = array( 'id' => 12, 'space_id' => $space );
		$this->join( $space, $this->member );

		$this->assertTrue( $this->is_member( $this->member, $event ), 'A member counts.' );
		$this->assertTrue( $this->is_member( $this->owner, $event ), 'The owner counts.' );
		$this->assertFalse( $this->is_member( $this->outsider, $event ), 'Signed in is not enough on an open Space.' );
		$this->assertFalse( $this->is_member( 0, $event ) );
	}

	public function test_private_and_secret_spaces_count_members_only(): void {
		foreach ( array( 'private', 'secret' ) as $type ) {
			$space = $this->space( $type );
			$event = array( 'id' => 13, 'space_id' => $space );
			$this->join( $space, $this->member );

			$this->assertTrue( $this->is_member( $this->member, $event ), "$type: a member counts." );
			$this->assertFalse( $this->is_member( $this->outsider, $event ), "$type: an outsider does not." );
		}
	}

	public function test_a_site_admin_counts(): void {
		$space = $this->space( 'private' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( $this->is_member( $admin, array( 'id' => 14, 'space_id' => $space ) ) );
	}

	public function test_a_plan_holder_who_has_not_joined_counts_on_a_plan_gated_space(): void {
		$space = $this->space( 'open', 'tier:gold' );
		$event = array( 'id' => 15, 'space_id' => $space );
		$plan  = $this->outsider;
		$gate  = static fn( $can, $space_id, $viewer ) => (int) $viewer === $plan ? $can : false;
		add_filter( 'buddynext_can_view_space_content', $gate, 10, 3 );

		$this->assertTrue( $this->is_member( $plan, $event ), 'A holder of the gating plan counts.' );
		$this->assertFalse( $this->is_member( self::factory()->user->create(), $event ), 'Someone without the plan does not.' );
		remove_filter( 'buddynext_can_view_space_content', $gate, 10 );
	}

	public function test_a_lapsed_plan_holder_who_joined_still_counts_as_a_member(): void {
		// Owner decision: a member stays on the roster after the plan lapses; the gate narrows content, not membership.
		$space = $this->space( 'open', 'tier:gold' );
		$this->join( $space, $this->member );
		$gate = static fn() => false;
		add_filter( 'buddynext_can_view_space_content', $gate );

		$this->assertTrue( $this->is_member( $this->member, array( 'id' => 16, 'space_id' => $space ) ) );
		remove_filter( 'buddynext_can_view_space_content', $gate );
	}

	public function test_a_space_that_no_longer_exists_closes_the_event(): void {
		$this->assertFalse( $this->is_member( $this->member, array( 'id' => 17, 'space_id' => 987654321 ) ) );
	}

	public function test_the_list_question_without_an_event_is_answered_no(): void {
		// Eventonomy asks this with no event to decide whether to list members-only events site-wide.
		// Which Space? There is none to ask, so nothing is listed; the Space's own tab lists them.
		$this->assertFalse( (bool) apply_filters( 'evnm_user_is_event_member', true, $this->member, array() ) );
	}

	public function test_the_answer_is_remembered_for_the_request_and_cleared_when_the_event_changes(): void {
		$space = $this->space( 'private' );
		$event = array( 'id' => 18, 'space_id' => $space );
		$this->assertFalse( $this->is_member( $this->outsider, $event ) );

		$this->join( $space, $this->outsider );
		$this->assertFalse( $this->is_member( $this->outsider, $event ), 'Same request: the first answer stands.' );

		do_action( 'evnm_after_update_event', $event, array( 'space_id' => $space ), array() );
		$this->assertTrue( $this->is_member( $this->outsider, $event ), 'The event changed, so it is asked again.' );
	}
}
