<?php
/**
 * "Online Now" honours blocks in BOTH directions.
 *
 * Regression cover for a presence leak: a member who blocked you could still be
 * seen as online in your sidebar. The widget filtered rows with
 * BlockService::is_restricted(), which is not a block check at all — it reads
 * bn_blocks WHERE blocker_id = viewer AND blocked_id = target AND type =
 * 'restrict'. One direction, and a different type. It could answer "did the
 * VIEWER restrict this person" and never "did this person block the viewer",
 * which is the whole point of a block.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;

/**
 * Presence visibility vs the block list.
 *
 * @covers \BuddyNext\Profile\MemberDirectoryService::online_now
 */
class OnlineNowBlockTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var MemberDirectoryService
	 */
	private MemberDirectoryService $directory;

	/**
	 * Create the schema and the service under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->directory = new MemberDirectoryService();
	}

	/**
	 * Mark a member as active right now so they qualify for the widget.
	 *
	 * @param int $user_id Member.
	 * @return void
	 */
	private function mark_online( int $user_id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_presence (user_id, last_active) VALUES (%d, %d)
				 ON DUPLICATE KEY UPDATE last_active = VALUES(last_active)",
				$user_id,
				time()
			)
		);
	}

	/**
	 * IDs the widget returns for a viewer.
	 *
	 * @param int $viewer_id Viewer, 0 for logged out.
	 * @return int[]
	 */
	private function online_ids( int $viewer_id ): array {
		wp_cache_flush();
		$out = array();

		foreach ( (array) $this->directory->online_now( $viewer_id, 20 ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = (int) ( $row['id'] ?? $row['ID'] ?? 0 );
			} else {
				$out[] = (int) ( $row->ID ?? $row->id ?? 0 );
			}
		}

		return $out;
	}

	/**
	 * The person who blocked you is not shown as online to you.
	 *
	 * This is the reported direction and the one the old check could never catch.
	 *
	 * @return void
	 */
	public function test_a_member_who_blocked_the_viewer_is_hidden(): void {
		$blocker = self::factory()->user->create();
		$viewer  = self::factory()->user->create();
		$this->mark_online( $blocker );
		$this->mark_online( $viewer );

		buddynext_service( 'blocks' )->block( $blocker, $viewer );

		$this->assertNotContains(
			$blocker,
			$this->online_ids( $viewer ),
			'A member who blocked the viewer must not appear in their Online Now.'
		);
	}

	/**
	 * Someone you blocked is not shown to you either.
	 *
	 * @return void
	 */
	public function test_a_member_the_viewer_blocked_is_hidden(): void {
		$viewer  = self::factory()->user->create();
		$blocked = self::factory()->user->create();
		$this->mark_online( $viewer );
		$this->mark_online( $blocked );

		buddynext_service( 'blocks' )->block( $viewer, $blocked );

		$this->assertNotContains( $blocked, $this->online_ids( $viewer ) );
	}

	/**
	 * An unrelated member still sees both parties.
	 *
	 * Mutation guard: a filter widened until it hides everyone would satisfy both
	 * assertions above and fail here.
	 *
	 * @return void
	 */
	public function test_an_unrelated_viewer_still_sees_both_parties(): void {
		$blocker    = self::factory()->user->create();
		$blocked    = self::factory()->user->create();
		$bystander  = self::factory()->user->create();
		$this->mark_online( $blocker );
		$this->mark_online( $blocked );
		$this->mark_online( $bystander );

		buddynext_service( 'blocks' )->block( $blocker, $blocked );

		$seen = $this->online_ids( $bystander );
		$this->assertContains( $blocker, $seen );
		$this->assertContains( $blocked, $seen );
	}

	/**
	 * The presence DOT honours blocks in both directions too.
	 *
	 * Same defect, second surface: is_user_online() applied only the
	 * one-directional restrict gate, so a member who blocked you kept showing you
	 * their live online dot on directory cards, profile headers and avatars — and
	 * you kept showing yours to them. Fixed at the shared choke point rather than
	 * per call site.
	 *
	 * @return void
	 */
	public function test_presence_dot_is_hidden_between_blocked_members(): void {
		global $wpdb;

		$blocker = self::factory()->user->create();
		$viewer  = self::factory()->user->create();
		$other   = self::factory()->user->create();
		foreach ( array( $blocker, $viewer, $other ) as $uid ) {
			$this->mark_online( $uid );
		}
		unset( $wpdb );

		$blocks = buddynext_service( 'blocks' );
		$blocks->block( $blocker, $viewer );
		wp_cache_flush();

		$this->assertFalse( $blocks->is_user_online( $viewer, $blocker ), 'The blocker must not show a dot to the blocked member.' );
		$this->assertFalse( $blocks->is_user_online( $blocker, $viewer ), 'And not the other way round either.' );

		// Not over-filtered, and self always resolves.
		$this->assertTrue( $blocks->is_user_online( $other, $blocker ) );
		$this->assertTrue( $blocks->is_user_online( 0, $blocker ) );
		$this->assertTrue( $blocks->is_user_online( $blocker, $blocker ) );
	}

	/**
	 * A logged-out viewer still gets the widget.
	 *
	 * block_exclude_sql() returns an empty fragment for viewer 0; this pins that
	 * the empty fragment is handled and does not blank the query.
	 *
	 * @return void
	 */
	public function test_logged_out_viewer_still_sees_online_members(): void {
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();
		$this->mark_online( $a );
		$this->mark_online( $b );

		$this->assertNotEmpty( $this->online_ids( 0 ) );
	}
}
