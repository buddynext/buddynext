<?php
/**
 * Tests for WBGamification bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\GamificationBridge
 */
class WBGamificationBridgeTest extends \WP_UnitTestCase {

	private GamificationBridge $bridge;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		// Plugin class and function stubs are registered in tests/bootstrap.php.
		$this->bridge = new GamificationBridge();
		$this->bridge->init();
	}

	public function test_user_followed_fires_wbgam_event(): void {
		$fired      = false;
		$fired_type = '';

		add_action(
			'wb_gamification_event',
			function ( string $event_type ) use ( &$fired, &$fired_type ): void {
				$fired      = true;
				$fired_type = $event_type;
			},
			10,
			1
		);

		do_action( 'buddynext_user_followed', 1, 2 );

		$this->assertTrue( $fired );
		$this->assertSame( 'bn_followed', $fired_type );
	}

	public function test_post_created_fires_wbgam_event(): void {
		$fired_type = '';

		add_action(
			'wb_gamification_event',
			function ( string $event_type ) use ( &$fired_type ): void {
				$fired_type = $event_type;
			},
			10,
			1
		);

		do_action( 'buddynext_post_created', 10, 1, 'text' );

		$this->assertSame( 'bn_post_created', $fired_type );
	}

	public function test_space_joined_fires_wbgam_event(): void {
		$fired_type = '';

		add_action(
			'wb_gamification_event',
			function ( string $event_type ) use ( &$fired_type ): void {
				$fired_type = $event_type;
			},
			10,
			1
		);

		// Hook contract (BLOCK 11): buddynext_space_member_joined( space_id, user_id, role ).
		do_action( 'buddynext_space_member_joined', 5, 1, 'member' );

		$this->assertSame( 'bn_space_joined', $fired_type );
	}

	public function test_strike_issued_fires_wbgam_strike_event(): void {
		$fired_type = '';

		add_action(
			'wb_gamification_event',
			function ( string $event_type ) use ( &$fired_type ): void {
				$fired_type = $event_type;
			},
			10,
			1
		);

		do_action( 'buddynext_strike_issued', 1, 2, 3 );

		$this->assertSame( 'bn_strike_issued', $fired_type );
	}

	public function test_connection_accepted_fires_wbgam_event(): void {
		$fired_type = '';

		add_action(
			'wb_gamification_event',
			function ( string $event_type ) use ( &$fired_type ): void {
				$fired_type = $event_type;
			},
			10,
			1
		);

		// Hook contract (BLOCK 11): buddynext_connection_accepted( connection_id, user_a, user_b ).
		do_action( 'buddynext_connection_accepted', 0, 1, 2 );

		$this->assertSame( 'bn_connected', $fired_type );
	}

	public function test_credential_badge_awarded_posts_feed_activity(): void {
		global $wpdb;
		$user = self::factory()->user->create();

		do_action(
			'wb_gam_badge_awarded',
			$user,
			array( 'name' => 'Top Contributor', 'is_credential' => 1 ),
			'top-contributor'
		);

		$url      = home_url( 'gamification/badge/top-contributor/' . $user . '/share/' );
		$activity = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link' AND link_url = %s",
				$user,
				$url
			)
		);
		$this->assertSame( 1, $activity );
	}

	public function test_non_credential_badge_posts_no_activity(): void {
		global $wpdb;
		$user = self::factory()->user->create();

		do_action(
			'wb_gam_badge_awarded',
			$user,
			array( 'name' => 'First Login', 'is_credential' => 0 ),
			'first-login'
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link'", $user )
		);
		$this->assertSame( 0, $count, 'only credential badges broadcast to the feed' );
	}
}
