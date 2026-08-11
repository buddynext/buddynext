<?php
/**
 * Tests for the admin member edit form's repeater rendering.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members\MemberEditForm;
use BuddyNext\Core\Installer;

/**
 * Guards the edit-member repeater renderer against a saved per-entry visibility.
 *
 * ProfileService::get_profile() surfaces per-entry visibility in the group's
 * PARALLEL entry_visibility array (index-aligned with entries), never inside the
 * packed entry list — the list must stay a JSON array for the app. The renderer
 * must iterate the field-array entries without tripping on that, and skip any
 * element that is not a field object.
 *
 * @covers \BuddyNext\Admin\Members\MemberEditForm
 */
class MemberEditFormTest extends \WP_UnitTestCase {

	/**
	 * Member being edited.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Fresh install + a member to edit before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->user_id = self::factory()->user->create(
			array(
				'display_name' => 'Repeater Member',
				'user_email'   => 'repeater@example.com',
			)
		);
	}

	/**
	 * Leave no request state behind.
	 */
	public function tear_down(): void {
		unset( $_GET['user_id'], $_GET['view'], $_GET['saved'], $_GET['bn_error'] );
		parent::tear_down();
	}

	/**
	 * A repeater entry saved with a per-entry visibility override renders on the
	 * edit form without fataling, and the entry's real field still renders. The
	 * override travels in the group's parallel entry_visibility array; the entry
	 * stays a packed field-array list the renderer can iterate. A regression that
	 * moved visibility back inside the entry (an earlier bug that fataled the
	 * array-typed field renderer with a string argument) is caught by the
	 * precondition below.
	 *
	 * Uses owner-defined group/field keys — never the seeded schema — so the
	 * guard is proven field-agnostic: every site defines its own repeater fields.
	 */
	public function test_renders_repeater_entry_with_visibility_meta_without_fatal(): void {
		$service = buddynext_service( 'profiles' );

		// Owner-defined repeater group + sub-field. Deliberately NOT the seeded
		// work_experience/education keys — a real install can name these anything.
		$group_id = $service->create_group(
			array(
				'group_key'  => 'bn_qa_places',
				'label'      => 'Places Lived',
				'type'       => 'repeater',
				'visibility' => 'public',
			)
		);
		$service->create_field(
			array(
				'group_id'   => $group_id,
				'field_key'  => 'qa_city',
				'label'      => 'City',
				'type'       => 'text',
				'visibility' => 'public',
				'sort_order' => 0,
			)
		);

		// Save one entry WITH a per-entry visibility override. `_visibility` inside
		// the entry is the write contract save_profile() accepts; on READ it is
		// surfaced in the group's parallel entry_visibility array, never inside the
		// packed entry list (which must stay a JSON array for the app — see the REST
		// contract fix in ProfileService::get_profile()).
		$service->save_profile(
			$this->user_id,
			array(
				'bn_qa_places' => array(
					array(
						'qa_city'     => 'Lisbon',
						'_visibility' => 'connections',
					),
				),
			)
		);

		$profile = $service->get_profile( $this->user_id, $this->user_id );
		$group   = $this->group_by_key( $profile, 'bn_qa_places' );

		// Precondition: the per-entry override round-trips via the parallel
		// entry_visibility array, and the entry itself stays a packed field-array
		// list with no scalar `_visibility` sibling — otherwise this test would pass
		// vacuously, and a regression that moved visibility back inside the entry
		// (breaking the app's for…of iteration) would slip through.
		$this->assertIsArray( $group, 'Repeater group should be present.' );
		$this->assertSame( 'connections', $group['entry_visibility'][0] ?? null, 'Saved per-entry visibility should round-trip in the parallel array.' );
		$entry = $group['entries'][0] ?? null;
		$this->assertIsArray( $entry, 'Repeater entry should be present.' );
		$this->assertArrayNotHasKey( '_visibility', $entry, 'Per-entry visibility must not live inside the packed entry list.' );

		// Render the admin edit view. Before the guard this fataled when the
		// entry-field loop reached the `_visibility` scalar.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['user_id'] = (string) $this->user_id;

		ob_start();
		( new MemberEditForm() )->render_edit_member_view();
		$html = (string) ob_get_clean();

		// The real owner-defined field still renders — proves we skipped the
		// scalar sibling, not the field itself.
		$this->assertStringContainsString( 'bn_qa_places[0][qa_city]', $html );
		$this->assertStringContainsString( 'Lisbon', $html );
	}

	/**
	 * A repeater group by key, from a get_profile() payload.
	 *
	 * @param array<string,mixed>|null $profile   Profile payload.
	 * @param string                   $group_key Group to find.
	 * @return array<string,mixed>|null
	 */
	private function group_by_key( ?array $profile, string $group_key ): ?array {
		foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
			if ( ( $group['group_key'] ?? '' ) === $group_key ) {
				return $group;
			}
		}
		return null;
	}
}
