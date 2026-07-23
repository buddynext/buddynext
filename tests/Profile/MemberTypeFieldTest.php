<?php
/**
 * Tests for the single-select `member_type` USER field.
 *
 * member_type is a thin UI over the member-type ASSIGNMENT (usermeta bn_member_type
 * + bn_member_type_assignments), not a bn_profile_values field. It is a public
 * classification and the pivot a future engine uses for per-type layouts, so:
 *   - only self_select = 1 types are offered / accepted (admin-only types never),
 *   - saving assigns the system type (directory filter works),
 *   - it is set-once: a member's own edit never changes an existing classification,
 *   - it never writes a bn_profile_values row.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\FieldType;
use BuddyNext\Profile\ProfileService;

/**
 * @covers \BuddyNext\Profile\FieldType::member_type_self_select_options
 * @covers \BuddyNext\Profile\FieldType::sanitize
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 */
class MemberTypeFieldTest extends \WP_UnitTestCase {

	/**
	 * Profile service under test.
	 *
	 * @var ProfileService
	 */
	private ProfileService $service;

	/**
	 * Member-type service (the assignment source of truth).
	 *
	 * @var object
	 */
	private $member_types;

	/**
	 * Test member.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * The member_type field under test.
	 *
	 * @var int
	 */
	private int $field_id;

	/**
	 * Self-selectable type id (self_select = 1).
	 *
	 * @var int
	 */
	private int $open_type;

	/**
	 * Admin-only type id (self_select = 0).
	 *
	 * @var int
	 */
	private int $locked_type;

	/**
	 * Schema, a self-selectable + an admin-only type, and the field.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service      = new ProfileService();
		$this->user_id      = self::factory()->user->create();
		$this->member_types = buddynext_service( 'member_types' );

		// Unique slugs so a starter-seeded 'mt_open'/'mt_locked' can't collide.
		$this->open_type   = $this->make_type( 'mt_open', 'Open Type', true );
		$this->locked_type = $this->make_type( 'mt_locked', 'Locked Type', false );

		$this->field_id = (int) $this->service->create_field(
			array(
				'field_key'  => 'member_type',
				'label'      => 'Member Type',
				'type'       => 'member_type',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 1,
			)
		);
	}

	/**
	 * Create a member type, resolving a seeded-slug conflict to the existing row.
	 *
	 * @param string $slug        Type slug.
	 * @param string $name        Display name.
	 * @param bool   $self_select Whether members may self-assign it.
	 * @return int Type id.
	 */
	private function make_type( string $slug, string $name, bool $self_select ): int {
		$id = $this->member_types->create(
			array(
				'slug'        => $slug,
				'name'        => $name,
				'self_select' => $self_select,
			)
		);
		if ( is_wp_error( $id ) ) {
			global $wpdb;
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_member_types WHERE slug = %s", $slug )
			);
			$this->member_types->update( $existing, array( 'self_select' => $self_select ) );
			return $existing;
		}
		return (int) $id;
	}

	/**
	 * Only self_select = 1 types are offered as a member's own choice.
	 *
	 * @return void
	 */
	public function test_self_select_options_exclude_admin_only_types(): void {
		$options = FieldType::member_type_self_select_options();

		$this->assertArrayHasKey( 'mt_open', $options, 'A self-selectable type must be offered.' );
		$this->assertArrayNotHasKey( 'mt_locked', $options, 'An admin-only (self_select=0) type must never be offered.' );
	}

	/**
	 * sanitize() keeps a self-selectable slug and drops everything else.
	 *
	 * @return void
	 */
	public function test_sanitize_accepts_self_select_and_rejects_the_rest(): void {
		$field = array( 'type' => 'member_type' );

		$this->assertSame( 'mt_open', FieldType::sanitize( $field, 'mt_open' ), 'A self-selectable slug survives.' );
		$this->assertSame( '', FieldType::sanitize( $field, 'mt_locked' ), 'An admin-only type is rejected to empty.' );
		$this->assertSame( '', FieldType::sanitize( $field, 'nope' ), 'An unknown slug is rejected to empty.' );
		$this->assertSame( '', FieldType::sanitize( $field, '' ), 'Empty stays empty.' );
	}

	/**
	 * Saving a self-selectable pick assigns the SYSTEM type (not a profile value).
	 *
	 * @return void
	 */
	public function test_save_assigns_the_system_member_type(): void {
		$this->service->save_profile( $this->user_id, array( 'member_type' => 'mt_open' ) );

		$type = $this->member_types->get_user_type( $this->user_id );
		$this->assertIsArray( $type, 'The member should now have a type.' );
		$this->assertSame( 'mt_open', $type['slug'] );
		$this->assertSame( 'mt_open', get_user_meta( $this->user_id, 'bn_member_type', true ), 'usermeta read cache is written through.' );
	}

	/**
	 * member_type never writes a bn_profile_values row — the assignment is truth.
	 *
	 * @return void
	 */
	public function test_save_writes_no_profile_values_row(): void {
		global $wpdb;
		$this->service->save_profile( $this->user_id, array( 'member_type' => 'mt_open' ) );

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d",
				$this->user_id,
				$this->field_id
			)
		);
		$this->assertSame( 0, $rows, 'member_type is assignment-backed; it must not create a profile-values row.' );
	}

	/**
	 * A member cannot self-assign an admin-only type by posting its slug directly.
	 *
	 * @return void
	 */
	public function test_save_ignores_admin_only_type(): void {
		$this->service->save_profile( $this->user_id, array( 'member_type' => 'mt_locked' ) );

		$this->assertNull( $this->member_types->get_user_type( $this->user_id ), 'An admin-only type must not be self-assignable.' );
	}

	/**
	 * Set-once: a member's own edit never overrides an existing classification.
	 *
	 * @return void
	 */
	public function test_member_cannot_change_type_once_set(): void {
		// First self-selection sticks.
		$this->service->save_profile( $this->user_id, array( 'member_type' => 'mt_open' ) );
		$this->assertSame( 'mt_open', $this->member_types->get_user_type( $this->user_id )['slug'] );

		// Add a second self-selectable type, then try to switch to it.
		$this->member_types->create(
			array(
				'slug'        => 'mt_mentor',
				'name'        => 'Mentor',
				'self_select' => true,
			)
		);
		$this->service->save_profile( $this->user_id, array( 'member_type' => 'mt_mentor' ) );

		$this->assertSame(
			'mt_open',
			$this->member_types->get_user_type( $this->user_id )['slug'],
			'Once set, a member cannot change their own type — only an admin can.'
		);
	}
}
