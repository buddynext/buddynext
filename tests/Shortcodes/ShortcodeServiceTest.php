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
	 * init() registers the buddynext_feed shortcode.
	 */
	public function test_registers_feed_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_feed' ) );
	}

	/**
	 * init() registers the buddynext_members shortcode.
	 */
	public function test_registers_members_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_members' ) );
	}

	/**
	 * init() registers the buddynext_spaces shortcode.
	 */
	public function test_registers_spaces_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_spaces' ) );
	}

	/**
	 * init() registers the buddynext_profile shortcode.
	 */
	public function test_registers_profile_shortcode(): void {
		$this->assertTrue( shortcode_exists( 'buddynext_profile' ) );
	}

	/**
	 * [buddynext_feed] output contains a wrapper div.
	 */
	public function test_feed_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_feed]' );
		$this->assertStringContainsString( 'buddynext-feed', $output );
	}

	/**
	 * [buddynext_members] output contains a wrapper div.
	 */
	public function test_members_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_members]' );
		$this->assertStringContainsString( 'buddynext-members', $output );
	}

	/**
	 * [buddynext_spaces] output contains a wrapper div.
	 */
	public function test_spaces_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_spaces]' );
		$this->assertStringContainsString( 'buddynext-spaces', $output );
	}

	/**
	 * [buddynext_profile] output contains a wrapper div.
	 */
	public function test_profile_shortcode_renders_wrapper(): void {
		$output = do_shortcode( '[buddynext_profile]' );
		$this->assertStringContainsString( 'buddynext-profile', $output );
	}

	/**
	 * [buddynext_feed] passes 'limit' attribute through to output.
	 */
	public function test_feed_shortcode_accepts_limit_attribute(): void {
		$output = do_shortcode( '[buddynext_feed limit="5"]' );
		$this->assertStringContainsString( 'buddynext-feed', $output );
	}
}
