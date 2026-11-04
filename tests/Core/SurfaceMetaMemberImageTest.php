<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A shared member profile shows the cover photo, the way a space card does,
 * unless a logged-out visitor cannot view the profile (Zoho #42239).
 *
 * @package BuddyNext\Tests\Core
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HeadMeta;
use BuddyNext\Core\Installer;
use BuddyNext\Core\SurfaceMeta;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\SurfaceMeta
 */
class SurfaceMetaMemberImageTest extends WP_UnitTestCase {

	private const COVER  = 'https://example.test/wp-content/uploads/bn-covers/1/full.webp';
	private const AVATAR = 'https://example.test/wp-content/uploads/bn-avatars/1/full.webp';

	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		HeadMeta::reset();

		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $this->member_id, 'buddynext_cover_url', self::COVER );
		update_user_meta( $this->member_id, 'bn_avatar', self::AVATAR );
	}

	public function tear_down(): void {
		HeadMeta::reset();
		parent::tear_down();
	}

	private function og_image(): string {
		wp_set_current_user( 0 );
		HeadMeta::reset();
		SurfaceMeta::register( 'people', array( 'user_id' => $this->member_id ) );
		ob_start();
		do_action( 'wp_head' );
		$head = (string) ob_get_clean();

		return preg_match( '/<meta property="og:image" content="([^"]+)"/', $head, $m ) ? $m[1] : '';
	}

	public function test_public_profile_shares_the_cover(): void {
		buddynext_service( 'privacy' )->set_preference( $this->member_id, 'profile_visibility', 'public' );

		$this->assertSame( self::COVER, $this->og_image() );
	}

	public function test_locked_profile_shares_the_avatar_not_the_cover(): void {
		buddynext_service( 'privacy' )->set_preference( $this->member_id, 'profile_visibility', 'followers' );

		$this->assertSame( self::AVATAR, $this->og_image(), 'A locked profile hides its cover from strangers; a scraper is one.' );
	}

	public function test_no_cover_falls_back_to_the_avatar(): void {
		buddynext_service( 'privacy' )->set_preference( $this->member_id, 'profile_visibility', 'public' );
		delete_user_meta( $this->member_id, 'buddynext_cover_url' );

		$this->assertSame( self::AVATAR, $this->og_image() );
	}
}
