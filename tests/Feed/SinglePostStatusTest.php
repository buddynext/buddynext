<?php
/**
 * A post the viewer cannot read answers 404 before anything prints.
 *
 * The template set the status itself, too late on a block theme: the page showed
 * the not-found state with a 200, and search engines indexed it.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\PostService;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PageRouter
 */
class SinglePostStatusTest extends WP_UnitTestCase {

	private int $author;

	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->author = self::factory()->user->create();
		add_filter( 'status_header', array( $this, 'capture' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'status_header', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	/**
	 * Record each status the router sends.
	 *
	 * @param string $header Header line.
	 * @param int    $code   Status code.
	 */
	public function capture( $header, $code ) {
		$this->sent[] = (int) $code;
		return $header;
	}

	private function statuses_for( int $post_id, int $viewer ): array {
		wp_set_current_user( $viewer );
		$this->sent = array();
		$method     = new ReflectionMethod( PageRouter::class, 'maybe_register_single_post_meta' );
		$method->invoke( new PageRouter(), $post_id );
		return $this->sent;
	}

	public function test_a_missing_post_is_404(): void {
		$this->assertContains( 404, $this->statuses_for( 999999, 0 ) );
	}

	public function test_a_private_post_is_404_for_others_and_not_for_its_author(): void {
		wp_set_current_user( $this->author );
		$post = ( new PostService() )->create( $this->author, array( 'content' => 'Mine', 'privacy' => 'private' ) );

		$this->assertContains( 404, $this->statuses_for( $post, 0 ) );
		$this->assertContains( 404, $this->statuses_for( $post, self::factory()->user->create() ) );
		$this->assertNotContains( 404, $this->statuses_for( $post, $this->author ) );
	}

	public function test_a_public_post_is_not_404(): void {
		wp_set_current_user( $this->author );
		$post = ( new PostService() )->create( $this->author, array( 'content' => 'Hello', 'privacy' => 'public' ) );

		$this->assertNotContains( 404, $this->statuses_for( $post, 0 ) );
	}
}
