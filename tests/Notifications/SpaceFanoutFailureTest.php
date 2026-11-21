<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * A page of the Space-post fan-out that fails must not stop the pages after it.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\NotificationListener;
use WP_UnitTestCase;

class SpaceFanoutFailureTest extends WP_UnitTestCase {

	private function pages(): array {
		$cursors = array();
		foreach ( as_get_scheduled_actions( array( 'hook' => 'buddynext_async_space_post_fanout', 'status' => 'pending' ) ) as $action ) {
			$args      = (array) ( $action->get_args()[0] ?? array() );
			$cursors[] = (int) ( $args['after_user_id'] ?? 0 );
		}
		sort( $cursors );
		return $cursors;
	}

	public function test_the_next_page_is_queued_before_a_page_runs_so_one_failure_does_not_end_the_chain(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		as_unschedule_all_actions( 'buddynext_async_space_post_fanout' );
		add_filter( 'buddynext_notification_fanout_batch', static fn() => 2 );

		$owner = self::factory()->user->create();
		$space = (int) buddynext_service( 'spaces' )->create( $owner, array( 'name' => 'Fanout ' . wp_rand(), 'slug' => 'fanout-' . wp_rand( 10000, 99999 ), 'type' => 'open' ) );
		foreach ( self::factory()->user->create_many( 7 ) as $member ) {
			( new \BuddyNext\Spaces\SpaceMemberService() )->join( $space, $member );
		}

		$post = (int) buddynext_service( 'post_service' )->create( $owner, array( 'type' => 'text', 'content' => 'fan out', 'space_id' => $space, 'privacy' => 'public' ) );

		// The inline page (2 members) queued the page after it up front.
		$first = $this->pages();
		$this->assertCount( 1, $first, 'The first page queues the second.' );

		// Run that page with a recipient check that blows up: the page after it is already queued.
		$boom = static function (): bool {
			throw new \RuntimeException( 'a recipient filter failed' );
		};
		add_filter( 'buddynext_notification_should_send', $boom );
		$args = array( 'post_id' => $post, 'space_id' => $space, 'author_id' => $owner, 'after_user_id' => $first[0] );
		try {
			( new NotificationListener() )->async_space_post_fanout( $args );
			$this->fail( 'The failing page should surface its error so Action Scheduler marks it failed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'a recipient filter failed', $e->getMessage() );
		}
		remove_filter( 'buddynext_notification_should_send', $boom );

		$after = $this->pages();
		$this->assertGreaterThan( 1, count( $after ), 'The next page was queued before the failing page ran.' );
		$this->assertGreaterThan( $first[0], max( $after ), 'The chain moved past the failed page.' );
		as_unschedule_all_actions( 'buddynext_async_space_post_fanout' );
	}
}
