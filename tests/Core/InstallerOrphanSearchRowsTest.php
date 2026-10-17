<?php
/**
 * The v69 data purge deletes bn_search_index rows whose member, post or space
 * no longer exists, so search stops linking to deleted things on sites that
 * removed them before deletes cleaned the index (card 10415529534).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\Installer::maybe_purge_orphan_search_rows
 */
class InstallerOrphanSearchRowsTest extends WP_UnitTestCase {

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Insert an index row directly (the delete paths no longer leave orphans).
	 *
	 * @param string $type   Object type.
	 * @param int    $object Object id.
	 * @return void
	 */
	private function seed_row( string $type, int $object ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture creating an orphan the API cannot.
		$wpdb->replace(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => $type,
				'object_id'   => $object,
				'title'       => 'Weekend trail running group',
				'author_id'   => $object,
			),
			array( '%s', '%d', '%s', '%d' )
		);
	}

	/**
	 * Count index rows for one object.
	 *
	 * @param string $type   Object type.
	 * @param int    $object Object id.
	 * @return int
	 */
	private function rows( string $type, int $object ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test read-back.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = %s AND object_id = %d", $type, $object ) );
	}

	/**
	 * Rows for a missing member, post or space go; live ones and partner types stay.
	 *
	 * @return void
	 */
	public function test_purge_removes_only_orphan_rows(): void {
		global $wpdb;

		$member = self::factory()->user->create();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array( 'user_id' => $member, 'content' => 'Trail map for Sunday', 'type' => 'text', 'privacy' => 'public', 'status' => 'published', 'created_at' => current_time( 'mysql', true ) ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$post_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array( 'name' => 'Trail Runners', 'slug' => 'trail-runners-' . $member, 'owner_id' => $member, 'created_at' => current_time( 'mysql', true ) ),
			array( '%s', '%s', '%d', '%s' )
		);
		$space_id = (int) $wpdb->insert_id;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$gone = 9000000;
		foreach ( array( 'user' => $member, 'post' => $post_id, 'space' => $space_id ) as $type => $live ) {
			$this->seed_row( $type, $live );
			$this->seed_row( $type, $gone );
		}
		$this->seed_row( 'job', $gone );

		$purge = new ReflectionMethod( Installer::class, 'maybe_purge_orphan_search_rows' );
		$purge->setAccessible( true );
		$purge->invoke( null );

		foreach ( array( 'user' => $member, 'post' => $post_id, 'space' => $space_id ) as $type => $live ) {
			$this->assertSame( 1, $this->rows( $type, $live ), "live {$type} row kept" );
			$this->assertSame( 0, $this->rows( $type, $gone ), "orphan {$type} row removed" );
		}
		$this->assertSame( 1, $this->rows( 'job', $gone ), 'partner types are left to their bridge' );

		$purge->invoke( null );
		$this->assertSame( 1, $this->rows( 'user', $member ), 'idempotent' );
	}
}
