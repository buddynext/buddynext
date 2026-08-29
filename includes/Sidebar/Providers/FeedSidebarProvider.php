<?php
/**
 * Feed-discovery right-sidebar provider (Free core).
 *
 * Registers the four self-chromed feed-discovery cards — greeting/streak,
 * trending topics, people to follow, your/discover spaces — that formerly
 * lived inline in `templates/partials/sidebar.php`. They render on the six
 * BN surfaces that share this widget set: activity feed, bookmarks, single
 * post, search results, leaderboard, and the hashtag feed.
 *
 * Every descriptor is `chrome => false`: its `render` closure emits the
 * card's own `<div class="bn-sidebar-card">` wrapper (moved verbatim from
 * the old partial into `templates/parts/sidebar-*.php`), so SidebarRegistry
 * echoes the body raw instead of double-wrapping it.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

/**
 * Feed-discovery sidebar widget descriptors.
 */
class FeedSidebarProvider {

	/**
	 * Surfaces this provider's widgets appear on.
	 *
	 * NOT `search`. The search page brings its own right column - date, sort,
	 * advanced member filters, saved and related searches - so adding the
	 * discovery widgets there produced FOUR columns at 1440 (rail, results,
	 * search filters, discovery) and squeezed the results themselves to 502px,
	 * narrower than either sidebar pair beside them. On a page whose entire job
	 * is finding something, the results are what deserves the width, and
	 * "Trending topics" / "People to follow" are a different kind of discovery
	 * competing with the one the member explicitly asked for.
	 *
	 * An owner who wants them back adds them through `buddynext_sidebar_widgets`,
	 * the same registry filter this provider registers on - no new seam needed.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'feed', 'bookmarks', 'single-post', 'leaderboard', 'hashtag' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the four feed-discovery descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( ! in_array( $surface, self::SURFACES, true ) ) {
			return $descriptors;
		}

		// Mirrors the null-guard formerly in templates/partials/sidebar.php:40-51 —
		// the sidebar_widgets binding is conditional (plug-and-play model), so each
		// render closure below falls back to an empty data set when it's absent.
		$service = function_exists( 'buddynext_service' ) && \BuddyNext\Core\Container::instance()->has( 'sidebar_widgets' )
			? buddynext_service( 'sidebar_widgets' )
			: null;

		$uid = get_current_user_id();

		// Spaces widgets are only relevant when the Spaces feature is enabled; mirrors
		// templates/partials/sidebar.php:48-50 — same guard, same "features" resolution.
		$spaces_on = function_exists( 'buddynext_service' )
			&& is_object( buddynext_service( 'features' ) )
			&& buddynext_service( 'features' )->is_enabled( 'spaces' );

		$descriptors[] = array(
			'id'        => 'greeting-streak',
			'priority'  => 10,
			'surfaces'  => self::SURFACES,
			'condition' => 'is_user_logged_in',
			'chrome'    => false,
			'render'    => static function () use ( $uid ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/sidebar-greeting-streak.php',
					array( 'user_id' => $uid )
				);
			},
		);

		$descriptors[] = array(
			'id'       => 'trending-topics',
			'priority' => 30,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function () use ( $service ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/sidebar-trending-topics.php',
					array(
						'sbar_trending' => null !== $service ? $service->trending_hashtags( 5 ) : array(),
					)
				);
			},
		);

		$descriptors[] = array(
			'id'        => 'people-to-follow',
			'priority'  => 40,
			'surfaces'  => self::SURFACES,
			'condition' => 'is_user_logged_in',
			'chrome'    => false,
			'render'    => static function () use ( $service, $uid ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/sidebar-people-to-follow.php',
					array(
						'sbar_suggested'   => null !== $service ? $service->suggested_follows( $uid, 3 ) : array(),
						'sbar_members_url' => \BuddyNext\Core\PageRouter::people_url(),
					)
				);
			},
		);

		$descriptors[] = array(
			'id'        => 'your-spaces',
			'priority'  => 50,
			'surfaces'  => self::SURFACES,
			'condition' => static function () use ( $spaces_on ): bool {
				return $spaces_on;
			},
			'chrome'    => false,
			'render'    => static function () use ( $service, $uid ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/sidebar-your-spaces.php',
					array(
						'sbar_spaces'     => null !== $service ? $service->joined_spaces( $uid, 4 ) : array(),
						'sbar_spaces_url' => \BuddyNext\Core\PageRouter::spaces_url(),
						'sidebar_user_id' => $uid,
					)
				);
			},
		);

		return $descriptors;
	}
}
