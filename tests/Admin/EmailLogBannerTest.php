<?php
/**
 * Tests for the failed-email admin banner.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\EmailLog;

/**
 * @covers \BuddyNext\Admin\EmailLog
 */
class EmailLogBannerTest extends \WP_UnitTestCase {

	/**
	 * Insert a log row.
	 *
	 * @param string $status  sent|failed.
	 * @param int    $minutes Minutes ago.
	 */
	private function log( string $status, int $minutes ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_email_log',
			array(
				'user_id' => 1,
				'type'    => 'bn.comment_reply',
				'status'  => $status,
				'sent_at' => gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ),
			)
		);
	}

	/**
	 * Whether the banner's recovery check sees a good send after the failures.
	 *
	 * Called directly: the test suite's temporary tables are invisible to the
	 * banner's SHOW TABLES guard, so the rendered banner cannot be asserted here.
	 *
	 * @return bool
	 */
	private function recovered(): bool {
		$method = new \ReflectionMethod( EmailLog::class, 'mail_recovered' );
		return (bool) $method->invoke( new EmailLog() );
	}

	public function test_not_recovered_while_the_latest_send_is_a_failure(): void {
		$this->log( 'sent', 90 );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->log( 'failed', 30 + $i );
		}
		$this->assertFalse( $this->recovered() );
	}

	public function test_recovered_once_a_send_succeeds_after_the_failures(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->log( 'failed', 30 + $i );
		}
		$this->log( 'sent', 1 );
		$this->assertTrue( $this->recovered() );
	}
}
