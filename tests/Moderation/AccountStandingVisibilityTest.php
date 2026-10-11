<?php
/**
 * Who may read a member's account standing on their profile.
 *
 * Strikes, a suspension reason and shadow-ban state are between a member and
 * the people who run the site. Running one space does not make someone staff.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\Spaces\SpaceService;

/**
 * Account-standing visibility tests.
 *
 * @covers \BuddyNext\Moderation\ModerationService::account_status_for
 */
class AccountStandingVisibilityTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ModerationService
	 */
	private ModerationService $moderation;

	/**
	 * The member whose profile is viewed.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->moderation = new ModerationService();
		$this->member     = self::factory()->user->create();
	}

	/**
	 * A member who owns a space is not shown another member's standing.
	 *
	 * @return void
	 */
	public function test_space_owner_does_not_see_standing(): void {
		$owner    = self::factory()->user->create();
		$space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Book Club',
				'slug' => 'book-club-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$this->assertIsInt( $space_id );
		wp_set_current_user( $owner );

		$this->assertNull( $this->moderation->account_status_for( $this->member, $owner ) );
	}

	/**
	 * Site staff see it, with the shadow-ban field.
	 *
	 * @return void
	 */
	public function test_admin_and_site_moderator_see_standing(): void {
		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$moderator = self::factory()->user->create();
		buddynext_service( 'roles' )->set_role( $moderator, 'moderator' );

		foreach ( array( $admin, $moderator ) as $viewer ) {
			wp_set_current_user( $viewer );
			$status = $this->moderation->account_status_for( $this->member, $viewer );
			$this->assertIsArray( $status );
			$this->assertSame( 'moderator', $status['scope'] );
			$this->assertArrayHasKey( 'is_shadow_banned', $status );
		}
	}

	/**
	 * An ordinary member and a logged-out visitor see nothing.
	 *
	 * @return void
	 */
	public function test_member_and_visitor_do_not_see_standing(): void {
		$other = self::factory()->user->create();
		wp_set_current_user( $other );
		$this->assertNull( $this->moderation->account_status_for( $this->member, $other ) );

		wp_set_current_user( 0 );
		$this->assertNull( $this->moderation->account_status_for( $this->member, 0 ) );
	}
}
