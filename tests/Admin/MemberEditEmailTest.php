<?php
/**
 * The admin Edit Member form refuses an email it cannot save.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Members;

/**
 * @covers \BuddyNext\Admin\Members
 */
class MemberEditEmailTest extends \WP_UnitTestCase {

	private int $user_id = 0;

	private function error_for( string $typed ): string {
		$this->user_id = $this->user_id ?: self::factory()->user->create( array( 'user_email' => 'kept@example.com' ) );
		$user_id       = $this->user_id;
		$_POST   = array( 'bn_user_email' => $typed );
		$check   = new \ReflectionMethod( Members::class, 'member_edit_error' );
		$code    = (string) $check->invoke( new Members(), $user_id, get_userdata( $user_id ) );
		$_POST   = array();
		return $code;
	}

	public function test_a_value_with_no_at_sign_is_refused(): void {
		$this->assertSame( 'email_invalid', $this->error_for( 'invalid-user-email' ) );
	}

	public function test_a_valid_new_address_passes(): void {
		$this->assertSame( '', $this->error_for( 'new@example.com' ) );
	}

	public function test_an_untouched_field_passes(): void {
		$this->assertSame( '', $this->error_for( 'kept@example.com' ) );
		$this->assertSame( '', $this->error_for( '' ) );
	}
}
