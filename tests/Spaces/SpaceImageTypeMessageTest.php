<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * A refused space image says exactly what that kind accepts: the icon takes
 * GIF, the cover does not.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceController;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\SpaceController
 */
class SpaceImageTypeMessageTest extends \WP_UnitTestCase {

	private string $tmp = '';

	public function tear_down(): void {
		$_FILES = array();
		if ( '' !== $this->tmp && file_exists( $this->tmp ) ) {
			unlink( $this->tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		parent::tear_down();
	}

	private function refusal( string $kind ): string {
		Installer::run();
		$owner = self::factory()->user->create();
		wp_set_current_user( $owner );
		$space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Image Rules ' . $kind,
				'slug' => 'image-rules-' . $kind . '-' . $owner,
				'type' => 'public',
			)
		);
		$this->assertIsInt( $space_id );

		$this->tmp = wp_tempnam( 'a.txt' );
		file_put_contents( $this->tmp, 'plain text, not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$_FILES['image'] = array(
			'name'     => 'a.txt',
			'type'     => 'text/plain',
			'tmp_name' => $this->tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 24,
		);

		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/' . $kind );
		$request->set_param( 'id', $space_id );
		$controller = new SpaceController();
		$result     = 'avatar' === $kind ? $controller->upload_space_avatar( $request ) : $controller->upload_space_cover( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'image_invalid_type', $result->get_error_code() );
		return $result->get_error_message();
	}

	public function test_the_icon_refusal_lists_gif(): void {
		$this->assertSame( 'Only JPEG, PNG, GIF, or WebP images are accepted.', $this->refusal( 'avatar' ) );
	}

	public function test_the_cover_refusal_does_not_list_gif(): void {
		$this->assertSame( 'Only JPEG, PNG, or WebP images are accepted.', $this->refusal( 'cover' ) );
	}
}
