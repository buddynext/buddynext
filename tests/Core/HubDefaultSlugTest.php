<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Characterisation test: PageRouter::default_slug() must return the same
 * values after the registry-sourced refactor as the legacy hardcoded map did.
 *
 * @package BuddyNext\Tests\Core
 * @since 1.0.4
 */

declare(strict_types=1);
namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CoreHubs;
use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * Characterisation test for PageRouter::default_slug() registry sourcing.
 */
class HubDefaultSlugTest extends WP_UnitTestCase {

	/**
	 * Reset the shared registry singleton and populate it with core hubs
	 * so default_slug() has a clean, known state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// Reset the shared registry so prior tests cannot pollute it, then
		// populate the core hubs that default_slug() reads.
		$ref = new \ReflectionProperty( HubRegistry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		CoreHubs::register( HubRegistry::instance() );
	}

	/**
	 * Every hub option and the two non-hub entries must return the same slug
	 * as the legacy hardcoded map.
	 *
	 * @return void
	 */
	public function test_registry_defaults_match_legacy_map(): void {
		$m = new \ReflectionMethod( PageRouter::class, 'default_slug' );
		$m->setAccessible( true );
		$cases = array(
			'buddynext_slug_activity'        => 'activity',
			'buddynext_slug_people'          => 'members',
			'buddynext_slug_spaces'          => 'spaces',
			'buddynext_slug_messages'        => 'messages',
			'buddynext_slug_notifications'   => 'notifications',
			'buddynext_slug_auth'            => 'login',
			'buddynext_slug_onboarding'      => 'onboarding',
			'buddynext_slug_community_admin' => 'bn-community-admin', // Non-hub entry; stays hardcoded.
			'buddynext_slug_unknown'         => 'community',          // Ultimate fallback.
		);
		foreach ( $cases as $opt => $expected ) {
			$this->assertSame( $expected, $m->invoke( null, $opt ), $opt );
		}
	}
}
