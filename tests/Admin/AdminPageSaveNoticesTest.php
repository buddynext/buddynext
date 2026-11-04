<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A page that saves through options.php prints the save result.
 *
 * Core prints Settings API notices only on its own Settings screens, so a
 * refused Realtime host (no http://) was dropped with no message at all.
 *
 * @package BuddyNext\Tests\Admin
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminPageBase;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Admin\AdminPageBase::render_save_notices
 */
class AdminPageSaveNoticesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		unset( $_GET['settings-updated'] );
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset between tests.
		parent::tear_down();
	}

	private function page(): AdminPageBase {
		return new class() extends AdminPageBase {
			protected function get_title(): string {
				return 'QA page';
			}
			protected function get_subtitle(): string {
				return '';
			}
			protected function render_content(): void {
				echo '<form method="post" action="options.php"></form>';
			}
		};
	}

	private function render(): string {
		ob_start();
		$this->page()->render_page();
		return (string) ob_get_clean();
	}

	public function test_a_refused_field_is_shown_after_saving(): void {
		$_GET['settings-updated'] = 'true';
		add_settings_error( 'buddynextpro_soketi_host', 'scheme', 'Realtime host not saved: QA refusal.' );

		$this->assertStringContainsString( 'Realtime host not saved: QA refusal.', $this->render() );
	}

	public function test_nothing_is_printed_without_a_save(): void {
		add_settings_error( 'buddynextpro_soketi_host', 'scheme', 'Realtime host not saved: QA refusal.' );

		$this->assertStringNotContainsString( 'QA refusal', $this->render() );
	}
}
