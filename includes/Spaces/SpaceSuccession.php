<?php
/**
 * Space ownership succession.
 *
 * Guarantees a space always has a valid owner after its owner leaves the site.
 * Without this, deleting a user leaves bn_spaces.owner_id pointing at a ghost
 * while their owner row is purged — the space becomes permanently unmanageable
 * by anyone but a site admin, with no UI to recover it.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

/**
 * Promotes an heir when a space owner is deleted or GDPR-erased.
 */
class SpaceSuccession {

	/**
	 * Hook the canonical user-purge signal.
	 *
	 * `buddynext_purge_user_data` is fired by MemberCleanupService on BOTH the
	 * admin-delete and the GDPR-erase path, so hooking it (rather than
	 * `deleted_user`) covers both. It runs after the user's own member rows are
	 * removed, while bn_spaces.owner_id still points at them — which is exactly
	 * what we key on.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_purge_user_data', array( $this, 'on_user_purged' ), 10, 2 );
	}

	/**
	 * Reassign every space the purged user owned.
	 *
	 * @param int    $user_id  The removed member's id.
	 * @param string $_context 'delete' | 'gdpr-erase' (unused — hook contract).
	 * @return void
	 */
	public function on_user_purged( int $user_id, string $_context = 'delete' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by hook contract.
		if ( $user_id > 0 ) {
			$this->reassign_spaces_owned_by( $user_id );
		}
	}

	/**
	 * Reassign every space owned by a user. Also the CLI repair entry point.
	 *
	 * @param int $user_id Outgoing owner.
	 * @return int Number of spaces successfully reassigned.
	 */
	public function reassign_spaces_owned_by( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_spaces WHERE owner_id = %d", $user_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $space_ids ) ) {
			return 0;
		}

		$spaces     = new SpaceService();
		$reassigned = 0;

		foreach ( $space_ids as $space_id ) {
			$space_id = (int) $space_id;
			$heir     = $this->find_heir( $space_id, $user_id );

			if ( $heir <= 0 ) {
				// Nobody to hand it to. Flag it rather than leave a silent ghost.
				update_space_meta( $space_id, 'needs_owner', '1' );
				continue;
			}

			if ( true === $spaces->assign_owner( $space_id, $heir, null ) ) {
				delete_space_meta( $space_id, 'needs_owner' );
				++$reassigned;
			}
		}

		return $reassigned;
	}

	/**
	 * Pick the heir for a space.
	 *
	 * Chain: longest-serving ACTIVE moderator, else the site-admin fallback.
	 *
	 * A plain member is deliberately NEVER promoted: an owner cannot leave a
	 * space, so auto-promoting a passive member would trap them permanently in a
	 * role they never accepted. A moderator has already taken responsibility for
	 * the space; a site admin can always hand it on.
	 *
	 * @param int $space_id          Space needing an owner.
	 * @param int $outgoing_owner_id The owner being removed (never eligible).
	 * @return int Heir user id, or 0 when none can be resolved.
	 */
	public function find_heir( int $space_id, int $outgoing_owner_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$heir = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND status = 'active' AND role = 'moderator' AND user_id <> %d
				 ORDER BY joined_at ASC, user_id ASC
				 LIMIT 1",
				$space_id,
				$outgoing_owner_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $heir <= 0 ) {
			$heir = $this->fallback_admin_id( $space_id );
		}

		/**
		 * Filters the heir chosen for a space whose owner is being removed.
		 *
		 * Return 0 to leave the space without an owner (it is flagged with the
		 * `needs_owner` space meta instead).
		 *
		 * @param int $heir              Resolved heir user id (0 = none).
		 * @param int $space_id          Space needing an owner.
		 * @param int $outgoing_owner_id The owner being removed.
		 */
		$heir = (int) apply_filters( 'buddynext_space_successor_id', $heir, $space_id, $outgoing_owner_id );

		// The outgoing owner is never a valid heir, and a heir who is not a real
		// user would just recreate the dangling pointer we exist to prevent.
		if ( $heir === $outgoing_owner_id || ( $heir > 0 && ! get_userdata( $heir ) ) ) {
			return 0;
		}

		return max( 0, $heir );
	}

	/**
	 * Last-resort owner: a site administrator.
	 *
	 * @param int $space_id Space needing an owner.
	 * @return int Admin user id, or 0 when none can be resolved.
	 */
	private function fallback_admin_id( int $space_id ): int {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		$fallback = empty( $admins ) ? 0 : (int) $admins[0];

		/**
		 * Filters the site-admin fallback owner for an orphaned space.
		 *
		 * @param int $fallback Admin user id (0 = none resolved).
		 * @param int $space_id Space needing an owner.
		 */
		return (int) apply_filters( 'buddynext_space_successor_fallback_user_id', $fallback, $space_id );
	}
}
