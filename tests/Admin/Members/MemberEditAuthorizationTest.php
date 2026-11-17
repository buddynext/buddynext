<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Who may change what on the admin Edit Member screen, and what an upload is.
 *
 * Run the multisite cases with WP_MULTISITE=1; they skip on a single site.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Edit Member save checks.
 *
 * @covers \BuddyNext\Admin\Members
 */
class MemberEditAuthorizationTest extends WP_UnitTestCase {

	/**
	 * Temp files to delete.
	 *
	 * @var string[]
	 */
	private array $tmp = array();

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function tear_down(): void {
		$_POST  = array();
		$_FILES = array();
		foreach ( $this->tmp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		parent::tear_down();
	}

	/**
	 * Run the pre-write check for a member with a posted form.
	 *
	 * @param int                 $user_id Member.
	 * @param array<string,mixed> $post    Posted fields.
	 * @return string
	 */
	private function check( int $user_id, array $post ): string {
		$_POST = $post;
		$check = new \ReflectionMethod( Members::class, 'member_edit_error' );
		return (string) $check->invoke( new Members(), $user_id, get_userdata( $user_id ) );
	}

	/**
	 * A temp file with the given bytes, posted as an image upload.
	 *
	 * @param string $kind  avatar|cover.
	 * @param string $name  Client file name.
	 * @param string $bytes File contents.
	 * @return void
	 */
	private function upload( string $kind, string $name, string $bytes ): void {
		$path        = wp_tempnam( $name );
		$this->tmp[] = $path;
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$_FILES[ 'bn_' . $kind ] = array(
			'name'     => $name,
			'type'     => 'image/png', // What the browser claims; must not be trusted.
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * A real 4x4 PNG.
	 *
	 * @return string
	 */
	private function png(): string {
		$img = imagecreatetruecolor( 4, 4 );
		ob_start();
		imagepng( $img );
		return (string) ob_get_clean();
	}

	private function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		return $id;
	}

	private function site_admin_on_network(): int {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		$this->assertFalse( is_super_admin( $id ) );
		return $id;
	}

	// ── Uploads are judged on their bytes ────────────────────────────────────

	public function test_a_text_file_named_png_is_refused_whatever_the_browser_claims(): void {
		$this->admin();
		$member = self::factory()->user->create();
		$this->upload( 'avatar', 'a.png', 'this is not an image' );

		$this->assertSame( 'avatar_type', $this->check( $member, array() ) );
	}

	public function test_a_text_cover_named_png_is_refused(): void {
		$this->admin();
		$member = self::factory()->user->create();
		$this->upload( 'cover', 'c.png', 'still not an image' );

		$this->assertSame( 'cover_type', $this->check( $member, array() ) );
	}

	public function test_a_real_png_passes(): void {
		$this->admin();
		$member = self::factory()->user->create();
		$this->upload( 'avatar', 'a.png', $this->png() );

		$this->assertSame( '', $this->check( $member, array() ) );
	}

	// ── Single site: an administrator keeps every power ──────────────────────

	public function test_an_administrator_changes_email_name_role_and_handle(): void {
		$this->admin();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame(
			'',
			$this->check(
				$member,
				array(
					'bn_user_email'   => 'moved@example.com',
					'display_name'    => 'A New Name',
					'bn_user_role'    => 'author',
					'bn_profile_slug' => 'fresh-handle-' . $member,
				)
			)
		);
	}

	// ── Reserved names: an administrator may assign impersonation words ──────

	public function test_an_administrator_may_assign_an_impersonation_word(): void {
		$this->admin();
		$member = self::factory()->user->create();

		$this->assertSame( '', $this->check( $member, array( 'bn_profile_slug' => 'support' ) ) );
	}

	public function test_a_route_word_stays_refused_for_an_administrator(): void {
		$this->admin();
		$member = self::factory()->user->create();

		$this->assertSame( 'slug_taken', $this->check( $member, array( 'bn_profile_slug' => 'settings' ) ) );
	}

	public function test_an_unchanged_reserved_handle_does_not_block_other_edits(): void {
		$this->admin();
		$member = self::factory()->user->create();
		update_user_meta( $member, 'bn_profile_slug', 'settings' );

		$this->assertSame( '', $this->check( $member, array( 'bn_profile_slug' => 'settings' ) ) );
	}

	// ── Multisite: core's per-user rules hold ────────────────────────────────

	public function test_a_site_admin_cannot_edit_a_super_admin_at_all(): void {
		$this->site_admin_on_network();
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $owner );

		$this->assertSame( 'member_forbidden', $this->check( $owner, array( 'bn_user_email' => 'attacker@example.com' ) ) );
		$this->assertSame( 'member_forbidden', $this->check( $owner, array() ), 'Not even the community fields.' );
	}

	public function test_a_site_admin_cannot_change_another_members_email_name_or_handle(): void {
		$this->site_admin_on_network();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( 'account_forbidden', $this->check( $member, array( 'bn_user_email' => 'moved@example.com' ) ) );
		$this->assertSame( 'account_forbidden', $this->check( $member, array( 'display_name' => 'Renamed' ) ) );
		$this->assertSame( 'account_forbidden', $this->check( $member, array( 'bn_profile_slug' => 'renamed-' . $member ) ) );
	}

	public function test_a_site_admin_still_saves_community_data_with_the_account_untouched(): void {
		$this->site_admin_on_network();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user   = get_userdata( $member );

		$this->assertSame(
			'',
			$this->check(
				$member,
				array(
					'bn_user_email' => $user->user_email,
					'display_name'  => $user->display_name,
					'bn_user_role'  => 'subscriber',
				)
			)
		);
	}

	public function test_a_super_admin_may_edit_another_super_admin(): void {
		$this->admin();
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $owner );
		}

		$this->assertSame( '', $this->check( $owner, array( 'bn_user_email' => 'owner-new@example.com' ) ) );
	}

	// ── The handler reports what it could not save ───────────────────────────

	public function test_a_photo_the_editor_cannot_process_is_reported_not_dropped(): void {
		$this->admin();
		$member = self::factory()->user->create();
		// A PNG signature and header that pass the type check, with no image data
		// behind them, so the image editor fails inside store().
		$broken = substr( $this->png(), 0, 33 );
		$this->upload( 'avatar', 'a.png', $broken );

		$_POST                = array(
			'user_id'  => (string) $member,
			'_wpnonce' => wp_create_nonce( 'bn_save_member_profile' ),
		);
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$location = '';
		$catch    = static function ( string $to ) {
			throw new \RuntimeException( $to ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_redirect', $catch );
		try {
			( new Members() )->handle_save_member_profile();
		} catch ( \RuntimeException $redirect ) {
			$location = $redirect->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
			unset( $_REQUEST['_wpnonce'] );
		}

		$this->assertStringContainsString( 'bn_error=avatar_failed', $location, 'The failed photo must be reported.' );
		$this->assertStringContainsString( 'saved=1', $location, 'The rest of the edit was saved.' );
		$this->assertSame( '', (string) get_user_meta( $member, 'bn_avatar', true ) );
	}
}
