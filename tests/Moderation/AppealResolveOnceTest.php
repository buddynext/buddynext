<?php
/**
 * Moderation state-machine guards: an appeal resolves exactly once, and only
 * OPEN reports count toward auto-hide (dismissed/resolved ones do not re-hide
 * content that was already cleared).
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::resolve_appeal
 */
class AppealResolveOnceTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private int $member = 0;
	private int $admin  = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();
		$this->member  = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin   = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_a_resolved_appeal_cannot_be_resolved_again(): void {
		$this->service->suspend_user( $this->member, $this->admin, 'spam' );
		$appeal_id = $this->service->create_appeal( $this->member, 'Please review.' );
		$this->assertIsInt( $appeal_id );

		$first = $this->service->resolve_appeal( $appeal_id, $this->admin, 'denied', 'No.' );
		$this->assertTrue( $first );

		$second = $this->service->resolve_appeal( $appeal_id, $this->admin, 'approved', 'Changed my mind.' );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'appeal_not_pending', $second->get_error_code() );
		$this->assertSame( 409, (int) ( $second->get_error_data()['status'] ?? 0 ) );

		// The original decision is intact.
		global $wpdb;
		$status = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_appeals WHERE id = %d", $appeal_id )
		);
		$this->assertSame( 'denied', $status, 'The second resolve must not overwrite the decision.' );
	}

	public function test_dismissed_reports_do_not_count_toward_auto_hide(): void {
		update_option( 'buddynext_auto_hide_threshold', 3 );
		$post_id = 4242;

		global $wpdb;
		// Three DISMISSED lifetime reports on the same post.
		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_reports',
				array(
					'reporter_id' => (int) self::factory()->user->create(),
					'object_type' => 'post',
					'object_id'   => $post_id,
					'reason'      => 'spam',
					'status'      => 'dismissed',
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}

		// A fresh, single pending report should NOT trip the threshold of 3, because
		// the three dismissed ones no longer count.
		$this->service->report( (int) self::factory()->user->create(), 'post', $post_id, 'spam' );

		$open = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE object_type = 'post' AND object_id = %d AND status IN ( 'pending', 'escalated' )",
				$post_id
			)
		);
		$this->assertSame( 1, $open, 'Only the one open report should count toward auto-hide.' );
	}
}
