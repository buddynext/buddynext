<?php
/**
 * Author links go to the member's BuddyNext profile.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\AuthorLinkListener;
use WP_UnitTestCase;

/**
 * Every author link a theme builds with get_author_posts_url() is handed the member's
 * profile instead - but only while the profile lists the author's posts (Member Blog
 * active, Articles tab on), only where that viewer can open the profile and only on
 * the front end.
 *
 * Each test runs in its own process: Member Blog is "active" when its version
 * constant is defined, and a constant cannot be undefined for the inactive case.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AuthorLinkListenerTest extends WP_UnitTestCase {

	/**
	 * Author under test.
	 *
	 * @var int
	 */
	private int $author_id;

	/**
	 * Set up: an author and the filter attached to a fresh listener.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		if ( 'test_without_member_blog_bylines_keep_the_author_archive' !== $this->getName( false ) && ! defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_VERSION', 'test' );
		}
		$this->author_id = self::factory()->user->create( array( 'user_login' => 'byline_author' ) );
		wp_set_current_user( 0 );
		remove_all_filters( 'author_link' );
		( new AuthorLinkListener() )->register();
	}

	/**
	 * Tear down: leave no admin screen or preference behind.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );
		remove_all_filters( 'author_link' );
		remove_all_filters( 'buddynext_author_link_to_profile' );
		parent::tear_down();
	}

	/**
	 * A public profile is what a byline links to.
	 *
	 * @return void
	 */
	public function test_author_link_points_at_public_profile(): void {
		$this->assertSame(
			buddynext_member_url( $this->author_id ),
			get_author_posts_url( $this->author_id )
		);
	}

	/**
	 * A profile the viewer cannot open keeps the WordPress URL, never a wall.
	 *
	 * @return void
	 */
	public function test_private_profile_keeps_wordpress_url(): void {
		update_user_meta( $this->author_id, 'bn_privacy_profile_visibility', 'private' );

		$this->assertNotSame( buddynext_member_url( $this->author_id ), get_author_posts_url( $this->author_id ) );
	}

	/**
	 * The author can always follow their own byline to their profile.
	 *
	 * @return void
	 */
	public function test_owner_still_reaches_own_private_profile(): void {
		update_user_meta( $this->author_id, 'bn_privacy_profile_visibility', 'private' );
		wp_set_current_user( $this->author_id );

		$this->assertSame(
			buddynext_member_url( $this->author_id ),
			get_author_posts_url( $this->author_id )
		);
	}

	/**
	 * Sites can hand the WordPress URL back with one filter.
	 *
	 * @return void
	 */
	public function test_developer_filter_opts_out(): void {
		add_filter( 'buddynext_author_link_to_profile', '__return_false' );

		$this->assertNotSame( buddynext_member_url( $this->author_id ), get_author_posts_url( $this->author_id ) );
	}

	/**
	 * Admin screens keep the WordPress URL.
	 *
	 * @return void
	 */
	public function test_admin_context_keeps_wordpress_url(): void {
		set_current_screen( 'dashboard' );

		$this->assertNotSame( buddynext_member_url( $this->author_id ), get_author_posts_url( $this->author_id ) );
	}

	/**
	 * The owner's blog Integrations nav switch turns the rewrite off with the Articles tab.
	 *
	 * @return void
	 */
	public function test_owner_switch_turns_the_rewrite_off_with_the_tab(): void {
		update_option( 'buddynext_integration_blog_nav', '0' );

		$this->assertNotSame( buddynext_member_url( $this->author_id ), get_author_posts_url( $this->author_id ) );

		delete_option( 'buddynext_integration_blog_nav' );
	}

	/**
	 * Without Member Blog the profile lists no posts, so a byline keeps the
	 * WordPress author archive, which does.
	 *
	 * @return void
	 */
	public function test_without_member_blog_bylines_keep_the_author_archive(): void {
		$this->assertFalse( defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) );
		$this->assertNotSame( buddynext_member_url( $this->author_id ), get_author_posts_url( $this->author_id ) );
		$this->assertStringContainsString( 'author', get_author_posts_url( $this->author_id ) );
	}
}
