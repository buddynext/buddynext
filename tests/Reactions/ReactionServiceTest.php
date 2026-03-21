<?php
/**
 * Tests for ReactionService.
 *
 * @package BuddyNext\Tests\Reactions
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Reactions;

use BuddyNext\Core\Installer;
use BuddyNext\Reactions\ReactionService;

/**
 * @covers \BuddyNext\Reactions\ReactionService
 */
class ReactionServiceTest extends \WP_UnitTestCase {

	private ReactionService $service;
	private int $user_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ReactionService();
		$this->user_id = self::factory()->user->create();
		$this->post_id = 1; // Synthetic post ID — no need for a real bn_posts row.
	}

	public function test_react_returns_true(): void {
		$result = $this->service->react( $this->user_id, 'post', $this->post_id, 'like' );

		$this->assertTrue( $result );
	}

	public function test_has_reacted_returns_true_after_react(): void {
		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );

		$this->assertTrue( $this->service->has_reacted( $this->user_id, 'post', $this->post_id ) );
	}

	public function test_has_reacted_returns_false_before_react(): void {
		$this->assertFalse( $this->service->has_reacted( $this->user_id, 'post', $this->post_id ) );
	}

	public function test_unreact_removes_reaction(): void {
		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );
		$this->service->unreact( $this->user_id, 'post', $this->post_id );

		$this->assertFalse( $this->service->has_reacted( $this->user_id, 'post', $this->post_id ) );
	}

	public function test_toggle_adds_reaction_when_absent(): void {
		$this->service->toggle( $this->user_id, 'post', $this->post_id, 'heart' );

		$this->assertTrue( $this->service->has_reacted( $this->user_id, 'post', $this->post_id ) );
	}

	public function test_toggle_removes_reaction_when_present(): void {
		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );
		$this->service->toggle( $this->user_id, 'post', $this->post_id, 'like' );

		$this->assertFalse( $this->service->has_reacted( $this->user_id, 'post', $this->post_id ) );
	}

	public function test_count_increments_on_react(): void {
		$before = $this->service->count( 'post', $this->post_id );

		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );

		$this->assertSame( $before + 1, $this->service->count( 'post', $this->post_id ) );
	}

	public function test_count_decrements_on_unreact(): void {
		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );
		$after_react = $this->service->count( 'post', $this->post_id );

		$this->service->unreact( $this->user_id, 'post', $this->post_id );

		$this->assertSame( $after_react - 1, $this->service->count( 'post', $this->post_id ) );
	}

	public function test_duplicate_react_is_safe(): void {
		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );
		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );

		$this->assertSame( 1, $this->service->count( 'post', $this->post_id ) );
	}

	public function test_get_user_emoji_returns_emoji(): void {
		$this->service->react( $this->user_id, 'post', $this->post_id, 'fire' );

		$emoji = $this->service->get_user_emoji( $this->user_id, 'post', $this->post_id );

		$this->assertSame( 'fire', $emoji );
	}

	public function test_get_user_emoji_returns_null_when_no_reaction(): void {
		$emoji = $this->service->get_user_emoji( $this->user_id, 'post', $this->post_id );

		$this->assertNull( $emoji );
	}
}
