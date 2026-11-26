<?php
/**
 * BuddyNext's every-request options cost one query in total, not one each.
 *
 * Without a persistent object cache each get_option() of a non-autoloaded or
 * never-saved option is its own query on every page, REST call, image and
 * heartbeat. Plugin::REQUEST_OPTIONS is primed in one query at boot; this test
 * runs the readers after priming and fails, naming nothing but the count, the
 * moment one of them reads an option the list does not cover.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CoreHubs;
use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\Plugin;
use BuddyNext\Core\PluginIsolation;

/**
 * @covers \BuddyNext\Core\Plugin
 */
class RequestOptionsPrimedTest extends \WP_UnitTestCase {

	/**
	 * After priming, the every-request readers run no option queries.
	 *
	 * @return void
	 */
	public function test_every_request_readers_are_covered_by_the_primed_list(): void {
		foreach ( Plugin::REQUEST_OPTIONS as $option ) {
			delete_option( $option ); // Worst case: never saved.
			wp_cache_delete( $option, 'options' );
		}
		wp_cache_delete( 'notoptions', 'options' );

		wp_prime_option_caches( Plugin::REQUEST_OPTIONS );

		global $wpdb;
		$before = $wpdb->num_queries;
		$seen   = array();
		$spy    = static function ( $sql ) use ( &$seen ) {
			if ( preg_match( "/option_name = '([^']+)'/", $sql, $m ) ) {
				$seen[] = $m[1];
			}
			return $sql;
		};
		add_filter( 'query', $spy );

		buddynext_feature_enabled( 'shares' );
		( new \BuddyNext\Privacy\CookieConsentService() )->register();
		PluginIsolation::owner_strip_list();
		$persist = new \ReflectionMethod( CoreHubs::class, 'persist_hub_slugs' );
		$persist->invoke( null, HubRegistry::instance() );
		// Shell appearance readers that run on every community page.
		\BuddyNext\Theme\Appearance::display_brand();
		\BuddyNext\Theme\Appearance::container_width();
		( new \BuddyNext\Theme\Appearance() )->seed_default_theme();

		remove_filter( 'query', $spy );
		$this->assertSame( array(), $seen, 'Every-request options missing from Plugin::REQUEST_OPTIONS.' );
	}

	/**
	 * Every integration's on/off switches load in one query, not one per tab.
	 *
	 * @return void
	 */
	public function test_integration_switches_are_primed_together(): void {
		$register = static function ( array $items ): array {
			$items['alpha'] = array( 'label' => 'Alpha', 'has_nav' => true, 'subtabs' => array( 'one' => 'One' ) );
			$items['beta']  = array( 'label' => 'Beta', 'has_nav' => true );
			return $items;
		};
		add_filter( 'buddynext_integrations', $register );
		$registry = \BuddyNext\Integrations\IntegrationRegistry::instance();
		$resolved = new \ReflectionProperty( $registry, 'resolved' );
		$resolved->setAccessible( true );
		$resolved->setValue( $registry, null );
		wp_cache_delete( 'notoptions', 'options' );

		$registry->prime_switches();

		$seen = array();
		$spy  = static function ( $sql ) use ( &$seen ) {
			if ( preg_match( "/option_name = '([^']+)'/", $sql, $m ) ) {
				$seen[] = $m[1];
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		buddynext_integration_enabled( 'alpha', 'nav', 'one' );
		buddynext_integration_enabled( 'beta', 'feed' );
		remove_filter( 'query', $spy );
		remove_filter( 'buddynext_integrations', $register );
		$resolved->setValue( $registry, null );

		$this->assertSame( array(), $seen );
	}
}
