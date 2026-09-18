<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests the directory "Active" sort (card 10312614032): last_active_at ordering
 * with NULLs last, the comment-driven activity stamp and its 5-minute throttle,
 * and the single sort-map source shared by the SSR grid and the REST route.
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.2.1
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Covers SpaceService::sort_map(), the last_active_at ORDER BY and
 * touch_activity_from_comment().
 */
class SpaceActiveSortTest extends WP_UnitTestCase {

	private SpaceService $spaces;
	private int $owner_id;

	/**
	 * Fresh schema + service per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->spaces   = new SpaceService();
		$this->owner_id = self::factory()->user->create();
	}

	/**
	 * Create an open space and force its last_active_at + created_at to fixed values.
	 *
	 * @param string      $name        Space name (also the slug seed).
	 * @param string|null $last_active 'Y-m-d H:i:s' or null for "no activity yet".
	 * @param string      $created     'Y-m-d H:i:s' created_at.
	 * @return int Space id.
	 */
	private function seed_space( string $name, ?string $last_active, string $created ): int {
		global $wpdb;
		$id = (int) $this->spaces->create(
			$this->owner_id,
			array(
				'name' => $name,
				'slug' => sanitize_title( $name ),
				'type' => 'open',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_spaces',
			array(
				'last_active_at' => $last_active,
				'created_at'     => $created,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $id;
	}

	/**
	 * The ordered id list for the "Active" sort.
	 *
	 * @return array<int,int>
	 */
	private function active_order(): array {
		$rows = $this->spaces->list_spaces( array( 'orderby' => 'last_active_at' ) );
		return array_map( static fn( $r ) => (int) $r['id'], $rows );
	}

	public function test_active_sort_orders_recent_first_nulls_last(): void {
		$recent = $this->seed_space( 'Recent', '2026-09-18 10:00:00', '2026-01-01 00:00:00' );
		$older  = $this->seed_space( 'Older', '2026-09-01 10:00:00', '2026-01-01 00:00:00' );
		$never  = $this->seed_space( 'Never', null, '2026-01-01 00:00:00' );

		$order = $this->active_order();

		// Most-recently-active first; the never-active space sorts LAST, not first.
		$this->assertSame( $recent, $order[0] );
		$this->assertSame( $older, $order[1] );
		$this->assertSame( $never, $order[ count( $order ) - 1 ] );
	}

	public function test_active_sort_breaks_null_ties_by_created_at_desc(): void {
		// Two spaces with no activity yet: the newer one wins the tail order.
		$old_null = $this->seed_space( 'Old Null', null, '2026-01-01 00:00:00' );
		$new_null = $this->seed_space( 'New Null', null, '2026-06-01 00:00:00' );

		$order = $this->active_order();
		$pos   = array_flip( $order );

		$this->assertLessThan(
			$pos[ $old_null ],
			$pos[ $new_null ],
			'Newer created_at should rank ahead among the null-activity tail.'
		);
	}

	public function test_active_sort_is_stable_when_timestamps_are_identical(): void {
		// Two spaces sharing last_active_at AND created_at to the second (the bulk-seed
		// / import case). created_at can't disambiguate, so the id tie-break must make
		// the order deterministic - otherwise the same space can repeat or vanish
		// across paginated requests.
		$first  = $this->seed_space( 'Tie A', '2026-09-10 12:00:00', '2026-09-10 09:00:00' );
		$second = $this->seed_space( 'Tie B', '2026-09-10 12:00:00', '2026-09-10 09:00:00' );

		$order = $this->active_order();
		$pos   = array_flip( $order );

		// id DESC: the later-created (higher id) space wins deterministically.
		$this->assertLessThan( $pos[ $first ], $pos[ $second ] );
	}

	/**
	 * Popular (member_count), Newest (created_at) and A-Z (name) must each carry the
	 * same id tie-break the Active sort got, or two spaces sharing the sort value
	 * order non-deterministically and pagination skips/duplicates at scale
	 * (card 10317488684). DESC sorts break to id DESC (higher id first); the ASC
	 * A-Z sort breaks to id ASC (lower id first).
	 *
	 * @return void
	 */
	public function test_non_active_sorts_break_ties_by_id(): void {
		global $wpdb;
		// Distinct names so the slugs don't collide on create; created_at is already
		// tied by the seed args.
		$first  = $this->seed_space( 'Tie One', null, '2026-05-01 09:00:00' );
		$second = $this->seed_space( 'Tie Two', null, '2026-05-01 09:00:00' );

		// Now force the remaining sort keys equal: same member_count, same name. With
		// created_at already tied, id is the only discriminator left for every sort.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_spaces SET member_count = 7, name = 'Zzz Tie' WHERE id IN ( %d, %d )",
				$first,
				$second
			)
		);

		foreach ( array( 'member_count', 'created_at' ) as $orderby ) {
			$order = $this->order_ids( array( 'orderby' => $orderby ) );
			$pos   = array_flip( $order );
			$this->assertLessThan(
				$pos[ $first ],
				$pos[ $second ],
				$orderby . ' DESC must break the tie to id DESC (higher id first).'
			);
		}

		// A-Z is ASC, so the tie breaks the other way: lower id first.
		$alpha = $this->order_ids( array( 'orderby' => 'name', 'order' => 'ASC' ) );
		$apos  = array_flip( $alpha );
		$this->assertLessThan(
			$apos[ $second ],
			$apos[ $first ],
			'name ASC must break the tie to id ASC (lower id first).'
		);
	}

	/**
	 * The ordered id list for an arbitrary sort argument set.
	 *
	 * @param array<string,mixed> $args list_spaces() arguments.
	 * @return array<int,int>
	 */
	private function order_ids( array $args ): array {
		return array_map( static fn( $r ) => (int) $r['id'], $this->spaces->list_spaces( $args ) );
	}

	public function test_comment_on_space_post_stamps_activity(): void {
		global $wpdb;
		$space_id = $this->seed_space( 'Talkative', '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
		$post_id  = $this->seed_post( $space_id );

		$this->spaces->touch_activity_from_comment( 1, 'post', $post_id, $this->owner_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT last_active_at FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id )
		);
		$this->assertGreaterThan(
			strtotime( '2026-01-01 00:00:00' ),
			strtotime( $after ),
			'A comment on a space post should advance last_active_at.'
		);
	}

	public function test_second_comment_is_throttled(): void {
		global $wpdb;
		$space_id = $this->seed_space( 'Busy', '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
		$post_id  = $this->seed_post( $space_id );

		$this->spaces->touch_activity_from_comment( 1, 'post', $post_id, $this->owner_id );

		// Roll last_active_at back to a sentinel WITHOUT clearing the throttle; a
		// second comment inside the window must leave the sentinel untouched.
		$sentinel = '2020-05-05 05:05:05';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'last_active_at' => $sentinel ), array( 'id' => $space_id ), array( '%s' ), array( '%d' ) );

		$this->spaces->touch_activity_from_comment( 2, 'post', $post_id, $this->owner_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT last_active_at FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id )
		);
		$this->assertSame( $sentinel, $after, 'A comment inside the throttle window must not write.' );
	}

	public function test_comment_off_a_space_writes_nothing(): void {
		global $wpdb;
		$space_id = $this->seed_space( 'Untouched', '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
		$orphan   = $this->seed_post( 0 ); // A post with no space.

		// Wrong object type is ignored outright.
		$this->spaces->touch_activity_from_comment( 1, 'media', 999, $this->owner_id );
		// A post that belongs to no space resolves space_id 0 and writes nothing.
		$this->spaces->touch_activity_from_comment( 2, 'post', $orphan, $this->owner_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT last_active_at FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id )
		);
		$this->assertSame( '2026-01-01 00:00:00', $after, 'Neither call should touch any space.' );
	}

	public function test_hydrated_rows_expose_last_active_at(): void {
		// Guards the hydrate() seam: the directory card and the REST /spaces payload
		// both read $space['last_active_at']; if hydrate() drops it, every card reads
		// "No activity yet" no matter the sort. (Root cause caught in browser QA.)
		$this->seed_space( 'Stamped', '2026-09-16 06:10:45', '2026-01-01 00:00:00' );
		$this->seed_space( 'Blank', null, '2026-01-01 00:00:00' );

		$by_name = array();
		foreach ( $this->spaces->list_spaces( array( 'orderby' => 'last_active_at' ) ) as $row ) {
			$this->assertArrayHasKey( 'last_active_at', $row );
			$by_name[ $row['name'] ] = $row['last_active_at'];
		}
		$this->assertSame( '2026-09-16 06:10:45', $by_name['Stamped'] );
		$this->assertNull( $by_name['Blank'] );
	}

	public function test_sort_map_is_the_one_source_and_active_means_last_active(): void {
		$map = SpaceService::sort_map();

		// The mutation guard: if 'active' ever drifts back to member_count (the old
		// popularity-relabel), this fails by name.
		$this->assertSame( array( 'last_active_at', 'DESC' ), $map['active'] );
		$this->assertSame( array( 'member_count', 'DESC' ), $map['popular'] );
		$this->assertSame( array( 'created_at', 'DESC' ), $map['newest'] );
		$this->assertSame( array( 'name', 'ASC' ), $map['alphabetical'] );
	}

	/**
	 * Insert a minimal bn_posts row for a space (or 0 for no space).
	 *
	 * @param int $space_id Space id, or 0 for a spaceless post.
	 * @return int Post id.
	 */
	private function seed_post( int $space_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'  => $this->owner_id,
				'space_id' => $space_id > 0 ? $space_id : null,
				'type'     => 'text',
				'content'  => 'hi',
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}
}
