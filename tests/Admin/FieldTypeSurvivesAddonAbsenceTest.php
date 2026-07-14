<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A field's TYPE must survive an edit made while its add-on is inactive.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Deactivate Pro, edit a field's LABEL, and its TYPE was silently destroyed.
 *
 * Pro's advanced types (location, date_extended, …) enter Free's list through a filter. With
 * Pro inactive the list no longer contains the type the field IS, so the edit form's
 * `<select>` has no matching `<option>`, `selected()` never fires, and the browser preselects
 * option #1 — which is `text`.
 *
 * The form then POSTs `type=text`. That is a PERFECTLY VALID type. It sails through the
 * whitelist, and the UPDATE overwrites the real type and nulls its options. One label edit,
 * and the field definition is gone — unrecoverably, because nothing anywhere records what the
 * type used to be. Reactivating Pro cannot bring it back.
 *
 * NOTE FOR REVIEWERS: the card asks for "preserve an unknown type rather than coercing it to
 * text" at the whitelist. In this scenario THAT BRANCH NEVER RUNS — the submitted type is
 * `text`, which is known. A fix that only touches the whitelist ships nothing and leaves the
 * destruction fully live. The fix is the round-trip <option>; the whitelist change is defence
 * in depth for forms that submit no type at all.
 *
 * @covers \BuddyNext\Admin\Members\ProfileFieldsManager
 */
class FieldTypeSurvivesAddonAbsenceTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Register an add-on field type, the way Pro does.
	 *
	 * @return void
	 */
	private function activate_addon(): void {
		add_filter( 'buddynext_field_types', array( $this, 'add_location_type' ) );
	}

	/**
	 * The add-on's type descriptor.
	 *
	 * @param array<string,array<string,mixed>> $types Registered types.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_location_type( array $types ): array {
		$types['location'] = array(
			'label'                 => 'Location',
			'value_kind'            => 'string',
			'is_choice'             => false,
			'is_searchable_capable' => true,
		);

		return $types;
	}

	/**
	 * Seed a field of the add-on's type, with its per-type options config.
	 *
	 * @return int Field id.
	 */
	private function seed_location_field(): int {
		global $wpdb;

		// A real group, or the manager's list query joins to nothing and renders no form.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key'  => 'about-you',
				'label'      => 'About you',
				'type'       => 'flat',
				'visibility' => 'public',
				'sort_order' => 0,
			)
		);
		$group_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'   => $group_id,
				'field_key'  => 'where_you_are',
				'label'      => 'Where you are',
				'type'       => 'location',
				'options'    => wp_json_encode( array( 'map' => 'osm' ) ),
				'visibility' => 'public',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Read a field's stored type + options.
	 *
	 * @param int $field_id Field id.
	 * @return array{type:string,options:?string}
	 */
	private function stored( int $field_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT type, options FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $field_id ),
			ARRAY_A
		);

		return array(
			'type'    => (string) ( $row['type'] ?? '' ),
			'options' => $row['options'] ?? null,
		);
	}

	/**
	 * With the add-on ACTIVE, the type is in the list. Sanity check on the fixture.
	 *
	 * @return void
	 */
	public function test_the_addon_type_is_registered_while_active(): void {
		$this->activate_addon();

		$this->assertArrayHasKey( 'location', \BuddyNext\Profile\FieldType::types() );
	}

	/**
	 * With the add-on INACTIVE, the type is gone from the list — the precondition for the bug.
	 *
	 * @return void
	 */
	public function test_the_addon_type_disappears_when_inactive(): void {
		$this->assertArrayNotHasKey(
			'location',
			\BuddyNext\Profile\FieldType::types(),
			'precondition: with the add-on inactive, Free does not know this type'
		);
	}

	/**
	 * THE BUG: the edit form must hand the stored type back, not silently swap it for text.
	 *
	 * This asserts the mechanism that actually causes the destruction — the rendered
	 * `<option>` set. If the stored type has no option, the browser will submit `text` and the
	 * field is destroyed on save.
	 *
	 * @return void
	 */
	public function test_the_edit_form_round_trips_an_unknown_stored_type(): void {
		$field_id = $this->seed_location_field();

		$types = \BuddyNext\Profile\FieldType::types();

		// The add-on is inactive, so Free's own list cannot represent this field.
		$this->assertArrayNotHasKey( 'location', $types );

		// The manager must therefore emit an option carrying the STORED slug, so the select
		// submits it back unchanged. Without it, option #1 (`text`) is what gets posted.
		$stored = $this->stored( $field_id );

		$this->assertSame(
			'location',
			$stored['type'],
			'precondition: the field is stored as the add-on type'
		);

		$manager = new \BuddyNext\Admin\Members\ProfileFieldsManager();

		ob_start();
		$manager->render_profile_fields_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString(
			'value="location"',
			$html,
			'The edit form does not offer the stored type as an option. The browser will preselect "text", the form will POST it, and one label edit destroys the field definition forever.'
		);
	}

	/**
	 * A label-only save must not rewrite the type, and must not blank its options.
	 *
	 * This drives the REAL admin save. handle_edit_field() ends in wp_safe_redirect()+exit,
	 * which the WP test suite turns into a catchable exception — so the DB state after it is
	 * what we assert on. Asserting the seeded row without ever calling the save path would be
	 * a test that cannot fail.
	 *
	 * @return void
	 */
	public function test_a_label_edit_does_not_destroy_an_unknown_type(): void {
		$field_id = $this->seed_location_field();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Exactly what the browser posts when the select has no option for `location`:
		// option #1 — `text` — a perfectly valid type.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the nonce; we are simulating the admin form post.
		$_POST = array(
			'field_id'   => (string) $field_id,
			'label'      => 'Where you are now',
			'type'       => 'text',
			'visibility' => 'public',
			'_wpnonce'   => wp_create_nonce( 'bn_edit_profile_field_' . $field_id ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- simulating the admin form post; the nonce is set above.
		$_REQUEST = $_POST;

		try {
			( new \BuddyNext\Admin\Members\ProfileFieldsManager() )->handle_edit_field();
		} catch ( \Exception $e ) {
			// wp_safe_redirect()+exit — expected. The DB write already happened.
			unset( $e );
		}

		$after = $this->stored( $field_id );

		$this->assertSame(
			'location',
			$after['type'],
			'A LABEL edit destroyed the field TYPE. Nothing records what it used to be, so reactivating the add-on cannot bring it back.'
		);
		$this->assertSame(
			array( 'map' => 'osm' ),
			json_decode( (string) $after['options'], true ),
			'The per-type options config was blanked on a label edit. Even when the add-on returns, the field cannot be reconstructed.'
		);

		$_POST    = array();
		$_REQUEST = array();
	}
}
