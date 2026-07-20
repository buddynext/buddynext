<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Every repeater entry must carry the group's FULL field schema.
 *
 * WHY THIS EXISTS — Zoho #40911 (Markus Kaufmann, bn.myblasmusik.de), card 10099614969.
 *
 * The customer, in his own words:
 *
 *     "If you add an additional/new field, it is only displayed in the first entry
 *      under 'Edit Profile.'"
 *
 * get_profile() built repeater entries purely from VALUE rows. A field only had rows at the
 * indexes where it was saved, and a field with no rows at all surfaced only through its single
 * LEFT-JOIN NULL row — which casts to entry_index 0. So a newly added field rendered its input
 * only in the first entry, and a sub-field left empty in an entry lost its input there on the
 * edit form: the member literally could not fill it in without re-saving something else first.
 *
 * The rule this pins: **an entry's field set is the GROUP's field set**, not "whichever fields
 * happen to have rows at this index". Values differ per entry; schema never does.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * Full-schema merge for repeater entries in get_profile().
 *
 * @covers \BuddyNext\Profile\ProfileService::get_profile
 */
class RepeaterEntrySchemaTest extends WP_UnitTestCase {

	/**
	 * Profiles service.
	 *
	 * @var ProfileService
	 */
	private ProfileService $profiles;

	/**
	 * Test member.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * The repeater group id under test.
	 *
	 * @var int
	 */
	private int $group_id;

	/**
	 * A custom repeater group with two sub-fields and two saved entries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->profiles = new ProfileService();
		$this->member   = (int) $this->factory->user->create();

		$this->group_id = $this->profiles->create_group(
			array(
				'group_key' => 'zz_schema_group',
				'label'     => 'Schema Group',
				'type'      => 'repeater',
			)
		);

		foreach ( array( 'zz_schema_name', 'zz_schema_role' ) as $key ) {
			$this->profiles->create_field(
				array(
					'group_id'   => $this->group_id,
					'field_key'  => $key,
					'label'      => ucfirst( $key ),
					'type'       => 'text',
					'visibility' => 'public',
				)
			);
		}

		$saved = $this->profiles->save_profile(
			$this->member,
			array(
				'zz_schema_group' => array(
					array(
						'zz_schema_name' => 'Acme',
						'zz_schema_role' => 'Trumpet',
					),
					array(
						'zz_schema_name' => 'Beta',
						// zz_schema_role deliberately left unsent for entry 1.
					),
				),
			)
		);
		$this->assertTrue( $saved, 'fixture: the two-entry save must succeed' );
	}

	/**
	 * Field keys present in one repeater entry of the profile payload.
	 *
	 * @param array<int, mixed> $entry One entry from the group's entries array.
	 * @return array<int, string>
	 */
	private function entry_keys( array $entry ): array {
		$keys = array();
		foreach ( $entry as $k => $field ) {
			if ( '_visibility' === $k || ! is_array( $field ) ) {
				continue;
			}
			$keys[] = (string) $field['field_key'];
		}
		sort( $keys );
		return $keys;
	}

	/**
	 * The group under test from a fresh get_profile() read.
	 *
	 * @return array<string, mixed>
	 */
	private function group_payload(): array {
		$profile = $this->profiles->get_profile( $this->member, $this->member );
		foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
			if ( 'zz_schema_group' === ( $group['group_key'] ?? '' ) ) {
				return $group;
			}
		}
		$this->fail( 'the repeater group must be present in the profile payload' );
	}

	/**
	 * A field added AFTER entries exist must appear in EVERY entry, and a
	 * sub-field with no value row at an index still renders there.
	 *
	 * @return void
	 */
	public function test_every_entry_carries_the_full_group_schema(): void {
		// Markus's exact move: the owner adds a new field once data exists.
		$this->profiles->create_field(
			array(
				'group_id'   => $this->group_id,
				'field_key'  => 'zz_schema_new',
				'label'      => 'Added later',
				'type'       => 'text',
				'visibility' => 'public',
			)
		);

		$group   = $this->group_payload();
		$entries = (array) ( $group['entries'] ?? array() );
		$this->assertCount( 2, $entries, 'both saved entries must be present' );

		$expected = array( 'zz_schema_name', 'zz_schema_new', 'zz_schema_role' );
		foreach ( $entries as $i => $entry ) {
			$this->assertSame(
				$expected,
				$this->entry_keys( (array) $entry ),
				"entry {$i} must carry the full group schema, not just its own value rows"
			);
		}
	}

	/**
	 * The schema merge must never bleed one entry's values into another —
	 * merged placeholders are blank, saved values stay put.
	 *
	 * @return void
	 */
	public function test_schema_merge_keeps_per_entry_values_distinct(): void {
		$group   = $this->group_payload();
		$entries = (array) ( $group['entries'] ?? array() );

		$value_of = static function ( array $entry, string $key ): string {
			foreach ( $entry as $k => $field ) {
				if ( '_visibility' !== $k && is_array( $field ) && $key === $field['field_key'] ) {
					return (string) $field['value'];
				}
			}
			return '(missing)';
		};

		$this->assertSame( 'Acme', $value_of( (array) $entries[0], 'zz_schema_name' ) );
		$this->assertSame( 'Trumpet', $value_of( (array) $entries[0], 'zz_schema_role' ) );
		$this->assertSame( 'Beta', $value_of( (array) $entries[1], 'zz_schema_name' ) );
		$this->assertSame(
			'',
			$value_of( (array) $entries[1], 'zz_schema_role' ),
			'the unset sub-field renders blank in entry 1 — never entry 0\'s value'
		);
	}
}
