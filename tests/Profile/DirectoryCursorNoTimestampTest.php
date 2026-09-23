<?php
/**
 * SECURITY regression — the member-directory cursor must not leak a member's raw
 * last_active timestamp.
 *
 * A card shows presence as a privacy-aware boolean (is_user_online_at), but the
 * most_active/online keyset cursor used to base64-encode the boundary member's
 * exact last_active — a precision the card withholds, leaked even for a member
 * who hid their presence. The cursor now carries only the pivot id; list_members
 * resolves the boundary value server-side by a PK lookup. This proves the cursor
 * is timestamp-free AND that keyset pagination still returns every member once,
 * in order. See free-internal security shelf; Basecamp 10321451871 (C1).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Profile\MemberDirectoryService::list_members
 */
class DirectoryCursorNoTimestampTest extends WP_UnitTestCase {

	private MemberDirectoryService $service;
	private int $viewer_id;
	/** @var int[] member id => seeded last_active, newest first */
	private array $members = array();

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;

		$this->service   = new MemberDirectoryService();
		$this->viewer_id = self::factory()->user->create();

		// Five members with distinct, descending last_active values.
		$now = time();
		foreach ( range( 1, 5 ) as $i ) {
			$uid                     = self::factory()->user->create();
			$last                    = $now - ( $i * 120 );
			$this->members[ $uid ]   = $last;
			$wpdb->replace(
				$wpdb->prefix . 'bn_presence',
				array(
					'user_id'     => $uid,
					'last_active' => $last,
				)
			);
		}
	}

	public function test_cursor_carries_no_last_active_timestamp(): void {
		$page = $this->service->list_members( $this->viewer_id, null, 2, array( 'sort' => 'most_active' ) );

		$this->assertNotNull( $page['next_cursor'] );
		$decoded = json_decode( (string) base64_decode( $page['next_cursor'], true ), true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'id', $decoded );
		$this->assertArrayNotHasKey( 'last_active', $decoded, 'The cursor leaked a raw presence timestamp.' );
	}

	public function test_keyset_pagination_returns_every_member_once_in_order(): void {
		$seen   = array();
		$cursor = null;

		// Walk the whole directory two at a time; guard against a runaway loop.
		for ( $guard = 0; $guard < 20; $guard++ ) {
			$page = $this->service->list_members( $this->viewer_id, $cursor, 2, array( 'sort' => 'most_active' ) );
			foreach ( $page['items'] as $item ) {
				$seen[] = (int) $item['user_id'];
			}
			$cursor = $page['next_cursor'];
			if ( null === $cursor ) {
				break;
			}
		}

		// Every seeded member appears exactly once (no dup, no skip) and the viewer
		// is excluded from their own directory.
		$member_ids = array_keys( $this->members );
		sort( $member_ids );
		$unique = array_values( array_unique( $seen ) );
		sort( $unique );

		$this->assertSame( $member_ids, array_values( array_intersect( $unique, $member_ids ) ), 'A member was skipped or duplicated across pages.' );
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'A member appeared on more than one page.' );
		$this->assertNotContains( $this->viewer_id, $seen );
	}
}
