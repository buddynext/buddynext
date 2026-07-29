<?php
/**
 * Plugin isolation must not silently break string-override plugins.
 *
 * Isolation strips non-allow-listed plugins on BuddyNext front-end routes to save
 * 20-40MB per request. A site owner who used Loco Translate to rename "Spaces" to
 * "Teams" got their override on every normal page and NOT on /spaces/, /members/,
 * /activity/ — the exact pages the terminology mattered on — because Loco was
 * stripped before it could register its `load_textdomain_mofile` hook. Nothing
 * announced this, and the only escape hatch was a developer-only PHP filter.
 *
 * Two changes are covered here: a built-in floor for string-override plugins
 * (they rewrite every string on the page, so stripping one corrupts the page's
 * text rather than removing a visible feature), and an owner-managed keep list
 * behind an admin screen so any other plugin can be kept without writing code.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PluginIsolation;

/**
 * The isolation allow-list.
 *
 * @covers \BuddyNext\Core\PluginIsolation
 */
class PluginIsolationAllowListTest extends \WP_UnitTestCase {

	/**
	 * Reset the owner list between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( PluginIsolation::OPTION_KEEP );
		delete_option( PluginIsolation::OPTION );
		parent::tear_down();
	}

	/**
	 * The reported case: Loco Translate survives isolation out of the box.
	 *
	 * @return void
	 */
	public function test_string_override_plugins_are_allowed_by_default(): void {
		$allowed = PluginIsolation::integration_plugins();

		foreach (
			array(
				'loco-translate/loco.php',
				'polylang/polylang.php',
				'sitepress-multilingual-cms/sitepress.php',
				'wpml-string-translation/plugin.php',
				'translatepress-multilingual/index.php',
			) as $basename
		) {
			$this->assertContains( $basename, $allowed, $basename . ' would still be stripped on BuddyNext routes.' );
		}
	}

	/**
	 * The in-house integration family is untouched by the change.
	 *
	 * @return void
	 */
	public function test_the_in_house_family_is_still_allowed(): void {
		$allowed = PluginIsolation::integration_plugins();

		$this->assertContains( 'wpmediaverse/wpmediaverse.php', $allowed );
		$this->assertContains( 'jetonomy/jetonomy.php', $allowed );
		$this->assertContains( 'wb-gamification/wb-gamification.php', $allowed );
	}

	/**
	 * Isolation still isolates: an ordinary plugin is NOT allowed just because the
	 * allow-list grew. This is the regression a too-broad fix would cause.
	 *
	 * @return void
	 */
	public function test_an_unrelated_plugin_is_still_stripped(): void {
		$this->assertNotContains( 'woocommerce/woocommerce.php', PluginIsolation::integration_plugins() );
	}

	/**
	 * An owner's choice from the admin screen reaches the allow-list.
	 *
	 * @return void
	 */
	public function test_the_owner_keep_list_is_merged_in(): void {
		update_option( PluginIsolation::OPTION_KEEP, array( 'some-tracker/some-tracker.php' ), false );

		$this->assertContains( 'some-tracker/some-tracker.php', PluginIsolation::integration_plugins() );
	}

	/**
	 * Garbage in the owner option cannot corrupt the allow-list — the mu-plugin
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

		$keep = PluginIsolation::owner_keep_list();

		$this->assertSame( array( 'spaced/plugin.php' ), $keep );
	}

	/**
	 * A non-array option (a corrupted or hand-edited value) degrades to empty
	 * rather than fatalling the front end before any plugin has loaded.
	 *
	 * @return void
	 */
	public function test_a_corrupt_owner_option_degrades_to_empty(): void {
		update_option( PluginIsolation::OPTION_KEEP, 'not-an-array', false );

		$this->assertSame( array(), PluginIsolation::owner_keep_list() );
	}

	/**
	 * The mirror option the mu-plugin reads carries the owner's choice, because
	 * the mu-plugin runs before the options API and reads only that JSON.
	 *
	 * @return void
	 */
	public function test_the_mirror_option_carries_the_owner_choice(): void {
		update_option( PluginIsolation::OPTION_KEEP, array( 'some-tracker/some-tracker.php' ), false );

		( new PluginIsolation() )->sync_option();

		$mirror = json_decode( (string) get_option( PluginIsolation::OPTION, '' ), true );

		$this->assertIsArray( $mirror );
		$this->assertContains( 'some-tracker/some-tracker.php', $mirror );
		$this->assertContains( 'loco-translate/loco.php', $mirror );
	}

	/**
	 * Pro (and any third party) can still extend the list through the documented
	 * filter, and receives the new floor rather than replacing it.
	 *
	 * @return void
	 */
	public function test_the_filter_still_extends_the_list(): void {
		$filter = static function ( array $plugins ): array {
			$plugins[] = 'pro-thing/pro-thing.php';

			return $plugins;
		};
		add_filter( 'buddynext_isolation_plugins', $filter );

		$allowed = PluginIsolation::integration_plugins();

		$this->assertContains( 'pro-thing/pro-thing.php', $allowed, 'The Pro extension seam broke.' );
		$this->assertContains( 'loco-translate/loco.php', $allowed, 'The filter received a list without the new floor.' );

		remove_filter( 'buddynext_isolation_plugins', $filter );
	}

	/**
	 * The generated mu-plugin carries the same floor, so a first request — before
	 * any sync has run — already keeps translation plugins alive.
	 *
	 * @return void
	 */
	public function test_the_generated_mu_plugin_carries_the_same_floor(): void {
		// Reflection rather than widening the method's visibility: the mu-plugin
		// body is an implementation detail of the installer, not an API.
		$method = new \ReflectionMethod( \BuddyNext\Core\Installer::class, 'mu_plugin_content' );
		$content = (string) $method->invoke( null );

		$this->assertStringContainsString( 'loco-translate/loco.php', $content );
		$this->assertStringContainsString( 'sitepress-multilingual-cms/sitepress.php', $content );
	}

	/**
	 * The mu-plugin's floor is DERIVED from essentials(), not typed twice.
	 *
	 * It used to be a second hand-written array inside the mu-plugin source, kept
	 * in step with this class by a comment reading "keep in sync". A comment is
	 * not a sync mechanism: the two lists are far apart in the codebase, and the
	 * only thing that would have revealed a drift is a site owner noticing an
	 * integration had gone missing on hub routes.
	 *
	 * This asserts the generated FILE, because that is what actually ships and
	 * what actually runs before plugins load. Adding an entry to the class must
	 * show up there without anyone editing the generator.
	 *
	 * @return void
	 */
	public function test_the_generated_mu_plugin_contains_every_essential(): void {
		$generate = new \ReflectionMethod( \BuddyNext\Core\Installer::class, 'mu_plugin_content' );
		$source = (string) $generate->invoke( null );

		foreach ( \BuddyNext\Core\PluginIsolation::essentials() as $basename ) {
			$this->assertStringContainsString(
				$basename,
				$source,
				$basename . ' is in the canonical floor but not in the generated mu-plugin.'
			);
		}
	}

	/**
	 * And the generated file is valid PHP. It is assembled by string
	 * substitution, so a malformed render would not fail until a site loaded it -
	 * at which point every request on that site is fatal, including wp-admin.
	 *
	 * @return void
	 */
	public function test_the_generated_mu_plugin_is_syntactically_valid(): void {
		$generate = new \ReflectionMethod( \BuddyNext\Core\Installer::class, 'mu_plugin_content' );
		$source = (string) $generate->invoke( null );

		$tmp = wp_tempnam( 'bn-mu-check' );
		file_put_contents( $tmp, $source );

		$output = array();
		$status = 0;
		exec( escapeshellcmd( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $output, $status );
		unlink( $tmp );

		$this->assertSame( 0, $status, 'Generated mu-plugin is not valid PHP: ' . implode( "\n", $output ) );
	}

	/**
	 * The floor still carries Pro's application-layer family.
	 *
	 * This looks redundant against the class constants until you know why it is
	 * there: sync_option() deliberately does NOT include these - Pro contributes
	 * them through the buddynext_isolation_plugins filter, and Free has no
	 * business hardcoding Pro's integrations at runtime. The mu-plugin floor is
	 * the exception, because it runs before any filter can fire. Someone
	 * "tidying" Pro entries out of Free would silently strip Portfolio tabs on the
	 * first request of every page load.
	 *
	 * @return void
	 */
	public function test_the_floor_includes_pro_integrations_on_purpose(): void {
		$floor = \BuddyNext\Core\PluginIsolation::essentials();

		foreach ( array( 'wp-career-board/wp-career-board.php', 'learnomy/learnomy.php', 'eventonomy/eventonomy.php' ) as $pro_plugin ) {
			$this->assertContains( $pro_plugin, $floor, 'The mu-plugin floor must survive before Pro\'s filter runs.' );
		}
	}
}
