<?php
/**
 * Every sign-in path takes the same names: email, login, current handle.
 *
 * BuddyNext's form and the app went through the mention resolver, which also answers to
 * user-{id} and old handles; wp-login.php refused both.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\Handle;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Profile\Handle::user_for_sign_in
 * @covers \BuddyNext\Auth\AuthController::login
 */
class HandleSignInTest extends WP_UnitTestCase {

	private const PASSWORD = 'correct horse battery';

	private int $member;

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		$this->member = self::factory()->user->create(
			array(
				'user_login' => 'jane.doe',
				'user_email' => 'jane.doe@example.test',
				'user_pass'  => self::PASSWORD,
			)
		);
		update_user_meta( $this->member, 'bn_onboarding_complete', '1' );
	}


	private function core_signs_in( string $name ): bool {
		return wp_authenticate( $name, self::PASSWORD ) instanceof \WP_User;
	}

	public function test_names_accepted_on_every_path(): void {
		$handle = get_userdata( $this->member )->user_nicename;
		foreach ( array( 'jane.doe', 'jane.doe@example.test', $handle, '@' . $handle ) as $name ) {
			$this->assertSame( $this->member, Handle::user_for_sign_in( $name )->ID, $name );
			$this->assertTrue( $this->core_signs_in( $name ), 'wp-login: ' . $name );
		}
	}

	public function test_reserved_id_and_old_handle_are_refused_everywhere(): void {
		$old = get_userdata( $this->member )->user_nicename;
		$this->assertNotInstanceOf( \WP_Error::class, Handle::set( $this->member, 'janedesigns' ) );

		foreach ( array( 'user-' . $this->member, $old, '@' . $old ) as $name ) {
			$this->assertNull( Handle::user_for_sign_in( $name ), $name );
			$this->assertFalse( $this->core_signs_in( $name ), 'wp-login: ' . $name );
		}
		$this->assertSame( $this->member, Handle::user_for_sign_in( 'janedesigns' )->ID );
		$this->assertTrue( $this->core_signs_in( 'janedesigns' ) );

		// Mentions and URLs still follow an old handle: only sign-in narrows.
		$this->assertSame( $this->member, Handle::user_for_identifier( $old )->ID );
	}

	public function test_a_login_wins_over_another_members_handle(): void {
		$other = self::factory()->user->create( array( 'user_login' => 'someone' ) );
		wp_update_user(
			array(
				'ID'            => $other,
				'user_nicename' => 'jane-doe-2',
			)
		);
		$this->assertSame( $this->member, Handle::user_for_sign_in( 'jane.doe' )->ID );
	}
}
