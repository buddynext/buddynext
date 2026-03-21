<?php
/**
 * Tests for HashtagService.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Hashtags\HashtagService;

/**
 * @covers \BuddyNext\Hashtags\HashtagService
 */
class HashtagServiceTest extends \WP_UnitTestCase {

	private HashtagService $service;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new HashtagService();
		$this->user_id = self::factory()->user->create();
	}

	public function test_extract_returns_hashtags_from_content(): void {
		$tags = $this->service->extract( 'Hello #world and #php are great' );

		$this->assertContains( 'world', $tags );
		$this->assertContains( 'php', $tags );
	}

	public function test_extract_deduplicates(): void {
		$tags = $this->service->extract( '#foo and #foo again' );

		$this->assertCount( 1, $tags );
	}

	public function test_extract_returns_empty_when_no_hashtags(): void {
		$tags = $this->service->extract( 'No hashtags here' );

		$this->assertEmpty( $tags );
	}

	public function test_sync_creates_hashtag_rows(): void {
		global $wpdb;

		$this->service->sync( 'post', 1, array( 'wordpress', 'php' ) );

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_hashtags WHERE slug IN ('wordpress', 'php')"
		);

		$this->assertSame( 2, $count );
	}

	public function test_sync_creates_post_hashtag_links(): void {
		global $wpdb;

		$this->service->sync( 'post', 5, array( 'test' ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_post_hashtags WHERE post_id = %d AND object_type = 'post'",
				5
			)
		);

		$this->assertSame( 1, $count );
	}

	public function test_sync_is_idempotent(): void {
		global $wpdb;

		$this->service->sync( 'post', 10, array( 'idempotent' ) );
		$this->service->sync( 'post', 10, array( 'idempotent' ) );

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_hashtags WHERE slug = 'idempotent'"
		);

		$this->assertSame( 1, $count );
	}

	public function test_get_by_slug_returns_hashtag(): void {
		$this->service->sync( 'post', 20, array( 'buddynext' ) );

		$tag = $this->service->get_by_slug( 'buddynext' );

		$this->assertNotNull( $tag );
		$this->assertSame( 'buddynext', $tag['slug'] );
	}

	public function test_get_by_slug_returns_null_for_missing(): void {
		$this->assertNull( $this->service->get_by_slug( 'nonexistentxyz' ) );
	}

	public function test_sync_removes_old_links_on_update(): void {
		global $wpdb;

		// Post originally tagged with 'old', now updated to 'new'.
		$this->service->sync( 'post', 30, array( 'old' ) );
		$this->service->sync( 'post', 30, array( 'new' ) );

		$old_link = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_hashtags
				 JOIN {$wpdb->prefix}bn_post_hashtags ON {$wpdb->prefix}bn_hashtags.id = {$wpdb->prefix}bn_post_hashtags.hashtag_id
				 WHERE {$wpdb->prefix}bn_post_hashtags.post_id = %d AND {$wpdb->prefix}bn_hashtags.slug = 'old'",
				30
			)
		);

		$this->assertNull( $old_link );
	}
}
