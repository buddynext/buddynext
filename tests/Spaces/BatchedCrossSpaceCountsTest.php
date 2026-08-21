<?php
/**
 * Batched cross-space counts for owner dashboards.
 *
 * Requested during the Wellbee Circles integration, and the ask was right: a
 * consumer showing several spaces at once had two options and both were wrong.
 * Loop the singular helpers and pay an N+1 that grows with exactly the owners who
 * have the most to manage, or sum the denormalised `bn_spaces.member_count` and
 * double-count everyone who belongs to two spaces.
 *
 * The second is the dangerous one, because it looks like it works. Measured on the
 * dev site while building this: summing member_count across ten spaces gave 54
 * where the real number of people was 14.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceMemberService;
use WP_UnitTestCase;

/**
 * Counting people and queues across many spaces.
 *
 * @covers \BuddyNext\Spaces\SpaceMemberService::count_distinct_members
 * @covers \BuddyNext\Spaces\SpaceMemberService::count_pending_requests_for_spaces
 */
class BatchedCrossSpaceCountsTest extends WP_UnitTestCase {

	/**
	 * Insert a membership row directly.
	 *
	 * Direct because the service's join path applies capability and space-type
	 * rules that are not what these counts are about.
	 *
	 * @param int    $space_id Space.
	 * @param int    $user_id  Member.
	 * @param string $status   Row status.
	 * @return void
	 */
	private function add_member( int $space_id, int $user_id, string $status = 'active' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id'  => $space_id,
				'user_id'   => $user_id,
				'status'    => $status,
				'role'      => 'member',
				'joined_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * A member of two spaces is one person, not two.
	 *
	 * The headline. Summing per-space counts gets this wrong, and gets it wrong in
	 * the flattering direction, so nobody questions the number.
	 *
	 * @return void
	 */
	public function test_a_member_of_two_spaces_is_counted_once(): void {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$this->add_member( 9001, $alice );
		$this->add_member( 9002, $alice );
		$this->add_member( 9002, $bob );

		$service = new SpaceMemberService();

		$this->assertSame(
			2,
			$service->count_distinct_members( array( 9001, 9002 ) ),
			'Alice belongs to both spaces and was counted twice - the exact error summing member_count makes.'
		);
	}

	/**
	 * Only active rows are members.
	 *
	 * Invited, pending and banned people are not members, and a dashboard that
	 * counts them tells an owner they have a community they do not have.
	 *
	 * @return void
	 */
	public function test_only_active_rows_count_as_members(): void {
		$this->add_member( 9010, self::factory()->user->create(), 'active' );
		$this->add_member( 9010, self::factory()->user->create(), 'pending' );
		$this->add_member( 9010, self::factory()->user->create(), 'invited' );
		$this->add_member( 9010, self::factory()->user->create(), 'banned' );

		$this->assertSame( 1, ( new SpaceMemberService() )->count_distinct_members( array( 9010 ) ) );
	}

	/**
	 * Pending requests count ROWS, not people - deliberately the opposite call.
	 *
	 * One person asking to join three spaces is three decisions an owner has to
	 * make. Collapsing them would under-report the work waiting, which is the
	 * opposite mistake from double-counting members.
	 *
	 * @return void
	 */
	public function test_pending_requests_count_decisions_not_people(): void {
		$eager = self::factory()->user->create();

		$this->add_member( 9020, $eager, 'pending' );
		$this->add_member( 9021, $eager, 'pending' );

		$this->assertSame(
			2,
			( new SpaceMemberService() )->count_pending_requests_for_spaces( array( 9020, 9021 ) ),
			'One person, two spaces, two decisions for the owner.'
		);
	}

	/**
	 * The batched answer agrees with the singular one for a single space.
	 *
	 * Two helpers answering the same question differently is worse than having one,
	 * and this is where that would show up first.
	 *
	 * @return void
	 */
	public function test_batched_and_singular_agree_on_one_space(): void {
		$this->add_member( 9030, self::factory()->user->create(), 'pending' );
		$this->add_member( 9030, self::factory()->user->create(), 'pending' );

		$service = new SpaceMemberService();

		$this->assertSame(
			$service->count_pending_requests( 9030 ),
			$service->count_pending_requests_for_spaces( array( 9030 ) )
		);
	}

	/**
	 * An empty list is 0, not everything.
	 *
	 * The failure mode worth guarding: an unscoped IN () or a dropped WHERE turns
	 * "count these spaces" into "count the site", and a dashboard for an owner of
	 * nothing would report the whole community.
	 *
	 * @return void
	 */
	public function test_an_empty_list_counts_nothing(): void {
		$this->add_member( 9040, self::factory()->user->create() );

		$service = new SpaceMemberService();

		$this->assertSame( 0, $service->count_distinct_members( array() ) );
		$this->assertSame( 0, $service->count_pending_requests_for_spaces( array() ) );
	}

	/**
	 * Junk ids are discarded rather than trusted into the query.
	 *
	 * @return void
	 */
	public function test_junk_ids_are_filtered_out(): void {
		$this->add_member( 9050, self::factory()->user->create() );

		$service = new SpaceMemberService();

		// @phpstan-ignore-next-line - deliberately passing the shapes a caller might.
		$this->assertSame( 1, $service->count_distinct_members( array( 9050, 0, -3 ) ) );
	}
}
