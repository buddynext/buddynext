<?php
/**
 * PostService::members_only_view() is the one redaction every surface renders
 * (card 10369172985): a viewer without access gets the teaser and nothing else
 * that carries the post's body - no media, link URL or link preview.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\PostService;

/**
 * @group feed
 */
class MembersOnlyViewTest extends \WP_UnitTestCase {

	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->author = self::factory()->user->create();
	}

	/**
	 * A members-only link post with media.
	 *
	 * @return array<string,mixed>
	 */
	private function post(): array {
		return array(
			'id'           => 1,
			'user_id'      => $this->author,
			'space_id'     => null,
			'members_only' => true,
			'content'      => str_repeat( 'Behind the wall. ', 40 ),
			'media_ids'    => array( 7, 8 ),
			'link_url'     => 'https://example.org/secret',
			'link_meta'    => array(
				'title'       => 'Secret title',
				'description' => 'Secret description',
				'image'       => 'https://example.org/secret.png',
			),
		);
	}

	public function test_guest_gets_only_the_teaser(): void {
		$view = ( new PostService() )->members_only_view( $this->post(), 0 );

		$this->assertTrue( $view['is_locked'] );
		$this->assertSame( array(), $view['media_ids'] );
		$this->assertSame( '', $view['link_url'] );
		$this->assertNull( $view['link_meta'] );
		$this->assertNotSame( $this->post()['content'], $view['content'], 'The full body reached a guest.' );
		$this->assertStringNotContainsString( 'Secret', wp_json_encode( $view ), 'A link-preview field reached a guest.' );
		$this->assertArrayHasKey( 'members_only_cta', $view );
	}

	public function test_author_gets_the_post_unchanged(): void {
		$view = ( new PostService() )->members_only_view( $this->post(), $this->author );

		$this->assertFalse( $view['is_locked'] );
		$this->assertSame( $this->post()['link_meta'], $view['link_meta'] );
		$this->assertSame( $this->post()['content'], $view['content'] );
	}

	public function test_public_post_is_untouched(): void {
		$post                 = $this->post();
		$post['members_only'] = false;
		$view                 = ( new PostService() )->members_only_view( $post, 0 );

		$this->assertFalse( $view['is_locked'] );
		$this->assertSame( $post['link_url'], $view['link_url'] );
	}
}
