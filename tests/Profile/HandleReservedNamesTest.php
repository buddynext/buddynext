<?php
/**
 * Reserved handles: one two-tier list enforced at every door, plus the locked mention contract.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Auth\RegistrationService;
use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\Handle;

/**
 * REGRESSION (card 10512059262): the reserved list held 7 route words and was
 * never checked at registration, so support@gmail.com became @support and a
 * typed username "administrator" was accepted.
 *
 * @covers \BuddyNext\Core\PageRouter::reserved_profile_slugs
 * @covers \BuddyNext\Core\PageRouter::is_reserved_profile_slug
 * @covers \BuddyNext\Core\PageRouter::is_slug_available
 * @covers \BuddyNext\Profile\Handle::set
 * @covers \BuddyNext\Auth\RegistrationService::unique_login
 */
class HandleReservedNamesTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema, open registration.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );
		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
	}

	/**
	 * Impersonation words block members; route words block everyone.
	 */
	public function test_two_tiers(): void {
		$member = self::factory()->user->create();

		foreach ( array( 'support', 'administrator', 'moderator', 'staff', 'help' ) as $word ) {
			$this->assertFalse( PageRouter::is_slug_available( $word, $member ), "a member may not take @$word" );
			$this->assertTrue( PageRouter::is_slug_available( $word, $member, true ), "an administrator may assign @$word" );
		}
		foreach ( array( 'me', 'edit', 'settings', 'search', 'page' ) as $word ) {
			$this->assertFalse( PageRouter::is_slug_available( $word, $member, true ), "@$word is a route: blocked for everyone" );
		}
		$this->assertTrue( PageRouter::is_slug_available( 'jane-doe', $member ) );
	}

	/**
	 * The filter extends the list and knows which tier is asking.
	 */
	public function test_filter_extends_the_list(): void {
		$seen = array();
		add_filter(
			'buddynext_reserved_profile_slugs',
			static function ( array $reserved, bool $is_admin ) use ( &$seen ): array {
				$seen[]     = $is_admin;
				$reserved[] = 'Acme';
				return $reserved;
			},
			10,
			2
		);
		$this->assertTrue( PageRouter::is_reserved_profile_slug( 'acme' ) );
		$this->assertTrue( PageRouter::is_reserved_profile_slug( 'ACME', true ) );
		$this->assertSame( array( false, true ), $seen );
	}

	/**
	 * Handle::set refuses a newly claimed reserved word but lets a holder keep theirs.
	 */
	public function test_handle_set_gates_claims_not_holders(): void {
		$member = self::factory()->user->create();
		$this->assertWPError( Handle::set( $member, 'support' ) );
		$this->assertTrue( Handle::set( $member, 'support', true ), 'admin assignment' );

		// A legacy holder (nicename already "staff") re-saving their own handle.
		$holder = self::factory()->user->create( array( 'user_login' => 'staff' ) );
		$this->assertSame( 'staff', get_userdata( $holder )->user_nicename );
		$this->assertTrue( Handle::set( $holder, 'staff' ) );
	}

	/**
	 * The email-derived login never lands on a reserved word.
	 */
	public function test_email_derived_login_skips_reserved_words(): void {
		$this->assertSame( 'support2', RegistrationService::unique_login( 'support@example.com' ) );
		$this->assertSame( 'me2', RegistrationService::unique_login( 'me@example.com' ) );
		$this->assertSame( 'jane-doe', RegistrationService::unique_login( 'jane.doe@example.com' ) );
	}

	/**
	 * A typed reserved username is refused on the REST form, inline on the field.
	 */
	public function test_rest_register_refuses_a_reserved_username(): void {
		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/auth/register' );
		$request->set_param( 'email', 'pose@example.com' );
		$request->set_param( 'password', 'a-long-password-1' );
		$request->set_param( 'user_login', 'administrator' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'user_login', (array) ( $response->get_data()['data']['fields'] ?? array() ) );
		$this->assertFalse( email_exists( 'pose@example.com' ) );
	}

	/**
	 * Locked mention contract: the charset stays a-zA-Z0-9_- (no dot), and these
	 * eight sentence shapes resolve exactly like this. Adding "." would turn
	 * "Thanks @john." into a mention of "john." (nobody), see group1-auth-infra.
	 *
	 * @dataProvider mention_rows
	 *
	 * @param string      $text     What a member types.
	 * @param string|null $expected Captured handle, or null for no mention.
	 */
	public function test_mention_contract( string $text, ?string $expected ): void {
		$matched = preg_match( Handle::mention_regex(), $text, $m );
		$this->assertSame( null === $expected ? 0 : 1, $matched, $text );
		if ( null !== $expected ) {
			$this->assertSame( $expected, $m[1], $text );
		}
	}

	/**
	 * The eight rows from the 1.3.0 handle audit.
	 *
	 * @return array<string, array{0:string,1:?string}>
	 */
	public function mention_rows(): array {
		return array(
			'sentence end'        => array( 'Thanks @john.', 'john' ),
			'missed space'        => array( 'cc @john.Then we ship', 'john' ),
			'file name'           => array( 'file @readme.md please', 'readme' ),
			'double dot'          => array( 'ping @john..doe', 'john' ),
			'dotted name'         => array( 'Ask @john.doe about it', 'john' ),
			'email address'       => array( 'mail first.last@example.com', null ),
			'hyphen then comma'   => array( 'see @john-doe, ok', 'john-doe' ),
			'possessive / parens' => array( "@john's post (@john)", 'john' ),
		);
	}
}
