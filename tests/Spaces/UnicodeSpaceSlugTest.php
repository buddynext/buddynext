<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A space with a Korean (or any non-Latin) name can be linked and opened.
 *
 * Space slugs are stored in WordPress core's post_name form: sanitize_title()
 * percent-encodes non-Latin letters. The link builders ran rawurlencode() over
 * that again (%ed became %25ed), so every such space link opened "not found",
 * and the REST {slug} route rejected the % characters outright.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * @group spaces
 */
class UnicodeSpaceSlugTest extends \WP_UnitTestCase {

	private int $owner;
	private int $space;
	private string $slug;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner = self::factory()->user->create();
		$this->space = (int) ( new SpaceService() )->create( $this->owner, array( 'name' => '한국어 모임', 'slug' => sanitize_title( '한국어 모임' ), 'type' => 'open' ) );
		$this->slug  = (string) ( new SpaceService() )->get( $this->space )['slug'];
	}

	public function test_the_slug_is_stored_in_core_form(): void {
		$this->assertSame( sanitize_title( '한국어 모임' ), $this->slug );
	}

	public function test_links_encode_the_slug_once(): void {
		$expected = PageRouter::spaces_url() . sanitize_title( '한국어 모임' ) . '/';
		$this->assertSame( $expected, buddynext_space_url( $this->slug ) );
		$this->assertSame( $expected, PageRouter::space_url( $this->space ) );
		$this->assertStringNotContainsString( '%25', buddynext_space_url( $this->slug ) );
	}

	public function test_the_slug_route_resolves_encoded_and_decoded_segments(): void {
		wp_set_current_user( $this->owner );
		foreach ( array( $this->slug, rawurlencode( '한국어-모임' ) ) as $segment ) {
			$res = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/slug/' . $segment ) );
			$this->assertSame( 200, $res->get_status(), $segment );
			$this->assertSame( $this->space, (int) $res->get_data()['id'] );
		}
	}

	public function test_a_korean_space_can_be_deleted_by_its_typed_name(): void {
		wp_set_current_user( $this->owner );
		$wrong = new WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $this->space );
		$wrong->set_header( 'X-BN-Confirm-Space-Name', rawurlencode( '다른 이름' ) );
		$this->assertSame( 422, rest_do_request( $wrong )->get_status() );

		// The browser sends the name percent-encoded: header values are ISO-8859-1 only.
		$request = new WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $this->space );
		$request->set_header( 'X-BN-Confirm-Space-Name', rawurlencode( '한국어 모임' ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertNull( ( new SpaceService() )->get( $this->space ) );
	}

	public function test_a_korean_category_filters_the_directory(): void {
		$cat = ( new \BuddyNext\Spaces\SpaceCategoryService() )->create( array( 'name' => '한국 모임' ) );
		$this->assertIsInt( $cat );

		// The browser decodes ?bn_cat=%ed%95... once; an old double-encoded link arrives encoded.
		foreach ( array( '한국-모임', sanitize_title( '한국 모임' ) ) as $value ) {
			$parsed = ( new SpaceService() )->directory_request( 0, false, array( 'bn_cat' => $value ), 1 );
			$this->assertSame( $cat, (int) ( $parsed['query_args']['category_id'] ?? 0 ), $value );
		}
	}

	public function test_the_gate_and_the_handler_resolve_the_same_space(): void {
		$spaces = new SpaceService();
		$public = (int) $spaces->create( $this->owner, array( 'name' => 'Gate Open', 'slug' => 'gate-ab', 'type' => 'open' ) );
		$secret = (int) $spaces->create( $this->owner, array( 'name' => 'Gate Secret', 'slug' => 'gateab', 'type' => 'secret' ) );
		wp_set_current_user( self::factory()->user->create() );

		// '+' decodes to a space in one decoder and stays '+' in another: both sides must agree.
		$res = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/slug/gate+ab' ) );

		$this->assertNotSame( $secret, (int) ( $res->get_data()['id'] ?? 0 ) );
		$this->assertContains( (int) ( $res->get_data()['id'] ?? 0 ), array( 0, $public ) );
	}
}
