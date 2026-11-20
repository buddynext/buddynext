<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Member Blog's Add new post and My Blog Dashboard pages render in the shell.
 *
 * Runs in separate processes: the bridge is live only while Member Blog's
 * version constant and functions exist, and neither can be removed again for
 * the rest of the suite.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\MemberBlogBridge;
use BuddyNext\Core\HubRegistry;
use BuddyNext\Feed\ArticleProvider;

/**
 * @covers \BuddyNext\Bridges\MemberBlogBridge
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberBlogWriteHubTest extends \WP_UnitTestCase {

	private MemberBlogBridge $bridge;
	private int $post_page;
	private int $dashboard_page;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_VERSION', 'test' );
		}
		// Member Blog's two public functions the hub reads, stubbed (this process only).
		if ( ! function_exists( 'bp_member_blog_get_settings' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stubs of a partner API, defined in the child process.
			eval(
				'function bp_member_blog_get_settings( $key = "", $default = 0 ) { return $GLOBALS["bn_test_mb_settings"][ $key ] ?? $default; }
				function bp_member_blog_user_can_create( $user_id ) { return (bool) apply_filters( "bpmb_can_create_post", in_array( (int) $user_id, $GLOBALS["bn_test_mb_writers"] ?? array(), true ), (int) $user_id ); }'
			);
		}

		$this->set_permalink_structure( '/%postname%/' );

		$this->post_page      = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Add new post',
				'post_content' => '[bp-member-blog]',
			)
		);
		$this->dashboard_page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'My Blog Dashboard',
				'post_content' => '[member-blog-dashboard]',
			)
		);

		$GLOBALS['bn_test_mb_settings'] = array(
			'bp_post_page'      => $this->post_page,
			'bp_dashboard_page' => $this->dashboard_page,
		);
		$GLOBALS['bn_test_mb_writers']  = array();

		$this->bridge = new MemberBlogBridge();
	}

	private function writer(): int {
		$uid                             = self::factory()->user->create();
		$GLOBALS['bn_test_mb_writers'][] = $uid;
		return $uid;
	}

	/**
	 * A writer Member Blog allows, held by BuddyNext: 'suspended' or 'pending'.
	 *
	 * @param string $hold Hold.
	 */
	private function held_writer( string $hold ): int {
		$uid = $this->writer();
		if ( 'suspended' === $hold ) {
			$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $uid, 1, 'phpunit' ) );
		} else {
			update_user_meta( $uid, 'bn_pending_approval', '1' );
		}
		return $uid;
	}

	private function render_hub(): string {
		ob_start();
		buddynext_get_template( 'blog/write-hub.php' );
		return (string) ob_get_clean();
	}

	private function visit( int $page_id ): void {
		$this->go_to( get_permalink( $page_id ) );
		set_query_var( 'bn_hub', '' );
	}

	public function test_the_hub_is_registered_and_resolves_only_once_routed(): void {
		$reg = new HubRegistry();
		$this->bridge->register_write_hub( $reg );

		$hub = $reg->get( MemberBlogBridge::WRITE_HUB );
		$this->assertNotNull( $hub );
		$this->assertFalse( $hub->backing_page );
		$this->assertFalse( $hub->admin_managed );
		$this->assertSame( '', $hub->default_slug, 'A slug would let the isolation mu-plugin strip Member Blog from its own URL.' );
		$this->assertNull( ( $hub->resolve_template )( MemberBlogBridge::WRITE_HUB ), 'A bare ?bn_hub=blogwrite must not render the hub.' );

		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertSame( 'blog/write-hub.php', ( $hub->resolve_template )( MemberBlogBridge::WRITE_HUB ) );
	}

	public function test_both_mapped_pages_route_into_the_hub(): void {
		wp_set_current_user( $this->writer() );

		foreach ( array( $this->post_page, $this->dashboard_page ) as $page_id ) {
			$this->visit( $page_id );
			$this->bridge->route_write_pages();
			$this->assertSame( MemberBlogBridge::WRITE_HUB, get_query_var( 'bn_hub' ) );
			$this->assertSame( $page_id, MemberBlogBridge::write_hub_page()->ID );
		}
	}

	public function test_member_blog_links_with_query_args_stay_in_the_hub(): void {
		wp_set_current_user( $this->writer() );

		// Shapes Member Blog itself links to: Edit (post_id), dashboard sub-tabs, row actions.
		$cases = array(
			array( $this->post_page, array( 'post_id' => 1 ) ),
			array(
				$this->post_page,
				array(
					'post_id' => 1,
					'action'  => 'edit',
				),
			),
			array( $this->dashboard_page, array( 'tab' => 'drafts' ) ),
			array( $this->dashboard_page, array( 'tab' => 'analytics' ) ),
		);
		foreach ( $cases as $case ) {
			$this->go_to( add_query_arg( $case[1], get_permalink( $case[0] ) ) );
			set_query_var( 'bn_hub', '' );
			$this->bridge->route_write_pages();
			$this->assertSame( MemberBlogBridge::WRITE_HUB, get_query_var( 'bn_hub' ), 'Query args: ' . wp_json_encode( $case[1] ) );
			$this->assertSame( $case[0], MemberBlogBridge::write_hub_page()->ID );
		}
	}

	public function test_the_entry_reaches_the_mobile_more_sheet(): void {
		// The More sheet (templates/partials/nav.php) lists ShellNavService::resolve(),
		// which applies buddynext_rail_items: the same list the desktop rail renders.
		// The plugin's own boot wires the bridge, so no filter is added here.
		wp_set_current_user( $this->writer() );

		$items = buddynext_service( 'shell_nav' )->resolve( get_current_user_id(), '' )['items'];
		$write = array_values( array_filter( $items, static fn( array $i ): bool => 'write-post' === $i['key'] ) );
		$this->assertCount( 1, $write );
		$this->assertSame( 'you', $write[0]['group'] );
		$this->assertSame( 'Write a post', $write[0]['label'] );
	}

	public function test_an_ordinary_page_is_left_alone(): void {
		wp_set_current_user( $this->writer() );
		$this->visit( self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		$this->bridge->route_write_pages();
		$this->assertSame( '', get_query_var( 'bn_hub' ) );
	}

	public function test_the_nav_switch_off_keeps_the_theme_page(): void {
		update_option( 'buddynext_integration_blog_nav', '0' );
		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertSame( '', get_query_var( 'bn_hub' ) );
	}

	public function test_the_opt_out_filter_keeps_the_theme_page(): void {
		add_filter( 'buddynext_blog_write_in_shell', '__return_false' );
		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertSame( '', get_query_var( 'bn_hub' ) );
	}

	public function test_an_existing_hub_is_left_alone(): void {
		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		set_query_var( 'bn_hub', 'feed' );
		$this->bridge->route_write_pages();
		$this->assertSame( 'feed', get_query_var( 'bn_hub' ) );
	}

	public function test_a_guest_is_sent_to_sign_in_and_back(): void {
		wp_set_current_user( 0 );
		$this->visit( $this->post_page );

		$location = '';
		add_filter(
			'wp_redirect',
			static function ( $to ) use ( &$location ) {
				$location = (string) $to;
				throw new \RuntimeException( 'redirected' );
			}
		);
		try {
			$this->bridge->route_write_pages();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertNotSame( '', $location, 'A guest must be redirected.' );
		$this->assertStringContainsString( 'redirect_to=', $location );
		$this->assertStringContainsString( rawurlencode( (string) wp_parse_url( get_permalink( $this->post_page ), PHP_URL_PATH ) ), $location );
		$this->assertSame( '', get_query_var( 'bn_hub' ) );
	}

	public function test_a_guest_on_an_edit_link_returns_to_that_post(): void {
		wp_set_current_user( 0 );
		$url = add_query_arg( 'post_id', 42, get_permalink( $this->post_page ) );
		$this->go_to( $url );
		set_query_var( 'bn_hub', '' );
		$_GET['post_id'] = '42';

		$location = '';
		add_filter(
			'wp_redirect',
			static function ( $to ) use ( &$location ) {
				$location = (string) $to;
				throw new \RuntimeException( 'redirected' );
			}
		);
		try {
			$this->bridge->route_write_pages();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		unset( $_GET['post_id'] );

		$this->assertStringContainsString( rawurlencode( $url ), $location, 'The return address keeps ?post_id=.' );
	}

	public function test_the_rail_item_shows_only_to_a_writer(): void {
		$keys = fn(): array => wp_list_pluck( $this->bridge->add_write_rail_item( array(), MemberBlogBridge::WRITE_HUB ), 'key' );

		wp_set_current_user( 0 );
		$this->assertNotContains( 'write-post', $keys(), 'Guest.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertNotContains( 'write-post', $keys(), 'Non-writer.' );

		wp_set_current_user( $this->writer() );
		$items = $this->bridge->add_write_rail_item( array(), MemberBlogBridge::WRITE_HUB );
		$this->assertSame( 'write-post', $items[0]['key'] );
		$this->assertSame( 'you', $items[0]['group'] );
		$this->assertSame( 208, $items[0]['order'] );
		$this->assertTrue( $items[0]['active'] );
		$this->assertSame( get_permalink( $this->post_page ), $items[0]['url'] );

		update_option( 'buddynext_integration_blog_nav', '0' );
		$this->assertNotContains( 'write-post', $keys(), 'Nav switch off.' );
	}

	public function test_member_blog_is_the_article_provider(): void {
		$writer   = $this->writer();
		$provider = ArticleProvider::for_user( $writer );

		$this->assertSame( 'member-blog', $provider['key'] );
		$this->assertTrue( $provider['can_write'] );
		$this->assertSame( get_permalink( $this->post_page ), $provider['new_url'] );
		$this->assertSame( get_permalink( $this->dashboard_page ), $provider['my_articles_url'] );
		$this->assertSame( add_query_arg( 'tab', 'drafts', get_permalink( $this->dashboard_page ) ), $provider['drafts_url'] );
		$this->assertSame( '', $provider['title_arg'], 'Member Blog\'s editor reads no title query arg.' );
		$this->assertFalse( $provider['supports_space'] );

		// A member Member Blog does not let write still gets its payload, with can_write false.
		$reader = ArticleProvider::for_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'member-blog', $reader['key'] );
		$this->assertFalse( $reader['can_write'] );
	}

	public function test_with_the_articles_tab_off_the_fallback_has_no_rail_entry(): void {
		update_option( 'buddynext_integration_blog_nav', '0' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertSame( 'wordpress', ArticleProvider::for_user( $editor )['key'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled -- a provider slug, not the product name.
		$this->assertNull( ArticleProvider::for_user( self::factory()->user->create() ), 'Subscriber with no provider.' );

		wp_set_current_user( $editor );
		$this->assertSame( array(), $this->bridge->add_write_rail_item( array(), '' ) );
		$this->assertSame( array(), MemberBlogBridge::write_hub_tabs() );
	}

	public function test_a_provider_registered_first_wins(): void {
		add_filter(
			'buddynext_article_provider',
			static fn(): array => array(
				'key'       => 'my-editor',
				'can_write' => true,
				'new_url'   => home_url( '/write/' ),
			),
			5
		);
		$writer = $this->writer();
		$this->assertSame( 'my-editor', ArticleProvider::for_user( $writer )['key'] );

		wp_set_current_user( $writer );
		$this->assertSame( array(), $this->bridge->add_write_rail_item( array(), '' ), 'The rail entry opens Member Blog\'s write hub only.' );
	}

	public function test_tabs_are_for_writers_only(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertSame( array(), MemberBlogBridge::write_hub_tabs() );

		wp_set_current_user( $this->writer() );
		$tabs = MemberBlogBridge::write_hub_tabs();
		$this->assertSame( array( 'New post', 'My articles' ), wp_list_pluck( $tabs, 'label' ) );
		$this->assertSame( array( true, false ), wp_list_pluck( $tabs, 'current' ) );
	}

	public function test_the_tab_keeps_the_hub_title_when_member_blog_relabels_an_edit_page(): void {
		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();

		$parts = $this->bridge->write_document_title_parts( array( 'title' => 'Edit post' ) );
		$this->assertSame( 'Write a post', $parts['title'] );

		// Outside the hub nothing changes.
		( new \ReflectionProperty( MemberBlogBridge::class, 'write_page' ) )->setValue( null, 0 );
		$this->assertSame( array( 'title' => 'Edit post' ), $this->bridge->write_document_title_parts( array( 'title' => 'Edit post' ) ) );
	}

	public function test_member_blog_assets_pass_asset_isolation_on_the_write_hub_only(): void {
		// A site with isolation on: every non-allowlisted style/script is dequeued on a BuddyNext route.
		if ( ! defined( 'BUDDYPRESS_MEMBER_BLOG_PLUGIN_URL' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_PLUGIN_URL', 'https://example.org/wp-content/plugins/buddypress-member-blog/' );
		}
		$base = array( 'https://example.org/wp-includes/' );

		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$allowed = $this->bridge->allow_member_blog_assets( $base );
		$this->assertContains( BUDDYPRESS_MEMBER_BLOG_PLUGIN_URL, $allowed, 'On the hub the form\'s own assets are let through.' );
		$this->assertContains( 'https://example.org/wp-includes/', $allowed );

		( new \ReflectionProperty( MemberBlogBridge::class, 'write_page' ) )->setValue( null, 0 );
		$this->assertSame( $base, $this->bridge->allow_member_blog_assets( $base ), 'Anywhere else the allowlist is untouched.' );
	}

	public function test_a_non_writer_on_the_dashboard_sees_my_reading_not_write_a_post(): void {
		// Someone Member Blog does not let write sees its reading view on the dashboard page.
		wp_set_current_user( self::factory()->user->create() );
		$this->visit( $this->dashboard_page );
		$this->bridge->route_write_pages();
		$this->assertSame( 'My reading', MemberBlogBridge::write_hub_heading() );

		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertSame( 'Write a post', MemberBlogBridge::write_hub_heading(), 'The new post page is always the writing page.' );

		wp_set_current_user( $this->writer() );
		$this->visit( $this->dashboard_page );
		$this->bridge->route_write_pages();
		$this->assertSame( 'Write a post', MemberBlogBridge::write_hub_heading(), 'A writer keeps the heading and both tabs.' );
	}

	public function test_the_global_post_is_restored_after_enqueue(): void {
		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();

		$shell_post      = new \WP_Post(
			(object) array(
				'ID'        => 0,
				'post_type' => 'page',
			)
		);
		$GLOBALS['post'] = $shell_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->bridge->lend_page_to_enqueue();
		$this->assertSame( $this->post_page, $GLOBALS['post']->ID, 'Member Blog sees its own page while it enqueues.' );

		$this->bridge->restore_after_enqueue();
		$this->assertSame( $shell_post, $GLOBALS['post'] );
	}

	public function test_write_pages_always_full_load(): void {
		$deny = $this->bridge->deny_client_nav( array() );
		$this->assertSame(
			array( wp_parse_url( get_permalink( $this->post_page ), PHP_URL_PATH ), wp_parse_url( get_permalink( $this->dashboard_page ), PHP_URL_PATH ) ),
			$deny['blog-write']
		);

		set_query_var( 'bn_hub', MemberBlogBridge::WRITE_HUB );
		$this->assertFalse( $this->bridge->no_client_nav_on_write_hub( true ) );
		set_query_var( 'bn_hub', 'feed' );
		$this->assertTrue( $this->bridge->no_client_nav_on_write_hub( true ) );
	}

	public function test_member_blog_filters_refuse_held_members_only(): void {
		$writer = $this->writer();
		$this->assertTrue( apply_filters( 'bpmb_can_create_post', true, $writer ) );
		$this->assertTrue( apply_filters( 'bpmb_can_edit_post', true, 1, $writer ) );

		foreach ( array( 'suspended', 'pending' ) as $hold ) {
			$held = $this->held_writer( $hold );
			$this->assertSame( $hold, MemberBlogBridge::write_hold( $held ) );
			$this->assertFalse( apply_filters( 'bpmb_can_create_post', true, $held ), "Create, $hold." );
			$this->assertFalse( apply_filters( 'bpmb_can_edit_post', true, 1, $held ), "Edit, $hold." );
		}

		// Never widens: a member Member Blog refused stays refused.
		$this->assertFalse( apply_filters( 'bpmb_can_create_post', false, $writer ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $admin, 'bn_pending_approval', '1' );
		$this->assertSame( '', MemberBlogBridge::write_hold( $admin ), 'Site admins are exempt, as in PermissionService.' );
	}

	public function test_held_members_get_no_writing_doorway(): void {
		foreach ( array( 'suspended', 'pending' ) as $hold ) {
			$held     = $this->held_writer( $hold );
			$provider = ArticleProvider::for_user( $held );
			$this->assertSame( 'member-blog', $provider['key'] );
			$this->assertFalse( $provider['can_write'], "Provider, $hold." );
			$this->assertFalse( ArticleProvider::show_composer_tab( $held, array( 'surface' => 'feed' ) ), "Composer tab, $hold." );

			wp_set_current_user( $held );
			$this->assertSame( array(), $this->bridge->add_write_rail_item( array(), '' ), "Rail, $hold." );
			$this->visit( $this->post_page );
			$this->bridge->route_write_pages();
			$this->assertSame( array(), MemberBlogBridge::write_hub_tabs(), "Tabs, $hold." );
		}
	}

	public function test_a_held_member_sees_the_notice_not_the_form(): void {
		$cases = array(
			'suspended' => 'Your account is suspended, so you cannot post right now.',
			'pending'   => 'Your account is awaiting administrator approval.',
		);
		foreach ( $cases as $hold => $text ) {
			wp_set_current_user( $this->held_writer( $hold ) );
			foreach ( array( $this->post_page, $this->dashboard_page ) as $page_id ) {
				$this->visit( $page_id );
				$this->bridge->route_write_pages();
				$html = $this->render_hub();
				$this->assertStringContainsString( 'Write a post</h1>', $html );
				$this->assertStringContainsString( $text, $html, "$hold on page $page_id." );
				$this->assertStringNotContainsString( 'bn-blog-write__tabs', $html );
				$this->assertStringNotContainsString( '[bp-member-blog]', $html, 'The form is not rendered.' );
				$this->assertStringNotContainsString( '[member-blog-dashboard]', $html );
			}
		}
	}

	public function test_a_writer_sees_the_page(): void {
		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertNull( MemberBlogBridge::write_hub_notice() );
		$this->assertStringContainsString( 'bn-blog-write__tabs', $this->render_hub() );
	}

	public function test_an_edit_link_to_a_missing_or_foreign_post_says_not_found(): void {
		$writer = $this->writer();
		wp_set_current_user( $writer );
		$own     = self::factory()->post->create( array( 'post_author' => $writer ) );
		$foreign = self::factory()->post->create( array( 'post_author' => self::factory()->user->create() ) );

		foreach ( array(
			$own     => false,
			$foreign => true,
			999999   => true,
		) as $post_id => $not_found ) {
			$_GET['post_id'] = (string) $post_id;
			$this->go_to( add_query_arg( 'post_id', $post_id, get_permalink( $this->post_page ) ) );
			set_query_var( 'bn_hub', '' );
			$this->bridge->route_write_pages();
			$notice = MemberBlogBridge::write_hub_notice();
			if ( $not_found ) {
				$this->assertSame( 'That post could not be found.', $notice['title'] ?? '', "Post $post_id." );
				$this->assertSame( get_permalink( $this->dashboard_page ), $notice['cta_url'] );
			} else {
				$this->assertNull( $notice, 'The author edits their own post.' );
			}
		}

		// The dashboard ignores post_id.
		$this->go_to( add_query_arg( 'post_id', 999999, get_permalink( $this->dashboard_page ) ) );
		set_query_var( 'bn_hub', '' );
		$this->bridge->route_write_pages();
		$this->assertNull( MemberBlogBridge::write_hub_notice() );
		unset( $_GET['post_id'] );
	}

	public function test_the_browser_tab_matches_the_page(): void {
		wp_set_current_user( $this->writer() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertSame( 'Write a post', $this->bridge->write_document_title( 'Add new post', MemberBlogBridge::WRITE_HUB ) );

		$this->visit( $this->dashboard_page );
		$this->bridge->route_write_pages();
		$this->assertSame( 'My articles', $this->bridge->write_document_title( 'My Blog Dashboard', MemberBlogBridge::WRITE_HUB ) );
		$this->assertSame( 'Feed', $this->bridge->write_document_title( 'Feed', 'feed' ) );
	}

	private function visit_with( int $page_id, array $args ): void {
		$_GET = $args;
		$this->go_to( add_query_arg( rawurlencode_deep( $args ), get_permalink( $page_id ) ) );
		$_GET = $args;
		set_query_var( 'bn_hub', '' );
		$this->bridge->route_write_pages();
	}

	public function test_the_composer_title_prefills_the_new_post_form_as_plain_text(): void {
		wp_set_current_user( $this->writer() );
		$this->visit_with( $this->post_page, array( 'bn_title' => 'Office <b>hours</b> "&" more' ) );

		$this->assertSame( 'Office hours "&" more', MemberBlogBridge::write_hub_prefill_title(), 'Tags stripped.' );
		$html = $this->render_hub();
		$this->assertStringContainsString( 'data-bn-prefill-title="Office hours &quot;&amp;&quot; more"', $html );

		$this->bridge->enqueue_title_prefill();
		$this->assertContains( '@buddynext/blog-write-prefill', wp_script_modules()->get_queue() );
		$_GET = array();
	}

	public function test_no_prefill_on_an_edit_link_for_a_non_writer_or_a_held_member(): void {
		wp_set_current_user( $this->writer() );
		$this->visit_with( $this->post_page, array( 'bn_title' => 'Hi' ) );
		$this->visit_with(
			$this->post_page,
			array(
				'bn_title' => 'Hi',
				'post_id'  => '1',
			)
		);
		$this->assertSame( '', MemberBlogBridge::write_hub_prefill_title(), 'Editing an existing post keeps its title.' );

		$this->visit_with( $this->dashboard_page, array( 'bn_title' => 'Hi' ) );
		$this->assertSame( '', MemberBlogBridge::write_hub_prefill_title(), 'Only the new post page.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->visit_with( $this->post_page, array( 'bn_title' => 'Hi' ) );
		$this->assertSame( '', MemberBlogBridge::write_hub_prefill_title(), 'Non-writer.' );
		$this->assertStringNotContainsString( 'data-bn-prefill-title', $this->render_hub() );

		wp_set_current_user( $this->held_writer( 'pending' ) );
		$this->visit_with( $this->post_page, array( 'bn_title' => 'Hi' ) );
		$this->assertSame( '', MemberBlogBridge::write_hub_prefill_title(), 'Held member.' );
		$_GET = array();
	}

	public function test_writers_get_a_way_back_to_the_feed_on_both_tabs(): void {
		wp_set_current_user( $this->writer() );
		foreach ( array( $this->post_page, $this->dashboard_page ) as $page_id ) {
			$this->visit( $page_id );
			$this->bridge->route_write_pages();
			$html = $this->render_hub();
			$this->assertStringContainsString( 'class="bn-blog-write__back" href="' . esc_url( \BuddyNext\Core\PageRouter::activity_url() ) . '"', $html );
			$this->assertStringContainsString( 'Back to What&#039;s New', $html );
		}

		wp_set_current_user( self::factory()->user->create() );
		$this->visit( $this->post_page );
		$this->bridge->route_write_pages();
		$this->assertStringNotContainsString( 'bn-blog-write__back', $this->render_hub(), 'Non-writers keep the old hub.' );
	}

	public function test_the_composer_sends_member_blog_the_title_as_bn_title(): void {
		$uid = $this->writer();
		$this->assertSame( '', ArticleProvider::for_user( $uid )['title_arg'] );

		wp_set_current_user( $uid );
		ob_start();
		buddynext_get_template(
			'partials/composer.php',
			array(
				'space_id'        => null,
				'current_user_id' => $uid,
				'article_tab'     => true,
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'action="' . esc_url( get_permalink( $this->post_page ) ) . '"', $html );
		$this->assertMatchesRegularExpression( '/class="bn-input bn-composer-article__title"[^>]*name="bn_title"/s', $html );
	}

	public function test_my_drafts_opens_the_dashboard_drafts_view_in_the_write_hub(): void {
		// There is no draft card in the composer any more: an unfinished draft is one click away under My drafts.
		$uid      = $this->writer();
		$provider = ArticleProvider::for_user( $uid );

		$this->assertSame( add_query_arg( 'tab', 'drafts', get_permalink( $this->dashboard_page ) ), $provider['drafts_url'] );
		$this->assertArrayNotHasKey( 'draft', ArticleProvider::composer_summary( $uid ), 'The composer summary is counts only.' );
	}
}
