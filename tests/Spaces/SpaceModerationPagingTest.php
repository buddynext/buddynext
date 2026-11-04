<?php
/**
 * Every space moderation tab pages past 20, with totals from COUNT queries.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationLogService;
use BuddyNext\Spaces\SpaceService;

/**
 * Reports, join requests and the log on spaces/moderation.php.
 */
class SpaceModerationPagingTest extends \WP_UnitTestCase {

	private int $space_id;
	private int $owner;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner    = self::factory()->user->create();
		$this->space_id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Busy Paging',
				'slug' => 'busy-paging',
				'type' => 'private',
			)
		);
		global $wpdb;
		$users = self::factory()->user->create_many( 25 );
		foreach ( $users as $i => $user ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_space_members',
				array(
					'space_id'  => $this->space_id,
					'user_id'   => $user,
					'role'      => 'member',
					'status'    => 'pending',
					'joined_at' => gmdate( 'Y-m-d H:i:s', time() - 1000 + $i ),
				)
			);
			$wpdb->insert(
				$wpdb->prefix . 'bn_reports',
				array(
					'reporter_id' => $this->owner,
					'object_type' => 'user',
					'object_id'   => $user,
					'reason'      => 'spam',
					'status'      => 'pending',
					'space_id'    => $this->space_id,
				)
			);
			( new ModerationLogService() )->log( $this->owner, 'warn', array( 'space_id' => $this->space_id ) );
		}
		wp_set_current_user( $this->owner );
	}

	public function tear_down(): void {
		unset( $_GET['bn_mtab'], $_GET['mpage'] );
		parent::tear_down();
	}

	private function page( string $tab, int $page = 0 ): string {
		$_GET['bn_mtab'] = $tab;
		unset( $_GET['mpage'] );
		if ( $page > 0 ) {
			$_GET['mpage'] = (string) $page;
		}
		ob_start();
		buddynext_get_template( 'spaces/moderation.php', array( 'space_id' => $this->space_id ) );
		return (string) ob_get_clean();
	}

	public function test_join_requests_page_and_the_badge_is_the_true_total(): void {
		$first = $this->page( 'pending' );
		$this->assertSame( 20, substr_count( $first, 'bn-space-mod__pending-row' ) );
		$this->assertStringContainsString( '25 awaiting approval', $first );
		$this->assertStringContainsString( 'mpage=2', $first );

		$second = $this->page( 'pending', 2 );
		$this->assertSame( 5, substr_count( $second, 'bn-space-mod__pending-row' ) );
		$this->assertStringContainsString( '25 awaiting approval', $second );
	}

	public function test_reports_and_log_page_past_twenty(): void {
		$this->assertSame( 20, substr_count( $this->page( 'reports' ), '<article' ) );
		$this->assertSame( 5, substr_count( $this->page( 'reports', 2 ), '<article' ) );
		$this->assertSame( 20, substr_count( $this->page( 'log' ), 'bn-space-mod__log-row' ) );
		$this->assertSame( 5, substr_count( $this->page( 'log', 2 ), 'bn-space-mod__log-row' ) );
	}

	public function test_a_page_past_the_end_clamps_to_the_last_and_one_page_draws_no_pager(): void {
		$this->assertSame( 5, substr_count( $this->page( 'pending', 99 ), 'bn-space-mod__pending-row' ) );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bn_mod_log WHERE space_id = %d AND id > ( SELECT min_id FROM ( SELECT MIN(id) + 2 AS min_id FROM {$wpdb->prefix}bn_mod_log WHERE space_id = %d ) AS first_rows )", $this->space_id, $this->space_id ) );
		$this->assertStringNotContainsString( 'mpage=2', $this->page( 'log' ) );
	}
}
