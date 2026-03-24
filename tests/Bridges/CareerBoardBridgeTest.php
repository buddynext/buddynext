<?php
/**
 * Tests for Career Board bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\CareerBoardBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\CareerBoardBridge
 */
class CareerBoardBridgeTest extends \WP_UnitTestCase {

	private CareerBoardBridge $bridge;
	private int $employer_id;
	private int $candidate_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		// Function and class stubs are registered in tests/bootstrap.php.
		$this->bridge       = new CareerBoardBridge();
		$this->bridge->init();
		$this->employer_id  = self::factory()->user->create();
		$this->candidate_id = self::factory()->user->create();
	}

	public function test_application_submitted_notifies_employer(): void {
		global $wpdb;

		// wcb_application_submitted( $app_id, $job_id, $candidate_id, $employer_id ).
		do_action( 'wcb_application_submitted', 1, 10, $this->candidate_id, $this->employer_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'cb.application_received'",
				$this->employer_id
			)
		);

		$this->assertGreaterThan( 0, $count );
	}

	public function test_application_status_changed_notifies_candidate(): void {
		global $wpdb;

		// wcb_application_status_changed( $app_id, $old_status, $new_status, $candidate_id ).
		do_action( 'wcb_application_status_changed', 1, 'pending', 'shortlisted', $this->candidate_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'cb.application_status'",
				$this->candidate_id
			)
		);

		$this->assertGreaterThan( 0, $count );
	}

	public function test_job_created_indexes_in_search(): void {
		global $wpdb;

		// wcb_job_created( $job_id, $employer_id, $title, $description ).
		do_action( 'wcb_job_created', 55, $this->employer_id, 'Senior PHP Developer', 'Job description.' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'job' AND object_id = %d",
				55
			)
		);

		$this->assertSame( 1, $count );
	}

	public function test_application_withdrawn_notifies_employer(): void {
		global $wpdb;

		// wcb_application_withdrawn( $app_id, $job_id, $candidate_id, $employer_id ).
		do_action( 'wcb_application_withdrawn', 2, 10, $this->candidate_id, $this->employer_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'cb.application_withdrawn'",
				$this->employer_id
			)
		);

		$this->assertGreaterThan( 0, $count );
	}
}
