<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Member lists show the member's own picture, not only initials.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\EmailLog;
use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * The wp-admin Members list, the Email Log and member search drew initials for
 * everyone, ignoring the avatar the rest of the community shows.
 *
 * @covers \BuddyNext\Admin\Members\MemberDisplay::render_avatar
 * @covers \BuddyNext\Search\SearchService
 */
class AdminMemberAvatarTest extends WP_UnitTestCase {

	private const PHOTO = 'https://example.test/uploads/bn-avatars/ann.webp';

	/**
	 * A member with an uploaded picture.
	 *
	 * @var int
	 */
	private int $member;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->member = self::factory()->user->create( array( 'display_name' => 'Ann Photo' ) );
		update_user_meta( $this->member, 'bn_avatar', self::PHOTO );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Run a private renderer and return its output.
	 *
	 * @param object $owner  Instance.
	 * @param string $method Method name.
	 * @return string
	 */
	private static function capture( object $owner, string $method ): string {
		$render = new \ReflectionMethod( $owner, $method );
		ob_start();
		$render->invoke( $owner );
		return (string) ob_get_clean();
	}

	public function test_the_members_list_shows_the_members_picture(): void {
		$html = self::capture( new Members(), 'render_members_tab' );

		$this->assertStringContainsString( 'src="' . self::PHOTO, $html );
	}

	public function test_the_email_log_shows_the_recipients_picture(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_email_log',
			array(
				'user_id' => $this->member,
				'type'    => 'welcome',
				'status'  => 'sent',
				'sent_at' => current_time( 'mysql', true ),
			)
		);

		ob_start();
		( new EmailLog() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Ann Photo', $html, 'The log row must render.' );
		$this->assertStringContainsString( 'src="' . self::PHOTO, $html );
	}

	public function test_member_search_rows_carry_the_members_picture(): void {
		$enrich = new \ReflectionMethod( \BuddyNext\Search\SearchService::class, 'enrich_members' );
		$rows   = $enrich->invoke( buddynext_service( 'search' ), array( $this->member ), get_current_user_id() );

		$this->assertStringStartsWith( self::PHOTO, (string) ( $rows[0]['avatar_url'] ?? '' ) );

		ob_start();
		buddynext_get_template(
			'parts/search-result-section-members.php',
			array(
				'members'     => $rows,
				'total_count' => 1,
				'query'       => 'Ann',
			)
		);
		$this->assertStringContainsString( 'src="' . self::PHOTO, (string) ob_get_clean() );
	}
}
