<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The Space Blog tab: who sees it, what it lists, how it pages, the Write for
 * this Space button, the REST type filter and the write hub's "Writing for" line.
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
use BuddyNext\Core\Installer;
use BuddyNext\Feed\BlogPostListener;
use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Bridges\MemberBlogBridge
 * @covers \BuddyNext\Feed\FeedService::space_feed
 * @covers \BuddyNext\Feed\FeedController::get_space_feed
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberBlogSpaceBlogTabTest extends \WP_UnitTestCase {

	private MemberBlogBridge $bridge;
	private int $owner;
	private int $writer;
	private int $reader;
	private int $admin;
	private int $post_page;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		if ( ! defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_VERSION', 'test' );
		}
		if ( ! function_exists( 'bp_member_blog_get_settings' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stubs of a partner API, defined in the child process.
			eval(
				'function bp_member_blog_get_settings( $key = "", $default = 0 ) { return $GLOBALS["bn_test_mb_settings"][ $key ] ?? $default; }
				function bp_member_blog_user_can_create( $user_id ) { return in_array( (int) $user_id, $GLOBALS["bn_test_mb_writers"] ?? array(), true ); }'
			);
		}

		$this->set_permalink_structure( '/%postname%/' );
		$this->post_page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Add new post',
			)
		);

		$GLOBALS['bn_test_mb_settings'] = array( 'bp_post_page' => $this->post_page );

		$this->owner  = self::factory()->user->create();
		$this->writer = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->reader = self::factory()->user->create();
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$GLOBALS['bn_test_mb_writers'] = array( $this->writer );

		$this->bridge = new MemberBlogBridge();
		$_GET         = array();
	}

	public function tear_down(): void {
		$_GET = array();
		NavRegistry::instance()->reset();
		parent::tear_down();
	}

	private function space( string $type = 'open' ): int {
		$id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Blog tab ' . $type,
				'slug' => 'blog-tab-' . $type . '-' . wp_rand( 10000, 99999 ),
				'type' => $type,
			)
		);
		$this->assertIsInt( $id );
		( new SpaceMemberService() )->join( (int) $id, $this->writer );
		( new SpaceMemberService() )->join( (int) $id, $this->reader );
		return (int) $id;
	}

	private function article( int $space_id, array $extra = array() ): int {
		return (int) wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Space article ' . wp_rand(),
					'post_content' => 'Body.',
					'post_status'  => 'publish',
					'post_author'  => $this->writer,
					'meta_input'   => array( BlogPostListener::SPACE_META => $space_id ),
				),
				$extra
			)
		);
	}

	/**
	 * The space's primary tab ids for a viewer, resolved fresh.
	 *
	 * @return string[]
	 */
	private function tabs( int $space_id, int $viewer ): array {
		wp_set_current_user( $viewer );
		NavRegistry::instance()->reset();
		$ctx = new NavContext( 'space', $space_id, $viewer );
		return array_map( static fn( $i ): string => (string) $i->id, buddynext_nav( $ctx )->layer( 'primary' ) );
	}

	/**
	 * The ids of the source posts the tab lists for a viewer.
	 *
	 * @return int[]
	 */
	private function listed_posts( int $space_id, int $viewer ): array {
		wp_set_current_user( $viewer );
		$page = $this->bridge->space_articles( $space_id, $viewer, null, 50 );
		return array_map( static fn( array $p ): int => (int) ( $p['link_meta'][ BlogPostListener::META_POST_ID ] ?? 0 ), $page['items'] );
	}

	private function panel( int $space_id, int $viewer ): string {
		wp_set_current_user( $viewer );
		ob_start();
		$this->bridge->render_space_blog_panel( $space_id, $viewer );
		return (string) ob_get_clean();
	}

	public function test_tab_shows_in_an_open_space_with_an_article_for_everyone(): void {
		$space = $this->space();
		$this->article( $space );

		foreach ( array( 0, $this->reader, $this->writer, $this->owner, $this->admin ) as $viewer ) {
			$this->assertContains( 'blog', $this->tabs( $space, $viewer ), "viewer {$viewer} sees the Blog tab" );
		}
		$tabs = $this->tabs( $space, 0 );
		if ( in_array( 'discussions', $tabs, true ) ) {
			$this->assertSame( array_search( 'discussions', $tabs, true ) + 1, array_search( 'blog', $tabs, true ), 'right after Discussions' );
		}
	}

	public function test_empty_space_shows_the_tab_only_to_a_member_who_may_write(): void {
		$space = $this->space();

		$this->assertContains( 'blog', $this->tabs( $space, $this->writer ), 'a writer sees it on an empty space' );
		foreach ( array( 0, $this->reader, $this->owner ) as $viewer ) {
			$this->assertNotContains( 'blog', $this->tabs( $space, $viewer ), "viewer {$viewer} who cannot write sees no empty tab" );
		}

		update_space_meta( $space, 'who_can_post', 'owner' );
		wp_cache_flush();
		$this->assertNotContains( 'blog', $this->tabs( $space, $this->writer ), 'a writer the space does not let post sees no empty tab' );
	}

	public function test_tab_is_hidden_on_private_and_secret_spaces(): void {
		foreach ( array( 'private', 'secret' ) as $type ) {
			$space = $this->space();
			$this->article( $space );
			$this->assertTrue( ( new SpaceService() )->update( $space, $this->owner, array( 'type' => $type ) ) );
			wp_cache_flush();

			foreach ( array( $this->reader, $this->writer, $this->owner, $this->admin ) as $viewer ) {
				$this->assertNotContains( 'blog', $this->tabs( $space, $viewer ), "{$type}: viewer {$viewer} sees no Blog tab" );
			}
			$this->assertSame( '', $this->panel( $space, $this->admin ), "{$type}: the panel renders nothing" );
		}
	}

	public function test_tab_is_hidden_when_the_blog_nav_switch_is_off(): void {
		$space = $this->space();
		$this->article( $space );
		$this->assertContains( 'blog', $this->tabs( $space, $this->writer ) );

		update_option( 'buddynext_integration_blog_nav', '0' );
		$this->assertNotContains( 'blog', $this->tabs( $space, $this->writer ) );
		$this->assertNotContains( 'blog', $this->tabs( $space, 0 ) );
	}

	public function test_leak_matrix_only_the_public_live_article_is_listed(): void {
		$space  = $this->space();
		$public = $this->article( $space );
		$others = array(
			'private'   => $this->article( $space, array( 'post_status' => 'private' ) ),
			'password'  => $this->article( $space, array( 'post_password' => 'secret' ) ),
			'draft'     => $this->article( $space, array( 'post_status' => 'draft' ) ),
			'pending'   => $this->article( $space, array( 'post_status' => 'pending' ) ),
			'scheduled' => $this->article(
				$space,
				array(
					'post_status' => 'future',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				)
			),
		);
		// Published, then withdrawn by going private.
		$withdrawn = $this->article( $space );
		wp_update_post(
			array(
				'ID'          => $withdrawn,
				'post_status' => 'private',
			)
		);
		$others['withdrawn'] = $withdrawn;

		foreach ( array( 0, $this->reader, $this->writer, $this->owner, $this->admin ) as $viewer ) {
			$this->assertSame( array( $public ), $this->listed_posts( $space, $viewer ), "viewer {$viewer} sees only the public article" );
			$html = $this->panel( $space, $viewer );
			foreach ( $others as $label => $post_id ) {
				$this->assertStringNotContainsString( (string) get_the_title( $post_id ), $html, "{$label} never renders for viewer {$viewer}" );
			}
		}
	}

	public function test_keyset_paging_twenty_one_articles(): void {
		$space = $this->space();
		// Twenty-one cards in one second would trip the posting rate limit.
		add_filter( 'buddynext_rate_limit_exempt', '__return_true' );
		for ( $i = 0; $i < 21; $i++ ) {
			$this->article( $space );
		}

		$first = $this->bridge->space_articles( $space, 0, null, 20 );
		$this->assertCount( 20, $first['items'] );
		$this->assertNotNull( $first['next_cursor'] );

		$second = $this->bridge->space_articles( $space, 0, $first['next_cursor'], 20 );
		$this->assertCount( 1, $second['items'] );
		$this->assertNull( $second['next_cursor'] );
		$this->assertNotContains( $second['items'][0]['id'], array_column( $first['items'], 'id' ) );

		$html = $this->panel( $space, 0 );
		$this->assertStringContainsString( 'shown=40', $html, 'Load more grows the page' );
		$this->assertStringContainsString( '/blog/', $html );
	}

	public function test_cards_are_the_space_feed_cards(): void {
		$space = $this->space();
		$this->article( $space );
		$this->article( $space );
		( new \BuddyNext\Feed\PostService() )->create(
			$this->reader,
			array(
				'content'  => 'A plain post',
				'space_id' => $space,
			)
		);

		$feed     = buddynext_service( 'feed' )->space_feed( $space, $this->reader, null, 20 );
		$articles = array_values( array_filter( $feed['items'], static fn( array $p ): bool => BlogPostListener::TYPE === $p['type'] ) );
		$this->assertCount( 2, $articles );
		$this->assertSame( $articles, $this->bridge->space_articles( $space, $this->reader, null, 20 )['items'], 'identical hydrated cards' );

		$html = $this->panel( $space, $this->reader );
		$this->assertSame( 2, substr_count( $html, 'class="bn-article-card"' ), 'the shared article card, once per article' );
		$this->assertStringNotContainsString( 'A plain post', $html );
	}

	public function test_write_button_carries_bn_space_and_shows_only_to_members_who_may_write(): void {
		$space = $this->space();
		$this->article( $space );

		$url = MemberBlogBridge::space_write_url( $space, $this->writer );
		$this->assertStringContainsString( 'bn_space=' . $space, $url );
		$this->assertStringContainsString( (string) wp_parse_url( (string) get_permalink( $this->post_page ), PHP_URL_PATH ), $url );
		$this->assertStringContainsString( 'Write for this Space', $this->panel( $space, $this->writer ) );

		foreach ( array( 0, $this->reader, $this->owner ) as $viewer ) {
			$this->assertSame( '', MemberBlogBridge::space_write_url( $space, $viewer ) );
			$this->assertStringNotContainsString( 'Write for this Space', $this->panel( $space, $viewer ), "viewer {$viewer} gets no button" );
		}

		$empty = $this->space();
		$html  = $this->panel( $empty, $this->writer );
		$this->assertStringContainsString( 'No articles in this space yet.', $html );
		$this->assertStringContainsString( 'bn_space=' . $empty, $html );
	}

	public function test_rest_type_filters_and_refuses_an_unknown_value(): void {
		$space = $this->space();
		$this->article( $space );
		( new \BuddyNext\Feed\PostService() )->create(
			$this->reader,
			array(
				'content'  => 'Plain',
				'space_id' => $space,
			)
		);
		wp_set_current_user( $this->reader );

		$all = rest_do_request( new \WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $space . '/feed' ) );
		$this->assertSame( 200, $all->get_status() );

		$request = new \WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $space . '/feed' );
		$request->set_param( 'type', 'article' );
		$typed = rest_do_request( $request );
		$this->assertSame( 200, $typed->get_status() );
		$data  = $typed->get_data();
		$items = (array) ( $data['items'] ?? $data['data']['items'] ?? $data );
		$types = array_unique( array_column( array_filter( $items, 'is_array' ), 'type' ) );
		$this->assertSame( array( 'article' ), array_values( $types ) );

		$bad = new \WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $space . '/feed' );
		$bad->set_param( 'type', 'link' );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );
	}

	public function test_type_filter_query_uses_the_type_index_and_no_count(): void {
		global $wpdb;
		$space = $this->space();
		$this->article( $space );

		$captured = array();
		$capture  = static function ( $sql ) use ( &$captured ) {
			if ( false !== strpos( (string) $sql, 'bn_posts' ) && false !== strpos( (string) $sql, "type = 'article'" ) ) {
				$captured[] = (string) $sql;
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		$this->bridge->space_articles( $space, 0, null, 20 );
		remove_filter( 'query', $capture );

		$this->assertCount( 1, $captured, 'one query' );
		$sql = $captured[0];
		$this->assertStringContainsString( 'space_id = ' . $space, $sql );
		$this->assertStringContainsString( "status = 'published'", $sql );
		$this->assertStringContainsString( 'ORDER BY created_at DESC, id DESC', $sql );
		$this->assertStringContainsString( 'LIMIT 21', $sql );
		$this->assertStringNotContainsString( 'COUNT(', strtoupper( $sql ) );

		$plan = $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- EXPLAIN of the captured, already prepared query.
		$this->assertStringContainsString( 'space_type_feed', (string) ( $plan['possible_keys'] ?? '' ) );
	}

	private function hub_for( int $space_id ): string {
		wp_set_current_user( $this->writer );
		$_GET = array( 'bn_space' => (string) $space_id );
		$this->go_to( add_query_arg( 'bn_space', $space_id, get_permalink( $this->post_page ) ) );
		set_query_var( 'bn_hub', '' );
		$this->bridge->route_write_pages();
		ob_start();
		buddynext_get_template( 'blog/write-hub.php' );
		return (string) ob_get_clean();
	}

	public function test_hub_says_where_the_article_will_appear_for_a_valid_space_only(): void {
		$space = $this->space();
		$html  = $this->hub_for( $space );
		$this->assertStringContainsString( 'Writing for Blog tab open', $html );
		$this->assertStringContainsString( 'blog-tab-open-', $html );
		$this->assertStringContainsString( '/blog/', $html );

		$private = $this->space( 'private' );
		$archive = $this->space();
		( new SpaceService() )->archive( $archive, $this->owner );
		$foreign = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Not joined',
				'slug' => 'not-joined-' . wp_rand( 10000, 99999 ),
				'type' => 'open',
			)
		);
		wp_cache_flush();
		foreach ( array( 999999, $private, $archive, (int) $foreign ) as $forged ) {
			$this->assertStringNotContainsString( 'Writing for', $this->hub_for( $forged ), "space {$forged} shows nothing" );
		}
	}

	private function card_space( int $post_id ): int {
		global $wpdb;
		$card = BlogPostListener::card_id_for_post( $post_id );
		$this->assertGreaterThan( 0, $card, 'The article has a card.' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(space_id,0) FROM {$wpdb->prefix}bn_posts WHERE id = %d", $card ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_the_articles_in_spaces_switch_hides_every_space_entry_and_restores_on_turning_back_on(): void {
		$space = $this->space();
		$this->article( $space );
		$this->assertTrue( BlogPostListener::spaces_enabled(), 'On by default.' );
		$this->assertContains( 'blog', $this->tabs( $space, $this->writer ) );
		$this->assertNotSame( '', MemberBlogBridge::space_write_url( $space, $this->writer ) );

		$entry = buddynext_integrations()['blog'];
		$this->assertArrayHasKey( 'spaces', $entry['subtab_options'], 'Declared as an option card.' );
		$this->assertArrayNotHasKey( 'spaces', $entry['subtabs_unavailable'], 'Available while Member Blog is active.' );

		update_option( 'buddynext_integration_blog_subtab_spaces', '0' );
		$this->assertFalse( BlogPostListener::spaces_enabled() );
		$this->assertNotContains( 'blog', $this->tabs( $space, $this->writer ), 'No tab for a writer.' );
		$this->assertNotContains( 'blog', $this->tabs( $space, 0 ), 'No tab for a guest, even with an article.' );
		$this->assertSame( '', MemberBlogBridge::space_write_url( $space, $this->writer ), 'No Write for this Space link.' );
		$this->assertSame( '', $this->panel( $space, $this->writer ), 'The panel renders nothing.' );
		$this->assertFalse( BlogPostListener::space_accepts_article( $space, $this->writer ), 'A new association is refused.' );
		$this->assertStringNotContainsString( 'Writing for', $this->hub_for( $space ), 'The write form ignores ?bn_space.' );

		update_option( 'buddynext_integration_blog_subtab_spaces', '1' );
		$this->assertContains( 'blog', $this->tabs( $space, $this->writer ), 'Restored.' );
		$this->assertStringContainsString( 'Writing for', $this->hub_for( $space ) );
	}

	public function test_turning_the_switch_off_moves_only_open_space_cards_site_wide_and_back_on_restores_them(): void {
		$open    = $this->space();
		$private = $this->space( 'private' );
		$in_open = $this->article( $open );
		$in_priv = $this->article( $private );
		$this->assertSame( $open, $this->card_space( $in_open ) );
		$this->assertSame( $private, $this->card_space( $in_priv ) );

		update_option( 'buddynext_integration_blog_subtab_spaces', '0' );
		( new BlogPostListener() )->resync_space_cards( 0 );
		$this->assertSame( 0, $this->card_space( $in_open ), 'The open Space card is site-wide (it was already public).' );
		$this->assertSame( $private, $this->card_space( $in_priv ), 'A private Space card never leaves its Space.' );
		$this->assertSame( (string) $open, (string) get_post_meta( $in_open, BlogPostListener::SPACE_META, true ), 'Nothing is deleted: the association is kept.' );

		( new BlogPostListener() )->resync_space_cards( 0 );
		$this->assertSame( 0, $this->card_space( $in_open ), 'Idempotent.' );

		update_option( 'buddynext_integration_blog_subtab_spaces', '1' );
		( new BlogPostListener() )->resync_space_cards( 0 );
		$this->assertSame( $open, $this->card_space( $in_open ), 'Turning it back on restores the Space.' );
		$this->assertSame( $private, $this->card_space( $in_priv ) );
	}

	private function articles_tab( int $space_id, string $value, ?int $actor = null ): array {
		$registry = \BuddyNext\Spaces\SpaceFieldRegistry::instance();
		$this->bridge->register_space_fields( $registry );
		return $registry->save_for_space( $space_id, array( MemberBlogBridge::SPACE_FIELD => $value ), $actor ?? $this->owner );
	}

	public function test_the_per_space_articles_tab_switch_affects_only_that_space(): void {
		$a = $this->space();
		$b = $this->space();
		$this->article( $a );
		$this->article( $b );
		$this->assertTrue( BlogPostListener::space_enabled( $a ), 'On by default.' );

		$result = $this->articles_tab( $a, '0' );
		$this->assertSame( array(), $result['errors'] );
		$this->assertFalse( BlogPostListener::space_enabled( $a ) );
		$this->assertTrue( BlogPostListener::space_enabled( $b ), 'The other Space is untouched.' );

		$this->assertNotContains( 'blog', $this->tabs( $a, $this->writer ), 'No tab in the Space that is off.' );
		$this->assertNotContains( 'blog', $this->tabs( $a, 0 ) );
		$this->assertContains( 'blog', $this->tabs( $b, $this->writer ), 'The other Space keeps its tab.' );
		$this->assertSame( '', MemberBlogBridge::space_write_url( $a, $this->writer ), 'No Write for this Space for that Space.' );
		$this->assertNotSame( '', MemberBlogBridge::space_write_url( $b, $this->writer ) );
		$this->assertFalse( BlogPostListener::space_accepts_article( $a, $this->writer ), 'A new association is refused.' );
		$this->assertTrue( BlogPostListener::space_accepts_article( $b, $this->writer ) );
		$this->assertStringNotContainsString( 'Writing for', $this->hub_for( $a ) );
		$this->assertStringContainsString( 'Writing for', $this->hub_for( $b ) );

		$this->articles_tab( $a, '1' );
		$this->assertTrue( BlogPostListener::space_enabled( $a ), 'Turning it back on restores it.' );
	}

	public function test_the_field_is_read_and_written_through_the_space_fields_like_the_other_tab_switches(): void {
		$space    = $this->space();
		$registry = \BuddyNext\Spaces\SpaceFieldRegistry::instance();
		$this->bridge->register_space_fields( $registry );
		$field = $registry->get_field( MemberBlogBridge::SPACE_FIELD );
		$this->assertSame( 'boolean', $field['type'] );
		$this->assertSame( '1', (string) $field['default'], 'Default on.' );
		$this->assertSame( 'integrations', $field['section'] );

		$by_member = $registry->save_for_space( $space, array( MemberBlogBridge::SPACE_FIELD => '0' ), $this->reader );
		$this->assertNotEmpty( $by_member['errors'], 'A member who is not the owner cannot change it.' );
		$this->assertTrue( BlogPostListener::space_enabled( $space ) );
	}

	public function test_only_a_real_change_queues_the_per_space_resync_and_it_moves_only_that_open_space(): void {
		$a       = $this->space();
		$b       = $this->space();
		$private = $this->space( 'private' );
		$in_a    = $this->article( $a );
		$in_b    = $this->article( $b );
		$in_priv = $this->article( $private );

		$seen = array();
		add_action(
			'buddynext_space_fields_saved',
			static function ( $space_id, $saved, $changed ) use ( &$seen ) {
				$seen[] = (array) $changed;
			},
			10,
			3
		);
		$this->articles_tab( $a, '0' );
		$this->assertContains( MemberBlogBridge::SPACE_FIELD, $seen[0], 'A real change is reported.' );
		$this->articles_tab( $a, '0' );
		$this->assertNotContains( MemberBlogBridge::SPACE_FIELD, $seen[1], 'Saving the same value is not a change.' );

		( new BlogPostListener() )->resync_space_cards( 0, $a );
		$this->assertSame( 0, $this->card_space( $in_a ), 'The Space that is off: card is site-wide.' );
		$this->assertSame( $b, $this->card_space( $in_b ), 'Another open Space is not touched.' );
		$this->assertSame( $private, $this->card_space( $in_priv ), 'A private Space card never moves.' );

		$this->articles_tab( $a, '1' );
		( new BlogPostListener() )->resync_space_cards( 0, $a );
		$this->assertSame( $a, $this->card_space( $in_a ), 'On again restores it.' );
	}

	public function test_the_blog_tab_visibility_filter_has_the_last_word(): void {
		$space = $this->space();
		$this->assertNotContains( 'blog', $this->tabs( $space, $this->reader ), 'Empty Space, a reader who may not write: hidden by default.' );

		$show = static fn( $default, $space_id, $viewer ) => true;
		add_filter( 'buddynext_space_blog_tab_visible', $show, 10, 3 );
		$this->assertContains( 'blog', $this->tabs( $space, $this->reader ), 'The filter can always show it.' );
		remove_filter( 'buddynext_space_blog_tab_visible', $show, 10 );

		$this->article( $space );
		$hide = static fn( $default, $space_id, $viewer ) => false;
		add_filter( 'buddynext_space_blog_tab_visible', $hide, 10, 3 );
		$this->assertNotContains( 'blog', $this->tabs( $space, $this->writer ), 'And hide it even with an article.' );
		remove_filter( 'buddynext_space_blog_tab_visible', $hide, 10 );

		$private = $this->space( 'private' );
		add_filter( 'buddynext_space_blog_tab_visible', '__return_true' );
		$this->assertNotContains( 'blog', $this->tabs( $private, $this->writer ), 'Never on a private Space, whatever the filter says.' );
		remove_filter( 'buddynext_space_blog_tab_visible', '__return_true' );
	}
}
