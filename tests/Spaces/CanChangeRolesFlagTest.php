<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing -- concise, self-describing test methods and fixtures.
/**
 * The app learns who may make or remove a moderator from the same check the
 * role route enforces (card 10417332335). Without the flag an app client showed
 * Make moderator to a space moderator, and every tap failed with a 403.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Spaces\SpaceController::get_space
 */
class CanChangeRolesFlagTest extends \WP_Test_REST_TestCase {

	private int $owner;
	private int $moderator;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner     = self::factory()->user->create();
		$this->moderator = self::factory()->user->create();
		$this->space_id  = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Roles room',
				'slug' => 'roles-room',
				'type' => 'open',
			)
		);
		$members = new SpaceMemberService();
		$members->join( $this->space_id, $this->moderator );
		$this->assertTrue( $members->change_role( $this->space_id, $this->moderator, 'moderator', $this->owner ) );
	}

	private function flag_for( int $viewer ): bool {
		wp_set_current_user( $viewer );
		$data = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/spaces/{$this->space_id}" ) )->get_data();
		$this->assertArrayHasKey( 'can_change_roles', $data );
		return (bool) $data['can_change_roles'];
	}

	public function test_owner_may_change_roles(): void {
		$this->assertTrue( $this->flag_for( $this->owner ) );
	}

	public function test_space_moderator_may_not(): void {
		$this->assertFalse( $this->flag_for( $this->moderator ) );
	}

	public function test_site_admin_may(): void {
		$this->assertTrue( $this->flag_for( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
	}
}
