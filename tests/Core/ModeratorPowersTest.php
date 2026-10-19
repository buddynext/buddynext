<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Moderation powers follow the community role, not manage_options.
 *
 * A site could not make someone a moderator without handing them full WordPress
 * admin: comment removal, appeals, open-ended suspensions and role changes all
 * checked manage_options directly (card 10322714806).
 *
 * @package BuddyNext\Tests\Core
 * @since 1.2.6
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Moderation\ModerationService;
use WP_UnitTestCase;

/**
 * Locks each moderation power to its ability.
 */
class ModeratorPowersTest extends WP_UnitTestCase {

	/**
	 * A member holding a community role (no WordPress admin rights).
	 *
	 * @param string $role Community role.
	 * @return int
	 */
	private function member( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $id, 'bn_community_role', $role );
		update_user_meta( $id, 'bn_email_verified', '1' );
		return $id;
	}

	/**
	 * A moderator removes another member's comment but cannot rewrite it; a
	 * community admin can do both.
	 *
	 * @return void
	 */
	public function test_comment_delete_is_a_moderator_power_and_edit_an_admin_one(): void {
		$author    = $this->member( 'member' );
		$moderator = $this->member( 'moderator' );
		$admin     = $this->member( 'admin' );
		$comments  = buddynext_service( 'comments' );
		$post      = ( new \BuddyNext\Feed\PostService() )->create( $author, array( 'type' => 'text', 'content' => 'Lost a blue water bottle at the Sunday ride.' ) );
		$post_id   = is_array( $post ) ? (int) $post['id'] : (int) $post;

		$first = $comments->create( $author, 'post', $post_id, 'Check the cafe at the finish line.' );
		$this->assertIsInt( $first );
		$this->assertWPError( $comments->update( $first, $moderator, 'rewritten' ), 'A moderator cannot rewrite a member.' );
		$this->assertTrue( $comments->update( $first, $admin, 'Check the cafe at the finish line, by the racks.' ) );
		$this->assertTrue( $comments->delete( $first, $moderator ) );

		$this->assertWPError( $comments->delete( (int) $comments->create( $author, 'post', $post_id, 'Found it, thanks!' ), $this->member( 'member' ) ), 'An ordinary member cannot remove it.' );
	}

	/**
	 * Moderators review shadow-banned members' posts; everyone else does not see them.
	 *
	 * @return void
	 */
	public function test_moderators_see_hidden_authors(): void {
		$service = new ModerationService();
		$this->assertSame( '', $service->hidden_authors_where( 'user_id', $this->member( 'moderator' ) ) );
		$this->assertNotSame( '', $service->hidden_authors_where( 'user_id', $this->member( 'member' ) ) );

		// Opening the post agrees with the feed: a moderator reads it, a member does not.
		$author = $this->member( 'member' );
		$post   = ( new \BuddyNext\Feed\PostService() )->create( $author, array( 'type' => 'text', 'content' => 'Selling cheap followers, message me.' ) );
		$id     = is_array( $post ) ? (int) $post['id'] : (int) $post;
		update_user_meta( $author, 'bn_shadow_banned', '1' );
		$posts = new \BuddyNext\Feed\PostService();
		$this->assertSame( array( $id ), $posts->filter_visible( array( $id ), $this->member( 'moderator' ) ) );
		$this->assertSame( array(), $posts->filter_visible( array( $id ), $this->member( 'member' ) ) );

		// The single-post gate every reader shares (REST read, page, reactions, shares).
		$this->assertNull( $posts->visibility_error( $id, $this->member( 'moderator' ) ) );
		$this->assertNull( $posts->visibility_error( $id, $author ), 'The author must not notice the ban.' );
		$this->assertWPError( $posts->visibility_error( $id, $this->member( 'member' ) ) );
	}

	/**
	 * Editing another member's post never reads or widens content the editor cannot see.
	 *
	 * @return void
	 */
	public function test_editing_others_posts_stops_at_what_the_editor_can_read(): void {
		$posts  = new \BuddyNext\Feed\PostService();
		$author = $this->member( 'member' );
		$editor = $this->member( 'admin' );
		$id     = static fn( $p ): int => is_array( $p ) ? (int) $p['id'] : (int) $p;

		$public  = $id( $posts->create( $author, array( 'type' => 'text', 'content' => 'Ride starts at 7 from the bridge.' ) ) );
		$private = $id( $posts->create( $author, array( 'type' => 'text', 'content' => 'My new door code is 4471.', 'privacy' => 'private' ) ) );

		$this->assertNotWPError( $posts->update( $public, $editor, array( 'content' => 'Ride starts at 7:30 from the bridge.' ) ), 'A community admin fixes a post they can read.' );
		$this->assertWPError( $posts->update( $private, $editor, array( 'content_warning' => false ) ), 'A community admin cannot reach a private post through an edit.' );

		$posts->update( $public, $editor, array( 'privacy' => 'private' ) );
		$this->assertSame( 'public', $posts->get( $public )['privacy'], 'Only the author picks the audience.' );

		$comments = buddynext_service( 'comments' );
		$secret   = $comments->create( $author, 'post', $private, 'Remember to change it monthly.' );
		$this->assertWPError( $comments->update( (int) ( is_array( $secret ) ? $secret['id'] : $secret ), $editor, 'Edited.' ), 'Nor a comment under it.' );

		$site_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertNotWPError( $posts->update( $private, $site_admin, array( 'content_warning' => false ) ), 'A site administrator keeps the power.' );
	}

	/**
	 * Admin-level powers: open-ended suspension, appeals and role changes.
	 *
	 * @return void
	 */
	public function test_admin_level_powers_follow_the_role_map(): void {
		$moderator = $this->member( 'moderator' );
		$admin     = $this->member( 'admin' );
		$target    = $this->member( 'member' );

		$this->assertWPError( buddynext_service( 'moderation' )->suspend_user( $target, $moderator, 'Repeated spam links' ), 'A moderator suspends for a set time only.' );
		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $target, $admin, 'Repeated spam links' ) );

		$this->assertFalse( ModerationService::can_resolve_appeals( $moderator ) );
		$this->assertTrue( ModerationService::can_resolve_appeals( $admin ) );
		$this->assertFalse( buddynext_can( $moderator, 'buddynext-community/assign-roles' ) );
		$this->assertTrue( buddynext_can( $admin, 'buddynext-community/assign-roles' ) );
	}

	/**
	 * Site-wide announcements are a community admin power; moderators and
	 * members are refused.
	 *
	 * @return void
	 */
	public function test_site_wide_announcements_follow_the_announce_ability(): void {
		$posts = new \BuddyNext\Feed\PostService();
		$data  = array(
			'type'    => 'announcement',
			'content' => 'The community garden opens for spring planting this Saturday at 9am.',
		);
		$this->assertWPError( $posts->create( $this->member( 'moderator' ), $data ) );
		$this->assertWPError( $posts->create( $this->member( 'member' ), $data ) );
		$this->assertNotWPError( $posts->create( $this->member( 'admin' ), $data ) );
	}

	/**
	 * A new report reaches community moderators, linked to the Community Admin
	 * queue they can open; an ordinary member is not told.
	 *
	 * @return void
	 */
	public function test_community_moderators_are_told_about_new_reports(): void {
		global $wpdb;
		$moderator = $this->member( 'moderator' );
		$bystander = $this->member( 'member' );
		$reporter  = $this->member( 'member' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert( $wpdb->prefix . 'bn_reports', array( 'reporter_id' => $reporter, 'object_type' => 'post', 'object_id' => 77, 'reason' => 'spam' ) );
		do_action( 'buddynext_report_created', (int) $wpdb->insert_id, 'post', 77, $reporter );

		$sent = static function ( int $user ) use ( $wpdb ): ?string {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test read-back.
			$data = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.new_report'", $user ) );
			return null === $data ? null : (string) ( json_decode( (string) $data, true )['url'] ?? '' );
		};
		$this->assertSame( trailingslashit( \BuddyNext\Core\PageRouter::community_admin_url() ) . 'moderation/', $sent( $moderator ) );
		$this->assertNull( $sent( $bystander ) );
	}

	/**
	 * The suspend dialog offers "no end date" to exactly who may use it.
	 *
	 * @return void
	 */
	public function test_suspend_dialog_offers_no_end_date_to_who_may_use_it(): void {
		$flag = function ( int $user ): bool {
			wp_set_current_user( $user );
			$method = new \ReflectionMethod( \BuddyNext\Core\AssetService::class, 'i18n_moderation' );
			$method->setAccessible( true );
			$method->invoke( ( new \ReflectionClass( \BuddyNext\Core\AssetService::class ) )->newInstanceWithoutConstructor() );
			return (bool) ( wp_interactivity_state( 'buddynext/moderation' )['canSuspendForever'] ?? false );
		};
		$this->assertTrue( $flag( $this->member( 'admin' ) ), 'a community admin may suspend with no end date' );
		$this->assertFalse( $flag( $this->member( 'moderator' ) ), 'a moderator suspends for a set time only' );
		wp_set_current_user( 0 );
	}

	/**
	 * The "New reports to review" setting shows to everyone who receives it.
	 *
	 * @return void
	 */
	public function test_report_setting_shows_to_every_recipient(): void {
		global $wpdb;
		$owner = $this->member( 'member' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert( $wpdb->prefix . 'bn_space_members', array( 'space_id' => 9100, 'user_id' => $owner, 'role' => 'owner', 'status' => 'active' ) );
		$shown = function ( int $user ): bool {
			wp_set_current_user( $user );
			foreach ( ( new \BuddyNext\Notifications\NotificationPrefCatalogue() )->grouped() as $rows ) {
				if ( in_array( 'bn.new_report', array_column( (array) $rows, 'slug' ), true ) ) {
					return true;
				}
			}
			return false;
		};
		$this->assertTrue( $shown( $this->member( 'moderator' ) ), 'community moderator' );
		$this->assertTrue( $shown( $owner ), 'space owner' );
		$this->assertFalse( $shown( $this->member( 'member' ) ), 'member' );
		wp_set_current_user( 0 );
	}

	/**
	 * An owner can hand appeals to moderators through the role map.
	 *
	 * @return void
	 */
	public function test_owner_can_delegate_appeals(): void {
		$remap = static function ( array $map ): array {
			$map['buddynext-moderation/resolve-appeals'] = 'moderator';
			return $map;
		};
		add_filter( 'buddynext_role_map', $remap );
		$this->reset_role_map();
		$this->assertTrue( ModerationService::can_resolve_appeals( $this->member( 'moderator' ) ) );
		remove_filter( 'buddynext_role_map', $remap );
		$this->reset_role_map();
	}

	/**
	 * A moderator cannot sanction the people above them; equals and juniors stay open.
	 *
	 * Warn, strike, suspend and shadow-ban all refuse a target that outranks the
	 * actor: a WordPress administrator or a community admin over a moderator.
	 *
	 * @return void
	 */
	public function test_sanctions_refuse_a_target_who_outranks_the_actor(): void {
		$service   = new ModerationService();
		$moderator = $this->member( 'moderator' );
		$peer      = $this->member( 'moderator' );
		$admin     = $this->member( 'admin' );
		$wp_admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$junior    = $this->member( 'member' );

		foreach ( array( $admin, $wp_admin ) as $above ) {
			$this->assertWPError( $service->warn( $above, $moderator, 'Test warning' ), 'Warn.' );
			$this->assertWPError( $service->issue_strike( $above, $moderator, 'Test strike' ), 'Strike.' );
			$this->assertWPError( $service->suspend_user( $above, $moderator, 'Test', array( 'duration_days' => 3 ) ), 'Suspend.' );
			$this->assertWPError( $service->shadow_ban( $above, $moderator, 'Test' ), 'Shadow-ban.' );
		}

		$this->assertTrue( $service->warn( $junior, $moderator, 'Test warning' ), 'A moderator may warn a member.' );
		$this->assertTrue( $service->warn( $peer, $moderator, 'Test warning' ), 'Equal ranks are not blocked.' );
		$this->assertTrue( $service->warn( $moderator, $wp_admin, 'Test warning' ), 'A WordPress administrator may warn a moderator.' );
	}

	/**
	 * Clear the per-request role map memo.
	 *
	 * @return void
	 */
	private function reset_role_map(): void {
		$memo = new \ReflectionProperty( \BuddyNext\Core\PermissionService::class, 'role_map_cache' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );
	}
}
