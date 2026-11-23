<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The v78 upgrade cancels the pending duplicate jobs earlier builds queued.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\CronScheduler::cancel_duplicate_pending
 */
class PendingDuplicateCleanupTest extends WP_UnitTestCase {

	/**
	 * Pending duplicate groups for these hooks (the owner's query, hook-scoped).
	 *
	 * @param string[] $hooks Hooks.
	 * @return int
	 */
	private function dup_groups( array $hooks ): int {
		global $wpdb;
		$in = "'" . implode( "','", array_map( 'esc_sql', $hooks ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending' AND hook IN ({$in}) GROUP BY hook, group_id, args HAVING COUNT(*) > 1) d" );
	}

	/**
	 * Pending rows for a hook.
	 *
	 * @param string $hook Hook.
	 * @return int[]
	 */
	private function pending_ids( string $hook ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending' AND hook = %s ORDER BY action_id", $hook ) ) );
	}

	/**
	 * Run the upgrade's migrations as a site on schema 77.
	 *
	 * @return void
	 */
	private function upgrade_from_77(): void {
		$m = new \ReflectionMethod( Installer::class, 'maybe_migrate_pending_duplicates' );
		$m->setAccessible( true );
		$m->invoke( null, 77 );
	}

	/**
	 * Duplicates go to zero, the oldest stays, other rows are untouched, a rerun is a no-op.
	 *
	 * @return void
	 */
	public function test_upgrade_cancels_our_duplicates_only(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not loaded.' );
		}
		$ours    = 'buddynext_async_index_space';
		$foreign = 'other_plugin_sync';
		$kept    = 'buddynext_webhook_deliver'; // Ours, but not a repeat-safe job.
		foreach ( array( 1, 1, 1, 2, 2 ) as $id ) {
			as_enqueue_async_action( $ours, array( $id ), 'buddynext' );
		}
		as_enqueue_async_action( $ours, array( 3 ), 'buddynext' );
		as_enqueue_async_action( $foreign, array( 1 ), 'other' );
		as_enqueue_async_action( $foreign, array( 1 ), 'other' );
		as_enqueue_async_action( $kept, array( 9 ), 'buddynext' );
		as_enqueue_async_action( $kept, array( 9 ), 'buddynext' );
		$before = $this->pending_ids( $ours );

		$this->assertSame( 2, $this->dup_groups( array( $ours ) ), 'Fixture: two duplicate groups.' );

		$this->upgrade_from_77();

		$this->assertSame( 0, $this->dup_groups( array( $ours ) ) );
		$this->assertSame( array( $before[0], $before[3], $before[5] ), $this->pending_ids( $ours ), 'The oldest row of each group stays, the single row too.' );
		$this->assertCount( 2, $this->pending_ids( $foreign ), 'Another plugin\'s rows are never touched.' );
		$this->assertCount( 2, $this->pending_ids( $kept ), 'Our hooks outside the list are never touched.' );

		$this->upgrade_from_77();
		$this->assertSame( array( $before[0], $before[3], $before[5] ), $this->pending_ids( $ours ), 'A second run changes nothing.' );
	}
}
