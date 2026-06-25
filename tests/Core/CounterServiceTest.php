<?php
/**
 * Tests for the denormalized counter service.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CounterService;
use BuddyNext\Core\Installer;

/**
 * Verifies that CounterService updates all denormalized counter columns.
 *
 * @covers \BuddyNext\Core\CounterService
 */
class CounterServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var CounterService
	 */
	private CounterService $service;

	/**
	 * A test user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create the service and DB tables before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new CounterService();
		$this->user_id = self::factory()->user->create();
	}

	// ── Follow counts ─────────────────────────────────────────────────────────

	/**
	 * Recounting follow counts stores positive values in usermeta.
	 */
	public function test_recount_follow_counts_stores_values(): void {
		global $wpdb;

		$other = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->prefix . 'bn_follows',
			array(
				'follower_id'  => $this->user_id,
				'following_id' => $other,
			)
		);

		$this->service->recount_follow_counts( $this->user_id );

		$this->assertSame( '1', get_user_meta( $this->user_id, 'bn_following_count', true ) );
		$this->assertSame( '0', get_user_meta( $this->user_id, 'bn_follower_count', true ) );
	}

	// ── Post counters ─────────────────────────────────────────────────────────

	/**
	 * Recounting reaction count updates bn_posts.reaction_count.
	 */
	public function test_recount_post_reactions_updates_column(): void {
		global $wpdb;

		$post_id = $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => $this->user_id,
				'type'    => 'text',
				'content' => 'hello',
				'privacy' => 'public',
			)
		);
		$post_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'bn_reactions',
			array(
				'user_id'     => $this->user_id,
				'object_type' => 'post',
				'object_id'   => $post_id,
				'emoji'       => 'like',
			)
		);

		$this->service->recount_post_reactions( $post_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reaction_count FROM {$wpdb->prefix}bn_posts WHERE id = %d",
				$post_id
			)
		);

		$this->assertSame( 1, $count );
	}

	/**
	 * Recounting comment count updates bn_posts.comment_count.
	 */
	public function test_recount_post_comments_updates_column(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => $this->user_id,
				'type'    => 'text',
				'content' => 'world',
				'privacy' => 'public',
			)
		);
		$post_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'user_id'     => $this->user_id,
				'object_type' => 'post',
				'object_id'   => $post_id,
				'content'     => 'nice',
			)
		);

		$this->service->recount_post_comments( $post_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_count FROM {$wpdb->prefix}bn_posts WHERE id = %d",
				$post_id
			)
		);

		$this->assertSame( 1, $count );
	}

	// ── Space member count ────────────────────────────────────────────────────

	/**
	 * Recounting space member count updates bn_spaces.member_count.
	 */
	public function test_recount_space_member_count_updates_column(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'     => 'Test Space',
				'slug'     => 'test-space',
				'type'     => 'open',
				'owner_id' => $this->user_id,
			)
		);
		$space_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $this->user_id,
				'role'     => 'owner',
			)
		);

		$this->service->recount_space_members( $space_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
				$space_id
			)
		);

		$this->assertSame( 1, $count );
	}

	// ── Hashtag counters ──────────────────────────────────────────────────────

	/**
	 * Recounting hashtag post count updates bn_hashtags.post_count.
	 */
	public function test_recount_hashtag_posts_updates_column(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_hashtags',
			array(
				'name' => 'php',
				'slug' => 'php',
			)
		);
		$tag_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => $this->user_id,
				'type'    => 'text',
				'content' => '#php',
				'privacy' => 'public',
			)
		);
		$post_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'bn_post_hashtags',
			array(
				'post_id'     => $post_id,
				'object_type' => 'post',
				'hashtag_id'  => $tag_id,
			)
		);

		$this->service->recount_hashtag_posts( $tag_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_count FROM {$wpdb->prefix}bn_hashtags WHERE id = %d",
				$tag_id
			)
		);

		$this->assertSame( 1, $count );
	}

	// ── Bulk (daily-job) recounts — S2(c) ──────────────────────────────────────

	/**
	 * Bulk space-member recount corrects a drifted member_count in one pass and
	 * counts only active members (pending/invited rows are excluded).
	 *
	 * @return void
	 */
	public function test_bulk_space_member_recount_corrects_drift_active_only(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'         => 'Bulk Space',
				'slug'         => 'bulk-space',
				'type'         => 'open',
				'owner_id'     => $this->user_id,
				'member_count' => 99, // Deliberately drifted.
			)
		);
		$space_id = (int) $wpdb->insert_id;

		// 2 active members + 1 pending (must not be counted).
		foreach ( array( 'active', 'active', 'pending' ) as $i => $status ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_space_members',
				array(
					'space_id' => $space_id,
					'user_id'  => self::factory()->user->create(),
					'role'     => 'member',
					'status'   => $status,
				)
			);
		}

		$this->service->recount_all_space_members();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id )
		);
		$this->assertSame( 2, $count, 'member_count reconciled to the active-member total.' );
	}

	/**
	 * Bulk hashtag recount corrects drifted post_count and follower_count.
	 *
	 * @return void
	 */
	public function test_bulk_hashtag_recount_corrects_drift(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_hashtags',
			array(
				'name'           => 'scale',
				'slug'           => 'scale',
				'post_count'     => 50, // Drifted.
				'follower_count' => 70, // Drifted.
			)
		);
		$tag_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => $this->user_id,
				'type'    => 'text',
				'content' => '#scale',
				'privacy' => 'public',
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'bn_post_hashtags',
			array(
				'post_id'     => (int) $wpdb->insert_id,
				'object_type' => 'post',
				'hashtag_id'  => $tag_id,
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'bn_hashtag_follows',
			array(
				'hashtag_id' => $tag_id,
				'user_id'    => $this->user_id,
			)
		);

		$this->service->recount_all_hashtag_counts();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT post_count, follower_count FROM {$wpdb->prefix}bn_hashtags WHERE id = %d", $tag_id )
		);
		$this->assertSame( 1, (int) $row->post_count, 'post_count reconciled.' );
		$this->assertSame( 1, (int) $row->follower_count, 'follower_count reconciled.' );
	}
}
