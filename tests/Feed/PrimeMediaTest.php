<?php
/**
 * A feed page loads all its media in one MediaVerse prefetch, guests included.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

/**
 * @covers \BuddyNext\Feed\FeedService::prime_media
 */
class PrimeMediaTest extends \WP_UnitTestCase {

	/**
	 * Ids passed to the fake repository's prefetch().
	 *
	 * @var array<int,array<int,int>>
	 */
	public static array $calls = array();

	/**
	 * Swap in a repository that records prefetch() calls.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		self::$calls = array();
		add_filter(
			'buddynext_media_service',
			static function ( $service, string $key ) {
				if ( 'media_repository' === $key ) {
					return new class() {
						public function prefetch( array $ids ): void {
							PrimeMediaTest::$calls[] = $ids;
						}
					};
				}
				return $service;
			},
			10,
			2
		);
	}

	/**
	 * One prefetch with every distinct media id, for a guest too.
	 *
	 * @return void
	 */
	public function test_one_prefetch_for_the_whole_page_even_for_guests(): void {
		$items = array(
			array( 'id' => 1, 'media_ids' => array( 11, 12 ) ),
			array( 'id' => 2, 'media_ids' => array() ),
			array( 'id' => 3, 'media_ids' => array( 12, 13 ) ),
		);

		buddynext_service( 'feed' )->prime_viewer_state( $items, 0 );

		$this->assertCount( 1, self::$calls, 'One prefetch per page.' );
		$this->assertSame( array( 11, 12, 13 ), self::$calls[0] );
	}
}
