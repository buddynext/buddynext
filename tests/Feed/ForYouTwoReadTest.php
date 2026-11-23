<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,WordPress.DB.DirectDatabaseQuery -- PSR-4 naming; fixture writes.
/**
 * The For you feed reads in two index-served queries and returns exactly the
 * pages the single tiered query returned.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\FeedService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Feed\FeedService::home_feed
 */
class ForYouTwoReadTest extends WP_UnitTestCase {

	/**
	 * Viewers by role.
	 *
	 * @var array<string,int>
	 */
	private array $viewers = array();

	/**
	 * About 2,000 posts across every tier and visibility case.
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		mt_srand( 42 );

		$p       = $wpdb->prefix;
		$authors = self::factory()->user->create_many( 30 );

		$this->viewers = array(
			'many_connections' => self::factory()->user->create(),
			'no_connections'   => self::factory()->user->create(),
			'admin'            => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'new_member'       => self::factory()->user->create(),
		);
		$viewer        = $this->viewers['many_connections'];

		foreach ( array_slice( $authors, 0, 12 ) as $a ) {
			$wpdb->insert(
				"{$p}bn_connections",
				array(
					'requester_id' => $viewer,
					'recipient_id' => $a,
					'status'       => 'accepted',
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
		foreach ( array_slice( $authors, 5, 10 ) as $a ) {
			$wpdb->insert(
				"{$p}bn_follows",
				array(
					'follower_id'  => $viewer,
					'following_id' => $a,
					'status'       => 'approved',
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
		// The admin follows a few too, so the admin's feed has a follow blend.
		foreach ( array_slice( $authors, 20, 3 ) as $a ) {
			$wpdb->insert(
				"{$p}bn_follows",
				array(
					'follower_id'  => $this->viewers['admin'],
					'following_id' => $a,
					'status'       => 'approved',
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		// An open space in an interest category (the interests tier) and a private space.
		$wpdb->insert(
			"{$p}bn_space_categories",
			array(
				'name' => 'Cooking',
				'slug' => 'cooking-' . wp_rand(),
			)
		);
		$cat = (int) $wpdb->insert_id;
		$wpdb->insert(
			"{$p}bn_spaces",
			array(
				'name'        => 'Open',
				'slug'        => 'open-' . wp_rand(),
				'type'        => 'open',
				'owner_id'    => $authors[0],
				'category_id' => $cat,
			)
		);
		$open = (int) $wpdb->insert_id;
		$wpdb->insert(
			"{$p}bn_spaces",
			array(
				'name'     => 'Closed',
				'slug'     => 'closed-' . wp_rand(),
				'type'     => 'private',
				'owner_id' => $authors[1],
			)
		);
		$closed = (int) $wpdb->insert_id;
		$wpdb->insert(
			"{$p}bn_space_members",
			array(
				'space_id' => $closed,
				'user_id'  => $viewer,
				'role'     => 'member',
				'status'   => 'active',
			)
		);
		wp_cache_set( 'interests_' . $viewer, array( $cat ), 'buddynext_onboarding' );

		// Blocked and muted authors.
		buddynext_service( 'blocks' )->block( $viewer, $authors[2] );
		buddynext_service( 'blocks' )->mute( $viewer, $authors[3] );

		$privacy = array( 'public', 'public', 'public', 'followers', 'connections', 'private' );
		$now     = time();
		$rows    = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$own    = 0 === $i % 97;
			$author = $own ? $viewer : $authors[ mt_rand( 0, 29 ) ];
			// Most posts in the last 30 days, a slice inside the 15-minute and 7-day windows,
			// and whole-minute stamps so several posts share a created_at (id tie-break).
			$age = $own && 0 === $i % 2 ? mt_rand( 0, 10 ) * 60 : mt_rand( 0, 30 * 24 ) * 60 * 60 / mt_rand( 1, 4 );
			$age = (int) ( floor( $age / 60 ) * 60 );
			// The 300 newest posts are private to their authors: the rest read scans past them.
			if ( $i >= 1700 ) {
				$author = $authors[ mt_rand( 4, 29 ) ];
				$age    = mt_rand( 0, 300 );
			}
			$space  = 0 === $i % 11 ? $open : ( 0 === $i % 13 ? $closed : null );
			$rows[] = $wpdb->prepare(
				'(%d, %s, %s, %s, %s, %s, %s, %s)',
				$author,
				null === $space ? '' : (string) $space,
				'text',
				'fixture',
				$i >= 1700 ? 'private' : $privacy[ mt_rand( 0, 5 ) ],
				0 === $i % 50 ? 'draft' : 'published',
				gmdate( 'Y-m-d H:i:s', $now - $age ),
				0 === $i % 333 ? gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS ) : ''
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- each row prepared above.
		$wpdb->query( "INSERT INTO {$p}bn_posts (user_id, space_id, type, content, privacy, status, created_at, scheduled_at) VALUES " . implode( ',', $rows ) );
		$wpdb->query( "UPDATE {$p}bn_posts SET space_id = NULL WHERE space_id = 0" );
		$wpdb->query( "UPDATE {$p}bn_posts SET scheduled_at = NULL WHERE scheduled_at = '0000-00-00 00:00:00'" );
	}

	/**
	 * Unseed the random generator for the tests that follow.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		mt_srand();
		parent::tear_down();
	}

	/**
	 * Every page of For you, as [ids, next_cursor] per page.
	 *
	 * @param int  $viewer Viewer.
	 * @param bool $single Run the old single query.
	 * @return array<int,array{0:int[],1:string|null}>
	 */
	private function pages( int $viewer, bool $single, int $per_page = 20 ): array {
		$seam = new \ReflectionProperty( FeedService::class, 'single_query' );
		$seam->setValue( null, $single );

		wp_set_current_user( $viewer );
		$feed   = buddynext_service( 'feed' );
		$call   = new \ReflectionMethod( FeedService::class, 'home_feed_uncached' );
		$pages  = array();
		$cursor = null;
		do {
			$page    = $call->invoke( $feed, $viewer, $cursor, $per_page, 'for-you' );
			$cursor  = $page['next_cursor'];
			$pages[] = array( array_map( 'intval', wp_list_pluck( $page['items'], 'id' ) ), $cursor );
		} while ( null !== $cursor && count( $pages ) < 200 );

		$seam->setValue( null, false );
		return $pages;
	}

	/**
	 * Same ids, same order, same cursors on every page, for every viewer.
	 *
	 * @return void
	 */
	public function test_every_page_matches_the_single_query(): void {
		if ( ! property_exists( FeedService::class, 'single_query' ) ) {
			$this->markTestSkipped( 'Code without the two-read feed has only the single query.' );
		}
		$seen   = array();
		$window = new \ReflectionProperty( FeedService::class, 'scan_window' );
		foreach ( $this->viewers as $role => $viewer ) {
			$old = $this->pages( $viewer, true );
			$new = $this->pages( $viewer, false );
			// A small walk window: pages are found inside it, or by the table-scan fallback.
			$window->setValue( null, 25 );
			$small = $this->pages( $viewer, false );
			$window->setValue( null, 2000 );
			$this->assertSame( $old, $small, "$role: pages differ with a small walk window." );
			$this->assertGreaterThan( 3, count( $old ), "$role sees several pages." );
			$this->assertSame( $old, $new, "$role: pages differ." );
			$tiers = array_map( static fn( $page ) => null === $page[1] ? null : \BuddyNext\Core\CursorCodec::decode( $page[1] )['tier'], $old );
			$seen  = array_merge( $seen, array_filter( $tiers, 'is_int' ) );
		}
		// Small pages end inside every boosted tier (own, connections, interests).
		foreach ( array( 1, 5 ) as $per_page ) {
			$old = $this->pages( $this->viewers['many_connections'], true, $per_page );
			$this->assertSame( $old, $this->pages( $this->viewers['many_connections'], false, $per_page ), "Pages of $per_page differ." );
			foreach ( $old as $page ) {
				$seen[] = null === $page[1] ? null : \BuddyNext\Core\CursorCodec::decode( $page[1] )['tier'];
			}
		}
		foreach ( array( 0, 1, 2 ) as $tier ) {
			$this->assertContains( $tier, $seen, "Some page ends inside boosted tier $tier, so the next page continues that tier's read." );
		}

		// Tier 0 present only for the viewer who posted in the last minutes.
		foreach ( array(
			'many_connections' => true,
			'no_connections'   => false,
		) as $role => $has_own ) {
			wp_set_current_user( $this->viewers[ $role ] );
			$first = ( new \ReflectionMethod( FeedService::class, 'home_feed_uncached' ) )->invoke( buddynext_service( 'feed' ), $this->viewers[ $role ], null, 50, 'for-you' );
			$own   = array_filter( $first['items'], fn( $item ) => (int) $item['user_id'] === $this->viewers[ $role ] );
			$this->assertSame( $has_own, array() !== $own, "$role: own recent posts on page 1." );
		}
	}

	/**
	 * No For you read sorts by the per-viewer tier CASE over every post: the old
	 * single query did, which is a full scan and filesort at any size.
	 *
	 * @return void
	 */
	public function test_no_read_sorts_the_whole_table_by_the_tier(): void {
		$queries = array();
		$capture = static function ( $sql ) use ( &$queries ) {
			if ( str_contains( $sql, 'bn_posts' ) && str_contains( $sql, 'ORDER BY' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		$viewer = $this->viewers['no_connections'];
		wp_set_current_user( $viewer );
		( new \ReflectionMethod( FeedService::class, 'home_feed_uncached' ) )->invoke( buddynext_service( 'feed' ), $viewer, null, 20, 'for-you' );
		remove_filter( 'query', $capture );

		$tiered = array_filter( $queries, static fn( $q ) => (bool) preg_match( '/ORDER BY\s+CASE/', $q ) );
		foreach ( $tiered as $q ) {
			// A tiered sort is fine only over the boosted rows, never the whole table: the
			// boost condition is in the WHERE too, not only in the SELECT and ORDER BY CASE.
			$this->assertGreaterThanOrEqual( 3, substr_count( $q, 'MINUTE )' ), 'The tiered sort is limited to the boosted rows.' );
		}
		$this->assertNotEmpty( preg_grep( '/AS bn_tier .*ORDER BY created_at DESC, id DESC/s', $queries ), 'The rest is read by date.' );
	}
}
