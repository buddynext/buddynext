<?php
/**
 * What Integration Settings offers for a companion follows its real state.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Integrations\CompanionRegistry;
use WP_UnitTestCase;

/**
 * One row per lifecycle state: connected, installed, missing, Pro-bridged.
 */
class CompanionNextStepTest extends WP_UnitTestCase {

	/**
	 * An admin, since Activate needs activate_plugins.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter(
			'buddynext_companions',
			static function ( $all ) {
				$base = static fn( bool $on, string $file, bool $pro = false ) => array(
					'label'     => 'QA',
					'detect'    => static fn(): bool => $on,
					'free'      => array( 'basename' => $file ),
					'needs_pro' => $pro,
				);

				$all['qa-active']   = $base( true, 'qa-none/qa.php' );
				$all['qa-inactive'] = $base( false, 'qa-next-step/qa.php' );
				$all['qa-missing']  = $base( false, 'qa-none/qa.php' );
				$all['qa-pro-off']  = $base( false, 'qa-next-step/qa.php', true );
				$all['qa-pro-on']   = $base( true, 'qa-next-step/qa.php', true );
				return $all;
			}
		);
		wp_mkdir_p( WP_PLUGIN_DIR . '/qa-next-step' );
		file_put_contents( WP_PLUGIN_DIR . '/qa-next-step/qa.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove the fake plugin.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_delete_file( WP_PLUGIN_DIR . '/qa-next-step/qa.php' );
		rmdir( WP_PLUGIN_DIR . '/qa-next-step' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down();
	}

	public function test_connected_has_no_next_step(): void {
		$this->assertNull( CompanionRegistry::next_step( 'qa-active' ) );
		$this->assertNull( CompanionRegistry::next_step( 'qa-unknown' ) );
	}

	public function test_installed_but_inactive_links_to_activation(): void {
		$step = CompanionRegistry::next_step( 'qa-inactive' );
		$this->assertSame( 'activate', $step['state'] );
		$this->assertStringContainsString( 'plugins.php?action=activate', $step['url'] );
		$this->assertStringContainsString( '_wpnonce=', $step['url'] );
		$this->assertStringContainsString( rawurlencode( 'qa-next-step/qa.php' ), $step['url'] );
	}

	public function test_installed_without_the_capability_gets_no_activation_link(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$step = CompanionRegistry::next_step( 'qa-inactive' );
		$this->assertSame( 'inactive', $step['state'] );
		$this->assertStringNotContainsString( 'action=activate', $step['url'] );
	}

	public function test_not_installed_deep_links_to_its_card(): void {
		$step = CompanionRegistry::next_step( 'qa-missing' );
		$this->assertSame( 'install', $step['state'] );
		$this->assertStringEndsWith( '#bn-companion-qa-missing', $step['url'] );
	}

	/**
	 * Free's suite runs without Pro, so both Pro-bridged rows say Pro whatever their state.
	 */
	public function test_pro_bridged_partner_needs_pro_first(): void {
		$this->assertFalse( defined( 'BUDDYNEXTPRO_VERSION' ) );
		$this->assertSame( 'needs_pro', CompanionRegistry::next_step( 'qa-pro-off' )['state'] );
		$this->assertSame( 'needs_pro', CompanionRegistry::next_step( 'qa-pro-on' )['state'], 'active without Pro connects nothing' );
	}
}
