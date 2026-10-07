<?php
/**
 * Author links go to the member's BuddyNext profile.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use BuddyNext\Bridges\MemberBlogBridge;
use BuddyNext\Contracts\ListenerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * On a BuddyNext site the profile is the member's page, so the link a byline, author
 * box or archive header is handed goes there instead of to a WordPress author archive
 * the community does not style.
 *
 * Bylines and author boxes on any theme or plugin build their link with
 * get_author_posts_url(), which lands on `author_link`; filtering it once means no
 * theme carries a copy of this rule. The /author/ route is left alone, only the link a
 * visitor is handed changes.
 *
 * Only while the profile can do the archive's job: Member Blog active and the Articles
 * tab on (MemberBlogBridge::articles_tab_available()). Without it the profile lists no
 * posts, so a byline keeps the WordPress author archive, which does.
 *
 * Stays out of everything that is not a front-end page: admin, ajax, cron, REST, feeds
 * and the users sitemap keep the WordPress URL. A viewer who may not see the profile
 * also keeps it, so a byline never leads to a wall. The owner's control is the blog
 * Integrations `nav` switch (the same switch that hides the Articles tab); developers
 * can return false from `buddynext_author_link_to_profile`.
 */
class AuthorLinkListener implements ListenerInterface {

	/**
	 * Per-request answers to "may this viewer see this author's profile", keyed viewer:author.
	 *
	 * @var array<string,bool>
	 */
	private array $profile_visible = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'author_link', array( $this, 'filter_author_link' ), 10, 2 );
	}

	/**
	 * Swap an author archive URL for the member's profile URL.
	 *
	 * @param string $link      Author archive URL.
	 * @param int    $author_id Author user ID.
	 * @return string
	 */
	public function filter_author_link( $link, $author_id ) {
		$author_id = (int) $author_id;
		if ( $author_id <= 0 || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || '' !== (string) get_query_var( 'sitemap' ) ) {
			return $link;
		}
		// Only while the profile can list this author's posts (the Articles tab);
		// otherwise the WordPress author archive is the page that does that job.
		if ( ! MemberBlogBridge::articles_tab_available() ) {
			return $link;
		}

		/**
		 * Whether an author link should point at the member's BuddyNext profile.
		 *
		 * @param bool $to_profile True to use the profile URL (default).
		 * @param int  $author_id  Author user ID.
		 */
		if ( ! apply_filters( 'buddynext_author_link_to_profile', true, $author_id ) ) {
			return $link;
		}

		// A byline list repeats the same author; ask the privacy service once each.
		$viewer = get_current_user_id();
		$key    = $viewer . ':' . $author_id;
		if ( ! isset( $this->profile_visible[ $key ] ) ) {
			$privacy                       = buddynext_service( 'privacy' );
			$this->profile_visible[ $key ] = $privacy instanceof \BuddyNext\SocialGraph\PrivacyService
				&& $privacy->can_view_profile( $viewer, $author_id );
		}
		if ( ! $this->profile_visible[ $key ] ) {
			return $link;
		}

		$profile = buddynext_member_url( $author_id );

		return '' !== $profile ? $profile : $link;
	}
}
