<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The cached comment thread follows new comments on a persistent object cache.
 *
 * Redis and Memcached return an integer as a numeric string. The thread's
 * generation counter refused anything but a PHP int, so on those sites it
 * read 0 forever and the cached thread never showed a new, deleted or pinned
 * comment until it expired.
 *
 * @package BuddyNext\Tests\Comments
 * @since 1.2.6
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Comments;

use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Locks thread invalidation when the cache hands values back as strings.
 */
class CommentListCacheGenerationTest extends WP_UnitTestCase {

	/**
	 * A new comment shows in the thread when the stored generation comes back as a string.
	 *
	 * @return void
	 */
	public function test_thread_shows_new_comment_when_cache_returns_strings(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$post = ( new PostService() )->create(
			$user,
			array(
				'type'    => 'text',
				'content' => 'Who is bringing chairs to the park picnic?',
			)
		);
		$post_id  = is_array( $post ) ? (int) $post['id'] : (int) $post;
		$comments = buddynext_service( 'comments' );
		$gen_key  = "gen_post_{$post_id}";

		$comments->create( $user, 'post', $post_id, 'I can bring four' );
		// What a persistent cache hands back on the next request.
		wp_cache_set( $gen_key, (string) wp_cache_get( $gen_key, 'buddynext_comments' ), 'buddynext_comments' );
		$this->assertSame( 1, (int) $comments->list_for_object( 'post', $post_id )['total'] );

		$comments->create( $user, 'post', $post_id, 'Two from me' );
		wp_cache_set( $gen_key, (string) wp_cache_get( $gen_key, 'buddynext_comments' ), 'buddynext_comments' );
		$this->assertSame( 2, (int) $comments->list_for_object( 'post', $post_id )['total'], 'second comment reaches the cached thread' );
	}
}
