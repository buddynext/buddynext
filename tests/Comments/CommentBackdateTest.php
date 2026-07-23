<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Importer seam: CommentService::create() accepts a backdated created_at.
 *
 * Migrations write through the service API only (never raw bn_* SQL), so
 * without this seam every imported comment was stamped with the migration
 * run time (card 10124307318).
 *
 * @package BuddyNext\Tests\Comments
 * @since 1.1.0
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Comments;

use WP_UnitTestCase;

/**
 * Locks the created_at pass-through on comment creation.
 */
class CommentBackdateTest extends WP_UnitTestCase {

	/**
	 * A supplied past created_at is stored verbatim; omitting it stamps now.
	 *
	 * @return void
	 */
	public function test_create_honours_backdated_created_at(): void {
		global $wpdb;

		$user_id = self::factory()->user->create();
		$post_id = $this->seed_post( $user_id );

		$service = buddynext_service( 'comments' );

		$backdated = $service->create( $user_id, 'post', $post_id, 'Imported comment', null, '2020-02-02 02:02:02' );
		$this->assertIsInt( $backdated );
		$this->assertSame(
			'2020-02-02 02:02:02',
			$wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_comments WHERE id = %d", $backdated ) )
		);

		$fresh = $service->create( $user_id, 'post', $post_id, 'Live comment' );
		$this->assertIsInt( $fresh );
		$fresh_ts = strtotime( (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_comments WHERE id = %d", $fresh ) ) . ' UTC' );
		$this->assertGreaterThan( time() - 60, $fresh_ts, 'without the seam argument a comment still stamps now' );
	}

	/**
	 * Seed a bn_posts row for the comment to attach to.
	 *
	 * @param int $user_id Author.
	 * @return int Post id.
	 */
	private function seed_post( int $user_id ): int {
		$post_id = ( new \BuddyNext\Feed\PostService() )->create(
			$user_id,
			array(
				'type'    => 'text',
				'content' => 'Fixture post',
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $post_id );

		return $post_id;
	}
}
