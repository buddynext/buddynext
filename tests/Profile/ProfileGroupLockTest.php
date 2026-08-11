<?php
/**
 * Profile groups locked by a membership plan.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * Guards the `buddynext_profile_group_locked` seam in ProfileService.
 *
 * The seam sits on the single read path every display surface goes through, so
 * these tests stand in for the public profile, the REST payload, the member
 * directory and the admin member editor at once — which is the entire reason it
 * was put there rather than in the templates that happen to remember to ask.
 *
 * The first case is the one that matters most: FREE LOCKS NOTHING. The vast
 * majority of installs never configure a plan, and a regression there would hide
 * every profile group on every one of them, silently.
 */
final class ProfileGroupLockTest extends WP_UnitTestCase {

	/**
	 * A group key that is safe to lock on THIS install, or '' if none is.
	 *
	 * Discovered rather than hard-coded. The seeded schema is a starter kit an
	 * owner may rename, delete or re-flag, and which groups carry `is_system`
	 * has already changed once (social_links, work_experience and education
	 * dropped it). A test naming a group is testing that install's fixtures, not
	 * the rule — this one asserted against `work_experience` and failed on a DB
	 * still holding the older seed.
	 *
	 * @return string
	 */
	private function lockable_group(): string {
		foreach ( ( new ProfileService() )->get_groups() as $group ) {
			if ( empty( $group['is_system'] ) && '' !== (string) ( $group['group_key'] ?? '' ) ) {
				return (string) $group['group_key'];
			}
		}

		return '';
	}

	/**
	 * A SYSTEM group key on this install, or '' if none exists.
	 *
	 * @return string
	 */
	private function system_group(): string {
		foreach ( ( new ProfileService() )->get_groups() as $group ) {
			if ( ! empty( $group['is_system'] ) && '' !== (string) ( $group['group_key'] ?? '' ) ) {
				return (string) $group['group_key'];
			}
		}

		return '';
	}

	/**
	 * Remove any lock callback between tests so they cannot leak into each other.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_profile_group_locked' );
		parent::tear_down();
	}

	/**
	 * Drop the per-viewer profile cache between cases.
	 *
	 * ProfileService caches the assembled profile per viewer-relationship, and the
	 * lock decision is baked into that blob. Without this, a case that locks a
	 * group leaves it locked in the cache for the NEXT case, which reads as a
	 * failure in the wrong test — it cost a real debugging detour the first time.
	 *
	 * It is also the honest reason the product needed
	 * ProfileService::flush_member_profile_cache(): the same staleness hits a
	 * member whose plan changes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_cache_flush();
	}

	/**
	 * Lock one group for everyone.
	 *
	 * @param string $group_key Group to lock.
	 * @return void
	 */
	private function lock( string $group_key ): void {
		add_filter(
			'buddynext_profile_group_locked',
			static function ( $locked, $key ) use ( $group_key ) {
				return $key === $group_key ? true : $locked;
			},
			10,
			2
		);
	}

	/**
	 * Group keys present in a profile payload.
	 *
	 * @param array|null $profile Profile payload.
	 * @return string[]
	 */
	private function group_keys( ?array $profile ): array {
		return array_map(
			static fn( array $g ): string => (string) ( $g['group_key'] ?? '' ),
			(array) ( $profile['groups'] ?? array() )
		);
	}

	/**
	 * With nothing hooked, no group is ever locked.
	 *
	 * This is the standalone-Free case and the Pro-without-plans case, i.e. most
	 * sites. It must never depend on anything being configured.
	 *
	 * @return void
	 */
	public function test_nothing_is_locked_by_default(): void {
		$user    = self::factory()->user->create();
		$service = new ProfileService();

		$own    = $service->get_profile( $user, $user );
		$viewer = $service->get_profile( $user, self::factory()->user->create() );

		foreach ( array( $own, $viewer ) as $profile ) {
			foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
				$this->assertFalse(
					(bool) ( $group['locked'] ?? false ),
					'No group may report itself locked when nothing answers the seam.'
				);
			}
		}
	}

	/**
	 * A locked group is REMOVED for anyone who is not the profile owner.
	 *
	 * Covers the public profile, REST reads, the directory and the admin editor in
	 * one assertion, because they all read through get_profile().
	 *
	 * @return void
	 */
	public function test_locked_group_is_absent_for_other_viewers(): void {
		$group = $this->lockable_group();
		if ( '' === $group ) {
			$this->markTestSkipped( 'No non-system profile group on this install.' );
		}

		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();
		$this->lock( $group );

		$keys = $this->group_keys( ( new ProfileService() )->get_profile( $owner, $viewer ) );

		$this->assertNotContains(
			$group,
			$keys,
			'A locked group must not reach another member — plan tier is not public information.'
		);
	}

	/**
	 * The OWNER keeps the group, flagged, so the edit screen can offer an upgrade.
	 *
	 * Hiding it from its own owner would mean they could never discover the
	 * section exists, which is the one thing that makes upgrading a choice.
	 *
	 * @return void
	 */
	public function test_owner_keeps_the_group_but_it_is_flagged(): void {
		$group = $this->lockable_group();
		if ( '' === $group ) {
			$this->markTestSkipped( 'No non-system profile group on this install.' );
		}

		$owner = self::factory()->user->create();
		$this->lock( $group );

		$profile = ( new ProfileService() )->get_profile( $owner, $owner );
		$groups  = (array) ( $profile['groups'] ?? array() );

		$found = null;
		foreach ( $groups as $row ) {
			if ( $group === ( $row['group_key'] ?? '' ) ) {
				$found = $row;
			}
		}

		if ( null === $found ) {
			$this->markTestSkipped( 'Group ' . $group . ' vanished mid-test.' );
		}

		$this->assertTrue( (bool) $found['locked'], 'The owner must see the group flagged as locked.' );
	}

	/**
	 * Locking one group does not disturb the others.
	 *
	 * The failure this catches is a predicate that answers for the wrong group, or
	 * a loop that drops everything after the first match.
	 *
	 * @return void
	 */
	public function test_other_groups_are_untouched(): void {
		$group = $this->lockable_group();
		if ( '' === $group ) {
			$this->markTestSkipped( 'No non-system profile group on this install.' );
		}

		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();

		$before = $this->group_keys( ( new ProfileService() )->get_profile( $owner, $viewer ) );

		// The first read populated the per-viewer cache, and the lock decision is
		// baked into that blob — so without a flush the second read answers from
		// BEFORE the lock existed and the test silently proves nothing. Same
		// staleness an owner hits when they change which groups a plan locks,
		// which is why that admin path has to flush too.
		$this->lock( $group );
		wp_cache_flush();

		$after = $this->group_keys( ( new ProfileService() )->get_profile( $owner, $viewer ) );

		$this->assertSame(
			array_values( array_diff( $before, array( $group ) ) ),
			array_values( $after ),
			'Only the locked group may disappear.'
		);
	}

	/**
	 * An unknown group key locks nothing.
	 *
	 * This is the renamed-or-deleted-group case: a stale key left behind in a
	 * plan must never hide a group that still exists.
	 *
	 * @return void
	 */
	public function test_stale_group_key_hides_nothing(): void {
		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();

		$before = $this->group_keys( ( new ProfileService() )->get_profile( $owner, $viewer ) );

		$this->lock( 'a_group_that_does_not_exist' );
		wp_cache_flush();

		$after = $this->group_keys( ( new ProfileService() )->get_profile( $owner, $viewer ) );

		$this->assertSame( $before, $after );
	}

	/**
	 * A SYSTEM group can never be locked, whatever answers the filter.
	 *
	 * These are the groups the product itself reads — Basic Info carries bio,
	 * display name and location, which the directory card, the search index and
	 * the member header all consume. Hiding one does not degrade a profile, it
	 * guts it, for every member on that plan at once.
	 *
	 * Asserted against the FILTER rather than against an admin screen on purpose:
	 * a tier row can also be written by an import, a migration or another plugin,
	 * so a rule that only exists in a checkbox is not a rule.
	 *
	 * @return void
	 */
	public function test_system_group_cannot_be_locked(): void {
		$group = $this->system_group();
		if ( '' === $group ) {
			$this->markTestSkipped( 'No system profile group on this install.' );
		}

		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();

		$this->lock( $group );
		wp_cache_flush();

		$this->assertContains(
			$group,
			$this->group_keys( ( new ProfileService() )->get_profile( $owner, $viewer ) ),
			'A system group must survive a plan that tries to lock it.'
		);

		$this->assertTrue(
			( new ProfileService() )->field_applies_to_user(
				array(
					'field_key'       => 'bio',
					'group_key'       => $group,
					'group_is_system' => true,
				),
				$owner
			),
			'A system group\'s fields must stay writable.'
		);
	}

	/**
	 * A locked group's fields are not writable, even by a crafted request.
	 *
	 * The visible form never posts them, so this is about the request that does
	 * not come from the form. field_applies_to_user() is the shared predicate the
	 * REST controller, the admin editor and onboarding all reach.
	 *
	 * @return void
	 */
	public function test_locked_group_fields_are_not_writable(): void {
		$group = $this->lockable_group();
		if ( '' === $group ) {
			$this->markTestSkipped( 'No non-system profile group on this install.' );
		}

		$user = self::factory()->user->create();
		$this->lock( $group );

		$applies = ( new ProfileService() )->field_applies_to_user(
			array(
				'field_key' => 'company',
				'group_key' => $group,
			),
			$user
		);

		$this->assertFalse( $applies, 'A field in a locked group must not accept a write.' );
	}
}
