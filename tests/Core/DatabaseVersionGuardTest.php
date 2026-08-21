<?php
/**
 * BuddyNext must refuse a database that cannot hold its schema.
 *
 * A customer on MariaDB 5.5.68 activated 1.1.5 successfully, got eight of forty-one
 * tables, and posted content that returned HTTP 201 and wrote nothing. There was no
 * minimum-version declaration anywhere and no activation guard, so nothing between
 * the plugin and a server it cannot run on.
 *
 * The binding constraint is the JSON column type - six columns use it - which MySQL
 * added in 5.7.8 and MariaDB in 10.2.7. Below those the CREATE TABLE is a syntax
 * error, not a degraded feature.
 *
 * These tests cover the DETECTION, which is the part written here. The vendors'
 * version history is not re-proven, and an old MariaDB container is not stood up:
 * 10.1 under x86 emulation crashes on Apple Silicon, and proving that MariaDB 10.1
 * lacks JSON would be testing MariaDB rather than this guard.
 *
 * The trap being guarded is subtler than the comparison. MariaDB reports itself as
 * '5.5.5-10.x.y' to some PHP versions, so a naive version_compare reads EVERY
 * MariaDB as 5.5.5 and refuses servers that are perfectly fine - turning a guard
 * against data loss into an outage. That correction is WordPress core's own, from
 * wpdb::has_cap(), and the case below pins it.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * The minimum-database-version guard.
 *
 * @covers \BuddyNext\Core\Installer::unsupported_db_reason
 */
class DatabaseVersionGuardTest extends WP_UnitTestCase {

	/**
	 * The real $wpdb, restored after each test.
	 *
	 * @var \wpdb|null
	 */
	private $real_wpdb = null;

	/**
	 * Keep the real connection safe.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->real_wpdb = $wpdb;
	}

	/**
	 * Put it back, whatever the test did.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb = $this->real_wpdb;

		parent::tear_down();
	}

	/**
	 * Ask the guard about a server reporting these strings.
	 *
	 * @param string $version Value of db_version().
	 * @param string $info    Value of db_server_info().
	 * @return string
	 */
	private function reason_for( string $version, string $info ): string {
		global $wpdb;

		$wpdb = new class( $version, $info ) extends \wpdb {
			/** @var string */
			private string $v;
			/** @var string */
			private string $i;

			/**
			 * @param string $v Version.
			 * @param string $i Server info.
			 */
			public function __construct( string $v, string $i ) {
				$this->v = $v;
				$this->i = $i;
			}

			/**
			 * @return string
			 */
			public function db_version() {
				return $this->v;
			}

			/**
			 * @return string
			 */
			public function db_server_info() {
				return $this->i;
			}
		};

		return Installer::unsupported_db_reason();
	}

	/**
	 * A modern MySQL is accepted.
	 *
	 * @return void
	 */
	public function test_a_modern_mysql_is_accepted(): void {
		$this->assertSame( '', $this->reason_for( '8.0.36', '8.0.36' ) );
	}

	/**
	 * A modern MariaDB is accepted.
	 *
	 * @return void
	 */
	public function test_a_modern_mariadb_is_accepted(): void {
		$this->assertSame( '', $this->reason_for( '10.11.6', '10.11.6-MariaDB-1:10.11.6+maria~ubu2204' ) );
	}

	/**
	 * The exact server from the support ticket is refused.
	 *
	 * @return void
	 */
	public function test_the_customers_mariadb_is_refused(): void {
		$reason = $this->reason_for( '5.5.68', '5.5.68-MariaDB' );

		$this->assertNotSame( '', $reason, 'The server that lost a customer their data was accepted.' );
		$this->assertStringContainsString( 'MariaDB', $reason, 'The message must name the server it detected.' );
		$this->assertStringContainsString( '10.2.7', $reason, 'The message must name the version required.' );
		$this->assertStringContainsString( '5.5.68', $reason, 'The message must name the version found.' );
	}

	/**
	 * MySQL 5.6 is refused: it predates the JSON column type.
	 *
	 * @return void
	 */
	public function test_mysql_before_json_is_refused(): void {
		$this->assertNotSame( '', $this->reason_for( '5.6.51', '5.6.51' ) );
	}

	/**
	 * MySQL 5.7.8 exactly - the first version with JSON - is accepted.
	 *
	 * The boundary, asserted on purpose: an off-by-one here refuses a supported
	 * server, and >= versus > is exactly the sort of thing that gets flipped.
	 *
	 * @return void
	 */
	public function test_the_first_supported_mysql_is_accepted(): void {
		$this->assertSame( '', $this->reason_for( '5.7.8', '5.7.8' ) );
	}

	/**
	 * MariaDB 10.2.6 is refused and 10.2.7 accepted - JSON arrived between them.
	 *
	 * @return void
	 */
	public function test_the_mariadb_boundary_is_exact(): void {
		$this->assertNotSame( '', $this->reason_for( '10.2.6', '10.2.6-MariaDB' ) );
		$this->assertSame( '', $this->reason_for( '10.2.7', '10.2.7-MariaDB' ) );
	}

	/**
	 * THE trap: a healthy MariaDB masquerading as 5.5.5 must NOT be refused.
	 *
	 * MariaDB reports '5.5.5-10.x.y' to certain PHP builds. A guard comparing that
	 * naively refuses EVERY MariaDB on those builds - taking a protection against
	 * silent data loss and turning it into an outage on servers that were fine.
	 *
	 * Asserted against `normalise_db_version()` with the PHP version passed IN,
	 * rather than against the live constant. The first version of this test read
	 * PHP_VERSION_ID and skipped itself on any build outside the affected ranges -
	 * including the development machine - so the single most dangerous mutation
	 * available here went uncaught. Deleting the correction entirely left the suite
	 * green. That is why the production code takes the version as a parameter.
	 *
	 * @return void
	 */
	public function test_a_healthy_mariadb_reporting_5_5_5_is_corrected_not_refused(): void {
		// PHP 8.0.15 - inside the affected range, so the correction must apply.
		list( $version, $info ) = Installer::normalise_db_version( '5.5.5', '5.5.5-10.6.12-MariaDB', 80015 );

		$this->assertSame( '10.6.12', $version, 'A healthy MariaDB 10.6 would have been read as 5.5.5 and refused.' );
		$this->assertStringContainsString( 'MariaDB', $info );
	}

	/**
	 * On a build that never sees the prefix, nothing is rewritten.
	 *
	 * The other half. A correction that fired unconditionally would mangle a real
	 * MySQL 5.5.5 into something else.
	 *
	 * @return void
	 */
	public function test_the_correction_does_not_fire_on_unaffected_php(): void {
		// PHP 8.2 - outside the affected ranges.
		list( $version, $info ) = Installer::normalise_db_version( '5.5.5', '5.5.5-10.6.12-MariaDB', 80229 );

		$this->assertSame( '5.5.5', $version, 'The prefix was stripped on a PHP build that never receives it.' );
		$this->assertSame( '5.5.5-10.6.12-MariaDB', $info );
	}

	/**
	 * And a genuine old MySQL 5.5.5 is still refused, prefix logic or not.
	 *
	 * @return void
	 */
	public function test_a_real_mysql_5_5_is_still_refused(): void {
		$this->assertNotSame( '', $this->reason_for( '5.5.5', '5.5.5' ) );
	}

	/**
	 * An unreadable version is accepted rather than guessed at.
	 *
	 * Failing open here is deliberate. The cost of a wrong refusal is a site that
	 * will not run at all; the cost of a wrong acceptance is the pre-existing
	 * behaviour, which the rest of this release now detects and reports.
	 *
	 * @return void
	 */
	public function test_an_unreadable_version_does_not_block_activation(): void {
		$this->assertSame( '', $this->reason_for( '', '' ) );
	}
}
