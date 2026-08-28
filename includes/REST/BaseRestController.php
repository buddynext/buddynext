<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Base REST controller.
 *
 * Shared permission helpers for buddynext/v1 controllers. Controllers extend
 * this to avoid re-declaring require_auth()/require_admin(). Each controller
 * still implements register_routes() and its own handlers.
 *
 * @package BuddyNext\REST
 */

declare( strict_types=1 );

namespace BuddyNext\REST;

use WP_Error;
use WP_REST_Request;

/**
 * Permission helpers shared across REST controllers.
 */
abstract class BaseRestController {

	/**
	 * Register the controller's routes. Called from REST\Router.
	 */
	abstract public function register_routes(): void;

	/**
	 * Stamp an HTTP status on a service error WITHOUT destroying one it already set.
	 *
	 * `WP_Error::add_data()` REPLACES the data array for a code — it does not merge
	 * (wp-includes/class-wp-error.php: `$this->error_data[ $code ] = $data`). So the
	 * natural-looking line
	 *
	 *     $result->add_data( array( 'status' => 400 ) );
	 *
	 * silently overwrites whatever the service decided. Services here do decide:
	 * SafeguardService returns 403 for a suspension or a blocked IP and 429 for a
	 * rate limit; SpaceMemberService returns 403 for an archived space and 404 for a
	 * missing one. Every one of those arrived at the client as a flat 400, so a
	 * caller could not tell "you are suspended" from "your input was malformed" —
	 * and a native client cannot show the right thing, or back off on a 429, without
	 * that distinction.
	 *
	 * The default applies only when the service expressed no opinion.
	 *
	 * @param WP_Error $error    The service's error.
	 * @param int      $fallback Status to apply when the service set none.
	 * @return WP_Error The same error, with a status guaranteed.
	 */
	protected function preserve_status( WP_Error $error, int $fallback ): WP_Error {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$error->add_data( array( 'status' => $fallback ) );
		}

		return $error;
	}

	/**
	 * Params WordPress itself may add to any request.
	 *
	 * These are never application data, so a strict handler must let them through
	 * or every request carrying one would be refused.
	 *
	 * @since 1.1.6
	 *
	 * @var string[]
	 */
	protected const RESERVED_PARAMS = array(
		'_locale',
		'_fields',
		'_embed',
		'_envelope',
		'_method',
		'_jsonp',
		'_wpnonce',
		'context',
	);

	/**
	 * Refuse a write whose body carries keys the endpoint does not understand.
	 *
	 * WordPress ignores undeclared params rather than rejecting them, so a caller
	 * that sent the wrong shape got 200 and an empty result set: PUT /me/profile
	 * with {"fields":{...}} answered {"saved":true,"errors":[]} and persisted
	 * nothing, and PUT /profile-fields/{id} accepted five attributes it then threw
	 * away. Silence is the worst answer a write can give — the caller has no way
	 * to tell "saved" from "understood nothing you sent".
	 *
	 * All-or-nothing: this runs BEFORE any persistence, so a request naming one
	 * bad key changes nothing at all. A partly-applied write would leave the
	 * record in a state neither side asked for and make a corrected retry unsafe.
	 *
	 * The allowlist is DERIVED from the route's own registered args, so declaring
	 * an arg is the only thing needed to accept it — there is no second list to
	 * drift. Endpoints whose accepted keys are dynamic (profile fields are owner
	 * -defined) pass them in $extra_allowed.
	 *
	 * Only the BODY is inspected. Query params on a write are transport (nonces,
	 * cache-busters, whatever a proxy appends) and are not the payload contract.
	 *
	 * @since 1.1.6
	 *
	 * @param WP_REST_Request   $request       Incoming request.
	 * @param array<int,string> $extra_allowed Additional accepted top-level keys.
	 * @return WP_Error|null Error when unknown keys are present, null when clean.
	 */
	protected function reject_unknown_body_params( WP_REST_Request $request, array $extra_allowed = array() ): ?WP_Error {
		$json = $request->get_json_params();
		$body = is_array( $json ) && ! empty( $json ) ? $json : (array) $request->get_body_params();

		if ( empty( $body ) ) {
			return null;
		}

		$attributes = $request->get_attributes();
		$allowed    = array_merge(
			array_keys( (array) ( $attributes['args'] ?? array() ) ),
			$extra_allowed,
			self::RESERVED_PARAMS
		);

		$unknown = array_values( array_diff( array_keys( $body ), $allowed ) );

		if ( empty( $unknown ) ) {
			return null;
		}

		return new WP_Error(
			'bn_unknown_params',
			sprintf(
				/* translators: %s: comma-separated list of unrecognised parameter names. */
				__( 'Unrecognised parameters: %s. Nothing was saved.', 'buddynext' ),
				implode( ', ', $unknown )
			),
			array(
				'status' => 400,
				'params' => $unknown,
			)
		);
	}

	/**
	 * Require an authenticated user.
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
	 * Require a user who can manage the community.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do that.', 'buddynext' ),
				array( 'status' => current_user_can( 'read' ) ? 403 : 401 )
			);
		}

		return true;
	}

	/**
	 * Require a community moderator (currently site managers).
	 *
	 * Distinct method name so moderator-gated routes read clearly and can gain
	 * space-moderator semantics later without touching every controller.
	 *
	 * @return true|WP_Error
	 */
	public function require_moderator(): bool|WP_Error {
		return $this->require_admin();
	}

	/**
	 * Require the current user to hold a BuddyNext capability (role map).
	 *
	 * Resolves through buddynext_can() so the Roles & Capabilities admin tab
	 * actually governs the action. Site admins (manage_options) always pass.
	 * Call at the top of a handler — login is assumed already enforced by the
	 * route's permission_callback (require_auth).
	 *
	 * @param string               $capability Capability slug (e.g. 'buddynext-feed/create-post').
	 * @param array<string, mixed> $context    Optional context (e.g. space_id) for contextual caps.
	 * @return true|WP_Error
	 */
	protected function require_cap( string $capability, array $context = array() ): bool|WP_Error {
		$user_id = get_current_user_id();

		if ( buddynext_can( $user_id, $capability, $context ) ) {
			return true;
		}

		$error = new WP_Error(
			'rest_forbidden',
			__( 'Your role does not permit this action.', 'buddynext' ),
			array( 'status' => current_user_can( 'read' ) ? 403 : 401 )
		);

		/**
		 * Filter the WP_Error returned when a capability check denies a REST action.
		 *
		 * Free's default message attributes the refusal to the member's ROLE, which is
		 * the only reason Free itself can deny for. A listener that denies the same
		 * capability for a different reason must be able to say so — Pro answers
		 * `buddynext_user_can` with the member's plan entitlements, and leaving the
		 * default message would tell a member whose PLAN withheld a feature that their
		 * role was at fault, sending them to the wrong place to fix it.
		 *
		 * Listeners MUST return a WP_Error.
		 *
		 * @since 1.0.8
		 *
		 * @param WP_Error             $error      The denial error.
		 * @param string               $capability Capability slug that was denied.
		 * @param int                  $user_id    The member denied.
		 * @param array<string, mixed> $context    Capability context (e.g. space_id).
		 */
		return apply_filters( 'buddynext_capability_denied_error', $error, $capability, $user_id, $context );
	}

	/**
	 * Hydrate a page of member IDs into enriched member cards when the caller asks
	 * for it via expand=members.
	 *
	 * Lets a social-graph list endpoint (followers / following / connections /
	 * mutual) return the same enriched member objects the /members directory does,
	 * in ONE batched request, so a client never has to fetch one profile per row.
	 * Returns null when expansion was not requested, so the endpoint keeps its
	 * ids-only default and existing consumers are unaffected.
	 *
	 * @param WP_REST_Request $request   Incoming request (reads the `expand` param).
	 * @param int[]           $ids       Member IDs for this page, in output order.
	 * @param int             $viewer_id Current viewer (0 = logged out).
	 * @return array<int, array<string, mixed>>|null Enriched members, or null when not expanded.
	 */
	protected function maybe_expand_members( WP_REST_Request $request, array $ids, int $viewer_id ): ?array {
		$expand = $request->get_param( 'expand' );
		$tokens = is_string( $expand ) ? array_map( 'trim', explode( ',', $expand ) ) : array();
		if ( ! in_array( 'members', $tokens, true ) ) {
			return null;
		}
		return ( new \BuddyNext\Profile\MemberDirectoryController() )->hydrate_members( $ids, $viewer_id );
	}
}
