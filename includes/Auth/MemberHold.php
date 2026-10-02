<?php
/**
 * Account holds that an add-on places on a member.
 *
 * @package BuddyNext
 */

declare(strict_types=1);

namespace BuddyNext\Auth;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Keeps a held member on the pages an add-on names, on the web and over REST.
 *
 * Free's own holds (email verification, forced 2FA) are fixed. This is the seam
 * for a hold Free does not know about: an add-on answers the filter
 * `buddynext_member_hold` with where to send the member and what stays open, and
 * Free enforces it with the same rules as its own holds. Pro uses it for
 * "Paying members only": a member without an active paid plan is sent to the
 * plans page, while their account, billing and sign-out stay reachable.
 *
 * Enforcement points:
 *   - web: maybe_redirect() on template_redirect:8, after the verification (6)
 *     and 2FA (7) holds, which take precedence;
 *   - REST: RestHoldGate::hold_for() and the partner gate;
 *   - onboarding stands down while a hold applies, so the wizard and the hold
 *     never bounce a member between them.
 *
 * Administrators (manage_options) are never held.
 *
 * @since 1.2.4
 */
final class MemberHold implements ListenerInterface {

	/**
	 * Hook the web gate.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 8 );
	}

	/**
	 * The hold on a member, if any.
	 *
	 * @param int $user_id Member.
	 * @return array{code:string,message:string,url:string,hubs:string[],routes:string[]}|null
	 */
	public static function get( int $user_id ): ?array {
		if ( $user_id <= 0 || user_can( $user_id, 'manage_options' ) ) {
			return null;
		}

		/**
		 * Filter the hold that keeps a member off the community.
		 *
		 * Return null for no hold, or an array:
		 *   - code    (string)   REST error code, e.g. 'buddynextpro_membership_required'.
		 *   - message (string)   Why the member is held, shown to API clients.
		 *   - url     (string)   Where a web request is sent.
		 *   - hubs    (string[]) Community hubs that stay open (e.g. 'settings').
		 *                        The auth hub is always open.
		 *   - routes  (string[]) REST route patterns that stay open, as regex
		 *                        fragments matched from the start of the lower-cased
		 *                        route, e.g. '/buddynext/v1/me/account'.
		 *
		 * @since 1.2.4
		 *
		 * @param array<string,mixed>|null $hold    Hold, or null.
		 * @param int                      $user_id Member.
		 */
		$hold = apply_filters( 'buddynext_member_hold', null, $user_id );
		if ( ! is_array( $hold ) || '' === (string) ( $hold['url'] ?? '' ) ) {
			return null;
		}

		return array(
			'code'    => sanitize_key( (string) ( $hold['code'] ?? 'buddynext_member_hold' ) ),
			'message' => (string) ( $hold['message'] ?? __( 'Your account cannot use the community right now.', 'buddynext' ) ),
			'url'     => (string) $hold['url'],
			'hubs'    => array_values( array_map( 'strval', (array) ( $hold['hubs'] ?? array() ) ) ),
			'routes'  => array_values( array_map( 'strval', (array) ( $hold['routes'] ?? array() ) ) ),
		);
	}

	/**
	 * Whether a held member may still use a REST route.
	 *
	 * @param array<string,mixed> $hold  Hold from get().
	 * @param string              $route Normalised (lower-cased) route.
	 * @return bool
	 */
	public static function allows_route( array $hold, string $route ): bool {
		foreach ( (array) $hold['routes'] as $pattern ) {
			if ( preg_match( '#^' . str_replace( '#', '\#', (string) $pattern ) . '(?:/|$)#', $route ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Send a held member from a community page to the hold's page.
	 *
	 * Only community hubs are gated: the owner's own pages (home, blog, landing
	 * pages) stay as they are.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || is_feed() ) {
			return;
		}

		$hub = (string) get_query_var( 'bn_hub', '' );
		if ( '' === $hub || 'auth' === $hub ) {
			return;
		}

		$hold = self::get( get_current_user_id() );
		if ( null === $hold || in_array( $hub, $hold['hubs'], true ) ) {
			return;
		}

		wp_safe_redirect( $hold['url'] );
		exit;
	}
}
