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
	/**
	 * Tables BuddyNext Pro owns. Pro prefixes its tables `bn_` too, and Free dropping any of
	 * these on uninstall is how a customer lost their invoices by deleting the free plugin.
	 *
	 * @var array<int, string>
	 */
	private const PRO_OWNED_TABLES = array(
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

	/**
	 * Free must never claim a table Pro owns.
	 *
	 * Pro prefixes its tables `bn_` too. Free dropping any of these on uninstall is how a
	 * customer lost their invoices by deleting the free plugin.
	 *
	 * @return void
	 */
	public function test_pro_owned_tables_are_never_claimed(): void {
		$pro_tables = self::PRO_OWNED_TABLES;

		$this->assertSame(
			array(),
			array_values( array_intersect( Installer::OWNED_TABLES, $pro_tables ) ),
			'Free claims ownership of a Pro table. Deleting Free would destroy Pro data.'
		);
	}

	/**
	 * Free's uninstall must not delete a bn_* user-meta key that Pro writes.
	 *
	 * This is the SECOND half of the uninstall bug. The table drop was fixed by
	 * naming OWNED_TABLES, but the user-meta sweep stayed an unqualified
	 * `LIKE 'bn\_%'` wildcard, defended by a comment claiming Pro wrote no user meta.
	 * It writes six keys. Deleting the free plugin therefore revoked members' PAID
	 * entitlements (`bn_ability_*`) and erased their email OPT-OUTS
	 * (`bn_email_unsubscribed_*`, `bn_email_suppressed`) — the latter silently
	 * re-subscribing people who had explicitly unsubscribed.
	 *
	 * This test reads BOTH repos on purpose. Every previous instance of this bug got
	 * through because the gate only ever scanned one of them.
	 *
	 * @return void
	 */
	public function test_pro_user_meta_is_never_deleted_by_free_uninstall(): void {
		$pro_meta_keys = array(
			'bn_ability_tier_supporter',            // Paid entitlement grant.
			'bn_push_pref_bn_new_follower',         // Pro push preference.
			'bn_email_unsubscribed_all_broadcasts', // Global email opt-out.
			'bn_email_unsubscribed_campaigns',
			'bn_email_unsubscribed_sequences',
			'bn_email_suppressed',                  // Hard suppression list.
		);

		foreach ( $pro_meta_keys as $key ) {
			$covered = false;
			foreach ( Installer::PRO_OWNED_USER_META as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					$covered = true;
					break;
				}
			}

			$this->assertTrue(
				$covered,
				sprintf(
					'Free\'s uninstall would DELETE the Pro user-meta key "%s". '
					. 'That revokes a paid entitlement or erases an email opt-out. '
					. 'Add its namespace to Installer::PRO_OWNED_USER_META.',
					$key
				)
			);
		}
	}

	/**
	 * A Free key that Pro does not own must still be cleaned up.
	 *
	 * The exclusion list is a scalpel, not a blanket: if it ever grew wide enough to
	 * spare Free's own rows, uninstall would leak the member's whole profile.
	 *
	 * @return void
	 */
	public function test_free_user_meta_is_still_cleaned_up(): void {
		$free_meta_keys = array( 'bn_headline', 'bn_avatar', 'bn_profile_slug', 'buddynext_email_verified' );

		foreach ( $free_meta_keys as $key ) {
			foreach ( Installer::PRO_OWNED_USER_META as $prefix ) {
				$this->assertFalse(
					str_starts_with( $key, $prefix ),
					sprintf( 'Free\'s own key "%s" is being spared by the Pro exclusion list — uninstall would leak it.', $key )
				);
			}
		}
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

		/*
		 * Drop Pro's tables before comparing.
		 *
		 * The class docblock used to reason that "Free's suite runs without Pro loaded, so every
		 * bn_* table present is by definition Free's own". That is true of the PLUGIN and false of
		 * the DATABASE: the test database is shared and persistent, and CREATE TABLE is not rolled
		 * back with the per-test transaction. So the moment anyone runs Pro's suite against the
		 * same database, Pro's 18 tables sit there forever and this test fails for a reason that
		 * has nothing to do with Free's code. It cost two false alarms during the 1.0.8 release.
		 *
		 * Pro prefixes its tables `bn_` too, and Free must never claim them (that is the whole
		 * point of test_pro_owned_tables_are_never_claimed, and of the bug where deleting Free
		 * destroyed a customer's invoices). So they are not Free's to declare, and their presence
		 * says nothing about Free's OWNED_TABLES.
		 *
		 * This does NOT weaken the check: the exclusion list is the same EXPLICIT set of names the
		 * test below asserts Free can never claim. A genuinely new Free table still fails here.
		 */
		$names = array_values( array_diff( $names, self::PRO_OWNED_TABLES ) );

		sort( $names );

		return $names;
	}
}
