<?php
/**
 * Tests the server-rendered directory landing cache (ssr_page_user_ids).
 *
 * The first paint's member rows are cached under the SAME per-viewer bn_dir_
 * version salt as the REST list_members(), so both surfaces invalidate together
 * on a block / membership / directory-opt-out change.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;
use WP_UnitTestCase;

/**
 * SSR directory landing cache: shared-salt read + invalidation.
 *
 * @covers \BuddyNext\Profile\MemberDirectoryService::ssr_page_user_ids
 */
class MemberDirectorySsrCacheTest extends WP_UnitTestCase {

	/**
	 * The buddynext_directory cache group.
	 *
	 * @var string
	 */
	private const GROUP = 'buddynext_directory';

	/**
	 * Fresh schema + clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
	}

	/**
	 * Standard first-page WP_User_Query args (newest first).
	 *
	 * @return array<string,mixed>
	 */
	private function args(): array {
		return array(
			'number'  => 20,
			'paged'   => 1,
			'orderby' => 'ID',
			'order'   => 'DESC',
		);
	}

	/**
	 * The page is served from cache; bumping the shared directory salt (what a
	 * block / membership change does) busts it so a new member shows on next paint.
	 *
	 * @return void
	 */
	public function test_ssr_page_is_cached_and_the_shared_salt_busts_it(): void {
		$viewer  = self::factory()->user->create();
		$service = new MemberDirectoryService();

		self::factory()->user->create(); // One visible member.
		$first = $service->ssr_page_user_ids( $viewer, $this->args(), array() );
		$this->assertNotEmpty( $first );

		// A new member created WITHOUT bumping the salt must NOT appear — proving the
		// second call is served from cache, not re-queried.
		$fresh = self::factory()->user->create();
		$this->assertSame(
			$first,
			$service->ssr_page_user_ids( $viewer, $this->args(), array() ),
			'the SSR page is served from the versioned cache, not re-queried every load'
		);
		$this->assertNotContains( $fresh, $first, 'precondition: the new member is not in the cached page' );

		// Bump the shared per-viewer directory salt — exactly what a block / join /
		// leave / directory-opt-out does — and the SSR page must refresh.
		$key = 'bn_dir_ver_' . $viewer;
		wp_cache_set( $key, (int) wp_cache_get( $key, self::GROUP ) + 1, self::GROUP );

		$after = $service->ssr_page_user_ids( $viewer, $this->args(), array() );
		$this->assertContains(
			$fresh,
			$after,
			'bumping the shared bn_dir_ salt must bust the SSR page cache too, not just the REST cache'
		);
	}
}
