<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Per-event jobs are not queued twice while an identical one is still pending.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use WP_UnitTestCase;

class EnqueueOnceTest extends WP_UnitTestCase {

	private function pending( string $hook ): int {
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending', 'per_page' => 20 ), 'ids' ) );
	}

	public function test_saving_a_space_twice_queues_one_reindex_pass(): void {
		as_unschedule_all_actions( 'buddynext_async_reindex_space_posts' );
		$space = (int) buddynext_service( 'spaces' )->create( self::factory()->user->create(), array( 'name' => 'Once ' . wp_rand(), 'slug' => 'once-' . wp_rand( 10000, 99999 ), 'type' => 'open' ) );
		as_unschedule_all_actions( 'buddynext_async_reindex_space_posts' );

		do_action( 'buddynext_space_updated', $space, array(), array() );
		do_action( 'buddynext_space_updated', $space, array(), array() );

		$this->assertSame( 1, $this->pending( 'buddynext_async_reindex_space_posts' ) );
	}

	public function test_a_member_flipping_search_visibility_twice_queues_one_reindex(): void {
		as_unschedule_all_actions( 'buddynext_async_reindex_user_posts' );
		$user = self::factory()->user->create();

		do_action( 'buddynext_user_search_visibility_changed', $user );
		do_action( 'buddynext_user_search_visibility_changed', $user );

		$this->assertSame( 1, $this->pending( 'buddynext_async_reindex_user_posts' ) );
	}

	public function test_saving_a_profile_field_twice_queues_one_mirror_continuation(): void {
		global $wpdb;
		as_unschedule_all_actions( 'buddynext_profile_field_updated' );
		$profiles = buddynext_service( 'profiles' );
		$field    = (int) $profiles->create_field(
			array(
				'group_name'    => 'basic_info',
				'field_key'     => 'once_' . wp_rand( 1000, 9999 ),
				'label'         => 'Once',
				'type'          => 'text',
				'is_searchable' => 1,
				'visibility'    => 'public',
			)
		);
		// More than two rebuild batches of stored values, so each save parks a cursor and queues a continuation.
		$rows = array();
		for ( $u = 1; $u <= 450; $u++ ) {
			$rows[] = $wpdb->prepare( '(%d, %d, 0, %s)', 900000 + $u, $field, 'v' . $u );
		}
		$wpdb->query( "INSERT INTO {$wpdb->prefix}bn_profile_values (user_id, field_id, entry_index, value) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		as_unschedule_all_actions( 'buddynext_profile_field_updated' );

		$profiles->rebuild_field_mirror( $field );
		$profiles->rebuild_field_mirror( $field );

		$this->assertSame( 1, $this->pending( 'buddynext_profile_field_updated' ) );
	}

	public function test_indexing_the_same_post_twice_queues_one_id_only_job(): void {
		as_unschedule_all_actions( 'buddynext_async_index_hashtags' );
		do_action( 'buddynext_post_created', 77, 1, 'text' );
		do_action( 'buddynext_post_updated', 77, 1, array( 'content' => 'x' ) );
		do_action( 'buddynext_post_created', 78, 1, 'text' );

		$jobs = as_get_scheduled_actions( array( 'hook' => 'buddynext_async_index_hashtags', 'status' => 'pending', 'per_page' => 20 ) );
		$this->assertCount( 2, $jobs, 'Identical pending job skipped; another post still queued.' );
		foreach ( $jobs as $job ) {
			$this->assertCount( 2, $job->get_args(), 'Job args are object type and id only.' );
		}
	}

	public function test_bridge_content_is_synced_inline_not_queued(): void {
		as_unschedule_all_actions( 'buddynext_async_index_hashtags' );
		do_action( 'buddynext_index_hashtags', 'media', 79, 'hello #inline' );

		$this->assertSame( 0, $this->pending( 'buddynext_async_index_hashtags' ), 'Content text is never copied into a job.' );
	}

	public function test_a_running_job_does_not_block_a_fresh_one(): void {
		as_unschedule_all_actions( 'buddynext_async_index_hashtags' );
		$running = as_enqueue_async_action( 'buddynext_async_index_hashtags', array( 'post', 80 ), 'buddynext' );
		\ActionScheduler::store()->log_execution( $running );

		do_action( 'buddynext_post_created', 80, 1, 'text' );

		$this->assertSame( 1, $this->pending( 'buddynext_async_index_hashtags' ), 'An edit made while the job runs is still picked up.' );
	}
}
