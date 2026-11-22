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

	public function test_a_space_an_add_on_hides_for_one_viewer_leaves_their_home_feed(): void {
		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();
		$space  = $this->space( $owner, 'open' );
		buddynext_service( 'space_members' )->join( $space, $viewer );
		$post = ( new PostService() )->create( $owner, array( 'type' => 'text', 'content' => 'a joined space post', 'space_id' => $space, 'privacy' => 'public' ) );
		$ids  = static function ( int $user ): array {
			return array_map( static fn( $item ) => (int) ( $item['id'] ?? 0 ), (array) ( buddynext_service( 'feed' )->home_feed( $user )['items'] ?? array() ) );
		};

		$this->assertContains( (int) $post, $ids( $viewer ), 'A joined Space shows in the home feed by default.' );

		$hide = static fn( $found, $user ) => $user === $viewer ? array_merge( (array) $found, array( $space ) ) : $found;
		add_filter( 'buddynext_feed_hidden_space_ids', $hide, 10, 2 );
		wp_cache_flush();
		// The hidden-space list is remembered per request; a filter added mid-request needs a fresh read.
		( new \ReflectionProperty( \BuddyNext\Spaces\SpaceVisibility::class, 'hidden_memo' ) )->setValue( null, array() );
		$this->assertNotContains( (int) $post, $ids( $viewer ), 'An add-on can close a Space for this viewer.' );
		$this->assertContains( (int) $post, $ids( $owner ), 'Other viewers are not affected.' );
		remove_filter( 'buddynext_feed_hidden_space_ids', $hide, 10 );
	}
}
