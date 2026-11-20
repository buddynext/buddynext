<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * An article written for a space: its one feed card follows the space choice.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\BlogPostListener;
use BuddyNext\Feed\PostService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Feed\BlogPostListener
 * @covers \BuddyNext\Feed\PostService::move_space_type_to_site
 */
class BlogPostSpaceTest extends \WP_UnitTestCase {

	private int $owner;
	private int $writer;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner  = self::factory()->user->create();
		$this->writer = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	private function space( string $type = 'open' ): int {
		$id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Blog space ' . $type,
				'slug' => 'blog-space-' . $type . '-' . wp_rand( 10000, 99999 ),
				'type' => $type,
			)
		);
		$this->assertIsInt( $id );
		( new SpaceMemberService() )->join( (int) $id, $this->writer );
		return (int) $id;
	}

	private function article( int $space_id, array $extra = array() ): int {
		return (int) wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Space article',
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
	 * The card's space and privacy, read from the row.
	 *
	 * @return array{0:int,1:string}
	 */
	private function card_space( int $post_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT space_id, privacy FROM {$wpdb->prefix}bn_posts WHERE id = %d", BlogPostListener::card_id_for_post( $post_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array( (int) ( $row['space_id'] ?? 0 ), (string) ( $row['privacy'] ?? '' ) );
	}

	private function card_count( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE type = %s AND JSON_EXTRACT( link_meta, '$.source_post_id' ) = %d", BlogPostListener::TYPE, $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_card_is_published_into_a_public_space(): void {
		$space   = $this->space();
		$post_id = $this->article( $space );

		$this->assertSame( array( $space, 'space_members' ), $this->card_space( $post_id ) );
	}

	public function test_card_moves_on_change_and_returns_site_wide_on_clear_one_card_throughout(): void {
		$first   = $this->space();
		$second  = $this->space();
		$post_id = $this->article( $first );
		$card    = BlogPostListener::card_id_for_post( $post_id );

		update_post_meta( $post_id, BlogPostListener::SPACE_META, $second );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Moved' ) );
		$this->assertSame( array( $second, 'space_members' ), $this->card_space( $post_id ) );

		update_post_meta( $post_id, BlogPostListener::SPACE_META, 0 );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Cleared' ) );
		$this->assertSame( array( 0, 'public' ), $this->card_space( $post_id ) );

		update_post_meta( $post_id, BlogPostListener::SPACE_META, $first );
		for ( $i = 0; $i < 10; $i++ ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Save ' . $i ) );
		}
		$this->assertSame( 1, $this->card_count( $post_id ), 'one card per post, whatever the saves' );
		$this->assertSame( $card, BlogPostListener::card_id_for_post( $post_id ), 'the same card moved, never a second' );
		$this->assertSame( array( $first, 'space_members' ), $this->card_space( $post_id ) );
	}

	public function test_private_and_secret_spaces_are_refused(): void {
		foreach ( array( 'private', 'secret' ) as $type ) {
			$post_id = $this->article( $this->space( $type ) );
			$this->assertSame( array( 0, 'public' ), $this->card_space( $post_id ), "a {$type} space gets no article" );
		}
	}

	public function test_a_non_member_or_unknown_space_is_refused(): void {
		$space = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Not joined',
				'slug' => 'not-joined-' . wp_rand( 10000, 99999 ),
				'type' => 'open',
			)
		);
		$this->assertSame( array( 0, 'public' ), $this->card_space( $this->article( (int) $space ) ) );
		$this->assertSame( array( 0, 'public' ), $this->card_space( $this->article( 999999 ) ) );
	}

	public function test_who_can_post_owner_blocks_a_member_but_not_the_owner(): void {
		$space = $this->space();
		update_space_meta( $space, 'who_can_post', 'owner' );
		wp_cache_flush();

		$this->assertFalse( BlogPostListener::space_accepts_article( $space, $this->writer ) );
		$this->assertTrue( BlogPostListener::space_accepts_article( $space, $this->owner ) );
		$this->assertSame( array( 0, 'public' ), $this->card_space( $this->article( $space ) ) );
	}

	public function test_an_archived_space_is_refused(): void {
		$space = $this->space();
		( new SpaceService() )->archive( $space, $this->owner );
		wp_cache_flush();

		$this->assertFalse( BlogPostListener::space_accepts_article( $space, $this->writer ) );
	}

	public function test_an_author_who_left_keeps_the_card_until_the_next_save_moves_it_site_wide(): void {
		$space   = $this->space();
		$post_id = $this->article( $space );
		$card    = BlogPostListener::card_id_for_post( $post_id );

		( new SpaceMemberService() )->leave( $space, $this->writer );
		wp_cache_flush();
		$this->assertSame( $space, $this->card_space( $post_id )[0], 'leaving alone moves nothing' );

		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'After leaving' ) );
		$this->assertSame( array( 0, 'public' ), $this->card_space( $post_id ) );
		$this->assertSame( $card, BlogPostListener::card_id_for_post( $post_id ) );
	}

	public function test_restricted_posts_never_have_a_live_card(): void {
		$space = $this->space();
		foreach ( array( array( 'post_status' => 'draft' ), array( 'post_status' => 'pending' ), array( 'post_status' => 'private' ), array( 'post_password' => 'pw' ), array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) ) as $extra ) {
			$post_id = $this->article( $space, $extra );
			$card    = BlogPostListener::card_id_for_post( $post_id );
			$status  = $card > 0 ? (string) ( ( new PostService() )->get( $card )['status'] ?? '' ) : '';
			$this->assertNotSame( 'published', $status, 'no live card for ' . wp_json_encode( $extra ) );
		}
	}

	public function test_deleting_a_space_moves_its_article_cards_site_wide_instead_of_deleting_them(): void {
		$space   = $this->space();
		$post_id = $this->article( $space );
		$card    = BlogPostListener::card_id_for_post( $post_id );
		$text    = ( new PostService() )->create(
			$this->writer,
			array(
				'type'     => 'text',
				'content'  => 'A space post.',
				'space_id' => $space,
				'privacy'  => 'space_members',
			)
		);
		$this->assertIsInt( $text );

		$this->assertTrue( ( new SpaceService() )->delete( $space, $this->owner ) );

		$this->assertSame( $card, BlogPostListener::card_id_for_post( $post_id ), 'the article card survives' );
		$this->assertSame( array( 0, 'public' ), $this->card_space( $post_id ) );
		$this->assertNull( ( new PostService() )->get( (int) $text ), 'the space\'s own posts still go' );
	}

	public function test_space_delete_hook_moves_cards_in_one_pass_and_is_safe_twice(): void {
		$space = $this->space();
		$ids   = array( $this->article( $space ), $this->article( $space, array( 'post_title' => 'Second' ) ) );

		do_action( 'buddynext_space_delete_queued', $space, $this->owner );
		do_action( 'buddynext_space_deleting', $space, $this->owner );

		foreach ( $ids as $post_id ) {
			$this->assertSame( array( 0, 'public' ), $this->card_space( $post_id ) );
		}
	}
}
