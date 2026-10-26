<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Webhook endpoint for managing user access, roles, abilities, and credits.
 *
 * POST buddynext/v1/webhook/access
 *
 * All requests must carry a valid HMAC-SHA256 signature in the
 * X-BuddyNext-Signature header. The shared secret is stored in the
 * buddynext_webhook_secret option and set via the admin settings page.
 *
 * Supported actions:
 *   set_role       — Set the user's community role (admin/moderator/member).
 *   grant_ability  — Grant a BuddyNext ability with optional expiry.
 *   revoke_ability — Remove a granted ability.
 *   add_credits    — Add to the user's credit balance.
 *   set_credits    — Replace the user's credit balance.
 *   deduct_credits — Subtract from the balance (floors at 0).
 *   grant_plan     — Give a membership plan: shorthand for grant_ability "tier:<plan>".
 *   extend_plan    — Move a plan's end date: grant_plan with a required expires_at.
 *   cancel_plan    — End a plan at the end of its period (at_period_end, default),
 *                    or at once with "at_period_end": false. Plans need BuddyNext Pro.
 *
 * Every call is written to bn_webhook_log for auditing.
 *
 * @package BuddyNext\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Outbound;

use BuddyNext\Core\PermissionService;
use BuddyNext\Core\RoleService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Handles incoming access-management webhooks.
 */
class AccessWebhookController {

	/**
	 * Register the route with the WordPress REST API.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/webhook/access',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$signature_check = $this->verify_signature( $request );

		if ( $signature_check instanceof WP_Error ) {
			return $signature_check;
		}

		if ( ! $signature_check ) {
			return new WP_Error(
				'invalid_signature',
				__( 'Invalid webhook signature. Sign "{timestamp}.{raw body}" with HMAC-SHA256 and the site secret, and send the hex digest in X-BuddyNext-Signature.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Payload must be valid JSON.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$action = sanitize_key( $body['action'] ?? '' );

		// Plan words for no-code tools, run through the ability actions underneath so
		// every guard (lock, idempotency, member creation, logging) applies to them.
		$requested    = $action;
		$plan_request = self::plan_shorthand( $action, $body );
		if ( $plan_request instanceof WP_Error ) {
			return $plan_request;
		}
		list( $action, $body ) = $plan_request;

		/**
		 * Filters the actions the access webhook accepts.
		 *
		 * One secret authorises every action, so a site that only sells plans can
		 * drop the ones it never wants a third-party tool to perform, set_role for
		 * example.
		 *
		 * @since 1.2.5
		 *
		 * @param string[] $actions Accepted action slugs.
		 */
		$allowed = (array) apply_filters( 'buddynext_access_webhook_actions', self::default_actions() );

		$this->role_changes_allowed = in_array( 'set_role', $allowed, true );
		// Both the action asked for and the one it runs as must be allowed: a site
		// that switched grant_ability off has switched plan grants off too.
		$refused = ! in_array( $requested, $allowed, true ) ? $requested : ( ! in_array( $action, $allowed, true ) ? $action : '' );
		if ( '' !== $refused && in_array( $refused, self::ACTIONS, true ) ) {
			return new WP_Error(
				'action_not_allowed',
				'set_role' === $refused
					? __( 'Role changes from outside are switched off on this site. An administrator can allow them under Platform, Webhooks.', 'buddynext' )
					/* translators: %s: action name */
					: sprintf( __( 'The action "%s" is switched off on this site.', 'buddynext' ), $refused ),
				array( 'status' => 403 )
			);
		}

		$user    = $this->resolve_user( $body );
		$created = false;

		// A buyer with no account yet: when the owner allows it, the member is
		// created from the paid email and then given what the request asks for.
		if ( $user instanceof WP_Error && 'user_not_found' === $user->get_error_code() && $this->may_create_member( $action, $body ) ) {
			$user = $this->create_member( $body, $created );
		}

		if ( $user instanceof WP_Error ) {
			return $user;
		}

		// One request at a time per member. A tool that retries, or runs branches in
		// parallel, sends the same grant several times at once; without this each
		// copy read "no plan yet" and wrote its own, and a retried credit was added
		// twice before the first could be remembered.
		global $wpdb;
		$lock = 'bn_access_webhook_' . $user->ID;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new WP_Error(
				'busy',
				__( 'Another request for this member is still being processed. Try again.', 'buddynext' ),
				array( 'status' => 409 )
			);
		}

		// The member can have been removed while this request waited: the request
		// that created the account for a new buyer takes it away again when its own
		// grant fails. Answer "try again" so the tool's retry starts clean.
		if ( ! $created ) {
			clean_user_cache( $user->ID );
			if ( ! get_userdata( $user->ID ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

				return new WP_Error(
					'busy',
					__( 'Another request for this member is still being processed. Try again.', 'buddynext' ),
					array( 'status' => 409 )
				);
			}
		}

		try {
			// Asked again now that the lock is held. verify_signature() looked for
			// this signature before the lock, and the signature is only recorded
			// once the action has run, so two copies of one captured request that
			// arrived together both passed that first look. The second copy waits
			// here for the first to finish and record itself, and is then refused.
			$response = $this->replay_seen( $this->accepted_signature )
				? new WP_Error(
					'replayed_request',
					__( 'This request has already been processed.', 'buddynext' ),
					array( 'status' => 409 )
				)
				: $this->run( $action, $user->ID, $body );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		if ( $created ) {
			// Still inside the hold create_member() took on the new member, so no
			// other request has been able to give this account anything yet.
			if ( $response instanceof WP_Error ) {
				// The account existed only to receive this grant. Nothing was
				// granted, so nothing is left behind and no email goes out.
				buddynext_remove_member( (int) $user->ID );
			} else {
				\BuddyNext\Auth\AuthController::send_set_password_email( $user );
				$response['member_created'] = true;
				$response['user_id']        = $user->ID;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		if ( is_array( $response ) ) {
			// Answer with the action the caller sent (grant_plan, not grant_ability).
			$response['action'] = $requested;
		}

		return $response instanceof WP_Error ? $response : new WP_REST_Response( $response, 200 );
	}

	/**
	 * Whether this request may change roles, after the owner's switch and the
	 * developer filter. Staff-only abilities follow it too.
	 *
	 * @var bool
	 */
	private bool $role_changes_allowed = false;

	/**
	 * Option that lets a signed request change a member's community role.
	 */
	public const OPT_ALLOW_SET_ROLE = 'buddynext_webhook_allow_set_role';

	/**
	 * Set once the owner has saved the Webhooks settings with the role switch on
	 * the screen, whichever way they left it.
	 */
	public const OPT_ROLE_REVIEWED = 'buddynext_webhook_role_reviewed';

	/**
	 * The actions this site accepts before any filter.
	 *
	 * Everything except set_role, which the owner switches on. One secret
	 * authorises every action, and changing roles is the most a leaked secret or
	 * a misused automation account could do, so it is not on unless asked for.
	 *
	 * @return string[]
	 */
	public static function default_actions(): array {
		return (bool) get_option( self::OPT_ALLOW_SET_ROLE, false )
			? self::ACTIONS
			: array_values( array_diff( self::ACTIONS, array( 'set_role' ) ) );
	}

	/**
	 * Whether to tell the owner that role changes from outside are now off.
	 *
	 * Only on a site that has used them (its webhook log holds a set_role row)
	 * and whose owner has not yet saved the Webhooks screen since (either way).
	 *
	 * @return bool
	 */
	public static function role_switch_needs_attention(): bool {
		global $wpdb;

		$used = get_transient( 'buddynext_webhook_set_role_used' );
		if ( false === $used ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$used = $wpdb->get_var( "SELECT 1 FROM {$wpdb->prefix}bn_webhook_log WHERE action = 'set_role' LIMIT 1" ) ? 'yes' : 'no';
			set_transient( 'buddynext_webhook_set_role_used', $used, DAY_IN_SECONDS );
		}
		if ( 'yes' !== $used ) {
			return false;
		}

		// Chosen = the owner has saved the Webhooks settings since (either way).
		// The switch's own value cannot say: off and never-set read the same.
		return ! get_option( self::OPT_ROLE_REVIEWED );
	}

	/**
	 * Option that lets a signed grant create the member when the email is new.
	 */
	public const OPT_CREATE_MEMBERS = 'buddynext_webhook_create_members';

	/**
	 * Whether this request may create the member it names.
	 *
	 * Only when the owner switched it on, only for a request that gives
	 * something (a plan, an ability, credits), only by email, and only on the
	 * timestamped signature: a body-only signature can be replayed for ever and
	 * must not be able to mint accounts.
	 *
	 * @param string $action Requested action.
	 * @param array  $body   Request body.
	 * @return bool
	 */
	private function may_create_member( string $action, array $body ): bool {
		return (bool) get_option( self::OPT_CREATE_MEMBERS, false )
			&& in_array( $action, array( 'grant_ability', 'add_credits' ), true )
			&& '' !== $this->accepted_signature
			&& empty( $body['user_id'] )
			&& is_string( $body['user_email'] ?? null )
			&& (bool) is_email( sanitize_email( $body['user_email'] ) );
	}

	/**
	 * Create the member a request names by email.
	 *
	 * One at a time per address, so a tool that sends the same purchase twice at
	 * once gets one account.
	 *
	 * @param array $body    Request body (user_email, optional name).
	 * @param bool  $created Set to true only when THIS request made the account.
	 *                       A copy that found the account already made by another
	 *                       must never be the one to remove it.
	 * @return WP_User|WP_Error
	 */
	private function create_member( array $body, bool &$created ): WP_User|WP_Error {
		global $wpdb;

		$created = false;

		$email = sanitize_email( (string) $body['user_email'] );
		$lock  = 'bn_access_webhook_new_' . md5( strtolower( $email ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new WP_Error( 'busy', __( 'Another request for this member is still being processed. Try again.', 'buddynext' ), array( 'status' => 409 ) );
		}

		try {
			$existing = get_user_by( 'email', $email );
			if ( $existing instanceof WP_User ) {
				return $existing; // The other copy of this request got here first.
			}

			$user_id = ( new \BuddyNext\Auth\RegistrationService() )->create_provisioned( $email, is_string( $body['name'] ?? null ) ? $body['name'] : '' );

			// Hold the new member before letting go of the address. handle() takes
			// the same lock again (a session may hold one lock twice) and gives this
			// hold back at the very end, so another purchase for the same buyer
			// waits until this request has either kept the account or removed it.
			if ( ! is_wp_error( $user_id ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$held = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', 'bn_access_webhook_' . (int) $user_id ) );
				if ( ! $held ) {
					// Without the hold this request could not safely take the account
					// away again later, so it does not keep one it cannot guard.
					buddynext_remove_member( (int) $user_id );
					$user_id = new WP_Error( 'busy', __( 'Another request for this member is still being processed. Try again.', 'buddynext' ), array( 'status' => 409 ) );
				}
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'user_not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}
		$created = true;

		return $user;
	}

	/**
	 * The actions this endpoint knows.
	 */
	private const ACTIONS = array( 'set_role', 'grant_ability', 'revoke_ability', 'add_credits', 'set_credits', 'deduct_credits', 'grant_plan', 'extend_plan', 'cancel_plan' );

	/**
	 * How long an idempotency key is remembered.
	 */
	private const IDEMPOTENCY_TTL = DAY_IN_SECONDS;

	/**
	 * Run one action for one member and build the answer.
	 *
	 * Called with the member's lock held. A request that carries an
	 * `idempotency_key` already used in the last day gets the first answer back
	 * and changes nothing, so a tool's retry cannot credit or grant twice.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id Member the request is about.
	 * @param array  $body    Decoded request body.
	 * @return array<string,mixed>|WP_Error Response body, or the refusal.
	 * @throws \Throwable Whatever the action threw, after the request is logged.
	 */
	private function run( string $action, int $user_id, array $body ): array|WP_Error {
		$key       = is_scalar( $body['idempotency_key'] ?? null ) ? trim( (string) $body['idempotency_key'] ) : '';
		$transient = '' !== $key ? 'bn_awh_' . md5( $user_id . '|' . $action . '|' . $key ) : '';

		// What the request asks for, apart from the key itself and anything a tool
		// adds per attempt. The same key with the same ask is a retry; the same key
		// with a different ask is a mapping mistake in the sender and is refused,
		// where it used to be answered as a retry and the new ask dropped.
		$ask = md5( (string) wp_json_encode( array_intersect_key( $body, array_flip( array( 'role', 'ability', 'expires_at', 'lifetime', 'amount', 'source', 'at_period_end', 'currency', 'reference' ) ) ) ) );

		if ( '' !== $transient ) {
			$earlier = get_transient( $transient );
			if ( is_array( $earlier ) ) {
				if ( ( $earlier['_ask'] ?? $ask ) !== $ask ) {
					return new WP_Error(
						'idempotency_conflict',
						__( 'This "idempotency_key" was already used for a different request for this member. Use a new key for a new request.', 'buddynext' ),
						array( 'status' => 409 )
					);
				}
				unset( $earlier['_ask'] );
				$earlier['replayed'] = true;

				return $earlier;
			}
		}

		/**
		 * Lets an extension refuse an action before anything is written.
		 *
		 * Pro uses it to answer 422 for a plan that does not exist, so the caller
		 * is not told a grant worked when no plan was given.
		 *
		 * @since 1.2.5
		 *
		 * @param WP_Error|null $refusal Null to carry on.
		 * @param string        $action  Action slug.
		 * @param int           $user_id Member the request is about.
		 * @param array         $body    Decoded request body.
		 */
		$refusal = apply_filters( 'buddynext_access_webhook_pre_action', null, $action, $user_id, $body );

		// This request logs itself, below, with the signed body — richer than
		// anything the action can carry. WebhookLogListener watches the same grant
		// action for callers that have no request to log from, so it is told to
		// stand down for the duration of this one. Without that, every HTTP grant
		// would be recorded twice: once here and once by the listener.
		WebhookLog::begin_request_scope();
		try {
			$result = $refusal instanceof WP_Error ? $refusal : $this->dispatch( $action, $user_id, $body );
		} catch ( \Throwable $error ) {
			// Recorded even when the action blew up part-way, so the signature is on
			// file and the same request cannot be sent again to finish the job twice.
			WebhookLog::end_request_scope();
			$this->log( $action, $user_id, $body, 'error' );
			throw $error;
		}
		WebhookLog::end_request_scope();

		$this->log( $action, $user_id, $body, $result instanceof WP_Error ? 'error' : 'success' );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$response = array_merge(
			array(
				'success' => true,
				'action'  => $action,
				'user_id' => $user_id,
			),
			$result
		);

		/**
		 * Filters what the access webhook answers after a successful action.
		 *
		 * Pro adds the plan and the subscription the grant or revoke ended up on.
		 *
		 * @since 1.2.5
		 *
		 * @param array<string,mixed> $response Response body.
		 * @param string              $action   Action slug.
		 * @param int                 $user_id  Member the request was about.
		 * @param array               $body     Decoded request body.
		 */
		$response = (array) apply_filters( 'buddynext_access_webhook_response', $response, $action, $user_id, $body );

		/**
		 * Fires after an access webhook action succeeded (not on a replayed retry).
		 *
		 * Pro records the payment facts an outside tool sent with a plan grant.
		 *
		 * @since 1.2.5
		 *
		 * @param string              $action   Action slug, after plan shorthand.
		 * @param int                 $user_id  Member the request was about.
		 * @param array               $body     Decoded request body.
		 * @param array<string,mixed> $response What the caller is answered.
		 */
		do_action( 'buddynext_access_webhook_completed', $action, $user_id, $body, $response );

		if ( '' !== $transient ) {
			set_transient( $transient, $response + array( '_ask' => $ask ), self::IDEMPOTENCY_TTL );
		}

		return $response;
	}

	/**
	 * Route the action to its handler.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id Resolved user ID.
	 * @param array  $body    Decoded request body.
	 * @return array<string,mixed>|WP_Error What changed, for the response.
	 */
	private function dispatch( string $action, int $user_id, array $body ): array|WP_Error {
		return match ( $action ) {
			'set_role'       => $this->action_set_role( $user_id, $body ),
			'grant_ability'  => $this->action_grant_ability( $user_id, $body ),
			'revoke_ability' => $this->action_revoke_ability( $user_id, $body ),
			'add_credits', 'set_credits', 'deduct_credits' => $this->action_credits( $action, $user_id, $body ),
			'cancel_plan'    => $this->action_cancel_plan( $user_id, $body ),
			default          => new WP_Error(
				'unknown_action',
				/* translators: %s: action name */
				sprintf( __( 'Unknown action: "%s".', 'buddynext' ), $action ),
				array( 'status' => 400 )
			),
		};
	}

	/**
	 * Turn a plan action into the ability action it stands for.
	 *
	 * The grant_plan and extend_plan actions are grant_ability "tier:<plan>" (extend_plan must
	 * say until when); cancel_plan "at_period_end": false is revoke_ability. A
	 * period-end cancel stays cancel_plan, which needs the plan's own billing
	 * period and is answered by Pro.
	 *
	 * @param string $action Requested action.
	 * @param array  $body   Request body.
	 * @return array{0:string,1:array}|WP_Error The action and body to run.
	 */
	private static function plan_shorthand( string $action, array $body ): array|WP_Error {
		if ( ! in_array( $action, array( 'grant_plan', 'extend_plan', 'cancel_plan' ), true ) ) {
			return array( $action, $body );
		}

		$plan = is_string( $body['plan'] ?? null ) ? sanitize_key( $body['plan'] ) : '';
		if ( '' === $plan ) {
			return new WP_Error( 'missing_plan', __( 'Send "plan" with the plan\'s slug.', 'buddynext' ), array( 'status' => 400 ) );
		}
		if ( 'extend_plan' === $action && empty( $body['expires_at'] ) ) {
			return new WP_Error( 'missing_expires_at', __( 'extend_plan needs "expires_at", the new end date.', 'buddynext' ), array( 'status' => 400 ) );
		}

		$body['ability'] = 'tier:' . $plan;

		if ( 'cancel_plan' !== $action ) {
			return array( 'grant_ability', $body );
		}

		$at_period_end = array_key_exists( 'at_period_end', $body ) ? filter_var( $body['at_period_end'], FILTER_VALIDATE_BOOLEAN ) : true;

		return array( $at_period_end ? 'cancel_plan' : 'revoke_ability', $body );
	}

	/**
	 * End a plan at the end of the period the member already has.
	 *
	 * @param int   $user_id Member.
	 * @param array $body    Request body (ability "tier:<plan>").
	 * @return array<string,mixed>|WP_Error
	 */
	private function action_cancel_plan( int $user_id, array $body ): array|WP_Error {
		/**
		 * Cancels a member's plan at the end of its period.
		 *
		 * Pro answers with what changed (plan, subscription, access end) or a
		 * WP_Error; without Pro there are no plans to cancel.
		 *
		 * @since 1.2.5
		 *
		 * @param array|WP_Error|null $result  Null until a handler answers.
		 * @param int                 $user_id Member.
		 * @param string              $ability "tier:<plan>".
		 * @param array               $body    Request body.
		 */
		$result = apply_filters( 'buddynext_access_webhook_cancel_plan', null, $user_id, (string) $body['ability'], $body );

		if ( null === $result ) {
			return new WP_Error( 'plans_unavailable', __( 'Membership plans need BuddyNext Pro.', 'buddynext' ), array( 'status' => 501 ) );
		}

		return $result instanceof WP_Error ? $result : (array) $result;
	}

	/**
	 * Set a user's community role.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function action_set_role( int $user_id, array $body ): array|WP_Error {
		$role = sanitize_key( $body['role'] ?? '' );

		if ( ! in_array( $role, array( 'admin', 'moderator', 'member' ), true ) ) {
			return new WP_Error(
				'invalid_role',
				__( 'Role must be admin, moderator, or member.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		update_user_meta( $user_id, 'bn_community_role', $role );

		/**
		 * Fires when a user's community role changes via webhook.
		 *
		 * @param int    $user_id User ID.
		 * @param string $role    New role slug.
		 */
		do_action( 'buddynext_role_changed', $user_id, $role );

		return array( 'role' => $role );
	}

	/**
	 * Grant an ability to a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function action_grant_ability( int $user_id, array $body ): array|WP_Error {
		$ability = sanitize_text_field( $body['ability'] ?? '' );
		$source  = sanitize_key( $body['source'] ?? '' );

		if ( ! $ability ) {
			return new WP_Error(
				'missing_ability',
				__( '"ability" is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		// A moderator or admin power granted as an ability is a role change by
		// another name, so it follows the same switch.
		if ( ! $this->role_changes_allowed && PermissionService::is_staff_ability( $ability ) ) {
			return new WP_Error(
				'action_not_allowed',
				__( 'That ability belongs to moderators or admins. Role changes from outside are switched off on this site. An administrator can allow them under Platform, Webhooks.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$expires_ts = $this->grant_expiry( $ability, $user_id, $body );
		if ( $expires_ts instanceof WP_Error ) {
			return $expires_ts;
		}

		update_user_meta( $user_id, PermissionService::ability_meta_key( $ability ), $expires_ts );

		/**
		 * Fires when an ability is granted to a user.
		 *
		 * @param int    $user_id User ID.
		 * @param string $ability Ability slug.
		 * @param string $source  Source tag (e.g. 'stripe', 'manual'); empty when not supplied.
		 */
		do_action( 'buddynext_ability_granted', $user_id, $ability, $source );

		return array(
			'ability'    => $ability,
			'expires_at' => $expires_ts > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $expires_ts ) : null,
		);
	}

	/**
	 * When a grant ends, as a unix timestamp; 0 for one that never ends.
	 *
	 * `expires_at` is an ISO 8601 date or unix seconds (milliseconds are accepted
	 * and divided). A date with no time means the end of that day, UTC, so the
	 * member keeps the last day they paid for. A value that cannot be read, or
	 * that is already in the past, is refused: it used to become "never expires",
	 * the most generous reading of a mistake.
	 *
	 * With no `expires_at`, the grant never ends unless an extension supplies a
	 * default (Pro gives a plan its own month or year). `"lifetime": true` says
	 * never-ending out loud and skips that default.
	 *
	 * @param string $ability Ability slug.
	 * @param int    $user_id Member the grant is for.
	 * @param array  $body    Request body.
	 * @return int|WP_Error
	 */
	private function grant_expiry( string $ability, int $user_id, array $body ): int|WP_Error {
		$raw = $body['expires_at'] ?? null;

		if ( null === $raw || '' === $raw ) {
			if ( ! empty( $body['lifetime'] ) && 'false' !== $body['lifetime'] ) {
				return 0;
			}

			/**
			 * Filters when a grant with no `expires_at` ends.
			 *
			 * @since 1.2.5
			 *
			 * @param int    $expires_ts Unix timestamp; 0 for never.
			 * @param string $ability    Ability slug.
			 * @param int    $user_id    Member the grant is for.
			 * @param array  $body       Request body.
			 */
			return max( 0, (int) apply_filters( 'buddynext_access_webhook_default_expiry', 0, $ability, $user_id, $body ) );
		}

		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( 1 === preg_match( '/^\d{10}$/', $raw ) ) {
			$ts = (int) $raw;
		} elseif ( 1 === preg_match( '/^\d{13}$/', $raw ) ) {
			$ts = (int) floor( (int) $raw / 1000 );
		} elseif ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			$ts = (int) strtotime( $raw . 'T23:59:59Z' );
		} elseif ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[T ]/', $raw ) ) {
			$ts = (int) strtotime( $raw );
		} else {
			$ts = 0;
		}

		if ( $ts <= time() ) {
			return new WP_Error(
				'invalid_expires_at',
				__( '"expires_at" must be a future date: ISO 8601 (2027-01-31T00:00:00Z) or unix seconds. Leave it out and send "lifetime": true for a grant that never ends.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		return $ts;
	}

	/**
	 * Revoke an ability from a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function action_revoke_ability( int $user_id, array $body ): array|WP_Error {
		$ability = sanitize_text_field( $body['ability'] ?? '' );

		if ( ! $ability ) {
			return new WP_Error(
				'missing_ability',
				__( '"ability" is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		delete_user_meta( $user_id, PermissionService::ability_meta_key( $ability ) );

		/**
		 * Fires when an ability is revoked from a user.
		 *
		 * @param int    $user_id User ID.
		 * @param string $ability Ability slug.
		 */
		do_action( 'buddynext_ability_revoked', $user_id, $ability );

		return array( 'ability' => $ability );
	}

	/**
	 * Add to, replace or deduct from a member's credit balance.
	 *
	 * `amount` is required and must be a whole number, zero or more. A missing
	 * amount used to count as 0 (set_credits wiped the balance), a negative one
	 * had its sign dropped (a deduction became a top-up) and a decimal was cut
	 * short, all answered with success.
	 *
	 * @param string $action  add_credits, set_credits or deduct_credits.
	 * @param int    $user_id User ID.
	 * @param array  $body    Request body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function action_credits( string $action, int $user_id, array $body ): array|WP_Error {
		$amount = $body['amount'] ?? null;
		$amount = is_int( $amount ) ? (string) $amount : ( is_string( $amount ) ? trim( $amount ) : '' );

		if ( 1 !== preg_match( '/^\d{1,15}$/', $amount ) ) {
			return new WP_Error(
				'invalid_amount',
				__( '"amount" is required and must be a whole number, zero or more.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$roles = new RoleService();
		if ( 'add_credits' === $action ) {
			$roles->add_credits( $user_id, (int) $amount );
		} elseif ( 'set_credits' === $action ) {
			$roles->set_credits( $user_id, (int) $amount );
		} else {
			$roles->deduct_credits( $user_id, (int) $amount );
		}

		return array( 'balance' => $roles->get_credits( $user_id ) );
	}

	/**
	 * Resolve the member a request is about, from user_id or user_email.
	 *
	 * When both are sent they must name the same member. A scenario that maps the
	 * wrong field used to act on whoever user_id pointed at and report success.
	 *
	 * @param array $body Request body.
	 * @return WP_User|WP_Error
	 */
	private function resolve_user( array $body ): WP_User|WP_Error {
		$by_id    = ! empty( $body['user_id'] ) ? get_userdata( (int) $body['user_id'] ) : null;
		$by_email = ! empty( $body['user_email'] ) ? get_user_by( 'email', sanitize_email( (string) $body['user_email'] ) ) : null;

		if ( ! empty( $body['user_id'] ) && ! empty( $body['user_email'] ) && ( ! $by_id || ! $by_email || $by_id->ID !== $by_email->ID ) ) {
			return new WP_Error(
				'identity_conflict',
				__( '"user_id" and "user_email" do not name the same member. Send one of them, or both for the same member.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$user = $by_id ? $by_id : $by_email;

		return $user instanceof WP_User ? $user : new WP_Error(
			'user_not_found',
			__( 'Member not found.', 'buddynext' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Signature this request was accepted on, recorded so a replay is detectable.
	 *
	 * Empty for a legacy body-only request: there is nothing time-bounded to
	 * compare a later copy against, which is the reason that scheme is going.
	 *
	 * @var string
	 */
	private string $accepted_signature = '';

	/**
	 * Tolerance, in seconds, between a request's timestamp and now.
	 *
	 * Wide enough for ordinary clock drift between two servers, narrow enough
	 * that a captured request is worthless within minutes.
	 */
	private const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Option that turns off acceptance of the pre-1.1.6 signature scheme.
	 */
	public const OPT_STRICT_SIGNATURES = 'buddynext_webhook_strict_signatures';

	/**
	 * Verify the HMAC-SHA256 signature from the X-BuddyNext-Signature header.
	 *
	 * Two schemes are accepted during the migration window.
	 *
	 * The current one signs `"{timestamp}.{body}"` and sends the timestamp in
	 * `X-BuddyNext-Timestamp`. The old one signed the body alone, which made a
	 * captured request valid forever: `grant_ability` replayed on a timer renews
	 * a membership indefinitely, and nothing about the request ever goes stale.
	 * Signing the timestamp is what puts an expiry on a captured call, and
	 * recording the signature (see `replay_seen()`) is what stops it being used
	 * twice inside that window.
	 *
	 * Timestamped signatures are now REQUIRED by default (1.1.6): the body-only
	 * fallback is refused unless a site explicitly re-enables it. A site still
	 * mid-migration can keep accepting the legacy scheme by setting
	 * `buddynext_webhook_strict_signatures` to '0', or per-request via the
	 * `buddynext_require_signed_timestamp` filter — the opt-out for a straggler
	 * finishing a move. Each legacy call that IS accepted is still logged as
	 * deprecated so an owner can see who to move.
	 *
	 * This flip closes the replay window on upgraded sites too (fresh installs
	 * were already strict via Installer::run()). It is a breaking change for a
	 * sender still signing the body alone — that is the deliberate, owner-chosen
	 * trade recorded on Basecamp 10227863022; note it in the release changelog.
	 *
	 * Returns a WP_Error when the secret is not configured or the request is
	 * stale/replayed, false when no scheme matches, true when one does.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|false|WP_Error
	 */
	private function verify_signature( WP_REST_Request $request ): bool|WP_Error {
		$secret = (string) get_option( 'buddynext_webhook_secret', '' );

		if ( '' === $secret ) {
			return new WP_Error(
				'webhook_not_configured',
				__( 'Webhook secret is not configured.', 'buddynext' ),
				array( 'status' => 503 )
			);
		}

		$header    = (string) ( $request->get_header( 'X-BuddyNext-Signature' ) ?? '' );
		$timestamp = (string) ( $request->get_header( 'X-BuddyNext-Timestamp' ) ?? '' );
		$body      = (string) $request->get_body();

		// The digest may arrive as `sha256=<hex>` or bare hex, in either case: every
		// one of those is how some hosted automation tool prints an HMAC. It is
		// normalised here and still compared in constant time.
		$header = strtolower( trim( $header ) );
		$header = 'sha256=' . ( str_starts_with( $header, 'sha256=' ) ? substr( $header, 7 ) : $header );

		if ( '' !== $timestamp ) {
			$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

			if ( ! hash_equals( $expected, $header ) ) {
				return false;
			}

			// Signature checked first, deliberately: a stale-timestamp message for
			// an unsigned request would tell an attacker their guess was otherwise
			// well-formed. A 13-digit value is milliseconds (JavaScript Date.now()).
			$sent_at = 13 === strlen( $timestamp ) ? (int) floor( (int) $timestamp / 1000 ) : (int) $timestamp;
			if ( abs( time() - $sent_at ) > self::TIMESTAMP_TOLERANCE ) {
				return new WP_Error(
					'stale_request',
					__( 'Request timestamp is outside the accepted window. Send the current unix time in X-BuddyNext-Timestamp and sign the same value; the request must arrive within five minutes.', 'buddynext' ),
					array( 'status' => 401 )
				);
			}

			if ( $this->replay_seen( self::signature_digest( $header ) ) ) {
				return new WP_Error(
					'replayed_request',
					__( 'This request has already been processed.', 'buddynext' ),
					array( 'status' => 409 )
				);
			}

			$this->accepted_signature = self::signature_digest( $header );

			return true;
		}

		// ── Legacy: body-only signature, no timestamp ──────────────────────────
		// Strict by default now (1.1.6). An upgraded site with legacy senders still
		// mid-migration opts out by setting the option to '0', or per request via
		// the filter. The option default is `true` so a site that never had the
		// row (upgraded from before it existed) is strict, matching a fresh install.
		$strict = (bool) apply_filters(
			'buddynext_require_signed_timestamp',
			(bool) get_option( self::OPT_STRICT_SIGNATURES, true ),
			$request
		);
		if ( $strict ) {
			return new WP_Error(
				'timestamp_required',
				__( 'This site requires a signed request: send the unix time in X-BuddyNext-Timestamp and, in X-BuddyNext-Signature, the HMAC-SHA256 hex of "{timestamp}.{raw body}" made with the site secret. A plain token or bearer header is not accepted.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$legacy = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $legacy, $header ) ) {
			return false;
		}

		$this->accepted_signature = '';

		WebhookLog::write(
			'signature_scheme_deprecated',
			0,
			array(
				'source' => sanitize_key( (string) ( $request->get_param( 'source' ) ?? '' ) ),
				'reason' => 'request signed without X-BuddyNext-Timestamp; this scheme cannot be replay-checked and will stop being accepted',
			),
			WebhookLog::STATUS_ERROR
		);

		return true;
	}

	/**
	 * The hex digest out of a signature header.
	 *
	 * The header is `sha256=<64 hex>` — 71 characters, which does not fit the
	 * CHAR(64) column and made MySQL reject the row outright, so nothing was
	 * recorded and every replay sailed through. The prefix is a constant and
	 * carries no information, so only the digest is kept.
	 *
	 * @param string $header Signature header value.
	 * @return string 64-char digest, or '' when the header is not in that shape.
	 */
	private static function signature_digest( string $header ): string {
		$digest = str_starts_with( $header, 'sha256=' ) ? substr( $header, 7 ) : $header;

		return 1 === preg_match( '/^[a-f0-9]{64}$/', $digest ) ? $digest : '';
	}

	/**
	 * Whether this exact signature has already been accepted recently.
	 *
	 * Only inside the tolerance window: outside it the timestamp check has
	 * already refused the request, so the log does not need to be kept forever
	 * to make this work.
	 *
	 * @param string $signature Signature header value.
	 * @return bool
	 */
	private function replay_seen( string $signature ): bool {
		global $wpdb;

		if ( '' === $signature ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}bn_webhook_log
				 WHERE signature = %s AND created_at >= %s
				 LIMIT 1",
				$signature,
				// Twice the tolerance: a request stamped five minutes ahead (a sender's
				// fast clock) stays acceptable for ten, so the look back must cover ten.
				// Both ends of the tolerance are inclusive, hence >= and the spare
				// second: a row written at the very edge must still be found.
				gmdate( 'Y-m-d H:i:s', time() - 2 * self::TIMESTAMP_TOLERANCE - 1 )
			)
		);

		// A lookup that failed cannot say "not seen". Refusing is the safe answer:
		// the sender retries, where accepting would let a replay through.
		return null !== $found || '' !== (string) $wpdb->last_error;
	}

	/**
	 * Write an audit row to bn_webhook_log.
	 *
	 * Delegates to WebhookLog, which is the shared door: same-site callers that
	 * fire `buddynext_ability_granted` directly have no HTTP request to log from,
	 * and they need the same trail this one writes.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id User ID.
	 * @param array  $body    Request body (payload stored as JSON).
	 * @param string $status  'success' or 'error'.
	 */
	private function log( string $action, int $user_id, array $body, string $status ): void {
		// The row already names the member by id, so the address is not kept in
		// full: only enough of it to recognise a mapping mistake.
		if ( isset( $body['user_email'] ) && is_string( $body['user_email'] ) ) {
			$body['user_email'] = preg_replace( '/^(.).*(@.*)$/', '$1***$2', $body['user_email'] );
		}

		// The stored row is what stops this signed request being used twice. If it
		// could not be stored the action has still run, so an owner must be able to
		// find out: a retry of this request would be accepted again.
		if ( ! WebhookLog::write( $action, $user_id, $body, $status, $this->accepted_signature ) ) {
			error_log( sprintf( 'BuddyNext: the access webhook log row for "%s" (member #%d) could not be stored; replay protection for that request is not in place.', $action, $user_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
