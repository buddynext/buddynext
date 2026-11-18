<?php
/**
 * The one-time media privacy repair is recorded as done only when it finished.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\MediaPrivacyRepairCommand;
use BuddyNext\Bridges\WPMediaVerseBridge;
use BuddyNext\Media\MediaClient;

/**
 * Queue state of the privacy repair.
 */
class MediaPrivacyRepairQueueTest extends \WP_UnitTestCase {

	private const OPTION = 'buddynext_media_privacy_repair_queued';

	public function tear_down(): void {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( MediaPrivacyRepairCommand::HOOK );
		parent::tear_down();
	}

	public function test_a_finished_repair_is_never_queued_again(): void {
		update_option( self::OPTION, 'done' );

		WPMediaVerseBridge::maybe_queue_privacy_repair();

		$this->assertSame( 'done', get_option( self::OPTION ) );
		$this->assertFalse( wp_next_scheduled( MediaPrivacyRepairCommand::HOOK ) );
	}

	public function test_a_run_queued_a_moment_ago_is_given_time_to_finish(): void {
		$recent = time() - 100;
		update_option( self::OPTION, $recent );

		WPMediaVerseBridge::maybe_queue_privacy_repair();

		$this->assertSame( $recent, (int) get_option( self::OPTION ), 'no second job while the first may still run' );
	}

	public function test_a_run_that_did_not_finish_is_not_recorded_as_done(): void {
		if ( MediaClient::available() ) {
			$this->markTestSkipped( 'Needs MediaVerse absent, where the repair cannot run.' );
		}
		update_option( self::OPTION, time() - 100 );

		WPMediaVerseBridge::run_privacy_repair();

		$this->assertNotSame( 'done', get_option( self::OPTION ), 'a run that could not complete must stay open to a retry' );
	}
}
