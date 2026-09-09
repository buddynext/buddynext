<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A promoted community moderator must be able to moderate — at the SERVICE seam.
 *
 * Card 10264294189: the REST routes were opened to community moderators, but the
 * moderation service methods still hard-gated on manage_options, so a promoted
 * moderator passed the route and was refused one layer down (403). These lock in
 * the authorization at the seam: a site moderator can act, a plain member cannot,
 * and the heaviest sanction (an INDEFINITE suspension) stays admin-only.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Moderation\ModerationService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Moderation\ModerationService
 */
class ModeratorAuthorizationTest extends WP_UnitTestCase {

	/** @var ModerationService */
	private ModerationService $mod;

	/** @var int A promoted site community moderator (no manage_options). */
	private int $moderator;

	/** @var int A plain member. */
	private int $member;

	/** @var int The user being actioned. */
	private int $victim;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->mod       = new ModerationService();
		$this->moderator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->victim    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		buddynext_service( 'roles' )->set_role( $this->moderator, 'moderator' );
	}

	/**
	 * A site moderator can issue a strike; a plain member cannot.
	 *
	 * @return void
	 */
	public function test_site_moderator_can_issue_a_strike_member_cannot(): void {
		$this->assertIsInt( $this->mod->issue_strike( $this->victim, $this->moderator, 'x' ), 'A site moderator may issue a strike.' );
		$this->assertWPError( $this->mod->issue_strike( $this->victim, $this->member, 'x' ), 'A plain member may not issue a strike.' );
	}

	/**
	 * A site moderator can shadow-ban; a plain member cannot.
	 *
	 * @return void
	 */
	public function test_site_moderator_can_shadow_ban_member_cannot(): void {
		$this->assertTrue( $this->mod->shadow_ban( $this->victim, $this->moderator, 'x' ), 'A site moderator may shadow-ban.' );
		$this->assertWPError( $this->mod->shadow_ban( $this->victim, $this->member, 'x' ), 'A plain member may not shadow-ban.' );
	}

	/**
	 * A site moderator can suspend for a FIXED term, but only an admin may suspend
	 * INDEFINITELY.
	 *
	 * @return void
	 */
	public function test_fixed_suspension_is_moderator_indefinite_is_admin_only(): void {
		$this->assertIsInt(
			$this->mod->suspend_user( $this->victim, $this->moderator, 'x', array( 'duration_days' => 7 ) ),
			'A site moderator may suspend for a fixed duration.'
		);

		$this->mod->unsuspend_user( $this->victim, $this->moderator );

		$this->assertWPError(
			$this->mod->suspend_user( $this->victim, $this->moderator, 'x', array() ),
			'A site moderator may NOT suspend indefinitely.'
		);

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt(
			$this->mod->suspend_user( $this->victim, $admin, 'x', array() ),
			'An administrator may suspend indefinitely.'
		);
	}
}
