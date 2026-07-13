<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for authentication-related endpoints.
 *
 * Routes (all under buddynext/v1):
 *   POST /auth/login          — log a user in by email/username + password
 *   POST /auth/register       — create a new user account
 *   POST /auth/verify/resend  — resend email verification for current user
 *   GET  /auth/verify/status  — check email verification status for current user
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Auth\VerificationService;
use BuddyNext\Core\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles email verification REST endpoints.
 */
class AuthController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/auth/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
					'remember' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/2fa',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_two_factor' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'twofa_token' => array(
						'required' => true,
						'type'     => 'string',
					),
					'code'        => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/2fa/email-code',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_two_factor_email' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'twofa_token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'            => array(
						'required' => true,
						'type'     => 'string',
					),
					// What the community DISPLAYS. Optional on the wire so an app or an
					// owner who does not want to ask for it still registers cleanly.
					'name'             => array(
						'required' => false,
						'type'     => 'string',
					),
					// No longer required. When it is absent we derive one from the email
					// (RegistrationService::unique_login), exactly as social signup has
					// always done — a member should not have to invent a handle to join a
					// community. An owner whose community wants handles chosen at the door
					// turns the field back on and the app can still send it.
					'user_login'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'password'         => array(
						'required' => true,
						'type'     => 'string',
					),
					'terms_agreed'     => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'invite'           => array(
						'required' => false,
						'type'     => 'string',
					),
					// Guard bundle, obtained from GET /auth/register/config. Declared
					// so OPTIONS discovery is honest: a native app can see what the
					// spam gate expects instead of being rejected as a bot with no
					// way to find out why.
					'reg_token'        => array(
						'required' => false,
						'type'     => 'string',
					),
					'challenge_token'  => array(
						'required' => false,
						'type'     => 'string',
					),
					'challenge_answer' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		// The signup contract + a fresh guard bundle. Without this a native app
		// cannot register at all: the time-trap token, the human-check question and
		// the honeypot field name are otherwise only minted inside the signup
		// template, so the API scores an app's submission as a bot and rejects it.
		register_rest_route(
			'buddynext/v1',
			'/auth/register/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'register_config' ),
				'permission_callback' => '__return_true',
			)
		);

		// Finish a parked social sign-up: collect the things OAuth cannot supply
		// (terms consent, required profile fields), then create the account.
		register_rest_route(
			'buddynext/v1',
			'/auth/register/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register_complete' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'pending_token' => array(
						'required' => true,
						'type'     => 'string',
					),
					'terms_agreed'  => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/lost-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lost_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user_login' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/reset-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'login'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/approve/(?P<id>[\d]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_member' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/verify/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend_verification' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/verify/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'verification_status' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/change-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change_password' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					// Not required at the route: a social-only member has never been
					// given a password, so demanding the current one would make setting
					// a first password impossible — the trap that let them delete their
					// only way in. change_password() still requires it for everyone who
					// actually has a password.
					'current_password' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
					'new_password'     => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/change-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change_email' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'email' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/sign-out-everywhere',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sign_out_everywhere' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_nonce' ),
				'permission_callback' => '__return_true',
			)
		);

		// Native-app token flow — mint / list / revoke Application Passwords.
		register_rest_route(
			'buddynext/v1',
			'/auth/app-password',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'issue_app_password' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_app_passwords' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);
		register_rest_route(
			'buddynext/v1',
			'/auth/app-password/(?P<uuid>[a-fA-F0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke_app_password' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * GET /auth/nonce — mint a fresh wp_rest nonce for this session.
	 *
	 * Used by the shared front-end REST client to recover from a stale-nonce
	 * 403 without forcing a full page reload. Chicken-and-egg subtlety: the
	 * refresh request itself carries the stale nonce, so core's
	 * rest_cookie_check_errors() has already downgraded it to user 0 — minting
	 * here would produce an anonymous nonce that can never verify against the
	 * caller's logged-in cookie. Re-validate the auth cookie directly (the same
	 * trust basis core's own admin-ajax `rest-nonce` refresh uses: cookie alone,
	 * no nonce) and mint for that user. Safe: the response is a nonce usable only
	 * by the same session, and cross-origin callers cannot read it.
	 *
	 * @return WP_REST_Response
	 */
	public function get_nonce(): WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			$cookie_user = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $cookie_user ) {
				wp_set_current_user( $cookie_user );
			}
		}

		$response = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		// A nonce response must never come from a page/CDN cache.
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );

		return $response;
	}

	/**
	 * POST /auth/change-password — set a new password after verifying the current one.
	 *
	 * Returns 422 with field-keyed errors on failure (mirrors update_profile).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id   = get_current_user_id();
		$user      = get_userdata( $user_id );
		$current   = (string) $request->get_param( 'current_password' );
		$candidate = (string) $request->get_param( 'new_password' );

		$errors = array();

		if ( ! $user ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'Account not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// A member who signed up through a social provider has never chosen a password
		// — create_member() gives the account a random one and never shows it to them.
		// Demanding the "current" password would make setting one impossible, which is
		// exactly what left social-only members unable to ever leave their provider.
		// With no password they know, this is a SET, not a CHANGE — and they are
		// already authenticated, which is the same standing every other password change
		// relies on.
		$is_first_password = ! self::has_known_password( $user_id );

		if ( ! $is_first_password ) {
			if ( '' === trim( $current ) ) {
				$errors['current_password'] = __( 'Enter your current password.', 'buddynext' );
			} elseif ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
				$errors['current_password'] = __( 'Current password does not match.', 'buddynext' );
			}
		}

		if ( '' === trim( $candidate ) ) {
			$errors['new_password'] = __( 'Enter a new password.', 'buddynext' );
		} elseif ( strlen( $candidate ) < 8 ) {
			$errors['new_password'] = __( 'Use at least 8 characters.', 'buddynext' );
		} elseif ( $current === $candidate ) {
			$errors['new_password'] = __( 'New password must be different from current password.', 'buddynext' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'saved'  => false,
					'errors' => $errors,
				),
				422
			);
		}

		wp_set_password( $candidate, $user_id );
		self::mark_password_known( $user_id );

		// wp_set_password() destroys all session tokens — re-authenticate so the
		// current request's cookie stays valid for the response/redirect chain.
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		return new WP_REST_Response(
			array( 'saved' => true ),
			200
		);
	}

	/**
	 * POST /auth/change-email — request an email change, sending verification.
	 *
	 * Stores the candidate in usermeta and triggers a verification token via
	 * the existing VerificationService. The swap only happens after the user
	 * clicks the link in the email (confirm-then-swap pattern).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id   = get_current_user_id();
		$user      = get_userdata( $user_id );
		$candidate = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! $user ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'Account not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$errors = array();

		if ( '' === $candidate || ! is_email( $candidate ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'buddynext' );
		} elseif ( strtolower( $candidate ) === strtolower( (string) $user->user_email ) ) {
			$errors['email'] = __( 'That is already your email.', 'buddynext' );
		} elseif ( email_exists( $candidate ) ) {
			$errors['email'] = __( 'An account with that email already exists.', 'buddynext' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'saved'  => false,
					'errors' => $errors,
				),
				422
			);
		}

		update_user_meta( $user_id, 'bn_pending_email', $candidate );

		/**
		 * Fires after a user has requested an email change. The default
		 * VerificationListener hooks here to send a confirmation email
		 * with a token-bearing link. Plugins can override to send via a
		 * branded template or alternative transport.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $user_id   Account requesting the change.
		 * @param string $candidate Pending email address.
		 */
		do_action( 'buddynext_email_change_requested', $user_id, $candidate );

		return new WP_REST_Response(
			array(
				'saved'   => true,
				'pending' => $candidate,
				'message' => __( 'Check your inbox to confirm.', 'buddynext' ),
			),
			200
		);
	}

	/**
	 * POST /auth/sign-out-everywhere — destroy all session tokens, then re-auth
	 * the current request so the response stays valid.
	 *
	 * @return WP_REST_Response
	 */
	public function sign_out_everywhere(): WP_REST_Response {
		$user_id = get_current_user_id();

		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		// Re-issue a cookie for the current device so the user can see the
		// confirmation toast without being booted on the response.
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		return new WP_REST_Response(
			array( 'signed_out' => true ),
			200
		);
	}

	/**
	 * Rate-limit a raw credential REST endpoint by IP + submitted identifier.
	 *
	 * The login / lost-password / reset-password routes are an unmetered brute-force
	 * surface that also bypasses wp-login.php security plugins, so they get the same
	 * RateLimiter the registration / social / 2FA paths already use. Keying on
	 * IP + login means one targeted account throttles without locking a whole NAT
	 * out of unrelated logins.
	 *
	 * @param string $action      Short slug (login|lost|reset) for the key + filter.
	 * @param string $identifier  Submitted login / email (folded into the key).
	 * @param bool   $per_ip_only When true the key ignores $identifier, so all
	 *                            attempts from the IP share one budget — used for
	 *                            lost/reset-password so an attacker cannot dodge the
	 *                            limit by rotating the email (enumeration / reset spam).
	 * @return WP_Error|null 429 error when over the cap, else null.
	 */
	private function rate_limit_guard( string $action, string $identifier, bool $per_ip_only = false ): ?WP_Error {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return null;
		}

		/**
		 * Filter the per-15-minutes attempt cap for a credential endpoint.
		 *
		 * @param int    $max    Default cap (10 attempts / 15 min). 0 disables it.
		 * @param string $action login|lost|reset.
		 */
		$max = (int) apply_filters( 'buddynext_auth_rate_limit', 10, $action );
		if ( $max <= 0 ) {
			return null;
		}

		$subject = $per_ip_only ? $action : ( $action . '|' . strtolower( $identifier ) );
		$key     = 'bn_auth_' . md5( $ip . '|' . $subject );
		if ( RateLimiter::count( $key ) >= $max ) {
			return new WP_Error(
				'bn_auth_rate_limited',
				__( 'Too many attempts. Please wait a few minutes and try again.', 'buddynext' ),
				array( 'status' => 429 )
			);
		}
		RateLimiter::hit( $key, 15 * MINUTE_IN_SECONDS );

		return null;
	}

	/**
	 * Translate an authenticate-chain WP_Error into a REST gate response, when
	 * (and only when) it is a NON-CREDENTIAL gate rather than a bad password.
	 *
	 * Because wp_authenticate() runs the whole filter chain, its WP_Error can mean
	 * two very different things:
	 *
	 *   1. The credentials were wrong (invalid_username, incorrect_password, …).
	 *      Those must stay collapsed into one generic 401 by the caller — telling
	 *      the client which half was wrong turns the endpoint into an account
	 *      enumeration oracle.
	 *   2. The credentials were RIGHT but a policy gate refused the sign-in — today
	 *      that is `bn_pending_approval` (Plugin::register_hooks() adds the
	 *      wp_authenticate_user filter for the `approval` registration mode).
	 *      Flattening that into "Invalid email or password." sends a member who
	 *      typed the correct password off to reset it, chasing a password problem
	 *      they do not have. wp-login.php shows the real message; the REST login
	 *      must too.
	 *
	 * Only codes on the allowlist below are passed through, so a future gate filter
	 * cannot accidentally start leaking a credential hint. Email verification is
	 * intentionally absent: it does not block sign-in (VerificationListener lets the
	 * user in and the router lands them on /auth/verify/), so it never reaches here.
	 *
	 * @param WP_Error $error      Error returned by wp_authenticate().
	 * @param string   $user_input Submitted username or email, for the re-check below.
	 * @param string   $password   Submitted password, for the re-check below.
	 * @return WP_Error|null A 403 gate error to return to the client, or null when
	 *                       the failure is a credential failure the caller should
	 *                       answer with its generic 401.
	 */
	/**
	 * User meta recording that the member chose their own password.
	 *
	 * WordPress always stores a password hash, so the hash cannot tell us whether
	 * the member has ever SEEN a password: a social sign-up is created with a
	 * random one that is never shown to them. This marker is the only honest way to
	 * answer "can this person actually sign in with a password?", which is what
	 * stands between a social-only member and locking themselves out for good.
	 */
	private const META_PASSWORD_SET = 'bn_password_set';

	/**
	 * Marks an account whose password BuddyNext generated and never showed the member
	 * (social signup). The ONLY positive evidence that a member has no password they
	 * could type — see has_known_password().
	 *
	 * @var string
	 */
	private const META_PASSWORD_GENERATED = 'bn_password_generated';

	/**
	 * Whether the member has a password they actually know.
	 *
	 * False for an account created through a social provider that has never set
	 * one. Also false for accounts that predate this marker — deliberately, and on
	 * the safe side: the cost of a false negative is one extra "set a password"
	 * step, while a false positive would let someone delete their last way in.
	 *
	 * @param int $user_id Member.
	 * @return bool True when a password the member chose is on file.
	 */
	public static function has_known_password( int $user_id ): bool {
		// They chose one through a BuddyNext path (signup, change, reset).
		if ( get_user_meta( $user_id, self::META_PASSWORD_SET, true ) ) {
			return true;
		}

		// Otherwise assume they DO have a password, and only believe otherwise for
		// an account we know we generated the password for (social signup, where
		// the member never saw it).
		//
		// The default matters enormously and it used to be backwards. This read a
		// single positive flag that only BuddyNext's own paths ever set, so every
		// account BuddyNext did not create — users who predate the plugin, the whole
		// BuddyPress migration, admin-created users, WooCommerce imports — reported
		// "no known password". change_password() takes that to mean "this is a SET,
		// not a CHANGE" and skips the current-password check. A stolen session could
		// then change the password without knowing the old one, which is exactly the
		// re-authentication step that stops session theft from becoming account
		// takeover.
		//
		// Absence of evidence was being read as evidence of absence. Now only a
		// positive "we generated this" marker suppresses the check.
		return ! get_user_meta( $user_id, self::META_PASSWORD_GENERATED, true );
	}

	/**
	 * Record that this account's password was generated by us and never shown to
	 * the member — so they have none they could type.
	 *
	 * Set only where BuddyNext mints the password itself (social signup). Never set
	 * it for an account whose password the member chose.
	 *
	 * @param int $user_id Member.
	 * @return void
	 */
	public static function mark_password_generated( int $user_id ): void {
		update_user_meta( $user_id, self::META_PASSWORD_GENERATED, 1 );
	}

	/**
	 * Record that the member now has a password they chose themselves.
	 *
	 * Called from every path where a member sets one: signup, change-password, and
	 * a reset. Once this is set, unlinking their last social provider is safe.
	 *
	 * @param int $user_id Member.
	 * @return void
	 */
	public static function mark_password_known( int $user_id ): void {
		update_user_meta( $user_id, self::META_PASSWORD_SET, 1 );
	}

	/**
	 * Re-issue a gate refusal that wp_authenticate() flattened into a generic error.
	 *
	 * Because wp_authenticate() runs the whole filter chain, a WP_Error coming back from
	 * it can mean "wrong password" OR "a gate refused you" — the approval hold, for
	 * instance, is a wp_authenticate_user filter. Those must not surface to the member
	 * as "incorrect password", which would be a lie they could never act on.
	 *
	 * @param WP_Error $error      Error returned by wp_authenticate().
	 * @param string   $user_input Login or email the member typed.
	 * @param string   $password   Password they supplied.
	 * @return WP_Error|null The gate's own error when this was a gate refusal, else null.
	 */
	private static function authenticate_gate_error( WP_Error $error, string $user_input, string $password ): ?WP_Error {
		$gate_codes = array(
			// The account exists and is held for administrator approval.
			'bn_pending_approval',
		);

		$code = (string) $error->get_error_code();
		if ( ! in_array( $code, $gate_codes, true ) ) {
			return null;
		}

		// WordPress applies the `wp_authenticate_user` filter — where the approval
		// gate lives — BEFORE it checks the password. So reaching this point proves
		// only that the account is pending, not that the caller knows its password.
		// Revealing the gate to someone who failed the password check would turn
		// this endpoint into an enumeration oracle ("that address is a real, pending
		// account"), which is exactly what the generic 401 below exists to prevent.
		// So verify the password ourselves, and only then explain the real reason.
		$user = is_email( $user_input )
			? get_user_by( 'email', $user_input )
			: get_user_by( 'login', $user_input );

		if ( ! $user instanceof \WP_User || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return null; // Fall through to the generic credential failure.
		}

		$message = wp_strip_all_tags( (string) $error->get_error_message() );
		if ( '' === $message ) {
			$message = __( 'Your account is awaiting administrator approval.', 'buddynext' );
		}

		// 403, not 401: the password was correct — access is what is refused.
		return new WP_Error( $code, $message, array( 'status' => 403 ) );
	}

	/**
	 * POST /auth/login — authenticate a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_input = trim( (string) $request->get_param( 'user' ) );
		$password   = (string) $request->get_param( 'password' );
		$remember   = (bool) $request->get_param( 'remember' );

		// The owner's blocked-IP list. It governed POSTING only, so a blocklisted
		// IP could still sign in — which is not what anyone means when they block
		// an IP address.
		$blocked = ( new RegistrationGuard() )->check_ip( self::client_ip() );
		if ( is_wp_error( $blocked ) ) {
			return new WP_Error(
				'bn_login_ip',
				__( 'Sign-in from your network is not allowed.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		if ( '' === $user_input || '' === $password ) {
			return new WP_Error(
				'rest_missing_credentials',
				__( 'Please enter your email or username and your password.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$limited = $this->rate_limit_guard( 'login', $user_input );
		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$login = is_email( $user_input ) ? $this->resolve_login_for_email( $user_input ) : $user_input;

		// Verify the password (runs the full authenticate chain, including the
		// pending-approval gate) WITHOUT setting an auth cookie yet — so a 2FA
		// challenge can be interposed before the session is actually created.
		$user = wp_authenticate( $login, $password );

		if ( is_wp_error( $user ) ) {
			$gate = self::authenticate_gate_error( $user, $user_input, $password );
			if ( $gate instanceof WP_Error ) {
				return $gate;
			}

			// Genuine credential failure. The message stays deliberately generic
			// (never "no such user" / "wrong password") so the endpoint cannot be
			// used to enumerate which emails hold accounts.
			return new WP_Error(
				'rest_login_failed',
				__( 'Invalid email or password.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$redirect_to = (string) $request->get_param( 'redirect_to' );
		if ( '' === $redirect_to ) {
			$redirect_to = \BuddyNext\Core\RedirectSettings::login( \BuddyNext\Core\PageRouter::activity_url() );
		}

		// Two-factor gate (optional, per-user). When on, hold the session and
		// hand back a one-time challenge ticket; the cookie is only set once a
		// code verifies at /auth/2fa.
		if ( TwoFactorService::is_enabled( (int) $user->ID ) ) {
			$token = TwoFactorService::issue_login_challenge( (int) $user->ID, $remember );
			return new WP_REST_Response(
				array(
					'success'        => true,
					'twofa_required' => true,
					'twofa_token'    => $token,
					'email_hint'     => $this->mask_email( (string) $user->user_email ),
					'redirect_to'    => esc_url_raw( $redirect_to ),
				),
				200
			);
		}

		$this->complete_login( $user, $remember );

		$payload = array(
			'success'     => true,
			'user_id'     => (int) $user->ID,
			'redirect_to' => esc_url_raw( $redirect_to ),
			// A REST nonce so a just-logged-in client can immediately call the
			// authenticated routes (e.g. mint an app password) over its cookie.
			'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
		);

		// One-shot native-app flow: mint an Application Password now so the app can
		// switch to stateless HTTP Basic auth and drop the cookie jar entirely.
		if ( (bool) $request->get_param( 'issue_app_password' ) ) {
			$minted = $this->mint_app_password( (int) $user->ID, (string) $request->get_param( 'device_name' ) );
			if ( ! is_wp_error( $minted ) ) {
				$payload['app_password'] = $minted;
			}
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Mint a WordPress Application Password for a user (the native-app token).
	 *
	 * The plaintext password is returned ONCE; the app stores it and authenticates
	 * every subsequent REST call with HTTP Basic (username:app-password) — no cookie,
	 * no nonce. Returns a shape the app can persist, or WP_Error when app passwords
	 * are unavailable for the user (e.g. disabled or non-SSL policy).
	 *
	 * @param int    $user_id User to mint for.
	 * @param string $name    Device / app label (defaults to a generic BuddyNext label).
	 * @return array{password:string,uuid:string,name:string,username:string}|WP_Error
	 */
	private function mint_app_password( int $user_id, string $name = '' ) {
		if ( ! class_exists( '\WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user_id ) ) {
			return new WP_Error(
				'app_passwords_unavailable',
				__( 'Application passwords are not available on this site.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$name    = sanitize_text_field( $name );
		$label   = '' !== $name ? $name : __( 'BuddyNext app', 'buddynext' );
		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $label ) );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$user = get_userdata( $user_id );

		return array(
			'password' => (string) $created[0],
			'uuid'     => (string) ( $created[1]['uuid'] ?? '' ),
			'name'     => (string) ( $created[1]['name'] ?? $label ),
			'username' => $user ? $user->user_login : '',
		);
	}

	/**
	 * POST /auth/app-password — mint an Application Password for the current user.
	 *
	 * The plaintext password is in the 201 response ONCE; the app stores it and
	 * authenticates future REST calls with HTTP Basic (username:app-password).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue_app_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$minted = $this->mint_app_password( get_current_user_id(), (string) $request->get_param( 'name' ) );
		if ( is_wp_error( $minted ) ) {
			return $minted;
		}

		return new WP_REST_Response( $minted, 201 );
	}

	/**
	 * GET /auth/app-password — list the current user's Application Passwords.
	 *
	 * Never exposes the secret (only the metadata needed to show + revoke them).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_app_passwords( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = get_current_user_id();
		$items   = class_exists( '\WP_Application_Passwords' )
			? \WP_Application_Passwords::get_user_application_passwords( $user_id )
			: array();

		$out = array();
		foreach ( (array) $items as $item ) {
			$out[] = array(
				'uuid'      => (string) ( $item['uuid'] ?? '' ),
				'name'      => (string) ( $item['name'] ?? '' ),
				'created'   => (int) ( $item['created'] ?? 0 ),
				'last_used' => isset( $item['last_used'] ) ? (int) $item['last_used'] : null,
			);
		}

		return new WP_REST_Response( array( 'app_passwords' => $out ), 200 );
	}

	/**
	 * DELETE /auth/app-password/{uuid} — revoke one of the current user's tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_app_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return new WP_Error(
				'app_passwords_unavailable',
				__( 'Application passwords are not available on this site.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$uuid    = sanitize_text_field( (string) $request['uuid'] );
		$deleted = \WP_Application_Passwords::delete_application_password( get_current_user_id(), $uuid );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'uuid'    => $uuid,
			),
			200
		);
	}

	/**
	 * POST /auth/2fa — complete a two-factor sign-in by verifying a code.
	 *
	 * Accepts a TOTP code, an emailed one-time code, or a single-use backup code.
	 * The challenge ticket is consumed only on success, so a mistyped code can be
	 * retried until the ticket expires.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_two_factor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = (string) $request->get_param( 'twofa_token' );
		$code  = (string) $request->get_param( 'code' );

		$ticket = TwoFactorService::peek_login_challenge( $token );
		if ( null === $ticket ) {
			return new WP_Error(
				'rest_2fa_expired',
				__( 'Your sign-in session expired. Please enter your password again.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$verified = TwoFactorService::verify_login_challenge( $token, $ticket['user'], $code );
		if ( is_wp_error( $verified ) ) {
			// 'bn_2fa_locked' once the per-ticket attempt cap is hit (429), otherwise
			// a wrong-code rejection (422). The throttle is shared with the wp-login
			// bn_2fa path so brute-force enforcement is identical on both surfaces.
			$locked = 'bn_2fa_locked' === $verified->get_error_code();
			return new WP_Error(
				$locked ? 'rest_2fa_locked' : 'rest_2fa_failed',
				$verified->get_error_message(),
				array(
					'status' => $locked ? 429 : 422,
					'fields' => array( 'code' => __( 'Incorrect or expired code.', 'buddynext' ) ),
				)
			);
		}

		TwoFactorService::consume_login_challenge( $token );

		$user = get_userdata( $ticket['user'] );
		if ( ! $user ) {
			return new WP_Error( 'rest_2fa_failed', __( 'Account not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$this->complete_login( $user, $ticket['remember'] );

		$redirect_to = (string) $request->get_param( 'redirect_to' );
		if ( '' === $redirect_to ) {
			$redirect_to = \BuddyNext\Core\RedirectSettings::login( \BuddyNext\Core\PageRouter::activity_url() );
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'user_id'     => (int) $user->ID,
				'redirect_to' => esc_url_raw( $redirect_to ),
			),
			200
		);
	}

	/**
	 * POST /auth/2fa/email-code — email a one-time fallback code to the user
	 * holding a valid challenge ticket. The response is deliberately generic so
	 * it never reveals whether the ticket or address is valid.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function send_two_factor_email( WP_REST_Request $request ): WP_REST_Response {
		$token  = (string) $request->get_param( 'twofa_token' );
		$ticket = TwoFactorService::peek_login_challenge( $token );
		// Only send when the ticket is valid AND past the per-ticket resend
		// cooldown, so the endpoint can't be used to mail-bomb a member. The
		// response stays generic either way so it never reveals ticket validity.
		if ( null !== $ticket && TwoFactorService::can_resend_email_code( $token ) ) {
			TwoFactorService::send_email_code( $ticket['user'] );
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'If your session is still valid, a code is on its way to your email.', 'buddynext' ),
			),
			200
		);
	}

	/**
	 * Complete sign-in for a verified user: set the current user, issue the auth
	 * cookie, and fire wp_login (the tail of wp_signon, split out so a 2FA step
	 * can run between password verification and session creation).
	 *
	 * @param \WP_User $user     Verified user.
	 * @param bool     $remember Persistent cookie.
	 * @return void
	 */
	private function complete_login( \WP_User $user, bool $remember ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		/** This action is documented in wp-includes/user.php (wp_signon). */
		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * Mask an email for a privacy-safe hint (e.g. "a***e@example.com").
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '';
		}
		$name   = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		$first  = substr( $name, 0, 1 );
		$last   = strlen( $name ) > 1 ? substr( $name, -1 ) : '';
		return $first . '***' . $last . $domain;
	}

	/**
	 * POST /auth/register — create a new account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$policy = buddynext_service( 'registration_policy' );

		$token      = sanitize_text_field( (string) $request->get_param( 'invite' ) );
		$email      = sanitize_email( (string) $request->get_param( 'email' ) );
		$user_login = sanitize_user( (string) $request->get_param( 'user_login' ), true );
		$password   = (string) $request->get_param( 'password' );

		// 1) Access policy — registration mode (incl. closed), the invite
		// requirement, and the allowed-domain allowlist. Shared with every other
		// door, so what the owner configured here binds on all of them.
		$access = $policy->check_access( $email, '' !== $token ? $token : null, RegistrationPolicy::SOURCE_FORM );
		if ( is_wp_error( $access ) ) {
			// Preserve the codes and statuses this endpoint already returned: a
			// closed or invite-only community is a 403; a disallowed email domain
			// came out of the guard and was a 422.
			$map                        = array(
				'bn_reg_closed' => array( 'rest_registration_closed', 403 ),
				'bn_reg_invite' => array( 'rest_invite_required', 403 ),
				'bn_reg_domain' => array( 'rest_registration_failed', 422 ),
			);
			list( $rest_code, $status ) = $map[ $access->get_error_code() ] ?? array( 'rest_registration_failed', 403 );

			return new WP_Error( $rest_code, $access->get_error_message(), array( 'status' => $status ) );
		}

		// 2) Core account fields.
		$errors = array();

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'buddynext' );
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = __( 'An account already exists with this email address.', 'buddynext' );
		}

		// An ABSENT username is not an invalid one.
		//
		// The username field is OFF BY DEFAULT — a member should not have to invent a
		// handle to join — and RegistrationService::create() derives one from the email
		// via unique_login(), exactly as social signup does. This route's own arg spec
		// says so ('user_login' => required: false) and the docblock above promises it.
		//
		// But this check rejected the empty string outright, so the promise was never
		// kept: on a DEFAULT install the form sent no username, the request was refused
		// with "Username must be at least 3 characters" — naming a field the visitor
		// could not even see — and NOBODY COULD REGISTER through the web form at all.
		//
		// So: validate the handle only when one was actually supplied. When it is
		// absent, let the service derive it.
		if ( '' !== $user_login ) {
			if ( strlen( $user_login ) < 3 ) {
				$errors['user_login'] = __( 'Username must be at least 3 characters.', 'buddynext' );
			} elseif ( ! validate_username( $user_login ) ) {
				$errors['user_login'] = __( 'Username contains invalid characters.', 'buddynext' );
			} elseif ( username_exists( $user_login ) ) {
				$errors['user_login'] = __( 'This username is already taken.', 'buddynext' );
			}
		}

		if ( strlen( $password ) < 8 ) {
			$errors['password'] = __( 'Password must be at least 8 characters.', 'buddynext' );
		}

		// 3) Everything the owner requires — terms consent and any profile field
		// flagged "ask for this on the registration form". A door that renders a
		// form must have collected them; social login, which cannot, parks a
		// pending signup instead (see SocialLogin::resolve_user).
		$params = $request->get_params();
		foreach ( $policy->missing( $params ) as $requirement ) {
			if ( 'terms' === $requirement ) {
				$errors['terms_agreed'] = __( 'You must accept the Terms of Service to continue.', 'buddynext' );
				continue;
			}
			/* translators: %s: profile field label. */
			$errors[ $requirement ] = sprintf( __( '%s is required.', 'buddynext' ), $policy->label_for( $requirement ) );
		}

		// 4) Field-type validation (format, options, ranges).
		$values = $policy->validate_data( $params );
		if ( is_wp_error( $values ) ) {
			$errors = array_merge( $errors, (array) ( $values->get_error_data()['fields'] ?? array() ) );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'rest_registration_failed',
				__( 'Please correct the errors below.', 'buddynext' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}

		// 5) Spam / abuse gate (rate limit, honeypot, time-trap, human check).
		// Runs on well-formed input so humans see field validation first, then
		// the guard only gates real attempts before we create the account.
		$gate = ( new RegistrationGuard() )->check(
			array(
				'source'           => RegistrationPolicy::SOURCE_FORM,
				'email'            => $email,
				'user_login'       => $user_login,
				'ip'               => self::client_ip(),
				'honeypot'         => (string) $request->get_param( RegistrationGuard::honeypot_field() ),
				'token'            => (string) $request->get_param( 'reg_token' ),
				'challenge_token'  => (string) $request->get_param( 'challenge_token' ),
				'challenge_answer' => (string) $request->get_param( 'challenge_answer' ),
			)
		);
		if ( is_wp_error( $gate ) ) {
			$code   = $gate->get_error_code();
			$status = ( 'bn_reg_rate' === $code ) ? 429 : 422;
			// Surface the human-check failure inline on its field; other guard
			// rejections (rate limit, spam score) are deliberately top-level only.
			$fields = ( 'bn_reg_challenge' === $code )
				? array( 'challenge' => $gate->get_error_message() )
				: array();
			return new WP_Error(
				'rest_registration_failed',
				$gate->get_error_message(),
				array(
					'status' => $status,
					'fields' => $fields,
				)
			);
		}

		// 6) Create. Every post-create step (invite redemption + space join,
		// DM-privacy seed, profile fields, approval hold) lives in the one
		// pipeline, so no door can forget one.
		$user_id = buddynext_service( 'registration' )->create(
			array_merge(
				$params,
				array(
					'email'      => $email,
					'user_login' => $user_login,
					'password'   => $password,
					'invite'     => $token,
				)
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'rest_registration_failed',
				$user_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// This member typed their own password, so they can always get back in with
		// it. Social sign-ups go through the same RegistrationService door but are
		// handed a random password they never see — which is why the marker is set
		// here, at the door where a password is actually chosen, and not inside the
		// shared pipeline.
		self::mark_password_known( (int) $user_id );

		// Email verification is handled by VerificationListener::on_user_register,
		// which wp_create_user() inside the pipeline already triggered via the
		// user_register hook. Do NOT create a token here as well.

		// 7) Session. The admin-approval hold and two-factor both live on the core
		// authenticate chain, and SessionIssuer is the only thing allowed to hand
		// out a cookie — so neither can be skipped by any door.
		$session = buddynext_service( 'session' )->start( (int) $user_id, false );

		if ( is_wp_error( $session ) && 'bn_pending_approval' === $session->get_error_code() ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'pending' => true,
					'user_id' => (int) $user_id,
					'message' => __( 'Your account was created and is awaiting administrator approval.', 'buddynext' ),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'user_id'     => (int) $user_id,
				'redirect_to' => esc_url_raw( self::post_register_redirect( (int) $user_id, $params ) ),
			),
			200
		);
	}

	/**
	 * GET /auth/register/config — the signup contract plus a fresh guard bundle.
	 *
	 * A native app previously could not register at all. The time-trap token, the
	 * human-check question and the honeypot field name are minted when the signup
	 * template renders, so an app that posted straight to /auth/register scored as
	 * a bot: the API answered "please solve the verification question" — a question
	 * there was no endpoint to fetch. The only workaround was for the owner to turn
	 * spam protection off entirely, which is not a workaround.
	 *
	 * This publishes exactly what the web form renders, so both surfaces read from
	 * one contract.
	 *
	 * @return WP_REST_Response
	 */
	public function register_config(): WP_REST_Response {
		$policy       = buddynext_service( 'registration_policy' );
		$requirements = $policy->requirements();

		$challenge = array();
		if ( RegistrationGuard::challenge_enabled() ) {
			$issued    = RegistrationGuard::issue_challenge();
			$challenge = array(
				'question' => $issued['question'],
				'token'    => $issued['token'],
			);
		}

		$fields = array();
		foreach ( $requirements['fields'] as $field ) {
			$fields[] = array(
				'key'         => (string) $field['field_key'],
				'label'       => (string) $field['label'],
				'type'        => (string) $field['type'],
				'required'    => ! empty( $field['is_required'] ),
				'options'     => $field['options'] ?? array(),
				'description' => (string) ( $field['description'] ?? '' ),
			);
		}

		return new WP_REST_Response(
			array(
				'mode'           => $requirements['mode'],
				'terms'          => $requirements['terms'],
				'terms_url'      => $requirements['terms_url'],
				'fields'         => $fields,
				'reg_token'      => RegistrationGuard::issue_token(),
				'honeypot_field' => RegistrationGuard::honeypot_field(),
				'challenge'      => $challenge,
			),
			200
		);
	}

	/**
	 * POST /auth/register/complete — finish a parked social sign-up.
	 *
	 * OAuth gives us an email and a name. It cannot give us terms consent, or the
	 * profile fields the owner marked required at registration. Rather than create
	 * the account anyway and patch the gaps afterwards — the ordering that produced
	 * the admin-approval bypass — the provider profile was parked and we collect
	 * the rest here. Only now does the member get created.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token   = (string) $request->get_param( 'pending_token' );
		$pending = PendingSignup::get( $token );

		if ( null === $pending ) {
			return new WP_Error(
				'bn_pending_expired',
				__( 'Your sign-up session expired. Please start again.', 'buddynext' ),
				array( 'status' => 410 )
			);
		}

		$policy = buddynext_service( 'registration_policy' );
		$params = array_merge( $request->get_params(), array( 'email' => (string) $pending['email'] ) );

		// Re-check the access policy: the community may have closed, or the owner
		// may have tightened the allowlist, since the sign-up was parked.
		//
		// Carry the parked INVITE into the re-check. Passing null here asked "may
		// this person join with no invitation?" — which on an invite-only site is
		// always no. And every invite-only social sign-up comes through this door,
		// because terms consent defaults on and OAuth cannot supply it. So the
		// invitation was accepted at the front door and then thrown away at the last
		// step, blocking exactly the people who had been invited.
		$invite = (string) ( $pending['invite'] ?? '' );
		$access = $policy->check_access(
			(string) $pending['email'],
			'' === $invite ? null : $invite,
			RegistrationPolicy::SOURCE_SOCIAL
		);
		if ( is_wp_error( $access ) ) {
			return new WP_Error(
				$access->get_error_code(),
				$access->get_error_message(),
				array( 'status' => 403 )
			);
		}

		$errors = array();
		foreach ( $policy->missing( $params ) as $requirement ) {
			if ( 'terms' === $requirement ) {
				$errors['terms_agreed'] = __( 'You must accept the Terms of Service to continue.', 'buddynext' );
				continue;
			}
			/* translators: %s: profile field label. */
			$errors[ $requirement ] = sprintf( __( '%s is required.', 'buddynext' ), $policy->label_for( $requirement ) );
		}

		$values = $policy->validate_data( $params );
		if ( is_wp_error( $values ) ) {
			$errors = array_merge( $errors, (array) ( $values->get_error_data()['fields'] ?? array() ) );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'rest_registration_failed',
				__( 'Please correct the errors below.', 'buddynext' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}

		// Spend the token before creating anything: a replayed submit must not be
		// able to mint a second member from the same parked sign-up.
		PendingSignup::consume( $token );

		$user_id = ( new SocialLogin() )->create_member( $pending, $params );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'rest_registration_failed',
				$user_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$session = buddynext_service( 'session' )->start( (int) $user_id, true );

		if ( is_wp_error( $session ) && 'bn_pending_approval' === $session->get_error_code() ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'pending' => true,
					'user_id' => (int) $user_id,
					'message' => __( 'Your account was created and is awaiting administrator approval.', 'buddynext' ),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'user_id'     => (int) $user_id,
				'redirect_to' => esc_url_raw( self::post_register_redirect( (int) $user_id, $params ) ),
			),
			200
		);
	}

	/**
	 * The caller's IP address, for the rate limiter.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * Where a freshly registered member lands.
	 *
	 * Email verification, when enabled, takes precedence so the account is
	 * confirmed before anything else. Otherwise the welcome wizard when onboarding
	 * is on, else the activity feed. Members who arrive by other paths (admin
	 * created, social login) are caught by the OnboardingListener redirect gate.
	 *
	 * @param int                  $user_id The account that was just created (0 when unknown).
	 * @param array<string, mixed> $params  The registration request parameters.
	 * @return string
	 */
	public static function post_register_redirect( int $user_id = 0, array $params = array() ): string {
		if ( get_option( 'buddynext_email_verify', false ) ) {
			$url = \BuddyNext\Core\PageRouter::hub_url(
				'buddynext_slug_auth',
				'buddynext_page_auth'
			) . 'verify/';
		} else {
			$onboarding_on = function_exists( 'buddynext_service' )
				&& buddynext_service( 'features' )->is_enabled( 'onboarding' );

			$url = $onboarding_on
				? \BuddyNext\Core\PageRouter::onboarding_url()
				: \BuddyNext\Core\PageRouter::activity_url();
		}

		/**
		 * Filter where a freshly registered member is sent.
		 *
		 * Signup is otherwise blind to everything that happened before it. A visitor
		 * who chose a paid plan and was bounced here to make an account must be able
		 * to be handed straight back into that purchase — Pro's SignupPlanFlow uses
		 * this seam to resume checkout instead of dropping them on the feed. The
		 * registration params are passed so a listener can read whatever the door
		 * collected (e.g. a signed plan intent) without a second source of truth.
		 *
		 * @since 1.0.8
		 *
		 * @param string               $url     Default destination.
		 * @param int                  $user_id The account that was just created.
		 * @param array<string, mixed> $params  The registration request parameters.
		 */
		return (string) apply_filters( 'buddynext_post_register_redirect', $url, $user_id, $params );
	}

	/**
	 * Resolve a username for a given email address.
	 *
	 * @param string $email Email.
	 * @return string Username or original input if no match.
	 */
	private function resolve_login_for_email( string $email ): string {
		$user = get_user_by( 'email', $email );
		return $user ? $user->user_login : $email;
	}

	/**
	 * POST /auth/lost-password — request a password-reset link.
	 *
	 * Drives WordPress core retrieve_password() so the secure reset key and
	 * delivery are owned by core; BuddyNext only provides the branded screen.
	 * The reset email's link is rewritten to the branded /{auth}/reset/ screen
	 * via the retrieve_password_message filter (registered in register()).
	 *
	 * Always returns the same generic success message whether or not the
	 * account exists — no account enumeration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function lost_password( WP_REST_Request $request ): WP_REST_Response {
		$login = sanitize_text_field( (string) $request->get_param( 'user_login' ) );

		$generic = new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'If an account matches that email or username, we have sent a link to reset its password.', 'buddynext' ),
			),
			200
		);

		if ( '' === $login ) {
			return $generic;
		}

		$limited = $this->rate_limit_guard( 'lost', $login, true );
		if ( $limited instanceof WP_Error ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $limited->get_error_message(),
				),
				429
			);
		}

		// retrieve_password() accepts a login or email; it sends the reset email
		// when the account exists and returns true, or a WP_Error otherwise. We
		// swallow the result so the response never reveals which.
		//
		// The email itself is branded globally by brand_reset_notification_email()
		// (hooked on retrieve_password_notification_email in
		// RegistrationEmailListener), so resets started here, from wp-login.php, or
		// programmatically all share the same branded shell, subject, From, and
		// /{auth}/reset/ link.
		retrieve_password( $login );

		return $generic;
	}

	/**
	 * Brand WordPress core's password-reset email for EVERY reset path.
	 *
	 * Hooked globally on retrieve_password_notification_email (registered in
	 * RegistrationEmailListener) so resets initiated from wp-login.php, the BN
	 * REST endpoint, or programmatically all receive the same branded HTML shell,
	 * subject, From identity, and /{auth}/reset/ deep link. Setting the HTML
	 * Content-Type in this email's own headers keeps the change scoped — it never
	 * leaks into unrelated wp_mail() calls.
	 *
	 * @param array<string, mixed> $defaults   Email parts: to, subject, message, headers.
	 * @param string               $key        Password-reset key.
	 * @param string               $user_login Username of the account being reset.
	 * @param \WP_User|mixed       $user_data  User object for the account (unused).
	 * @return array<string, mixed>
	 */
	public static function brand_reset_notification_email( array $defaults, string $key, string $user_login, $user_data = null ): array {
		unset( $user_data );

		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		$url = add_query_arg(
			array(
				'key'   => rawurlencode( $key ),
				'login' => rawurlencode( $user_login ),
			),
			\BuddyNext\Core\PageRouter::reset_url()
		);

		$body = sprintf(
			/* translators: 1: site name, 2: username, 3: reset URL. */
			__( '<p>Someone requested a password reset for your %1$s account (<strong>%2$s</strong>).</p><p>If this was you, set a new password using the button below. If it wasn\'t, you can ignore this email and your password will stay the same.</p><p><a href="%3$s" style="display:inline-block;background:#0073aa;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600;">Reset my password</a></p><p style="font-size:13px;color:#6b7280;">Or paste this link into your browser:<br>%3$s</p>', 'buddynext' ),
			esc_html( $site_name ),
			esc_html( $user_login ),
			esc_url( $url )
		);

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Reset your %s password', 'buddynext' ),
			$site_name
		);

		$headers      = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_name    = \BuddyNext\Notifications\EmailSender::from_name();
		$from_address = \BuddyNext\Notifications\EmailSender::from_address();
		if ( '' !== $from_address && is_email( $from_address ) ) {
			$headers[] = '' !== $from_name
				? sprintf( 'From: %s <%s>', $from_name, $from_address )
				: 'From: ' . $from_address;
		}
		$reply_to = sanitize_email( (string) get_option( 'buddynext_email_reply_to', '' ) );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$defaults['subject'] = $subject;
		$defaults['message'] = \BuddyNext\Notifications\EmailSender::brand_wrap( $body, $subject );
		$defaults['headers'] = $headers;

		return $defaults;
	}

	/**
	 * POST /auth/reset-password — set a new password from a reset key.
	 *
	 * Validates the key with WordPress core check_password_reset_key() and
	 * commits via reset_password(), so the security model is core's. The branded
	 * screen at /{auth}/reset/?key=...&login=... posts here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$key      = sanitize_text_field( (string) $request->get_param( 'key' ) );
		$login    = sanitize_text_field( (string) $request->get_param( 'login' ) );
		$password = (string) $request->get_param( 'password' );

		$limited = $this->rate_limit_guard( 'reset', $login, true );
		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		if ( strlen( $password ) < 8 ) {
			return new WP_Error(
				'rest_reset_failed',
				__( 'Please correct the errors below.', 'buddynext' ),
				array(
					'status' => 422,
					'fields' => array( 'password' => __( 'Password must be at least 8 characters.', 'buddynext' ) ),
				)
			);
		}

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'rest_reset_invalid',
				__( 'This password-reset link has expired or is invalid. Please request a new one.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		reset_password( $user, $password );
		self::mark_password_known( (int) $user->ID );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'message'     => __( 'Your password has been reset. You can now sign in.', 'buddynext' ),
				'redirect_to' => \BuddyNext\Core\PageRouter::auth_url(),
			),
			200
		);
	}

	/**
	 * Resend the verification email for the currently logged-in user.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_verification(): WP_REST_Response|WP_Error {
		// Email Verification feature off: the endpoint has nothing to do.
		if ( ! buddynext_feature_enabled( 'verification' ) ) {
			return new WP_Error(
				'buddynext_verification_disabled',
				__( 'Email verification is not enabled on this community.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$user_id = get_current_user_id();
		$svc     = new VerificationService();
		$result  = $svc->resend( $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response(
			array( 'message' => __( 'Verification email sent.', 'buddynext' ) ),
			200
		);
	}

	/**
	 * Return the email verification status for the currently logged-in user.
	 *
	 * @return WP_REST_Response
	 */
	public function verification_status(): WP_REST_Response {
		// Email Verification feature off: there is no "unverified" state, so the
		// caller should treat every account as cleared rather than gating on a
		// subsystem the owner disabled.
		if ( ! buddynext_feature_enabled( 'verification' ) ) {
			return new WP_REST_Response(
				array(
					'verified' => true,
					'enabled'  => false,
				),
				200
			);
		}

		$user_id  = get_current_user_id();
		$svc      = new VerificationService();
		$verified = $svc->is_verified( $user_id );

		return new WP_REST_Response(
			array(
				'verified' => $verified,
				'enabled'  => true,
			),
			200
		);
	}

	/**
	 * Apply the site-default DM-privacy preference to a new account.
	 *
	 * Reads buddynext_default_dm_access (Settings → General → Direct Messaging)
	 * and writes it to the member's bn_privacy_dm meta — the same key the
	 * privacy settings screen and the messaging layer read. Only sets the value
	 * when the member has no explicit preference yet, so a member who later
	 * changes their privacy is never overwritten, and re-running registration
	 * flows stays idempotent.
	 *
	 * The site default is validated against the canonical audience vocabulary
	 * (everyone / members / connections / nobody) so a stale or filtered option
	 * value can never seed an invalid preference.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public static function seed_default_dm_access( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		// Don't clobber an explicit preference (e.g. set during onboarding).
		if ( '' !== (string) get_user_meta( $user_id, 'bn_privacy_dm', true ) ) {
			return;
		}

		$default   = (string) get_option( 'buddynext_default_dm_access', 'everyone' );
		$audiences = array( 'everyone', 'members', 'connections', 'nobody' );
		if ( ! in_array( $default, $audiences, true ) ) {
			$default = 'everyone';
		}

		update_user_meta( $user_id, 'bn_privacy_dm', $default );
	}

	/**
	 * Persist registration profile-field values onto a freshly created account.
	 *
	 * DB-backed fields (those with a bn_profile_fields row) are written via
	 * ProfileService::save_profile so they land in bn_profile_values and the
	 * searchable usermeta mirror. Programmatic/virtual fields (id 0, registered via
	 * buddynext_register_member_field() / buddynext_register_profile_field()) have no
	 * row, so their value is stored to usermeta as bn_field_{key} — the same key
	 * get_profile()'s virtual merge and save_profile()'s virtual branch use. Addons can
	 * take over storage entirely on the buddynext_registration_fields_saved action.
	 *
	 * @param int                             $user_id     New user id.
	 * @param array<int, array<string,mixed>> $reg_fields The registration field defs.
	 * @param array<string, mixed>            $reg_values  field_key => sanitised value.
	 * @param object|null                     $profile_svc Resolved ProfileService (or null).
	 * @return void
	 */
	public static function save_registration_fields( int $user_id, array $reg_fields, array $reg_values, $profile_svc ): void {
		if ( $user_id <= 0 || empty( $reg_fields ) ) {
			return;
		}

		$db_values = array();
		foreach ( $reg_fields as $reg_field ) {
			$field_key = (string) $reg_field['field_key'];
			if ( ! array_key_exists( $field_key, $reg_values ) ) {
				continue;
			}

			if ( (int) ( $reg_field['id'] ?? 0 ) > 0 ) {
				$db_values[ $field_key ] = $reg_values[ $field_key ];
			} else {
				// Virtual (programmatic) field: no table row to write to.
				update_user_meta( $user_id, 'bn_field_' . $field_key, $reg_values[ $field_key ] );
			}
		}

		if ( ! empty( $db_values ) && is_object( $profile_svc ) && method_exists( $profile_svc, 'save_profile' ) ) {
			$profile_svc->save_profile( $user_id, $db_values );
		}

		/**
		 * Fires after registration profile-field values are saved to a new account.
		 *
		 * @param int                  $user_id    New user id.
		 * @param array<string, mixed> $reg_values field_key => sanitised value.
		 * @param array                $reg_fields The registration field definitions.
		 */
		do_action( 'buddynext_registration_fields_saved', $user_id, $reg_values, $reg_fields );
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
	public function require_auth(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Permission callback: require a site administrator.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do this.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /auth/approve/{id} — approve a pending (approval-mode) registration.
	 *
	 * Clears the bn_pending_approval flag so the account can sign in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		if ( ! get_user_meta( $user_id, 'bn_pending_approval', true ) ) {
			return new WP_REST_Response(
				array(
					'approved' => true,
					'already'  => true,
				),
				200
			);
		}

		delete_user_meta( $user_id, 'bn_pending_approval' );

		/**
		 * Fires when an administrator approves a pending registration.
		 *
		 * @param int $user_id Approved user ID.
		 */
		do_action( 'buddynext_member_approved', $user_id );

		return new WP_REST_Response( array( 'approved' => true ), 200 );
	}
}
