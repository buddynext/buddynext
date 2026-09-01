<?php
/**
 * The self-service appeal path (POST /moderation/me/appeals -> create_appeal)
 * must allow only one open appeal per suspension. Without the guard a member
 * could file the same appeal repeatedly, flooding the queue and notifying every
 * admin per insert.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::create_appeal
 */
class CreateAppealDedupeTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private int $member = 0;
	private int $admin  = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();
		$this->member  = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin   = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->service->suspend_user( $this->member, $this->admin, 'spam' );
	}

	private function pending_appeals(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_appeals WHERE user_id = %d AND status = 'pending'", $this->member )
		);
	}

	public function test_second_appeal_is_rejected_and_not_stored(): void {
		$first = $this->service->create_appeal( $this->member, 'Please review my case.' );
		$this->assertIsInt( $first );
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 1, $this->pending_appeals() );

		$second = $this->service->create_appeal( $this->member, 'Filing again.' );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'appeal_already_pending', $second->get_error_code() );
		$this->assertSame( 409, (int) ( $second->get_error_data()['status'] ?? 0 ) );

		// Still exactly one appeal on record.
		$this->assertSame( 1, $this->pending_appeals(), 'A duplicate appeal must not be stored.' );
	}
}
