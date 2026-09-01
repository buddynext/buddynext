<?php
/**
 * Tests for the photo tasks in the profile-strength meter.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * A completion meter that cannot ask for a photo is not measuring completion.
 *
 * @covers \BuddyNext\Profile\ProfileService::get_strength
 * @covers \BuddyNext\Profile\AvatarService::has_custom_avatar
 */
class ProfileStrengthPhotosTest extends \WP_UnitTestCase {

	/**
	 * Install the schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Task labels for a member, mapped label => done.
	 *
	 * @param int $user_id Member.
	 * @return array<string,bool>
	 */
	private function tasks( int $user_id ): array {
		$out = array();
		foreach ( (array) ( ( new ProfileService() )->get_strength( $user_id )['tasks'] ?? array() ) as $task ) {
			$out[ (string) $task['label'] ] = (bool) $task['done'];
		}

		return $out;
	}

	/**
	 * A member with no photo is asked for one.
	 *
	 * @return void
	 */
	public function test_a_member_without_photos_is_asked_for_both(): void {
		$tasks = $this->tasks( self::factory()->user->create() );

		$this->assertArrayHasKey( 'Add a profile photo', $tasks );
		$this->assertArrayHasKey( 'Add a cover image', $tasks );
		$this->assertFalse( $tasks['Add a profile photo'] );
		$this->assertFalse( $tasks['Add a cover image'] );
	}

	/**
	 * A GENERATED avatar does not count as having added a photo.
	 *
	 * AvatarService always answers with something — initials, Gravatar, a
	 * site-wide default — so "is there an avatar_url" is always true and could
	 * never be the test. That is the trap this task has to avoid, or it can never
	 * be asked at all.
	 *
	 * @return void
	 */
	public function test_a_generated_avatar_does_not_count_as_a_photo(): void {
		$user_id = self::factory()->user->create();

		$this->assertNotSame(
			'',
			get_avatar_url( $user_id ),
			'precondition: every member always resolves to some avatar URL'
		);
		$this->assertFalse( $this->tasks( $user_id )['Add a profile photo'] );
	}

	/**
	 * An uploaded avatar completes the task.
	 *
	 * @return void
	 */
	public function test_an_uploaded_avatar_completes_the_task(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_avatar', 'https://example.test/a.jpg' );

		$this->assertTrue( $this->tasks( $user_id )['Add a profile photo'] );
	}

	/**
	 * A saved cover completes its own task, independently of the avatar.
	 *
	 * @return void
	 */
	public function test_a_cover_completes_its_own_task_only(): void {
		$user_id = self::factory()->user->create();
		buddynext_service( 'avatars' )->save_cover_url( $user_id, 'https://example.test/c.jpg' );

		$tasks = $this->tasks( $user_id );
		$this->assertTrue( $tasks['Add a cover image'] );
		$this->assertFalse( $tasks['Add a profile photo'], 'a cover is not a profile photo' );
	}

	/**
	 * An external avatar filter counts, since that is a real avatar too.
	 *
	 * @return void
	 */
	public function test_an_avatar_supplied_by_filter_counts(): void {
		$user_id = self::factory()->user->create();
		add_filter( 'buddynext_avatar_url', static fn(): string => 'https://cdn.example.test/x.png' );

		$this->assertTrue( $this->tasks( $user_id )['Add a profile photo'] );
	}

	/**
	 * The photo tasks are removable like any other, through the existing filter.
	 *
	 * @return void
	 */
	public function test_a_site_can_remove_the_photo_tasks(): void {
		add_filter(
			'buddynext_profile_strength_tasks',
			static function ( array $tasks ): array {
				return array_values(
					array_filter(
						$tasks,
						static fn( array $t ): bool => ! str_contains( (string) $t['label'], 'cover image' )
					)
				);
			}
		);

		$this->assertArrayNotHasKey( 'Add a cover image', $this->tasks( self::factory()->user->create() ) );
	}
}
