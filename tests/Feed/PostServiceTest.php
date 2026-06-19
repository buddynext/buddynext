<?php
/**
 * Tests for PostService.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService
 */
class PostServiceTest extends \WP_UnitTestCase {

	private PostService $service;
	private int $alice;
	private int $bob;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new PostService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_create_returns_id(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Hello world',
				'privacy' => 'public',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_create_returns_error_for_invalid_type(): void {
		$result = $this->service->create(
			$this->alice,
			array(
				'type'    => 'invalid_type',
				'content' => 'Bad type',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}

	public function test_create_fires_action(): void {
		$fired = array();
		add_action(
			'buddynext_post_created',
			function ( int $post_id, int $user_id, string $type ) use ( &$fired ): void {
				$fired[] = array( $post_id, $user_id, $type );
			},
			10,
			3
		);

		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Fire test' )
		);

		$this->assertCount( 1, $fired );
		$this->assertSame( $id, $fired[0][0] );
		$this->assertSame( $this->alice, $fired[0][1] );
		$this->assertSame( 'text', $fired[0][2] );
	}

	public function test_get_returns_post_data(): void {
		$id   = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Readable post', 'privacy' => 'public' )
		);
		$post = $this->service->get( $id );

		$this->assertIsArray( $post );
		$this->assertSame( $id, $post['id'] );
		$this->assertSame( $this->alice, $post['user_id'] );
		$this->assertSame( 'Readable post', $post['content'] );
		$this->assertSame( 'text', $post['type'] );
		$this->assertSame( 'public', $post['privacy'] );
	}

	public function test_get_returns_null_for_unknown_post(): void {
		$this->assertNull( $this->service->get( 99999 ) );
	}

	public function test_get_decodes_media_ids_json(): void {
		// Create as an admin: PostService::authorize_media_ids() drops attachments a
		// non-admin doesn't own (IDOR guard), and the test only exercises JSON
		// round-tripping of the stored ids.
		$id   = $this->service->create(
			$this->admin,
			array(
				'type'      => 'photo',
				'content'   => '',
				'media_ids' => array( 10, 20, 30 ),
			)
		);
		$post = $this->service->get( $id );

		$this->assertSame( array( 10, 20, 30 ), $post['media_ids'] );
	}

	public function test_update_changes_content(): void {
		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Original' )
		);

		$result = $this->service->update( $id, $this->alice, array( 'content' => 'Updated' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'Updated', $this->service->get( $id )['content'] );
	}

	public function test_update_sets_edited_at(): void {
		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Before edit' )
		);

		$this->service->update( $id, $this->alice, array( 'content' => 'After edit' ) );
		$post = $this->service->get( $id );

		$this->assertNotNull( $post['edited_at'] );
	}

	public function test_update_requires_owner(): void {
		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Alice post' )
		);

		$result = $this->service->update( $id, $this->bob, array( 'content' => 'Bob changes' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'not_post_owner', $result->get_error_code() );
	}

	public function test_delete_removes_post(): void {
		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Delete me' )
		);

		$this->service->delete( $id, $this->alice );

		$this->assertNull( $this->service->get( $id ) );
	}

	public function test_delete_requires_owner(): void {
		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Alice post' )
		);

		$result = $this->service->delete( $id, $this->bob );

		$this->assertWPError( $result );
		$this->assertSame( 'not_post_owner', $result->get_error_code() );
		$this->assertNotNull( $this->service->get( $id ) );
	}

	public function test_pin_sets_flag(): void {
		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Pin me' )
		);

		$this->service->pin( $id, $this->alice );

		$this->assertSame( 1, $this->service->get( $id )['is_pinned'] );
	}

	public function test_unpin_clears_flag(): void {
		$id = $this->service->create(
			$this->alice,
			array( 'type' => 'text', 'content' => 'Pinned' )
		);
		$this->service->pin( $id, $this->alice );

		$this->service->unpin( $id, $this->alice );

		$this->assertSame( 0, $this->service->get( $id )['is_pinned'] );
	}

	public function test_create_poll_creates_options(): void {
		$id = $this->service->create(
			$this->alice,
			array(
				'type'    => 'poll',
				'content' => 'Best colour?',
				'options' => array( 'Red', 'Green', 'Blue' ),
			)
		);

		$post = $this->service->get( $id );
		$this->assertSame( 'poll', $post['type'] );
		$this->assertCount( 3, $post['poll_options'] );
		$this->assertSame( 'Red', $post['poll_options'][0]['option_text'] );
	}

	public function test_create_poll_requires_at_least_two_options(): void {
		$result = $this->service->create(
			$this->alice,
			array(
				'type'    => 'poll',
				'content' => 'Invalid poll',
				'options' => array( 'Only one' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'poll_requires_options', $result->get_error_code() );
	}
}
