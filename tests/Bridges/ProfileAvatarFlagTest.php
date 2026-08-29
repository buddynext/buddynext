<?php
/**
 * BuddyNext answers MediaVerse's has_custom_avatar flag.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;
use WP_UnitTestCase;

/**
 * MediaVerse's profile payload disagreed with itself: `avatar` resolved to the
 * BuddyNext upload while `has_custom_avatar` stayed false, because the flag only
 * consulted MediaVerse's own avatar meta. Anything gated on it asked a member to
 * upload the avatar it was already showing them.
 */
class ProfileAvatarFlagTest extends WP_UnitTestCase {

	/**
	 * The bridge under test.
	 *
	 * @var WPMediaVerseBridge
	 */
	private WPMediaVerseBridge $bridge;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->bridge = new WPMediaVerseBridge();
	}

	/**
	 * A member with a BuddyNext-uploaded avatar is reported as having one.
	 *
	 * @return void
	 */
	public function test_a_bn_uploaded_avatar_sets_the_flag(): void {
		$user_id = (int) self::factory()->user->create();
		update_user_meta( $user_id, 'bn_avatar', 'bn-avatars/' . $user_id . '/full.webp' );

		$out = $this->bridge->profile_avatar_flag( array( 'has_custom_avatar' => false ), $user_id );

		$this->assertTrue( $out['has_custom_avatar'] );
	}

	/**
	 * A member with no avatar in either system is left alone. The flag exists to
	 * drive an "add a photo" prompt, so a false positive silences the one prompt
	 * it is for.
	 *
	 * @return void
	 */
	public function test_a_member_without_an_avatar_stays_false(): void {
		$user_id = (int) self::factory()->user->create();

		$out = $this->bridge->profile_avatar_flag( array( 'has_custom_avatar' => false ), $user_id );

		$this->assertFalse( $out['has_custom_avatar'] );
	}

	/**
	 * MediaVerse's own `true` is never overwritten. It is authoritative for its
	 * own store; this only adds the source it cannot see.
	 *
	 * @return void
	 */
	public function test_mediaverses_own_true_is_preserved(): void {
		$user_id = (int) self::factory()->user->create();

		$out = $this->bridge->profile_avatar_flag( array( 'has_custom_avatar' => true ), $user_id );

		$this->assertTrue( $out['has_custom_avatar'] );
	}

	/**
	 * A payload that is not an array, or a bad user id, passes through untouched
	 * rather than fataling inside another plugin's filter.
	 *
	 * @return void
	 */
	public function test_a_non_array_payload_passes_through(): void {
		$this->assertNull( $this->bridge->profile_avatar_flag( null, 1 ) );
		$this->assertSame( array(), $this->bridge->profile_avatar_flag( array(), 0 ) );
	}
}
