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
 * Guards the edit-member repeater renderer against the per-entry `_visibility`
 * scalar that ProfileService appends alongside an entry's field arrays.
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
	 * A saved repeater entry carries a scalar `_visibility` sibling next to its
	 * field arrays. The edit form must skip that scalar instead of passing it to
	 * the array-typed field renderer, which fataled with:
	 * "render_repeater_field_input(): Argument #3 must be of type array, string given".
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

		// Save one entry WITH a per-entry visibility override, so get_profile()
		// surfaces the scalar `_visibility` element that triggered the fatal.
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

		// Precondition: the entry really does carry the scalar `_visibility`
		// sibling — otherwise this test would pass vacuously.
		$entry = $this->first_entry( $service->get_profile( $this->user_id, $this->user_id ), 'bn_qa_places' );
		$this->assertIsArray( $entry, 'Repeater entry should be present.' );
		$this->assertArrayHasKey( '_visibility', $entry, 'Entry should carry the scalar visibility sibling.' );
		$this->assertIsString( $entry['_visibility'] );

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
	 * First entry of a repeater group by key, from a get_profile() payload.
	 *
	 * @param array<string,mixed>|null $profile   Profile payload.
	 * @param string                   $group_key Group to find.
	 * @return array<string,mixed>|null
	 */
	private function first_entry( ?array $profile, string $group_key ): ?array {
		foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
			if ( ( $group['group_key'] ?? '' ) === $group_key ) {
				$entries = $group['entries'] ?? array();
				return $entries[0] ?? null;
			}
		}
		return null;
	}
}
