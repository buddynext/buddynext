<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Deleting a repeater entry must actually delete it.
 *
 * WHY THIS EXISTS — Zoho #40911 (Markus Kaufmann, bn.myblasmusik.de), card 10099615124.
 *
 * The customer, in his own words:
 *
 *     "Deleting an entry does not work (using the X). The entry remains even after saving."
 *
 * The client removed the row, renumbered the survivors to 0..n-1, and submitted only the
 * survivors — but the server-side repeater save only ever UPSERTED the submitted entries and
 * never pruned the rows the member removed. Deleting the last entry left its rows untouched;
 * deleting a middle entry shifted the survivors down onto the lower indexes and left the old
 * top index behind as a stale duplicate. Reload: the "deleted" entry is back.
 *
 * The rule this pins: **the submitted entry set IS the entry set.** Any stored entry_index for
 * the group that is not in the submitted payload belongs to a removed entry and must be deleted
 * in the same save — including the "member removed every entry" case, which arrives as an empty
 * array and must clear the group (and flush its stale search mirrors).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * Server-side pruning of removed repeater entries.
 *
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 */
class RepeaterEntryPruneTest extends WP_UnitTestCase {

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
	 * Field ids of the two sub-fields, keyed by field_key.
	 *
	 * @var array<string, int>
	 */
	private array $fields = array();

	/**
	 * A custom repeater group with two sub-fields, one of them searchable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->profiles = new ProfileService();
		$this->member   = (int) $this->factory->user->create();

		$this->group_id = $this->profiles->create_group(
			array(
				'group_key' => 'zz_prune_group',
				'label'     => 'Prune Group',
				'type'      => 'repeater',
			)
		);

		foreach ( array( 'zz_prune_name', 'zz_prune_role' ) as $key ) {
			$this->fields[ $key ] = $this->profiles->create_field(
				array(
					'group_id'      => $this->group_id,
					'field_key'     => $key,
					'label'         => ucfirst( str_replace( '_', ' ', $key ) ),
					'type'          => 'text',
					'is_searchable' => 'zz_prune_name' === $key ? 1 : 0,
					'visibility'    => 'public',
				)
			);
			$this->assertGreaterThan( 0, $this->fields[ $key ], 'fixture: sub-field must exist' );
		}
	}

	/**
	 * Stored entry indexes for the group, per sub-field value.
	 *
	 * @return array<int, array{field_key: string, entry_index: int, value: string}>
	 */
	private function stored_rows(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.field_key, v.entry_index, v.value
				   FROM {$wpdb->prefix}bn_profile_values v
				   JOIN {$wpdb->prefix}bn_profile_fields f ON f.id = v.field_id
				  WHERE v.user_id = %d AND f.group_id = %d
				  ORDER BY v.entry_index, f.field_key",
				$this->member,
				$this->group_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Save two entries, then re-save with only the second (renumbered to 0):
	 * the stale top index must be pruned — no resurrected duplicate on reload.
	 *
	 * @return void
	 */
	public function test_middle_delete_prunes_stale_top_index(): void {
		$saved = $this->profiles->save_profile(
			$this->member,
			array(
				'zz_prune_group' => array(
					array(
						'zz_prune_name' => 'Acme',
						'zz_prune_role' => 'Trumpet',
					),
					array(
						'zz_prune_name' => 'Beta',
						'zz_prune_role' => 'Engineer',
					),
				),
			)
		);
		$this->assertTrue( $saved, 'fixture: initial two-entry save must succeed' );
		$this->assertCount( 4, $this->stored_rows(), 'fixture: two entries × two sub-fields' );

		// The member deletes entry 0; the client renumbers the survivor to index 0.
		$saved = $this->profiles->save_profile(
			$this->member,
			array(
				'zz_prune_group' => array(
					array(
						'zz_prune_name' => 'Beta',
						'zz_prune_role' => 'Engineer',
					),
				),
			)
		);
		$this->assertTrue( $saved );

		$rows = $this->stored_rows();
		$this->assertCount( 2, $rows, 'the removed entry\'s rows must be pruned, not left as a duplicate' );
		foreach ( $rows as $row ) {
			$this->assertSame( '0', (string) $row['entry_index'], 'only the renumbered survivor index remains' );
			$this->assertContains( $row['value'], array( 'Beta', 'Engineer' ), 'the survivor keeps its own values' );
		}
	}

	/**
	 * Removing EVERY entry (submitted as an empty group array) must clear all
	 * rows and flush the group's aggregated search mirror.
	 *
	 * @return void
	 */
	public function test_delete_all_entries_clears_rows_and_search_mirror(): void {
		$saved = $this->profiles->save_profile(
			$this->member,
			array(
				'zz_prune_group' => array(
					array(
						'zz_prune_name' => 'Acme',
						'zz_prune_role' => 'Trumpet',
					),
				),
			)
		);
		$this->assertTrue( $saved );
		$this->assertNotEmpty( $this->stored_rows() );
		$this->assertSame(
			'Acme',
			get_user_meta( $this->member, 'bn_field_zz_prune_name', true ),
			'fixture: the searchable sub-field must have written its mirror'
		);

		$saved = $this->profiles->save_profile( $this->member, array( 'zz_prune_group' => array() ) );
		$this->assertTrue( $saved );

		$this->assertSame( array(), $this->stored_rows(), 'an emptied group must have zero stored rows' );
		$this->assertSame(
			'',
			(string) get_user_meta( $this->member, 'bn_field_zz_prune_name', true ),
			'the stale search mirror must be flushed with the entries'
		);
	}

	/**
	 * A save that never mentions the group must not touch its entries — pruning
	 * is keyed on the submitted payload, not on absence.
	 *
	 * @return void
	 */
	public function test_partial_save_without_group_key_leaves_entries_alone(): void {
		$this->profiles->save_profile(
			$this->member,
			array(
				'zz_prune_group' => array(
					array( 'zz_prune_name' => 'Acme' ),
				),
			)
		);
		$this->assertNotEmpty( $this->stored_rows() );

		// A partial surface (e.g. the Privacy tab) saves unrelated flat fields only.
		$this->profiles->save_profile( $this->member, array( 'headline' => 'Hello' ) );

		$this->assertNotEmpty( $this->stored_rows(), 'a save without the group key must not prune the group' );
	}
}
