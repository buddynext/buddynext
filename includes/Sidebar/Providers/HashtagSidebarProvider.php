<?php
/**
 * Hashtag-page right-sidebar provider (Free core).
 *
 * Registers the three hashtag-specific cards — about this hashtag, related
 * hashtags, top contributors — that formerly lived in a bespoke in-body
 * `<aside class="bn-hashtag-sidebar">` in `templates/hashtags/feed.php`,
 * SEPARATE from the shell right sidebar. The hashtag page's shell sidebar is
 * now the feed-discovery set (FeedSidebarProvider, which already includes the
 * `hashtag` surface) PLUS these three cards, interleaved by priority.
 *
 * Every descriptor is `chrome => false`: its `render` closure calls the
 * existing self-chromed partials (`templates/parts/hashtag-sidebar-*.php`)
 * verbatim, unchanged, so SidebarRegistry echoes the body raw instead of
 * double-wrapping it. Each partial already returns silently when its
 * required data is empty (hashtag_slug / related_tags / top_contributors),
 * so the self-hiding behaviour from the old in-body aside is preserved.
 *
 * Data travels via `Surface::set( 'hashtag', [...] )`, set by feed.php once
 * all the hashtag-page locals (post_count_total, related_tags,
 * top_contributors, etc.) are computed, and read back here via
 * `Surface::context()` — same pattern as SpaceSidebarProvider.
 *
 * @package BuddyNext\Sidebar\Providers
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar\Providers;

use BuddyNext\Sidebar\Surface;

/**
 * Hashtag-page sidebar widget descriptors.
 */
class HashtagSidebarProvider {

	/**
	 * Surface this provider's widgets appear on.
	 *
	 * @var array<int,string>
	 */
	private const SURFACES = array( 'hashtag' );

	/**
	 * Hooks the descriptor callback onto the sidebar registry filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'widgets' ), 10, 2 );
	}

	/**
	 * Appends the three hashtag-specific descriptors when the surface matches.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors collected so far.
	 * @param string                         $surface     Current sidebar surface slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function widgets( array $descriptors, string $surface ): array {
		if ( 'hashtag' !== $surface ) {
			return $descriptors;
		}

		$ctx = Surface::context();

		$hashtag_slug     = isset( $ctx['hashtag_slug'] ) ? (string) $ctx['hashtag_slug'] : '';
		$post_count_total = isset( $ctx['post_count_total'] ) ? (int) $ctx['post_count_total'] : 0;
		$first_used_label = isset( $ctx['first_used_label'] ) ? (string) $ctx['first_used_label'] : '';
		$follows_hashtag  = isset( $ctx['follows_hashtag'] ) ? (bool) $ctx['follows_hashtag'] : false;
		$is_logged_in     = isset( $ctx['is_logged_in'] ) ? (bool) $ctx['is_logged_in'] : false;
		$related_tags     = isset( $ctx['related_tags'] ) ? (array) $ctx['related_tags'] : array();
		$current_user_id  = isset( $ctx['current_user_id'] ) ? (int) $ctx['current_user_id'] : 0;
		$following_map    = isset( $ctx['following_map'] ) ? (array) $ctx['following_map'] : array();
		$top_contributors = isset( $ctx['top_contributors'] ) ? (array) $ctx['top_contributors'] : array();

		// Card: About this hashtag.
		$descriptors[] = array(
			'id'       => 'hashtag-about',
			'priority' => 15,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function () use ( $hashtag_slug, $post_count_total, $first_used_label, $follows_hashtag, $is_logged_in ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/hashtag-sidebar-about.php',
					array(
						'hashtag_slug'     => $hashtag_slug,
						'post_count_total' => $post_count_total,
						'first_used_label' => $first_used_label,
						'follows_hashtag'  => $follows_hashtag,
						'is_logged_in'     => $is_logged_in,
					)
				);
			},
		);

		// Card: Related hashtags.
		$descriptors[] = array(
			'id'       => 'hashtag-related',
			'priority' => 25,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function () use ( $related_tags, $is_logged_in, $current_user_id, $following_map ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/hashtag-sidebar-related.php',
					array(
						'related_tags'    => $related_tags,
						'is_logged_in'    => $is_logged_in,
						'current_user_id' => $current_user_id,
						'following_map'   => $following_map,
					)
				);
			},
		);

		// Card: Top contributors.
		$descriptors[] = array(
			'id'       => 'hashtag-top-contributors',
			'priority' => 35,
			'surfaces' => self::SURFACES,
			'chrome'   => false,
			'render'   => static function () use ( $top_contributors ): void {
				if ( ! function_exists( 'buddynext_get_template' ) ) {
					return;
				}
				buddynext_get_template(
					'parts/hashtag-sidebar-top-contributors.php',
					array(
						'top_contributors' => $top_contributors,
					)
				);
			},
		);

		return $descriptors;
	}
}
