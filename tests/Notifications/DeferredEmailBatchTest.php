<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * An event that emails many members queues one job per batch, and every member is emailed once.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\EmailSender;
use WP_UnitTestCase;

class DeferredEmailBatchTest extends WP_UnitTestCase {

	public function test_sixty_emails_in_one_request_queue_three_jobs_and_reach_everyone(): void {
		$sender = buddynext_service( 'email_sender' );
		$prefs  = buddynext_service( 'notification_prefs' );
		$users  = self::factory()->user->create_many( 60 );
		foreach ( $users as $user ) {
			$prefs->set_pref( $user, 'bn.new_follower', array( 'email_freq' => 'immediate' ) );
		}
		if ( method_exists( EmailSender::class, 'flush_deferred' ) ) {
			EmailSender::flush_deferred(); // Empty what earlier tests in this process held.
		}
		as_unschedule_all_actions( 'buddynext_send_notification_email' );
		as_unschedule_all_actions( 'buddynext_send_notification_emails' );

		foreach ( $users as $user ) {
			$sender->send( $user, 'bn.new_follower', array( 'actor_id' => 1 ) );
		}
		if ( method_exists( EmailSender::class, 'flush_deferred' ) ) {
			EmailSender::flush_deferred();
		}

		$jobs = as_get_scheduled_actions( array( 'hook' => 'buddynext_send_notification_emails', 'status' => 'pending', 'per_page' => 100 ) );
		$this->assertSame( 0, count( as_get_scheduled_actions( array( 'hook' => 'buddynext_send_notification_email', 'status' => 'pending' ), 'ids' ) ), 'No per-recipient jobs.' );
		$this->assertCount( 3, $jobs, '60 emails, batches of 25: three jobs instead of sixty.' );

		$mailed = array();
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) use ( &$mailed ) {
				$mailed[] = (string) ( is_array( $atts['to'] ) ? $atts['to'][0] : $atts['to'] );
				return true;
			},
			10,
			2
		);
		foreach ( $jobs as $job ) {
			do_action_ref_array( $job->get_hook(), array_values( $job->get_args() ) );
		}
		$expected = array_map( static fn( $id ) => get_userdata( $id )->user_email, $users );
		sort( $expected );
		sort( $mailed );
		$this->assertSame( $expected, $mailed, 'Every member gets the email, once.' );
	}
}
