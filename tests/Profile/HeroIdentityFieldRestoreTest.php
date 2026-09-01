<?php
/**
 * A hero identity field that went missing before v18 is restored on upgrade.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * converge_profile_schema() is UPDATE-only by design, so it re-flags a row that
 * exists and inserts nothing. That left a site which deleted pronouns BEFORE the
 * is_system guard arrived with a template hardcoding a key that no longer exists:
 * a permanently blank hero slot, and no screen saying why.
 *
 * The restore pass is narrow on purpose - only the keys a template hardcodes, and
 * only because the owner is no longer permitted to delete them. Every other
 * deleted field must stay deleted.
 *
 * @covers \BuddyNext\Core\Installer::restore_hero_identity_fields
 */
class HeroIdentityFieldRestoreTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * @param string $field_key Field key.
	 * @return array<string,mixed>|null
	 */
	private function field( string $field_key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = %s",
				$field_key
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete straight from the table: delete_field() now refuses a system field,
	 * and the site being simulated deleted it back when it was still allowed.
	 *
	 * @param string $field_key Field key.
	 * @return void
	 */
	private function hard_delete( string $field_key ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'bn_profile_fields', array( 'field_key' => $field_key ), array( '%s' ) );
	}

	public function test_a_deleted_hero_identity_field_comes_back_on_upgrade(): void {
		foreach ( ProfileService::HERO_IDENTITY_FIELDS as $key ) {
			$this->hard_delete( $key );
			$this->assertNull( $this->field( $key ), "{$key} should be gone before the upgrade runs." );
		}

		Installer::run();

		foreach ( ProfileService::HERO_IDENTITY_FIELDS as $key ) {
			$row = $this->field( $key );
			$this->assertNotNull( $row, "{$key} must be restored - the hero hardcodes it." );
			$this->assertSame( '1', (string) $row['is_system'], "{$key} must come back protected." );
		}
	}

	public function test_the_restored_field_matches_the_seeded_definition(): void {
		$before = $this->field( 'pronouns' );
		$this->hard_delete( 'pronouns' );

		Installer::run();

		$after = $this->field( 'pronouns' );
		$this->assertNotNull( $after );
		foreach ( array( 'group_id', 'field_key', 'label', 'type', 'sort_order', 'visibility' ) as $column ) {
			$this->assertSame(
				(string) $before[ $column ],
				(string) $after[ $column ],
				"Restored pronouns.{$column} must match the seeded definition."
			);
		}
	}

	public function test_a_field_the_owner_deleted_is_not_resurrected(): void {
		// The whole point of the UPDATE-only rule. website and birth_date are
		// seeded but deletable, and they render by nature, so their absence
		// degrades quietly instead of leaving a hole.
		$this->hard_delete( 'website' );
		$this->hard_delete( 'birth_date' );

		Installer::run();

		$this->assertNull( $this->field( 'website' ) );
		$this->assertNull( $this->field( 'birth_date' ) );
	}

	public function test_restoring_twice_does_not_duplicate_the_field(): void {
		global $wpdb;
		$this->hard_delete( 'pronouns' );

		Installer::run();
		Installer::run();

		$this->assertSame(
			1,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'pronouns'" )
		);
	}
}
