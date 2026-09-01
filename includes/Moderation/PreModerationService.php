<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Pre-moderation (approval queue) rule engine.
 *
 * Decides whether a newly authored post should be held with status='pending'
 * for a moderator to approve before it goes live. When held, the post is created
 * with status='pending'; PostService keeps it out of every feed (feeds filter
 * status='published') and fires no live side-effects until a moderator approves
 * it.
 *
 * DEVELOPER-ONLY as of 1.1.6. There is no owner-facing setting any more: this is
 * a Facebook/Twitter-shaped community, where you post and it appears and
 * moderation is reactive (reports, auto-hide, strikes, suspensions, Pro rules).
 * Review-before-publish is the opposite product, and offering it as a switch
 * invited owners into a queue they then had to staff — on a feature whose own
 * help text told them not to turn it on. Owner directive 2026-08-27.
 *
 * The engine stays, because a site with a real compliance requirement (schools,
 * healthcare, regulated brand communities) still needs it. It is switched on in
 * code now, not in wp-admin:
 *
 *     add_filter( 'buddynext_premod_mode', fn() => 'new_members' );
 *
 * Anything held before this release stays reviewable — Moderation → Pending
 * renders whenever pending posts exist, regardless of mode.
 *
 * @package BuddyNext\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Moderation;

/**
 * Evaluates the pre-moderation hold rules for a post about to be created.
 */
final class PreModerationService {

	/**
	 * Resolve the active pre-moderation mode.
	 *
	 * @return string One of: off, new_members, links, all.
	 */
	public static function mode(): string {
		/**
		 * Pre-moderation mode. Developer-only — there is no owner setting.
		 *
		 * Deliberately NOT read from an option: the `buddynext_premod_mode`
		 * option is retired, and honouring a stale stored value would leave a
		 * site silently holding posts with no UI to turn it off or to find them.
		 *
		 * @since 1.1.6
		 *
		 * @param string $mode One of: off, new_members, links, all.
		 */
		$mode  = (string) apply_filters( 'buddynext_premod_mode', 'off' );
		$valid = array( 'off', 'new_members', 'links', 'all' );
		return in_array( $mode, $valid, true ) ? $mode : 'off';
	}

	/**
	 * Whether a post by this author with this data should be held for approval.
	 *
	 * Admins and users who can moderate are never held; held content only makes
	 * sense for ordinary members. Returns false whenever the mode is 'off'.
	 *
	 * @param int                  $user_id Author user ID.
	 * @param array<string, mixed> $data    Post data (content, link_url, space_id).
	 * @return bool
	 */
	public function should_hold( int $user_id, array $data ): bool {
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return false;
		}

		// Never hold staff: admins and anyone with the moderation capability.
		// buddynext_moderate is the plugin's own optional moderator capability, exposed
		// so site owners can grant moderation to a non-admin role; it has no core meta map.
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'buddynext_moderate' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- plugin-own custom moderator capability.
			return false;
		}

		/**
		 * Allow integrations to exempt trusted members from pre-moderation.
		 *
		 * @param bool                 $trusted Default false.
		 * @param int                  $user_id Author user ID.
		 * @param array<string, mixed> $data    Post data.
		 */
		if ( (bool) apply_filters( 'buddynext_premod_is_trusted', false, $user_id, $data ) ) {
			return false;
		}

		switch ( $mode ) {
			case 'all':
				return true;
			case 'links':
				return $this->has_link( $data );
			case 'new_members':
				return $this->is_new_member( $user_id );
		}

		return false;
	}

	/**
	 * Whether the post carries a link (explicit link_url or an inline URL).
	 *
	 * @param array<string, mixed> $data Post data.
	 * @return bool
	 */
	private function has_link( array $data ): bool {
		if ( ! empty( $data['link_url'] ) ) {
			return true;
		}
		return 1 === preg_match( '#https?://#i', (string) ( $data['content'] ?? '' ) );
	}

	/**
	 * Whether the author is still a "new member" — fewer than the configured
	 * number of already-published posts. Once enough posts have been approved
	 * the member posts freely. Default count is 1 (only the very first post is
	 * held), and even that only applies when the mode filter switches it on.
	 *
	 * @param int $user_id Author user ID.
	 * @return bool
	 */
	private function is_new_member( int $user_id ): bool {
		/**
		 * How many of a new member's first posts to hold. Developer-only,
		 * alongside the mode filter above.
		 *
		 * @since 1.1.6
		 *
		 * @param int $limit Posts to review before the member posts freely.
		 */
		$limit = (int) apply_filters( 'buddynext_premod_new_member_count', 1 );
		if ( $limit < 1 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$published = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $published < $limit;
	}
}
