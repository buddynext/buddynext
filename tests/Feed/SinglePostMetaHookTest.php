<?php
/**
 * Tests verifying SinglePostMeta hook wiring at the PageRouter level.
 *
 * Wave 2 introduced PageRouter::maybe_register_single_post_meta() as the
 * canonical wire point for OG / canonical head meta tags on /p/{id}/.
 * Without this wiring, the tags never reached the rendered <head>
 * because get_header() (which fires wp_head) runs before the inner
 * single-post.php template gets a chance to call emit_for_post().
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\PostService;
use ReflectionMethod;

/**
 * @covers \BuddyNext\Core\PageRouter::maybe_register_single_post_meta
 */
class SinglePostMetaHookTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		remove_all_actions( 'wp_head' );
		remove_all_filters( 'document_title_parts' );
	}

	public function test_wp_head_callback_registered_when_post_visible(): void {
		$author_id = self::factory()->user->create( array( 'display_name' => 'Pat Public' ) );
		$post_id   = ( new PostService() )->create(
			$author_id,
			array(
				'content' => 'A public test post',
				'privacy' => 'public',
				'type'    => 'text',
			)
		);
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$router = new PageRouter();
		$method = new ReflectionMethod( PageRouter::class, 'maybe_register_single_post_meta' );
		$method->setAccessible( true );
		$method->invoke( $router, $post_id );

		// SinglePostMeta::emit_for_post registers a wp_head priority-1
		// callback when it succeeds. The presence of a wp_head action
		// proves the post survived the visibility gate.
		$this->assertNotFalse( has_action( 'wp_head' ), 'wp_head should be wired after emit_for_post fires.' );
	}

	public function test_no_wp_head_callback_when_post_id_invalid(): void {
		$router = new PageRouter();
		$method = new ReflectionMethod( PageRouter::class, 'maybe_register_single_post_meta' );
		$method->setAccessible( true );
		$method->invoke( $router, 0 );

		// No wp_head action should be registered for an invalid post.
		$this->assertFalse( has_action( 'wp_head' ) );
	}

	public function test_private_post_skipped_for_non_author(): void {
		$author_id = self::factory()->user->create();
		$viewer_id = self::factory()->user->create();
		wp_set_current_user( $viewer_id );

		$post_id = ( new PostService() )->create(
			$author_id,
			array(
				'content' => 'Private note',
				'privacy' => 'private',
				'type'    => 'text',
			)
		);

		$router = new PageRouter();
		$method = new ReflectionMethod( PageRouter::class, 'maybe_register_single_post_meta' );
		$method->setAccessible( true );
		$method->invoke( $router, $post_id );

		$this->assertFalse( has_action( 'wp_head' ), 'Private posts must not register OG meta for non-author viewers.' );
	}

	public function test_document_title_filter_runs_when_post_is_visible(): void {
		$author_id = self::factory()->user->create( array( 'display_name' => 'Title Author' ) );
		$post_id   = ( new PostService() )->create(
			$author_id,
			array(
				'content' => 'Searchable excerpt body',
				'privacy' => 'public',
				'type'    => 'text',
			)
		);

		$router = new PageRouter();
		$method = new ReflectionMethod( PageRouter::class, 'maybe_register_single_post_meta' );
		$method->setAccessible( true );
		$method->invoke( $router, $post_id );

		$parts = apply_filters( 'document_title_parts', array( 'title' => 'Post' ) );

		$this->assertArrayHasKey( 'title', $parts );
		$this->assertStringContainsString( 'Title Author', (string) $parts['title'] );
		$this->assertStringContainsString( 'Searchable excerpt body', (string) $parts['title'] );
	}
}
