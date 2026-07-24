<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests that Installer::create_hub_pages() creates backing pages from the hub registry.
 *
 * @package BuddyNext\Tests\Core
 * @since 1.0.4
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\CoreHubs;
use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Tests Installer::create_hub_pages() creates pages from the hub registry.
 */
class InstallerHubPagesTest extends WP_UnitTestCase {

	/**
	 * Resets the hub registry and clears page options before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$ref = new \ReflectionProperty( HubRegistry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		CoreHubs::register( HubRegistry::instance() );
		// Ensure a clean slate so create_hub_pages() actually creates pages.
		foreach ( HubRegistry::instance()->all() as $hub ) {
			delete_option( $hub->page_option );
		}
	}

	/**
	 * Verifies that one backing page is created per hub with backing_page=true.
	 *
	 * @return void
	 */
	public function test_backing_pages_created_for_backing_hubs_only(): void {
		Installer::create_hub_pages();
		$created = 0;
		foreach ( HubRegistry::instance()->all() as $hub ) {
			$page_id = (int) get_option( $hub->page_option, 0 );
			if ( $hub->backing_page ) {
				$this->assertGreaterThan( 0, $page_id, "no backing page for {$hub->key}" );
				$post = get_post( $page_id );
				$this->assertSame( $hub->shortcode, $post->post_content, "wrong content for {$hub->key}" );
				$this->assertSame( $hub->title, $post->post_title, "wrong title for {$hub->key}" );
				$this->assertSame( 'page', $post->post_type );
				$this->assertSame( 'publish', $post->post_status );
				++$created;
			} else {
				$this->assertSame( 0, $page_id, "{$hub->key} (backing_page=false) should have no page" );
			}
		}
		$this->assertSame( 6, $created, 'expected 6 backing pages (onboarding excluded)' );
	}

	/**
	 * Activation must defer the rewrite flush to PageRouter, never flush directly.
	 *
	 * On the activation request BuddyNext was not loaded at plugins_loaded, so
	 * PageRouter::register_rewrites() never ran — a direct flush here persists a
	 * rewrite_rules option WITHOUT any BuddyNext rule. Because
	 * buddynext_router_version still matches ROUTER_VERSION, maybe_flush_rewrites()
	 * never repairs it and every deep route 404s until permalinks are resaved.
	 * Clearing the sentinel instead makes the next normal request perform the
	 * complete flush after the rules are registered.
	 *
	 * @return void
	 */
	public function test_create_hub_pages_defers_flush_to_page_router(): void {
		update_option( 'rewrite_rules', array( 'sentinel/?$' => 'index.php?sentinel=1' ) );
		update_option( 'buddynext_router_version', '2000-01-01-stale' );

		Installer::create_hub_pages();

		$this->assertSame(
			array( 'sentinel/?$' => 'index.php?sentinel=1' ),
			get_option( 'rewrite_rules' ),
			'create_hub_pages() must not regenerate rewrite rules while BuddyNext rules are unregistered'
		);
		$this->assertFalse(
			get_option( 'buddynext_router_version' ),
			'create_hub_pages() must clear the router sentinel so PageRouter flushes on the next request'
		);
	}
}
