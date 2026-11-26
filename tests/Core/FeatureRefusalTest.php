<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A turned-off feature refuses the same way on every route.
 *
 * @package BuddyNext\Tests\Core
 * @since 1.3.0
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Each feature built its own refusal, and bookmarks answered with a bare
 * `{code}` body and no message. Every refusal now comes from
 * buddynext_feature_refusal(): the documented code, a message, and the feature
 * slug in its data.
 *
 * @covers ::buddynext_feature_refusal
 */
class FeatureRefusalTest extends WP_UnitTestCase {

	/**
	 * Log in a member and boot the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Drop the feature overrides.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array( 'bookmarks', 'polls', 'reactions', 'comments', 'hashtags' ) as $slug ) {
			remove_all_filters( "buddynext_feature_{$slug}" );
		}
		parent::tear_down();
	}

	/**
	 * Null while on, a coded error carrying the slug while off.
	 */
	public function test_helper_returns_null_when_on_and_a_coded_error_when_off(): void {
		$this->assertNull( buddynext_feature_refusal( 'bookmarks' ) );

		add_filter( 'buddynext_feature_bookmarks', '__return_false' );
		$err = buddynext_feature_refusal( 'bookmarks' );
		$this->assertWPError( $err );
		$this->assertSame( 'bookmarks_disabled', $err->get_error_code() );
		$this->assertSame( 403, $err->get_error_data()['status'] );
		$this->assertSame( 'bookmarks', $err->get_error_data()['feature'] );
		$this->assertNotSame( '', $err->get_error_message() );
	}

	/**
	 * Every converted route answers with its documented code, a message and the slug.
	 */
	public function test_routes_refuse_with_code_message_and_feature(): void {
		$cases = array(
			'bookmarks' => array( 'POST', '/buddynext/v1/posts/1/bookmark', array(), 'bookmarks_disabled' ),
			'polls'     => array( 'POST', '/buddynext/v1/posts/1/vote', array( 'option_id' => 1 ), 'polls_disabled' ),
			'reactions' => array( 'POST', '/buddynext/v1/reactions/toggle', array( 'object_type' => 'post', 'object_id' => 1 ), 'reactions_disabled' ),
			'comments'  => array( 'POST', '/buddynext/v1/comments', array( 'object_type' => 'post', 'object_id' => 1, 'content' => 'Hi' ), 'comments_disabled' ),
			'hashtags'  => array( 'GET', '/buddynext/v1/hashtags/trending', array(), 'hashtags_disabled' ),
		);
		foreach ( $cases as $slug => list( $method, $route, $params, $code ) ) {
			add_filter( "buddynext_feature_{$slug}", '__return_false' );
			$request = new WP_REST_Request( $method, $route );
			foreach ( $params as $k => $v ) {
				$request->set_param( $k, $v );
			}
			$data = rest_get_server()->response_to_data( rest_get_server()->dispatch( $request ), false );

			$this->assertSame( $code, $data['code'] ?? null, $route );
			$this->assertNotEmpty( $data['message'] ?? '', $route . ' carries a message' );
			$this->assertSame( $slug, $data['data']['feature'] ?? null, $route . ' names the feature' );
		}
	}
}
