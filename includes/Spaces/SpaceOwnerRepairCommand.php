<?php
/**
 * WP-CLI: repair spaces whose owner no longer exists.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use WP_CLI;

/**
 * Finds spaces whose owner_id points at a deleted user and hands them to an heir.
 *
 * SpaceSuccession only protects deletions from now on. Sites that ran an earlier
 * release already carry spaces orphaned by a deleted owner; this sweep repairs
 * them. Chunked — a site may hold many thousands of spaces.
 */
class SpaceOwnerRepairCommand {

	/**
	 * Rows scanned per chunk.
	 */
	private const CHUNK = 200;

	/**
	 * Repair orphaned space owners.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext repair-space-owners --dry-run
	 *     wp buddynext repair-space-owners
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		global $wpdb;

		$dry_run    = isset( $assoc_args['dry-run'] );
		$succession = new SpaceSuccession();
		$spaces     = new SpaceService();
		$offset     = 0;
		$repaired   = 0;
		$flagged    = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$orphans = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.name, s.owner_id
					 FROM {$wpdb->prefix}bn_spaces s
					 LEFT JOIN {$wpdb->users} u ON u.ID = s.owner_id
					 WHERE u.ID IS NULL
					 ORDER BY s.id ASC
					 LIMIT %d OFFSET %d",
					self::CHUNK,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			// Rows that will STILL match the orphan query after this pass. A repaired
			// space drops out of the result set, so on a real run the window would
			// otherwise re-read the same unrepairable rows forever; paging past
			// exactly the leftovers is what makes the loop terminate.
			$left_behind = 0;

			foreach ( $orphans as $orphan ) {
				$space_id = (int) $orphan->id;
				$heir     = $succession->find_heir( $space_id, (int) $orphan->owner_id );

				if ( $heir <= 0 ) {
					++$flagged;
					++$left_behind;
					WP_CLI::warning( sprintf( 'Space %d (%s): no heir found - flagged needs_owner.', $space_id, $orphan->name ) );
					if ( ! $dry_run ) {
						update_space_meta( $space_id, 'needs_owner', '1' );
					}
					continue;
				}

				if ( $dry_run ) {
					WP_CLI::log( sprintf( 'Space %d (%s): would assign owner %d.', $space_id, $orphan->name, $heir ) );
					++$repaired;
					continue;
				}

				$result = $spaces->assign_owner( $space_id, $heir, null );
				if ( true === $result ) {
					delete_space_meta( $space_id, 'needs_owner' );
					++$repaired;
					WP_CLI::log( sprintf( 'Space %d (%s): owner set to %d.', $space_id, $orphan->name, $heir ) );
				} else {
					++$left_behind;
					WP_CLI::warning( sprintf( 'Space %d (%s): %s', $space_id, $orphan->name, $result->get_error_message() ) );
				}
			}

			$fetched = count( $orphans );

			// A dry run changes nothing, so the whole chunk stays in the result set
			// and we page straight past it. A real run only needs to page past the
			// rows it could not fix.
			$offset += $dry_run ? $fetched : $left_behind;
		} while ( self::CHUNK === $fetched );

		WP_CLI::success(
			sprintf(
				'%s %d space(s); %d still need an owner.',
				$dry_run ? 'Would repair' : 'Repaired',
				$repaired,
				$flagged
			)
		);
	}
}
