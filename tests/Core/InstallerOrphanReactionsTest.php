<?php
/**
 * The v56 data purge deletes bn_reactions rows whose target object was
 * hard-deleted, so orphaned reactions stop inflating counts (card 10264292715).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\Installer::maybe_purge_orphan_reactions
 */
class InstallerOrphanReactionsTest extends WP_UnitTestCase {

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Insert a reaction row directly (the fixture must create the orphan state the
	 * write path now refuses to create).
	 *
	 * @param int    $user_id User id.
	 * @param string $type    Object type.
	 * @param int    $object  Object id.
	 * @return void
	 */
	private function seed_reaction( int $user_id, string $type, int $object ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture creating an orphan the API cannot.
		$wpdb->insert(
			$wpdb->prefix . 'bn_reactions',
			array(
				'user_id'     => $user_id,
				'object_type' => $type,
				'object_id'   => $object,
				'emoji'       => 'like',
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * The purge deletes reactions on a missing comment/post but keeps reactions
	 * on a live target and on other object types.
	 *
	 * @return void
	 */
	public function test_purge_removes_only_orphan_reactions(): void {
		global $wpdb;

		// Insert the target rows directly so nothing else (activity hooks, counters)
		// adds stray reactions — full control over the state under test.
		$author = self::factory()->user->create();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array( 'user_id' => $author, 'content' => 'x', 'type' => 'text', 'privacy' => 'public', 'status' => 'published', 'created_at' => current_time( 'mysql', true ) ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$post_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array( 'user_id' => $author, 'object_type' => 'post', 'object_id' => $post_id, 'content' => 'hi', 'created_at' => current_time( 'mysql', true ) ),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
		$comment_id = (int) $wpdb->insert_id;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$reactor = self::factory()->user->create();
		$other   = self::factory()->user->create();
		// Valid reactions (targets exist).
		$this->seed_reaction( $reactor, 'post', $post_id );
		$this->seed_reaction( $reactor, 'comment', $comment_id );
		// Orphan reactions (targets do not exist).
		$this->seed_reaction( $reactor, 'post', 999901 );
		$this->seed_reaction( $other, 'comment', 999902 );
		// A different object type must be left untouched even if orphaned.
		$this->seed_reaction( $reactor, 'media', 999903 );

		$table = $wpdb->prefix . 'bn_reactions';
		$exists = static function ( string $type, int $object ) use ( $wpdb, $table ): int {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type=%s AND object_id=%d", $type, $object ) );
		};

		// Precondition: both orphans present.
		$this->assertSame( 1, $exists( 'post', 999901 ) );
		$this->assertSame( 1, $exists( 'comment', 999902 ) );

		$purge = new ReflectionMethod( Installer::class, 'maybe_purge_orphan_reactions' );
		$purge->invoke( null );

		// The two target-missing reactions are gone.
		$this->assertSame( 0, $exists( 'post', 999901 ), 'Orphan post reaction must be purged.' );
		$this->assertSame( 0, $exists( 'comment', 999902 ), 'Orphan comment reaction must be purged.' );
		// The valid reactions and the other-type (media) reaction survive.
		$this->assertSame( 1, $exists( 'post', $post_id ), 'A reaction on a live post survives.' );
		$this->assertSame( 1, $exists( 'comment', $comment_id ), 'A reaction on a live comment survives.' );
		$this->assertSame( 1, $exists( 'media', 999903 ), 'A media reaction (other type) is not touched.' );
	}
}
