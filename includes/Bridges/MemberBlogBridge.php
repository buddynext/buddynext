<?php
/**
 * WB Member Blog bridge: the member's published articles, on their profile.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces a member's published WordPress posts as an Articles profile tab.
 *
 * BuddyNext's interest here is deliberately narrow and generic: WHICH MEMBER WROTE
 * A WordPress POST. It reads `post` through WP_Query and nothing else - no Member
 * Blog tables, no Member Blog functions beyond the one dashboard URL below, and no
 * BuddyNext code inside Member Blog. Member Blog is simply the thing that lets a
 * member write one from the front end; a post written in wp-admin lands on the tab
 * identically.
 *
 * The feed half already exists and is NOT here. {@see \BuddyNext\Feed\BlogPostListener}
 * publishes a typed `article` card for every tracked post type on publish, and has
 * since site tracking landed - so a member publishing an article already reaches
 * the community feed with no bridge involved. This class adds the missing half: the
 * profile surface, and the owner's route back to where they write.
 *
 * Owner decision 2026-08-02: the tab appears only when Member Blog is active. A site
 * without it gives members no front-end way to write, so the tab would be empty for
 * everyone except the admins - and the "Write a new post" affordance would have
 * nowhere to point.
 *
 * Reference, not embed (bridge contract rule 7): every row links OUT to the post and
 * the owner's controls link OUT to Member Blog's dashboard. BuddyNext renders no
 * editor and owns no authoring UI.
 */
class MemberBlogBridge {

	/**
	 * Integration key. Deliberately the SAME key BlogPostListener already declares.
	 *
	 * Both surfaces show one thing - the member's WordPress posts - so a site owner
	 * gets one control for it, with `feed` and `nav` as its two aspects, rather than
	 * two entries that can contradict each other.
	 */
	private const INTEGRATION = 'blog';

	/**
	 * Attach hooks. Called via the `buddynext_load_bridges` action (priority 25).
	 *
	 * Hooks are attached unconditionally and every surface self-guards at hook time,
	 * which is the contract every other bridge follows: `plugins_loaded:25` can still
	 * be earlier than some partner's own late bootstrap, so an activity check HERE
	 * would silently disable the tab on a site where the plugin is in fact present.
	 */
	public function init(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_nav_items' ) );
		add_filter( 'buddynext_integrations', array( $this, 'register_integration' ) );
	}

	/**
	 * Whether Member Blog is on this site.
	 *
	 * Guards on the VERSION CONSTANT, which Member Blog's entry file defines
	 * unconditionally on every request it is active for. That is the real bootstrap
	 * symbol.
	 *
	 * It is deliberately NOT `class_exists( '\Member_Blog_Compat' )`, which is the
	 * obvious-looking check and is wrong: that class is loaded conditionally, and on
	 * a BuddyNext profile page it is not loaded at all. Guarding on it made the tab
	 * resolve correctly everywhere EXCEPT the one screen it belongs on - present at
	 * `init`, gone by template render - which reads as a caching bug rather than a
	 * bad guard. A conditionally-loaded class is never a bootstrap symbol.
	 */
	public static function available(): bool {
		return defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' );
	}

	/**
	 * Declare the `nav` aspect on the existing blog integration entry.
	 *
	 * MERGES rather than replaces: BlogPostListener registers this same key for the
	 * feed aspect and may run either side of this filter. Overwriting the entry would
	 * silently turn the feed aspect off, or be turned off itself, depending on hook
	 * order - a class of bug that is invisible until someone reorders a hook.
	 *
	 * @param array<string,array<string,mixed>> $items Integration registry.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_integration( array $items ): array {
		$existing = isset( $items[ self::INTEGRATION ] ) && is_array( $items[ self::INTEGRATION ] )
			? $items[ self::INTEGRATION ]
			: array();

		$items[ self::INTEGRATION ] = array_merge(
			array(
				'label'      => __( 'Blog posts', 'buddynext' ),
				'version'    => null,
				'has_feed'   => true,
				'has_search' => false,
			),
			$existing,
			array(
				'has_nav' => true,
				// BlogPostListener registers this key with a null version, because
				// site tracking is core - there is no plugin behind it to name. Once
				// Member Blog IS present it is the thing supplying the surface, so
				// report its version: /app/config publishes these so a mobile client
				// can gate a module on the build actually installed, and a null there
				// means the app cannot tell an old Member Blog from no Member Blog.
				'version' => self::available() ? BUDDYPRESS_MEMBER_BLOG_VERSION : ( $existing['version'] ?? null ),
			)
		);

		return $items;
	}

	/**
	 * Register the Articles profile tab.
	 *
	 * @param NavRegistry $registry Nav registry.
	 */
	public function register_nav_items( NavRegistry $registry ): void {
		$enabled = static fn(): bool => self::available()
			&& buddynext_integration_enabled( self::INTEGRATION, 'nav' );

		$registry->register(
			array(
				'id'        => 'articles',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Articles', 'buddynext' ),
				// No icon, deliberately. Profile tabs are text-only - ProfileNav
				// declares none for any core tab, and the strip reads as one row of
				// words. An icon here is not a nicer tab, it is the only tab that
				// looks different.
				//
				// Note this is NOT contradicted by JetonomyBridge registering
				// `message-square` for Discussions: there is no message-square.svg in
				// assets/icons, so that declaration renders nothing and has always
				// been dead. `file-text` DOES exist, so copying the pattern from
				// Discussions produced the one tab in the strip with an icon.
				// After Discussions (60), before the Portfolio cluster - authored
				// long-form sits with the member's other social content.
				'priority'  => 65,
				'condition' => $enabled,
				'url'       => static fn( NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . 'articles/',
				'count'     => fn( NavContext $c ): int => $this->article_count( $c->subject_id, $c->viewer_id ),
				'render'    => function ( NavContext $c ): void {
					$this->render_profile_articles_panel( $c->subject_id, $c->viewer_id );
				},
			)
		);
	}

	/**
	 * How many articles this viewer may see from this member.
	 *
	 * Counted with a dedicated COUNT query rather than by measuring the list: the
	 * badge must not pull a prolific author's whole archive into memory to print a
	 * number.
	 *
	 * @param int $user_id   Profile owner.
	 * @param int $viewer_id Viewer (0 = logged out).
	 */
	public function article_count( int $user_id, int $viewer_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$statuses = $this->visible_statuses( $user_id, $viewer_id );

		$query = new \WP_Query(
			array(
				'author'                 => $user_id,
				'post_type'              => $this->tracked_types(),
				'post_status'            => $statuses,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Render the Articles panel.
	 *
	 * @param int $user_id   Profile owner.
	 * @param int $viewer_id Viewer (0 = logged out).
	 */
	public function render_profile_articles_panel( int $user_id, int $viewer_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination cursor on a public profile; no state changes.
		$paged    = isset( $_GET['bn_page'] ) ? absint( wp_unslash( $_GET['bn_page'] ) ) : 1;
		$paged    = max( 1, $paged );
		$per_page = (int) apply_filters( 'buddynext_profile_articles_per_page', 10 );
		$per_page = max( 1, min( 50, $per_page ) );

		$query = new \WP_Query(
			array(
				'author'                 => $user_id,
				'post_type'              => $this->tracked_types(),
				'post_status'            => $this->visible_statuses( $user_id, $viewer_id ),
				'posts_per_page'         => $per_page,
				'paged'                  => $paged,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);

		buddynext_get_template(
			'parts/profile/articles-panel.php',
			array(
				'profile_user_id' => $user_id,
				'viewer_id'       => $viewer_id,
				'is_owner'        => $viewer_id > 0 && $viewer_id === $user_id,
				'articles'        => $query->posts,
				'article_count'   => (int) $query->found_posts,
				'page'            => $paged,
				'total_pages'     => (int) $query->max_num_pages,
				'dashboard_url'   => $this->dashboard_url( $user_id ),
			)
		);

		wp_reset_postdata();
	}

	/**
	 * Post types treated as articles.
	 *
	 * Reads the SAME filter BlogPostListener uses for site tracking, so a site that
	 * teaches BuddyNext about a custom post type gets it on the profile tab and in
	 * the feed from one change rather than two.
	 *
	 * @return string[]
	 */
	private function tracked_types(): array {
		$types = (array) apply_filters( 'buddynext_site_tracking_post_types', array( 'post' ) );
		$types = array_values( array_filter( array_map( 'strval', $types ) ) );

		return array() === $types ? array( 'post' ) : $types;
	}

	/**
	 * Post statuses this viewer may see on this profile.
	 *
	 * Published only for everyone else. The owner (and anyone who can edit their
	 * posts) also sees drafts and pending review, because the tab is where they come
	 * to find a piece they have not finished - and Member Blog's whole submit-for-
	 * review flow produces `pending` posts that would otherwise be invisible here.
	 *
	 * @param int $user_id   Profile owner.
	 * @param int $viewer_id Viewer.
	 * @return string[]
	 */
	private function visible_statuses( int $user_id, int $viewer_id ): array {
		$is_owner = $viewer_id > 0 && $viewer_id === $user_id;

		if ( $is_owner || ( $viewer_id > 0 && user_can( $viewer_id, 'edit_others_posts' ) ) ) {
			return array( 'publish', 'pending', 'draft', 'future' );
		}

		return array( 'publish' );
	}

	/**
	 * Where this member writes and manages their posts.
	 *
	 * Member Blog's own dashboard when it can tell us (it resolves the configured
	 * page and any per-member routing), falling back to wp-admin for a member who can
	 * reach it. Returns '' when neither applies, and the template then renders no
	 * management affordance rather than a link that 404s or bounces off a login wall.
	 *
	 * @param int $user_id Profile owner.
	 */
	private function dashboard_url( int $user_id ): string {
		// Member Blog's own resolver when its compat class happens to be loaded - it
		// knows about per-platform profile routing that a page id cannot express.
		//
		// class_exists() alone: the class and get_dashboard_url() are one public API
		// that ships together, so a method_exists() on the literal class-string adds
		// no real protection. (The method_exists() guards elsewhere in Bridges/ are a
		// different case - those test runtime OBJECTS pulled from a partner's
		// container, where the shape genuinely varies.
		if ( class_exists( '\Member_Blog_Compat' ) ) {
			$url = (string) \Member_Blog_Compat::get_dashboard_url( $user_id );
			if ( '' !== $url && home_url() !== $url ) {
				return $url;
			}
		}

		// That class is NOT loaded on a BuddyNext profile page, which is precisely
		// where this link is rendered, so the configured page is read directly as
		// well. On a standalone site (BuddyNext is not BuddyPress, so this is the
		// mode Member Blog reports here) get_dashboard_url() resolves to exactly
		// this page anyway - same answer, one fewer dependency on load order.
		if ( function_exists( 'bp_member_blog_get_settings' ) ) {
			$page_id = (int) bp_member_blog_get_settings( 'bp_dashboard_page', 0 );
			if ( $page_id > 0 ) {
				$url = (string) get_permalink( $page_id );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return user_can( $user_id, 'edit_posts' ) ? admin_url( 'edit.php' ) : '';
	}
}
