<?php
/**
 * A plan-gated open Space is private for its forum, and the repair command must use that same rule.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * JetonomyBridge::forum_type_for() as the repair command reads it.
 */
class GatedSpaceForumTypeTest extends WP_UnitTestCase {

	/**
	 * Create the tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * An open Space with a plan gate maps to a private forum, an ungated one stays public.
	 *
	 * @return void
	 */
	public function test_a_gated_open_space_is_private_for_its_forum(): void {
		global $wpdb;

		$owner = self::factory()->user->create();
		$id    = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Gated open',
				'slug' => 'gated-open-' . wp_rand( 10000, 99999 ),
				'type' => 'open',
			)
		);
		$this->assertIsInt( $id );

		$plain = buddynext_service( 'spaces' )->get( (int) $id );
		$this->assertSame( 'public', JetonomyBridge::discussion_visibility_for( JetonomyBridge::forum_type_for( (array) $plain ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'required_ability' => 'tier:pro' ), array( 'id' => (int) $id ) );
		wp_cache_flush();

		$gated = buddynext_service( 'spaces' )->get( (int) $id );
		$this->assertSame( 'private', JetonomyBridge::discussion_visibility_for( JetonomyBridge::forum_type_for( (array) $gated ) ), 'the hydrated row carries the gate the repair command must honour' );
	}

	/**
	 * The repair command asks forum_type_for() before it maps a type to a visibility.
	 *
	 * @return void
	 */
	public function test_the_repair_command_shapes_the_type_first(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Bridges/DiscussionVisibilityRepairCommand.php' );
		$this->assertStringContainsString( 'JetonomyBridge::forum_type_for(', $source );
		$this->assertStringNotContainsString( 'discussion_visibility_for( (string) $space->type )', $source );
	}

	/**
	 * Writing a forum with Jetonomy off or too old to have the model answers with an error, never a fatal.
	 *
	 * @return void
	 */
	public function test_write_forum_without_jetonomy_is_an_error_not_a_fatal(): void {
		if ( class_exists( '\\Jetonomy\\Models\\Space' ) ) {
			$this->markTestSkipped( 'Jetonomy is loaded in this run.' );
		}
		$this->assertWPError( JetonomyBridge::write_forum( 1, array( 'visibility' => 'private' ) ) );
	}
}
