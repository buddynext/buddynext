<?php
/**
 * Tests for HubRegistry and HubDescriptor — uniform hub surface registry.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\HubDescriptor;
use WP_UnitTestCase;

/**
 * Tests for HubRegistry and HubDescriptor.
 *
 * @covers \BuddyNext\Core\HubRegistry
 * @covers \BuddyNext\Core\HubDescriptor
 */
class HubRegistryTest extends WP_UnitTestCase {
	/**
	 * Tests register, has, get, and all on a fresh registry instance.
	 */
	public function test_register_and_get(): void {
		$reg = new HubRegistry();
		$d   = new HubDescriptor( 'demo', 'buddynext_slug_demo', 'demo', 'buddynext_page_demo', 'Demo', '[demo]' );
		$reg->register( $d );
		$this->assertTrue( $reg->has( 'demo' ) );
		$this->assertSame( 'demo', $reg->get( 'demo' )->default_slug );
		$this->assertArrayHasKey( 'demo', $reg->all() );
	}

	/**
	 * Tests that get returns null for an unregistered key.
	 */
	public function test_get_unknown_returns_null(): void {
		$this->assertNull( ( new HubRegistry() )->get( 'nope' ) );
	}

	/**
	 * Tests that hub_query_var falls back to the hub key when query_var is null.
	 */
	public function test_hub_query_var_defaults_to_key(): void {
		$d = new HubDescriptor( 'feed', 'buddynext_slug_activity', 'activity', 'buddynext_page_activity', 'Activity', '[buddynext_activity]' );
		$this->assertSame( 'feed', $d->hub_query_var() );
	}
}
