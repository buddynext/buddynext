<?php
/**
 * A search typed as an exact @handle lists that member first in the directory,
 * whatever the sort (card 10440001926).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;

/**
 * @covers \BuddyNext\Profile\MemberDirectoryService
 */
class DirectoryHandlePinTest extends \WP_UnitTestCase {

	private int $noah_kim = 0;

	private int $viewer = 0;

	/**
	 * Noah Kim registers first, then ten newer Noahs, so newest-first puts him last.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		// Handle 'noah': the test DB has no FULLTEXT, and its LIKE path needs every Noah to match the text too.
		$this->noah_kim = $this->member( 'noah', 'Noah Kim' );
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->member( 'noah-' . $i, 'Noah Newer' . $i );
		}
		$this->viewer = self::factory()->user->create();
		wp_set_current_user( $this->viewer );
	}

	private function member( string $login, string $name ): int {
		global $wpdb;
		$id = self::factory()->user->create(
			array(
				'user_login'    => $login,
				'user_nicename' => $login,
				'display_name'  => $name,
			)
		);
		$wpdb->replace(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type'     => 'user',
				'object_id'       => $id,
				'title'           => $name,
				'content'         => '',
				'content_members' => '',
				'visibility'      => 'public',
			)
		);
		return $id;
	}

	private function ids( array $page ): array {
		return array_map( static fn( $row ) => (int) $row['user_id'], $page['items'] );
	}

	public function test_an_exact_handle_is_row_one_and_not_repeated_on_the_next_page(): void {
		$service = new MemberDirectoryService();
		$page1   = $service->list_members( $this->viewer, null, 8, array( 'search' => '@noah' ) );
		$this->assertSame( $this->noah_kim, $this->ids( $page1 )[0] );

		$page2 = $service->list_members( $this->viewer, $page1['next_cursor'], 8, array( 'search' => '@noah' ) );
		$all   = array_merge( $this->ids( $page1 ), $this->ids( $page2 ) );
		$this->assertCount( 11, $all, 'Every Noah is listed once across the two pages.' );
		$this->assertCount( 11, array_unique( $all ) );
	}

	public function test_the_server_rendered_directory_pins_the_handle_too(): void {
		$service = new MemberDirectoryService();
		$request = $service->ssr_request( $this->viewer, array( 's' => '@noah' ), 1 );
		$ids     = $service->ssr_page_user_ids( $this->viewer, $request['query_args'], $request['filters'] );
		$this->assertSame( $this->noah_kim, $ids[0] );
	}

	public function test_a_blocked_member_is_not_pinned_back_in(): void {
		buddynext_service( 'blocks' )->block( $this->viewer, $this->noah_kim );
		$page = ( new MemberDirectoryService() )->list_members( $this->viewer, null, 8, array( 'search' => '@noah' ) );
		$this->assertNotContains( $this->noah_kim, $this->ids( $page ) );
	}
}
