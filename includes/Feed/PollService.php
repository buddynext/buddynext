<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Poll voting and results service.
 *
 * Manages vote recording for poll posts. Each user may cast exactly one vote
 * per poll (enforced by a UNIQUE KEY on bn_poll_votes). Votes also increment
 * the denormalised vote_count on the relevant bn_poll_options row.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use WP_Error;

/**
 * Handles poll voting and result reads.
 */
class PollService {

	/**
	 * Cast or switch a vote for an option in a poll.
	 *
	 * If the user has already voted for a different option, the old vote is
	 * removed and the new vote inserted (vote switching). If they click the
	 * same option they already voted for, the vote is removed (toggle off).
	 *
	 * Returns WP_Error('not_a_poll') if the post is not a poll.
	 *
	 * @param int $user_id   Voting user.
	 * @param int $post_id   Poll post ID.
	 * @param int $option_id Option to vote for.
	 * @return true|WP_Error
	 */
	public function vote( int $user_id, int $post_id, int $option_id ): true|WP_Error {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Verify the post is a poll.
		$type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT type FROM {$wpdb->prefix}bn_posts WHERE id = %d",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 'poll' !== $type ) {
			return new WP_Error(
				'not_a_poll',
				__( 'This post is not a poll.', 'buddynext' )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Fetch existing vote (returns option_id or null).
		$existing_option_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->prefix}bn_poll_votes
				 WHERE post_id = %d AND user_id = %d",
				$post_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null !== $existing_option_id ) {
			// Remove previous vote and decrement its count.
			$wpdb->delete(
				$wpdb->prefix . 'bn_poll_votes',
				array(
					'post_id' => $post_id,
					'user_id' => $user_id,
				),
				array( '%d', '%d' )
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_poll_options
					 SET vote_count = GREATEST(0, vote_count - 1)
					 WHERE id = %d",
					(int) $existing_option_id
				)
			);

			// Same option clicked again → toggle off, we're done.
			if ( (int) $existing_option_id === $option_id ) {
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return true;
			}
		}

		// Insert new vote and increment its count.
		$wpdb->insert(
			$wpdb->prefix . 'bn_poll_votes',
			array(
				'post_id'   => $post_id,
				'option_id' => $option_id,
				'user_id'   => $user_id,
			),
			array( '%d', '%d', '%d' )
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_poll_options
				 SET vote_count = vote_count + 1
				 WHERE id = %d",
				$option_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		/**
		 * Fires after a poll vote is cast or switched.
		 *
		 * Does NOT fire on toggle-off (clicking the same option to remove a
		 * vote) — that path returns early above. Vote switches (different
		 * option than previous) fire once, after the new vote is inserted.
		 *
		 * @param int $post_id   Poll post ID.
		 * @param int $option_id Option the user voted for.
		 * @param int $user_id   Voting user.
		 */
		do_action( 'buddynext_poll_voted', $post_id, $option_id, $user_id );

		return true;
	}

	/**
	 * Return the options and vote counts for a poll.
	 *
	 * @param int $post_id Poll post ID.
	 * @return array[] Array of option rows: id, option_text, display_order, vote_count.
	 */
	public function results( int $post_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, option_text, display_order, vote_count
				 FROM {$wpdb->prefix}bn_poll_options
				 WHERE post_id = %d
				 ORDER BY display_order ASC",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			fn( $r ) => array(
				'id'            => (int) $r['id'],
				'option_text'   => $r['option_text'],
				'display_order' => (int) $r['display_order'],
				'vote_count'    => (int) $r['vote_count'],
			),
			(array) $rows
		);
	}

	/**
	 * Return the option ID the user voted for, or null if they have not voted.
	 *
	 * @param int $user_id User to check.
	 * @param int $post_id Poll post ID.
	 * @return int|null Option ID or null.
	 */
	public function user_vote( int $user_id, int $post_id ): ?int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->prefix}bn_poll_votes
				 WHERE post_id = %d AND user_id = %d",
				$post_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $option_id ? (int) $option_id : null;
	}
}
