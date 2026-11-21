<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Reposition a cover that is already set: member and Space routes, permissions, validation.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\CoverFocal;
use WP_REST_Request;
use WP_UnitTestCase;

class CoverPositionTest extends WP_UnitTestCase {

	private function post( string $route, array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function space_with_cover( int $owner ): int {
		$space = (int) buddynext_service( 'spaces' )->create(
			$owner,
			array(
				'name' => 'Cover ' . wp_rand(),
				'slug' => 'cover-' . wp_rand( 10000, 99999 ),
				'type' => 'open',
			)
		);
		buddynext_service( 'spaces' )->update( $space, $owner, array( 'cover_image_url' => 'http://example.org/c.webp' ) );
		return $space;
	}

	public function test_a_member_repositions_their_own_cover(): void {
		$member = self::factory()->user->create();
		wp_set_current_user( $member );
		update_user_meta( $member, 'buddynext_cover_url', 'http://example.org/c.webp' );

		$response = $this->post(
			'/buddynext/v1/me/cover/position',
			array(
				'focal_x'    => 30,
				'focal_y'    => 70,
				'focal_zoom' => 9,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'x'    => 30.0,
				'y'    => 70.0,
				'zoom' => 3.0,
			),
			get_user_meta( $member, CoverFocal::META_KEY, true ),
			'Zoom is clamped on the server.'
		);
	}

	public function test_no_cover_and_a_bad_position_are_refused_and_nothing_is_stored(): void {
		$member = self::factory()->user->create();
		wp_set_current_user( $member );

		$this->assertSame(
			409,
			$this->post(
				'/buddynext/v1/me/cover/position',
				array(
					'focal_x' => 10,
					'focal_y' => 10,
				)
			)->get_status(),
			'No cover to frame.'
		);

		update_user_meta( $member, 'buddynext_cover_url', 'http://example.org/c.webp' );
		$this->assertSame(
			422,
			$this->post(
				'/buddynext/v1/me/cover/position',
				array(
					'focal_x' => 150,
					'focal_y' => 10,
				)
			)->get_status()
		);
		$this->assertSame( '', get_user_meta( $member, CoverFocal::META_KEY, true ) );
	}

	public function test_only_someone_who_may_edit_any_profile_can_frame_another_members_cover(): void {
		$owner = self::factory()->user->create();
		update_user_meta( $owner, 'buddynext_cover_url', 'http://example.org/c.webp' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame(
			403,
			$this->post(
				"/buddynext/v1/users/{$owner}/cover/position",
				array(
					'focal_x' => 5,
					'focal_y' => 5,
				)
			)->get_status()
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame(
			200,
			$this->post(
				"/buddynext/v1/users/{$owner}/cover/position",
				array(
					'focal_x' => 5,
					'focal_y' => 5,
				)
			)->get_status()
		);
	}

	public function test_a_space_owner_repositions_the_cover_and_a_member_cannot(): void {
		$owner = self::factory()->user->create();
		$space = $this->space_with_cover( $owner );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame(
			403,
			$this->post(
				"/buddynext/v1/spaces/{$space}/cover/position",
				array(
					'focal_x' => 20,
					'focal_y' => 20,
				)
			)->get_status()
		);

		wp_set_current_user( $owner );
		$this->assertSame(
			200,
			$this->post(
				"/buddynext/v1/spaces/{$space}/cover/position",
				array(
					'focal_x'    => 20,
					'focal_y'    => 80,
					'focal_zoom' => 1.5,
				)
			)->get_status()
		);
		$this->assertSame(
			array(
				'x'    => 20.0,
				'y'    => 80.0,
				'zoom' => 1.5,
			),
			get_space_meta( $space, CoverFocal::META_KEY, true )
		);
		$this->assertSame(
			422,
			$this->post(
				"/buddynext/v1/spaces/{$space}/cover/position",
				array(
					'focal_x' => -1,
					'focal_y' => 20,
				)
			)->get_status()
		);
	}

	public function test_a_space_without_a_cover_cannot_be_repositioned(): void {
		$owner = self::factory()->user->create();
		$space = (int) buddynext_service( 'spaces' )->create(
			$owner,
			array(
				'name' => 'Bare ' . wp_rand(),
				'slug' => 'bare-' . wp_rand( 10000, 99999 ),
				'type' => 'open',
			)
		);
		wp_set_current_user( $owner );

		$this->assertSame(
			409,
			$this->post(
				"/buddynext/v1/spaces/{$space}/cover/position",
				array(
					'focal_x' => 20,
					'focal_y' => 20,
				)
			)->get_status()
		);
	}
}
