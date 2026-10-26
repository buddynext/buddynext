<?php
/**
 * Safeguard holds are filed under a reason that tells a moderator what they are.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Moderation\SafeguardService;

/**
 * @covers \BuddyNext\Moderation\SafeguardService::report_reason
 * @covers \BuddyNext\Moderation\ModerationService::auto_flag
 */
class SafeguardReportReasonTest extends \WP_UnitTestCase {

	public function test_a_duplicate_post_is_reported_as_spam_not_inappropriate(): void {
		global $wpdb;
		update_option( 'buddynext_duplicate_post_window', 5 );
		$author = self::factory()->user->create();
		$posts  = buddynext_service( 'post_service' );

		$posts->create( $author, array( 'content' => 'same words twice', 'privacy' => 'public', 'type' => 'text' ) );
		$second = $posts->create( $author, array( 'content' => 'same words twice', 'privacy' => 'public', 'type' => 'text' ) );
		$id     = is_array( $second ) ? (int) $second['id'] : (int) $second;
		$this->assertGreaterThan( 0, $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reason = $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$wpdb->prefix}bn_reports WHERE object_type = 'post' AND object_id = %d AND reporter_id = 0", $id ) );
		$this->assertSame( 'spam', $reason );
	}

	public function test_a_rule_flag_keeps_the_default_reason(): void {
		$this->assertSame( 'inappropriate', SafeguardService::report_reason( new \WP_Error( 'bnpro_keyword_flagged', 'x', array( 'status' => 202 ) ) ) );
		$this->assertSame( 'inappropriate', SafeguardService::report_reason( true ) );
	}
}
