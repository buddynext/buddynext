<?php
/**
 * Tests for ProfileSidebarProvider — the profile surface carries context-aware
 * feature/discovery widgets (own vs other), never field-group cards.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Sidebar\Providers\ProfileSidebarProvider;
use BuddyNext\Sidebar\Surface;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Sidebar\Providers\ProfileSidebarProvider
 */
class ProfileSidebarProviderTest extends WP_UnitTestCase {

	/**
	 * Field-group card ids that must NEVER appear on the sidebar (they live in
	 * the schema-driven About tab now).
	 *
	 * @var array<int,string>
	 */
	private const REMOVED_IDS = array( 'profile-connect', 'profile-work', 'profile-education', 'profile-interests', 'profile-skills' );

	public function tear_down(): void {
		Surface::reset();
		parent::tear_down();
	}

	/**
	 * Pluck descriptor ids.
	 *
	 * @param array<int,array<string,mixed>> $widgets Descriptors.
	 * @return array<int,string>
	 */
	private function ids( array $widgets ): array {
		return array_map( static fn( array $w ): string => (string) $w['id'], $widgets );
	}

	public function test_non_profile_surface_short_circuits(): void {
		$this->assertSame( array(), ( new ProfileSidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_no_field_group_cards_ever_appear(): void {
		Surface::set(
			'profile',
			array(
				'is_own_profile' => true,
				'completion'     => array( 'score' => 40 ),
				'work_entries'   => array( array( 'x' => 1 ) ),
				'social_links'   => array( array( 'y' => 1 ) ),
				'skills'         => array( 'PHP' ),
				'member_spaces'  => array( (object) array( 'id' => 1, 'name' => 'S', 'slug' => 's', 'role' => 'member' ) ),
			)
		);
		$ids = $this->ids( ( new ProfileSidebarProvider() )->widgets( array(), 'profile' ) );
		foreach ( self::REMOVED_IDS as $removed ) {
			$this->assertNotContains( $removed, $ids, "Field-group card '{$removed}' must not appear on the profile sidebar." );
		}
	}

	public function test_own_profile_shows_strength_and_member_of(): void {
		Surface::set(
			'profile',
			array(
				'is_own_profile' => true,
				'completion'     => array( 'score' => 40 ),
				'member_spaces'  => array( (object) array( 'id' => 1, 'name' => 'S', 'slug' => 's', 'role' => 'member' ) ),
			)
		);
		$widgets = ( new ProfileSidebarProvider() )->widgets( array(), 'profile' );
		$ids     = $this->ids( $widgets );

		$this->assertContains( 'profile-strength', $ids );
		$this->assertContains( 'profile-member-of', $ids );
		foreach ( $widgets as $w ) {
			$this->assertArrayHasKey( 'chrome', $w );
			$this->assertFalse( $w['chrome'], "Widget '{$w['id']}' must be self-chromed (chrome => false)." );
			$this->assertSame( array( 'profile' ), $w['surfaces'] );
		}
	}

	public function test_visitor_never_sees_profile_strength_but_still_has_a_sidebar(): void {
		// Another member's profile: Profile Strength is own-only, but Member of
		// (their spaces) keeps the column populated — never an empty sidebar.
		Surface::set(
			'profile',
			array(
				'is_own_profile' => false,
				'completion'     => array( 'score' => 40 ),
				'member_spaces'  => array( (object) array( 'id' => 1, 'name' => 'S', 'slug' => 's', 'role' => 'member' ) ),
			)
		);
		$widgets = ( new ProfileSidebarProvider() )->widgets( array(), 'profile' );
		$ids     = $this->ids( $widgets );

		$this->assertNotContains( 'profile-strength', $ids, 'A visitor must never see another member\'s completion checklist.' );
		$this->assertContains( 'profile-member-of', $ids, 'Another member\'s profile must still show a populated sidebar.' );
	}
}
