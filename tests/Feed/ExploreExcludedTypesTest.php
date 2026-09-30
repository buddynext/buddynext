<?php
/**
 * Document posts stay off the public Explore deck by default, with a filter to
 * opt in; reshares can never be re-added (card 10344283332).
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Feed\FeedService::explore_renderable_where
 */
class ExploreExcludedTypesTest extends \WP_UnitTestCase {

	private int $doc  = 0;
	private int $text = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;
		$author = self::factory()->user->create();
		foreach ( array( 'doc' => 'document', 'text' => 'text' ) as $key => $type ) {
			$wpdb->insert( $wpdb->prefix . 'bn_posts', array( 'user_id' => $author, 'type' => $type, 'content' => "Explore {$type}", 'status' => 'published', 'privacy' => 'public' ) );
			$this->$key = (int) $wpdb->insert_id;
		}
	}

	/**
	 * The ids Explore would surface, through the same fragments its pulse count uses.
	 *
	 * @return int[]
	 */
	private function explore_ids(): array {
		global $wpdb;
		$feed = buddynext_service( 'feed' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE " . $feed->explore_space_where() . " AND status = 'published'" . $feed->explore_renderable_where() ) );
	}

	public function test_documents_are_off_explore_by_default(): void {
		$ids = $this->explore_ids();
		$this->assertContains( $this->text, $ids );
		$this->assertNotContains( $this->doc, $ids );
	}

	public function test_filter_opts_documents_back_in(): void {
		add_filter( 'buddynext_explore_excluded_post_types', '__return_empty_array' );
		$this->assertContains( $this->doc, $this->explore_ids() );
		remove_filter( 'buddynext_explore_excluded_post_types', '__return_empty_array' );
	}

	public function test_hostile_filter_value_cannot_break_the_query(): void {
		$hostile = static fn(): array => array( "document') OR 1=1 -- ", 'text' );
		add_filter( 'buddynext_explore_excluded_post_types', $hostile );
		$ids = $this->explore_ids();
		remove_filter( 'buddynext_explore_excluded_post_types', $hostile );
		$this->assertNotContains( $this->text, $ids, 'a real type in the list is still excluded' );
		$this->assertContains( $this->doc, $ids, 'the injected text is sanitized to a harmless key, not SQL' );
	}
}
