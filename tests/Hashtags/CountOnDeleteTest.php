<?php
/**
 * Deleting posts lowers their hashtags' post_count.
 *
 * PostService's delete cascade removed the bn_post_hashtags rows BEFORE
 * buddynext_post_deleted fired, so the listener's sync() found nothing to
 * recount: a tag page said "Posts 15" over "No posts yet". The cascade also
 * deleted by post_id alone, taking the links of media / discussions that share
 * the table and happen to have the same numeric id.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Hashtags\HashtagService;

/**
 * Hashtag counts on delete.
 *
 * @covers \BuddyNext\Feed\PostService::delete
 * @covers \BuddyNext\Feed\PostService::delete_by_link
 * @covers \BuddyNext\Hashtags\HashtagService::recount
 */
class CountOnDeleteTest extends \WP_UnitTestCase {

	/**
	 * Hashtag service.
	 *
	 * @var HashtagService
	 */
	private $tags;

	/**
	 * Post author.
	 *
	 * @var int
	 */
	private $author = 0;

	/**
	 * Per-test tag (DDL in Installer::run() commits, so tests must not share one).
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Posts this test inserted.
	 *
	 * @var int[]
	 */
	private $created_posts = array();

	/**
	 * Schema, a per-test tag and an author.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->slug   = 'qadel' . substr( md5( $this->getName() ), 0, 8 );
		$this->tags   = new HashtagService();
		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->author );
	}

	/**
	 * Undo committed rows after the rollback (see CountMatchesListTest).
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;
		$posts = $this->created_posts;
		$slug  = $this->slug;
		parent::tear_down();
		if ( $posts ) {
			$ids = implode( ',', array_map( 'absint', $posts ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_post_hashtags WHERE post_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_posts WHERE id IN ({$ids})" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->delete( $wpdb->prefix . 'bn_hashtags', array( 'slug' => $slug ) );
		$this->created_posts = array();
	}

	/**
	 * Create a published post carrying the tag, and index it.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return int Post id.
	 */
	private function tagged_post( array $overrides = array() ): int {
		global $wpdb;
		$row = array_merge(
			array(
				'user_id'    => $this->author,
				'content'    => 'A post about #' . $this->slug,
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			$overrides
		);
		$wpdb->insert( $wpdb->prefix . 'bn_posts', $row );
		$post_id               = (int) $wpdb->insert_id;
		$this->created_posts[] = $post_id;
		$this->tags->sync( 'post', $post_id, $this->tags->extract( (string) $row['content'] ) );
		return $post_id;
	}

	/**
	 * The count the tag page reads (through the slug cache).
	 *
	 * @return int
	 */
	private function page_count(): int {
		return (int) ( $this->tags->get_by_slug( $this->slug )['post_count'] ?? -1 );
	}

	/**
	 * Deleting posts one by one walks the count down to zero, cache included.
	 *
	 * @return void
	 */
	public function test_deleting_posts_lowers_the_count(): void {
		$posts = array( $this->tagged_post(), $this->tagged_post(), $this->tagged_post() );
		$this->assertSame( 3, $this->page_count(), 'Primes the cached row.' );

		$service = new PostService();
		foreach ( array( 2, 1, 0 ) as $i => $expected ) {
			$this->assertTrue( true === $service->delete( $posts[ $i ], $this->author ) );
			$this->assertSame( $expected, $this->page_count() );
		}
	}

	/**
	 * A bulk delete (a bridge removing its cards) lowers the count too.
	 *
	 * @return void
	 */
	public function test_bulk_delete_lowers_the_count(): void {
		$link = 'https://example.test/qa-' . $this->slug;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->tagged_post(
				array(
					'type'     => 'link',
					'link_url' => $link,
				)
			);
		}
		$this->assertSame( 3, $this->page_count() );

		$this->assertSame( 3, ( new PostService() )->delete_by_link( 'link', $link ) );
		$this->assertSame( 0, $this->page_count() );
	}

	/**
	 * Deleting post N leaves the tag links of media N (same table, other type).
	 *
	 * @return void
	 */
	public function test_other_objects_with_the_same_id_keep_their_tags(): void {
		global $wpdb;
		$post_id = $this->tagged_post();
		$this->tags->sync( 'mvs_media', $post_id, array( $this->slug ) );

		( new PostService() )->delete( $post_id, $this->author );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_post_hashtags WHERE post_id = %d AND object_type = 'mvs_media'", $post_id ) );
		$this->assertSame( 1, $left, 'The media item keeps its hashtag.' );
	}

	/**
	 * The upgrade recount repairs counts left behind before the fix: a tag whose
	 * posts were all deleted goes back to 0, a tag with a live post keeps its count.
	 *
	 * @covers \BuddyNext\Hashtags\HashtagListener::recount_batch
	 * @return void
	 */
	public function test_upgrade_recount_repairs_ghost_counts(): void {
		global $wpdb;
		$this->tagged_post();
		$ghost = $this->slug . 'gone';
		$this->tags->sync( 'post', 987654321, array( $ghost ) ); // A link whose post no longer exists.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_hashtags SET post_count = 15 WHERE slug IN ( %s, %s )", $this->slug, $ghost ) );
		$wpdb->delete( $wpdb->prefix . 'bn_post_hashtags', array( 'post_id' => 987654321 ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		( new \BuddyNext\Hashtags\HashtagListener( $this->tags ) )->recount_batch( 0 );

		$this->assertSame( 1, $this->page_count(), 'A tag with a live post keeps its real count.' );
		$this->assertSame( 0, (int) ( $this->tags->get_by_slug( $ghost )['post_count'] ?? -1 ), 'A tag whose posts are gone drops to zero.' );
		$wpdb->delete( $wpdb->prefix . 'bn_hashtags', array( 'slug' => $ghost ) );
	}
}
