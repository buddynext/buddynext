<?php
/**
 * Which posts may be shared outside the community.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\PrivateCommunity;
use BuddyNext\Feed\PostService;
use BuddyNext\Spaces\SpaceService;

/**
 * PostService::is_publicly_shareable(): true only when anyone with the link sees the post.
 *
 * @covers \BuddyNext\Feed\PostService::is_publicly_shareable
 */
class PostShareableTest extends \WP_UnitTestCase {

	/**
	 * A public post on a public site is shareable; every narrower audience is not.
	 *
	 * @return void
	 */
	public function test_only_posts_anyone_can_open_are_shareable(): void {
		$service = new PostService();
		$author  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$public  = $service->get( (int) $service->create( $author, array( 'content' => 'Public post' ) ) );
		$follow  = $service->get( (int) $service->create( $author, array( 'content' => 'Followers post', 'privacy' => 'followers' ) ) );
		$members = $service->get( (int) $service->create( $author, array( 'content' => 'Members only post', 'members_only' => 1 ) ) );

		$spaces  = new SpaceService();
		$open    = (int) $spaces->create( $author, array( 'name' => 'Open share', 'slug' => 'open-share-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );
		$private = (int) $spaces->create( $author, array( 'name' => 'Private share', 'slug' => 'private-share-' . wp_rand( 1000, 9999 ), 'type' => 'private' ) );
		$in_open = array_merge( $public, array( 'space_id' => $open ) );
		$in_priv = array_merge( $public, array( 'space_id' => $private ) );

		$this->assertTrue( $service->is_publicly_shareable( $public ) );
		$this->assertTrue( $service->is_publicly_shareable( $in_open ), 'An open space shows its posts to guests.' );
		$this->assertFalse( $service->is_publicly_shareable( $follow ) );
		$this->assertFalse( $service->is_publicly_shareable( $members ) );
		$this->assertFalse( $service->is_publicly_shareable( $in_priv ) );
		$this->assertFalse( $service->is_publicly_shareable( array_merge( $public, array( 'status' => 'scheduled' ) ) ) );

		update_option( PrivateCommunity::OPTION, '1' );
		$this->assertFalse( $service->is_publicly_shareable( $public ), 'A private community shares nothing outside.' );
		delete_option( PrivateCommunity::OPTION );
	}
}
