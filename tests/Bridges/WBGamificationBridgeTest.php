<?php
/**
 * Tests for WBGamification bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WBGamification;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\WBGamification
 */
class WBGamificationBridgeTest extends \WP_UnitTestCase {

	private WBGamification $bridge;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->bridge = new WBGamification();
		$this->bridge->init();
	}

	public function test_user_followed_fires_wbgam_event(): void {
		$fired  = false;
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

		do_action( 'buddynext_member_joined_space', 1, 5 );

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

		do_action( 'buddynext_connection_accepted', 1, 2 );

		$this->assertSame( 'bn_connected', $fired_type );
	}
}
