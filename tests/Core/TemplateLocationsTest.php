<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the buddynext_template_locations filter in TemplateLoader.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\TemplateLoader;
use WP_UnitTestCase;

/**
 * Verifies that addon plugins can register template directories via the
 * buddynext_template_locations filter, and that theme + Free-default resolution
 * is unaffected.
 *
 * @covers \BuddyNext\Core\TemplateLoader::locate
 */
class TemplateLocationsTest extends WP_UnitTestCase {

	/**
	 * Addon-supplied template is returned when Free ships no matching template.
	 *
	 * Creates a real temporary file so locate()'s file_exists() check hits
	 * disk. Raw filesystem calls are used intentionally: WP_Filesystem is a
	 * production abstraction, not a test-scaffolding helper, and the WP test
	 * suite itself uses direct FS calls for the same purpose.
	 */
	public function test_addon_dir_is_searched_for_templates_free_does_not_ship(): void {
		$tmp = sys_get_temp_dir() . '/bn-addon-tpl-' . getmypid();

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- silencing expected failures in test teardown paths is intentional.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WP_Filesystem is a production helper; raw FS calls are standard in test scaffolding.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- same rationale as mkdir above.
		@mkdir( $tmp . '/membership', 0777, true );
		file_put_contents( $tmp . '/membership/pricing.php', '<!--addon-pricing-->' );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		add_filter(
			'buddynext_template_locations',
			static function ( array $dirs ) use ( $tmp ) {
				$dirs[] = trailingslashit( $tmp );
				return $dirs;
			}
		);

		$loader = new TemplateLoader();
		$this->assertSame( $tmp . '/membership/pricing.php', $loader->locate( 'membership/pricing.php' ) );

		// Teardown.
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test teardown; not production code.
		@unlink( $tmp . '/membership/pricing.php' );
		@rmdir( $tmp . '/membership' );
		@rmdir( $tmp );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Free's own templates/feed/home.php is still found via the default (step 3).
	 */
	public function test_core_template_still_resolves_from_free_default(): void {
		$loader = new TemplateLoader();
		$this->assertStringEndsWith( 'templates/feed/home.php', (string) $loader->locate( 'feed/home.php' ) );
	}

	/**
	 * A completely unknown template returns null even after applying the filter.
	 */
	public function test_unknown_template_returns_null(): void {
		$loader = new TemplateLoader();
		$this->assertNull( $loader->locate( 'does/not/exist.php' ) );
	}
}
