<?php
/**
 * Tests that the search index follows post edits, privacy flips, and account
 * search-visibility changes.
 *
 * Regression guard for card 10062110525: buddynext_post_updated was fired but
 * never consumed, so edited posts kept stale content and public->private flips
 * stayed publicly searchable.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchIndexListener;
use BuddyNext\SocialGraph\FollowService;

/**
 * @covers \BuddyNext\Search\SearchIndexListener
 */
class SearchIndexUpdateTest extends \WP_UnitTestCase {

	private SearchIndexListener $listener;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->listener = new SearchIndexListener();
	}

	/**
	 * Insert a published post and return its id.
	 *
	 * @param int    $author  Author user id.
	 * @param string $content Post content.
	 * @param string $privacy Post privacy.
	 * @return int Post id.
	 */
	private function seed_post( int $author, string $content, string $privacy = 'public' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $author,
				'content'    => $content,
				'type'       => 'text',
				'privacy'    => $privacy,
				'status'     => 'published',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array{content:?string,visibility:?string}
	 */
	private function index_row( int $post_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT content, visibility FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				$post_id
			),
			ARRAY_A
		);
		return array(
			'content'    => $row['content'] ?? null,
			'visibility' => $row['visibility'] ?? null,
		);
	}

	public function test_listener_hooks_update_and_visibility_events(): void {
		$this->listener->register();
		$this->assertNotFalse( has_action( 'buddynext_post_updated', array( $this->listener, 'on_post_updated' ) ) );
		$this->assertNotFalse( has_action( 'buddynext_user_search_visibility_changed', array( $this->listener, 'on_user_search_visibility_changed' ) ) );
	}

	public function test_edit_updates_content_and_privacy_flip_hides_post(): void {
		global $wpdb;
		$author = self::factory()->user->create();
		$post   = $this->seed_post( $author, 'ORIGINALTEXT apple', 'public' );

		$this->listener->async_index_post( $post, $author );
		$this->assertSame( 'public', $this->index_row( $post )['visibility'] );
		$this->assertStringContainsString( 'ORIGINALTEXT', (string) $this->index_row( $post )['content'] );

		// Edit content + flip privacy to private, then re-index.
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'content' => 'EDITEDTEXT banana',
				'privacy' => 'private',
			),
			array( 'id' => $post )
		);
		$this->listener->async_index_post( $post, $author );

		$row = $this->index_row( $post );
		$this->assertSame( 'private', $row['visibility'], 'a public->private flip must leave the public index' );
		$this->assertStringContainsString( 'EDITEDTEXT', (string) $row['content'] );
		$this->assertStringNotContainsString( 'ORIGINALTEXT', (string) $row['content'], 'stale content must be replaced' );
	}

	public function test_private_account_hides_a_public_post_on_reindex(): void {
		$author = self::factory()->user->create();
		$post   = $this->seed_post( $author, 'visible text', 'public' );

		update_user_meta( $author, FollowService::PRIVATE_META, '1' );
		$this->listener->async_index_post( $post, $author );

		$this->assertSame( 'private', $this->index_row( $post )['visibility'], 'a private account hides even a public post from global search' );
	}
}
