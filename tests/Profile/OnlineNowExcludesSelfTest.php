<?php
/**
 * "Online Now" is a discovery widget, so it never lists the viewer.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use WP_UnitTestCase;

/**
 * On a quiet community the viewer was the ENTIRE widget: one row, the person
 * reading it, under the heading "Online Now (1)".
 */
class OnlineNowExcludesSelfTest extends WP_UnitTestCase {

	/**
	 * Mark a member as active right now.
	 *
	 * @param int $user_id Member.
	 * @return void
	 */
	private function mark_online( int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$wpdb->prefix . 'bn_presence',
			array(
				'user_id'     => $user_id,
				'last_active' => time(),
			)
		);
	}

	/**
	 * Ids returned by the widget.
	 *
	 * @param int $viewer_id Viewer.
	 * @return int[]
	 */
	private function ids( int $viewer_id ): array {
		return array_map(
			static fn( array $row ): int => (int) $row['ID'],
			buddynext_service( 'member_directory' )->online_now( $viewer_id, 6 )
		);
	}

	/**
	 * The viewer is not their own discovery result.
	 *
	 * @return void
	 */
	public function test_the_viewer_is_excluded(): void {
		$viewer = (int) self::factory()->user->create();
		$other  = (int) self::factory()->user->create();
		$this->mark_online( $viewer );
		$this->mark_online( $other );

		$ids = $this->ids( $viewer );

		$this->assertNotContains( $viewer, $ids );
		$this->assertContains( $other, $ids );
	}

	/**
	 * Excluding self must not cost the widget a real member. The exclusion is in
	 * SQL, before the over-fetch is trimmed to the limit, for exactly this
	 * reason — dropping the row afterwards would silently short-fill.
	 *
	 * @return void
	 */
	public function test_excluding_self_does_not_shorten_the_list(): void {
		$viewer = (int) self::factory()->user->create();
		$this->mark_online( $viewer );

		$others = array();
		foreach ( range( 1, 6 ) as $ignored ) {
			$id = (int) self::factory()->user->create();
			$this->mark_online( $id );
			$others[] = $id;
		}

		$ids = $this->ids( $viewer );

		$this->assertCount( 6, $ids, 'The widget should still return a full page of other members.' );
		$this->assertNotContains( $viewer, $ids );
	}

	/**
	 * A logged-out visitor has no self to exclude, so nobody is dropped.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_excludes_nobody(): void {
		$a = (int) self::factory()->user->create();
		$this->mark_online( $a );

		$this->assertContains( $a, $this->ids( 0 ) );
	}
}
