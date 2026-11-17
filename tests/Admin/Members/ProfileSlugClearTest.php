<?php
/**
 * Clearing the Profile URL slug on Edit Member returns the member to the default.
 *
 * The save ignored an empty field, so a custom slug could never be removed, and
 * the hint promised a "user-{id}" default the router never used (it falls back
 * to the username). Clearing now resets the handle to the username-based
 * default through Handle::reset(), keeping the custom slug in the history.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\Handle;

/**
 * @covers \BuddyNext\Admin\Members::handle_save_member_profile
 * @covers \BuddyNext\Profile\Handle::reset
 * @covers \BuddyNext\Profile\Handle::default_for
 */
class ProfileSlugClearTest extends \WP_UnitTestCase {

	/**
	 * Fresh install and a signed-in administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clear request state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Post the edit form the way the screen does.
	 *
	 * @param int    $user_id Member.
	 * @param string $slug    Posted slug field.
	 * @return string The redirect URL.
	 */
	private function save( int $user_id, string $slug ): string {
		$_POST               = array(
			'user_id'         => (string) $user_id,
			'bn_profile_slug' => $slug,
			'_wpnonce'        => wp_create_nonce( 'bn_save_member_profile' ),
		);
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		$to                   = '';
		$stop                 = static function ( string $location ) use ( &$to ): never {
			$to = $location;
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			( new Members() )->handle_save_member_profile();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		remove_filter( 'wp_redirect', $stop );
		return $to;
	}

	public function test_clearing_the_slug_returns_the_member_to_their_username(): void {
		$member = self::factory()->user->create( array( 'user_login' => 'jane_doe' ) );
		$this->assertTrue( Handle::set( $member, 'custom-jane', true ) );

		$to = $this->save( $member, '' );

		$this->assertStringNotContainsString( 'bn_error', $to );
		$this->assertSame( '', (string) get_user_meta( $member, 'bn_profile_slug', true ), 'The custom slug is cleared.' );
		$this->assertSame( 'jane_doe', get_userdata( $member )->user_nicename );
		$this->assertStringEndsWith( '/jane_doe/', PageRouter::profile_url( $member ) );
		$this->assertSame( $member, Handle::resolve( 'custom-jane' )?->ID, 'Old links keep reaching the member.' );
	}

	public function test_clearing_is_refused_when_the_default_belongs_to_someone_else(): void {
		$member = self::factory()->user->create( array( 'user_login' => 'sam_one' ) );
		$this->assertTrue( Handle::set( $member, 'sam-custom', true ) );
		// Core lets a later account take the nicename the member moved away from.
		self::factory()->user->create(
			array(
				'user_login'    => 'other_sam',
				'user_nicename' => 'sam_one',
			)
		);

		$to = $this->save( $member, '' );

		$this->assertStringContainsString( 'bn_error=slug_default_taken', $to );
		$this->assertSame( 'sam-custom', (string) get_user_meta( $member, 'bn_profile_slug', true ) );
	}

	public function test_an_unrelated_save_leaves_a_default_handle_alone(): void {
		$member = self::factory()->user->create( array( 'user_login' => 'plain_member' ) );

		$this->save( $member, '' );

		$this->assertSame( 'plain_member', get_userdata( $member )->user_nicename );
	}

	public function test_the_default_falls_back_to_user_id_for_a_non_latin_username(): void {
		$member = self::factory()->user->create();
		$user   = get_userdata( $member );
		$user->user_login = 'дмитрий';

		$this->assertSame( 'user-' . $member, Handle::default_for( $user ) );
	}
}
