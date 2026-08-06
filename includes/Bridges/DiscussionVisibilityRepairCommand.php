<?php
/**
 * WP-CLI: realign linked discussions with their space's privacy.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use WP_CLI;

/**
 * Repairs discussions created world-readable for a non-public space.
 *
 * JetonomyBridge::provision_space_forum() used to hardcode the new discussion's
 * visibility to 'public', whatever the BuddyNext space actually was. Enabling
 * Discussion on a private or secret space therefore published its entire
 * conversation, silently, and nothing re-evaluated it afterwards. The code no
 * longer does that, but a fix at the creation site cannot reach discussions
 * already provisioned - those keep the wrong visibility forever.
 *
 * This is a deliberate one-off sweep rather than an activation migration: it
 * changes who can READ existing content, which is not something to do to a live
 * site as a side effect of an update. Owners run it when they choose, and
 * --dry-run tells them exactly what would change first.
 *
 * Chunked, because a large community holds many thousands of spaces.
 */
class DiscussionVisibilityRepairCommand {

	/**
	 * Rows scanned per chunk.
	 */
	private const CHUNK = 200;

	/**
	 * Realign linked discussions with their space's privacy.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext repair-discussion-visibility --dry-run
	 *     wp buddynext repair-discussion-visibility
	 *
	 * @param array $args       Positional args (unused - WP-CLI signature).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		global $wpdb;

		if ( ! class_exists( '\Jetonomy\Models\Space' ) ) {
			WP_CLI::error( 'Jetonomy is not active, so no discussions are linked.' );
		}

		$dry_run  = isset( $assoc_args['dry-run'] );
		$offset   = 0;
		$scanned  = 0;
		$repaired = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$spaces = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, type FROM {$wpdb->prefix}bn_spaces ORDER BY id ASC LIMIT %d OFFSET %d",
					self::CHUNK,
					$offset
				)
			);
			// phpcs:enable

			$in_chunk = count( $spaces );

			foreach ( $spaces as $space ) {
				$space_id = (int) $space->id;
				$forum_id = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );
				if ( $forum_id <= 0 ) {
					continue;
				}

				++$scanned;

				$discussion = \Jetonomy\Models\Space::find( $forum_id );
				if ( ! $discussion ) {
					continue;
				}

				$have = (string) ( $discussion->visibility ?? '' );
				$want = JetonomyBridge::discussion_visibility_for( (string) $space->type );
				if ( $have === $want ) {
					continue;
				}

				++$repaired;
				WP_CLI::log(
					sprintf(
						'%s space #%d "%s" (%s): discussion #%d %s -> %s',
						$dry_run ? 'WOULD FIX' : 'FIXED',
						$space_id,
						(string) $space->name,
						(string) $space->type,
						$forum_id,
						$have,
						$want
					)
				);

				if ( ! $dry_run ) {
					\Jetonomy\Models\Space::update( $forum_id, array( 'visibility' => $want ) );
				}
			}

			$offset += self::CHUNK;
		} while ( self::CHUNK === $in_chunk );

		WP_CLI::success(
			sprintf(
				'%d linked discussion(s) scanned, %d %s.',
				$scanned,
				$repaired,
				$dry_run ? 'would be corrected' : 'corrected'
			)
		);
	}
}
