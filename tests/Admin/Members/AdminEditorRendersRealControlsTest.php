<?php
/**
 * The admin member editor must offer the same controls the member's own editor does.
 *
 * Members > Edit hand-rolled its inputs and named the types it understood. Anything
 * it did not name fell through to a plain text box, so the screen showed raw storage
 * and invited an admin to overwrite it with something nothing can read. Measured in
 * the browser at `?page=buddynext-members&view=edit-member&user_id=1`, before the
 * fix:
 *
 *   name="qa_pro_location"  type=text
 *   value={"address":"Berlin, Germany","lat":52.52,"lng":13.404999999999999}
 *
 * Type "Berlin" over that and the coordinates are gone. The member's own editor
 * rendered a search box, a Remove control and a Leaflet map for the same field,
 * because it asks `FieldType::render_input()` rather than re-implementing it.
 *
 * The repeater rows had the same shape and a shorter list: textarea and url. So a
 * `date` sub-field was a free-text box, and a `boolean` sub-field was a free-text
 * box in which "Yes" and "on" happen to register as true while "Y" or a tick mark
 * silently do not.
 *
 * ## The fix is a deletion
 *
 * The editor now renders EVERY type through `FieldType::render_input()`, with no
 * list of exceptions. The 226-line flat renderer became 39, and the two copies of
 * the repeater row became one.
 *
 * That is possible because the branches were not carrying their weight. The product
 * registers 20 field types; the form had 12 hand-written branches, of which four -
 * `checkbox`, `toggle`, `rating`, `social` - were for types that DO NOT EXIST, so
 * those controls could never render. The other eight duplicated a control
 * `FieldType` already had, which is how they drifted: the option types printed the
 * option LABEL where the option VALUE belonged, so every select on the site rendered
 * empty whatever the member had saved.
 *
 * The tests below use `boolean`, `date` and `category_multiselect` - types Free
 * itself registers - so they hold on a site with no Pro installed, while the same
 * single path is what serves Pro's advanced types.
 *
 * ## The saved-row / new-row pair
 *
 * The blank `<template>` a new entry is cloned from was a second hand-written copy
 * of the same markup. Two copies is how they came to disagree in the first place, so
 * one test renders both and asserts they carry the same control.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members\MemberEditForm;
use BuddyNext\Core\Installer;

/**
 * Control rendering and boolean round-trip in the admin member editor.
 *
 * @covers \BuddyNext\Admin\Members\MemberEditForm::render_flat_field_input
 * @covers \BuddyNext\Admin\Members\MemberEditForm::render_repeater_row
 * @covers \BuddyNext\Admin\Members::collect_profile_data
 * @covers \BuddyNext\Admin\Members::empty_submission
 */
class AdminEditorRendersRealControlsTest extends \WP_UnitTestCase {

	/**
	 * The member being edited.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Fresh install plus a member to edit.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::run();

		$this->user_id = self::factory()->user->create(
			array(
				'display_name' => 'Control Member',
				'user_email'   => 'controls@example.com',
			)
		);
	}

	/**
	 * Leave no request state behind.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['user_id'], $_GET['view'] );

		parent::tear_down();
	}

	/**
	 * Create a flat group holding one field of the given type.
	 *
	 * @param string $type Field type slug.
	 * @param string $key  Field key.
	 * @return void
	 */
	private function seed_flat_field( string $type, string $key ): void {
		$service = buddynext_service( 'profiles' );

		$group_id = $service->create_group(
			array(
				'group_key'  => 'bn_qa_flat_' . $key,
				'label'      => 'Flat',
				'type'       => 'flat',
				'visibility' => 'public',
			)
		);

		$service->create_field(
			array(
				'group_id'   => $group_id,
				'field_key'  => $key,
				'label'      => 'Flat Control',
				'type'       => $type,
				'visibility' => 'public',
				'sort_order' => 0,
			)
		);
	}

	/**
	 * Create a repeater group with one sub-field of each given type.
	 *
	 * @param array<string, string> $fields field_key => type.
	 * @return string The group key.
	 */
	private function seed_repeater_group( array $fields ): string {
		$service   = buddynext_service( 'profiles' );
		$group_key = 'bn_qa_rep';

		$group_id = $service->create_group(
			array(
				'group_key'  => $group_key,
				'label'      => 'Repeating',
				'type'       => 'repeater',
				'visibility' => 'public',
			)
		);

		$order = 0;
		foreach ( $fields as $key => $type ) {
			$service->create_field(
				array(
					'group_id'   => $group_id,
					'field_key'  => $key,
					'label'      => ucfirst( str_replace( '_', ' ', $key ) ),
					'type'       => $type,
					'visibility' => 'public',
					'sort_order' => $order++,
				)
			);
		}

		return $group_key;
	}

	/**
	 * Render the admin edit view as an administrator and return its HTML.
	 *
	 * @return string
	 */
	private function render_admin_editor(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['user_id'] = (string) $this->user_id;

		ob_start();
		( new MemberEditForm() )->render_edit_member_view();

		return (string) ob_get_clean();
	}

	/**
	 * A flat boolean renders a checkbox, not a text box.
	 *
	 * @return void
	 */
	public function test_a_flat_boolean_renders_a_checkbox(): void {
		$this->seed_flat_field( 'boolean', 'qa_flat_bool' );

		$html = $this->render_admin_editor();

		$this->assertStringContainsString(
			'qa_flat_bool',
			$html,
			'Fixture: the seeded field must be on the form at all.'
		);
		$this->assertMatchesRegularExpression(
			'/<input[^>]+type="checkbox"[^>]+name="qa_flat_bool"/',
			$html,
			'A boolean rendered as something other than a checkbox - the admin is being asked to type a truth value.'
		);
	}

	/**
	 * A repeater date sub-field renders a date control on the saved row.
	 *
	 * `date` is chosen because Free registers it, so this holds with no Pro present,
	 * and because it is one of the types the old two-branch repeater silently
	 * downgraded.
	 *
	 * @return void
	 */
	public function test_a_repeater_date_renders_a_date_control(): void {
		$group_key = $this->seed_repeater_group( array( 'qa_when' => 'date' ) );

		buddynext_service( 'profiles' )->save_profile(
			$this->user_id,
			array( $group_key => array( array( 'qa_when' => '2021-04-01' ) ) )
		);

		$html = $this->render_admin_editor();

		$this->assertMatchesRegularExpression(
			'/<input[^>]+type="date"[^>]+name="' . preg_quote( $group_key, '/' ) . '\[0\]\[qa_when\]"/',
			$html,
			'A repeater date sub-field is a free-text box; the member editing the same entry gets a date picker.'
		);
	}

	/**
	 * A saved row and the blank template row carry the same control.
	 *
	 * They were two hand-written copies of the same markup, which is how their type
	 * lists came to differ. A fix that reached only one of them means the row an
	 * admin ADDS behaves differently from the row already there.
	 *
	 * @return void
	 */
	public function test_an_added_row_gets_the_same_control_as_a_saved_row(): void {
		$group_key = $this->seed_repeater_group( array( 'qa_flag' => 'boolean' ) );

		buddynext_service( 'profiles' )->save_profile(
			$this->user_id,
			array( $group_key => array( array( 'qa_flag' => '1' ) ) )
		);

		$html = $this->render_admin_editor();

		$this->assertMatchesRegularExpression(
			'/<input[^>]+type="checkbox"[^>]+name="' . preg_quote( $group_key, '/' ) . '\[0\]\[qa_flag\]"/',
			$html,
			'The saved row is not a checkbox.'
		);
		$this->assertMatchesRegularExpression(
			'/<input[^>]+type="checkbox"[^>]+name="' . preg_quote( $group_key, '/' ) . '\[__idx__\]\[qa_flag\]"/',
			$html,
			'The blank template row is not a checkbox, so a row the admin ADDS is a different control from the one already on screen.'
		);
	}

	/**
	 * An unchecked box clears the stored value instead of leaving it set.
	 *
	 * This is the half of the change that has no visible symptom until someone tries
	 * to undo something. An unchecked checkbox posts nothing, `save_profile()` walks
	 * only the keys it is handed, so without the guard the stored 1 survives, the
	 * screen reports success, and the row comes back ticked.
	 *
	 * Asserted on the collected payload rather than through the handler: the handler
	 * redirects and exits, and the mapping is the whole of the behaviour.
	 *
	 * @return void
	 */
	public function test_an_unchecked_box_is_submitted_as_false(): void {
		$group_key = $this->seed_repeater_group(
			array(
				'qa_flag' => 'boolean',
				'qa_note' => 'text',
			)
		);
		$this->seed_flat_field( 'boolean', 'qa_flat_bool' );

		// No setAccessible(): it has been a no-op since PHP 8.1 and is deprecated in
		// 8.5, where it prints a notice per call.
		$collect = new \ReflectionMethod( \BuddyNext\Admin\Members::class, 'collect_profile_data' );

		// Exactly what the browser posts when the box is unticked and the text
		// beside it is filled in: the checkbox key is simply not there.
		$payload = $collect->invoke(
			null,
			array(
				$group_key => array(
					array( 'qa_note' => 'Still here' ),
				),
			)
		);

		$this->assertArrayHasKey( $group_key, $payload, 'Fixture: the repeater group must survive collection.' );
		$this->assertSame(
			'',
			$payload[ $group_key ][0]['qa_flag'] ?? null,
			'An unticked box was left out of the payload, so save_profile() never touches it and the stored value stays true - the admin can tick this and never untick it.'
		);
		$this->assertSame(
			'Still here',
			$payload[ $group_key ][0]['qa_note'],
			'The sibling field must be unaffected.'
		);
		$this->assertSame(
			'',
			$payload['qa_flat_bool'] ?? null,
			'The same rule must hold for a flat boolean.'
		);
	}

	/**
	 * A ticked box still submits true. Guards the guard.
	 *
	 * Writing an empty string unconditionally would pass every assertion above and
	 * make the control impossible to switch ON.
	 *
	 * @return void
	 */
	public function test_a_ticked_box_is_still_submitted_as_true(): void {
		$group_key = $this->seed_repeater_group( array( 'qa_flag' => 'boolean' ) );

		// No setAccessible(): it has been a no-op since PHP 8.1 and is deprecated in
		// 8.5, where it prints a notice per call.
		$collect = new \ReflectionMethod( \BuddyNext\Admin\Members::class, 'collect_profile_data' );

		$payload = $collect->invoke(
			null,
			array(
				$group_key => array(
					array( 'qa_flag' => '1' ),
				),
			)
		);

		$this->assertSame( '1', $payload[ $group_key ][0]['qa_flag'] );
	}

	/**
	 * A field the form did not post is left alone, unless it is a boolean.
	 *
	 * The "absent means false" rule is scoped to booleans on purpose. Every other
	 * control posts a key whenever it is rendered, so widening it would start
	 * clearing fields nobody touched.
	 *
	 * @return void
	 */
	public function test_an_absent_non_boolean_is_not_cleared(): void {
		$this->seed_flat_field( 'text', 'qa_flat_text' );

		// No setAccessible(): it has been a no-op since PHP 8.1 and is deprecated in
		// 8.5, where it prints a notice per call.
		$collect = new \ReflectionMethod( \BuddyNext\Admin\Members::class, 'collect_profile_data' );

		$payload = $collect->invoke( null, array() );

		$this->assertArrayNotHasKey(
			'qa_flat_text',
			$payload,
			'A text field nobody submitted was queued for writing, which would blank it on every save.'
		);
	}

	/**
	 * No type renders as a bare text box holding its own storage.
	 *
	 * The general statement of the bug, rather than one instance of it: whatever the
	 * type, the admin must be given a control, never the value as the reader would
	 * find it in the database.
	 *
	 * @return void
	 */
	public function test_no_registered_type_falls_through_to_raw_storage(): void {
		$expected = array(
			'boolean'              => 'checkbox',
			'date'                 => 'date',
			'number'               => 'number',
			'url'                  => 'url',
			'email'                => 'email',
		);

		foreach ( $expected as $type => $input_type ) {
			$key = 'qa_t_' . $type;
			$this->seed_flat_field( $type, $key );
		}

		$html = $this->render_admin_editor();

		foreach ( $expected as $type => $input_type ) {
			$key = 'qa_t_' . $type;
			$this->assertMatchesRegularExpression(
				'/<input[^>]+type="' . $input_type . '"[^>]+name="' . $key . '"/',
				$html,
				sprintf( 'A %s field is not a %s control - the admin is being shown storage and asked to retype it.', $type, $input_type )
			);
		}
	}

	/**
	 * A category-backed group is editable by the admin, not read-only.
	 *
	 * It used to render as resolved labels with no input at all and the note
	 * "Members choose these themselves ... Read-only here." That was a defensive
	 * workaround from when the hand-rolled control would have destroyed the value on
	 * save; with one correct renderer there is nothing to defend against, and the
	 * site owner is supposed to have full control from the backend.
	 *
	 * @return void
	 */
	public function test_a_category_group_is_editable_not_read_only(): void {
		$this->seed_flat_field( 'category_multiselect', 'qa_cats' );

		$html = $this->render_admin_editor();

		$this->assertStringNotContainsString(
			'Read-only here',
			$html,
			'The admin is still being told they cannot edit a field they are supposed to control.'
		);
		$this->assertStringContainsString(
			'name="qa_cats[]"',
			$html,
			'A category group rendered with no input, so an admin cannot change it at all.'
		);
	}

	/**
	 * Every control that can post nothing can also be cleared.
	 *
	 * A checkbox, a radio group and a checkbox group all submit NO key when the admin
	 * chooses nothing, and save_profile() only walks the keys it is handed. Without
	 * the empty-submission rule these three are one-way: settable from the backend
	 * and impossible to unset.
	 *
	 * @return void
	 */
	public function test_every_emptyable_control_can_be_cleared(): void {
		$this->seed_flat_field( 'boolean', 'qa_c_bool' );
		$this->seed_flat_field( 'radio', 'qa_c_radio' );
		$this->seed_flat_field( 'multiselect', 'qa_c_multi' );
		$this->seed_flat_field( 'category_multiselect', 'qa_c_cats' );

		$collect = new \ReflectionMethod( \BuddyNext\Admin\Members::class, 'collect_profile_data' );

		// The form was submitted with none of them chosen, so none of them posted.
		$payload = $collect->invoke( null, array() );

		$this->assertSame( '', $payload['qa_c_bool'] ?? null, 'A boolean cannot be unticked from the backend.' );
		$this->assertSame( '', $payload['qa_c_radio'] ?? null, 'A radio group cannot be cleared from the backend.' );
		$this->assertSame( array(), $payload['qa_c_multi'] ?? null, 'A multiselect cannot be emptied from the backend.' );
		$this->assertSame( array(), $payload['qa_c_cats'] ?? null, 'A category group cannot be emptied from the backend.' );
	}

	/**
	 * Free alone: a Pro-typed value is protected, not handed over as raw JSON.
	 *
	 * The case most sites are actually in. A field whose stored TYPE is `location`
	 * on a site with no Pro has a value nothing present can decode, and the old
	 * hand-rolled fallback rendered exactly the wrong thing for it - a visible text
	 * box prefilled with
	 * `{"address":"Berlin, Germany","lat":52.52,"lng":13.404999999999999}`. One
	 * keystroke from an admin who assumed it was a typo and the coordinates were
	 * gone, with "Profile updated successfully" on screen.
	 *
	 * Routing every type through the shared renderer fixed this for free, because
	 * `FieldType::render_input()` already guards it: `is_unreadable_payload()`
	 * returns a read-only panel with the value preserved in a hidden input.
	 *
	 * This is the ONE place a read-only state is correct on this screen, and the
	 * reason is narrow: the control that can edit the value does not exist. It is
	 * not a field being withheld from the admin - there is nothing to withhold.
	 *
	 * Free's suite does not load Pro, so `location` is genuinely unregistered here
	 * and this is the real code path, not a simulation of it. Verified in the
	 * browser the same way: deactivated Pro, saved the member untouched, and the
	 * JSON came back byte-for-byte.
	 *
	 * @return void
	 */
	public function test_a_pro_typed_value_is_protected_when_pro_is_absent(): void {
		$this->assertFalse(
			\BuddyNext\Profile\FieldType::is_registered_type( 'location' ),
			'Fixture: this test is about an UNREGISTERED type. If Pro is loaded here, it proves nothing.'
		);

		$payload = '{"address":"Berlin, Germany","lat":52.52,"lng":13.404999999999999}';

		$this->seed_flat_field( 'location', 'qa_orphan_loc' );
		buddynext_service( 'profiles' )->save_profile( $this->user_id, array( 'qa_orphan_loc' => $payload ) );

		$html = $this->render_admin_editor();

		$this->assertStringContainsString(
			'bn-field--unavailable',
			$html,
			'No protective panel: an undecodable value is being offered for editing.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<input(?![^>]*type="hidden")[^>]*name="qa_orphan_loc"/',
			$html,
			'The raw payload is sitting in a VISIBLE input. One keystroke destroys coordinates the site can no longer regenerate.'
		);
		$this->assertMatchesRegularExpression(
			'/<input[^>]*type="hidden"[^>]*name="qa_orphan_loc"/',
			$html,
			'The value is not preserved in a hidden input, so saving this form would drop it.'
		);
	}

	/**
	 * An unregistered type holding PLAIN TEXT stays editable.
	 *
	 * Guards the guard, and guards the admin's reach: the protection above is for
	 * values that cannot be read, not for every field whose type is unknown. A
	 * legacy field, or one re-typed back to text, holds an ordinary string that an
	 * admin must still be able to edit.
	 *
	 * @return void
	 */
	public function test_an_unregistered_type_holding_plain_text_stays_editable(): void {
		$this->seed_flat_field( 'some_retired_type', 'qa_legacy' );
		buddynext_service( 'profiles' )->save_profile( $this->user_id, array( 'qa_legacy' => 'Just a sentence' ) );

		$html = $this->render_admin_editor();

		$this->assertMatchesRegularExpression(
			'/<input[^>]*name="qa_legacy"[^>]*value="Just a sentence"|<input[^>]*value="Just a sentence"[^>]*name="qa_legacy"/',
			$html,
			'A readable value was locked behind the needs-Pro panel, so the admin cannot edit a perfectly ordinary field.'
		);
	}
}
