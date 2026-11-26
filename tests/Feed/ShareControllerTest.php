<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing -- concise, self-describing test methods and fixtures.
/**
 * Sharing a share card is gated on the ORIGINAL it shares.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\ShareService;
use WP_REST_Request;

/**
 * A share card is never shared as such: its original is. The REST route checked
 * only the card that was clicked, so through someone's older share card a member
 * could reshare a removed post, or one they cannot see (card 10501719919).
 *
 * @covers \BuddyNext\Feed\ShareController::share
 * @covers \BuddyNext\Feed\ShareService::share
 * @covers \BuddyNext\Feed\ShareService::root_post_id
 */
class ShareControllerTest extends \WP_UnitTestCase {

	private int $author;
	private int $sharer;
	private int $viewer;
	private int $original;
	private int $card;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->author   = self::factory()->user->create();
		$this->sharer   = self::factory()->user->create();
		$this->viewer   = self::factory()->user->create();
		$this->original = (int) ( new PostService() )->create( $this->author, array( 'content' => 'The original' ) );
		$this->card     = (int) ( new ShareService() )->share( $this->sharer, $this->original );
		$this->assertGreaterThan( 0, $this->card );
	}

	private function share_as_viewer( int $post_id ): int {
		wp_set_current_user( $this->viewer );
		return rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/' . $post_id . '/share' ) )->get_status();
	}

	private function share_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT share_count FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->original ) );
	}

	private function viewer_shares(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_shares WHERE user_id = %d", $this->viewer ) );
	}

	public function test_a_removed_original_cannot_be_reshared_through_an_older_share_card(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'bn_posts', array( 'status' => 'deleted' ), array( 'id' => $this->original ) );
		$before = $this->share_count();

		$this->assertSame( 404, $this->share_as_viewer( $this->card ) );
		$this->assertSame( 0, $this->viewer_shares(), 'no share row' );
		$this->assertSame( $before, $this->share_count(), 'share count unchanged' );
	}

	public function test_an_original_whose_author_blocked_the_viewer_cannot_be_reshared(): void {
		buddynext_service( 'blocks' )->block( $this->author, $this->viewer );

		$this->assertSame( 404, $this->share_as_viewer( $this->card ) );
		$this->assertSame( 0, $this->viewer_shares() );
	}

	public function test_a_live_original_is_shared_through_a_share_card(): void {
		$before = $this->share_count();

		$this->assertSame( 200, $this->share_as_viewer( $this->card ) );
		$this->assertSame( $before + 1, $this->share_count(), 'the count rises on the original' );
	}

	public function test_the_service_refuses_a_removed_original_for_callers_without_the_rest_gate(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'bn_posts', array( 'status' => 'deleted' ), array( 'id' => $this->original ) );

		$result = ( new ShareService() )->share( $this->viewer, $this->card );
		$this->assertWPError( $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
		$this->assertSame( $this->original, ( new ShareService() )->root_post_id( $this->card ) );
		$this->assertSame( $this->original, ( new ShareService() )->root_post_id( $this->original ) );
	}
}
