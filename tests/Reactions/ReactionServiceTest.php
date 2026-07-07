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
		$this->service->react( $this->user_id, 'post', $this->post_id, 'love' );

		$emoji = $this->service->get_user_emoji( $this->user_id, 'post', $this->post_id );

		$this->assertSame( 'love', $emoji );
	}

	public function test_get_user_emoji_returns_null_when_no_reaction(): void {
		$emoji = $this->service->get_user_emoji( $this->user_id, 'post', $this->post_id );

		$this->assertNull( $emoji );
	}

	public function test_toggle_replaces_emoji_when_different(): void {
		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );
		$this->service->toggle( $this->user_id, 'post', $this->post_id, 'love' );

		$this->assertSame( 'love', $this->service->get_user_emoji( $this->user_id, 'post', $this->post_id ) );
		$this->assertSame( 1, $this->service->count( 'post', $this->post_id ) );
	}

	public function test_get_counts_returns_per_emoji_breakdown(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		$this->service->react( $user_a, 'post', $this->post_id, 'like' );
		$this->service->react( $user_b, 'post', $this->post_id, 'like' );
		$this->service->react( $this->user_id, 'post', $this->post_id, 'love' );

		$counts = $this->service->get_counts( 'post', $this->post_id );

		$this->assertSame( 2, $counts['like'] );
		$this->assertSame( 1, $counts['love'] );
	}

	public function test_react_fires_buddynext_reaction_added(): void {
		$captured = null;
		add_action(
			'buddynext_reaction_added',
			function ( string $object_type, int $object_id, int $user_id, string $emoji ) use ( &$captured ): void {
				$captured = array( $object_type, $object_id, $user_id, $emoji );
			},
			10,
			4
		);

		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );

		$this->assertSame( array( 'post', $this->post_id, $this->user_id, 'like' ), $captured );
	}

	public function test_unreact_fires_buddynext_reaction_removed(): void {
		$captured = null;
		add_action(
			'buddynext_reaction_removed',
			function ( string $object_type, int $object_id, int $user_id ) use ( &$captured ): void {
				$captured = array( $object_type, $object_id, $user_id );
			},
			10,
			3
		);

		$this->service->react( $this->user_id, 'post', $this->post_id, 'like' );
		$this->service->unreact( $this->user_id, 'post', $this->post_id );

		$this->assertSame( array( 'post', $this->post_id, $this->user_id ), $captured );
	}
	/**
	 * The batch map returns each reacted emoji and a null for un-reacted ids.
	 *
	 * @return void
	 */
	public function test_get_user_emoji_map_batches_reactions(): void {
		$this->service->react( $this->user_id, 'post', 101, 'like' );
		$this->service->react( $this->user_id, 'post', 103, 'love' );

		$map = $this->service->get_user_emoji_map( $this->user_id, 'post', array( 101, 102, 103 ) );

		// Reacted posts return their emoji; the un-reacted one is present but null.
		$this->assertSame( 'like', $map[101] );
		$this->assertNull( $map[102] );
		$this->assertSame( 'love', $map[103] );
	}

	/**
	 * The batch map short-circuits to empty for a guest or an empty id list.
	 *
	 * @return void
	 */
	public function test_get_user_emoji_map_empty_for_guest_or_no_ids(): void {
		// A guest has no reactions, but the map keeps a consistent shape: every
		// requested id present with a null value (callers read $map[$id] directly).
		$this->assertSame( array( 1 => null, 2 => null ), $this->service->get_user_emoji_map( 0, 'post', array( 1, 2 ) ) );
		// No ids -> genuinely empty map.
		$this->assertSame( array(), $this->service->get_user_emoji_map( $this->user_id, 'post', array() ) );
	}
	/**
	 * The enabled set carries render metadata and includes Pro custom slugs.
	 *
	 * @return void
	 */
	public function test_enabled_reactions_includes_metadata_and_pro_slugs(): void {
		$base = ReactionService::enabled_reactions();
		$this->assertNotEmpty( $base );
		$this->assertSame(
			array( 'slug', 'label', 'char', 'color', 'icon_url' ),
			array_keys( $base[0] )
		);

		add_filter(
			'buddynext_reaction_types',
			static function ( array $types ): array {
				$types[] = 'celebrate';
				return $types;
			}
		);
		$slugs = array_column( ReactionService::enabled_reactions(), 'slug' );
		$this->assertContains( 'celebrate', $slugs );
	}
}
