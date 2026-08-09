<?php
/**
 * Field visibility is a DEFAULT; group visibility is the CEILING.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * Guards the two-layer visibility model and the members-only field default.
 *
 * The model, and the reason it is shaped this way:
 *
 *   GROUP visibility  = the owner's ceiling. Binds everything inside it.
 *   FIELD visibility  = where a value STARTS. The member's own choice replaces it.
 *
 * Before this, the field was a floor as well, so a member could only ever tighten.
 * That made "members-only by default, public if the member asks" impossible to
 * express: a members-only field default would have removed Public from the picker
 * and locked every member out of publishing their own value. Owner decision
 * 2026-08-09 requires BOTH halves, so the field had to become a default.
 *
 * Nothing here is retroactive. Existing sites keep the field visibilities they
 * already have; only newly seeded and newly created fields start members-only.
 *
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 */
final class FieldVisibilityDefaultTest extends WP_UnitTestCase {

	/**
	 * Reset caches between cases.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_cache_flush();
	}

	/**
	 * Field keys an anonymous viewer can read off a profile.
	 *
	 * Read through get_profile() with viewer 0 — the same call the public profile
	 * template, the REST read and the directory make — so this asserts what a
	 * logged-out visitor (and a crawler) actually receives.
	 *
	 * @param int $user_id Profile owner.
	 * @return string[]
	 */
	private function anonymous_keys( int $user_id ): array {
		$out = array();
		foreach ( (array) ( ( new ProfileService() )->get_profile( $user_id, 0 )['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				// Multi-value types hand back an array; a bare string cast would raise
				// "Array to string conversion" and error the case instead of asserting.
				$raw = $field['value'] ?? '';
				if ( '' !== ( is_array( $raw ) ? implode( ',', $raw ) : (string) $raw ) ) {
					$out[] = (string) $field['field_key'];
				}
			}
		}

		return $out;
	}

	/**
	 * Create a field in its own group so the ceiling is known.
	 *
	 * @param string $key             Field key.
	 * @param array  $overrides       Field args to override.
	 * @return string The group key used.
	 */
	private function make_field( string $key, array $overrides = array() ): string {
		$group_key = 'vis_' . $key;

		( new ProfileService() )->create_field(
			array_merge(
				array(
					'field_key'  => $key,
					'label'      => ucfirst( $key ),
					'type'       => 'text',
					'group_name' => $group_key,
					'sort_order' => 1,
				),
				$overrides
			)
		);

		return $group_key;
	}

	/**
	 * A field created without an explicit visibility starts members-only.
	 *
	 * Asserted on the SERVICE, not the admin screen, because a field also arrives
	 * from the REST route, an import and WP-CLI. A default that only lives in a
	 * form is not a default.
	 *
	 * @return void
	 */
	public function test_a_new_field_defaults_to_members_only(): void {
		$this->make_field( 'probe_default' );

		$found = null;
		foreach ( ( new ProfileService() )->get_flat_fields() as $field ) {
			if ( 'probe_default' === ( $field['field_key'] ?? '' ) ) {
				$found = $field;
			}
		}

		$this->assertNotNull( $found, 'the fixture field must exist.' );
		$this->assertSame( 'members', $found['visibility'], 'a new field must start members-only.' );
	}

	/**
	 * An untouched value on a members-only field never reaches a logged-out visitor.
	 *
	 * @return void
	 */
	public function test_untouched_value_on_a_members_field_is_not_public(): void {
		$this->make_field( 'probe_hidden' );

		$user    = self::factory()->user->create();
		$service = new ProfileService();
		$service->save_profile( $user, array( 'probe_hidden' => 'Testville' ) );
		$service->invalidate_profile_cache( $user );
		wp_cache_flush();

		$this->assertNotContains( 'probe_hidden', $this->anonymous_keys( $user ) );
	}

	/**
	 * The member can still publish it — the half that makes the default acceptable.
	 *
	 * This is the case the old field-as-floor model made impossible, and it is why
	 * clamp_visibility() now clamps to the group rather than the field.
	 *
	 * @return void
	 */
	public function test_the_member_can_publish_a_members_only_field(): void {
		$this->make_field( 'probe_optin' );

		$user    = self::factory()->user->create();
		$service = new ProfileService();
		$service->save_profile(
			$user,
			array(
				'probe_optin'               => 'Testville',
				'probe_optin__visibility'   => 'public',
			)
		);
		$service->invalidate_profile_cache( $user );
		wp_cache_flush();

		$this->assertContains(
			'probe_optin',
			$this->anonymous_keys( $user ),
			'a member must be able to opt their own field into public.'
		);
	}

	/**
	 * An owner who sets a field to Public gets a public field.
	 *
	 * The regression that killed the previous design: the site-wide default
	 * overrode the field, so an explicitly public field was not public and the
	 * owner had no way to publish anything.
	 *
	 * @return void
	 */
	public function test_an_explicitly_public_field_is_public(): void {
		$this->make_field( 'probe_public', array( 'visibility' => 'public' ) );

		$user    = self::factory()->user->create();
		$service = new ProfileService();
		$service->save_profile( $user, array( 'probe_public' => 'Testville' ) );
		$service->invalidate_profile_cache( $user );
		wp_cache_flush();

		$this->assertContains( 'probe_public', $this->anonymous_keys( $user ) );
	}

	/**
	 * The GROUP is a hard ceiling: a member cannot loosen past it.
	 *
	 * With the field demoted to a default, this is the only place an owner can
	 * enforce a floor — so it has to actually hold against a crafted request, not
	 * just against the picker's option list.
	 *
	 * @return void
	 */
	public function test_a_member_cannot_loosen_past_the_group_ceiling(): void {
		$group_key = $this->make_field( 'probe_ceiling', array( 'visibility' => 'members' ) );

		// Tighten the GROUP after the fact — create_field() makes the group public.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_groups',
			array( 'visibility' => 'members' ),
			array( 'group_key' => $group_key ),
			array( '%s' ),
			array( '%s' )
		);
		wp_cache_flush();

		$user    = self::factory()->user->create();
		$service = new ProfileService();
		$service->save_profile(
			$user,
			array(
				'probe_ceiling'             => 'Testville',
				'probe_ceiling__visibility' => 'public',
			)
		);
		$service->invalidate_profile_cache( $user );
		wp_cache_flush();

		$this->assertNotContains(
			'probe_ceiling',
			$this->anonymous_keys( $user ),
			'the group ceiling must beat a member asking for public.'
		);
	}

	/**
	 * The owner always sees their own value, whatever the default.
	 *
	 * @return void
	 */
	public function test_the_owner_sees_their_own_value(): void {
		$this->make_field( 'probe_owner' );

		$user    = self::factory()->user->create();
		$service = new ProfileService();
		$service->save_profile( $user, array( 'probe_owner' => 'Testville' ) );
		$service->invalidate_profile_cache( $user );
		wp_cache_flush();

		$own = array();
		foreach ( (array) ( $service->get_profile( $user, $user )['groups'] ?? array() ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				$raw = $field['value'] ?? '';
				if ( '' !== ( is_array( $raw ) ? implode( ',', $raw ) : (string) $raw ) ) {
					$own[] = (string) $field['field_key'];
				}
			}
		}

		$this->assertContains( 'probe_owner', $own, 'a member must always see what they typed.' );
	}

	/**
	 * Display and the public search mirror agree.
	 *
	 * get_profile() used to re-implement the visibility ladder inline, reading a
	 * NULL entry visibility as `public` while effective_visibility() skipped it.
	 * The copies matched only while everything shipped public. Both now call one
	 * method, and this asserts they reach the same answer — the property that
	 * broke silently the moment a non-public default existed.
	 *
	 * @return void
	 */
	public function test_display_and_search_mirror_agree(): void {
		$this->make_field( 'probe_mirror', array( 'is_searchable' => 1 ) );

		$user    = self::factory()->user->create();
		$service = new ProfileService();
		$service->save_profile( $user, array( 'probe_mirror' => 'Testville' ) );
		$service->invalidate_profile_cache( $user );
		wp_cache_flush();

		$visible_anonymously = in_array( 'probe_mirror', $this->anonymous_keys( $user ), true );
		$in_public_mirror    = '' !== (string) get_user_meta( $user, 'bn_field_probe_mirror', true );

		$this->assertSame(
			$visible_anonymously,
			$in_public_mirror,
			'what a logged-out visitor reads and what the public search mirror holds must be one decision.'
		);
		$this->assertFalse( $in_public_mirror, 'and on a members-only default, neither.' );
	}
}
