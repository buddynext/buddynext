<?php
/**
 * Trashed media is gone for everyone, its owner included.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\MediaUrlResolver;

/**
 * Trashed media resolver tests.
 *
 * @covers \BuddyNext\Media\MediaUrlResolver::descriptor
 */
class TrashedMediaResolverTest extends \WP_UnitTestCase {

	/**
	 * The viewer-aware URLs answer for the owner whatever the status, as the engine does.
	 *
	 * @return void
	 */
	public function test_trashed_item_is_skipped_and_a_live_one_kept(): void {
		$repo = new class() {
			/**
			 * Read a field.
			 *
			 * @param int    $id  Media id.
			 * @param string $key Field.
			 * @return mixed
			 */
			public function get( int $id, string $key ) {
				return array(
					'media_type' => 'image',
					'status'     => 1 === $id ? 'trash' : 'publish',
				)[ $key ] ?? '';
			}

			/**
			 * Signed URL for the viewer.
			 *
			 * @param int $id Media id.
			 * @return string
			 */
			public function get_url_for_viewer( int $id ): string {
				return 'https://example.test/' . $id . '.png';
			}
		};

		$swap = static function ( $resolved, $key ) use ( $repo ) {
			return 'media_repository' === $key ? $repo : $resolved;
		};
		add_filter( 'buddynext_media_service', $swap, 10, 2 );
		$ids = array_column( MediaUrlResolver::descriptors( array( 1, 2 ) ), 'id' );
		remove_filter( 'buddynext_media_service', $swap, 10 );

		$this->assertSame( array( 2 ), $ids );
	}
}
