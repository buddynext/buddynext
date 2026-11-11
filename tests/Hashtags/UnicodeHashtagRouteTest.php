<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A Korean, Japanese or accented hashtag can be linked, opened and followed.
 *
 * Tags are stored as Unicode slugs (한국어, café), but three readers ran
 * sanitize_title() over them: the link builder (percent-encoded Hangul, then
 * rawurlencode() encoded the % again), the hashtag page lookup, and the REST
 * {slug} argument (sanitize_text_field() stripped the %XX octets). Every
 * non-Latin hashtag link opened "not found", and following one returned 404.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Hashtags\HashtagService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Hashtags\HashtagService::slug_from_param
 * @covers \BuddyNext\Hashtags\HashtagService::slug_from_request
 * @covers \BuddyNext\Core\PageRouter::hashtag_feed_url
 */
class UnicodeHashtagRouteTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function tags(): array {
		return array(
			'Korean'   => array( '한국어' ),
			'Japanese' => array( '日本語' ),
			'Accented' => array( 'café' ),
			'Latin'    => array( 'test' ),
		);
	}

	/**
	 * @dataProvider tags
	 *
	 * @param string $tag Stored hashtag slug.
	 */
	public function test_the_link_encodes_the_stored_slug_once( string $tag ): void {
		$this->assertStringEndsWith( '/hashtag/' . rawurlencode( $tag ) . '/', PageRouter::hashtag_feed_url( $tag ) );
	}

	/**
	 * @dataProvider tags
	 *
	 * @param string $tag Stored hashtag slug.
	 */
	public function test_an_encoded_url_segment_resolves_to_the_stored_slug( string $tag ): void {
		$this->assertSame( $tag, HashtagService::slug_from_param( rawurlencode( $tag ) ), 'Encoded path segment.' );
		$this->assertSame( $tag, HashtagService::slug_from_param( $tag ), 'Already-decoded value.' );

		set_query_var( 'bn_hashtag', rawurlencode( $tag ) );
		$this->assertSame( $tag, HashtagService::slug_from_request() );
	}

	public function test_a_korean_hashtag_can_be_followed_over_rest(): void {
		$this->assertGreaterThan( 0, ( new HashtagService() )->register( '한국어' ) );
		wp_set_current_user( self::factory()->user->create() );

		$follow = rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/hashtags/' . rawurlencode( '한국어' ) . '/follow' ) );

		$this->assertSame( 200, $follow->get_status() );
		$this->assertTrue( $follow->get_data()['following'] );
	}
}
