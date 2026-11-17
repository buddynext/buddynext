<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The cached comment thread follows new comments on a persistent object cache.
 *
 * Two failures of the old generation counter are locked here:
 *  - Redis and Memcached return an integer as a numeric string, and the counter
 *    refused anything but a PHP int, so it read 0 forever (1.2.6);
 *  - the counter was stored with a 300-second TTL and seeded at 0, so when it
 *    expired the next comment bumped it back onto a number an older thread was
 *    still cached under, and that older thread was served again (1.3.0).
 *
 * @package BuddyNext\Tests\Comments
 * @since 1.2.6
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Comments;

use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Locks thread invalidation whatever the cache hands back or forgets.
 */
class CommentListCacheGenerationTest extends WP_UnitTestCase {

	/**
	 * Comments cache group.
	 */
	private const GROUP = 'buddynext_comments';

	/**
	 * A fresh post, by a fresh member who is logged in.
	 *
	 * @return array{0:int,1:int} Member and post ids.
	 */
	private function post(): array {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$post = ( new PostService() )->create(
			$user,
			array(
				'type'    => 'text',
				'content' => 'Who is bringing chairs to the park picnic?',
			)
		);
		return array( $user, is_array( $post ) ? (int) $post['id'] : (int) $post );
	}

	/**
	 * Forget the thread's invalidation key, as an eviction or an expiry would.
	 *
	 * Both the pre-1.3.0 counter key and the current salt key are dropped so this
	 * models the same event on either code.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	private function forget_thread_key( int $post_id ): void {
		wp_cache_delete( "gen_post_{$post_id}", self::GROUP );
		wp_cache_delete( "last_changed:thread:post:{$post_id}", self::GROUP );
	}

	/**
	 * A new comment shows in the thread when the stored key comes back as a string.
	 *
	 * @return void
	 */
	public function test_thread_shows_new_comment_when_cache_returns_strings(): void {
		list( $user, $post_id ) = $this->post();
		$comments               = buddynext_service( 'comments' );
		$key                    = "last_changed:thread:post:{$post_id}";

		$comments->create( $user, 'post', $post_id, 'I can bring four' );
		// What a persistent cache hands back on the next request.
		wp_cache_set( $key, (string) wp_cache_get( $key, self::GROUP ), self::GROUP );
		$this->assertSame( 1, (int) $comments->list_for_object( 'post', $post_id )['total'] );

		$comments->create( $user, 'post', $post_id, 'Two from me' );
		wp_cache_set( $key, (string) wp_cache_get( $key, self::GROUP ), self::GROUP );
		$this->assertSame( 2, (int) $comments->list_for_object( 'post', $post_id )['total'], 'second comment reaches the cached thread' );
	}

	/**
	 * After the invalidation key is lost, a later change never re-serves an older thread.
	 *
	 * @return void
	 */
	public function test_lost_invalidation_key_never_resurrects_an_older_thread(): void {
		list( $user, $post_id ) = $this->post();
		$comments               = buddynext_service( 'comments' );

		$comments->create( $user, 'post', $post_id, 'I can bring four' );
		$this->assertSame( 1, (int) $comments->list_for_object( 'post', $post_id )['total'], 'thread cached with one comment' );

		$this->forget_thread_key( $post_id );
		$comments->create( $user, 'post', $post_id, 'Two from me' );

		$this->assertSame(
			2,
			(int) $comments->list_for_object( 'post', $post_id )['total'],
			'the one-comment thread cached before the key was lost must not come back'
		);
	}
}
