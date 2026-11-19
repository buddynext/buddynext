<?php
/**
 * The Home installer card installs the Wbcom Demo Installer in place, and once it is
 * on offers up to two demos from it.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Demo\DemoAdmin;

/**
 * Card states for each install situation, and the demo tile picks.
 *
 * @covers \BuddyNext\Demo\DemoAdmin::render_installer_card
 * @covers \BuddyNext\Demo\DemoAdmin::pick_demos
 * @covers \BuddyNext\Demo\DemoAdmin::readiness
 * @covers \BuddyNext\Integrations\CompanionGithubUi
 */
class HomeDemoInstallerCardTest extends \WP_UnitTestCase {

	/**
	 * A dummy installer written to disk by a test.
	 *
	 * @var bool
	 */
	private bool $dummy = false;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		delete_option( 'buddynext_setup_dismissed' );
	}

	public function tear_down(): void {
		remove_all_filters( 'file_mod_allowed' );
		delete_option( 'buddynext_setup_dismissed' );
		wp_dequeue_script( 'bn-companion-install' );
		if ( $this->dummy ) {
			wp_delete_file( WP_PLUGIN_DIR . '/wbcom-demo-installer/wbcom-demo-installer.php' );
			rmdir( WP_PLUGIN_DIR . '/wbcom-demo-installer' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		parent::tear_down();
	}

	private function card(): string {
		ob_start();
		( new DemoAdmin() )->render_installer_card();
		return (string) ob_get_clean();
	}

	public function test_not_installed_offers_the_one_click_install_with_its_source(): void {
		if ( function_exists( 'wbcom_demo_status' ) || defined( 'WBCOM_DEMO_DIR' ) ) {
			$this->markTestSkipped( 'The real installer is loaded in this run.' );
		}
		$html = $this->card();

		$this->assertStringContainsString( 'Install and set up a demo', $html );
		$this->assertStringContainsString( 'Installs from the public GitHub release (buddynext/wbcom-demo-installer).', $html );
		$this->assertStringContainsString( 'data-bn-gh-install', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertMatchesRegularExpression( '/data-bn-gh-fallback hidden/', $html, 'The manual steps wait for a failure.' );
		$this->assertStringContainsString( 'data-bn-gh-retry', $html );
		$this->assertTrue( wp_script_is( 'bn-companion-install', 'enqueued' ) );
	}

	public function test_a_host_that_blocks_installs_shows_the_manual_steps_up_front(): void {
		if ( function_exists( 'wbcom_demo_status' ) || defined( 'WBCOM_DEMO_DIR' ) ) {
			$this->markTestSkipped( 'The real installer is loaded in this run.' );
		}
		add_filter( 'file_mod_allowed', '__return_false' );
		$html = $this->card();

		$this->assertStringNotContainsString( 'data-bn-gh-go', $html, 'No install button on a locked host.' );
		$this->assertStringContainsString( 'does not allow installing plugins', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringNotContainsString( 'data-bn-gh-fallback hidden', $html );
		$this->assertStringNotContainsString( 'data-bn-gh-retry', $html, 'Nothing to try again.' );
		$this->assertStringContainsString( 'data-bn-gh-check', $html );
		$this->assertStringContainsString( 'releases/latest', $html );
	}

	public function test_without_install_rights_it_links_to_the_release(): void {
		if ( function_exists( 'wbcom_demo_status' ) || defined( 'WBCOM_DEMO_DIR' ) ) {
			$this->markTestSkipped( 'The real installer is loaded in this run.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$html = $this->card();

		$this->assertStringContainsString( 'Get the installer', $html );
		$this->assertStringNotContainsString( 'data-bn-gh-install', $html );
	}

	public function test_installed_but_off_offers_activation(): void {
		if ( function_exists( 'wbcom_demo_status' ) || defined( 'WBCOM_DEMO_DIR' ) ) {
			$this->markTestSkipped( 'The real installer is loaded in this run.' );
		}
		$this->dummy = true;
		wp_mkdir_p( WP_PLUGIN_DIR . '/wbcom-demo-installer' );
		file_put_contents( WP_PLUGIN_DIR . '/wbcom-demo-installer/wbcom-demo-installer.php', "<?php\n/**\n * Plugin Name: QA dummy\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$html = $this->card();

		$this->assertStringContainsString( 'Activate the installer', $html );
		$this->assertStringContainsString( 'data-state="inactive"', $html );
		$this->assertStringNotContainsString( 'Installs from the public GitHub release', $html );
	}

	public function test_finished_setup_hides_the_install_card(): void {
		update_option( 'buddynext_setup_dismissed', 1 );
		$this->assertSame( '', trim( $this->card() ) );
	}

	public function test_the_recommended_tile_follows_the_active_theme(): void {
		$demos = array(
			'community'           => array( 'id' => 'community' ),
			'buddyx-buddynext'    => array( 'id' => 'buddyx-buddynext' ),
			'reign-buddynext'     => array( 'id' => 'reign-buddynext' ),
			'buddyxpro-buddynext' => array( 'id' => 'buddyxpro-buddynext' ),
			'reign-learndash'     => array( 'id' => 'reign-learndash' ),
		);

		$reign = DemoAdmin::pick_demos( $demos, 'reign-theme' );
		$this->assertSame( array( 'reign-buddynext', 'community' ), array_column( $reign, 'id' ) );
		$this->assertTrue( $reign[0]['recommended'] );
		$this->assertArrayNotHasKey( 'recommended', $reign[1] );

		$this->assertSame( 'buddyxpro-buddynext', DemoAdmin::pick_demos( $demos, 'buddyx-pro' )[0]['id'] );
		$this->assertSame( 'buddyx-buddynext', DemoAdmin::pick_demos( $demos, 'twentytwentyfive' )[0]['id'], 'A theme with no demo gets the BuddyX one.' );
	}

	public function test_tiles_fall_back_to_any_buddynext_demo(): void {
		$picks = DemoAdmin::pick_demos(
			array(
				'reign-buddynext' => array( 'id' => 'reign-buddynext' ),
				'reign-learndash' => array( 'id' => 'reign-learndash' ),
			),
			'astra'
		);
		$this->assertSame( array( 'reign-buddynext' ), array_column( $picks, 'id' ) );
		$this->assertSame( array(), DemoAdmin::pick_demos( array( 'reign-learndash' => array( 'id' => 'reign-learndash' ) ), 'reign-theme' ) );
	}

	public function test_readiness_reads_the_installer_state(): void {
		$this->assertSame( 'Everything it needs is active.', DemoAdmin::readiness( array( 'state' => 'ready' ) ) );
		$this->assertSame(
			'Sets up 2 plugins for you in one click.',
			DemoAdmin::readiness(
				array(
					'state' => 'fixable',
					'needs' => array(
						array(
							'label'  => 'Jetonomy',
							'action' => 'install',
						),
						array(
							'label'  => 'MediaVerse',
							'action' => 'install',
						),
					),
				)
			)
		);
		$this->assertStringContainsString(
			'Reign LearnDash Addon',
			DemoAdmin::readiness(
				array(
					'state' => 'blocked',
					'needs' => array(
						array(
							'label'  => 'Reign LearnDash Addon',
							'action' => 'upload',
						),
					),
				)
			)
		);
		$this->assertSame( '', DemoAdmin::readiness( array() ) );
	}

	/**
	 * The installer is active and idle: its two tiles show, with a film link only when it has one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_idle_installer_shows_two_tiles_and_see_all(): void {
		require_once __DIR__ . '/fixtures/wbcom-demo-api.php';
		$GLOBALS['bn_test_demo_status'] = 'idle';

		$html = $this->card();

		$this->assertSame( 2, substr_count( $html, 'class="bn-demo-tile"' ) );
		$this->assertStringContainsString( 'Recommended', $html );
		$this->assertStringContainsString( 'Set up this demo', $html );
		$this->assertSame( 1, substr_count( $html, 'Watch the film (3:10)' ), 'Only the demo with a film links one.' );
		$this->assertStringContainsString( 'See all demos', $html );
		$this->assertStringNotContainsString( 'data-bn-gh-install', $html );

		// Building: the progress card, no tiles.
		$GLOBALS['bn_test_demo_status'] = 'building';
		$this->assertStringNotContainsString( 'bn-demo-tile', $this->card() );

		// Setup done: nothing.
		$GLOBALS['bn_test_demo_status'] = 'idle';
		update_option( 'buddynext_setup_dismissed', 1 );
		$this->assertSame( '', trim( $this->card() ) );
	}
}
