<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The @handle we publish must be the one that actually resolves.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\MemberDirectoryController;
use BuddyNext\Profile\MemberDirectoryService;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * A member picks their own mention, and the API keeps handing out the old one.
 *
 * BuddyNext invites a member to choose their own mention / username (bn_profile_slug), and
 * PageRouter::profile_url() builds /members/{bn_profile_slug ?: user_nicename}/ from it.
 * PageRouter::resolve_user() then looks the member up with get_user_by( 'slug', ... ) - the
 * NICENAME, and only the nicename. It never falls back to user_login.
 *
 * The member directory published `handle` => user_login. A different field. On a fresh install
 * the two coincide, so nothing looks wrong and the bug is invisible.
 *
 * Then a member picks @aisha-khan. The API still hands out `aisha`. Every client that builds a
 * profile link or a mention from `handle` - which is precisely what the app has to do, since
 * BuddyNext is REST-first - now points at /members/aisha/, which resolves to nobody. A 404, on
 * the member's own profile link, because we published a credential where the public slug goes.
 *
 * @covers \BuddyNext\Profile\MemberDirectoryController
 */
class MemberHandleResolvesTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * The shaped directory item for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    Display name.
	 * @return array<string,mixed>
	 */
	private function shape( int $user_id, string $name ): array {
		$controller = new MemberDirectoryController();
		$method     = new ReflectionMethod( $controller, 'shape_item' );
		$method->setAccessible( true );

		return (array) $method->invoke(
			$controller,
			array(
				'user_id'      => $user_id,
				'display_name' => $name,
			),
			0
		);
	}

	/**
	 * The published handle resolves to the member, once they have chosen their own mention.
	 *
	 * @return void
	 */
	public function test_handle_resolves_after_the_member_picks_their_own_mention(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login'   => 'aisha',
				'display_name' => 'Aisha Khan',
			)
		);

		// The member does what the product invites them to do: picks their own mention.
		wp_update_user(
			array(
				'ID'            => $user_id,
				'user_nicename' => 'aisha-khan',
			)
		);
		clean_user_cache( $user_id );

		$handle = (string) ( $this->shape( $user_id, 'Aisha Khan' )['handle'] ?? '' );

		$this->assertSame(
			'aisha-khan',
			$handle,
			'The API published the login instead of the mention the member chose.'
		);

		$resolved = get_user_by( 'slug', $handle );

		$this->assertInstanceOf(
			\WP_User::class,
			$resolved,
			"The published handle '{$handle}' does not resolve to any member. A client that builds a profile link or a mention from it - which is what the app does - gets a 404."
		);
		$this->assertSame( $user_id, $resolved->ID );
	}

	/**
	 * A custom bn_profile_slug wins, exactly as it does for the profile URL.
	 *
	 * @return void
	 */
	public function test_a_custom_profile_slug_is_the_handle(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'marcus' ) );

		update_user_meta( $user_id, 'bn_profile_slug', 'marcus-obrien' );
		wp_update_user(
			array(
				'ID'            => $user_id,
				'user_nicename' => 'marcus-obrien',
			)
		);
		clean_user_cache( $user_id );

		$this->assertSame(
			'marcus-obrien',
			(string) ( $this->shape( $user_id, 'Marcus' )['handle'] ?? '' ),
			'A member with a custom profile slug is still published under their login.'
		);
	}

	/**
	 * The handle and the profile URL never disagree. One slug, one source of truth.
	 *
	 * @return void
	 */
	public function test_the_handle_and_the_profile_url_agree(): void {
		$user_id = self::factory()->user->create( array( 'user_login' => 'sara' ) );

		wp_update_user(
			array(
				'ID'            => $user_id,
				'user_nicename' => 'sara-lindqvist',
			)
		);
		clean_user_cache( $user_id );

		$handle = (string) ( $this->shape( $user_id, 'Sara' )['handle'] ?? '' );
		$url    = PageRouter::profile_url( $user_id );

		$this->assertStringContainsString(
			'/' . $handle . '/',
			$url,
			"The handle we publish ({$handle}) is not the slug the profile URL uses ({$url}). One of them is wrong, and a client cannot tell which."
		);
	}

	/**
	 * PageRouter::member_handle is the one public-handle source: nicename, never login.
	 *
	 * Every @handle surface (member cards, space member lists, the profile hero, the
	 * online-now sidebar) resolves through this. Pointing any of them back at
	 * user_login re-opens the credential leak.
	 *
	 * @covers \BuddyNext\Core\PageRouter::member_handle
	 * @return void
	 */
	public function test_member_handle_is_the_public_slug_never_the_login(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login'   => 'raw_login_value',
				'display_name' => 'Nadia Rahman',
			)
		);
		wp_update_user(
			array(
				'ID'            => $user_id,
				'user_nicename' => 'nadia-rahman',
			)
		);
		clean_user_cache( $user_id );

		$this->assertSame( 'nadia-rahman', PageRouter::member_handle( $user_id ), 'member_handle must return user_nicename, never user_login.' );
		$this->assertNotSame( 'raw_login_value', PageRouter::member_handle( $user_id ), 'member_handle leaked user_login.' );

		update_user_meta( $user_id, 'bn_profile_slug', 'nadia' );
		$this->assertSame( 'nadia', PageRouter::member_handle( $user_id ), 'A custom bn_profile_slug must win over the nicename.' );

		$this->assertSame( '', PageRouter::member_handle( 0 ), 'An invalid user id must yield an empty handle.' );
	}

	/**
	 * The "Online now" sidebar (SSR) publishes the public slug, never user_login.
	 *
	 * The REST directory was already fixed to publish the nicename-derived handle;
	 * this pins the SSR twin (MemberDirectoryService::online_now()) so the credential
	 * can never leak back onto the public members-directory sidebar. Reverting the
	 * query/row back to user_login fails this test by name.
	 *
	 * @covers \BuddyNext\Profile\MemberDirectoryService::online_now
	 * @return void
	 */
	public function test_online_now_publishes_the_public_handle_not_user_login(): void {
		global $wpdb;

		$user_id = self::factory()->user->create(
			array(
				'user_login'   => 'secret_login_name',
				'display_name' => 'Priya Nair',
			)
		);
		wp_update_user(
			array(
				'ID'            => $user_id,
				'user_nicename' => 'priya-nair',
			)
		);
		clean_user_cache( $user_id );

		// Mark the member active inside the online window.
		$wpdb->replace(
			$wpdb->prefix . 'bn_presence',
			array(
				'user_id'     => $user_id,
				'last_active' => time(),
			),
			array( '%d', '%d' )
		);

		$mine = null;
		foreach ( ( new MemberDirectoryService() )->online_now( 0, 6 ) as $row ) {
			if ( (int) $row['ID'] === $user_id ) {
				$mine = $row;
				break;
			}
		}

		$this->assertNotNull( $mine, 'The active member did not appear in the Online now sidebar.' );
		$this->assertSame( 'priya-nair', $mine['handle'], 'The Online now sidebar published the wrong handle - it must be user_nicename, not user_login.' );
		$this->assertArrayNotHasKey( 'user_login', $mine, 'The Online now sidebar row still carries a user_login key.' );
		$this->assertStringNotContainsString(
			'secret_login_name',
			(string) wp_json_encode( $mine ),
			'The credential user_login leaked into the Online now sidebar row.'
		);
	}
}
