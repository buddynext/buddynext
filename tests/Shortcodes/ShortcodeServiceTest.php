<?php
/**
 * Tests for the BuddyNext shortcode service.
 *
 * @package BuddyNext\Tests\Shortcodes
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Shortcodes;

use BuddyNext\Shortcodes\ShortcodeService;

/**
 * Verifies shortcode registration and output.
 *
 * @covers \BuddyNext\Shortcodes\ShortcodeService
 */
class ShortcodeServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var ShortcodeService
	 */
	private ShortcodeService $service;

	/**
	 * Create a fresh instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = new ShortcodeService();
		$this->service->init();
	}

	/**
	 * init() registers the buddynext_activity shortcode.
	 */
	public function test_registers_feed_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_activity' ) );
	}

	/**
	 * init() registers the buddynext_people shortcode.
	 */
	public function test_registers_members_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_people' ) );
	}

	/**
	 * init() registers the buddynext_spaces shortcode.
	 */
	public function test_registers_spaces_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_spaces' ) );
	}

	/**
	 * init() registers the buddynext_auth shortcode.
	 */
	public function test_registers_profile_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_auth' ) );
	}

	/**
	 * [buddynext_feed] produces non-empty output.
	 */
	public function test_feed_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_feed]' );
		$this->assertNotEmpty( $output );
	}

	/**
	 * [buddynext_members] produces non-empty output.
	 */
	public function test_members_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_members]' );
		$this->assertNotEmpty( $output );
	}

	/**
	 * [buddynext_spaces] produces non-empty output.
	 */
	public function test_spaces_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_spaces]' );
		$this->assertNotEmpty( $output );
	}

	/**
	 * [buddynext_profile] produces non-empty output.
	 */
	public function test_profile_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_profile]' );
		$this->assertNotEmpty( $output );
	}

	/**
	 * [buddynext_feed] passes 'limit' attribute through to output.
	 */
	public function test_feed_shortcode_accepts_limit_attribute(): void {
		$output = do_shortcode( '[buddynext_feed limit="5"]' );
		$this->assertNotEmpty( $output );
	}
}
