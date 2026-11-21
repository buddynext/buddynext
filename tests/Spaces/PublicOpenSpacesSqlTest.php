<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Which Spaces' posts the site feed, Explore and the hashtag feeds show to everyone.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Feed\PostService;
use BuddyNext\Spaces\SpaceVisibility;
use WP_UnitTestCase;

class PublicOpenSpacesSqlTest extends WP_UnitTestCase {

	private function space( int $owner, string $type, string $ability = '' ): int {
		global $wpdb;
		$id = (int) buddynext_service( 'spaces' )->create(
			$owner,
			array(
				'name' => 'Vis ' . $type . wp_rand(),
				'slug' => 'vis-' . $type . '-' . wp_rand( 10000, 99999 ),
				'type' => $type,
			)
		);
		if ( '' !== $ability ) {
			$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'required_ability' => $ability ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_flush();
		}
		return $id;
	}

	public function test_only_open_spaces_without_a_gate_are_public(): void {
		global $wpdb;
		$owner = self::factory()->user->create();
		$open  = $this->space( $owner, 'open' );
		$gated = $this->space( $owner, 'open', 'tier:pro' );
		$priv  = $this->space( $owner, 'private' );

		$ids = array_map( 'intval', (array) $wpdb->get_col( SpaceVisibility::public_open_spaces_sql() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$this->assertContains( $open, $ids );
		$this->assertNotContains( $gated, $ids, 'A plan-gated open Space is members-only in practice.' );
		$this->assertNotContains( $priv, $ids );
	}

	public function test_a_stranger_does_not_see_a_gated_spaces_posts_in_explore(): void {
		global $wpdb;
		$owner    = self::factory()->user->create();
		$stranger = self::factory()->user->create();
		$posts    = new PostService();
		$open_id  = $posts->create(
			$owner,
			array(
				'type'     => 'text',
				'content'  => 'open space post',
				'space_id' => $this->space( $owner, 'open' ),
				'privacy'  => 'public',
			)
		);
		$gate_id  = $posts->create(
			$owner,
			array(
				'type'     => 'text',
				'content'  => 'gated space post',
				'space_id' => $this->space( $owner, 'open', 'tier:pro' ),
				'privacy'  => 'public',
			)
		);

		$where = buddynext_service( 'feed' )->explore_space_where( $stranger );
		$seen  = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE {$where}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertContains( (int) $open_id, $seen );
		$this->assertNotContains( (int) $gate_id, $seen );
	}
}
