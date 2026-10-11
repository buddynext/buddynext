<?php
/**
 * "Delete anyone's post" lets the holder delete a post that is not theirs.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * Moderator delete tests.
 *
 * @covers \BuddyNext\Feed\PostService::delete
 */
class ModeratorDeleteAnyPostTest extends \WP_UnitTestCase {

	/**
	 * Post service.
	 *
	 * @var PostService
	 */
	private PostService $posts;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts = new PostService();
	}

	/**
	 * Create a public post outside any space.
	 *
	 * @return int
	 */
	private function feed_post(): int {
		$id = $this->posts->create(
			self::factory()->user->create(),
			array(
				'type'    => 'text',
				'content' => 'A post on the main feed',
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * A community moderator, who holds the capability by default, can delete it.
	 *
	 * @return void
	 */
	public function test_moderator_can_delete_a_feed_post(): void {
		$moderator = self::factory()->user->create();
		buddynext_service( 'roles' )->set_role( $moderator, 'moderator' );

		$this->assertTrue( $this->posts->delete( $this->feed_post(), $moderator ) );
	}

	/**
	 * An ordinary member still cannot delete someone else's post.
	 *
	 * @return void
	 */
	public function test_member_cannot_delete_someone_elses_post(): void {
		$result = $this->posts->delete( $this->feed_post(), self::factory()->user->create() );

		$this->assertWPError( $result );
		$this->assertSame( 'not_post_owner', $result->get_error_code() );
	}
}
