<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods and fixtures.
/**
 * Tests for ShareService.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\ShareService;

/**
 * @covers \BuddyNext\Feed\ShareService
 */
class ShareServiceTest extends \WP_UnitTestCase {

	private ShareService $service;
	private PostService $posts;
	private int $alice;
	private int $bob;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts   = new PostService();
		$this->service = new ShareService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->post_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Original post',
			)
		);
	}

	public function test_share_creates_row(): void {
		$share_id = $this->service->share( $this->bob, $this->post_id, '' );

		$this->assertIsInt( $share_id );
		$this->assertGreaterThan( 0, $share_id );
	}

	public function test_share_with_note(): void {
		$share_id = $this->service->share( $this->bob, $this->post_id, 'Great post!' );
		$shares   = $this->service->user_shares( $this->bob );

		$this->assertContains( $this->post_id, $shares );
	}

	public function test_share_increments_post_share_count(): void {
		$this->service->share( $this->bob, $this->post_id, '' );

		$post = $this->posts->get( $this->post_id );
		$this->assertSame( 1, $post['share_count'] );
	}

	public function test_unshare_removes_row(): void {
		$this->service->share( $this->bob, $this->post_id, '' );
		$this->service->unshare( $this->bob, $this->post_id );

		$shares = $this->service->user_shares( $this->bob );
		$this->assertNotContains( $this->post_id, $shares );
	}

	public function test_unshare_decrements_share_count(): void {
		$this->service->share( $this->bob, $this->post_id, '' );
		$this->service->unshare( $this->bob, $this->post_id );

		$post = $this->posts->get( $this->post_id );
		$this->assertSame( 0, $post['share_count'] );
	}

	public function test_user_shares_returns_list(): void {
		$post2 = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Post 2',
			)
		);
		$this->service->share( $this->bob, $this->post_id, '' );
		$this->service->share( $this->bob, $post2, '' );

		$shares = $this->service->user_shares( $this->bob );

		$this->assertContains( $this->post_id, $shares );
		$this->assertContains( $post2, $shares );
	}

	public function test_duplicate_share_returns_error(): void {
		$this->service->share( $this->bob, $this->post_id, '' );
		$result = $this->service->share( $this->bob, $this->post_id, 'Again' );

		$this->assertWPError( $result );
		$this->assertSame( 'already_shared', $result->get_error_code() );
	}

	public function test_share_count_does_not_go_negative(): void {
		$this->service->unshare( $this->bob, $this->post_id );

		$post = $this->posts->get( $this->post_id );
		$this->assertSame( 0, $post['share_count'] );
	}

	public function test_recount_counters_reconciles_drifted_share_count(): void {
		global $wpdb;

		$this->service->share( $this->bob, $this->post_id, '' ); // share_count -> 1.

		// Corrupt the denormalized counter to simulate drift.
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'share_count' => 42 ),
			array( 'id' => $this->post_id )
		);

		$this->posts->recount_counters( array( $this->post_id ) );

		$post = $this->posts->get( $this->post_id );
		$this->assertSame( 1, $post['share_count'], 'share_count reconciled from bn_shares (S2(c)).' );
	}
}
