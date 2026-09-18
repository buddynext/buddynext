<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests PostService::set_hidden() — the Hide/Restore behind the admin Activity
 * screen's bulk actions.
 *
 * @package BuddyNext\Tests\Feed
 * @since 1.2.1
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Covers hide/restore/skip and the moderator-only guard.
 */
class PostSetHiddenTest extends WP_UnitTestCase {

	private PostService $posts;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts = new PostService();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function make_post(): int {
		return (int) $this->posts->create(
			self::factory()->user->create(),
			array(
				'content' => 'hello world',
				'type'    => 'text',
			)
		);
	}

	private function status( int $pid ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_posts WHERE id = %d", $pid ) );
	}

	public function test_hide_sets_a_published_post_under_review(): void {
		$pid = $this->make_post();
		$this->assertSame( 'published', $this->status( $pid ) );
		$this->assertTrue( $this->posts->set_hidden( $pid, true, $this->admin ) );
		$this->assertSame( 'under_review', $this->status( $pid ) );
	}

	public function test_restore_brings_it_back(): void {
		$pid = $this->make_post();
		$this->posts->set_hidden( $pid, true, $this->admin );
		$this->assertTrue( $this->posts->set_hidden( $pid, false, $this->admin ) );
		$this->assertSame( 'published', $this->status( $pid ) );
	}

	public function test_hide_on_a_non_published_post_is_skipped(): void {
		$pid = $this->make_post();
		$this->posts->set_hidden( $pid, true, $this->admin ); // -> under_review
		// Hiding an already-hidden post is a no-op but still reported as done.
		$this->assertTrue( $this->posts->set_hidden( $pid, true, $this->admin ) );
		$this->assertSame( 'under_review', $this->status( $pid ) );
	}

	public function test_non_moderator_cannot_hide(): void {
		$pid      = $this->make_post();
		$stranger = self::factory()->user->create();
		$result   = $this->posts->set_hidden( $pid, true, $stranger );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'published', $this->status( $pid ) );
	}
}
