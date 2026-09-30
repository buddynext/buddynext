<?php
/**
 * Photo posts follow their WPMediaVerse media: withdraw/restore only what the
 * media lifecycle withdrew, and drop deleted ids (card 10344032853).
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::ids_with_media
 * @covers \BuddyNext\Feed\PostService::set_media_withdrawn
 * @covers \BuddyNext\Feed\PostService::remove_media_id
 */
class PostMediaLifecycleTest extends \WP_UnitTestCase {

	private PostService $posts;
	private int $author = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts  = new PostService();
		$this->author = self::factory()->user->create();
	}

	private function photo_post( array $media_ids, string $status = 'published' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array( 'user_id' => $this->author, 'type' => 'photo', 'content' => '', 'status' => $status, 'privacy' => 'public', 'media_ids' => wp_json_encode( $media_ids ) )
		);
		$id = (int) $wpdb->insert_id;
		$this->posts->index_media( $id, $media_ids );
		return $id;
	}

	private function status( int $post_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ) );
	}

	public function test_withdraw_and_restore_touch_only_what_was_withdrawn(): void {
		$bridge_post  = $this->photo_post( array( 901 ) );
		$member_draft = $this->photo_post( array( 901 ), 'draft' );

		$this->assertSame( array( $bridge_post ), $this->posts->ids_with_media( 901, 'published' ) );
		$this->assertTrue( $this->posts->set_media_withdrawn( $bridge_post, true ) );
		$this->assertSame( 'draft', $this->status( $bridge_post ) );

		foreach ( $this->posts->ids_with_media( 901, 'draft' ) as $id ) {
			$this->posts->set_media_withdrawn( $id, false );
		}
		$this->assertSame( 'published', $this->status( $bridge_post ), 'the withdrawn post comes back' );
		$this->assertSame( 'draft', $this->status( $member_draft ), "a member's own draft is never published by a restore" );
	}

	public function test_one_lookup_covers_every_status_and_skips_deleted_posts(): void {
		global $wpdb;
		$published = $this->photo_post( array( 921 ) );
		$pending   = $this->photo_post( array( 921, 922 ), 'pending' );
		$gone      = $this->photo_post( array( 921 ) );
		$wpdb->delete( $wpdb->prefix . 'bn_posts', array( 'id' => $gone ) );

		$found = $this->posts->ids_with_media( 921, 'published', 'draft', 'pending', 'scheduled', 'under_review' );
		sort( $found );
		$this->assertSame( array( $published, $pending ), $found, 'all statuses in one query; a deleted post is not returned' );
		$this->assertSame( array( $pending ), $this->posts->ids_with_media( 922, 'pending' ) );
		$this->assertSame( array(), $this->posts->ids_with_media( 921, 'draft' ) );
	}

	public function test_remove_media_id_keeps_the_others(): void {
		$post = $this->photo_post( array( 911, 912 ) );
		$this->assertSame( array( 912 ), $this->posts->remove_media_id( $post, 911 ) );
		$this->assertSame( array(), $this->posts->remove_media_id( $post, 912 ) );
		$this->assertSame( array(), $this->posts->ids_with_media( 912, 'published' ) );
	}
}
