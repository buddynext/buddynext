<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * GET /members/{id}/blog honours the profile gate every sibling panel route asks.
 *
 * Runs in a separate process: the bridge is live only while Member Blog's
 * version constant is defined, and a constant cannot be undefined for the
 * tests that need it absent.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\MemberBlogRestController;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\MemberBlogRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberBlogRestControllerTest extends \WP_UnitTestCase {

	private function articles( int $member, int $viewer ): array {
		if ( ! defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_VERSION', 'test' );
		}
		wp_set_current_user( $viewer );
		$request = new \WP_REST_Request( 'GET', '/buddynext/v1/members/' . $member . '/blog' );
		$request->set_param( 'id', $member );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 10 );
		$response = ( new MemberBlogRestController() )->get_articles( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return (array) $response->get_data();
	}

	public function test_a_blocked_viewer_gets_the_empty_read_model(): void {
		Installer::run();
		$author = self::factory()->user->create();
		$viewer = self::factory()->user->create();
		self::factory()->post->create( array( 'post_author' => $author ) );

		$this->assertCount( 1, $this->articles( $author, $viewer )['items'], 'Fixture: the article is listed before the block.' );

		buddynext_service( 'blocks' )->block( $author, $viewer );

		$data = $this->articles( $author, $viewer );
		$this->assertSame( array(), $data['items'], 'A member who blocked the viewer must not have their articles listed to them.' );
		$this->assertSame( 0, $data['total'] );
		$this->assertFalse( $data['is_owner'] );
	}

	public function test_the_owner_always_sees_their_own_articles(): void {
		Installer::run();
		$author = self::factory()->user->create();
		self::factory()->post->create( array( 'post_author' => $author ) );

		$this->assertCount( 1, $this->articles( $author, $author )['items'] );
	}
}
