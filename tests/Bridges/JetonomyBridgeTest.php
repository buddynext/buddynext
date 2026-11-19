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
				is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
				reply_count INT NOT NULL DEFAULT 0,
				vote_score INT NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_spaces (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				slug VARCHAR(200) NOT NULL DEFAULT '',
				title VARCHAR(200) NOT NULL DEFAULT '',
				visibility VARCHAR(20) NOT NULL DEFAULT 'public',
				author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_replies (
				id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				content LONGTEXT NULL,
				content_plain LONGTEXT NULL,
				is_private TINYINT(1) NOT NULL DEFAULT 0,
				is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_space_members (
				space_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				source VARCHAR(20) NOT NULL DEFAULT 'manual',
				PRIMARY KEY (space_id, user_id)
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
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_replies" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_space_members" );
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

	/**
	 * Move a seeded discussion into publish the way Jetonomy does: Post::create()
	 * and Post::update() fire jetonomy_post_publish_transition( id, +1, created_at ).
	 */
	private function publish_jt_post( int $post_id, int $space_id = 0 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'jt_posts',
			array(
				'space_id' => $space_id,
				'status'   => 'publish',
			),
			array( 'id' => $post_id )
		);
		do_action( 'jetonomy_post_publish_transition', $post_id, 1, '' );
	}

	/**
	 * Move a discussion out of publish (trash, unapprove), as Post::update() does.
	 */
	private function unpublish_jt_post( int $post_id, string $status = 'trash' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'status' => $status ), array( 'id' => $post_id ) );
		do_action( 'jetonomy_post_publish_transition', $post_id, -1, '' );
	}

	public function test_discussion_indexed_with_correct_author(): void {
		global $wpdb;

		$this->seed_jt_post( 99, $this->author_id, 'Another Discussion', 'Content body.' );

		$this->publish_jt_post( 99, 0 );

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

		$this->publish_jt_post( 20, 0 );

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

		$this->publish_jt_post( 30, 5 );

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
		$this->unpublish_jt_post( 30 );
		$still_there = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE link_url = %s", $expected_url )
		);
		$published   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE link_url = %s AND status = 'published'", $expected_url )
		);
		$this->assertSame( 1, $still_there, 'the card row is preserved on a soft delete (withdrawn, not removed)' );
		$this->assertSame( 0, $published, 'the withdrawn card leaves every feed' );
	}

	/**
	 * Approve and Restore go through Moderation_Service, which fires only the
	 * publish transition (card 10344390418): an approved topic gets its first
	 * card, and a restored one gets the SAME card back.
	 *
	 * @return void
	 */
	public function test_approve_creates_and_restore_brings_back_the_same_card(): void {
		global $wpdb;
		$this->seed_jt_space( 11, 'approvals' );
		$this->seed_jt_post( 60, $this->author_id, 'Pending', 'Body', 'pending-thread' );
		$wpdb->update(
			$wpdb->prefix . 'jt_posts',
			array(
				'space_id' => 11,
				'status'   => 'pending',
			),
			array( 'id' => 60 )
		);
		$url  = home_url( '/community' ) . '/s/approvals/t/pending-thread/';
		$card = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$wpdb->prefix}bn_posts WHERE type = 'discussion' AND link_url = %s", $url ) );

		$this->assertNull( $card(), 'a pending topic has no card' );

		$this->publish_jt_post( 60, 11 ); // Approve.
		$first = $card();
		$this->assertNotNull( $first, 'approving creates the card' );
		$this->assertSame( 'published', $first->status );

		$this->unpublish_jt_post( 60 ); // Trash.
		$this->assertSame( 'draft', $card()->status, 'trash withdraws the card' );

		$this->publish_jt_post( 60, 11 ); // Restore.
		$this->assertSame( (int) $first->id, (int) $card()->id, 'restore brings back the same card' );
		$this->assertSame( 'published', $card()->status );
	}

	/**
	 * A forum reply's comment on the card follows the reply in and out of publish,
	 * and a restore brings back the same comment (card 10344390418). The mirror
	 * never notifies: Jetonomy sends the one notification (card 10344414409).
	 *
	 * @return void
	 */
	public function test_reply_mirror_follows_publish_state_and_never_notifies(): void {
		global $wpdb;
		$this->seed_jt_space( 12, 'replies' );
		$this->seed_jt_post( 70, $this->author_id, 'Thread', 'Body', 'reply-thread' );
		$this->publish_jt_post( 70, 12 );
		$wpdb->insert(
			$wpdb->prefix . 'jt_replies',
			array(
				'id'            => 700,
				'post_id'       => 70,
				'author_id'     => $this->user_id,
				'content'       => '<p>A forum reply</p>',
				'content_plain' => 'A forum reply',
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
		$comment = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT id, is_deleted, content FROM {$wpdb->prefix}bn_comments WHERE sync_reply_id = %d", 700 ) );
		$bell    = fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.post_commented'", $this->author_id ) );

		do_action( 'jetonomy_reply_publish_transition', 700, 1, '2026-09-01 10:00:00' );
		$first = $comment();
		$this->assertNotNull( $first, 'a published reply is mirrored onto the card' );
		$this->assertSame( 0, $bell(), 'the mirrored comment does not notify the card owner' );

		$wpdb->update( $wpdb->prefix . 'jt_replies', array( 'status' => 'trash' ), array( 'id' => 700 ) );
		do_action( 'jetonomy_reply_publish_transition', 700, -1, '' );
		$this->assertSame( 1, (int) $comment()->is_deleted, 'a trashed reply loses its comment' );

		$wpdb->update( $wpdb->prefix . 'jt_replies', array( 'status' => 'publish' ), array( 'id' => 700 ) );
		do_action( 'jetonomy_reply_publish_transition', 700, 1, '' );
		$this->assertSame( (int) $first->id, (int) $comment()->id, 'restore brings back the same comment' );
		$this->assertSame( 0, (int) $comment()->is_deleted );
		$this->assertSame( 'A forum reply', (string) $comment()->content, 'the comment carries the plain text, never the reply HTML' );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE sync_reply_id = %d", 700 ) ), 'no second comment' );
	}

	/**
	 * Feed card rows the bridge published for a topic author.
	 *
	 * @param int    $author_id Topic author.
	 * @param string $status    Card status.
	 * @return int
	 */
	private function discussion_cards( int $author_id, string $status = 'published' ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = %s", $author_id, $status ) );
	}

	/**
	 * An anonymous topic gets no feed card and no search row.
	 *
	 * @return void
	 */
	public function test_anonymous_topic_is_not_mirrored(): void {
		global $wpdb;
		$this->seed_jt_space( 21, 'anon' );
		$this->seed_jt_post( 210, $this->author_id, 'Anonymous question', 'Body', 'anon-question' );
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'is_anonymous' => 1 ), array( 'id' => 210 ) );

		$this->publish_jt_post( 210, 21 );
		do_action( 'jetonomy_after_create_post', 210, 21 );

		$this->assertSame( 0, $this->discussion_cards( $this->author_id ), 'No feed card under the author.' );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'discussion' AND object_id = 210" ),
			'No search row.'
		);
	}

	/**
	 * Editing a signed topic to anonymous withdraws its card and search row.
	 *
	 * @return void
	 */
	public function test_topic_edited_to_anonymous_is_withdrawn(): void {
		global $wpdb;
		$this->seed_jt_space( 22, 'edit-anon' );
		$this->seed_jt_post( 220, $this->author_id, 'Signed topic', 'Body', 'signed-topic' );
		$this->publish_jt_post( 220, 22 );
		$this->assertSame( 1, $this->discussion_cards( $this->author_id ), 'A signed topic is mirrored.' );

		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'is_anonymous' => 1 ), array( 'id' => 220 ) );
		do_action( 'jetonomy_post_updated', 220, 22 );

		$this->assertSame( 0, $this->discussion_cards( $this->author_id ), 'The card is withdrawn.' );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'discussion' AND object_id = 220" ),
			'The search row is dropped.'
		);
	}

	/**
	 * Rows mirrored from anonymous content before the fix are cleared once.
	 *
	 * @return void
	 */
	public function test_repair_clears_previously_mirrored_anonymous_content(): void {
		global $wpdb;
		$this->seed_jt_space( 26, 'repair' );
		$this->seed_jt_post( 260, $this->author_id, 'Was signed', 'Body', 'was-signed' );
		$this->publish_jt_post( 260, 26 );
		$wpdb->insert(
			$wpdb->prefix . 'jt_replies',
			array(
				'id'            => 2601,
				'post_id'       => 260,
				'author_id'     => $this->user_id,
				'content_plain' => 'A reply',
			)
		);
		do_action( 'jetonomy_reply_publish_transition', 2601, 1, '' );
		$search  = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'discussion' AND object_id = 260" );
		$comment = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE sync_reply_id = 2601 AND is_deleted = 0" );
		$this->assertSame( 1, $search(), 'Fixture: mirrored while signed.' );
		$this->assertSame( 1, $comment(), 'Fixture: reply mirrored while signed.' );

		// The state an older version left behind: flagged in the forum, still mirrored here.
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'is_anonymous' => 1 ), array( 'id' => 260 ) );
		$wpdb->update( $wpdb->prefix . 'jt_replies', array( 'is_anonymous' => 1 ), array( 'id' => 2601 ) );
		delete_transient( 'buddynext_jetonomy_anonymous_checked' );

		$hook    = \BuddyNext\Bridges\JetonomyBridge::REPAIR_ANONYMOUS_HOOK;
		$queued  = static function () use ( $hook ): bool {
			return function_exists( 'as_has_scheduled_action' )
				? (bool) as_has_scheduled_action( $hook, array(), 'buddynext' )
				: false !== wp_next_scheduled( $hook );
		};
		$unqueue = static function () use ( $hook ): void {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'buddynext' );
			}
			wp_clear_scheduled_hook( $hook );
		};
		$unqueue();

		// Nobody but an administrator on a dashboard screen asks for it.
		wp_set_current_user( $this->user_id );
		$this->bridge->queue_anonymous_repair();
		$this->assertFalse( $queued(), 'A member cannot start the pass.' );
		$this->assertFalse( get_transient( 'buddynext_jetonomy_anonymous_checked' ) );

		// An administrator's page load only queues the job: nothing is repaired
		// inside the request, and the day is marked so it is not queued twice.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->bridge->queue_anonymous_repair();
		$this->assertTrue( $queued(), 'The check is queued.' );
		$this->assertSame( 1, $search(), 'The page load itself repaired nothing.' );
		$this->assertNotFalse( get_transient( 'buddynext_jetonomy_anonymous_checked' ), 'Not queued again on the next page load.' );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, (int) get_option( '_transient_timeout_buddynext_jetonomy_anonymous_checked' ), 'Held for an hour, not a day, until a run has finished clean.' );

		// The background job does the work, and asks for another run while it found some.
		$unqueue();
		$this->bridge->repair_anonymous_mirrors();

		$this->assertSame( 0, $search(), 'The search row is gone.' );
		$this->assertSame( 0, $comment(), 'The signed comment is gone.' );
		$this->assertSame( 0, $this->discussion_cards( $this->author_id ), 'The card is withdrawn.' );
		$this->assertTrue( $queued(), 'Runs again while it still found work.' );

		$unqueue();
		$this->bridge->repair_anonymous_mirrors();
		$this->assertFalse( $queued(), 'Stops once nothing is left.' );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, (int) get_option( '_transient_timeout_buddynext_jetonomy_anonymous_checked' ), 'A clean run waits a day.' );
	}

	/**
	 * A card with no search row behind it is still withdrawn.
	 *
	 * @return void
	 */
	public function test_repair_withdraws_a_card_that_has_no_search_row(): void {
		global $wpdb;
		$this->seed_jt_space( 27, 'card-only' );
		$this->seed_jt_post( 270, $this->author_id, 'Card only', 'Body', 'card-only' );
		$this->publish_jt_post( 270, 27 );
		$wpdb->delete(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => 'discussion',
				'object_id'   => 270,
			)
		);
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'is_anonymous' => 1 ), array( 'id' => 270 ) );
		delete_transient( 'buddynext_jetonomy_anonymous_checked' );
		$this->assertSame( 1, $this->discussion_cards( $this->author_id ), 'Fixture: a published card.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->bridge->repair_anonymous_mirrors();

		$this->assertSame( 0, $this->discussion_cards( $this->author_id ) );
	}

	/**
	 * Switching discussion search back on re-indexes topics without posting them again.
	 *
	 * @return void
	 */
	public function test_search_reindex_indexes_topics_and_creates_no_cards(): void {
		global $wpdb;
		$this->seed_jt_space( 28, 'reindex' );
		// Topics that exist in the forum and are in neither the index nor the feed,
		// as after search was switched off and time passed.
		foreach ( array( 280, 281 ) as $topic_id ) {
			$this->seed_jt_post( $topic_id, $this->author_id, 'Reindex topic ' . $topic_id, 'Body', 'reindex-' . $topic_id );
			$wpdb->update(
				$wpdb->prefix . 'jt_posts',
				array(
					'space_id' => 28,
					'status'   => 'publish',
				),
				array( 'id' => $topic_id )
			);
		}
		$indexed = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'discussion' AND object_id IN ( 280, 281 )" );
		$this->assertSame( 0, $indexed(), 'Fixture: not indexed.' );
		$this->assertSame( 0, $this->discussion_cards( $this->author_id ), 'Fixture: no feed cards.' );

		( new \BuddyNext\Search\SearchIndexListener() )->handle_integration_reindex( 'jetonomy', 0 );

		$this->assertSame( 2, $indexed(), 'Both topics are back in search.' );
		$this->assertSame( 0, $this->discussion_cards( $this->author_id ), 'No old topic was posted to the feed as new.' );
	}

	/**
	 * An anonymous or private reply is not mirrored as a signed feed comment.
	 *
	 * @return void
	 */
	public function test_anonymous_and_private_replies_are_not_mirrored(): void {
		global $wpdb;
		$this->seed_jt_space( 23, 'anon-replies' );
		$this->seed_jt_post( 230, $this->author_id, 'Thread', 'Body', 'anon-reply-thread' );
		$this->publish_jt_post( 230, 23 );
		foreach ( array(
			2301 => 'is_anonymous',
			2302 => 'is_private',
		) as $reply_id => $flag ) {
			$wpdb->insert(
				$wpdb->prefix . 'jt_replies',
				array(
					'id'            => $reply_id,
					'post_id'       => 230,
					'author_id'     => $this->user_id,
					'content_plain' => 'A reply',
					$flag           => 1,
				)
			);
			do_action( 'jetonomy_reply_publish_transition', $reply_id, 1, '' );
		}

		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE sync_reply_id IN ( 2301, 2302 )" )
		);
	}

	/**
	 * The profile list and count leave out anonymous, private and private-forum topics.
	 *
	 * @return void
	 */
	public function test_profile_discussions_list_only_public_signed_topics(): void {
		global $wpdb;
		$this->seed_jt_space( 24, 'public-forum' );
		$this->seed_jt_space( 25, 'private-forum' );
		$wpdb->update( $wpdb->prefix . 'jt_spaces', array( 'visibility' => 'private' ), array( 'id' => 25 ) );
		foreach ( array(
			240 => 24,
			241 => 24,
			242 => 24,
			243 => 25,
		) as $post_id => $forum_id ) {
			$this->seed_jt_post( $post_id, $this->author_id, 'Topic ' . $post_id, 'Body', 'topic-' . $post_id );
			$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'space_id' => $forum_id ), array( 'id' => $post_id ) );
		}
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'is_anonymous' => 1 ), array( 'id' => 241 ) );
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'is_private' => 1 ), array( 'id' => 242 ) );

		$listed = array_map( static fn( $row ) => (int) $row->id, $this->bridge->user_discussions( $this->author_id, 10 ) );

		$this->assertSame( array( 240 ), $listed );
		$this->assertSame( 1, $this->bridge->discussion_count( $this->author_id ) );
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
		$this->publish_jt_post( 40, 7 );

		$card_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE type = 'discussion' AND CAST( JSON_UNQUOTE( JSON_EXTRACT( link_meta, '$.post_id' ) ) AS UNSIGNED ) = %d", 40 )
		);
		$this->assertGreaterThan( 0, $card_id, 'the card is stamped with its post_id in link_meta' );

		// A member commented on the card.
		$commenter = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'object_type' => 'post',
				'object_id'   => $card_id,
				'user_id'     => $commenter,
				'content'     => 'great thread',
			),
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
		$this->publish_jt_post( 50, 9 );

		$url  = home_url( '/community' ) . '/s/forum/t/old-thread/';
		$card = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT status, link_meta FROM {$wpdb->prefix}bn_posts WHERE type = 'discussion' AND link_url = %s", $url ) );

		// A card from before the post_id stamp: matched by URL and stamped.
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'link_meta' => wp_json_encode(
					array(
						'url'   => $url,
						'title' => 'Old',
					)
				),
			),
			array( 'link_url' => $url )
		);
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

		$this->publish_jt_post( 31, 6 );

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
	 * The owner's on/off choice: first on creates the discussion, off keeps it,
	 * on again restores the same one. Web settings and REST share this path.
	 *
	 * @return void
	 */
	public function test_discussion_choice_round_trip_keeps_one_discussion(): void {
		global $wpdb;
		$owner    = self::factory()->user->create();
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => 'Choice',
				'slug' => 'choice-' . wp_rand( 1000, 9999 ),
			)
		);

		$this->assertTrue( $this->bridge->apply_discussion_choice( $space_id, true, 0, $owner ) );
		$first = $this->bridge->space_discussion_status( $space_id );
		$this->assertTrue( $first['enabled'] );
		$this->assertGreaterThan( 0, $first['forum_id'] );

		$this->assertTrue( $this->bridge->apply_discussion_choice( $space_id, false, 0, $owner ) );
		$off = $this->bridge->space_discussion_status( $space_id );
		$this->assertFalse( $off['enabled'] );
		$this->assertSame( $first['forum_id'], $off['forum_id'], 'Off keeps the discussion.' );

		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_spaces" );
		$this->bridge->apply_discussion_choice( $space_id, true, 0, $owner );
		$this->assertSame( $first['forum_id'], $this->bridge->space_discussion_status( $space_id )['forum_id'], 'On again restores the same one.' );
		$this->assertSame( $rows, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_spaces" ), 'No second discussion.' );
	}

	/**
	 * Unlink removes the link only: the forum and its people stay, the space keeps
	 * its members, the tab turns off, and the next enable offers a fresh choice.
	 *
	 * @return void
	 */
	public function test_unlink_keeps_forum_and_members_and_reopens_the_choice(): void {
		global $wpdb;
		$owner    = self::factory()->user->create();
		$member   = self::factory()->user->create();
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => 'Trail runners',
				'slug' => 'trail-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		buddynext_service( 'space_members' )->join( $space_id, $member );
		$this->bridge->apply_discussion_choice( $space_id, true, 0, $owner );
		$forum_id = $this->bridge->space_discussion_status( $space_id )['forum_id'];

		wp_set_current_user( $owner );
		$request = new \WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $space_id . '/discussion' );
		$request->set_url_params( array( 'id' => $space_id ) );
		$status = $this->bridge->rest_unlink_discussion( $request )->get_data();

		$this->assertFalse( $status['has_discussion'] );
		$this->assertFalse( $this->bridge->space_discussion_enabled( $space_id ), 'The Discussion tab turns off.' );
		$this->assertSame( $forum_id, (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE id = %d", $forum_id ) ), 'The forum is kept.' );
		$this->assertTrue( buddynext_service( 'space_members' )->is_member( $space_id, $member ), 'Space members stay.' );

		$this->bridge->apply_discussion_choice( $space_id, true, 0, $owner );
		$this->assertNotSame( $forum_id, $this->bridge->space_discussion_status( $space_id )['forum_id'], 'Enabling again creates or links afresh, not the old link.' );
	}

	/**
	 * Only someone who can manage the space's settings may read or change it.
	 *
	 * @return void
	 */
	public function test_discussion_route_is_for_space_managers(): void {
		$owner    = self::factory()->user->create();
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => 'Gate',
				'slug' => 'gate-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$request  = new \WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/discussion' );
		$request->set_url_params( array( 'id' => $space_id ) );

		wp_set_current_user( $owner );
		$this->assertTrue( $this->bridge->rest_discussion_manage_permission( $request ) );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->bridge->rest_discussion_manage_permission( $request )->get_error_data()['status'] );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->bridge->rest_discussion_manage_permission( $request )->get_error_data()['status'] );
	}

	/**
	 * Linking an existing forum copies its self-joined members into an open space,
	 * once. A banned member, a member held by a tier, and a private space are all
	 * left alone, and a job for a space that is not linked does nothing.
	 *
	 * @return void
	 */
	public function test_forum_members_are_copied_into_the_linked_space(): void {
		global $wpdb;
		$owner   = self::factory()->user->create();
		$members = self::factory()->user->create_many( 3 );
		$banned  = self::factory()->user->create();
		$by_tier = self::factory()->user->create();
		$spaces  = new \BuddyNext\Spaces\SpaceMemberService();
		$make    = static fn( string $type ): int => (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => 'Linked ' . $type,
				'slug' => 'linked-' . $type . '-' . wp_rand( 1000, 9999 ),
				'type' => $type,
			)
		);
		$open    = $make( 'open' );
		$private = $make( 'private' );
		$spaces->ban_from_space( $open, $banned, $owner );

		foreach ( array_merge( $members, array( $banned, 999999 ) ) as $user_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'jt_space_members',
				array(
					'space_id' => 7,
					'user_id'  => $user_id,
					'source'   => 'manual',
				)
			);
		}
		$wpdb->insert(
			$wpdb->prefix . 'jt_space_members',
			array(
				'space_id' => 7,
				'user_id'  => $by_tier,
				'source'   => 'tier',
			)
		);

		// Not linked to forum 7: nothing is copied.
		$this->bridge->copy_forum_members( $open, 7, 0 );
		$this->assertFalse( $spaces->is_member( $open, $members[0] ) );

		update_space_meta( $open, 'jetonomy_forum_id', 7 );
		update_space_meta( $private, 'jetonomy_forum_id', 7 );
		$this->bridge->copy_forum_members( $open, 7, 0 );
		$this->bridge->copy_forum_members( $private, 7, 0 );

		foreach ( $members as $member ) {
			$this->assertTrue( $spaces->is_member( $open, $member ) );
			$this->assertFalse( $spaces->is_member( $private, $member ), 'A private space takes nobody without a request or an invitation.' );
		}
		$this->assertFalse( $spaces->is_member( $open, $banned ), 'A banned member stays out.' );
		$this->assertFalse( $spaces->is_member( $open, $by_tier ), 'A member the forum holds through a paid tier is not copied.' );

		// Continuing after the highest user id finds nobody left.
		$this->bridge->copy_forum_members( $open, 7, 999999 );
		$this->assertSame( 1 + count( $members ), $spaces->member_count( $open ) );
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

	public function test_a_mirror_write_mutes_jetonomy_notifications_too(): void {
		$mirror = new \ReflectionMethod( JetonomyBridge::class, 'mirror' );
		$during = null;
		$mirror->invoke(
			null,
			static function () use ( &$during ) {
				$during = apply_filters( 'jetonomy_notification_should_send', true );
			}
		);

		// The member acted on the card, so BuddyNext's notice is the one; Jetonomy's reply mail is the duplicate.
		$this->assertFalse( $during );
		$this->assertTrue( apply_filters( 'jetonomy_notification_should_send', true ), 'a member\'s own forum reply still notifies' );
	}

	public function test_register_hook_is_idempotent(): void {
		global $wpdb;

		$this->seed_jt_post( 101, $this->user_id, 'Dupe Test', 'Body.' );

		// Syncing the same discussion twice must not duplicate rows.
		$this->publish_jt_post( 101, 0 );
		$this->publish_jt_post( 101, 0 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				101
			)
		);

		$this->assertSame( 1, $count );
	}

	/**
	 * A non-ASCII slug is used as stored. sanitize_title() keeps it percent-
	 * encoded already; encoding again (%f0 -> %25f0) opened "Topic not found".
	 */
	public function test_discussion_link_never_double_encodes_a_slug(): void {
		$url = $this->bridge->discussion_permalink( 'caf%c3%a9-club', 'helped-you-today-%f0%9f%8c%bf' );

		$this->assertStringContainsString( '/s/caf%c3%a9-club/t/helped-you-today-%f0%9f%8c%bf/', $url );
		$this->assertStringNotContainsString( '%25', $url );
		$this->assertStringEndsWith( '/s/plain/t/plain-title/', $this->bridge->discussion_permalink( 'plain', 'plain-title' ) );
	}

	/**
	 * v72 repairs feed cards saved with the double-encoded link, and leaves any
	 * link outside Jetonomy's base alone even when it contains %25.
	 */
	public function test_migration_repairs_saved_double_encoded_links_only(): void {
		global $wpdb;
		$base    = trailingslashit( home_url( '/community' ) ) . 's/';
		$broken  = $base . 'club/t/today-%25f0%259f%258c%25bf/';
		$outside = 'https://example.com/report?q=100%25';
		$ids     = array();
		foreach ( array( $broken, $outside ) as $link ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_posts',
				array(
					'user_id'    => $this->author_id,
					'type'       => 'link',
					'content'    => 'Card',
					'link_url'   => $link,
					'link_meta'  => wp_json_encode(
						array(
							'url'   => $link,
							'title' => '100%25 sure',
						)
					),
					'privacy'    => 'public',
					'status'     => 'published',
					'created_at' => current_time( 'mysql', true ),
					'updated_at' => current_time( 'mysql', true ),
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}

		$method = new \ReflectionMethod( Installer::class, 'maybe_migrate_jetonomy_link_encoding' );
		$method->setAccessible( true );
		$method->invoke( null );

		$fixed = $wpdb->get_row( $wpdb->prepare( "SELECT link_url, link_meta FROM {$wpdb->prefix}bn_posts WHERE id = %d", $ids[0] ) );
		$this->assertSame( $base . 'club/t/today-%f0%9f%8c%bf/', $fixed->link_url );
		$meta = json_decode( $fixed->link_meta, true );
		$this->assertSame( $base . 'club/t/today-%f0%9f%8c%bf/', $meta['url'] );
		$this->assertSame( '100%25 sure', $meta['title'], 'only url fields change' );

		$this->assertSame( $outside, $wpdb->get_var( $wpdb->prepare( "SELECT link_url FROM {$wpdb->prefix}bn_posts WHERE id = %d", $ids[1] ) ) );
	}

	/**
	 * A space's "who can post" holds in its linked forum, without demoting anyone.
	 *
	 * @return void
	 */
	public function test_who_can_post_is_carried_to_the_linked_forum(): void {
		$owner    = self::factory()->user->create();
		$space_id = (int) buddynext_service( 'spaces' )->create(
			$owner,
			array(
				'name' => 'Posting Rule',
				'slug' => 'posting-rule',
				'type' => 'open',
			)
		);
		$forum_id = 987654;
		update_space_meta( $space_id, 'jetonomy_forum_id', $forum_id );
		$read = static fn(): string => (string) ( \Jetonomy\Models\Space::get_settings( $forum_id )['who_can_post'] ?? '' );

		update_space_meta( $space_id, 'who_can_post', 'mods' );
		$bridge = new JetonomyBridge();

		// An unrelated save does not touch the forum.
		$bridge->sync_discussion_posting( $space_id, array( 'name' => 'x' ) );
		$this->assertSame( '', $read() );

		$bridge->sync_discussion_posting( $space_id, array( 'who_can_post' => 'mods' ) );
		$this->assertSame( 'moderators', $read() );

		update_space_meta( $space_id, 'who_can_post', 'owner' );
		$bridge->sync_discussion_posting( $space_id, array( 'who_can_post' => 'owner' ) );
		$this->assertSame( 'admins', $read() );

		update_space_meta( $space_id, 'who_can_post', 'members' );
		$bridge->sync_discussion_posting( $space_id, array( 'who_can_post' => 'members' ) );
		$this->assertSame( '', $read() );

		// A space restricted before this shipped gets the rule once, on an admin load.
		update_space_meta( $space_id, 'who_can_post', 'mods' );
		delete_option( 'buddynext_discussion_posting_synced' );
		$bridge->backfill_discussion_posting();
		$this->assertSame( 'moderators', $read() );

		// ...and only once.
		\Jetonomy\Models\Space::$settings[ $forum_id ] = array();
		$bridge->backfill_discussion_posting();
		$this->assertSame( '', $read() );
	}

	/**
	 * A space with an owner, a space moderator, and a plain member who holds the
	 * site-wide community moderator rank.
	 *
	 * @return array{space:int,owner:int,moderator:int,community_mod:int}
	 */
	private function space_with_team(): array {
		$owner         = self::factory()->user->create();
		$moderator     = self::factory()->user->create();
		$community_mod = self::factory()->user->create();
		$space_id      = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => 'Team',
				'slug' => 'team-' . wp_rand( 1000, 99999 ),
				'type' => 'open',
			)
		);
		$members       = buddynext_service( 'space_members' );
		$members->join( $space_id, $moderator );
		$members->change_role( $space_id, $moderator, 'moderator', $owner );
		$members->join( $space_id, $community_mod );
		update_user_meta( $community_mod, 'bn_community_role', 'moderator' );

		return array(
			'space'         => $space_id,
			'owner'         => $owner,
			'moderator'     => $moderator,
			'community_mod' => $community_mod,
		);
	}

	/**
	 * Dispatch one Discussion management route as a user and return the HTTP status.
	 *
	 * @param string $method   HTTP verb.
	 * @param string $route    discussion|discussion-search.
	 * @param int    $space_id Space.
	 * @param int    $user_id  Caller.
	 * @return int
	 */
	private function discussion_route_status( string $method, string $route, int $space_id, int $user_id ): int {
		wp_set_current_user( $user_id );
		$request = new \WP_REST_Request( $method, '/buddynext/v1/spaces/' . $space_id . '/' . $route );
		if ( 'POST' === $method ) {
			$request->set_param( 'enabled', false );
		}
		return rest_get_server()->dispatch( $request )->get_status();
	}

	/**
	 * Only the space owner (or a site admin) links, unlinks, reads or searches a
	 * space's discussion over REST: the same rule the web settings save applies.
	 * A space moderator and a member with the community moderator rank are refused
	 * on every verb (card 10517707153).
	 *
	 * @return void
	 */
	public function test_only_the_space_owner_manages_the_discussion_over_rest(): void {
		$team = $this->space_with_team();
		$id   = $team['space'];

		foreach ( array( array( 'GET', 'discussion' ), array( 'POST', 'discussion' ), array( 'DELETE', 'discussion' ), array( 'GET', 'discussion-search' ) ) as $call ) {
			$label = implode( ' ', $call );
			$this->assertSame( 403, $this->discussion_route_status( $call[0], $call[1], $id, $team['moderator'] ), "Space moderator: $label" );
			$this->assertSame( 403, $this->discussion_route_status( $call[0], $call[1], $id, $team['community_mod'] ), "Community moderator: $label" );
			$this->assertSame( 200, $this->discussion_route_status( $call[0], $call[1], $id, $team['owner'] ), "Owner: $label" );
		}

		$this->assertTrue( $this->bridge->can_manage_discussion( $id, $team['owner'] ) );
		$this->assertFalse( $this->bridge->can_manage_discussion( $id, $team['moderator'] ) );
		$this->assertFalse( $this->bridge->can_manage_discussion( $id, 0 ) );
	}

	/**
	 * With Jetonomy switched off site-wide the web save writes nothing, so REST
	 * refuses too, even for the owner.
	 *
	 * @return void
	 */
	public function test_site_switch_off_blocks_discussion_management_over_rest(): void {
		$team = $this->space_with_team();
		update_option( 'buddynext_integration_jetonomy_nav', '0' );

		$this->assertSame( 403, $this->discussion_route_status( 'POST', 'discussion', $team['space'], $team['owner'] ) );
		$this->assertSame( 403, $this->discussion_route_status( 'GET', 'discussion-search', $team['space'], $team['owner'] ) );
		$this->assertFalse( $this->bridge->can_manage_discussion( $team['space'], $team['owner'] ) );
	}

	/**
	 * "Start the first discussion" creates a forum only once the owner has
	 * switched Discussion on; a moderator cannot create one the owner never chose.
	 *
	 * @return void
	 */
	public function test_on_demand_discussion_needs_the_owner_switch(): void {
		$team    = $this->space_with_team();
		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $team['space'] . '/forum' );
		$request->set_url_params( array( 'id' => $team['space'] ) );
		wp_set_current_user( $team['moderator'] );

		$off = $this->bridge->rest_provision_forum( $request )->get_data();
		$this->assertSame( 0, $off['forum_id'], 'Nothing is created while Discussion is off.' );
		$this->assertFalse( $off['can_provision'] );

		update_space_meta( $team['space'], 'discussion_enabled', '1' );
		$on = $this->bridge->rest_provision_forum( $request )->get_data();
		$this->assertGreaterThan( 0, $on['forum_id'], 'Once the owner switched it on, a moderator may start it.' );
	}

	/**
	 * A forum belongs to one space: linking, adopting and the picker all refuse a
	 * forum another space already holds (card 10517706879).
	 *
	 * @return void
	 */
	public function test_a_forum_is_linked_to_one_space_only(): void {
		global $wpdb;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$wpdb->insert(
			$wpdb->prefix . 'jt_spaces',
			array(
				'slug'      => 'shared',
				'title'     => 'Shared forum',
				'author_id' => $admin,
			)
		);
		$forum = (int) $wpdb->insert_id;
		$make  = static fn( string $name ): int => (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$admin,
			array(
				'name' => $name,
				'slug' => sanitize_title( $name ) . '-' . wp_rand( 1000, 99999 ),
				'type' => 'open',
			)
		);
		$first = $make( 'First' );
		$other = $make( 'Other' );

		$this->assertTrue( $this->bridge->apply_discussion_choice( $first, true, $forum, $admin ) );

		// The picker hides it from every other space, not from its own.
		$ids = static fn( array $rows ): array => array_column( $rows, 'id' );
		$this->assertNotContains( $forum, $ids( $this->bridge->search_discussions( 'Shared', 0, 20, $other ) ) );
		$this->assertContains( $forum, $ids( $this->bridge->search_discussions( 'Shared', 0, 20, $first ) ) );

		// A crafted link is refused and nothing is written.
		$taken = $this->bridge->apply_discussion_choice( $other, true, $forum, $admin );
		$this->assertWPError( $taken );
		$this->assertSame( 'bn_discussion_taken', $taken->get_error_code() );
		$this->assertSame( 0, (int) buddynext_get_space_field( $other, 'jetonomy_forum_id' ) );

		// An integration offering it for adoption is passed over; a fresh one is made.
		$offer = static fn(): int => $forum;
		add_filter( 'buddynext_adopt_discussion_space', $offer );
		$made  = $this->bridge->provision_space_forum( $other );
		remove_filter( 'buddynext_adopt_discussion_space', $offer );
		$this->assertGreaterThan( 0, $made );
		$this->assertNotSame( $forum, $made );
	}

	/**
	 * The forum-to-space lookup answers the same space every time when old data
	 * holds a duplicate, remembers a miss for the request, and forgets it the
	 * moment a link is written.
	 *
	 * @return void
	 */
	public function test_forum_lookup_is_deterministic_and_remembers_a_miss(): void {
		global $wpdb;
		$owner  = self::factory()->user->create();
		$make   = static fn( string $name ): int => (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => $name,
				'slug' => sanitize_title( $name ) . '-' . wp_rand( 1000, 99999 ),
			)
		);
		$first  = $make( 'Lookup one' );
		$second = $make( 'Lookup two' );
		$lookup = new \ReflectionMethod( JetonomyBridge::class, 'space_id_for_forum' );
		$lookup->setAccessible( true );

		$this->assertSame( 0, $lookup->invoke( $this->bridge, 4242 ) );
		$before = $wpdb->num_queries;
		$this->assertSame( 0, $lookup->invoke( $this->bridge, 4242 ) );
		$this->assertSame( $before, $wpdb->num_queries, 'A miss is remembered for the request.' );

		update_space_meta( $first, 'jetonomy_forum_id', 4242 );
		$this->assertSame( $first, $lookup->invoke( $this->bridge, 4242 ), 'Writing a link clears the remembered miss.' );

		// Legacy duplicate: the earliest link keeps answering.
		update_space_meta( $second, 'jetonomy_forum_id', 4242 );
		$this->assertSame( $first, $lookup->invoke( $this->bridge, 4242 ) );
		$this->assertSame( array( 4242 => array( $first, $second ) ), JetonomyBridge::duplicate_forum_links() );
	}

	/**
	 * A deleted space's forum is purged only when the bridge made it for THAT space, left
	 * alone when an add-on claimed it, and only hidden when anything else is in doubt.
	 */
	public function test_forum_fate_on_delete_never_destroys_a_forum_the_space_did_not_make(): void {
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$this->user_id,
			array(
				'name' => 'Fate',
				'slug' => 'fate-space',
				'type' => 'open',
			)
		);

		$this->assertSame( 'none', $this->bridge->forum_fate_on_delete( $space_id ), 'No forum, nothing to do.' );

		update_space_meta( $space_id, 'jetonomy_forum_id', 41 );
		update_space_meta( $space_id, 'jetonomy_forum_provisioned', 41 );
		$this->assertSame( 'purge', $this->bridge->forum_fate_on_delete( $space_id ), 'The forum the bridge made is removed.' );

		// An older forum is linked later; the marker still names the first forum.
		update_space_meta( $space_id, 'jetonomy_forum_id', 77 );
		$this->assertSame( 'hide', $this->bridge->forum_fate_on_delete( $space_id ), 'A relinked older forum is never purged.' );

		// A forum from before the marker existed, with nothing proving it is ours.
		update_space_meta( $space_id, 'jetonomy_forum_provisioned', 0 );
		$this->assertSame( 'hide', $this->bridge->forum_fate_on_delete( $space_id ), 'No proof, no purge.' );

		// A course (or any add-on) claims the forum the bridge made.
		update_space_meta( $space_id, 'jetonomy_forum_id', 41 );
		update_space_meta( $space_id, 'jetonomy_forum_provisioned', 41 );
		$claim = static fn() => true;
		add_filter( 'buddynext_space_forum_claimed', $claim );
		$this->assertSame( 'unlink', $this->bridge->forum_fate_on_delete( $space_id ), 'A claimed forum outlives the space.' );
		remove_filter( 'buddynext_space_forum_claimed', $claim );
	}

	/**
	 * A space whose Discussions are switched off keeps the forum's topics but shows none of
	 * them: no feed card, no search row. Switching back on republishes both.
	 */
	public function test_discussions_off_shows_no_card_and_no_search_row(): void {
		global $wpdb;

		$bn_space = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$this->user_id,
			array(
				'name' => 'Off switch',
				'slug' => 'off-switch',
				'type' => 'open',
			)
		);
		$this->seed_jt_space( 8, 'off-switch' );
		update_space_meta( $bn_space, 'jetonomy_forum_id', 8 );
		$this->seed_jt_post( 81, $this->author_id, 'Hidden while off', 'Body', 'hidden-while-off' );
		$count = fn( string $table, string $where ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_space_meta( $bn_space, 'discussion_enabled', '0' );
		$this->publish_jt_post( 81, 8 );
		$this->assertSame( 0, $count( 'bn_search_index', "object_type = 'discussion' AND object_id = 81" ), 'No search row while off.' );
		$this->assertSame( 0, $count( 'bn_posts', "type = 'discussion' AND status = 'published' AND link_url LIKE '%hidden-while-off%'" ), 'No card while off.' );

		update_space_meta( $bn_space, 'discussion_enabled', '1' );
		$this->bridge->sync_discussion( 81, 8 );
		$this->assertSame( 1, $count( 'bn_search_index', "object_type = 'discussion' AND object_id = 81" ), 'Back on: the topic is searchable again.' );
		$this->assertSame( 1, $count( 'bn_posts', "type = 'discussion' AND status = 'published' AND link_url LIKE '%hidden-while-off%'" ), 'Back on: the card is back.' );
	}

	/**
	 * A join made through the forum cannot get round the space: refused while the space is archived,
	 * but never for a member already in it or for the bridge's own roster copies.
	 */
	public function test_forum_join_cannot_get_round_the_space(): void {
		$sv       = new \BuddyNext\Spaces\SpaceService();
		$bn_space = (int) $sv->create(
			$this->user_id,
			array(
				'name' => 'Join veto',
				'slug' => 'join-veto',
				'type' => 'open',
			)
		);
		$this->seed_jt_space( 9, 'join-veto' );
		update_space_meta( $bn_space, 'jetonomy_forum_id', 9 );
		$outsider = self::factory()->user->create();

		$this->assertTrue( $this->bridge->veto_forum_join( true, $outsider, 9 ), 'An open space lets an outsider join its forum.' );

		$sv->archive( $bn_space, $this->user_id );
		$this->assertWPError( $this->bridge->veto_forum_join( true, $outsider, 9 ), 'An archived space takes no new members, forum or not.' );
		$this->assertTrue( $this->bridge->veto_forum_join( true, $this->user_id, 9 ), 'The owner is already in the space.' );
		\BuddyNext\Feed\IntegrationActivity::as_mirror(
			fn() => $this->assertTrue( $this->bridge->veto_forum_join( true, $outsider, 9 ), 'The bridge\'s own roster copies pass.' )
		);
	}

	/**
	 * A plan-gated open space shapes a private forum; nothing else changes the type.
	 */
	public function test_a_plan_gated_open_space_gets_a_private_forum(): void {
		$this->assertSame( 'open', JetonomyBridge::forum_type_for( array( 'type' => 'open' ) ) );
		$this->assertSame( 'private', JetonomyBridge::forum_type_for( array( 'type' => 'open', 'is_gated' => true ) ) );
		$this->assertSame( 'secret', JetonomyBridge::forum_type_for( array( 'type' => 'secret', 'is_gated' => true ) ) );
		$this->assertSame( 'private', JetonomyBridge::discussion_visibility_for( JetonomyBridge::forum_type_for( array( 'type' => 'open', 'is_gated' => true ) ) ) );
	}

	/**
	 * Rail item keys by rail key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function rail_items(): array {
		return array_column( $this->bridge->inject_discussions_nav_item( array() ), null, 'key' );
	}

	/**
	 * The forum keeps the "discussions" rail key (so saved nav overrides still apply)
	 * and reaches guests; "my-discussions" is the member's own tab, logged in only.
	 *
	 * @return void
	 */
	public function test_rail_links_the_forum_for_everyone_and_my_discussions_for_members(): void {
		wp_set_current_user( 0 );
		$guest = $this->rail_items();
		$this->assertSame( array( 'discussions' ), array_keys( $guest ) );
		$this->assertSame( trailingslashit( home_url( '/community' ) ), $guest['discussions']['url'] );
		$this->assertSame( 60, $guest['discussions']['order'] );
		$this->assertArrayNotHasKey( 'active', $guest['discussions'] );
		$this->assertArrayNotHasKey( 'group', $guest['discussions'] );

		wp_set_current_user( $this->user_id );
		$member = $this->rail_items();
		$this->assertSame( array( 'discussions', 'my-discussions' ), array_keys( $member ) );
		$this->assertSame( 'you', $member['my-discussions']['group'] );
		$this->assertSame( 206, $member['my-discussions']['order'] );
		$this->assertStringEndsWith( '/discussions/', $member['my-discussions']['url'] );
		$this->assertStringContainsString( (string) wp_parse_url( \BuddyNext\Core\PageRouter::profile_url( $this->user_id ), PHP_URL_PATH ), $member['my-discussions']['url'] );
	}

	/**
	 * Integration Settings > Show in navigation off hides both rail items.
	 *
	 * @return void
	 */
	public function test_rail_links_follow_the_navigation_switch(): void {
		wp_set_current_user( $this->user_id );
		update_option( 'buddynext_integration_jetonomy_nav', '0' );
		$this->assertSame( array(), $this->rail_items() );
	}

	/**
	 * A linked forum is on unless the owner explicitly switched it off: a link made
	 * before the flag existed must not lose its tab.
	 *
	 * @return void
	 */
	public function test_a_linked_forum_is_on_unless_explicitly_switched_off(): void {
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$this->user_id,
			array(
				'name' => 'Flag',
				'slug' => 'flag-' . wp_rand( 1000, 99999 ),
				'type' => 'open',
			)
		);
		$this->assertFalse( $this->bridge->space_discussion_enabled( $space_id ), 'No forum linked.' );

		update_space_meta( $space_id, 'jetonomy_forum_id', 5150 );
		delete_space_meta( $space_id, 'discussion_enabled' );
		$this->assertTrue( $this->bridge->space_discussion_enabled( $space_id ), 'Linked, flag unset.' );
		$this->assertTrue( $this->bridge->space_discussion_status( $space_id )['enabled'], 'The settings switch shows on.' );
		$this->assertNotNull( $this->resolve_space_discussions_url( $space_id ), 'The tab shows.' );

		update_space_meta( $space_id, 'discussion_enabled', '0' );
		$this->assertFalse( $this->bridge->space_discussion_enabled( $space_id ), "Linked, flag '0'." );

		update_space_meta( $space_id, 'discussion_enabled', '1' );
		$this->assertTrue( $this->bridge->space_discussion_enabled( $space_id ), "Linked, flag '1'." );
	}

	/**
	 * An open space with a linked forum holding one thread.
	 *
	 * @param string $type Space type.
	 * @return array{space:int,owner:int,forum:int,slug:string}
	 */
	private function space_with_forum( string $type = 'open' ): array {
		global $wpdb;
		$owner    = self::factory()->user->create();
		$name     = 'Forum space ' . wp_rand( 1000, 99999 );
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create(
			$owner,
			array(
				'name' => $name,
				'slug' => sanitize_title( $name ),
				'type' => $type,
			)
		);
		$slug = 'jt-' . $space_id;
		$wpdb->insert(
			$wpdb->prefix . 'jt_spaces',
			array(
				'slug'  => $slug,
				'title' => $name,
			)
		);
		$forum = (int) $wpdb->insert_id;
		update_space_meta( $space_id, 'jetonomy_forum_id', $forum );
		$wpdb->insert(
			$wpdb->prefix . 'jt_posts',
			array(
				'id'        => 9000 + $forum,
				'space_id'  => $forum,
				'author_id' => $owner,
				'slug'      => 'first-thread',
				'title'     => 'First thread',
			)
		);
		wp_cache_flush();

		return array(
			'space' => $space_id,
			'owner' => $owner,
			'forum' => $forum,
			'slug'  => $slug,
		);
	}

	/**
	 * Render the space Discussions panel for a viewer.
	 *
	 * @param int $space_id Space.
	 * @param int $viewer   Viewer.
	 * @return string
	 */
	private function space_panel_html( int $space_id, int $viewer ): string {
		wp_set_current_user( $viewer );
		ob_start();
		$this->bridge->render_space_discussions_panel( $space_id, $viewer );
		return (string) ob_get_clean();
	}

	/**
	 * With threads present the panel header offers "Start a discussion" to someone
	 * who may post, and never to someone who may not; the forum link is there for both.
	 *
	 * @return void
	 */
	public function test_start_a_discussion_is_in_the_header_only_for_a_poster(): void {
		$fx       = $this->space_with_forum();
		$composer = '/community/s/' . $fx['slug'] . '/new/';

		$owner = $this->space_panel_html( $fx['space'], $fx['owner'] );
		$this->assertStringContainsString( 'First thread', $owner );
		$this->assertStringContainsString( $composer, $owner );
		$this->assertStringContainsString( 'Open in Community', $owner );

		$outsider = $this->space_panel_html( $fx['space'], $this->user_id );
		$this->assertStringContainsString( 'First thread', $outsider );
		$this->assertStringNotContainsString( $composer, $outsider );
		$this->assertStringContainsString( 'Open in Community', $outsider );
	}

	/**
	 * An empty linked forum still links to the forum, and its one "Start a
	 * discussion" is the empty-state CTA, not a second header button.
	 *
	 * @return void
	 */
	public function test_an_empty_forum_links_to_the_forum_with_one_start_button(): void {
		global $wpdb;
		$fx = $this->space_with_forum();
		$wpdb->delete( $wpdb->prefix . 'jt_posts', array( 'space_id' => $fx['forum'] ) );
		wp_cache_flush();

		$html = $this->space_panel_html( $fx['space'], $fx['owner'] );
		$this->assertStringContainsString( 'Open in Community', $html );
		$this->assertSame( 1, substr_count( $html, '/new/' ) );
	}

	/**
	 * Render the owner hint above the About panel.
	 *
	 * @param int $space_id Space.
	 * @param int $viewer   Viewer.
	 * @return string
	 */
	private function owner_hint_html( int $space_id, int $viewer ): string {
		wp_set_current_user( $viewer );
		ob_start();
		$this->bridge->render_discussion_owner_hint( array( 'space' => ( new \BuddyNext\Spaces\SpaceService() )->get_object( $space_id ) ) );
		return (string) ob_get_clean();
	}

	/**
	 * Only the owner of a space with no forum is pointed at the Discussion switch.
	 *
	 * @return void
	 */
	public function test_turn_on_discussions_hint_is_for_the_owner_of_a_forumless_space(): void {
		$team = $this->space_with_team();

		$owner = $this->owner_hint_html( $team['space'], $team['owner'] );
		$this->assertStringContainsString( 'Turn on Discussions', $owner );
		$this->assertStringContainsString( 'bn_stab=integrations', $owner );

		$this->assertSame( '', $this->owner_hint_html( $team['space'], $team['moderator'] ) );
		$this->assertSame( '', $this->owner_hint_html( $team['space'], $this->user_id ) );
		$this->assertSame( '', $this->owner_hint_html( $team['space'], 0 ) );

		update_space_meta( $team['space'], 'jetonomy_forum_id', 6160 );
		update_space_meta( $team['space'], 'discussion_enabled', '0' );
		$this->assertSame( '', $this->owner_hint_html( $team['space'], $team['owner'] ), 'A forum switched off is a choice already made.' );
	}

	/**
	 * Run the breadcrumb filter on a Jetonomy route.
	 *
	 * @param array<string,string> $vars Query vars.
	 * @param int                  $viewer Viewer.
	 * @return string
	 */
	private function crumb_html( array $vars, int $viewer ): string {
		wp_set_current_user( $viewer );
		foreach ( array( 'jetonomy_route', 'jetonomy_slug', 'jetonomy_space_slug' ) as $var ) {
			set_query_var( $var, $vars[ $var ] ?? '' );
		}
		return $this->bridge->add_space_breadcrumb( self::CRUMB );
	}

	/**
	 * Jetonomy's breadcrumb as its partial renders it.
	 *
	 * @var string
	 */
	private const CRUMB = '<nav class="jt-crumb" aria-label="Breadcrumb"><a href="http://example.org/community/">Home</a><span aria-hidden="true">/</span><span aria-current="page" dir="auto">Forum</span></nav>';

	/**
	 * A linked forum's pages lead back to its Space, on the forum and topic routes.
	 *
	 * @return void
	 */
	public function test_breadcrumb_leads_back_to_the_linked_space(): void {
		$fx    = $this->space_with_forum();
		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $fx['space'] );
		$back  = '<a href="' . esc_url( buddynext_space_url( (string) $space['slug'] ) ) . '" dir="auto">Back to ' . esc_html( (string) $space['name'] ) . '</a> <span aria-hidden="true">/</span> ';

		$forum = $this->crumb_html(
			array(
				'jetonomy_route' => 'space',
				'jetonomy_slug'  => $fx['slug'],
			),
			$this->user_id
		);
		$this->assertStringStartsWith( '<nav class="jt-crumb" aria-label="Breadcrumb">' . $back . '<a href="http://example.org/community/">Home</a>', $forum, 'The link leads the trail, inside Jetonomy\'s nav.' );

		$topic = $this->crumb_html(
			array(
				'jetonomy_route'      => 'post',
				'jetonomy_space_slug' => $fx['slug'],
				'jetonomy_slug'       => 'first-thread',
			),
			0
		);
		$this->assertStringContainsString( $back, $topic, 'Guests see the link to a space they may view.' );
	}

	/**
	 * Unlinked forum, a Space the viewer may not see, and an unknown route all
	 * leave the trail untouched.
	 *
	 * @return void
	 */
	public function test_breadcrumb_is_unchanged_when_the_space_cannot_be_shown(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'jt_spaces',
			array(
				'slug'  => 'loose-forum',
				'title' => 'Loose',
			)
		);
		$this->assertSame(
			self::CRUMB,
			$this->crumb_html(
				array(
					'jetonomy_route' => 'space',
					'jetonomy_slug'  => 'loose-forum',
				),
				$this->user_id
			),
			'Unlinked forum.'
		);

		$secret = $this->space_with_forum( 'secret' );
		$vars   = array(
			'jetonomy_route' => 'space',
			'jetonomy_slug'  => $secret['slug'],
		);
		$this->assertSame( self::CRUMB, $this->crumb_html( $vars, $this->user_id ), 'A secret space is never named to a non-member.' );
		$this->assertSame( self::CRUMB, $this->crumb_html( $vars, 0 ), 'Nor to a guest.' );
		$this->assertStringContainsString( 'Back to', $this->crumb_html( $vars, $secret['owner'] ), 'Its owner gets the link.' );

		$this->assertSame( self::CRUMB, $this->crumb_html( array( 'jetonomy_route' => 'profile', 'jetonomy_slug' => $secret['slug'] ), $secret['owner'] ), 'Not a forum route.' );
		$this->assertSame( self::CRUMB, $this->crumb_html( array( 'jetonomy_route' => 'space', 'jetonomy_slug' => 'no-such-forum' ), $this->user_id ), 'Unknown forum.' );
		$this->assertSame( '', $this->bridge->add_space_breadcrumb( '' ), 'No breadcrumb at all.' );
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

		/**
		 * Look a forum up. The stub keeps no rows to read back, so nothing is found
		 * and the visibility sync has nothing to align.
		 *
		 * @param int $id Forum id.
		 * @return object|null
		 */
		public static function find( int $id ): ?object {
			unset( $id );
			return null;
		}

		/**
		 * Look a forum up by slug in the shadow jt_spaces table.
		 *
		 * @param string $slug Forum slug.
		 * @return object|null
		 */
		public static function find_by_slug( string $slug ): ?object {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE slug = %s", $slug ) );
			return $id > 0 ? (object) array( 'id' => $id ) : null;
		}

		/**
		 * Settings written through update(), by forum id.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public static array $settings = array();

		/**
		 * Read a forum's settings.
		 *
		 * @param int $id Forum id.
		 * @return array<string,mixed>
		 */
		public static function get_settings( int $id ): array {
			return self::$settings[ $id ] ?? array();
		}

		/**
		 * Merge settings the way the model does: an empty value removes the key.
		 *
		 * @param int                 $id       Forum id.
		 * @param array<string,mixed> $incoming Settings to merge.
		 * @return array<string,mixed>
		 */
		public static function merge_settings( int $id, array $incoming ): array {
			return array_filter( array_merge( self::get_settings( $id ), $incoming ), static fn( $v ) => '' !== $v );
		}

		/**
		 * Update a forum.
		 *
		 * @param int                 $id   Forum id.
		 * @param array<string,mixed> $data Columns to write.
		 * @return void
		 */
		public static function update( int $id, array $data ): void {
			self::$settings[ $id ] = (array) json_decode( (string) ( $data['settings'] ?? '{}' ), true );
		}
	}
}

if ( ! class_exists( __NAMESPACE__ . '\\Post' ) ) {
	/**
	 * Test stub for Jetonomy's Post model. The bridge only checks that the class
	 * exists before it reads jt_posts; it never calls it.
	 */
	class Post {}
}
