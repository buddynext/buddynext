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
 *
 * The first fix answered `mvs_profile_data`, which repaired get_profile() and
 * nothing else - and get_profile() is not what the screens use. Every MediaVerse
 * TEMPLATE calls has_custom_avatar() directly, so the REST payload said the
 * member had an avatar while the pages they actually looked at still asked for
 * one. These now pin the BOOLEAN filter, `mvs_has_custom_avatar`, which
 * MediaVerse also builds the payload key from - so one answer serves both and
 * they cannot drift apart again.
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

		$this->assertTrue( $this->bridge->profile_avatar_flag( false, $user_id ) );
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

		$this->assertFalse( $this->bridge->profile_avatar_flag( false, $user_id ) );
	}

	/**
	 * MediaVerse's own `true` is never overwritten. It is authoritative for its
	 * own store; this only adds the source it cannot see.
	 *
	 * @return void
	 */
	public function test_mediaverses_own_true_is_preserved(): void {
		$user_id = (int) self::factory()->user->create();

		$this->assertTrue( $this->bridge->profile_avatar_flag( true, $user_id ) );
	}

	/**
	 * A bad user id passes through untouched rather than fataling inside another
	 * plugin's filter.
	 *
	 * @return void
	 */
	public function test_a_bad_user_id_passes_through(): void {
		$this->assertFalse( $this->bridge->profile_avatar_flag( false, 0 ) );
		$this->assertTrue( $this->bridge->profile_avatar_flag( true, -1 ) );
	}

	/**
	 * The bridge is registered on the boolean filter, not the profile payload.
	 *
	 * This is the regression the card came back for: answering `mvs_profile_data`
	 * left every template that calls has_custom_avatar() directly still wrong, so
	 * the seam itself is what has to be pinned, not just the return value.
	 *
	 * @return void
	 */
	public function test_the_bridge_answers_the_boolean_filter(): void {
		$bridge = new WPMediaVerseBridge();
		$bridge->init();

		$this->assertNotFalse(
			has_filter( 'mvs_has_custom_avatar', array( $bridge, 'profile_avatar_flag' ) ),
			'BuddyNext must answer mvs_has_custom_avatar - the seam every MediaVerse caller goes through.'
		);
		$this->assertFalse(
			has_filter( 'mvs_profile_data', array( $bridge, 'profile_avatar_flag' ) ),
			'The profile payload is the wrong seam: it fixes get_profile() and leaves the templates wrong.'
		);
	}
}
