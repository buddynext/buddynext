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
}
