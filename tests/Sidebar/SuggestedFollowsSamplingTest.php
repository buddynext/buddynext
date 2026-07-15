<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * S1 — the suggested-follows backfill must not sort the whole community by RAND().
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Core\Installer;
use BuddyNext\Sidebar\WidgetCache;
use BuddyNext\Sidebar\WidgetService;
use WP_UnitTestCase;

/**
 * The widget renders on every logged-in page, and it was full-scanning wp_users.
 *
 * ORDER BY RAND() cannot use an index: MySQL materialises the whole filtered set, stamps a
 * random number on every row, and filesorts the lot — to keep three of them. The old
 * docblock said "the cache absorbs the worst case", and that sentence is why it survived.
 * It does not: a cold cache is the normal state for the first viewer of every TTL window,
 * and this project's standard is that everything holds up with the object cache OFF.
 *
 * The replacement samples the PRIMARY KEY from a random entry point and reads forward,
 * wrapping to the start if it lands near the end. What these tests care about is that
 * changing HOW the rows are chosen did not change WHICH rows are allowed to be chosen:
 * the exclusions are the whole safety surface here — a blocked member must never be
 * suggested, in either direction.
 *
 * @covers \BuddyNext\Sidebar\WidgetService::suggested_follows
 */
class SuggestedFollowsSamplingTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, cold cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
	}

	/**
	 * The widget service, with a cold cache each call.
	 *
	 * @return WidgetService
	 */
	private function widgets(): WidgetService {
		wp_cache_flush();

		return new WidgetService( new WidgetCache() );
	}

	/**
	 * Ids in a suggestion list.
	 *
	 * @param array<int, object> $rows Suggestion rows.
	 * @return array<int, int>
	 */
	private function ids( array $rows ): array {
		return array_map( static fn( $r ): int => (int) ( $r->ID ?? 0 ), $rows );
	}

	/**
	 * The widget still fills its slots.
	 *
	 * @return void
	 */
	public function test_it_still_suggests_people(): void {
		$viewer = self::factory()->user->create();
		self::factory()->user->create_many( 10 );

		$rows = $this->widgets()->suggested_follows( $viewer, 3 );

		$this->assertNotEmpty(
			$rows,
			'The suggestions widget came back empty on a community with ten members. The keyset sample must wrap around rather than give up when the random entry point lands past the last candidate.'
		);
		$this->assertLessThanOrEqual( 3, count( $rows ) );
	}

	/**
	 * The viewer is never suggested to themselves.
	 *
	 * @return void
	 */
	public function test_it_never_suggests_the_viewer_themselves(): void {
		$viewer = self::factory()->user->create();
		self::factory()->user->create_many( 10 );

		for ( $i = 0; $i < 8; $i++ ) {
			$this->assertNotContains(
				$viewer,
				$this->ids( $this->widgets()->suggested_follows( $viewer, 3 ) ),
				'The widget suggested the viewer follow themselves.'
			);
		}
	}

	/**
	 * THE ONE THAT MATTERS: a blocked member is never suggested, in either direction.
	 *
	 * Run repeatedly, because the sample is random: a single pass could miss a broken
	 * exclusion by luck. The whole community is small enough here that every member is a
	 * candidate on every pass, so a blocked member WILL surface if the filter is gone.
	 *
	 * @return void
	 */
	public function test_a_blocked_member_is_never_suggested_in_either_direction(): void {
		$viewer      = self::factory()->user->create();
		$i_blocked   = self::factory()->user->create();
		$blocked_me  = self::factory()->user->create();
		self::factory()->user->create_many( 4 );

		$blocks = buddynext_service( 'blocks' );
		$blocks->block( $viewer, $i_blocked );
		$blocks->block( $blocked_me, $viewer );

		for ( $i = 0; $i < 12; $i++ ) {
			$suggested = $this->ids( $this->widgets()->suggested_follows( $viewer, 5 ) );

			$this->assertNotContains(
				$i_blocked,
				$suggested,
				'The widget suggested a member the viewer had BLOCKED. Changing how the sample is drawn must not change who is allowed into it.'
			);
			$this->assertNotContains(
				$blocked_me,
				$suggested,
				'The widget suggested a member who had blocked the VIEWER. The exclusion is bidirectional and must stay that way.'
			);
		}
	}

	/**
	 * Someone the viewer already follows is never suggested.
	 *
	 * @return void
	 */
	public function test_an_already_followed_member_is_never_suggested(): void {
		$viewer   = self::factory()->user->create();
		$followed = self::factory()->user->create();
		self::factory()->user->create_many( 4 );

		buddynext_service( 'follows' )->follow( $viewer, $followed );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->assertNotContains(
				$followed,
				$this->ids( $this->widgets()->suggested_follows( $viewer, 5 ) ),
				'The widget suggested somebody the viewer already follows.'
			);
		}
	}

	/**
	 * No SQL on this path sorts by RAND() any more.
	 *
	 * The behavioural tests above would all still pass on the old query — it was correct,
	 * just ruinous. This is the one that pins the actual fix, so nobody reintroduces the
	 * full scan while keeping the tests green.
	 *
	 * @return void
	 */
	public function test_the_query_no_longer_sorts_the_community_by_rand(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/Sidebar/WidgetService.php'
		);

		// Strip comments so the explanation of the old bug does not count as the bug.
		$code = (string) preg_replace( '#/\*.*?\*/#s', '', $source );
		$code = (string) preg_replace( '#^\s*//.*$#m', '', $code );

		$this->assertStringNotContainsStringIgnoringCase(
			'RAND()',
			$code,
			'ORDER BY RAND() is back in WidgetService. It cannot use an index: on a large community it full-scans wp_users and filesorts the result, on the sidebar, on every logged-in page. A cache is not a fix for that - it is a place for it to hide.'
		);
	}
}
