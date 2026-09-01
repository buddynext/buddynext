<?php
/**
 * Tests for the moderation object label resolver.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\ObjectLabels;

/**
 * A moderation surface must name what it is showing, and say when it is gone.
 *
 * @covers \BuddyNext\Core\ObjectLabels
 */
class ObjectLabelsTest extends \WP_UnitTestCase {

	/**
	 * Install the schema and clear the per-request cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		ObjectLabels::flush();
	}

	/**
	 * Insert a row and return its id.
	 *
	 * @param string              $table Unprefixed table name.
	 * @param array<string,mixed> $data  Row data.
	 * @return int
	 */
	private function insert( string $table, array $data ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . $table, $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * A live member is named, not numbered.
	 *
	 * @return void
	 */
	public function test_live_user_renders_display_name(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Alex Rivera' ) );

		$this->assertSame( 'Alex Rivera', buddynext_object_label( 'user', $user_id ) );
	}

	/**
	 * A deleted member is named as deleted, keeping the id as a handle.
	 *
	 * @return void
	 */
	public function test_missing_user_is_tombstoned(): void {
		$this->assertSame( 'Deleted member (#987654)', buddynext_object_label( 'user', 987654 ) );
	}

	/**
	 * A live space renders its name — the thing a moderator judges it by.
	 *
	 * @return void
	 */
	public function test_live_space_renders_its_name(): void {
		$space_id = $this->insert(
			'bn_spaces',
			array(
				'name'       => 'Open Discussion',
				'slug'       => 'open-discussion-' . wp_rand( 1000, 9999 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);

		$this->assertSame( 'Open Discussion', buddynext_object_label( 'space', $space_id ) );
	}

	/**
	 * A destroyed space is named as destroyed.
	 *
	 * @return void
	 */
	public function test_missing_space_is_tombstoned(): void {
		$this->assertSame( 'Deleted space (#4242)', buddynext_object_label( 'space', 4242 ) );
	}

	/**
	 * A post keeps its id (that IS its handle) but gains a deleted state.
	 *
	 * bn_mod_log rows deliberately outlive their targets, so "post #4046" had to
	 * stop meaning both "live" and "destroyed".
	 *
	 * @return void
	 */
	public function test_post_label_distinguishes_live_from_deleted(): void {
		$post_id = $this->insert(
			'bn_posts',
			array(
				'user_id'    => self::factory()->user->create(),
				'content'    => 'probe',
				'created_at' => current_time( 'mysql', true ),
			)
		);

		$this->assertSame( 'Post #' . $post_id, buddynext_object_label( 'post', $post_id ) );
		$this->assertSame( 'Deleted post (#777777)', buddynext_object_label( 'post', 777777 ) );
	}

	/**
	 * A type BuddyNext does not own is never claimed to be deleted.
	 *
	 * Messages live in the WPMediaVerse engine and the moderation layer lets an
	 * add-on claim its own object type. Saying "Deleted message (#5)" about
	 * something we cannot see would be a confident lie on an audit surface.
	 *
	 * @return void
	 */
	public function test_unowned_type_is_never_claimed_deleted(): void {
		$label = buddynext_object_label( 'message', 5 );

		$this->assertSame( 'Message #5', $label );
		$this->assertStringNotContainsString( 'Deleted', $label );
		$this->assertNull( buddynext_object_exists( 'message', 5 ), 'unowned types answer "unknown", not "gone"' );
	}

	/**
	 * exists() separates gone from unknowable for owned types.
	 *
	 * @return void
	 */
	public function test_exists_reports_owned_types_definitively(): void {
		$space_id = $this->insert(
			'bn_spaces',
			array(
				'name'       => 'Real Space',
				'slug'       => 'real-space-' . wp_rand( 1000, 9999 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);

		$this->assertTrue( buddynext_object_exists( 'space', $space_id ) );
		$this->assertFalse( buddynext_object_exists( 'space', 999999 ) );
	}

	/**
	 * An empty pair renders an em dash rather than a stray "#0".
	 *
	 * @return void
	 */
	public function test_empty_pair_renders_a_dash(): void {
		$this->assertSame( "\u{2014}", buddynext_object_label( '', 0 ) );
		$this->assertSame( "\u{2014}", buddynext_object_label( 'post', 0 ) );
	}

	/**
	 * A page of objects costs one query per TYPE, not one per row.
	 *
	 * A moderation queue renders a full page of reports; resolving each as it is
	 * printed is the N+1 the big-site rules exist to prevent.
	 *
	 * @return void
	 */
	public function test_priming_a_page_costs_one_query_per_type(): void {
		global $wpdb;

		$author = self::factory()->user->create();
		$pairs  = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$pairs[] = array(
				'post',
				$this->insert(
					'bn_posts',
					array(
						'user_id'    => $author,
						'content'    => 'probe ' . $i,
						'created_at' => current_time( 'mysql', true ),
					)
				),
			);
		}

		ObjectLabels::flush();

		$before = $wpdb->num_queries;
		buddynext_prime_object_labels( $pairs );
		$primed = $wpdb->num_queries - $before;

		$this->assertLessThanOrEqual( 1, $primed, 'priming 12 posts must be a single query' );

		// Rendering the page must now cost nothing further.
		$before = $wpdb->num_queries;
		foreach ( $pairs as $pair ) {
			buddynext_object_label( $pair[0], $pair[1] );
		}
		$this->assertSame( 0, $wpdb->num_queries - $before, 'labels must be served from the primed cache' );
	}

	/**
	 * A missing id resolved once is remembered, not re-queried.
	 *
	 * @return void
	 */
	public function test_a_missing_object_is_not_requeried(): void {
		global $wpdb;

		buddynext_object_label( 'post', 555555 );

		$before = $wpdb->num_queries;
		buddynext_object_label( 'post', 555555 );

		$this->assertSame( 0, $wpdb->num_queries - $before );
	}

	/**
	 * An add-on can name an object type it owns.
	 *
	 * @return void
	 */
	public function test_filter_lets_an_addon_name_its_own_type(): void {
		add_filter(
			'buddynext_object_label',
			static function ( string $label, string $type, int $id ): string {
				return 'listing' === $type ? 'Listing: Blue Bicycle' : $label;
			},
			10,
			3
		);

		$this->assertSame( 'Listing: Blue Bicycle', buddynext_object_label( 'listing', 9 ) );
	}
}
