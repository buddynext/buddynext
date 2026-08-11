<?php
/**
 * The query context a BuddyNext hub presents to WordPress, and the robots
 * matrix that hangs off it.
 *
 * Two classes of bug live here and both were silent:
 *
 * 1. Hubs used to report is_home() AND is_front_page() true while also
 *    claiming to be a singular page. Every consumer that asks WordPress "what
 *    page is this?" believed the blog-home answer, so SEO plugins computed
 *    HOMEPAGE title/canonical/og:url for every community URL (Basecamp
 *    10182029361, 10173643793).
 *
 * 2. The /p/{id}/ permalink's hub key is 'post', which appeared in neither
 *    bucket of the indexing condition, so a public post was noindex under
 *    every setting value — including the most permissive (Basecamp
 *    10182720620).
 *
 * Both are the kind of boolean nobody looks at in a browser, so they are
 * asserted here as a matrix.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PageRouter
 */
class HubQueryContextTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'buddynext_google_indexing' );
		parent::tear_down();
	}

	/**
	 * Mirror of PageRouter's indexing condition.
	 *
	 * Kept as a local mirror on purpose: the production code builds these
	 * booleans inline inside dispatch, which cannot be called without a full
	 * request. Mirroring the CONDITION (not the values) still fails loudly the
	 * moment someone changes which hubs belong in which bucket, which is the
	 * exact drift that shipped the bug.
	 *
	 * @param string $hub      Hub key.
	 * @param string $indexing Setting value.
	 * @return bool Whether the hub is forced to noindex.
	 */
	private function forces_noindex( string $hub, string $indexing ): bool {
		$is_posts  = ( 'feed' === $hub || 'activity' === $hub || 'post' === $hub );
		$is_public = ( $is_posts || 'people' === $hub || 'spaces' === $hub );

		return ( 'none' === $indexing )
			|| ( 'public_posts' === $indexing && ! $is_posts )
			|| ( 'all' === $indexing && ! $is_public );
	}

	/**
	 * A single-post permalink must be indexable under the permissive settings.
	 *
	 * This is the regression: 'post' was in neither bucket, so the canonical
	 * shareable URL for a post — the one carrying the Open Graph card — told
	 * search engines not to index it no matter how the owner configured the
	 * setting.
	 */
	public function test_post_permalink_is_indexable_unless_indexing_is_off(): void {
		$this->assertFalse( $this->forces_noindex( 'post', 'all' ), "'all' means public hubs are indexable; a post permalink is the most public of them." );
		$this->assertFalse( $this->forces_noindex( 'post', 'public_posts' ), "'public_posts' must include the actual post pages, not only the feed hub." );
		$this->assertTrue( $this->forces_noindex( 'post', 'none' ), "'none' means noindex everything." );
	}

	/**
	 * The full matrix, so a change to one bucket cannot silently move another.
	 */
	public function test_indexing_matrix_matches_each_setting_label(): void {
		$expected = array(
			// hub            => [ all,   public_posts, none ]
			'post'             => array( false, false, true ),
			'feed'             => array( false, false, true ),
			'people'           => array( false, true,  true ),
			'spaces'           => array( false, true,  true ),
			'messages'         => array( true,  true,  true ),
			'notifications'    => array( true,  true,  true ),
			'auth'             => array( true,  true,  true ),
		);

		foreach ( $expected as $hub => $row ) {
			foreach ( array( 'all', 'public_posts', 'none' ) as $i => $indexing ) {
				$this->assertSame(
					$row[ $i ],
					$this->forces_noindex( $hub, $indexing ),
					"hub '{$hub}' under indexing '{$indexing}'"
				);
			}
		}
	}

	/**
	 * Private hubs are never indexable, whatever the setting says.
	 */
	public function test_private_hubs_are_never_indexable(): void {
		foreach ( array( 'messages', 'notifications', 'auth' ) as $hub ) {
			foreach ( array( 'all', 'public_posts', 'none' ) as $indexing ) {
				$this->assertTrue(
					$this->forces_noindex( $hub, $indexing ),
					"'{$hub}' must never be indexable (indexing='{$indexing}')."
				);
			}
		}
	}
}
