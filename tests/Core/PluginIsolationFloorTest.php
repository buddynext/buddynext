<?php
/**
 * The isolation FLOOR and the owner deny-list that drive plugin isolation.
 *
 * Isolation keeps every active plugin by DEFAULT and strips only what the owner
 * explicitly ticks (owner_strip_list). This asserts the parts that decide what is
 * kept: the never-strip essentials() floor, the owner keep/strip options and
 * their sanitisation, the mirror option the mu-plugin reads, and that the
 * generated mu-plugin bakes the same floor + the dependency fail-safe.
 *
 * (Was PluginIsolationAllowListTest, from the pre-1.2.1 allow-list model. The
 * allow-list assembly integration_plugins() and the tests that exercised it were
 * removed with the dead code in card 10317873598; these are the tests that cover
 * the live deny-list path.)
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PluginIsolation;

/**
 * @covers \BuddyNext\Core\PluginIsolation
 */
class PluginIsolationFloorTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( PluginIsolation::OPTION_ENABLED, '1', false );
	}

	public function tear_down(): void {
		delete_option( PluginIsolation::OPTION_KEEP );
		delete_option( PluginIsolation::OPTION_STRIP );
		delete_option( PluginIsolation::OPTION );
		delete_option( PluginIsolation::OPTION_ENABLED );
		parent::tear_down();
	}

	/**
	 * Garbage in the owner option cannot corrupt the list — the mu-plugin
	 * intersects against it, so a stray value is harmless, but a non-string entry
	 * must not reach json_encode as an object.
	 *
	 * @return void
	 */
	public function test_the_owner_keep_list_is_sanitised(): void {
		update_option(
			PluginIsolation::OPTION_KEEP,
			array( '  spaced/plugin.php  ', '', 42, array( 'nested' ), 'spaced/plugin.php' ),
			false
		);

		$this->assertSame( array( 'spaced/plugin.php' ), PluginIsolation::owner_keep_list() );
	}

	/**
	 * A non-array option (corrupted or hand-edited) degrades to empty rather than
	 * fatalling the front end before any plugin has loaded.
	 *
	 * @return void
	 */
	public function test_a_corrupt_owner_option_degrades_to_empty(): void {
		update_option( PluginIsolation::OPTION_KEEP, 'not-an-array', false );

		$this->assertSame( array(), PluginIsolation::owner_keep_list() );
	}

	/**
	 * The mirror option the mu-plugin reads carries the owner's STRIP choice, while
	 * the in-house family is dropped by the safety floor.
	 *
	 * @return void
	 */
	public function test_the_mirror_option_carries_the_owner_choice(): void {
		update_option(
			PluginIsolation::OPTION_STRIP,
			array( 'heavy-backoffice/heavy-backoffice.php', 'buddynext/buddynext.php' ),
			false
		);

		( new PluginIsolation() )->sync_option();

		$mirror = json_decode( (string) get_option( PluginIsolation::OPTION, '' ), true );

		$this->assertIsArray( $mirror );
		$this->assertContains( 'heavy-backoffice/heavy-backoffice.php', $mirror, 'The owner strip choice must reach the mu-plugin.' );
		$this->assertNotContains( 'buddynext/buddynext.php', $mirror, 'The in-house family must never be in the strip mirror.' );
	}

	/**
	 * The generated mu-plugin carries the in-house family as its hard safety floor
	 * and keeps everything else by default (array_diff, not array_intersect).
	 *
	 * @return void
	 */
	public function test_the_generated_mu_plugin_carries_the_same_floor(): void {
		$content = $this->mu_plugin_source();

		$this->assertStringContainsString( 'buddynext/buddynext.php', $content );
		$this->assertStringContainsString( 'wpmediaverse/wpmediaverse.php', $content );
		$this->assertStringContainsString( 'array_diff( $plugins, $strip )', $content );
	}

	/**
	 * The generated mu-plugin never strips a plugin a KEPT plugin declares as
	 * required (the Requires-Plugins fail-safe), and runs it BEFORE the strip diff.
	 *
	 * @return void
	 */
	public function test_the_generated_mu_plugin_never_strips_a_kept_dependency(): void {
		$content = $this->mu_plugin_source();

		$this->assertStringContainsString( 'Requires Plugins', $content );
		$this->assertStringContainsString( 'get_file_data', $content );
		$this->assertStringContainsString( '$required_slugs', $content );
		$this->assertMatchesRegularExpression(
			'/required_slugs.*array_diff\(\s*\$plugins,\s*\$strip\s*\)/s',
			$content,
			'The dependency fail-safe must run BEFORE the final strip diff, or a stripped parent still fatals its kept dependant.'
		);
	}

	/**
	 * The dependency scan skips the in-house family floor: it only reads the
	 * Requires-Plugins header of the THIRD-PARTY kept plugins, because a family
	 * plugin is always kept and only requires other family plugins - so scanning
	 * the whole active set on every hub route is what the fix removes, and it does
	 * so without a cross-request cache or a front-end write (card 10317874510).
	 *
	 * @return void
	 */
	public function test_the_dependency_scan_skips_the_family_floor(): void {
		$content = $this->mu_plugin_source();

		// The scan set is kept MINUS the essentials floor.
		$this->assertStringContainsString( 'array_diff( array_diff( $plugins, $strip ), $essentials )', $content );
		// And it does NOT reach for a persistent option cache / front-end write.
		$this->assertStringNotContainsString( 'buddynext_isolation_required_slugs', $content );
	}

	/**
	 * The mu-plugin's floor is DERIVED from essentials(), not typed twice — every
	 * basename in the canonical floor appears in the generated file.
	 *
	 * @return void
	 */
	public function test_the_generated_mu_plugin_contains_every_essential(): void {
		$source = $this->mu_plugin_source();

		foreach ( PluginIsolation::essentials() as $basename ) {
			$this->assertStringContainsString(
				$basename,
				$source,
				$basename . ' is in the canonical floor but not in the generated mu-plugin.'
			);
		}
	}

	/**
	 * The generated file is valid PHP. It is assembled by string substitution, so a
	 * malformed render would fatal every request on a site that loaded it.
	 *
	 * @return void
	 */
	public function test_the_generated_mu_plugin_is_syntactically_valid(): void {
		$source = $this->mu_plugin_source();

		$tmp = wp_tempnam( 'bn-mu-check' );
		file_put_contents( $tmp, $source );

		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $output, $status );
		unlink( $tmp );

		$this->assertSame( 0, $status, 'Generated mu-plugin is not valid PHP: ' . implode( "\n", $output ) );
	}

	/**
	 * The floor still carries Pro's application-layer family, which sync_option()
	 * deliberately omits (Pro contributes it via the filter). The mu-plugin floor
	 * is the exception because it runs before any filter can fire.
	 *
	 * @return void
	 */
	public function test_the_floor_includes_pro_integrations_on_purpose(): void {
		$floor = PluginIsolation::essentials();

		foreach ( array( 'wp-career-board/wp-career-board.php', 'learnomy/learnomy.php', 'eventonomy/eventonomy.php' ) as $pro_plugin ) {
			$this->assertContains( $pro_plugin, $floor, 'The mu-plugin floor must survive before Pro\'s filter runs.' );
		}
	}

	/**
	 * The generated mu-plugin source (reflection, not a widened method visibility).
	 *
	 * @return string
	 */
	private function mu_plugin_source(): string {
		$method = new \ReflectionMethod( Installer::class, 'mu_plugin_content' );
		$method->setAccessible( true );

		return (string) $method->invoke( null );
	}
}
