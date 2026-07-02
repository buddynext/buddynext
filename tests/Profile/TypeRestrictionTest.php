<?php
/**
 * Tests for per-member-type profile groups (bn_profile_groups.type_restriction,
 * governance plan G2) and owner-authored field hints (description/placeholder, G1).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * Type-restricted group visibility in get_profile() + registration, and the
 * description/placeholder columns.
 *
 * @covers \BuddyNext\Profile\ProfileService::get_profile
 * @covers \BuddyNext\Profile\ProfileService::get_registration_fields
 */
class TypeRestrictionTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ProfileService
	 */
	private ProfileService $service;

	/**
	 * Create the schema + seeds and the service under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
	}

	/**
	 * Create a member type and return its id + slug.
	 *
	 * @param string $name Type name.
	 * @return array{id: int, slug: string}
	 */
	private function create_member_type( string $name ): array {
		$types = buddynext_service( 'member_types' );
		$id    = $types->create(
			array(
				'name' => $name,
				'slug' => sanitize_title( $name ),
			)
		);
		$this->assertIsInt( $id );
		$row = $types->get_by_id( $id );

		return array(
			'id'   => $id,
			'slug' => (string) $row['slug'],
		);
	}

	/**
	 * Restrict a seeded group to a member type slug (direct write — the admin
	 * handler path is exercised separately).
	 *
	 * @param string      $group_key   Group to restrict.
	 * @param string|null $restriction Member type slug or null.
	 * @return int Group id.
	 */
	private function restrict_group( string $group_key, ?string $restriction ): int {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'bn_profile_groups',
			array( 'type_restriction' => $restriction ),
			array( 'group_key' => $group_key ),
			array( '%s' ),
			array( '%s' )
		);
		wp_cache_delete( 'all_groups', 'buddynext_profiles' );
		wp_cache_delete( 'all_fields', 'buddynext_profiles' );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_profile_groups WHERE group_key = %s", $group_key )
		);
	}

	/**
	 * Return the group_keys present in a get_profile() payload.
	 *
	 * @param int $profile_user_id Profile owner.
	 * @param int $viewer_id       Viewer.
	 * @return string[]
	 */
	private function profile_group_keys( int $profile_user_id, int $viewer_id ): array {
		$profile = $this->service->get_profile( $profile_user_id, $viewer_id );

		return array_map(
			static fn( array $g ): string => (string) $g['group_key'],
			(array) ( $profile['groups'] ?? array() )
		);
	}

	/**
	 * A restricted group is hidden on profiles of members WITHOUT the type and
	 * present on profiles of members WITH it; values are retained either way.
	 *
	 * @return void
	 */
	public function test_restricted_group_exists_only_for_matching_member_type(): void {
		global $wpdb;

		$type = $this->create_member_type( 'Type A' );
		$this->restrict_group( 'social_links', $type['slug'] );

		$matching = self::factory()->user->create();
		$other    = self::factory()->user->create();

		// Both members hold a value in the restricted group.
		$this->service->save_profile( $matching, array( 'social_github' => 'https://github.com/a' ) );
		$this->service->save_profile( $other, array( 'social_github' => 'https://github.com/b' ) );

		buddynext_service( 'member_types' )->assign_type( $matching, $type['id'] );

		// Owner view: matching member sees the group; the other does not.
		$this->assertContains( 'social_links', $this->profile_group_keys( $matching, $matching ) );
		$this->assertNotContains( 'social_links', $this->profile_group_keys( $other, $other ) );

		// Visitor view mirrors the owner's type, not the viewer's.
		$this->assertContains( 'social_links', $this->profile_group_keys( $matching, $other ) );
		$this->assertNotContains( 'social_links', $this->profile_group_keys( $other, $matching ) );

		// Hidden, not destroyed: the non-matching member's value row is retained.
		$stored = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT v.value FROM {$wpdb->prefix}bn_profile_values v
				 INNER JOIN {$wpdb->prefix}bn_profile_fields f ON f.id = v.field_id
				 WHERE v.user_id = %d AND f.field_key = 'social_github'",
				$other
			)
		);
		$this->assertSame( 'https://github.com/b', $stored );
	}

	/**
	 * NULL / empty restriction keeps the group visible to every member — the
	 * default, so existing sites see no behaviour change.
	 *
	 * @return void
	 */
	public function test_null_restriction_means_visible_to_all(): void {
		$member = self::factory()->user->create();

		$this->restrict_group( 'social_links', null );

		$this->assertContains( 'social_links', $this->profile_group_keys( $member, $member ) );
		$this->assertContains( 'basic_info', $this->profile_group_keys( $member, $member ) );
	}

	/**
	 * A member-type change busts the cached profile so the restricted group
	 * appears/disappears on the next read (the Plugin.php hook wiring calls
	 * invalidate_profile_cache — exercised here directly).
	 *
	 * @return void
	 */
	public function test_type_change_invalidates_cached_profile(): void {
		$type = $this->create_member_type( 'Type B' );
		$this->restrict_group( 'social_links', $type['slug'] );

		$member = self::factory()->user->create();

		// Prime the owner-view cache without the type.
		$this->assertNotContains( 'social_links', $this->profile_group_keys( $member, $member ) );

		buddynext_service( 'member_types' )->assign_type( $member, $type['id'] );
		$this->service->invalidate_profile_cache( $member );

		$this->assertContains( 'social_links', $this->profile_group_keys( $member, $member ) );
	}

	/**
	 * Registration never surfaces fields from a type-restricted group — a
	 * registrant has no member type yet.
	 *
	 * @return void
	 */
	public function test_registration_fields_exclude_restricted_groups(): void {
		global $wpdb;

		// Opt a restricted-group field into registration; it must still not
		// surface there while the restriction stands.
		$type = $this->create_member_type( 'Type C' );
		$this->restrict_group( 'social_links', $type['slug'] );
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'show_on_register' => 1 ),
			array( 'field_key' => 'social_github' ),
			array( '%d' ),
			array( '%s' )
		);
		// An unrestricted control field proves the filter is group-scoped.
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'show_on_register' => 1 ),
			array( 'field_key' => 'pronouns' ),
			array( '%d' ),
			array( '%s' )
		);
		wp_cache_delete( 'all_fields', 'buddynext_profiles' );

		$reg_keys = array_map(
			static fn( array $f ): string => (string) $f['field_key'],
			$this->service->get_registration_fields()
		);

		$this->assertNotContains( 'social_github', $reg_keys );
		$this->assertContains( 'pronouns', $reg_keys );

		// Clearing the restriction restores the field at registration.
		$this->restrict_group( 'social_links', null );
		$reg_keys = array_map(
			static fn( array $f ): string => (string) $f['field_key'],
			$this->service->get_registration_fields()
		);
		$this->assertContains( 'social_github', $reg_keys );
	}

	// ── G1 description + placeholder ─────────────────────────────────────────

	/**
	 * Description and placeholder persist through create_field/update_field
	 * and surface in get_fields() + get_profile(); empty stays empty.
	 *
	 * @return void
	 */
	public function test_description_and_placeholder_persist_and_surface(): void {
		$field_id = (int) $this->service->create_field(
			array(
				'group_name'  => 'hint_group',
				'field_key'   => 'hinted',
				'label'       => 'Hinted',
				'type'        => 'text',
				'description' => 'Explain what to enter here.',
				'placeholder' => 'e.g. Example value',
			)
		);
		$this->assertGreaterThan( 0, $field_id );

		$found = null;
		foreach ( $this->service->get_fields() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( 'hinted' === $field['field_key'] ) {
					$found = $field;
				}
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( 'Explain what to enter here.', $found['description'] );
		$this->assertSame( 'e.g. Example value', $found['placeholder'] );

		// Surfaces on the profile read path (edit form + REST read).
		$member  = self::factory()->user->create();
		$profile = $this->service->get_profile( $member, $member );
		$hinted  = null;
		foreach ( (array) $profile['fields'] as $field ) {
			if ( 'hinted' === ( $field['field_key'] ?? '' ) ) {
				$hinted = $field;
			}
		}
		$this->assertNotNull( $hinted );
		$this->assertSame( 'Explain what to enter here.', $hinted['description'] );
		$this->assertSame( 'e.g. Example value', $hinted['placeholder'] );

		// The rendered input carries the placeholder attribute.
		$html = \BuddyNext\Profile\FieldType::render_input( $hinted, '', 'hinted' );
		$this->assertStringContainsString( 'placeholder="e.g. Example value"', $html );

		// update_field() can change and clear both.
		$this->service->update_field(
			$field_id,
			array(
				'description' => '',
				'placeholder' => 'Changed',
			)
		);

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT description, placeholder FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $field_id ),
			ARRAY_A
		);
		$this->assertSame( '', (string) $row['description'] );
		$this->assertSame( 'Changed', (string) $row['placeholder'] );

		// A field with no hints renders no placeholder attribute at all.
		$bare_html = \BuddyNext\Profile\FieldType::render_input(
			array(
				'type'        => 'text',
				'placeholder' => '',
			),
			'',
			'bare'
		);
		$this->assertStringNotContainsString( 'placeholder=', $bare_html );
	}
}
