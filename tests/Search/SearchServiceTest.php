<?php
/**
 * Tests for SearchService.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchService;

/**
 * @covers \BuddyNext\Search\SearchService
 */
class SearchServiceTest extends \WP_UnitTestCase {

	private SearchService $service;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service   = new SearchService();
		$this->author_id = self::factory()->user->create();
	}

	public function test_index_creates_record(): void {
		global $wpdb;

		$this->service->index( 'post', 42, 'Hello World', 'Some content', $this->author_id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				42
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'Hello World', $row['title'] );
		$this->assertSame( 'public', $row['visibility'] );
	}

	public function test_index_upserts_on_duplicate(): void {
		global $wpdb;

		$this->service->index( 'post', 10, 'Original Title', 'Original content', $this->author_id );
		$this->service->index( 'post', 10, 'Updated Title', 'Updated content', $this->author_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				10
			)
		);

		$this->assertSame( 1, $count );

		$title = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT title FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				10
			)
		);

		$this->assertSame( 'Updated Title', $title );
	}

	public function test_deindex_removes_record(): void {
		global $wpdb;

		$this->service->index( 'post', 99, 'To Delete', '', $this->author_id );
		$this->service->deindex( 'post', 99 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				99
			)
		);

		$this->assertSame( 0, $count );
	}

	public function test_search_returns_matching_records(): void {
		$this->service->index( 'post', 1, 'WordPress Development', 'Building plugins for WordPress', $this->author_id );
		$this->service->index( 'post', 2, 'JavaScript Tips', 'Front-end development guide', $this->author_id );

		$results = $this->service->search( 'WordPress' );

		$this->assertArrayHasKey( 'items', $results );
		$this->assertArrayHasKey( 'total', $results );

		$object_ids = array_column( $results['items'], 'object_id' );
		$this->assertContains( 1, $object_ids );
	}

	public function test_search_filters_by_type(): void {
		$this->service->index( 'post', 5, 'Post about PHP', '', $this->author_id );
		$this->service->index( 'user', 6, 'PHP Developer', '', $this->author_id );

		$results = $this->service->search( 'PHP', 'post' );

		$types = array_column( $results['items'], 'object_type' );
		$this->assertNotContains( 'user', $types );
	}

	public function test_search_excludes_private_records(): void {
		$this->service->index( 'post', 7, 'Secret Post', 'Hidden content', $this->author_id, 'private' );
		$this->service->index( 'post', 8, 'Public Post', 'Public content', $this->author_id, 'public' );

		$results = $this->service->search( 'content' );

		$object_ids = array_column( $results['items'], 'object_id' );
		$this->assertNotContains( 7, $object_ids );
		$this->assertContains( 8, $object_ids );
	}

	public function test_search_returns_empty_for_no_match(): void {
		$this->service->index( 'post', 20, 'Completely Unrelated', 'Nothing here', $this->author_id );

		$results = $this->service->search( 'xyznotfound12345' );

		$this->assertSame( 0, $results['total'] );
		$this->assertEmpty( $results['items'] );
	}
}
