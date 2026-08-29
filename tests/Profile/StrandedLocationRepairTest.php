<?php
/**
 * The v46 repair for location values stranded on text-like fields.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * A location field downgraded to text used to keep its {address,lat,lng} JSON,
 * which the hero, About panel and directory card then printed verbatim under the
 * member's name. The REST type-change path now converts, so no NEW value can be
 * stranded - but nothing healed the ones already written, and they render JSON
 * until something rewrites them.
 */
class StrandedLocationRepairTest extends WP_UnitTestCase {

	/**
	 * Insert a field and one value for it.
	 *
	 * @param string $type  Field type.
	 * @param string $value Stored value.
	 * @return array{0:int,1:int} Field id and user id.
	 */
	private function seed( string $type, string $value ): array {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'   => 1,
				'field_key'  => 'loc_' . wp_generate_password( 8, false ),
				'label'      => 'Location',
				'type'       => $type,
				'visibility' => 'public',
			)
		);
		$field_id = (int) $wpdb->insert_id;
		$user_id  = (int) self::factory()->user->create();

		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_values',
			array(
				'user_id'     => $user_id,
				'field_id'    => $field_id,
				// Part of the (user_id, field_id, entry_index) uniqueness. Omitting it
				// lets every fixture row take the same default and collide with
				// whatever an earlier test left behind - FixtureUniqueKeyTest exists to
				// catch exactly that, and caught this.
				'entry_index' => 0,
				'value'       => $value,
			)
		);

		return array( $field_id, $user_id );
	}

	/**
	 * Read one stored value back.
	 *
	 * @param int $field_id Field.
	 * @param int $user_id  Member.
	 * @return string
	 */
	private function stored( int $field_id, int $user_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE field_id = %d AND user_id = %d AND entry_index = 0",
				$field_id,
				$user_id
			)
		);
	}

	/**
	 * Run the repair.
	 *
	 * Calls the migration directly rather than driving `Installer::maybe_upgrade()`.
	 * The full installer issues DDL (dbDelta's CREATE/ALTER), and DDL forces an
	 * implicit COMMIT in MySQL — which ends the transaction WP_UnitTestCase wraps
	 * each test in, so everything this test and its predecessors wrote is committed
	 * for real instead of rolled back. Driving the installer here leaked seeded
	 * posts into the shared database and failed `GetManyTest::test_an_empty_list_is_a_no_op`
	 * under one run order while passing under others, which reads as a flake in an
	 * unrelated suite.
	 *
	 * The wiring — that `maybe_upgrade()` calls this at v46 — is asserted by the
	 * schema-version constant and the call site, not by re-running the installer
	 * 5 times here.
	 *
	 * @return void
	 */
	private function upgrade(): void {
		$run = new \ReflectionMethod( Installer::class, 'maybe_repair_stranded_location_values' );
		$run->setAccessible( true );
		$run->invoke( null );
	}

	/**
	 * A text field holding a location envelope is unwrapped to its address.
	 *
	 * @return void
	 */
	public function test_a_stranded_location_value_is_unwrapped_to_its_address(): void {
		list( $field_id, $user_id ) = $this->seed( 'text', '{"lat": null, "lng": null, "address": "Kyoto, JP"}' );

		$this->upgrade();

		$this->assertSame( 'Kyoto, JP', $this->stored( $field_id, $user_id ) );
	}

	/**
	 * A real location field is left alone — the JSON is its storage format, not a
	 * defect. Repairing it would destroy the coordinates.
	 *
	 * @return void
	 */
	public function test_a_location_field_keeps_its_json(): void {
		$json                       = '{"lat": 35.01, "lng": 135.76, "address": "Kyoto, JP"}';
		list( $field_id, $user_id ) = $this->seed( 'location', $json );

		$this->upgrade();

		$this->assertSame( $json, $this->stored( $field_id, $user_id ) );
	}

	/**
	 * A text value that merely starts with a brace is not JSON and is not touched.
	 * The repair keys on a well-formed object carrying an address, so ordinary
	 * member text cannot be eaten by it.
	 *
	 * @return void
	 */
	public function test_ordinary_text_starting_with_a_brace_is_untouched(): void {
		list( $field_id, $user_id ) = $this->seed( 'text', '{not json at all' );

		$this->upgrade();

		$this->assertSame( '{not json at all', $this->stored( $field_id, $user_id ) );
	}

	/**
	 * A well-formed JSON object with no `address` key is not a stranded location
	 * value and stays as it is.
	 *
	 * @return void
	 */
	public function test_json_without_an_address_is_untouched(): void {
		list( $field_id, $user_id ) = $this->seed( 'text', '{"note": "hello"}' );

		$this->upgrade();

		$this->assertSame( '{"note": "hello"}', $this->stored( $field_id, $user_id ) );
	}

	/**
	 * Running twice changes nothing the second time.
	 *
	 * @return void
	 */
	public function test_the_repair_is_idempotent(): void {
		list( $field_id, $user_id ) = $this->seed( 'text', '{"lat": null, "lng": null, "address": "Dakar, SN"}' );

		$this->upgrade();
		$this->upgrade();

		$this->assertSame( 'Dakar, SN', $this->stored( $field_id, $user_id ) );
	}
}
