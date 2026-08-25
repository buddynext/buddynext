<?php
/**
 * A space moderator's warn authority is scoped to their space: they may warn a
 * member OF that space, not any user id they name. Site admins are unscoped.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::warn
 */
class WarnAuthorityScopeTest extends \WP_UnitTestCase {

	private ModerationService $moderation;
	private SpaceService $spaces;
	private SpaceMemberService $members;
	private int $owner    = 0;
	private int $mod      = 0;
	private int $member   = 0;
	private int $outsider = 0;
	private int $admin    = 0;
	private int $space_id = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->moderation = new ModerationService();
		$this->spaces     = new SpaceService();
		$this->members    = new SpaceMemberService();

		$this->owner    = (int) self::factory()->user->create();
		$this->mod      = (int) self::factory()->user->create();
		$this->member   = (int) self::factory()->user->create();
		$this->outsider = (int) self::factory()->user->create();
		$this->admin    = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->space_id = (int) $this->spaces->create(
			$this->owner,
			array(
				'name' => 'Warn Scope Space',
				'slug' => 'warn-scope-space',
				'type' => 'open',
			)
		);
		$this->members->join( $this->space_id, $this->mod );
		$this->members->join( $this->space_id, $this->member );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'role' => 'moderator' ),
			array(
				'space_id' => $this->space_id,
				'user_id'  => $this->mod,
			)
		);
		wp_cache_flush();
	}

	public function test_space_moderator_cannot_warn_a_non_member(): void {
		$result = $this->moderation->warn( $this->outsider, $this->mod, 'off-topic', $this->space_id );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_space_moderator_can_warn_a_member_of_their_space(): void {
		$this->assertTrue(
			$this->moderation->warn( $this->member, $this->mod, 'off-topic', $this->space_id )
		);
	}

	public function test_site_admin_is_unscoped(): void {
		$this->assertTrue(
			$this->moderation->warn( $this->outsider, $this->admin, 'sitewide', 0 )
		);
	}
}
