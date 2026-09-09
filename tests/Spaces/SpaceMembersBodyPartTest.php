<?php
/**
 * The reusable space-members body part must be safe to render on its own.
 *
 * Card 10280272637 extracted the roster into templates/parts/space-members-body.php
 * so a second consumer (Wellbee Circles) can render the exact members UX behind its
 * own header. The RFT round-4 re-check flagged that nothing exercised the part
 * DIRECTLY — only through the space page — so a second consumer could break the
 * reuse contract without failing a test. This is that test: it renders the part
 * with nothing but context ($space_id / $viewer_id) and pins the two things a
 * consumer relies on — the roster gate is honoured, and it is driven by the passed
 * $viewer_id rather than the actual request user.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;

/**
 * Direct-render contract for parts/space-members-body.php.
 */
class SpaceMembersBodyPartTest extends \WP_UnitTestCase {

	/** @var int Owner (site admin). */
	private int $owner = 0;

	/** @var int Active member (plain subscriber). */
	private int $member = 0;

	/** @var int A subscriber who is NOT in the space. */
	private int $stranger = 0;

	/** @var int Private space id. */
	private int $space_id = 0;

	/**
	 * A private space with an owner, one active member, and an outside stranger.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$_GET = array();

		$this->owner    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$space = buddynext_service( 'spaces' )->create(
			$this->owner,
			array(
				'name' => 'Body Part',
				'slug' => 'body-part-' . wp_rand( 1000, 9999 ),
				'type' => 'private',
			)
		);
		$this->assertIsInt( $space, 'the fixture space must exist' );
		$this->space_id = (int) $space;

		// Put the member into the roster as an active member (a private-space join
		// would land as pending; the roster shows active rows).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $this->space_id,
				'user_id'  => $this->member,
				'role'     => 'member',
				'status'   => 'active',
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Render the part for a given viewer and return its HTML.
	 *
	 * @param int $viewer_id Viewer to render the roster for.
	 * @return string
	 */
	private function render_for( int $viewer_id ): string {
		ob_start();
		buddynext_get_template(
			'parts/space-members-body.php',
			array(
				'space_id'  => $this->space_id,
				'viewer_id' => $viewer_id,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * A member sees the roster grid, not the private-roster gate.
	 *
	 * @return void
	 */
	public function test_member_sees_the_roster(): void {
		$html = $this->render_for( $this->member );
		$this->assertStringContainsString( 'bn-space-members__grid', $html, 'A member gets the roster grid.' );
		$this->assertStringNotContainsString( 'Members are private', $html, 'A member does not get the gate.' );
	}

	/**
	 * A non-member is gated: the "Members are private" empty state, and the roster
	 * grid is NOT in the output (no membership leak).
	 *
	 * @return void
	 */
	public function test_non_member_is_gated_with_no_roster_leak(): void {
		$html = $this->render_for( $this->stranger );
		$this->assertStringContainsString( 'Members are private', $html, 'A non-member gets the gate.' );
		$this->assertStringNotContainsString( 'bn-space-members__grid', $html, 'The roster grid must not render for a gated viewer.' );
	}

	/**
	 * The part is driven by $viewer_id, NOT the actual request user. Rendering as
	 * the stranger (current user) but passing the member's id shows the roster;
	 * passing the stranger's id while the owner is the current user still gates.
	 * This is the contract a consumer that renders another member's roster relies
	 * on (card 10280272637 RFT round 4 — the current_user_can/is_user_logged_in
	 * mismatch).
	 *
	 * @return void
	 */
	public function test_gate_follows_viewer_id_over_current_user(): void {
		wp_set_current_user( $this->stranger );
		$this->assertStringContainsString( 'bn-space-members__grid', $this->render_for( $this->member ), 'viewer_id=member wins over current-user=stranger.' );

		wp_set_current_user( $this->owner );
		$this->assertStringContainsString( 'Members are private', $this->render_for( $this->stranger ), 'viewer_id=stranger wins over current-user=owner.' );
	}
}
