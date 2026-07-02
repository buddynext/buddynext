<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests that PageRouter::init() registers a flush hook for every hub slug option
 * sourced from HubRegistry (not a hardcoded list).
 *
 * @package BuddyNext\Tests\Core
 * @since 1.0.4
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\CoreHubs;
use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * Tests that PageRouter::init() registers a flush hook for every hub slug option.
 */
class HubSlugFlushTest extends WP_UnitTestCase {
	/**
	 * Reset the HubRegistry singleton and re-populate with core hubs before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$ref = new \ReflectionProperty( HubRegistry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		CoreHubs::register( HubRegistry::instance() );
	}

	/**
	 * Every hub registered in HubRegistry must have a flush hook on its slug option.
	 */
	public function test_flush_hook_registered_for_every_hub_slug_option(): void {
		( new PageRouter() )->init();
		foreach ( HubRegistry::instance()->all() as $hub ) {
			$this->assertNotFalse( has_action( 'update_option_' . $hub->slug_option ), 'no flush hook for ' . $hub->slug_option );
		}
		// Spot-check a couple of exact legacy option names are still hooked.
		$this->assertNotFalse( has_action( 'update_option_buddynext_slug_activity' ) );
		$this->assertNotFalse( has_action( 'update_option_buddynext_slug_onboarding' ) );
	}
}
