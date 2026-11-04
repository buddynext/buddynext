<?php
/**
 * The Member Profile pattern is about the page's member, never the viewer.
 *
 * Profile Header and Profile Fields fell back to the logged-in viewer, and the
 * Activity Feed's 'profile' scope quietly rendered the site-wide explore feed.
 *
 * @package BuddyNext\Tests\Blocks
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Blocks;

use WP_UnitTestCase;

/**
 * Member context, privacy and scope on the pattern's blocks.
 *
 * @covers \BuddyNext\Blocks\BlockRegistrar
 */
class MemberProfilePatternTest extends WP_UnitTestCase {

	private int $member;
	private int $viewer;
	private int $page_id;

	public function set_up(): void {
		parent::set_up();
		$this->member  = self::factory()->user->create(
			array(
				'display_name' => 'QA-FIX PageMember',
				'role'         => 'subscriber',
			)
		);
		$this->viewer  = self::factory()->user->create(
			array(
				'display_name' => 'QA-FIX Viewer',
				'role'         => 'subscriber',
			)
		);
		$this->page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $this->member,
			)
		);
	}

	/**
	 * Render block markup on the member's page as this viewer.
	 *
	 * @param string $markup Block markup.
	 * @param int    $viewer Viewer id; 0 = guest.
	 * @param int    $page   Page to render on; 0 = none.
	 * @return string
	 */
	private function render( string $markup, int $viewer, ?int $page = null ): string {
		wp_set_current_user( $viewer );
		$GLOBALS['post'] = get_post( $page ?? $this->page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		// A fresh registrar per render: the "shown once" state is per request.
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( 'buddynext/profile-header' );
		if ( is_array( $block_type->render_callback ) ) {
			$prop = new \ReflectionProperty( $block_type->render_callback[0], 'member_state_shown' );
			$prop->setValue( $block_type->render_callback[0], false );
		}
		return do_blocks( $markup );
	}

	private function post_by( int $author, string $content, string $privacy = 'public' ): int {
		wp_set_current_user( $author );
		return (int) ( new \BuddyNext\Feed\PostService() )->create(
			$author,
			array(
				'type'    => 'text',
				'content' => $content,
				'privacy' => $privacy,
			)
		);
	}

	public function test_header_shows_the_pages_member_to_a_member_and_a_guest(): void {
		foreach ( array( $this->viewer, 0 ) as $viewer ) {
			$html = $this->render( '<!-- wp:buddynext/profile-header /-->', $viewer );
			$this->assertStringContainsString( 'QA-FIX PageMember', $html );
			$this->assertStringNotContainsString( 'QA-FIX Viewer', $html );
			$this->assertStringContainsString( 'data-user-id="' . $this->member . '"', $html );
		}
	}

	public function test_fields_are_the_pages_member(): void {
		$html = $this->render( '<!-- wp:buddynext/profile-fields /-->', $this->viewer );
		$this->assertStringContainsString( 'data-user-id="' . $this->member . '"', $html );
	}

	public function test_a_chosen_member_wins(): void {
		$other = self::factory()->user->create( array( 'display_name' => 'QA-FIX Chosen' ) );
		$html  = $this->render( '<!-- wp:buddynext/profile-header {"userId":' . $other . '} /-->', $this->viewer );
		$this->assertStringContainsString( 'QA-FIX Chosen', $html );
	}

	public function test_no_member_is_a_clear_state_not_the_viewers_profile(): void {
		$GLOBALS['wp_query']->is_author = false;

		$html = $this->render( '<!-- wp:buddynext/profile-header /-->', $this->viewer, 0 );

		$this->assertStringNotContainsString( 'QA-FIX Viewer', $html );
		$this->assertStringNotContainsString( 'data-user-id', $html );
		$this->assertStringContainsString( 'No member to show', $html );
	}

	public function test_the_state_is_said_once_across_the_pattern(): void {
		$html = $this->render(
			'<!-- wp:buddynext/profile-header /--><!-- wp:buddynext/activity-feed {"scope":"profile"} /--><!-- wp:buddynext/profile-fields /-->',
			$this->viewer,
			0
		);
		$this->assertSame( 1, substr_count( $html, 'No member to show' ) );
	}

	public function test_a_private_profile_stays_private_on_a_page_for_guest_and_member(): void {
		buddynext_service( 'privacy' )->set_preference( $this->member, 'profile_visibility', 'private' );
		$secret = $this->post_by( $this->member, 'QA-FIX private-profile post' );
		$this->assertGreaterThan( 0, $secret );

		foreach ( array( $this->viewer, 0 ) as $viewer ) {
			$html = $this->render(
				'<!-- wp:buddynext/profile-header /--><!-- wp:buddynext/profile-fields /--><!-- wp:buddynext/activity-feed {"scope":"profile"} /-->',
				$viewer
			);
			$this->assertStringNotContainsString( 'QA-FIX PageMember', $html );
			$this->assertStringNotContainsString( 'QA-FIX private-profile post', $html );
			$this->assertStringContainsString( 'This profile is private.', $html );
		}

		$own = $this->render( '<!-- wp:buddynext/profile-header /-->', $this->member );
		$this->assertStringContainsString( 'QA-FIX PageMember', $own );
	}

	public function test_a_bio_hidden_from_the_viewer_does_not_come_back_through_the_meta_copy(): void {
		buddynext_service( 'profiles' )->save_profile(
			$this->member,
			array(
				'bio'             => 'QA-FIX hidden meta bio',
				'bio__visibility' => 'private',
			)
		);
		update_user_meta( $this->member, 'bn_bio', 'QA-FIX hidden meta bio' );

		$html = $this->render( '<!-- wp:buddynext/profile-header {"userId":' . $this->member . '} /-->', $this->viewer );
		$this->assertStringContainsString( 'QA-FIX PageMember', $html );
		$this->assertStringNotContainsString( 'QA-FIX hidden meta bio', $html );
	}

	public function test_profile_scope_is_that_members_posts_never_explore(): void {
		$mine    = $this->post_by( $this->member, 'QA-FIX member public post' );
		$hidden  = $this->post_by( $this->member, 'QA-FIX member connections post', 'connections' );
		$foreign = $this->post_by( $this->viewer, 'QA-FIX somebody else post' );
		$this->assertGreaterThan( 0, $mine );
		$this->assertGreaterThan( 0, $hidden );
		$this->assertGreaterThan( 0, $foreign );

		foreach ( array( $this->viewer, 0 ) as $viewer ) {
			$html = $this->render( '<!-- wp:buddynext/activity-feed {"scope":"profile"} /-->', $viewer );
			$this->assertStringContainsString( 'QA-FIX member public post', $html );
			$this->assertStringNotContainsString( 'QA-FIX somebody else post', $html );
			$this->assertStringNotContainsString( 'QA-FIX member connections post', $html );
		}

		$explore = $this->render( '<!-- wp:buddynext/activity-feed {"scope":"explore"} /-->', $this->viewer );
		$this->assertStringContainsString( 'QA-FIX somebody else post', $explore );
	}

	public function test_completion_bar_is_only_for_the_member_themself(): void {
		$markup = '<!-- wp:buddynext/profile-completion-bar /-->';

		foreach ( array( $this->viewer, 0 ) as $viewer ) {
			$this->assertStringNotContainsString( 'bn-block-profile-completion', $this->render( $markup, $viewer ) );
		}
		$this->assertStringContainsString( 'bn-block-profile-completion', $this->render( $markup, $this->member ) );
		$this->assertStringNotContainsString( 'bn-block-profile-completion', $this->render( $markup, $this->viewer, 0 ) );
	}
}
