<?php
/**
 * Membership on a network: who the community counts as a member.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * One community lives on the network's main site, and a member is a user with a
 * role there (WordPress's own model). A network user who has none becomes a
 * member on their first community action, never by just browsing. Single sites
 * are untouched: every method answers "no restriction" outside a network.
 */
class NetworkMembership {

	/**
	 * Whether the community turns this person away right now.
	 *
	 * True when their sign-up is still waiting for approval, or when they are a
	 * network user who is not a member yet and the community is closed or
	 * invite-only. A network user who is allowed in is joined here, but only on a
	 * write request: a page view or a button that merely asks "may I?" never adds
	 * anyone.
	 *
	 * @param int $user_id User being checked.
	 * @return bool
	 */
	public function refuses( int $user_id ): bool {
		if ( $user_id <= 0 || ! is_multisite() ) {
			return false;
		}

		if ( (bool) get_user_meta( $user_id, 'bn_pending_approval', true ) ) {
			return true;
		}

		if ( is_user_member_of_blog( $user_id, get_main_site_id() ) ) {
			return false;
		}

		$mode = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );
		if ( in_array( $mode, array( 'closed', 'invite' ), true ) ) {
			return true;
		}

		if ( ! $this->is_write_request() ) {
			return false;
		}

		$this->join( $user_id, $mode );
		return (bool) get_user_meta( $user_id, 'bn_pending_approval', true );
	}

	/**
	 * Add a network user to the main site, the way a new sign-up would be.
	 *
	 * @param int    $user_id User to add.
	 * @param string $mode    Registration mode on the main site.
	 * @return void
	 */
	private function join( int $user_id, string $mode ): void {
		add_user_to_blog( get_main_site_id(), $user_id, (string) get_option( 'default_role', 'subscriber' ) );

		if ( 'approval' === $mode ) {
			update_user_meta( $user_id, 'bn_pending_approval', '1' );
			$user = get_userdata( $user_id );

			/** This action is documented in AuthController::register(). */
			do_action( 'buddynext_registration_pending', $user_id, $user ? $user->user_email : '' );
		}

		/**
		 * A network user has just become a member of the community.
		 *
		 * The listeners that act on a new sign-up (search index, onboarding,
		 * auto-join, welcome email, webhook) hook this too.
		 *
		 * @param int $user_id The new member.
		 */
		do_action( 'buddynext_network_user_joined', $user_id );
	}

	/**
	 * Whether this request changes data (a REST write), as opposed to reading.
	 *
	 * @return bool
	 */
	private function is_write_request(): bool {
		if ( ! wp_is_serving_rest_request() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );
	}
}
