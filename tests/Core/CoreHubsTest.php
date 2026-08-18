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
	 * Tests that every core hub is registered with the correct slug_option,
	 * default_slug, and backing_page values.
	 */
	public function test_all_core_hubs_registered_with_correct_slugs(): void {
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
			// Added after this test was written, which is exactly what the count
			// below is for — but the count alone reported "8 is not 7" without
			// naming what the eighth was, so the suite carried a permanent red
			// that read as noise rather than as "add the new hub here".
			'community_admin' => array( 'buddynext_slug_community_admin', 'community-admin', false ),
		);
		foreach ( $expected as $key => [ $opt, $default, $backing ] ) {
			$this->assertTrue( $reg->has( $key ), "missing hub: $key" );
			$this->assertSame( $opt, $reg->get( $key )->slug_option, "slug_option $key" );
			$this->assertSame( $default, $reg->get( $key )->default_slug, "default_slug $key" );
			$this->assertSame( $backing, $reg->get( $key )->backing_page, "backing_page $key" );
		}
		// Every registered hub must be named above. A bare count says only that the
		// number moved; the diff says WHICH hub appeared, which is the thing the
		// next person needs in order to act on it.
		$this->assertSame(
			array_keys( $expected ),
			array_keys( $reg->all() ),
			'A core hub was added or removed without updating this test. Add it to $expected with its slug option, default slug and backing_page.'
		);
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
