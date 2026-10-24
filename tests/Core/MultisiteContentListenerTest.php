<?php
/**
 * Content events on another site of a network never reach the community tables.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * Another plugin can switch_to_blog() and publish there while a BuddyNext
 * listener is still registered on the main site. The listener used to query
 * that site's bn_* tables, which do not exist. Run with WP_MULTISITE=1.
 */
class MultisiteContentListenerTest extends \WP_UnitTestCase {

	public function test_publishing_while_switched_to_a_subsite_touches_no_community_table(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		global $wpdb;

		$site_id = (int) self::factory()->blog->create();
		$needle  = $wpdb->get_blog_prefix( $site_id ) . 'bn_';
		$seen    = array();
		$spy     = static function ( $sql ) use ( &$seen, $needle ) {
			if ( str_contains( (string) $sql, $needle ) ) {
				$seen[] = (string) $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );

		switch_to_blog( $site_id );
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Published on a subsite',
				'post_status' => 'publish',
			)
		);
		wp_delete_post( $post_id, true );
		restore_current_blog();

		remove_filter( 'query', $spy );
		wp_delete_site( $site_id );

		$this->assertSame( array(), $seen, 'No query may name a subsite\'s bn_* table.' );
	}
}
