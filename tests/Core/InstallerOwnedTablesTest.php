<?php
/**
 * The uninstall table list must match the tables the installer actually creates.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Guards Installer::OWNED_TABLES against drift in both directions.
 *
 * The uninstall routine drops exactly OWNED_TABLES. A table created by install_schema()
 * but missing from the list survives an uninstall forever (a leak); a name in the
 * list that install_schema() never creates is dead weight. Free's test suite runs
 * without Pro loaded, so every bn_* table present after Installer::run() is by
 * definition Free's own — which makes the comparison exact.
 */
class InstallerOwnedTablesTest extends WP_UnitTestCase {

	/**
	 * Every bn_* table in the database is declared in OWNED_TABLES.
	 *
	 * This is the leak direction: a new table nobody added to the list.
	 *
	 * @return void
	 */
	public function test_every_created_table_is_declared_as_owned(): void {
		$declared = array_merge( Installer::OWNED_TABLES, Installer::LEGACY_TABLES );

		$this->assertSame(
			array(),
			array_diff( $this->live_tables(), $declared ),
			'A bn_* table exists that Installer::OWNED_TABLES does not name, so uninstall.php will leave it behind. Add it to the list.'
		);
	}

	/**
	 * A retired table is dropped on uninstall, not stranded.
	 *
	 * Removing a table from install_schema() stops NEW installs from getting it; it
	 * does nothing for the installs that already have it. bn_feed_items was retired
	 * that way in 85b20825 and still sits on every older site. The old wildcard
	 * uninstall swept it up by accident — an explicit list must name it on purpose.
	 *
	 * @return void
	 */
	public function test_retired_tables_are_still_owned_for_cleanup(): void {
		$this->assertContains(
			'bn_feed_items',
			Installer::LEGACY_TABLES,
			'A table Free created in an earlier version must stay on the legacy list, or uninstall strands it forever.'
		);

		$this->assertSame(
			array(),
			array_intersect( Installer::OWNED_TABLES, Installer::LEGACY_TABLES ),
			'A table cannot be both current and retired.'
		);
	}

	/**
	 * Every name in OWNED_TABLES is a table that really exists.
	 *
	 * This is the stale direction: a renamed or dropped table still on the list.
	 *
	 * @return void
	 */
	public function test_every_owned_table_really_exists(): void {
		$this->assertSame(
			array(),
			array_diff( Installer::OWNED_TABLES, $this->live_tables() ),
			'Installer::OWNED_TABLES names a table that install_schema() does not create.'
		);
	}

	/**
	 * OWNED_TABLES must not claim a table that belongs to BuddyNext Pro.
	 *
	 * Pro prefixes its tables `bn_` too. Free dropping any of these on uninstall is
	 * how a customer lost their invoices by deleting the free plugin. This asserts
	 * the specific financial tables can never re-enter Free's list.
	 *
	 * @return void
	 */
	public function test_pro_owned_tables_are_never_claimed(): void {
		$pro_tables = array(
			'bn_invoices',
			'bn_subscriptions',
			'bn_membership_tiers',
			'bn_coupons',
			'bn_tax_rules',
			'bn_plan_gateway_map',
			'bn_analytics_events',
			'bn_email_campaigns',
			'bn_campaign_recipients',
			'bn_drip_sequences',
			'bn_drip_enrollments',
			'bn_saved_searches',
			'bn_member_labels',
			'bn_member_label_assignments',
			'bn_mod_rules',
			'bn_push_tokens',
			'bn_ai_signals',
			'bn_ai_embeddings',
		);

		$this->assertSame(
			array(),
			array_values( array_intersect( Installer::OWNED_TABLES, $pro_tables ) ),
			'Free claims ownership of a Pro table. Deleting Free would destroy Pro data.'
		);
	}

	/**
	 * Read the bn_* tables that actually exist for this site's prefix.
	 *
	 * @return string[] Unprefixed table names, sorted.
	 */
	private function live_tables(): array {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix . 'bn_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		$names = array_map(
			static fn( $table ) => substr( (string) $table, strlen( $wpdb->prefix ) ),
			(array) $found
		);

		sort( $names );

		return $names;
	}
}
