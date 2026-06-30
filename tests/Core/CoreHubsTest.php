<?php
/**
 * Tests for CoreHubs — built-in hub registration.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\CoreHubs;
use WP_UnitTestCase;

/**
 * Tests for CoreHubs.
 *
 * @covers \BuddyNext\Core\CoreHubs
 */
class CoreHubsTest extends WP_UnitTestCase {
	/**
	 * Tests that all 7 core hubs are registered with the correct slug_option,
	 * default_slug, and backing_page values.
	 */
	public function test_all_seven_core_hubs_registered_with_correct_slugs(): void {
		$reg = new HubRegistry();
		CoreHubs::register( $reg );
		$expected = array(
			'feed'          => array( 'buddynext_slug_activity', 'activity', true ),
			'people'        => array( 'buddynext_slug_people', 'members', true ),
			'spaces'        => array( 'buddynext_slug_spaces', 'spaces', true ),
			'messages'      => array( 'buddynext_slug_messages', 'messages', true ),
			'notifications' => array( 'buddynext_slug_notifications', 'notifications', true ),
			'auth'          => array( 'buddynext_slug_auth', 'login', true ),
			'onboarding'    => array( 'buddynext_slug_onboarding', 'onboarding', false ),
		);
		foreach ( $expected as $key => [ $opt, $default, $backing ] ) {
			$this->assertTrue( $reg->has( $key ), "missing hub: $key" );
			$this->assertSame( $opt, $reg->get( $key )->slug_option, "slug_option $key" );
			$this->assertSame( $default, $reg->get( $key )->default_slug, "default_slug $key" );
			$this->assertSame( $backing, $reg->get( $key )->backing_page, "backing_page $key" );
		}
		$this->assertCount( 7, $reg->all() );
	}

	/**
	 * Tests that buddynext_register_hubs action fires with the registry instance.
	 */
	public function test_register_hubs_action_fires_with_registry(): void {
		$received = null;
		add_action(
			'buddynext_register_hubs',
			function ( $reg ) use ( &$received ) {
				$received = $reg;
			}
		);
		$reg = new HubRegistry();
		CoreHubs::register( $reg );
		$this->assertSame( $reg, $received );
	}
}
