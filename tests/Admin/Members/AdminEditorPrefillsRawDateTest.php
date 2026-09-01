<?php
/**
 * The admin member editor must prefill a date input with the real date.
 *
 * A Date of Birth field set to any "Display as" mode except Full date renders
 * EMPTY in Members > Edit. The member set it on the front end and it saved fine;
 * the admin simply cannot see it.
 *
 * The consequence is worse than a blank box. Profile saving is atomic, so if the
 * DOB field is required, the empty input fails validation and the admin cannot
 * save ANY field on that member — a support request that reads as "the member
 * editor is broken", with nothing on screen connecting it to a date-display
 * setting somebody chose months earlier.
 *
 * ## Why it happens
 *
 * `ProfileService::view_value()` reduces a date to its display form the moment it
 * enters the payload — "36 years old", "1990" — deliberately, so a member's real
 * birthday never leaves the server for anyone but them. `value_raw` carries the
 * real date alongside it, and the member-facing edit template already prefills
 * from `value_raw ?? value` for exactly this reason.
 *
 * The admin form read `value` alone. In full-date mode the two are identical,
 * which is why this was invisible until someone chose Age.
 *
 * ## The one thing worth checking before trusting the fix
 *
 * `value_raw` is populated for the OWNER only — `$viewer_id === $profile_user_id`.
 * An admin editing someone else is not the owner, so `value_raw ?? value` would
 * fall straight through to the reduced string and fix nothing. It works here only
 * because `render_edit_member_view()` calls `get_profile( $user_id, $user_id )`,
 * passing the edited member as their own viewer. That call is load-bearing for
 * this fix, so the test asserts the payload shape too — if someone "corrects" it
 * to pass the current admin's id, this fails rather than silently regressing.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members\MemberEditForm;
use BuddyNext\Core\Installer;

/**
 * Date prefill in the admin member editor.
 *
 * @covers \BuddyNext\Admin\Members\MemberEditForm::render_flat_field_input
 */
class AdminEditorPrefillsRawDateTest extends \WP_UnitTestCase {

	/**
	 * The member being edited.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * The date the member actually saved.
	 *
	 * @var string
	 */
	private const DOB = '1990-05-15';

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
				'display_name' => 'Date Member',
				'user_email'   => 'dob@example.com',
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
	 * Create a date field in the given display mode and save a value for the member.
	 *
	 * @param string $display One of date|month_year|year|age.
	 * @return string The field key.
	 */
	private function seed_date_field( string $display ): string {
		$service  = buddynext_service( 'profiles' );
		$group_id = $service->create_group(
			array(
				'group_key'  => 'bn_qa_dates_' . $display,
				'label'      => 'Dates',
				'type'       => 'flat',
				'visibility' => 'public',
			)
		);

		$key = 'qa_dob_' . $display;
		$service->create_field(
			array(
				'group_id'   => $group_id,
				'field_key'  => $key,
				'label'      => 'Date of Birth',
				'type'       => 'date',
				'visibility' => 'public',
				'sort_order' => 0,
				'options'    => array( 'display' => $display ),
			)
		);

		$service->save_profile( $this->user_id, array( $key => self::DOB ) );

		return $key;
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
	 * Every reduced display mode still prefills the input with the real date.
	 *
	 * Driven off the mode list rather than one case, because the bug is invisible
	 * in exactly one mode (full date) and present in the other three — a
	 * single-case test written against `date` would pass with the fix removed.
	 *
	 * @return void
	 */
	public function test_a_reduced_display_mode_still_prefills_the_real_date(): void {
		foreach ( array( 'age', 'year', 'month_year' ) as $display ) {
			$key  = $this->seed_date_field( $display );
			$html = $this->render_admin_editor();

			$this->assertStringContainsString(
				'value="' . self::DOB . '"',
				$html,
				sprintf(
					'Display mode "%s": the editor rendered no date to edit, so a required DOB blocks the whole atomic save.',
					$display
				)
			);
			$this->assertStringContainsString( $key, $html, 'The seeded field should be on the form at all.' );
		}
	}

	/**
	 * Full-date mode keeps working — the control, and the reason this hid so long.
	 *
	 * @return void
	 */
	public function test_full_date_mode_is_unaffected(): void {
		$this->seed_date_field( 'date' );

		$this->assertStringContainsString( 'value="' . self::DOB . '"', $this->render_admin_editor() );
	}

	/**
	 * The payload the fix depends on: reduced `value`, real `value_raw`.
	 *
	 * Guards the guard. `value_raw` is owner-only, so the fix works solely because
	 * the form asks for the member's OWN view of their profile. If that call ever
	 * changes to pass the acting admin, `value_raw` becomes null, `?? value` falls
	 * through to "36 years old", and the bug returns with the fix still in place.
	 *
	 * @return void
	 */
	public function test_the_owner_payload_carries_both_the_reduced_and_the_raw_value(): void {
		$key = $this->seed_date_field( 'age' );

		$profile = buddynext_service( 'profiles' )->get_profile( $this->user_id, $this->user_id );

		$field = null;
		foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $candidate ) {
				if ( $key === ( $candidate['field_key'] ?? '' ) ) {
					$field = $candidate;
				}
			}
		}

		$this->assertIsArray( $field, 'The seeded date field should be in the profile payload.' );
		$this->assertNotSame(
			self::DOB,
			$field['value'],
			'`value` must stay display-reduced — if it carried the raw date, the fix would be untested and the date would leak to viewers.'
		);
		$this->assertSame( self::DOB, $field['value_raw'], 'The owner payload must carry the real date for the edit form to prefill.' );
	}
}
