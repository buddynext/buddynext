<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing,WordPress.DB.DirectDatabaseQuery -- concise, self-describing test methods; a second DB connection stands in for a second request.
/**
 * The update path reaches Installer::run() at init:0, before Action Scheduler's
 * data store; its scheduler calls wait for action_scheduler_init. Two first
 * requests: only the one holding the lock upgrades.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use BuddyNext\Core\Installer;
use WP_UnitTestCase;

class UpgradeEarlyCallTest extends WP_UnitTestCase {

	public function test_run_before_action_scheduler_is_ready_defers_its_scheduler_calls(): void {
		global $wp_actions;
		$had = $wp_actions['action_scheduler_init'] ?? null;
		unset( $wp_actions['action_scheduler_init'] ); // As on the update path at init:0.
		remove_all_actions( 'action_scheduler_init' );
		try {
			Installer::run();
			$this->assertNotFalse( has_action( 'action_scheduler_init', array( CronScheduler::class, 'run_cron_migration' ) ), 'The retire step waits for Action Scheduler.' );
			$this->assertNotFalse( has_action( 'action_scheduler_init', array( \BuddyNext\Search\SearchService::class, 'schedule_reindex_all' ) ), 'The reindex waits too.' );
		} finally {
			remove_all_actions( 'action_scheduler_init' );
			if ( null !== $had ) {
				$wp_actions['action_scheduler_init'] = $had; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	public function test_the_manual_update_flow_owes_the_data_migrations(): void {
		$pending = (string) ( new \ReflectionClassConstant( Installer::class, 'PENDING_MIGRATIONS_OPTION' ) )->getValue();
		$current = (int) ( new \ReflectionClassConstant( Installer::class, 'SCHEMA_VERSION' ) )->getValue();
		update_option( 'buddynext_schema_version', 75 ); // A 1.2.9 site.
		delete_option( $pending );
		delete_transient( CronScheduler::REQUEUE_FLAG );

		Installer::run(); // Activation after the files were replaced.

		$this->assertSame( $current, (int) get_option( 'buddynext_schema_version' ) );
		$this->assertSame( 75, (int) get_option( $pending ), 'The migrations from 75 are owed, so maybe_upgrade() runs them.' );
		$this->assertTrue( CronScheduler::has_pending( Installer::FINISH_UPGRADE_HOOK ), 'And a runner is queued.' );
		$this->assertNotFalse( get_transient( CronScheduler::REQUEUE_FLAG ), 'The after-activation pass is owed.' );
		delete_option( $pending );
		as_unschedule_all_actions( Installer::FINISH_UPGRADE_HOOK );
	}

	public function test_a_second_first_request_does_not_upgrade_while_the_first_holds_the_lock(): void {
		global $wpdb;
		$current = (int) ( new \ReflectionClassConstant( Installer::class, 'SCHEMA_VERSION' ) )->getValue();
		$pending = (string) ( new \ReflectionClassConstant( Installer::class, 'PENDING_MIGRATIONS_OPTION' ) )->getValue();
		update_option( 'buddynext_schema_version', $current - 1 );
		delete_option( $pending );
		list( $host, $port, $socket ) = $wpdb->parse_db_host( DB_HOST );
		$other                        = new \mysqli( (string) $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : 3306, $socket ? (string) $socket : '' );
		$lock                         = ( new \ReflectionMethod( Installer::class, 'upgrade_lock_name' ) )->invoke( null );
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $lock ) . "', 0)" )->fetch_row()[0] );

		Installer::maybe_upgrade_schema();

		$this->assertSame( $current - 1, (int) get_option( 'buddynext_schema_version' ), 'The waiting request leaves the upgrade to the lock holder.' );
		$this->assertFalse( get_option( $pending ) );
		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $lock ) . "')" );
		$other->close();
		update_option( 'buddynext_schema_version', $current );
	}
}
