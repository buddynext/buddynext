<?php
/**
 * Community role and credit service.
 *
 * Provides a clean API for reading and writing a user's BuddyNext community
 * role (stored in wp_usermeta as bn_community_role) and credit balance
 * (stored in bn_user_credits).
 *
 * Role hierarchy (lowest → highest): member → moderator → admin
 *
 * Credits are optional. A zero balance is represented by the absence of a row
 * in bn_user_credits — no row is created unless credits are added.  Spending
 * credits returns false and makes no DB write when the balance is insufficient.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Manages community roles and user credit balances.
 */
class RoleService {

	/**
	 * Numeric weight for each community role (higher = more privileged).
	 *
	 * @var array<string, int>
	 */
	private const ROLE_HIERARCHY = array(
		'admin'     => 3,
		'moderator' => 2,
		'member'    => 1,
	);

	// ── Role helpers ──────────────────────────────────────────────────────────

	/**
	 * Return the community role for a user.
	 *
	 * Falls back to 'member' when no role is stored.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Role slug: 'admin', 'moderator', or 'member'.
	 */
	public function get_role( int $user_id ): string {
		$role = (string) get_user_meta( $user_id, 'bn_community_role', true );
		return isset( self::ROLE_HIERARCHY[ $role ] ) ? $role : 'member';
	}

	/**
	 * Set the community role for a user.
	 *
	 * Fires the buddynext_role_changed action after persisting.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $role    New role slug (admin | moderator | member).
	 * @return void
	 */
	public function set_role( int $user_id, string $role ): void {
		if ( ! isset( self::ROLE_HIERARCHY[ $role ] ) ) {
			return;
		}

		update_user_meta( $user_id, 'bn_community_role', $role );

		/**
		 * Fires when a user's community role is changed.
		 *
		 * @param int    $user_id WordPress user ID.
		 * @param string $role    New role slug.
		 */
		do_action( 'buddynext_role_changed', $user_id, $role );
	}

	/**
	 * Return true if the user holds the 'admin' community role.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function is_admin( int $user_id ): bool {
		return 'admin' === $this->get_role( $user_id );
	}

	/**
	 * Return true if the user is a moderator or higher (moderator or admin).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function is_moderator( int $user_id ): bool {
		return ( self::ROLE_HIERARCHY[ $this->get_role( $user_id ) ] ?? 0 ) >= self::ROLE_HIERARCHY['moderator'];
	}

	// ── Credit helpers ────────────────────────────────────────────────────────

	/**
	 * Return the current credit balance for a user.
	 *
	 * Returns 0 when no row exists (no credits have ever been added).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Non-negative balance.
	 */
	public function get_credits( int $user_id ): int {
		global $wpdb;

		$balance = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT balance FROM {$wpdb->prefix}bn_user_credits WHERE user_id = %d",
				$user_id
			)
		);

		return (int) ( $balance ?? 0 );
	}

	/**
	 * Add credits to a user's balance.
	 *
	 * Creates the row if it does not exist yet.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $amount  Positive integer to add.
	 * @return void
	 */
	public function add_credits( int $user_id, int $amount ): void {
		global $wpdb;

		$amount = max( 0, $amount );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_user_credits (user_id, balance)
				 VALUES (%d, %d)
				 ON DUPLICATE KEY UPDATE balance = balance + %d",
				$user_id,
				$amount,
				$amount
			)
		);
	}

	/**
	 * Deduct credits from a user's balance.
	 *
	 * Returns false without modifying the balance when the balance is
	 * insufficient.  On success returns true and fires buddynext_credits_spent.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param int    $amount  Positive integer to deduct.
	 * @param string $reason  Short description for audit purposes.
	 * @return bool True on success, false when balance is too low.
	 */
	public function spend_credits( int $user_id, int $amount, string $reason ): bool {
		$amount = max( 0, $amount );

		if ( $this->get_credits( $user_id ) < $amount ) {
			return false;
		}

		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_user_credits
				 SET balance = GREATEST(0, balance - %d)
				 WHERE user_id = %d",
				$amount,
				$user_id
			)
		);

		/**
		 * Fires when credits are successfully spent.
		 *
		 * @param int    $user_id WordPress user ID.
		 * @param int    $amount  Amount deducted.
		 * @param string $reason  Reason supplied by the caller.
		 */
		do_action( 'buddynext_credits_spent', $user_id, $amount, $reason );

		return true;
	}
}
