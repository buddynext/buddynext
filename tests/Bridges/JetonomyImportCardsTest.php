<?php
/**
 * After a forum import, the imported topics get their feed cards in chunks of 100, each chunk its own job, and a
 * topic that fails does not stop the rest.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\CronScheduler;

/**
 * @covers \BuddyNext\Bridges\JetonomyBridge::on_import_batch
 * @covers \BuddyNext\Bridges\JetonomyBridge::import_cards_chunk
 */
class JetonomyImportCardsTest extends \WP_UnitTestCase {

	private function pending(): array {
		return function_exists( 'as_get_scheduled_actions' )
			? as_get_scheduled_actions(
				array(
					'hook'     => JetonomyBridge::IMPORT_CARDS_HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 50,
				)
			)
			: array();
	}

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Needs Action Scheduler.' );
		}
		as_unschedule_all_actions( JetonomyBridge::IMPORT_CARDS_HOOK );
	}

	public function test_a_batch_of_topics_is_queued_in_chunks_of_100(): void {
		( new JetonomyBridge() )->on_import_batch( 'bbpress', 'topic', range( 1, 250 ) );

		$sizes = array();
		foreach ( $this->pending() as $action ) {
			$args    = $action->get_args();
			$sizes[] = count( $args[0] );
		}
		sort( $sizes );
		$this->assertSame( array( 50, 100, 100 ), $sizes );
	}

	public function test_replies_and_an_empty_batch_queue_nothing(): void {
		( new JetonomyBridge() )->on_import_batch( 'bbpress', 'reply', range( 1, 20 ) );
		( new JetonomyBridge() )->on_import_batch( 'bbpress', 'topic', array() );

		$this->assertCount( 0, $this->pending() );
	}

	public function test_the_same_chunk_delivered_twice_is_queued_once(): void {
		$bridge = new JetonomyBridge();
		$bridge->on_import_batch( 'bbpress', 'topic', array( 5, 6, 7 ) );
		$bridge->on_import_batch( 'bbpress', 'topic', array( 5, 6, 7 ) );

		$this->assertCount( 1, $this->pending() );
	}

	public function test_one_topic_failing_does_not_stop_the_rest_of_its_chunk(): void {
		$seen   = array();
		$bridge = new class( $seen ) extends JetonomyBridge {
			public array $seen = array();
			public function __construct( array &$seen ) {
				$this->seen = &$seen;
			}
			public function sync_discussion( int $post_id, int $space_id = 0 ): void {
				if ( 2 === $post_id ) {
					throw new \RuntimeException( 'boom' );
				}
				$this->seen[] = $post_id;
			}
		};

		$bridge->import_cards_chunk( array( 1, 2, 3 ) );

		$this->assertSame( array( 1, 3 ), $seen );
	}

	public function test_it_is_registered_to_the_import_event_and_kept_across_deactivation(): void {
		( new JetonomyBridge() )->init();

		$this->assertNotFalse( has_action( 'jetonomy_import_batch_imported' ) );
		$this->assertContains( JetonomyBridge::IMPORT_CARDS_HOOK, CronScheduler::DEACTIVATION_KEEP );
	}
}
