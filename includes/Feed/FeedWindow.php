<?php
/**
 * The page window every "Load more" list renders: how many items, from where.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * One reading of `?shown=` / `?cursor=` and one rule for the links below a list.
 *
 * A post card is an Interactivity island and the API hydrates only islands
 * present at first paint, so "Load more" GROWS the page server-side (`shown`)
 * instead of injecting cards (see templates/parts/feed-load-more.php). Growing
 * re-renders the whole list, so it stops at a ceiling of six pages; past it the
 * member continues on a fresh page from the keyset cursor - bounded render,
 * unbounded reach. Home, Explore, Bookmarks and the profile tabs all page this
 * way, so the arithmetic and the link rule live here once.
 */
final class FeedWindow {

	/**
	 * Pages a list may grow to before continuing on a fresh page.
	 */
	public const MAX_PAGES = 6;

	/**
	 * Read the window from the request.
	 *
	 * `shown` is clamped to whole pages and to the ceiling, so a crafted URL can
	 * never ask for an unbounded render. The ceiling never exceeds what one
	 * service read returns (FeedService::MAX_PER_PAGE), or the render would be
	 * silently truncated and "Load more" would go dead.
	 *
	 * @param int $page_size Items per page.
	 * @return array{page_size: int, shown: int, max: int, cursor: string}
	 */
	public static function read( int $page_size ): array {
		$page_size = max( 1, $page_size );
		$max       = min( $page_size * self::MAX_PAGES, FeedService::MAX_PER_PAGE );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only paging params.
		$raw    = isset( $_GET['shown'] ) ? absint( wp_unslash( $_GET['shown'] ) ) : 0;
		$cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'page_size' => $page_size,
			'shown'     => max( $page_size, min( $max, (int) ( ceil( $raw / $page_size ) * $page_size ) ) ),
			'max'       => $max,
			'cursor'    => $cursor,
		);
	}

	/**
	 * The links below the list, for parts/feed-load-more.php.
	 *
	 * - More to show and under the ceiling: `more_url` grows this page. It keeps
	 *   the current cursor, or a member reading a continuation page would be sent
	 *   back to the newest items they already scrolled past.
	 * - More to show at the ceiling: `next_url` starts a fresh page at the cursor.
	 * - Nothing more: both empty (the partial shows the end note).
	 *
	 * @param string               $base_url    The list's own URL, with any filter args but no shown/cursor.
	 * @param array<string, mixed> $window      Result of read().
	 * @param string|null          $next_cursor Cursor after the last rendered item; null/'' at the end.
	 * @return array{more_url: string, next_url: string}
	 */
	public static function links( string $base_url, array $window, ?string $next_cursor ): array {
		$links = array(
			'more_url' => '',
			'next_url' => '',
		);
		if ( null === $next_cursor || '' === $next_cursor ) {
			return $links;
		}

		$base = remove_query_arg( array( 'shown', 'cursor' ), $base_url );
		if ( (int) $window['shown'] < (int) $window['max'] ) {
			$args = array( 'shown' => (int) $window['shown'] + (int) $window['page_size'] );
			if ( '' !== (string) $window['cursor'] ) {
				$args['cursor'] = rawurlencode( (string) $window['cursor'] );
			}
			$links['more_url'] = add_query_arg( $args, $base );
		} else {
			$links['next_url'] = add_query_arg( 'cursor', rawurlencode( $next_cursor ), $base );
		}
		return $links;
	}
}
