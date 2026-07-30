<?php
/**
 * REST mirror of the account holds that keep a member off the community.
 *
 * @package BuddyNext
 */

declare(strict_types=1);

namespace BuddyNext\Auth;

use BuddyNext\Contracts\ListenerInterface;
use WP_Error;
use WP_REST_Request;

/**
 * Holds a member out of buddynext(-pro)/v1 while an account hold applies.
 *
 * Two holds exist, and both were WEB-ONLY:
 *
 *   Email verification, enforcement "full" — VerificationListener::maybe_gate_unverified()
 *   Forced 2FA enrolment                   — TwoFactorService::enforce_enrolment()
 *
 * Both hang off `template_redirect`, which does not fire on a REST request. So a
 * hold the owner switched on was not merely weaker over REST; it did not exist.
 * A site that requires 2FA of its administrators was bypassed by any REST client,
 * and "full" verification — whose own setting text promises the member "cannot use
 * the community until they verify" — let an unverified member react and follow.
 * Verified before this class existed:
 *
 *     enforcement = full ; unverified member:
 *       create post     GATED (email_unverified)
 *       create comment  GATED (email_unverified)
 *       react           ALLOWED
 *       follow          ALLOWED
 *
 * Posts and comments were caught only because PostService and CommentService each
 * happen to call is_verified() themselves. That is the "restricted" tier doing its
 * job, and it works over REST precisely because it lives in the service rather
 * than in a redirect. Everything without such a call — reactions, follows,
 * connections, space joins, bookmarks, shares, media, profile edits — was open.
 *
 * ── Why here, and not on each service ────────────────────────────────────────
 *
 * A hold is not a per-feature rule; it is "this member may not use the community
 * yet". Pushing it into every service means every future service has to remember
 * it, which is the failure this class exists to end. rest_pre_dispatch is the one
 * place that sees every route before any callback runs, and PrivateCommunity
 * already proves the seam works under Application Passwords.
 *
 * Priority 11: after PrivateCommunity's anonymous gate at 10. A logged-out visitor
 * to a private community should be told to log in, not told to verify.
 *
 * ── Scope ────────────────────────────────────────────────────────────────────
 *
 * This mirrors the web holds exactly, including their escape hatches: admins are
 * never trapped, the /auth surface stays reachable so the member can verify or
 * resend, the 2FA account routes stay reachable so they can enrol, and both
 * existing filters (buddynext_gate_unverified, buddynext_enforce_2fa_enrolment)
 * are honoured so a site that opted out on web is not suddenly gated on REST.
 *
 * It deliberately does NOT gate the "restricted" verification tier. That tier
 * means "cannot post or comment", it is already enforced in the services, and it
 * already behaves identically on web and REST. Gating it here would take away
 * reactions and follows that a restricted member is entitled to.
 *
 * @since 1.0.9
 */
final class RestHoldGate implements ListenerInterface {

	/**
	 * Bind the gate ahead of every BuddyNext REST callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'gate' ), 11, 3 );

		/*
		 * Extend the same holds to a partner's REST surface through the contract
		 * that partner publishes, rather than by pattern-matching their routes.
		 *
		 * gate() above only ever inspects buddynext(-pro)/v1, so every hold was
		 * simply absent on any namespace we do not own. The card reported it for
		 * DMs; the hole is the whole mvs/v1 surface, so an unverified member under
		 * "full" enforcement could also upload media, write tags and edit their
		 * MediaVerse profile.
		 *
		 * PrivateCommunity already solved this exact problem twenty lines away and
		 * its comment invites reuse: drive the partner's OWN gate through the two
		 * filters it exposes. Widening our regex to '#^/mvs/v1/#' would mean
		 * guessing at another plugin's routing and going stale the moment they add
		 * a namespace; the filter contract is their supported seam and tracks their
		 * own coverage (they extend it to mvs-pro/v1 themselves).
		 *
		 * require_auth engages their gate at all; can_access is the verdict. Both
		 * are needed — the gate short-circuits when require_auth is false, so
		 * answering only can_access would never be consulted.
		 */
		add_filter( 'mvs_rest_require_auth', array( $this, 'partner_gate_engaged' ) );
		add_filter( 'mvs_rest_can_access', array( $this, 'partner_gate_allows' ) );
	}

	/**
	 * Whether a partner's REST gate should engage for the current member.
	 *
	 * True as soon as any hold applies, so the partner consults can_access below.
	 * Left untouched otherwise, which keeps the partner's standalone behaviour and
	 * any other integration's answer intact.
	 *
	 * @param bool $require Current value from the partner (or a prior filter).
	 * @return bool
	 */
	public function partner_gate_engaged( $require ): bool {
		return $this->current_member_is_held() ? true : (bool) $require;
	}

	/**
	 * Whether the current member may use a partner's REST surface.
	 *
	 * Only ever removes access — a held member is refused; everyone else keeps
	 * whatever the partner or a prior filter decided. Never widens access, so this
	 * cannot turn a private community public.
	 *
	 * @param bool $can Current value from the partner (or a prior filter).
	 * @return bool
	 */
	public function partner_gate_allows( $can ): bool {
		return $this->current_member_is_held() ? false : (bool) $can;
	}

	/**
	 * Whether a hold applies to the member making this request.
	 *
	 * Route-independent, unlike hold_for(): a partner's gate asks "may this member
	 * use our surface", not "may they use this route". The auth and 2FA-enrolment
	 * carve-outs are BuddyNext routes, so they cannot apply to a partner namespace
	 * and are correctly absent here.
	 *
	 * @return bool
	 */
	private function current_member_is_held(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user_id = get_current_user_id();

		// Same administrator carve-out gate() makes — never trap an owner out of
		// their own site.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		return $this->is_unverified_under_full_enforcement( $user_id ) || $this->needs_2fa_enrolment( $user_id );
	}

	/**
	 * Refuse a BuddyNext REST route while an account hold applies.
	 *
	 * @param mixed           $result  Pre-dispatch short-circuit, or null.
	 * @param mixed           $server  REST server (unused; part of the signature).
	 * @param WP_REST_Request $request Current request.
	 * @return mixed Unchanged result, or a 403 WP_Error.
	 */
	public function gate( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $server is part of the rest_pre_dispatch signature.
		if ( null !== $result || ! is_user_logged_in() || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( ! preg_match( '#^/buddynext(?:-pro)?/v1/#', $route ) ) {
			return $result;
		}

		$user_id = get_current_user_id();

		// Never trap an administrator out of their own site — the same carve-out
		// both web gates make.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return $result;
		}

		// The auth surface is how a held member gets un-held: verify, resend,
		// check status, sign out. Refusing it would be a locked room with the key
		// inside.
		if ( preg_match( '#^/buddynext/v1/auth(?:/|$)#', $route ) ) {
			return $result;
		}

		$hold = $this->hold_for( $user_id, $route );

		return null === $hold ? $result : $hold;
	}

	/**
	 * The hold that applies to this member on this route, if any.
	 *
	 * @param int    $user_id Member.
	 * @param string $route   REST route.
	 * @return WP_Error|null
	 */
	private function hold_for( int $user_id, string $route ): ?WP_Error {
		if ( $this->is_unverified_under_full_enforcement( $user_id ) ) {
			return new WP_Error(
				'buddynext_email_unverified',
				__( 'Please confirm your email address to use the community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->needs_2fa_enrolment( $user_id ) ) {
			// The account 2FA routes are the enrolment flow itself — holding a
			// member out of them is the locked-room problem again.
			if ( preg_match( '#^/buddynext/v1/account/2fa(?:/|$)#', $route ) ) {
				return null;
			}

			return new WP_Error(
				'buddynext_2fa_required',
				__( 'Your role requires two-factor authentication. Set it up to continue.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Whether the member is held by "full" email-verification enforcement.
	 *
	 * @param int $user_id Member.
	 * @return bool
	 */
	private function is_unverified_under_full_enforcement( int $user_id ): bool {
		if ( 'full' !== VerificationListener::enforcement() ) {
			return false;
		}

		$verification = buddynext_service( 'verification' );
		if ( ! $verification instanceof VerificationService || $verification->is_verified( $user_id ) ) {
			return false;
		}

		/** This filter is documented in includes/Auth/VerificationListener.php */
		return (bool) apply_filters( 'buddynext_gate_unverified', true, $user_id );
	}

	/**
	 * Whether the member's role requires 2FA they have not enrolled in.
	 *
	 * @param int $user_id Member.
	 * @return bool
	 */
	private function needs_2fa_enrolment( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User
			|| ! TwoFactorService::is_required_for( $user )
			|| TwoFactorService::is_enabled( $user_id ) ) {
			return false;
		}

		/** This filter is documented in includes/Auth/TwoFactorService.php */
		return (bool) apply_filters( 'buddynext_enforce_2fa_enrolment', true, $user_id );
	}
}
