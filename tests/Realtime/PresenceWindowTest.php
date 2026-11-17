<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Presence stays truthful without a timer (1.3.0).
 *
 * - "Online" means active in the last 10 minutes by default (was 5), one
 *   filterable value every reader shares.
 * - The Online only checkbox is not offered to viewers who cannot see presence,
 *   and a shared ?online=1 link does not switch the filter on for them.
 * - Site Health says so in plain words when members post but nobody is recorded.
 *
 * The client ping itself (assets/js/shell/presence.js) is covered by the
 * browser journey; its server route is the existing /me/presence/heartbeat.
 *
 * @package BuddyNext\Tests\Realtime
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Realtime;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;
use BuddyNext\Realtime\PresenceService;
use WP_UnitTestCase;

/**
 * Presence window, Online only gating, Site Health.
 */
class PresenceWindowTest extends WP_UnitTestCase {

	/**
	 * Empty presence and posts so each case starts clean.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_presence" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_posts" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * A member last active 7 minutes ago still reads online. Fails on 1.2.9 (300s).
	 *
	 * @return void
	 */
	public function test_member_active_seven_minutes_ago_is_online(): void {
		$uid = self::factory()->user->create();
		PresenceService::write( $uid, time() - 420 );

		$this->assertSame( 600, PresenceService::online_window() );
		$this->assertTrue( PresenceService::is_online( $uid ) );
		$this->assertContains( $uid, PresenceService::online_ids() );
		$this->assertTrue( buddynext_service( 'blocks' )->is_user_online( $uid, $uid ) );
	}

	/**
	 * One filter moves every reader, and is floored at the one-minute throttle.
	 *
	 * @return void
	 */
	public function test_window_filter_moves_every_reader(): void {
		$uid = self::factory()->user->create();
		PresenceService::write( $uid, time() - 420 );

		add_filter( 'buddynext_presence_online_window', static fn(): int => 300 );
		$this->assertFalse( PresenceService::is_online( $uid ) );
		$this->assertSame( 0, PresenceService::online_count() );
		$this->assertFalse( buddynext_service( 'blocks' )->is_user_online( $uid, $uid ) );

		add_filter( 'buddynext_presence_online_window', static fn(): int => 5, 20 );
		$this->assertSame( PresenceService::THROTTLE_SECONDS, PresenceService::online_window() );
	}

	/**
	 * Logged-out visitors get no Online only checkbox. Fails on 1.2.9.
	 *
	 * @return void
	 */
	public function test_online_only_checkbox_hidden_from_visitors_who_cannot_see_presence(): void {
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'bn-md-strip__online-input', $this->filter_bar() );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringContainsString( 'bn-md-strip__online-input', $this->filter_bar() );
	}

	/**
	 * A shared ?online=1 link does not switch the filter on for a visitor.
	 * Fails on 1.2.9, which reported online_only = true to the template.
	 *
	 * @return void
	 */
	public function test_shared_online_link_is_ignored_for_visitors(): void {
		$directory = new MemberDirectoryService();

		$this->assertFalse( $directory->ssr_request( 0, array( 'online' => '1' ), 1 )['online_only'] );
		$this->assertTrue( $directory->ssr_request( self::factory()->user->create(), array( 'online' => '1' ), 1 )['online_only'] );
	}

	/**
	 * Site Health: posts in the last hour with nobody recorded online warns;
	 * a presence row clears it.
	 *
	 * @return void
	 */
	public function test_site_health_warns_when_members_post_but_nobody_is_recorded(): void {
		$this->assertSame( 'good', PresenceService::site_health_test()['status'], 'No recent posts: nothing to judge.' );

		$uid = self::factory()->user->create();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $uid,
				'type'       => 'text',
				'content'    => 'Recent post',
				'privacy'    => 'public',
				'status'     => 'published',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->assertSame( 'recommended', PresenceService::site_health_test()['status'] );

		( new PresenceService() )->stamp( $uid );
		$this->assertSame( 'good', PresenceService::site_health_test()['status'] );
	}

	/**
	 * Render the directory filter bar.
	 *
	 * @return string
	 */
	private function filter_bar(): string {
		ob_start();
		buddynext_get_template( 'parts/member-directory-filter-bar.php', array( 'current_online' => false ) );
		return (string) ob_get_clean();
	}
}
