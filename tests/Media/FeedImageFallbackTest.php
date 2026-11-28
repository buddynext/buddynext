<?php
/**
 * A feed photo whose chosen size is missing still shows.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\MediaRenderer;

/**
 * Feed image fallback tests.
 *
 * @covers \BuddyNext\Media\MediaRenderer::grid
 * @covers \BuddyNext\Media\MediaClient::modern_image_sources
 */
class FeedImageFallbackTest extends \WP_UnitTestCase {

	/**
	 * Engine services handed to MediaClient.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Swap in a fake media repository and, optionally, template helpers.
	 *
	 * @param bool $with_webp Whether the engine has a WebP copy of the large size.
	 * @return string Rendered single-photo grid.
	 */
	private function render( bool $with_webp ): string {
		$this->services = array(
			'media_repository' => new class() {
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
						'status'     => 'publish',
						'title'      => 'Beach',
						'width'      => 1200,
						'height'     => 800,
					)[ $key ] ?? '';
				}

				/**
				 * Broadcast thumbnail URL.
				 *
				 * @param int    $id   Media id.
				 * @param string $size Size.
				 * @return string
				 */
				public function get_broadcast_thumbnail_url( int $id, string $size ): string {
					return 'https://cdn.example.test/' . $id . '-' . $size . '.jpg';
				}

				/**
				 * Broadcast original URL.
				 *
				 * @param int $id Media id.
				 * @return string
				 */
				public function get_broadcast_url( int $id ): string {
					return 'https://cdn.example.test/' . $id . '.jpg';
				}
			},
		);
		if ( $with_webp ) {
			$this->services['template_helpers'] = new class() {
				/**
				 * WebP sibling of a size.
				 *
				 * @param int    $id   Media id.
				 * @param string $size Size key.
				 * @param string $url  Primary URL.
				 * @return string
				 */
				public function get_webp_variant_url( int $id, string $size, string $url ): string {
					return 'large' === $size && '' !== $url ? 'https://cdn.example.test/' . $id . '-large.webp' : '';
				}
			};
		}

		$swap = function ( $resolved, $key ) {
			return $this->services[ $key ] ?? $resolved;
		};
		add_filter( 'buddynext_media_service', $swap, 10, 2 );
		$html = MediaRenderer::grid( array( 7 ) );
		remove_filter( 'buddynext_media_service', $swap, 10 );

		return $html;
	}

	/**
	 * With the large JPEG missing and its WebP present, the browser is offered the WebP.
	 *
	 * @return void
	 */
	public function test_photo_offers_the_engine_webp_copy(): void {
		$html = $this->render( true );

		$this->assertStringContainsString( '<picture class="bn-media-tile__picture">', $html );
		$this->assertStringContainsString( '<source type="image/webp" srcset="https://cdn.example.test/7-large.webp 1x, https://cdn.example.test/7.jpg 2x">', $html );
		$this->assertStringContainsString( 'src="https://cdn.example.test/7-thumb_large.jpg"', $html );
	}

	/**
	 * With every size missing, the tile still names the original to fall back to.
	 *
	 * @return void
	 */
	public function test_photo_names_the_original_as_its_fallback(): void {
		foreach ( array( true, false ) as $with_webp ) {
			$html = $this->render( $with_webp );
			$this->assertStringContainsString( 'data-bn-fallback-src="https://cdn.example.test/7.jpg"', $html );
		}
		$this->assertStringNotContainsString( '<picture', $this->render( false ), 'No modern copy, no picture wrapper.' );
	}
}
