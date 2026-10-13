<?php
/**
 * The feed image size an owner can choose by filter.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Media\MediaUrlResolver;

/**
 * Feed image variant tests.
 *
 * @covers \BuddyNext\Media\MediaUrlResolver::image_variant
 */
class FeedImageVariantTest extends \WP_UnitTestCase {

	/**
	 * Large by default, a known size by filter, large again for anything else.
	 *
	 * @return void
	 */
	public function test_variant_is_large_unless_a_known_size_is_asked_for(): void {
		$this->assertSame( 'thumb_large', MediaUrlResolver::image_variant() );

		$medium = static fn(): string => 'thumb_medium';
		add_filter( 'buddynext_feed_image_variant', $medium );
		$this->assertSame( 'thumb_medium', MediaUrlResolver::image_variant() );
		remove_filter( 'buddynext_feed_image_variant', $medium );

		$bad = static fn(): string => 'original';
		add_filter( 'buddynext_feed_image_variant', $bad );
		$this->assertSame( 'thumb_large', MediaUrlResolver::image_variant(), 'An unknown size must not reach MediaVerse.' );
		remove_filter( 'buddynext_feed_image_variant', $bad );
	}
}
