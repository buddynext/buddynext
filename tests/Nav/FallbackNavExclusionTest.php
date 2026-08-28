<?php
/**
 * Tests for the page-list fallback nav exclusions.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Core\Installer;
use BuddyNext\Nav\MenuRenderer;

/**
 * With no menu assigned, the theme lists every published page. BuddyNext must
 * not let that advertise its own pages to a visitor who cannot use them.
 *
 * @covers \BuddyNext\Nav\MenuRenderer::exclude_unusable_pages
 */
class FallbackNavExclusionTest extends \WP_UnitTestCase {

	/**
	 * Renderer under test.
	 *
	 * @var MenuRenderer
	 */
	private MenuRenderer $renderer;

	/**
	 * Install and boot the renderer.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->renderer = new MenuRenderer();
	}

	/**
	 * Create a page and record it as a BuddyNext hub page.
	 *
	 * @param string $option Page option name.
	 * @param string $title  Page title.
	 * @return int
	 */
	private function page_for( string $option, string $title ): int {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		update_option( $option, $page_id );

		return (int) $page_id;
	}

	/**
	 * Excluded ids for the current visitor.
	 *
	 * @return array<int,int>
	 */
	private function excluded(): array {
		return array_map( 'intval', $this->renderer->exclude_unusable_pages( array() ) );
	}

	/**
	 * A logged-out visitor is not offered pages that bounce them to /login/.
	 *
	 * @return void
	 */
	public function test_logged_out_visitors_are_not_offered_session_only_pages(): void {
		$messages      = $this->page_for( 'buddynext_page_messages', 'Messages' );
		$notifications = $this->page_for( 'buddynext_page_notifications', 'Notifications' );

		wp_set_current_user( 0 );
		$excluded = $this->excluded();

		$this->assertContains( $messages, $excluded );
		$this->assertContains( $notifications, $excluded );
	}

	/**
	 * A signed-in member is not offered a Login link.
	 *
	 * @return void
	 */
	public function test_a_signed_in_member_is_not_offered_login(): void {
		$login = $this->page_for( 'buddynext_page_login', 'Login' );

		wp_set_current_user( self::factory()->user->create() );

		$this->assertContains( $login, $this->excluded() );
	}

	/**
	 * ...and a logged-out visitor still is.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_still_offered_login(): void {
		$login = $this->page_for( 'buddynext_page_login', 'Login' );

		wp_set_current_user( 0 );

		$this->assertNotContains( $login, $this->excluded() );
	}

	/**
	 * The community hubs are never hidden — a fresh install must still surface
	 * the community with no configuration at all.
	 *
	 * @return void
	 */
	public function test_community_hubs_are_never_excluded(): void {
		$activity = $this->page_for( 'buddynext_page_activity', 'Activity' );
		$spaces   = $this->page_for( 'buddynext_page_spaces', 'Spaces' );

		foreach ( array( 0, self::factory()->user->create() ) as $viewer ) {
			wp_set_current_user( $viewer );
			$excluded = $this->excluded();
			$this->assertNotContains( $activity, $excluded );
			$this->assertNotContains( $spaces, $excluded );
		}
	}

	/**
	 * Only BuddyNext's OWN pages are ever excluded.
	 *
	 * Deciding what a site's navigation contains is the owner's job. Not
	 * advertising our own dead ends is ours — and the line between those is that
	 * we touch no page we did not create, including WordPress's Sample Page.
	 *
	 * @return void
	 */
	public function test_no_foreign_page_is_ever_excluded(): void {
		$sample  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Sample Page',
				'post_status' => 'publish',
			)
		);
		$foreign = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Shop',
				'post_status' => 'publish',
			)
		);
		$this->page_for( 'buddynext_page_messages', 'Messages' );

		wp_set_current_user( 0 );
		$excluded = $this->excluded();

		$this->assertNotContains( $sample, $excluded );
		$this->assertNotContains( $foreign, $excluded );
	}

	/**
	 * Whatever WordPress or another plugin already excluded is preserved.
	 *
	 * @return void
	 */
	public function test_existing_exclusions_are_preserved(): void {
		$this->page_for( 'buddynext_page_messages', 'Messages' );
		wp_set_current_user( 0 );

		$result = array_map( 'intval', $this->renderer->exclude_unusable_pages( array( 4242 ) ) );

		$this->assertContains( 4242, $result, 'another plugin\'s exclusion must survive' );
	}
}
