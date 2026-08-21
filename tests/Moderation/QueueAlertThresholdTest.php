<?php
/**
 * Turning the queue alert off must turn it off.
 *
 * The field is an `optional_limit`: unticking "Email admins when the queue builds
 * up" stores 0, and 0 conventionally means "no limit". That reading is correct for
 * a cap and exactly backwards for a threshold to EXCEED.
 *
 * The guard was `if ( $count < $threshold ) return;`. With the toggle off that is
 * `$count < 0`, which a count never is - so the daily alert fired every single day
 * regardless of queue size. The off switch turned it on, and kept mailing every
 * admin daily until someone worked out why.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use WP_UnitTestCase;

/**
 * The moderation queue alert threshold.
 *
 * @covers \BuddyNext\Moderation\ModerationListener::on_daily_queue_check
 */
class QueueAlertThresholdTest extends WP_UnitTestCase {

	/**
	 * Mails captured instead of sent.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $mails = array();

	/**
	 * Intercept wp_mail and start from a clean queue.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->mails = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_reports" );

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Stop intercepting.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		delete_option( 'buddynext_mod_queue_alert_threshold' );

		parent::tear_down();
	}

	/**
	 * Record a mail and stop it leaving.
	 *
	 * @param null|bool            $short_circuit Short-circuit value.
	 * @param array<string,mixed>  $atts          wp_mail arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ): bool {
		unset( $short_circuit );

		$this->mails[] = (array) $atts;

		return true;
	}

	/**
	 * Put reports in the queue.
	 *
	 * @param int $count How many.
	 * @return void
	 */
	private function queue_reports( int $count ): void {
		global $wpdb;

		for ( $i = 0; $i < $count; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_reports',
				array(
					'reporter_id' => 1,
					'object_type' => 'post',
					'object_id'   => 1000 + $i,
					'status'      => 'pending',
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Run the daily check.
	 *
	 * @return void
	 */
	private function run_check(): void {
		( new \BuddyNext\Moderation\ModerationListener() )->on_daily_queue_check();
	}

	/**
	 * THE bug: the toggle off must send nothing, however full the queue.
	 *
	 * @return void
	 */
	public function test_a_threshold_of_zero_sends_nothing(): void {
		update_option( 'buddynext_mod_queue_alert_threshold', 0 );
		$this->queue_reports( 50 );

		$this->run_check();

		$this->assertCount(
			0,
			$this->mails,
			'The admin turned the alert OFF and got one anyway - every day, whatever the queue size.'
		);
	}

	/**
	 * A queue under the threshold is still silent.
	 *
	 * The control, so the test above cannot pass merely because nothing ever sends.
	 *
	 * @return void
	 */
	public function test_a_queue_below_the_threshold_is_silent(): void {
		update_option( 'buddynext_mod_queue_alert_threshold', 20 );
		$this->queue_reports( 5 );

		$this->run_check();

		$this->assertCount( 0, $this->mails );
	}

	/**
	 * And a queue at or over the threshold DOES alert.
	 *
	 * The other control. A fix that simply stopped sending would pass both tests
	 * above and break the feature.
	 *
	 * @return void
	 */
	public function test_a_queue_at_the_threshold_alerts(): void {
		update_option( 'buddynext_mod_queue_alert_threshold', 3 );
		$this->queue_reports( 3 );

		$this->run_check();

		$this->assertCount( 1, $this->mails, 'The queue reached the threshold and no alert was sent.' );
	}
}
