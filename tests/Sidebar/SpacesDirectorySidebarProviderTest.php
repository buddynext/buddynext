<?php
/**
 * Tests for SpacesDirectorySidebarProvider.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Core\Installer;
use BuddyNext\Sidebar\Providers\SpacesDirectorySidebarProvider;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Sidebar\Providers\SpacesDirectorySidebarProvider
 */
class SpacesDirectorySidebarProviderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_cache_flush();
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new SpacesDirectorySidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_guest_gets_popular_but_not_suggested(): void {
		$owner = self::factory()->user->create();
		( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Guest Popular Space',
				'slug' => 'guest-popular-space',
				'type' => 'open',
			)
		);

		wp_set_current_user( 0 );

		$widgets = ( new SpacesDirectorySidebarProvider() )->widgets( array(), 'spaces' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		$this->assertNotContains( 'spaces-suggested', $ids );
		$this->assertNotContains( 'spaces-yours', $ids );
		$this->assertContains( 'spaces-popular', $ids );

		$by_id = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}
		$this->assertSame( 'Popular spaces', $by_id['spaces-popular']['title'] );
		$this->assertSame( 'star', $by_id['spaces-popular']['icon'] );

		// Titled cards using the registry's DEFAULT chrome — never chrome => false.
		foreach ( $widgets as $widget ) {
			$this->assertArrayNotHasKey( 'chrome', $widget );
		}
	}

	public function test_logged_in_shows_suggested_and_popular_together(): void {
		$owner = self::factory()->user->create();
		( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Suggestible Space',
				'slug' => 'suggestible-space',
				'type' => 'open',
			)
		);

		// A fresh viewer with zero joins/follows/interests still gets a
		// suggestion via the popularity fallback signal (see
		// SpaceSuggestionInterestTest::test_blank_interests_keeps_popularity_order),
		// so this exercises the real suggest() path rather than a stub.
		$viewer = self::factory()->user->create();
		wp_set_current_user( $viewer );

		$widgets = ( new SpacesDirectorySidebarProvider() )->widgets( array(), 'spaces' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		// Personalized affinity (suggested) and community-wide popularity answer
		// different questions, so both render together — the directory sidebar is
		// never a single thin card. The always-on discovery + pulse cards join them.
		$this->assertContains( 'spaces-suggested', $ids );
		$this->assertContains( 'spaces-popular', $ids, 'Popular renders alongside Suggested, not instead of it.' );
		$this->assertContains( 'spaces-new', $ids );
		$this->assertContains( 'spaces-pulse', $ids );

		$by_id = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}
		$this->assertSame( 'Suggested for you', $by_id['spaces-suggested']['title'] );
		$this->assertSame( 'sparkles', $by_id['spaces-suggested']['icon'] );
		$this->assertSame( 'New spaces', $by_id['spaces-new']['title'] );
		$this->assertSame( 'Community pulse', $by_id['spaces-pulse']['title'] );

		foreach ( $widgets as $widget ) {
			$this->assertArrayNotHasKey( 'chrome', $widget );
		}
	}

	public function test_community_pulse_reports_space_and_membership_scale(): void {
		$owner = self::factory()->user->create();
		( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Pulse Space',
				'slug' => 'pulse-space',
				'type' => 'open',
			)
		);

		wp_set_current_user( 0 );

		$widgets = ( new SpacesDirectorySidebarProvider() )->widgets( array(), 'spaces' );
		$by_id   = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}

		$this->assertArrayHasKey( 'spaces-pulse', $by_id, 'The pulse card renders whenever the directory has spaces.' );

		ob_start();
		call_user_func( $by_id['spaces-pulse']['render'] );
		$body = (string) ob_get_clean();

		// Three labelled stat rows: spaces, open to join, memberships.
		$this->assertStringContainsString( 'bn-sd-pulse', $body );
		$this->assertStringContainsString( 'open to join', $body );
	}

	public function test_logged_in_yours_card_lists_managed_and_joined_spaces(): void {
		$viewer = self::factory()->user->create();

		$managed_id = ( new SpaceService() )->create(
			$viewer,
			array(
				'name' => 'Managed Space',
				'slug' => 'managed-space',
				'type' => 'open',
			)
		);

		$other_owner = self::factory()->user->create();
		$joined_id   = ( new SpaceService() )->create(
			$other_owner,
			array(
				'name' => 'Joined Space',
				'slug' => 'joined-space',
				'type' => 'open',
			)
		);
		( new SpaceMemberService() )->join( (int) $joined_id, $viewer );

		wp_set_current_user( $viewer );

		$widgets = ( new SpacesDirectorySidebarProvider() )->widgets( array(), 'spaces' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		$this->assertContains( 'spaces-yours', $ids );

		$by_id = array();
		foreach ( $widgets as $widget ) {
			$by_id[ $widget['id'] ] = $widget;
		}
		$this->assertSame( 'Your spaces', $by_id['spaces-yours']['title'] );
		$this->assertSame( 'users', $by_id['spaces-yours']['icon'] );

		ob_start();
		call_user_func( $by_id['spaces-yours']['render'] );
		$body = (string) ob_get_clean();

		$this->assertStringContainsString( 'Managed Space', $body );
		$this->assertStringContainsString( 'Joined Space', $body );
		$this->assertStringContainsString( 'You manage', $body );
		$this->assertStringContainsString( 'You joined', $body );
	}
}
