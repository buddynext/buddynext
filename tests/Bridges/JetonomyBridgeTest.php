<?php
/**
 * Tests for Jetonomy bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\JetonomyBridge
 */
class JetonomyBridgeTest extends \WP_UnitTestCase {

	private JetonomyBridge $bridge;
	private int $user_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		// Bridge reads discussion title/content/author from jt_posts —
		// Jetonomy's table that this plugin doesn't ship. Create a minimal
		// shadow table for the duration of these tests.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_posts (
				id BIGINT UNSIGNED NOT NULL,
				space_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				slug VARCHAR(200) NOT NULL DEFAULT '',
				title TEXT NULL,
				content_plain LONGTEXT NULL,
				is_private TINYINT(1) NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_spaces (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				slug VARCHAR(200) NOT NULL DEFAULT '',
				visibility VARCHAR(20) NOT NULL DEFAULT 'public',
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Plugin class stub is registered in tests/bootstrap.php.
		$this->bridge = new JetonomyBridge();
		$this->bridge->init();
		$this->user_id   = self::factory()->user->create();
		$this->author_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_posts" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_spaces" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		parent::tear_down();
	}

	/**
	 * Insert a fake Jetonomy discussion row so the bridge can read author/title/body.
	 */
	private function seed_jt_post( int $post_id, int $author_id, string $title = 'Hi', string $body = 'Body', string $slug = 'topic' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'jt_posts',
			array(
				'id'            => $post_id,
				'author_id'     => $author_id,
				'slug'          => $slug,
				'title'         => $title,
				'content_plain' => $body,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Insert a fake Jetonomy space row so the bridge can build a discussion URL.
	 */
	private function seed_jt_space( int $space_id, string $slug = 'general' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'jt_spaces',
			array(
				'id'   => $space_id,
				'slug' => $slug,
			),
			array( '%d', '%s' )
		);
	}

	public function test_discussion_indexed_with_correct_author(): void {
		global $wpdb;

		$this->seed_jt_post( 99, $this->author_id, 'Another Discussion', 'Content body.' );

		// jetonomy_after_create_post fires ($post_id, $space_id) — 2 args only.
		do_action( 'jetonomy_after_create_post', 99, 0 );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT author_id FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				99
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $this->author_id, (int) $row->author_id );
	}

	public function test_post_created_indexes_in_search(): void {
		global $wpdb;

		$this->seed_jt_post( 20, $this->user_id, 'Test Discussion Title', 'Body content here.' );

		do_action( 'jetonomy_after_create_post', 20, 0 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				20
			)
		);

		$this->assertSame( 1, $count );
	}

	public function test_discussion_create_publishes_feed_activity(): void {
		global $wpdb;

		$this->seed_jt_space( 5, 'general' );
		$this->seed_jt_post( 30, $this->author_id, 'Welcome', 'Body', 'welcome-thread' );

		do_action( 'jetonomy_after_create_post', 30, 5 );

		$expected_url = home_url( '/community' ) . '/s/general/t/welcome-thread/';
		$activity     = (int) $wpdb->get_var(
			$wpdb->prepare(
				// The bridge stores the discussion activity as type 'discussion' (so
				// remove() matches it on soft-delete), not the generic 'link'.
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'discussion' AND link_url = %s",
				$this->author_id,
				$expected_url
			)
		);
		$this->assertSame( 1, $activity );

		// Soft-delete (trash) WITHDRAWS it reversibly: the row is preserved but
		// moved to 'draft' so it leaves every feed, and a restore brings the same
		// card back with its comments (card 10320560928). jt_posts/jt_spaces rows
		// still present, so the URL still resolves.
		do_action( 'jetonomy_post_deleted', 30, 5, $this->author_id );
		$still_there = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE link_url = %s", $expected_url )
		);
		$published = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE link_url = %s AND status = 'published'", $expected_url )
		);
		$this->assertSame( 1, $still_there, 'the card row is preserved on a soft delete (withdrawn, not removed)' );
		$this->assertSame( 0, $published, 'the withdrawn card leaves every feed' );
	}

	/**
	 * A PERMANENT purge (Post::delete fires jetonomy_after_delete_post) removes the
	 * card outright — the source discussion is gone for good — and cascades the
	 * comments members left on it, rather than orphaning them. The card is found by
	 * the post_id stamped in its link_meta, because by the time the action fires the
	 * jt_posts/jt_spaces rows the URL is built from are already deleted.
	 *
	 * @return void
	 */
	public function test_hard_delete_purges_the_card_and_its_comments(): void {
		global $wpdb;

		$this->seed_jt_space( 7, 'purge' );
		$this->seed_jt_post( 40, $this->author_id, 'Doomed', 'Body', 'doomed-thread' );
		do_action( 'jetonomy_after_create_post', 40, 7 );

		$card_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE type = 'discussion' AND CAST( JSON_UNQUOTE( JSON_EXTRACT( link_meta, '$.post_id' ) ) AS UNSIGNED ) = %d", 40 )
		);
		$this->assertGreaterThan( 0, $card_id, 'the card is stamped with its post_id in link_meta' );

		// A member commented on the card.
		$commenter = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array( 'object_type' => 'post', 'object_id' => $card_id, 'user_id' => $commenter, 'content' => 'great thread' ),
			array( '%s', '%d', '%d', '%s' )
		);
		$this->assertGreaterThan( 0, (int) $wpdb->insert_id );

		// Simulate the purge: the source row is gone (Post::delete deletes it before
		// firing), then the hard-delete action fires with only the id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'jt_posts', array( 'id' => 40 ), array( '%d' ) );
		do_action( 'jetonomy_after_delete_post', 40 );

		$card_left    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE id = %d", $card_id ) );
		$comment_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE object_type = 'post' AND object_id = %d", $card_id ) );
		$search_left  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'discussion' AND object_id = %d", 40 ) );

		$this->assertSame( 0, $card_left, 'the card is permanently removed on a hard delete' );
		$this->assertSame( 0, $comment_left, 'the comment is cascaded, not orphaned' );
		$this->assertSame( 0, $search_left, 'the search entry is dropped' );
	}

	/**
	 * A discussion card never links to a discussion the viewer cannot open
	 * (card 10343776298). Checked at render, because some causes fire no hook:
	 * a card published before post_id was stamped whose discussion was deleted,
	 * or a forum switched private in Jetonomy.
	 *
	 * @return void
	 */
	public function test_card_link_is_verified_at_render(): void {
		global $wpdb;
		$this->seed_jt_space( 9, 'forum' );
		$this->seed_jt_post( 50, $this->author_id, 'Old', 'Body', 'old-thread' );
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'space_id' => 9 ), array( 'id' => 50 ) );
		do_action( 'jetonomy_after_create_post', 50, 9 );

		$url  = home_url( '/community' ) . '/s/forum/t/old-thread/';
		$card = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT status, link_meta FROM {$wpdb->prefix}bn_posts WHERE type = 'discussion' AND link_url = %s", $url ) );

		// A card from before the post_id stamp: matched by URL and stamped.
		$wpdb->update( $wpdb->prefix . 'bn_posts', array( 'link_meta' => wp_json_encode( array( 'url' => $url, 'title' => 'Old' ) ) ), array( 'link_url' => $url ) );
		$this->assertSame( $url, $this->bridge->verify_discussion_card( $url, array() ) );
		$this->assertSame( 50, (int) ( json_decode( $card()->link_meta, true )['post_id'] ?? 0 ), 'an unstamped card is stamped' );

		// The forum goes private in Jetonomy (no hook): the card is withdrawn.
		$wpdb->update( $wpdb->prefix . 'jt_spaces', array( 'visibility' => 'private' ), array( 'id' => 9 ) );
		$fresh = new JetonomyBridge();
		$this->assertSame( '', $fresh->verify_discussion_card( $url, array( 'post_id' => 50 ) ) );

		// Deleted outright with no hook: gone, and withdrawn from every feed.
		$wpdb->update( $wpdb->prefix . 'jt_spaces', array( 'visibility' => 'public' ), array( 'id' => 9 ) );
		$wpdb->delete( $wpdb->prefix . 'jt_posts', array( 'id' => 50 ) );
		$this->assertSame( '', ( new JetonomyBridge() )->verify_discussion_card( $url, array( 'post_id' => 50 ) ) );
		$this->assertSame( 'draft', $card()->status, 'the dead card leaves every feed' );
	}

	public function test_discussion_activity_can_be_filtered_off(): void {
		global $wpdb;

		add_filter( 'buddynext_jetonomy_discussion_activity', '__return_false' );
		$this->seed_jt_space( 6, 'team' );
		$this->seed_jt_post( 31, $this->author_id, 'Quiet', 'Body', 'quiet-thread' );

		do_action( 'jetonomy_after_create_post', 31, 6 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link'", $this->author_id )
		);
		$this->assertSame( 0, $count );
	}

	public function test_provision_space_forum_is_idempotent_and_links(): void {
		$owner    = self::factory()->user->create();
		$space_id = ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => 'Design',
				'slug' => 'design',
			)
		);
		$this->assertIsInt( $space_id );

		$forum_id = $this->bridge->provision_space_forum( $space_id );
		$this->assertGreaterThan( 0, $forum_id );
		$this->assertSame( $forum_id, (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' ) );

		// Idempotent: a second call returns the same forum, creates no new jt_space.
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_spaces" );
		$again  = $this->bridge->provision_space_forum( $space_id );
		$after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_spaces" );
		$this->assertSame( $forum_id, $again );
		$this->assertSame( $before, $after );
	}

	/**
	 * Resolve the bridge's space-surface Discussions tab via the unified Nav
	 * registry and return its resolved URL (or null when the tab is absent).
	 *
	 * @param int $space_id Space subject id.
	 * @return string|null
	 */
	private function resolve_space_discussions_url( int $space_id ): ?string {
		$registry = \BuddyNext\Nav\NavRegistry::instance();
		$registry->reset();
		remove_all_actions( 'buddynext_register_nav' );
		$this->bridge->register_nav_items( $registry );

		$resolved = $registry->resolve( new \BuddyNext\Nav\NavContext( 'space', $space_id, 0, '' ) );
		foreach ( $resolved->layer( 'primary' ) as $item ) {
			if ( 'discussions' === $item->id ) {
				return $item->url_value;
			}
		}
		return null;
	}

	public function test_space_discussions_tab_links_to_in_hub_route(): void {
		$space_id = 4242;

		// The space Discussions tab appears once the space has a linked forum AND
		// its owner has enabled discussion (per-space opt-in). When present it
		// points at the clean in-hub discussions route (a real <a>) — the
		// nonce-protected provision URL lives in the panel data, never the tab link.
		update_space_meta( $space_id, 'jetonomy_forum_id', 777 );
		update_space_meta( $space_id, 'discussion_enabled', '1' );

		$url = $this->resolve_space_discussions_url( $space_id );
		$this->assertNotNull( $url, 'Space Discussions tab should be registered.' );
		$this->assertStringContainsString( '/discussions/', (string) $url );
		$this->assertStringNotContainsString( 'bn_provision_forum', (string) $url );
	}

	public function test_register_hook_is_idempotent(): void {
		global $wpdb;

		$this->seed_jt_post( 101, $this->user_id, 'Dupe Test', 'Body.' );

		// Indexing the same object_id twice should not duplicate rows (INSERT IGNORE).
		do_action( 'jetonomy_after_create_post', 101, 0 );
		do_action( 'jetonomy_after_create_post', 101, 0 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				101
			)
		);

		$this->assertSame( 1, $count );
	}
}

namespace Jetonomy\Models;

if ( ! class_exists( __NAMESPACE__ . '\\Space' ) ) {
	/**
	 * Test stub for Jetonomy's Space model — Jetonomy is not loaded in BN tests.
	 * create() inserts a row into the shadow jt_spaces table and returns its id.
	 */
	class Space {
		/**
		 * Create a forum space.
		 *
		 * @param array<string,mixed> $data        Space data (slug, title, ...).
		 * @param int|null            $owner_id    Creator user id.
		 * @return int New jt_spaces id.
		 */
		public static function create( array $data, ?int $owner_id = null ): int {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $wpdb->prefix . 'jt_spaces', array( 'slug' => (string) ( $data['slug'] ?? '' ) ), array( '%s' ) );
			return (int) $wpdb->insert_id;
		}
	}
}
