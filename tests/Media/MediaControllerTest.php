<?php
/**
 * Tests for the member-facing Media + Albums REST controller.
 *
 * These exercise the 1.0.3 profile media uploads + albums feature against the
 * STUBBED WPMediaVerse engine that tests/bootstrap.php aliases in. With the stub
 * the engine container resolves nothing, so MediaClient::upload()/repo()/albums()/
 * privacy() all return null and every endpoint must hit its graceful-degradation
 * path rather than fatal. Engine-backed success paths are intentionally NOT
 * asserted here because they require a real engine.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\MediaController;
use BuddyNext\REST\Router;
use ReflectionMethod;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Media + Albums controller behaviour against the stubbed engine.
 *
 * @covers \BuddyNext\Media\MediaController
 */
class MediaControllerTest extends \WP_UnitTestCase {

	/**
	 * Controller under test.
	 *
	 * @var MediaController
	 */
	private MediaController $controller;

	/**
	 * A logged-in member id.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Fresh controller + member per test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->controller = new MediaController();
		$this->member     = self::factory()->user->create();
	}

	// ── Graceful degradation: engine stubbed, services resolve to null ─────────

	/**
	 * Upload reports the engine is unavailable rather than fataling on a null
	 * upload service.
	 */
	public function test_upload_returns_503_when_engine_unavailable(): void {
		wp_set_current_user( $this->member );

		$result = $this->controller->upload_own_media( new WP_REST_Request( 'POST', '/buddynext/v1/me/media' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bn_media_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/**
	 * Delete reports the engine is unavailable when the media repository is
	 * absent (the guard fires before any ownership check).
	 */
	public function test_delete_returns_503_when_engine_unavailable(): void {
		wp_set_current_user( $this->member );

		$request = new WP_REST_Request( 'DELETE', '/buddynext/v1/me/media/123' );
		$request->set_param( 'media_id', 123 );

		$result = $this->controller->delete_own_media( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bn_media_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/**
	 * Album creation reports albums are unavailable when the album service is
	 * absent (this guard runs before the title-required validation).
	 */
	public function test_create_album_returns_503_when_engine_unavailable(): void {
		wp_set_current_user( $this->member );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/me/albums' );
		$request->set_param( 'title', 'My album' );

		$result = $this->controller->create_album( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bn_albums_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	/**
	 * The media list endpoint degrades to an empty, well-formed 200 response
	 * (no engine = no media, not an error) so the grid renders an empty state.
	 */
	public function test_list_user_media_degrades_to_empty_response(): void {
		wp_set_current_user( $this->member );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->member . '/media' );
		$request->set_param( 'id', $this->member );

		$response = $this->controller->list_user_media( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( '', $data['html'] );
		$this->assertSame( array(), $data['ids'] );
	}

	/**
	 * The album list endpoint degrades to an empty 200 response when the
	 * mvs_album CPT is not registered (engine absent).
	 */
	public function test_list_user_albums_degrades_to_empty_response(): void {
		wp_set_current_user( $this->member );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->member . '/albums' );
		$request->set_param( 'id', $this->member );

		$response = $this->controller->list_user_albums( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['albums'] );
	}

	// ── Input validation independent of the engine ────────────────────────────

	/**
	 * Adding items to an owned album with no media ids is rejected with a clear
	 * validation error. A real mvs_album post (owned by the member) clears the
	 * owner gate so the empty-media check is reached.
	 */
	public function test_add_album_items_requires_media_ids(): void {
		wp_set_current_user( $this->member );

		$album_id = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_album',
				'post_author' => $this->member,
				'post_status' => 'publish',
			)
		);

		$request = new WP_REST_Request( 'POST', "/buddynext/v1/me/albums/{$album_id}/items" );
		$request->set_param( 'id', $album_id );
		// No media_ids param.

		$result = $this->controller->add_album_items( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bn_album_no_media', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	// ── Album existence / ownership gates ─────────────────────────────────────

	/**
	 * GET /albums/{id} for an id that is not an mvs_album returns a privacy-safe
	 * 404 (existence is not disclosed).
	 */
	public function test_get_album_returns_404_for_non_album_id(): void {
		wp_set_current_user( $this->member );

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$request = new WP_REST_Request( 'GET', "/buddynext/v1/albums/{$post_id}" );
		$request->set_param( 'id', $post_id );

		$result = $this->controller->get_album( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bn_album_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * An album write op against an id that is not an mvs_album fails the owner
	 * gate with a 404 before any engine work.
	 */
	public function test_album_write_returns_404_for_non_album_id(): void {
		wp_set_current_user( $this->member );

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$request = new WP_REST_Request( 'POST', "/buddynext/v1/me/albums/{$post_id}/items" );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'media_ids', array( 1, 2, 3 ) );

		$result = $this->controller->add_album_items( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bn_album_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	// ── Privacy mapping (post vocabulary → engine vocabulary) ─────────────────

	/**
	 * The composer/post privacy vocabulary maps to the engine's media-file
	 * vocabulary per PRIVACY_MAP; unknown values fall back to public.
	 */
	public function test_sanitize_privacy_maps_post_vocabulary_to_engine(): void {
		$method = new ReflectionMethod( MediaController::class, 'sanitize_privacy' );
		$method->setAccessible( true );

		$this->assertSame( 'public', $method->invoke( $this->controller, 'public' ) );
		$this->assertSame( 'members', $method->invoke( $this->controller, 'followers' ) );
		$this->assertSame( 'members', $method->invoke( $this->controller, 'connections' ) );
		$this->assertSame( 'private', $method->invoke( $this->controller, 'private' ) );
		$this->assertSame( 'public', $method->invoke( $this->controller, 'nonsense' ) );
	}

	// ── Auth: unauthenticated requests are rejected by the route guard ────────

	/**
	 * Unauthenticated writes are rejected with 401 by the require_auth
	 * permission callback (verified through the real REST dispatch).
	 */
	public function test_unauthenticated_writes_are_rejected(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new Router() )->register();
		do_action( 'rest_api_init' );

		wp_set_current_user( 0 );

		$upload = $wp_rest_server->dispatch( new WP_REST_Request( 'POST', '/buddynext/v1/me/media' ) );
		$this->assertSame( 401, $upload->get_status() );

		$album = $wp_rest_server->dispatch( new WP_REST_Request( 'POST', '/buddynext/v1/me/albums' ) );
		$this->assertSame( 401, $album->get_status() );

		$wp_rest_server = null;
	}
}
