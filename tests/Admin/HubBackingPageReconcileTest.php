<?php
/**
 * A hub's backing page follows the hub.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\NavManager;
use BuddyNext\Core\Installer;

/**
 * The backing page has an identity - its slug and its published state - that the
 * hub never maintained. Three symptoms, one cause: renaming a hub left the old URL
 * live, reassigning a hub orphaned the old page, and a trashed page still counted
 * as the hub's.
 *
 * The first two matter more than a stale link. The installer seeds these pages with
 * the hub's SHORTCODE in their content, so an abandoned path still renders the hub
 * - via the shortcode, not dispatch_hub_template(), so none of that method's guards
 * run on it. A site that turns Spaces off, or closes the community to members only,
 * kept a reachable copy at every slug it had ever used.
 *
 * Owner decision (2026-08-29): the old URL 404s rather than redirecting. A redirect
 * would leave the page published, and a published page carrying the shortcode is
 * the exposure - the broken link is the lesser problem.
 *
 * @covers \BuddyNext\Admin\NavManager
 */
class HubBackingPageReconcileTest extends \WP_UnitTestCase {

	private NavManager $nav;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->nav = new NavManager();
	}

	/**
	 * Call the private reconciler directly - the save handler around it redirects
	 * and exits, which a unit test cannot follow.
	 *
	 * @param int    $page_id  Page the hub now points at.
	 * @param int    $previous Page it pointed at before.
	 * @param string $slug     Effective hub slug.
	 * @return void
	 */
	private function reconcile( int $page_id, int $previous, string $slug ): void {
		$m = new \ReflectionMethod( NavManager::class, 'reconcile_backing_page' );
		$m->setAccessible( true );
		$m->invoke( $this->nav, $page_id, $previous, $slug );
	}

	/**
	 * @param string $slug   Page slug.
	 * @param string $status Post status.
	 * @return int
	 */
	private function make_page( string $slug, string $status = 'publish' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_content' => '[buddynext_people]',
			)
		);
	}

	public function test_renaming_a_hub_moves_its_backing_page_off_the_old_slug(): void {
		$page_id = $this->make_page( 'crew-hub' );

		$this->reconcile( $page_id, $page_id, 'crew-team' );

		$this->assertSame( 'crew-team', get_post( $page_id )->post_name );

		// get_page_by_path() memoises path -> id, so a stale hit here would look
		// like the fix had not worked.
		wp_cache_flush();

		$this->assertNull(
			get_page_by_path( 'crew-hub' ),
			'The old path must resolve to nothing, so it 404s instead of rendering the hub shortcode.'
		);
	}

	public function test_reassigning_a_hub_retires_the_page_it_left_behind(): void {
		$old = $this->make_page( 'crew-hub' );
		$new = $this->make_page( 'crew-community' );

		$this->reconcile( $new, $old, 'crew-community' );

		$this->assertSame( 'draft', get_post( $old )->post_status, 'An orphaned backing page still carries the hub shortcode.' );
		$this->assertSame( 'publish', get_post( $new )->post_status );
	}

	public function test_a_trashed_backing_page_is_restored_rather_than_silently_kept(): void {
		// hub_page_id() gates on get_post_type() === 'page', which is true for a
		// trashed page - so the hub went on pointing at one sitting in the bin.
		$page_id = $this->make_page( 'crew-hub', 'trash' );

		$this->reconcile( $page_id, $page_id, 'crew-hub' );

		$this->assertSame( 'publish', get_post( $page_id )->post_status );
	}

	public function test_an_unchanged_hub_is_left_alone(): void {
		$page_id = $this->make_page( 'crew-hub' );
		$before  = get_post( $page_id )->post_modified;

		$this->reconcile( $page_id, $page_id, 'crew-hub' );

		$this->assertSame( 'crew-hub', get_post( $page_id )->post_name );
		$this->assertSame( $before, get_post( $page_id )->post_modified, 'No write when nothing changed.' );
	}
}
