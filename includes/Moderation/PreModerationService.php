<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Pre-moderation (approval queue) rule engine.
 *
 * Decides whether a newly authored post should be held with status='pending'
 * for a moderator to approve before it goes live, based on the community's
 * pre-moderation mode. The whole feature ships OFF by default — a community is
 * meant to bring people in, not gate them — so owners opt in only when they
 * actually see spam. When held, the post is created with status='pending';
 * PostService keeps it out of every feed (feeds filter status='published') and
 * fires no live side-effects until a moderator approves it.
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
		$mode  = (string) get_option( 'buddynext_premod_mode', 'off' );
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
	 * held), and even that only applies when an owner switches the mode on.
	 *
	 * @param int $user_id Author user ID.
	 * @return bool
	 */
	private function is_new_member( int $user_id ): bool {
		$limit = (int) get_option( 'buddynext_premod_new_member_count', 1 );
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
