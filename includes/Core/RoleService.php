<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Community role and credit service.
 *
 * Provides a clean API for reading and writing a user's BuddyNext community
 * role and credit balance — both stored in wp_usermeta. Roles use the
 * bn_community_role key; credit balances use the bn_credits key (integer).
 *
 * Role hierarchy (lowest → highest): member → moderator → admin
 *
 * Credits are optional. A zero balance is represented by the absence of the
 * meta entry — no row is created unless credits are added. Spending credits
 * returns false and makes no DB write when the balance is insufficient.
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

	/**
	 * The user_meta key holding the credit balance.
	 */
	public const CREDITS_META = 'bn_credits';

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
	 * Returns 0 when no meta exists (no credits have ever been added).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Non-negative balance.
	 */
	public function get_credits( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::CREDITS_META, true );
	}

	/**
	 * Add credits to a user's balance.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $amount  Positive integer to add.
	 * @return void
	 */
	public function add_credits( int $user_id, int $amount ): void {
		global $wpdb;

		$amount = max( 0, $amount );
		if ( 0 === $amount ) {
			return;
		}

		// Atomic increment in SQL so concurrent credit grants don't clobber each
		// other (read-modify-write via get_credits() + update_user_meta() lost
		// updates under concurrency — a correctness bug for a monetary balance).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS SIGNED) + %d
				 WHERE user_id = %d AND meta_key = %s",
				$amount,
				$user_id,
				self::CREDITS_META
			)
		);

		// No balance row yet — create it. The only residual race is two truly
		// simultaneous first-grants for a brand-new user (benign + vanishingly
		// rare); every subsequent grant takes the atomic UPDATE path above.
		if ( empty( $updated ) ) {
			add_user_meta( $user_id, self::CREDITS_META, $amount, true );
		}

		wp_cache_delete( $user_id, 'user_meta' );
	}

	/**
	 * Replace the user's credit balance outright.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $amount  Non-negative balance to set.
	 * @return void
	 */
	public function set_credits( int $user_id, int $amount ): void {
		update_user_meta( $user_id, self::CREDITS_META, max( 0, $amount ) );
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
		global $wpdb;

		$amount = max( 0, $amount );
		if ( 0 === $amount ) {
			return true;
		}

		// Atomic conditional deduction: the row is decremented ONLY when the
		// stored balance still covers the amount, in a single statement. This
		// closes the check-then-write race where two concurrent spends both read
		// the same balance and each deduct, overdrawing the account. rows_affected
		// is 1 only when the guard matched (sufficient balance + row exists).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS SIGNED) - %d
				 WHERE user_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) >= %d",
				$amount,
				$user_id,
				self::CREDITS_META,
				$amount
			)
		);

		if ( (int) $wpdb->rows_affected < 1 ) {
			// Insufficient balance (or no balance row) — no write happened.
			return false;
		}

		wp_cache_delete( $user_id, 'user_meta' );

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

	/**
	 * Deduct credits flooring at zero (used by webhook deductions where the
	 * caller does not require sufficiency).
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $amount  Positive integer to subtract.
	 * @return void
	 */
	public function deduct_credits( int $user_id, int $amount ): void {
		global $wpdb;

		$amount = max( 0, $amount );
		if ( 0 === $amount ) {
			return;
		}

		// Atomic floored decrement so concurrent webhook deductions don't lose
		// updates. GREATEST(0, ...) keeps the balance non-negative without a
		// separate read. No-op when the row doesn't exist (nothing to deduct).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) - %d)
				 WHERE user_id = %d AND meta_key = %s",
				$amount,
				$user_id,
				self::CREDITS_META
			)
		);

		wp_cache_delete( $user_id, 'user_meta' );
	}
}
