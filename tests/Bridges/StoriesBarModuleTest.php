<?php
/**
 * The stories bar's script module is queued in the head on the feed.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;

/**
 * The bar renders mid-page, so the block queued its module after the import map
 * had printed without the module's dependencies: the browser could not resolve
 * @wpmediaverse/sync-events and the bar's script never ran.
 *
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge::enqueue_stories_bar_module
 */
class StoriesBarModuleTest extends \WP_UnitTestCase {

	private const MODULE = 'bn-test-stories-view';

	public function set_up(): void {
		parent::set_up();
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'mvs/pro-stories' ) ) {
			register_block_type( 'mvs/pro-stories', array( 'view_script_module_ids' => array( self::MODULE ) ) );
		}
		wp_register_script_module( self::MODULE, 'https://example.test/view.js' );
		update_option( 'mvs_stories_enabled', '1' );
		wp_set_current_user( self::factory()->user->create() );
	}

	public function tear_down(): void {
		unregister_block_type( 'mvs/pro-stories' );
		wp_dequeue_script_module( self::MODULE );
		delete_option( 'mvs_stories_enabled' );
		set_query_var( 'bn_hub', '' );
		parent::tear_down();
	}

	private function queued(): bool {
		$queue = ( new \ReflectionProperty( \WP_Script_Modules::class, 'queue' ) )->getValue( wp_script_modules() );
		return in_array( self::MODULE, (array) $queue, true );
	}

	public function test_the_feed_queues_the_module_before_the_page_renders(): void {
		if ( ! \BuddyNext\Media\MediaClient::available() ) {
			$this->markTestSkipped( 'Needs the WPMediaVerse stub.' );
		}
		set_query_var( 'bn_hub', 'feed' );
		( new WPMediaVerseBridge() )->enqueue_stories_bar_module();

		$this->assertTrue( $this->queued() );
	}

	public function test_other_hubs_do_not(): void {
		set_query_var( 'bn_hub', 'spaces' );
		( new WPMediaVerseBridge() )->enqueue_stories_bar_module();

		$this->assertFalse( $this->queued() );
	}
}
