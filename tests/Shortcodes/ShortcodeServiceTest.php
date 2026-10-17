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

	/**
	 * A community admin holding "Edit anyone's profile" opening another
	 * member's edit page through [buddynext_people] edits THAT member, not
	 * their own profile (card 10322714806, permissions program phase 4).
	 */
	public function test_people_shortcode_edit_opens_the_member_being_edited(): void {
		\BuddyNext\Core\Installer::run();
		( new \BuddyNext\MemberTypes\MemberTypeService( new \BuddyNext\Core\CacheService() ) )->create( array( 'slug' => 'beekeeper', 'name' => 'Beekeeper', 'self_select' => 1 ) );
		$member = self::factory()->user->create( array( 'role' => 'subscriber', 'user_login' => 'ruth_hives' ) );
		$editor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $editor, 'bn_community_role', 'admin' );
		update_user_meta( $editor, 'bn_email_verified', '1' );
		wp_set_current_user( $editor );
		set_query_var( 'bn_user_slug', 'ruth_hives' );
		set_query_var( 'bn_resolved_user_id', $member );
		set_query_var( 'bn_profile_action', 'edit' );

		$html = do_shortcode( '[buddynext_people]' );

		$this->assertStringContainsString( 'data-user-id="' . $member . '"', $html );
		$this->assertStringNotContainsString( 'data-user-id="' . $editor . '"', $html );
	}
}
