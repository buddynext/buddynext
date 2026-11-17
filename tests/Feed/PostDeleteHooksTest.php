<?php
/**
 * Delete and unshare hooks carry what a points or streak listener needs.
 *
 * Before 1.3.0: the two bulk delete paths fired buddynext_post_deleted with no
 * author, a moderator delete handed listeners the moderator (so the moderator's
 * streak was busted, not the author's), comments removed with a post fired
 * nothing, and un-sharing fired nothing.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Engagement\StreakService;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\ShareService;

/**
 * Delete / unshare hook contract.
 *
 * @covers \BuddyNext\Feed\PostService::delete
 * @covers \BuddyNext\Feed\PostService::delete_by_link
 * @covers \BuddyNext\Feed\PostService::delete_by_link_meta_int
 * @covers \BuddyNext\Feed\ShareService::unshare
 * @covers \BuddyNext\Engagement\StreakListener::bust_from_post
 */
class PostDeleteHooksTest extends \WP_UnitTestCase {

	/**
	 * Post service.
	 *
	 * @var PostService
	 */
	private PostService $posts;

	/**
	 * Captured hook calls: hook name => list of argument arrays.
	 *
	 * @var array<string,array<int,array<int,mixed>>>
	 */
	private array $fired = array();

	/**
	 * Fresh tables, service and capture listeners.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts = new PostService();
		$this->fired = array();

		foreach ( array( 'buddynext_post_deleted', 'buddynext_post_comments_deleted', 'buddynext_post_unshared', 'buddynext_comment_deleted' ) as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ): void {
					$this->fired[ $hook ][] = $args;
				},
				10,
				5
			);
		}
	}

	/**
	 * A public post by a member, retyped into an integration card when asked.
	 *
	 * @param int                 $author Author.
	 * @param array<string,mixed> $retype Columns to overwrite after create.
	 * @return int
	 */
	private function post_by( int $author, array $retype = array() ): int {
		$id = $this->posts->create(
			$author,
			array(
				'type'    => 'text',
				'content' => 'A post',
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $id );

		if ( $retype ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'bn_posts', $retype, array( 'id' => $id ) );
		}

		return (int) $id;
	}

	/**
	 * Add a comment row directly (the cascade is what is under test, not the comment gate).
	 *
	 * @param int $post_id Post.
	 * @return int Comment id.
	 */
	private function comment_on( int $post_id ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'user_id'     => self::factory()->user->create(),
				'object_type' => 'post',
				'object_id'   => $post_id,
				'content'     => 'A comment',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * delete_by_link() passes the card's author as arg 3 (deleter stays 0).
	 *
	 * @return void
	 */
	public function test_delete_by_link_passes_author_as_third_arg(): void {
		$author = self::factory()->user->create();
		$pid    = $this->post_by(
			$author,
			array(
				'type'     => 'job',
				'link_url' => 'https://example.test/jobs/1',
			)
		);

		$this->assertSame( 1, $this->posts->delete_by_link( 'job', 'https://example.test/jobs/1' ) );

		$this->assertSame( array( array( $pid, 0, $author ) ), $this->fired['buddynext_post_deleted'] ?? array() );
	}

	/**
	 * delete_by_link_meta_int() passes the card's author as arg 3 too.
	 *
	 * @return void
	 */
	public function test_delete_by_link_meta_int_passes_author_as_third_arg(): void {
		$author = self::factory()->user->create();
		$pid    = $this->post_by(
			$author,
			array(
				'type'      => 'event',
				'link_meta' => wp_json_encode( array( 'event_id' => 77 ) ),
			)
		);

		$this->assertSame( 1, $this->posts->delete_by_link_meta_int( 'event', 'event_id', 77 ) );

		$this->assertSame( array( array( $pid, 0, $author ) ), $this->fired['buddynext_post_deleted'] ?? array() );
	}

	/**
	 * A moderator delete keeps the moderator in arg 2 and the author in arg 3, and
	 * busts the AUTHOR's streak, not the moderator's.
	 *
	 * @return void
	 */
	public function test_moderator_delete_busts_the_authors_streak(): void {
		$author    = self::factory()->user->create();
		$moderator = self::factory()->user->create();
		buddynext_service( 'roles' )->set_role( $moderator, 'moderator' );
		$pid = $this->post_by( $author );

		$streaks = new StreakService();
		wp_cache_flush();
		$streaks->summary( $author );
		$streaks->summary( $moderator );
		$this->assertTrue( $this->is_streak_cached( $author ), 'the author summary must be cached before the delete' );

		$this->assertTrue( $this->posts->delete( $pid, $moderator ) );

		$this->assertSame( array( array( $pid, $moderator, $author ) ), $this->fired['buddynext_post_deleted'] ?? array() );
		$this->assertFalse( $this->is_streak_cached( $author ), 'the author lost a post, so their streak must be recomputed' );
		$this->assertTrue( $this->is_streak_cached( $moderator ), 'the moderator posted nothing; their streak stays cached' );
	}

	/**
	 * Is a member's streak summary cached?
	 *
	 * @param int $uid Member.
	 * @return bool
	 */
	private function is_streak_cached( int $uid ): bool {
		$found = false;
		wp_cache_get( 'streak-summary:' . $uid, 'buddynext_user_meta', false, $found );
		return $found;
	}

	/**
	 * The cascade fires one batch hook per post that had comments, with exactly
	 * those comment ids, and never the per-comment hook.
	 *
	 * @return void
	 */
	public function test_cascade_fires_one_batch_hook_per_post_with_comments(): void {
		$author = self::factory()->user->create();
		$url    = 'https://example.test/jobs/2';
		$with   = $this->post_by(
			$author,
			array(
				'type'     => 'job',
				'link_url' => $url,
			)
		);
		$also   = $this->post_by(
			$author,
			array(
				'type'     => 'job',
				'link_url' => $url,
			)
		);
		$none   = $this->post_by( $author );
		$c1     = $this->comment_on( $with );
		$c2     = $this->comment_on( $with );
		$c3     = $this->comment_on( $also );

		$this->assertSame( 2, $this->posts->delete_by_link( 'job', $url ) );
		$this->assertTrue( $this->posts->delete( $none, $author ) );

		$batches = array();
		foreach ( $this->fired['buddynext_post_comments_deleted'] ?? array() as $args ) {
			$ids = $args[0];
			sort( $ids );
			$batches[ $args[1] ] = $ids;
		}
		ksort( $batches );
		$expected = array(
			$with => array( $c1, $c2 ),
			$also => array( $c3 ),
		);
		ksort( $expected );

		$this->assertSame( $expected, $batches, 'one batch per post that had comments; none for the post without' );
		$this->assertArrayNotHasKey( 'buddynext_comment_deleted', $this->fired, 'the per-comment hook must not fire for a cascade' );
	}

	/**
	 * Un-sharing fires buddynext_post_unshared once with the share id; a second
	 * un-share (nothing left to remove) fires nothing.
	 *
	 * @return void
	 */
	public function test_unshare_fires_once_and_not_on_a_noop(): void {
		$original = $this->post_by( self::factory()->user->create() );
		$sharer   = self::factory()->user->create();
		$shares   = new ShareService();
		$this->assertIsInt( $shares->share( $sharer, $original ) );

		global $wpdb;
		$share_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_shares WHERE user_id = %d AND post_id = %d", $sharer, $original ) );
		$this->assertGreaterThan( 0, $share_id );

		$shares->unshare( $sharer, $original );
		$shares->unshare( $sharer, $original );

		$this->assertSame( array( array( $share_id, $original, $sharer ) ), $this->fired['buddynext_post_unshared'] ?? array() );
	}

	/**
	 * Deleting the repost card un-shares through PostService::delete() and the
	 * nested unshare() call; the hook still fires exactly once.
	 *
	 * @return void
	 */
	public function test_deleting_the_repost_card_fires_unshared_once(): void {
		$original = $this->post_by( self::factory()->user->create() );
		$sharer   = self::factory()->user->create();
		$card     = ( new ShareService() )->share( $sharer, $original );
		$this->assertIsInt( $card );

		$this->assertTrue( $this->posts->delete( $card, $sharer ) );

		$this->assertCount( 1, $this->fired['buddynext_post_unshared'] ?? array() );
		$this->assertSame( array( $original, $sharer ), array_slice( $this->fired['buddynext_post_unshared'][0], 1 ) );
	}
}
