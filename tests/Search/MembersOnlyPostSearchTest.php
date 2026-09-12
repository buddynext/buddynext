<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A members-only post's body must never be searchable by a guest.
 *
 * WHY THIS EXISTS — 1.2.0 pre-release smoke:
 *
 *     GET /buddynext/v1/search?q=... returned the FULL, unredacted body of a
 *     members-only post to a logged-OUT visitor.
 *
 * The members-only gate (1.2.0) is a column on bn_posts, separate from `privacy`.
 * The search indexer computed its row visibility from `privacy` alone, so a
 * members-only post (privacy `public`, members_only `1`) was indexed `public` —
 * and the guest search gate is literally `visibility = 'public'`. The whole point
 * of the feature (visible to members, never to guests) was defeated at the one
 * surface that reads straight from the index.
 *
 * The fix is the shared seam SearchIndexListener::post_index_visibility(): a post
 * is indexed `public` ONLY when privacy is public AND it is not members-only AND
 * the author is not a followers-only account. These tests hold that line — if the
 * first ever goes red, a members-only body is being handed to strangers again.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchIndexListener;
use WP_UnitTestCase;

/**
 * Post-level members-only content is gated out of the public search index.
 */
class MembersOnlyPostSearchTest extends WP_UnitTestCase {

	/**
	 * Insert a bn_posts row, index it through the real listener, and return the
	 * visibility its bn_search_index row was written with.
	 *
	 * @param array<string,mixed> $overrides Column overrides on a public, non-members post.
	 * @return string The indexed visibility ('public' or 'private'), or '' if not indexed.
	 */
	private function indexed_visibility( array $overrides ): string {
		global $wpdb;

		$author = (int) $this->factory->user->create();
		$row    = array_merge(
			array(
				'user_id'      => $author,
				'content'      => 'searchable body ' . uniqid( '', true ),
				'privacy'      => 'public',
				'members_only' => 0,
				'status'       => 'published',
				'space_id'     => 0,
				'created_at'   => current_time( 'mysql' ),
			),
			$overrides
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'bn_posts', $row );
		$post_id = (int) $wpdb->insert_id;

		( new SearchIndexListener() )->async_index_post( $post_id, (int) $row['user_id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT visibility FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				$post_id
			)
		);
	}

	/**
	 * THE LINE. A members-only post must never be indexed at public visibility.
	 *
	 * @return void
	 */
	public function test_a_members_only_post_is_never_indexed_public(): void {
		$this->assertSame(
			'private',
			$this->indexed_visibility( array( 'members_only' => 1 ) ),
			'PRIVACY LEAK: a members-only post was indexed at public visibility. The guest search gate '
			. 'is literally `visibility = "public"`, so its full body would be returned to a logged-out '
			. 'searcher.'
		);
	}

	/**
	 * A normal public post is still publicly searchable — the fix must not regress it.
	 *
	 * @return void
	 */
	public function test_a_normal_public_post_still_indexes_public(): void {
		$this->assertSame(
			'public',
			$this->indexed_visibility( array( 'members_only' => 0 ) ),
			'A normal public post must stay publicly searchable.'
		);
	}
}
