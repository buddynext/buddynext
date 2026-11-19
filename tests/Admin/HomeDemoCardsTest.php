<?php
/**
 * Home offers a demo only while setup is still open.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\SetupChecklist;
use BuddyNext\Demo\DemoAdmin;

/**
 * An owner who finished or dismissed setup is not offered a demo build again, but a demo
 * that is still on the site keeps its card so it can be removed.
 *
 * @covers \BuddyNext\Demo\DemoAdmin::render_installer_card
 * @covers \BuddyNext\Demo\DemoAdmin::render_promo
 * @covers \BuddyNext\Admin\SetupChecklist::is_done
 */
class HomeDemoCardsTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'buddynext_setup_dismissed' );
		delete_option( 'bn_demo_manifest' );
	}

	public function tear_down(): void {
		delete_option( 'buddynext_setup_dismissed' );
		delete_option( 'bn_demo_manifest' );
		parent::tear_down();
	}

	private function html( string $method ): string {
		ob_start();
		( new DemoAdmin() )->$method();
		return (string) ob_get_clean();
	}

	public function test_dismissing_setup_marks_it_done(): void {
		update_option( 'buddynext_setup_dismissed', 1 );
		$this->assertTrue( SetupChecklist::is_done() );
	}

	public function test_open_setup_offers_the_installer_and_the_built_in_demo(): void {
		$this->assertStringContainsString( 'bn-demo-installer', $this->html( 'render_installer_card' ) );
		$this->assertStringContainsString( 'Load a demo community', $this->html( 'render_promo' ) );
	}

	public function test_finished_setup_offers_no_demo(): void {
		update_option( 'buddynext_setup_dismissed', 1 );
		$this->assertSame( '', trim( $this->html( 'render_installer_card' ) ) );
		$this->assertSame( '', trim( $this->html( 'render_promo' ) ) );
	}

	public function test_a_demo_on_the_site_keeps_its_remove_card_after_setup(): void {
		update_option( 'buddynext_setup_dismissed', 1 );
		update_option( 'bn_demo_manifest', array( 'users' => array( 1 ) ) );
		$this->assertStringContainsString( 'Remove demo data', $this->html( 'render_promo' ) );
	}
}
