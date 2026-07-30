<?php
/**
 * Sorting the admin members list by Last Active.
 *
 * Presence lives in bn_presence, which WP_User_Query cannot order by, so the
 * sort is served by a one-shot pre_user_query join. Three things have to hold
 * and none of them are obvious from reading the query:
 *
 *   1. Members with NO presence row still appear. A member who has never been
 *      seen has no row at all, so an INNER join would silently drop most of the
 *      directory - on the dev site, 201 of 214 members.
 *   2. The ordering expression matches the member directory's
 *      (MemberDirectoryService, sort=most_active) exactly, or the same member
 *      ranks differently in two places the owner reads side by side.
 *   3. The join does not leak. It is added immediately before the query and
 *      removed immediately after, so an unrelated WP_User_Query later in the
 *      same request is untouched.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Admin\Members::list_members
 */
class MembersLastActiveSortTest extends \WP_UnitTestCase {

	/**
	 * Seen most recently.
	 *
	 * @var int
	 */
	private $recent = 0;

	/**
	 * Seen a while ago.
	 *
	 * @var int
	 */
	private $stale = 0;

	/**
	 * Never seen - no bn_presence row at all.
	 *
	 * @var int
	 */
	private $never = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wpdb;

		$this->recent = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stale  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->never  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$now = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_presence',
			array(
				'user_id'     => $this->recent,
				'last_active' => $now - MINUTE_IN_SECONDS,
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'bn_presence',
			array(
				'user_id'     => $this->stale,
				'last_active' => $now - ( 30 * DAY_IN_SECONDS ),
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Ordered member ids for a given direction.
	 *
	 * @param string $order ASC or DESC.
	 * @return int[]
	 */
	private function sorted_ids( string $order ): array {
		$data = ( new Members() )->list_members(
			array(
				'per_page' => 100,
				'orderby'  => 'last_active',
				'order'    => $order,
			)
		);

		return array_map( 'intval', wp_list_pluck( $data['members'], 'id' ) );
	}

	public function test_most_recently_active_ranks_first(): void {
		$ids = $this->sorted_ids( 'DESC' );

		$this->assertLessThan(
			array_search( $this->stale, $ids, true ),
			array_search( $this->recent, $ids, true ),
			'A member seen a minute ago ranked below one seen 30 days ago.'
		);
	}

	/**
	 * The reason the join must be LEFT, stated as a test.
	 *
	 * @return void
	 */
	public function test_a_member_with_no_presence_row_still_appears(): void {
		$ids = $this->sorted_ids( 'DESC' );

		$this->assertContains(
			$this->never,
			$ids,
			'A never-seen member vanished from the list - the join is INNER, and most of the directory is now invisible when sorting by Last Active.'
		);
	}

	/**
	 * Never-seen members COALESCE to 0, so descending puts them last...
	 *
	 * @return void
	 */
	public function test_never_seen_members_rank_last_when_descending(): void {
		$ids = $this->sorted_ids( 'DESC' );

		$this->assertGreaterThan(
			array_search( $this->stale, $ids, true ),
			array_search( $this->never, $ids, true ),
			'A member who has never been seen outranked one seen 30 days ago.'
		);
	}

	/**
	 * ...and ascending surfaces them first, which is the dormant-account view
	 * the direction toggle exists for.
	 *
	 * @return void
	 */
	public function test_ascending_surfaces_dormant_accounts_first(): void {
		$ids = $this->sorted_ids( 'ASC' );

		$this->assertLessThan(
			array_search( $this->recent, $ids, true ),
			array_search( $this->never, $ids, true ),
			'Ascending did not put never-seen members before recently active ones.'
		);
	}

	/**
	 * The join is one-shot. If it leaked, every later WP_User_Query in the
	 * request would carry a bn_presence join and an ORDER BY it never asked
	 * for - and the failure would surface far away from this code.
	 *
	 * @return void
	 */
	public function test_the_presence_join_does_not_leak_into_later_queries(): void {
		$this->sorted_ids( 'DESC' );

		$probe = new \WP_User_Query(
			array(
				'number'  => 10,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		$this->assertStringNotContainsString(
			'bn_presence',
			$probe->query_from,
			'The pre_user_query hook outlived its query and is joining bn_presence into unrelated user queries.'
		);
	}

	/**
	 * An unknown orderby must not reach the query layer as-is.
	 *
	 * @return void
	 */
	public function test_default_sort_is_unaffected(): void {
		$data = ( new Members() )->list_members( array( 'per_page' => 100 ) );

		$this->assertNotEmpty( $data['members'] );
		$this->assertSame( (int) $data['total'], count( $data['members'] ) );
	}
}
