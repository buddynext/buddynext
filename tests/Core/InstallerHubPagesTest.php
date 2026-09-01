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
	 * Since 1.1.6 a hub can also DECLINE its page while its dependency is absent
	 * (buddynext_create_hub_page). Messages does exactly that without the
	 * WPMediaVerse engine, which is the state a test run is in — so the
	 * expectation is "every hub that wants a page has one", not "every hub with
	 * backing_page=true has one". The withheld case has its own test below.
	 *
	 * @return void
	 */
	public function test_backing_pages_created_for_backing_hubs_only(): void {
		Installer::create_hub_pages();
		$created = 0;
		foreach ( HubRegistry::instance()->all() as $hub ) {
			$page_id = (int) get_option( $hub->page_option, 0 );
			$wanted  = $hub->backing_page
				&& (bool) apply_filters( 'buddynext_create_hub_page', true, $hub );

			if ( $hub->backing_page && ! $wanted ) {
				$this->assertSame( 0, $page_id, "{$hub->key} declined its page; none should exist" );
				continue;
			}

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
		// Messages declines its page without the WPMediaVerse engine, which is the
		// state of a test run; the other five are unconditional.
		$this->assertSame( 5, $created, 'expected 5 backing pages (onboarding excluded, messages withheld)' );
	}

	/**
	 * A hub whose dependency is missing leaves NO published page behind.
	 *
	 * BuddyNext published /messages/ on every site regardless of WPMediaVerse, so
	 * a site without the engine carried a page the theme's page-list advertised,
	 * members clicked, and nothing could serve.
	 *
	 * @return void
	 */
	public function test_a_hub_with_a_missing_dependency_gets_no_page(): void {
		Installer::create_hub_pages();

		$this->assertFalse(
			\BuddyNext\Messages\MessagesData::available(),
			'precondition: the WPMediaVerse engine is absent in a test run'
		);
		$this->assertSame(
			0,
			(int) get_option( 'buddynext_page_messages', 0 ),
			'no messages page while messaging cannot work'
		);
		$this->assertNull( get_page_by_path( 'messages' ), 'and nothing published at that slug' );
	}

	/**
	 * An existing page at the hub's slug is ADOPTED, never duplicated.
	 *
	 * The loop decided "does the page exist?" from the stored option alone, so a
	 * lost option next to a surviving page produced a second page — WordPress
	 * suffixes it (messages-2) and the hub starts serving a URL the owner's links
	 * do not point at.
	 *
	 * @return void
	 */
	public function test_an_existing_page_at_the_slug_is_adopted_not_duplicated(): void {
		Installer::create_hub_pages();
		$feed_page = (int) get_option( 'buddynext_page_activity', 0 );
		$this->assertGreaterThan( 0, $feed_page, 'precondition: the feed hub has a page' );

		// Lose the mapping while the page survives.
		delete_option( 'buddynext_page_activity' );
		Installer::create_hub_pages();

		$this->assertSame(
			$feed_page,
			(int) get_option( 'buddynext_page_activity', 0 ),
			'the surviving page is reclaimed rather than replaced'
		);
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
