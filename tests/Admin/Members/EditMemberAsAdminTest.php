<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Editing another member's account from wp-admin.
 *
 * Two rules: an administrator is never asked for the member's own required
 * answers, and a save changes the one role shown in the Role box, not the
 * member's whole set of roles.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * Edit member, as an administrator.
 *
 * @covers \BuddyNext\Admin\Members::swap_primary_role
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 */
class EditMemberAsAdminTest extends WP_UnitTestCase {

	/**
	 * Profile service.
	 *
	 * @var ProfileService
	 */
	private ProfileService $profiles;

	/**
	 * The member being edited.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Test setup: one required text field every member is asked for.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->profiles = new ProfileService();
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$group_id = $this->profiles->create_group(
			array(
				'group_key' => 'zz_about_you',
				'label'     => 'About you',
			)
		);
		$this->profiles->create_field(
			array(
				'group_id'    => $group_id,
				'field_key'   => 'zz_pronouns',
				'label'       => 'Pronouns',
				'type'        => 'text',
				'is_required' => 1,
			)
		);
		$this->profiles->create_field(
			array(
				'group_id'  => $group_id,
				'field_key' => 'zz_city',
				'label'     => 'City',
				'type'      => 'text',
			)
		);
		wp_cache_flush();
	}

	/**
	 * The member must answer a required field; their administrator need not.
	 *
	 * @return void
	 */
	public function test_required_binds_the_member_not_their_administrator(): void {
		$edit = array(
			'zz_pronouns' => '',
			'zz_city'     => 'Lisbon',
		);

		wp_set_current_user( $this->member );
		$this->assertWPError( $this->profiles->save_profile( $this->member, $edit ), 'The member is still asked.' );

		// Someone who may not edit this member is not excused either.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $this->profiles->save_profile( $this->member, $edit ), 'Another member is not an administrator.' );

		// Nobody signed in (a background job, an import) is not excused.
		wp_set_current_user( 0 );
		$this->assertWPError( $this->profiles->save_profile( $this->member, $edit ) );

		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->profiles->save_profile( $this->member, $edit ), 'The administrator saves the city without inventing pronouns.' );

		// The administrator editing their OWN profile is a member like any other.
		$this->assertWPError( $this->profiles->save_profile( $this->admin, $edit ) );
	}

	/**
	 * A save changes the one role in the Role box and keeps the others.
	 *
	 * @return void
	 */
	public function test_saving_keeps_a_members_other_roles(): void {
		add_role( 'zz_instructor', 'Instructor' );
		$user = new \WP_User( $this->member );
		$user->add_role( 'zz_instructor' );
		wp_set_current_user( $this->admin );

		// The unchanged form posts the role already shown.
		Members::swap_primary_role( $this->member, 'subscriber' );
		$this->assertEqualsCanonicalizing( array( 'subscriber', 'zz_instructor' ), ( new \WP_User( $this->member ) )->roles, 'An unrelated edit touches no role.' );

		// A deliberate change swaps that one role.
		Members::swap_primary_role( $this->member, 'editor' );
		$this->assertEqualsCanonicalizing( array( 'editor', 'zz_instructor' ), ( new \WP_User( $this->member ) )->roles );

		// An administrator's own role is not changed from this screen.
		Members::swap_primary_role( $this->admin, 'subscriber' );
		$this->assertSame( array( 'administrator' ), array_values( ( new \WP_User( $this->admin ) )->roles ) );

		remove_role( 'zz_instructor' );
	}
}
