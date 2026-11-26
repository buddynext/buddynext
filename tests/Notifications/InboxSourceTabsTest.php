<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Integration notifications get their own inbox tabs, and a grouped row is not
 * counted twice.
 *
 * Jobs (WP Career Board) and Achievements (WB Gamification) rows landed only
 * under All: the tab set was a fixed type list and their types are
 * `{source}.{slug}`, named by the plugin. And a grouped row said "and 31
 * others" next to a "x32" badge.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\NotificationMessageService;
use BuddyNext\Notifications\NotificationService;
use WP_UnitTestCase;

/**
 * Source-prefix inbox tabs.
 *
 * @covers \BuddyNext\Notifications\NotificationService::tab_of_type
 * @covers \BuddyNext\Notifications\NotificationService::inbox_filters
 * @covers \BuddyNext\Notifications\NotificationMessageService::compose
 */
class InboxSourceTabsTest extends WP_UnitTestCase {

	/**
	 * Fixed types keep their tab; contract types match on their source prefix.
	 *
	 * @return void
	 */
	public function test_type_resolves_to_its_tab(): void {
		$this->assertSame( 'follow', NotificationService::tab_of_type( 'bn.follow_requested' ) );
		$this->assertSame( 'jobs', NotificationService::tab_of_type( 'career_board.job_approved' ) );
		$this->assertSame( 'achievements', NotificationService::tab_of_type( 'wb_gamification.badge_awarded' ) );
		$this->assertSame( '', NotificationService::tab_of_type( 'jetonomy.reply' ) );
		// A prefix must end at the dot: a lookalike source is not Jobs.
		$this->assertSame( '', NotificationService::tab_of_type( 'career_boardx.job' ) );
	}

	/**
	 * A tab is offered only while its plugin has adopted the contract.
	 *
	 * @return void
	 */
	public function test_tab_offered_only_when_source_adopted(): void {
		remove_all_filters( 'wb_gam_community_notification_types' );
		$this->assertNotContains( 'achievements', NotificationService::inbox_filters() );

		add_filter( 'wb_gam_community_notification_types', '__return_empty_array' );
		$this->assertContains( 'achievements', NotificationService::inbox_filters() );
	}

	/**
	 * A sentence that already counts the others drops the "xN" badge.
	 *
	 * @return void
	 */
	public function test_grouped_sentence_marks_others_named(): void {
		$actor = self::factory()->user->create();
		$row   = array(
			'type'         => 'bn.new_follower',
			'recipient_id' => self::factory()->user->create(),
			'sender_id'    => $actor,
			'object_id'    => 0,
			'data'         => array(),
		);
		$svc   = new NotificationMessageService();

		$this->assertFalse( $svc->compose( $row )['others_named'] );
		$this->assertTrue( $svc->compose( $row + array( 'group_count' => 4 ) )['others_named'] );
	}
}
