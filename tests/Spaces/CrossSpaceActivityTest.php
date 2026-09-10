<?php
/**
 * CrossSpaceActivityService::recent() merges moderation actions + membership joins
 * across a set of spaces into a newest-first stream in the documented shape, and
 * lets another domain contribute rows via a filter (card 10276234812).
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\CrossSpaceActivityService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Spaces\CrossSpaceActivityService::recent
 */
class CrossSpaceActivityTest extends WP_UnitTestCase {

	/** @var CrossSpaceActivityService */
	private $activity;

	/** @var int */
	private $space = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->activity = new CrossSpaceActivityService();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array( 'name' => 'Circle Act', 'slug' => 'circle-act-' . wp_rand( 1000, 9999 ), 'owner_id' => 1, 'parent_id' => null, 'category_id' => 888, 'is_archived' => 0, 'type' => 'public' ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
		$this->space = (int) $wpdb->insert_id;

		$joiner = self::factory()->user->create( array( 'display_name' => 'Joiner Jo' ) );
		// A recent join and an older moderation action, both in the space.
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array( 'space_id' => $this->space, 'user_id' => $joiner, 'role' => 'member', 'status' => 'active', 'joined_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		$wpdb->insert(
			$wpdb->prefix . 'bn_mod_log',
			array( 'actor_id' => 0, 'action' => 'ai_remove_content', 'object_type' => 'post', 'object_id' => 5, 'space_id' => $this->space, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_cross_space_activity_rows' );
		remove_all_filters( 'buddynext_cross_space_activity_total' );
		parent::tear_down();
	}

	/**
	 * Both sources appear, newest first, in the documented shape.
	 *
	 * @return void
	 */
	public function test_merges_joins_and_moderation_newest_first(): void {
		$result = $this->activity->recent( array( 'category_id' => 888 ) );

		$this->assertSame( 2, $result['total'], 'One join + one moderation row.' );
		$this->assertCount( 2, $result['items'] );

		// Documented shape on every row.
		foreach ( $result['items'] as $row ) {
			$this->assertSame(
				array( 'id', 'icon', 'avatar', 'text', 'occurred_at_utc' ),
				array_keys( $row ),
				'Each row must carry exactly the documented keys.'
			);
		}

		// The join is newer than the moderation action.
		$this->assertSame( 'user-plus', $result['items'][0]['icon'], 'The recent join is first.' );
		$this->assertStringContainsString( 'Joiner Jo joined', $result['items'][0]['text'] );
		$this->assertSame( 'shield', $result['items'][1]['icon'], 'The older moderation action is second.' );
		$this->assertStringContainsString( 'System', $result['items'][1]['text'], 'A system (actor 0) action reads as System.' );
	}

	/**
	 * Another domain contributes rows through the filter; they merge newest-first
	 * and the total reflects them.
	 *
	 * @return void
	 */
	public function test_contribution_filter_merges_and_counts(): void {
		add_filter(
			'buddynext_cross_space_activity_rows',
			static fn( array $rows ): array => array_merge(
				$rows,
				array( array( 'id' => 'pay-1', 'icon' => 'credit-card', 'avatar' => '', 'text' => 'Sam paid for Pro', 'occurred_at_utc' => gmdate( 'Y-m-d H:i:s', time() + 10 ) ) )
			)
		);
		add_filter( 'buddynext_cross_space_activity_total', static fn( int $t ): int => $t + 1 );

		$result = $this->activity->recent( array( 'category_id' => 888 ) );

		$this->assertSame( 3, $result['total'], 'Total includes the contributed row.' );
		$this->assertSame( 'pay-1', $result['items'][0]['id'], 'The contributed payment row is newest and sorts first.' );
	}

	/**
	 * An empty space set is a well-formed empty result — no query, no error.
	 *
	 * @return void
	 */
	public function test_empty_scope_is_safe(): void {
		$this->assertSame(
			array( 'items' => array(), 'total' => 0 ),
			$this->activity->recent( array( 'space_ids' => array() ) )
		);
	}
}
