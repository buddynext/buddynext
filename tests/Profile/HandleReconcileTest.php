<?php
/**
 * Repairing the members who already carry two public identities.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\Handle;
use BuddyNext\Profile\HandleRepair;

/**
 * Handle::set() stops NEW rows diverging; it does nothing for the ones already
 * written. Before 1.1.6 the settings screen wrote bn_profile_slug alone, so a
 * member who renamed themselves has a BuddyNext handle their profile and mentions
 * use, and a stale user_nicename that core, the author archive, /wp/v2/users and
 * every partner plugin still show. Both resolve inside BuddyNext, which is exactly
 * why the split is invisible from the inside.
 *
 * Divergence is planted the way 1.1.5 produced it - a bare update_user_meta() -
 * because a row built through Handle::set() cannot reach this state at all.
 *
 * @covers \BuddyNext\Profile\HandleRepair::find_divergent
 * @covers \BuddyNext\Profile\HandleRepair::reconcile_all
 */
class HandleReconcileTest extends \WP_UnitTestCase {

	private int $member;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->member = self::factory()->user->create( array( 'user_login' => 'legacy_member' ) );
		$this->other  = self::factory()->user->create( array( 'user_login' => 'other_member' ) );
	}

	/**
	 * Plant a pre-1.1.6 row: the handle written, the nicename left behind.
	 *
	 * @param int    $user_id Member.
	 * @param string $handle  Handle the member chose.
	 * @return void
	 */
	private function plant_divergence( int $user_id, string $handle ): void {
		update_user_meta( $user_id, 'bn_profile_slug', $handle );
	}

	public function test_finds_only_rows_whose_two_identities_disagree(): void {
		$this->plant_divergence( $this->member, 'renamed-me' );

		// In step already - Handle::set() wrote both - so not a repair candidate.
		Handle::set( $this->other, 'in-step' );

		$found = ( new HandleRepair() )->find_divergent();

		$this->assertCount( 1, $found, 'Only the planted row diverges.' );
		$this->assertSame( $this->member, $found[0]['ID'] );
		$this->assertSame( 'renamed-me', $found[0]['handle'] );
		$this->assertSame( 'legacy_member', $found[0]['user_nicename'] );
	}

	public function test_members_with_no_handle_of_their_own_are_not_candidates(): void {
		// The overwhelming majority of a real roster: never renamed, no meta row.
		$this->assertSame( array(), ( new HandleRepair() )->find_divergent() );
	}

	public function test_dry_run_reports_without_writing(): void {
		$this->plant_divergence( $this->member, 'renamed-me' );

		$result = ( new HandleRepair() )->reconcile_all( true );

		$this->assertSame( 1, $result['reconciled'] );
		$this->assertSame(
			'legacy_member',
			get_userdata( $this->member )->user_nicename,
			'A dry run must leave the public URL alone.'
		);
	}

	public function test_keeping_the_handle_collapses_both_fields_and_keeps_the_old_url_alive(): void {
		$this->plant_divergence( $this->member, 'renamed-me' );

		$result = ( new HandleRepair() )->reconcile_all( false );

		$this->assertSame( 1, $result['reconciled'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 'renamed-me', get_userdata( $this->member )->user_nicename );
		$this->assertSame( 'renamed-me', get_user_meta( $this->member, 'bn_profile_slug', true ) );

		// The abandoned nicename was a working profile and author-archive URL for as
		// long as the member had two identities. Reconciling must not silently break
		// every link written against it.
		$this->assertContains( 'legacy_member', Handle::history( $this->member ) );
		$this->assertSame( $this->member, Handle::resolve( 'legacy_member' )?->ID );
		$this->assertSame( $this->member, Handle::previous_owner( 'legacy_member' )?->ID );
	}

	public function test_keeping_the_nicename_collapses_the_other_way(): void {
		$this->plant_divergence( $this->member, 'renamed-me' );

		$result = ( new HandleRepair() )->reconcile_all( false, 'nicename' );

		$this->assertSame( 1, $result['reconciled'] );
		$this->assertSame( 'legacy_member', get_userdata( $this->member )->user_nicename );
		$this->assertSame( 'legacy_member', get_user_meta( $this->member, 'bn_profile_slug', true ) );

		// The handle the member actually chose is the one being taken away here, so
		// it is the one that has to keep resolving.
		$this->assertContains( 'renamed-me', Handle::history( $this->member ) );
		$this->assertSame( $this->member, Handle::resolve( 'renamed-me' )?->ID );
	}

	public function test_a_handle_another_member_already_owns_is_skipped_not_suffixed(): void {
		// Nothing stopped two members claiming the same slug before 1.1.6: the old
		// writer never checked. Applying it now would hand this member "taken-2",
		// a handle nobody chose, on a URL they have been sharing.
		Handle::set( $this->other, 'taken' );
		$this->plant_divergence( $this->member, 'taken' );

		$result = ( new HandleRepair() )->reconcile_all( false );

		$this->assertSame( 0, $result['reconciled'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'legacy_member', get_userdata( $this->member )->user_nicename );
		$this->assertSame( 'taken', get_userdata( $this->other )->user_nicename );
		$this->assertNotEmpty( $result['skips'][0]['reason'] );
	}

	public function test_a_legacy_handle_below_the_length_floor_is_skipped_with_its_reason(): void {
		// MIN_LENGTH arrived in 1.1.6; a two-character slug predates it.
		$this->plant_divergence( $this->member, 'ab' );

		$result = ( new HandleRepair() )->reconcile_all( false );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertStringContainsString( '3', $result['skips'][0]['reason'] );
		$this->assertSame( 'legacy_member', get_userdata( $this->member )->user_nicename );
	}
}
