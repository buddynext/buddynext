<?php
/**
 * The upgrade path must clear a tracking-consent flag that pre-1.2.0 wrote
 * without the owner ever opting in (card 10264291915).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Guards the non-consented-tracking cleanup on upgrade.
 *
 * @covers \BuddyNext\Core\Installer::maybe_clear_unconsented_tracking
 */
class InstallerTrackingConsentTest extends WP_UnitTestCase {

	private const OPTION = 'buddynext_license_key_allow_tracking';

	/**
	 * Invoke the private cleanup with a given pre-upgrade schema version.
	 *
	 * @param int $stored_schema Schema version recorded before the upgrade.
	 * @return void
	 */
	private function run_clear( int $stored_schema ): void {
		$method = new ReflectionMethod( Installer::class, 'maybe_clear_unconsented_tracking' );
		$method->setAccessible( true );
		$method->invoke( null, $stored_schema );
	}

	/**
	 * A site upgrading from a pre-1.2.0 schema with allowed:true (never opted in)
	 * has the flag flipped to false.
	 *
	 * @return void
	 */
	public function test_pre_120_upgrade_clears_non_consented_flag(): void {
		update_option( self::OPTION, array( 'allowed' => true, 'timestamp' => 1700000000 ) );

		$this->run_clear( 40 );

		$data = get_option( self::OPTION );
		$this->assertIsArray( $data );
		$this->assertFalse( $data['allowed'], 'a pre-1.2.0 auto-written flag must be cleared' );
		$this->assertSame( 1700000000, $data['timestamp'], 'the original write time is kept as the record' );
	}

	/**
	 * A site already at the current schema (where a genuine SDK opt-in can exist)
	 * is left untouched, so a real consent is never clobbered.
	 *
	 * @return void
	 */
	public function test_current_schema_leaves_a_genuine_optin_alone(): void {
		update_option( self::OPTION, array( 'allowed' => true, 'timestamp' => 1700000000 ) );

		$this->run_clear( 55 );

		$data = get_option( self::OPTION );
		$this->assertTrue( $data['allowed'], 'a genuine opt-in on the current schema must be preserved' );
	}

	/**
	 * A flag already false, or absent, is not written.
	 *
	 * @return void
	 */
	public function test_absent_or_false_flag_is_untouched(): void {
		delete_option( self::OPTION );
		$this->run_clear( 40 );
		$this->assertFalse( get_option( self::OPTION ), 'an absent flag must not be created' );
	}
}
