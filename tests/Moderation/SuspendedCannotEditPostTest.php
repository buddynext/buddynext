<?php
/**
 * A suspended member cannot EDIT their existing posts, not just create new ones.
 *
 * create() gated suspended authors; update() did not, so an already-suspended
 * member could keep rewriting posts they had published before the suspension.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Feed\PostService::update
 */
class SuspendedCannotEditPostTest extends \WP_UnitTestCase {

	private ModerationService $moderation;
	private PostService $posts;
	private int $admin  = 0;
	private int $author = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->moderation = new ModerationService();
		$this->posts      = new PostService();
		$this->admin      = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author     = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_suspended_author_cannot_edit_existing_post(): void {
		$post_id = $this->posts->create(
			$this->author,
			array(
				'type'    => 'text',
				'content' => 'Original body, written before the suspension.',
			)
		);
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Editing works while in good standing.
		$this->assertTrue(
			$this->posts->update( $post_id, $this->author, array( 'content' => 'A normal edit.' ) ),
			'An unsuspended author should be able to edit.'
		);

		$this->moderation->suspend_user( $this->author, $this->admin, 'spam' );

		$result = $this->posts->update( $post_id, $this->author, array( 'content' => 'Edit attempted while suspended.' ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A suspended author must not edit.' );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		// And the stored content is unchanged.
		$post = $this->posts->get( $post_id );
		$this->assertSame( 'A normal edit.', (string) ( $post['content'] ?? '' ), 'The suspended edit must not persist.' );
	}
}
