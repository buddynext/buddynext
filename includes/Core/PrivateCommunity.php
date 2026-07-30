<?php
/**
 * Native private-community lockdown.
 *
 * When enabled (Settings → Privacy & Data → Private Community), every
 * BuddyNext-routed front-end page AND its REST data require login — only the auth
 * surface (login / register / password-reset / verify) stays public. This closes
 * the gap a paying customer hit (Zoho #40662): BuddyNext serves its pages through
 * its own routing, so a membership plugin (Paid Memberships Pro / WP Fusion) that
 * only gates WordPress pages never saw those routes and the community leaked to
 * logged-out visitors. Rather than depend on a third-party gate reaching our
 * routes, we make the pages + data unreachable when logged out, natively.
 *
 * Two seams:
 *   - Pages: PageRouter::dispatch_hub_template() redirects logged-out hub visitors
 *            to the auth page (this class only supplies is_enabled()).
 *   - REST:  gate_rest() below returns 401 for any buddynext(-pro)/v1 route except
 *            the /auth/ surface, so the data cannot be read out from under the pages.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Private-community access control.
 */
final class PrivateCommunity {

	/**
	 * Option storing the on/off toggle (registered via the Privacy settings
	 * descriptor; default off — communities are public unless the owner opts in).
	 *
	 * @var string
	 */
	public const OPTION = 'buddynext_private_community';

	/**
	 * Whether private-community mode is on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Whether the current visitor may access the private community.
	 *
	 * The single access seam for membership plugins. Defaults to
	 * is_user_logged_in(); Paid Memberships Pro, WP Fusion, MemberPress, and the
	 * like filter `buddynext_private_community_can_access` to decide on their own
	 * terms — e.g. only a logged-in member with an active plan / required tag
	 * passes. Return false for a logged-in non-member to send them to the login
	 * (or upgrade) page like a guest; return true to grant access. Applied to BOTH
	 * the page redirect and the REST 401 gate, so one hook controls the whole
	 * surface. A deeper first-party PMP / WP Fusion integration is planned; this
	 * filter is the stable seam it (and any custom membership logic) hangs off.
	 *
	 * @param bool|null $prior Value decided by a previous callback when used as a
	 *                         filter, or null when called directly. A denial is
	 *                         never widened — see the note in the body.
	 * @return bool
	 */
	public static function can_access( $prior = null ): bool {
		/**
		 * Filter whether the current visitor may access a private community.
		 *
		 * @since 1.0.7
		 *
		 * @param bool $can_access Default: whether the visitor is logged in.
		 */
		$allowed = (bool) apply_filters( 'buddynext_private_community_can_access', is_user_logged_in() );

		/*
		 * This method is BOTH a direct call (gate_rest, with no argument) and a
		 * filter callback on the partner contract (mvs_rest_can_access, which
		 * passes the value decided so far). It used to take no parameter at all, so
		 * as a filter it silently DISCARDED whatever a previous callback decided and
		 * answered is_user_logged_in() — turning another gate's denial back into an
		 * allow.
		 *
		 * That made RestHoldGate's hold enforcement depend on filter REGISTRATION
		 * ORDER: the hold survived only because it happens to be added after this
		 * one. Re-order boot and an unverified member silently regains the partner
		 * surface. A gate must not be load-order-sensitive.
		 *
		 * So: never widen. A denial already made stands, whoever made it; this
		 * method can only ever add its own.
		 */
		if ( null !== $prior && ! $prior ) {
			return false;
		}

		return $allowed;
	}

	/**
	 * Wire the REST gate. The page gate lives in
	 * PageRouter::dispatch_hub_template() (it needs the resolved hub).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'gate_rest' ), 10, 3 );

		// Extend the same lockdown to MediaVerse's REST surface. The BN gate above
		// only covers the buddynext(-pro)/v1 namespace, so mvs/v1 (media, DMs,
		// tags, profiles) stayed readable logged-out on a private community. Rather
		// than reach into another plugin's namespace, we drive MediaVerse's OWN
		// gate through the filters it exposes — so this one setting seals both, and
		// MediaVerse standalone (nobody setting these) is unaffected. Any other
		// integration can adopt the same two-filter contract.
		add_filter( 'mvs_rest_require_auth', array( self::class, 'is_enabled' ) );
		add_filter( 'mvs_rest_can_access', array( self::class, 'can_access' ) );
	}

	/**
	 * Block BuddyNext REST data for logged-out visitors while private mode is on.
	 *
	 * The /auth/ surface stays open so a guest can still log in, register, reset a
	 * password, verify email, or refresh a nonce; every other buddynext(-pro)/v1
	 * route returns 401. Non-BuddyNext namespaces (wp/v2, other plugins) are never
	 * touched. Runs before dispatch so no controller callback executes.
	 *
	 * @param mixed            $result  Pre-dispatch short-circuit (WP_Error/response) or null.
	 * @param \WP_REST_Server  $server  REST server (unused).
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed Unchanged result, or a 401 WP_Error for a gated route.
	 */
	public static function gate_rest( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $server is part of the rest_pre_dispatch signature.
		if ( null !== $result || ! self::is_enabled() || self::can_access() ) {
			return $result;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();
		// Only our namespaces.
		if ( ! preg_match( '#^/buddynext(?:-pro)?/v1/#', $route ) ) {
			return $result;
		}
		// Login / register / reset / verify / 2fa must stay reachable so a guest can
		// authenticate — that is the whole point of a login page.
		if ( preg_match( '#^/buddynext/v1/auth(?:/|$)#', $route ) ) {
			return $result;
		}

		return new \WP_Error(
			'buddynext_private_community',
			__( 'This community is private. Please log in to view it.', 'buddynext' ),
			array( 'status' => 401 )
		);
	}
}
