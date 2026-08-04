<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Tests for the JSON shape of repeater entries in the profile payload.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * @covers \BuddyNext\Profile\ProfileService
 */
class RepeaterEntryShapeTest extends \WP_UnitTestCase {

	private ProfileService $service;
	private int $owner;
	private int $viewer;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
		$this->owner   = self::factory()->user->create();
		$this->viewer  = self::factory()->user->create();
	}

	/**
	 * Regression: a repeater entry with a per-entry visibility used to get a
	 * `_visibility` STRING key appended to its packed field list, which made
	 * wp_json_encode() emit `entries[i]` as a JSON object instead of an array
	 * and crashed every typed consumer (the mobile app's About panel). The
	 * saved visibility must instead ride the group's parallel entry_visibility
	 * array, index-aligned with entries.
	 */
	public function test_entries_stay_packed_lists_when_entry_visibility_is_set(): void {
		global $wpdb;

		$field_id = (int) $wpdb->get_var(
			"SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'work_company'"
		);
		$this->assertGreaterThan( 0, $field_id, 'work_company must exist in the seeded schema.' );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_profile_values',
			array(
				'user_id'          => $this->owner,
				'field_id'         => $field_id,
				'entry_index'      => 0,
				'value'            => 'Acme Corp',
				'entry_visibility' => 'public',
			)
		);
		$this->assertSame( 1, $inserted, 'The fixture row must actually be inserted: ' . $wpdb->last_error );

		$profile = $this->service->get_profile( $this->owner, $this->viewer );
		$this->assertNotNull( $profile );

		$group = null;
		foreach ( $profile['groups'] as $g ) {
			if ( 'work_experience' === $g['group_key'] ) {
				$group = $g;
				break;
			}
		}
		$this->assertNotNull( $group, 'The work_experience repeater group must be in the payload.' );
		$this->assertNotEmpty( $group['entries'] );

		foreach ( $group['entries'] as $entry ) {
			$this->assertTrue(
				array_is_list( $entry ),
				'Every repeater entry must be a packed list — a string key makes it JSON-encode as an object.'
			);
		}
		$this->assertStringStartsWith(
			'[',
			(string) wp_json_encode( $group['entries'][0] ),
			'entries[0] must encode as a JSON array.'
		);

		$this->assertSame(
			array( 'public' ),
			$group['entry_visibility'],
			'The saved per-entry visibility must ride the parallel entry_visibility array.'
		);
	}
}
