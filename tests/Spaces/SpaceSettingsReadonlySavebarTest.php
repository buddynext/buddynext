<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A read-only settings panel offers no Save.
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use WP_UnitTestCase;

/**
 * A space moderator on the Integrations tab got the sticky "Save changes" bar,
 * though the panel is owner-only and renders read-only for them: Save did
 * nothing but post an error. The form now says it is read-only and the store
 * shows the bar only when some form on the page can be saved.
 *
 * @coversNothing
 */
class SpaceSettingsReadonlySavebarTest extends WP_UnitTestCase {

	/**
	 * Read a plugin file.
	 *
	 * @param string $path Path under the plugin root.
	 * @return string
	 */
	private function source( string $path ): string {
		return (string) file_get_contents( BUDDYNEXT_DIR . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * The template marks the moderator's Integrations form read-only.
	 *
	 * @return void
	 */
	public function test_moderator_integrations_form_is_marked_read_only(): void {
		$tpl = $this->source( 'templates/spaces/settings.php' );

		$this->assertStringContainsString( "\$bn_form_readonly = 'integrations' === \$settings_tab && ! \$bn_is_space_owner;", $tpl );
		$this->assertStringContainsString( "data-bn-readonly", $tpl );
	}

	/**
	 * The save bar shows only when a form on the page is editable.
	 *
	 * @return void
	 */
	public function test_save_bar_needs_an_editable_form(): void {
		$js = $this->source( 'assets/js/spaces/store.js' );

		$this->assertStringContainsString( "storeInstance.state.savebarHasForm = editable;", $js );
		$this->assertStringNotContainsString( 'storeInstance.state.savebarHasForm = true;', $js );
	}
}
