<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Importer seam: ReactionService::react() accepts a backdated created_at.
 *
 * @package BuddyNext\Tests\Reactions
 * @since 1.1.0
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Reactions;

use BuddyNext\Feed\PostService;
use BuddyNext\Reactions\ReactionService;
use WP_UnitTestCase;

/**
 * Locks created_at pass-through on react().
 */
class ReactionBackdateTest extends WP_UnitTestCase {

	/**
	 * A supplied past created_at is stored on the reaction row.
	 *
	 * @return void
	 */
	public function test_react_honours_backdated_created_at(): void {
		global $wpdb;

		$user_id = self::factory()->user->create();
		$post_id = ( new PostService() )->create(
			$user_id,
			array(
				'type'    => 'text',
				'content' => 'Fixture post',
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $post_id );

		$service = new ReactionService();
		$this->assertTrue( $service->react( $user_id, 'post', $post_id, 'like', '2022-05-05 05:05:05' ) );
		$this->assertSame(
			'2022-05-05 05:05:05',
			$wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_reactions WHERE user_id = %d AND object_type = 'post' AND object_id = %d", $user_id, $post_id ) )
		);
	}
}
