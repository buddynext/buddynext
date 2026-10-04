<?php
/**
 * A directory page past the last one is a 404, as /blog/page/999/ is in core.
 *
 * /members/page/999/ answered 200 with "No members match your filters": a soft
 * 404 for crawlers, reachable from /page/N/ links indexed since 1.2.2. The
 * router decides before output, through the same request method each template
 * renders from.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Spaces\SpaceService;

/**
 * Past-the-end gate.
 *
 * @covers \BuddyNext\Core\PageRouter::is_past_last_page
 * @covers \BuddyNext\Profile\MemberDirectoryService::ssr_request
 * @covers \BuddyNext\Spaces\SpaceService::directory_request
 * @covers \BuddyNext\Notifications\NotificationService::inbox_page
 */
class PastLastPageTest extends \WP_UnitTestCase {

	/**
	 * Pretty permalinks with the plugin's rules, re-registered per test (they
	 * hook `init`, which fires once per process).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_cache_flush();

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		( new PageRouter() )->register_rewrites();
		$wp_rewrite->flush_rules( false );
	}

	/**
	 * Back to plain permalinks.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules( false );
		parent::tear_down();
	}

	/**
	 * Route a URL and ask the gate about it.
	 *
	 * @param string $path    Site path.
	 * @param array  $context Hub context the dispatcher would pass.
	 * @return bool
	 */
	private function past( string $path, array $context = array() ): bool {
		$this->go_to( home_url( $path ) );

		$gate = new \ReflectionMethod( PageRouter::class, 'is_past_last_page' );

		return (bool) $gate->invoke( new PageRouter(), (string) get_query_var( 'bn_hub' ), $context );
	}

	/**
	 * Members: page 1 is never past the end; a page beyond the total is.
	 *
	 * @return void
	 */
	public function test_members_page_past_the_end(): void {
		self::factory()->user->create_many( 3 );

		$this->assertFalse( $this->past( '/members/' ), 'Page 1 is an empty state at worst, never a 404.' );
		$this->assertTrue( $this->past( '/members/page/2/' ), 'Three members fit on one page of twenty.' );
		$this->assertTrue( $this->past( '/members/page/999/' ) );
	}

	/**
	 * Members: the last real page still renders.
	 *
	 * @return void
	 */
	public function test_members_last_page_renders(): void {
		self::factory()->user->create_many( 25 );

		$this->assertFalse( $this->past( '/members/page/2/' ), 'Page 2 holds members 21-25.' );
		$this->assertTrue( $this->past( '/members/page/3/' ) );
	}

	/**
	 * Members: a filter that matches nothing makes page 2 past the end too.
	 *
	 * @return void
	 */
	public function test_members_filtered_past_the_end(): void {
		self::factory()->user->create_many( 25 );
		$this->assertFalse( $this->past( '/members/page/2/' ), 'Unfiltered, page 2 is real.' );
		$this->assertTrue( $this->past( '/members/page/2/?s=zzz-nobody-matches-this' ) );
	}

	/**
	 * Spaces: a page past the list total is a 404; page 1 never is.
	 *
	 * @return void
	 */
	public function test_spaces_page_past_the_end(): void {
		$owner = self::factory()->user->create();
		( new SpaceService() )->create( $owner, array( 'name' => 'Only Space', 'slug' => 'only-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );

		$this->assertFalse( $this->past( '/spaces/' ) );
		$this->assertTrue( $this->past( '/spaces/page/2/' ) );
	}

	/**
	 * Notifications: the member's own inbox past its last page.
	 *
	 * @return void
	 */
	public function test_inbox_page_past_the_end(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( $this->past( '/notifications/' ) );
		$this->assertTrue( $this->past( '/notifications/page/2/' ) );
		$this->assertTrue( $this->past( '/notifications/page/2/?filter=mention' ) );
	}

	/**
	 * first_page() drops the page segment and ?paged, and keeps every filter.
	 *
	 * @return void
	 */
	public function test_first_page_keeps_filters_and_drops_the_page(): void {
		$this->assertSame( home_url( '/notifications/?filter=mention' ), PageRouter::first_page( home_url( '/notifications/page/2/?filter=mention' ) ) );
		$this->assertSame( home_url( '/spaces/?bn_subspaces=1' ), PageRouter::first_page( home_url( '/spaces/page/3/?bn_subspaces=1' ) ) );
		$this->assertSame( home_url( '/spaces/?bn_sort=newest' ), PageRouter::first_page( home_url( '/spaces/?bn_sort=newest&paged=4' ) ) );
		$this->assertSame( home_url( '/spaces/page-turner/' ), PageRouter::first_page( home_url( '/spaces/page-turner/' ) ), 'a slug containing "page" is not a page segment' );
	}

	/**
	 * page_url() is the one /page/N/ builder: page 1 is the bare address, any old
	 * page segment or ?paged is replaced, filters and a #fragment are kept.
	 *
	 * @return void
	 */
	public function test_page_url_builds_every_page_link_one_way(): void {
		$this->assertSame( home_url( '/members/' ), PageRouter::page_url( home_url( '/members/page/4/' ), 1 ) );
		$this->assertSame( home_url( '/members/page/2/' ), PageRouter::page_url( home_url( '/members/' ), 2 ) );
		$this->assertSame( home_url( '/members/page/3/?s=a&bn_sort=newest' ), PageRouter::page_url( home_url( '/members/page/2/?s=a&bn_sort=newest' ), 3 ) );
		$this->assertSame( home_url( '/spaces/page/2/?bn_sort=newest' ), PageRouter::page_url( home_url( '/spaces/?bn_sort=newest&paged=7' ), 2 ) );
		$this->assertSame( home_url( '/members/x/articles/page/2/#top' ), PageRouter::page_url( home_url( '/members/x/articles/#top' ), 2 ) );
		$this->assertSame( home_url( '/members/' ), PageRouter::page_url( home_url( '/members/' ), 0 ), 'below 1 is page 1' );
	}

	/**
	 * '' means the current request: absolute, on the site's host, filters kept.
	 *
	 * @return void
	 */
	public function test_page_url_from_the_current_request(): void {
		self::factory()->user->create_many( 25 );
		$this->go_to( home_url( '/members/page/2/?bn_sort=newest' ) );

		$this->assertSame( home_url( '/members/page/3/?bn_sort=newest' ), PageRouter::page_url( '', 3 ) );
		$this->assertSame( home_url( '/members/?bn_sort=newest' ), PageRouter::page_url( '', 1 ) );
	}

	/**
	 * Page N of a profile tab is canonical at its own address. The descriptor is
	 * the profile, and profile + page/2/ (/members/x/page/2/) is a 404; an
	 * address outside the descriptor (an alias) keeps the descriptor as base.
	 *
	 * @return void
	 */
	public function test_canonical_of_a_tab_page_is_its_own_address(): void {
		$user    = get_userdata( self::factory()->user->create( array( 'user_login' => 'pagerx' ) ) );
		$profile = trailingslashit( PageRouter::profile_url( $user->ID ) );

		$this->go_to( $profile . 'articles/page/2/?bn_sort=newest' );
		$this->assertSame( 2, (int) get_query_var( 'paged' ) );
		$this->assertSame(
			$profile . 'articles/page/2/',
			\BuddyNext\Core\HeadMeta::canonical_url( array( 'url' => $profile ) ),
			'the tab page, not profile + page/2/; query args left off'
		);

		$this->assertSame(
			'https://example.test/members/page/2/',
			\BuddyNext\Core\HeadMeta::canonical_url( array( 'url' => 'https://example.test/members/' ) ),
			'outside the descriptor: the descriptor stays the base'
		);
	}

	/**
	 * On inbox page 2 every tab links to page 1 of that tab (QA bounce: Mentions
	 * from /notifications/page/2/ landed on a 404).
	 *
	 * @return void
	 */
	public function test_inbox_tabs_on_page_two_link_to_page_one(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->go_to( home_url( '/notifications/page/2/' ) );

		ob_start();
		buddynext_get_template(
			'parts/notifications-filter-bar.php',
			array(
				'tabs'          => array(
					array( 'key' => 'all', 'label' => 'All' ),
					array( 'key' => 'mention', 'label' => 'Mentions' ),
				),
				'active_filter' => 'all',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'filter=mention', $html );
		$this->assertStringNotContainsString( '/page/2/', $html );
	}
}
