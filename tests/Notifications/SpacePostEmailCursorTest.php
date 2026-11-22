<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The space-post email job carries a member-id range, not a recipient list, and
 * still emails exactly the members the record stage gated in.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use WP_UnitTestCase;

class SpacePostEmailCursorTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $mailed = array();

	/** @var array<int,array<mixed>> */
	private array $email_args = array();

	private function drain(): void {
		$store = \ActionScheduler::store();
		for ( $guard = 0; $guard < 50; $guard++ ) {
			$actions = array();
			foreach ( array( 'buddynext_async_space_post_fanout', 'buddynext_async_space_post_emails' ) as $hook ) {
				$actions += as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'status'   => 'pending',
						'per_page' => 50,
					)
				);
			}
			if ( array() === $actions ) {
				return;
			}
			foreach ( $actions as $id => $action ) {
				$store->mark_complete( $id );
				if ( 'buddynext_async_space_post_emails' === $action->get_hook() ) {
					$this->email_args[] = $action->get_args();
				}
				do_action_ref_array( $action->get_hook(), array_values( $action->get_args() ) );
			}
		}
	}

	public function test_email_job_args_are_short_scalars_and_the_same_members_are_emailed(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		add_filter( 'buddynext_notification_fanout_batch', static fn() => 2 );
		add_filter(
			'pre_wp_mail',
			function ( $short, $atts ) {
				foreach ( (array) $atts['to'] as $to ) {
					$this->mailed[] = (string) $to;
				}
				return true;
			},
			10,
			2
		);

		$owner = self::factory()->user->create();
		$space = (int) buddynext_service( 'spaces' )->create( $owner, array( 'name' => 'Mail ' . wp_rand(), 'slug' => 'mail-' . wp_rand( 10000, 99999 ), 'type' => 'open' ) );
		$prefs = buddynext_service( 'notification_prefs' );
		$m     = self::factory()->user->create_many( 6 );
		foreach ( $m as $member ) {
			( new \BuddyNext\Spaces\SpaceMemberService() )->join( $space, $member );
		}
		foreach ( array( $m[0], $m[1], $m[2], $m[3], $m[4] ) as $member ) {
			$prefs->set_pref( $member, 'bn.space_new_post', array( 'email_freq' => 'immediate' ) );
		}
		// m[2] blocked the author; m[3] set the space to mentions only; m[4] turned the bell off but keeps email; m[5] keeps the default (email off).
		buddynext_service( 'blocks' )->block( $m[2], $owner );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'bn_space_members', array( 'notification_pref' => 'mentions_only' ), array( 'space_id' => $space, 'user_id' => $m[3] ) );
		$prefs->set_pref( $m[4], 'bn.space_new_post', array( 'on_site' => false, 'email_freq' => 'immediate' ) );

		$this->drain();
		$this->mailed     = array();
		$this->email_args = array();
		buddynext_service( 'post_service' )->create( $owner, array( 'type' => 'text', 'content' => 'hello space', 'space_id' => $space, 'privacy' => 'public' ) );
		$this->drain();

		$this->assertNotEmpty( $this->email_args, 'At least one email job ran.' );
		foreach ( $this->email_args as $args ) {
			foreach ( $args as $arg ) {
				$this->assertIsScalar( $arg, 'Email job args are ids and short scalars, never a recipient list.' );
			}
			$this->assertLessThan( 191, strlen( (string) wp_json_encode( $args ) ), 'Args fit Action Scheduler\'s indexed column.' );
		}

		$expected = array_map( static fn( $id ) => get_userdata( $id )->user_email, array( $m[0], $m[1], $m[4] ) );
		sort( $expected );
		$got = $this->mailed;
		sort( $got );
		$this->assertSame( $expected, $got, 'Exactly the gated members get the email, once each.' );
	}
}
