<?php
/**
 * Shared registration policy — the single decision point for every signup door.
 *
 * BuddyNext has four ways into the community: the BuddyNext signup form, its
 * REST endpoint (which the native app uses), social login, and — when the owner
 * allows it — the WordPress core registration form. Historically only the first
 * two knew the owner's registration policy existed, so a configured allowlist,
 * a required profile field, or a terms gate simply did not apply to the others.
 *
 * This class is that policy, in one place, so a door cannot forget it.
 *
 * PURE: it never creates a user, never writes an option, never sets a cookie.
 * Creation lives in RegistrationService; sessions live in SessionIssuer.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Onboarding\InviteService;
use BuddyNext\Profile\FieldType;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a signup may proceed, and what data it still owes.
 */
class RegistrationPolicy {

	/**
	 * The BuddyNext signup form (renders guard tokens).
	 */
	public const SOURCE_FORM = 'form';

	/**
	 * A native app via REST (fetches guard tokens from /auth/register/config).
	 */
	public const SOURCE_APP = 'app';

	/**
	 * Social login — no form, therefore no honeypot / time-trap / human check.
	 */
	public const SOURCE_SOCIAL = 'social';

	/**
	 * The WordPress core registration form at wp-login.php.
	 */
	public const SOURCE_CORE = 'core';

	/**
	 * Sources that render a BuddyNext form and can therefore carry guard tokens.
	 *
	 * @var string[]
	 */
	public const FORM_SOURCES = array( self::SOURCE_FORM, self::SOURCE_APP );

	/**
	 * May a signup for this email proceed at all?
	 *
	 * Runs for EVERY source. This is the owner's access policy: registration
	 * mode, the invite requirement, and the allowed-domain allowlist. None of
	 * these are spam heuristics — they are decisions the owner made, and they
	 * bind on every door equally.
	 *
	 * @param string      $email        Candidate email address.
	 * @param string|null $invite_token Invite token, when one was presented.
	 * @param string      $source       One of the SOURCE_* constants.
	 * @return true|WP_Error True to proceed, WP_Error with a user-safe message.
	 */
	public function check_access( string $email, ?string $invite_token, string $source ): bool|WP_Error {
		unset( $source ); // Reserved: every source is currently gated identically.

		$mode = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );

		if ( 'closed' === $mode || ! (bool) get_option( 'users_can_register', false ) ) {
			return new WP_Error(
				'bn_reg_closed',
				__( 'Registration is closed on this community.', 'buddynext' )
			);
		}

		if ( 'invite' === $mode ) {
			$invite = ( null !== $invite_token && '' !== $invite_token )
				? ( new InviteService() )->get_by_token( $invite_token )
				: null;

			if ( ! $invite ) {
				return new WP_Error(
					'bn_reg_invite',
					__( 'This community is invite-only. You need an invitation to join.', 'buddynext' )
				);
			}
		}

		return ( new RegistrationGuard() )->check_domain( $email );
	}

	/**
	 * The signup contract: everything a door must collect before creating a member.
	 *
	 * This is the same payload served by GET /auth/register/config, so a native
	 * app learns exactly the contract the web form renders — rather than guessing
	 * and being rejected as a bot.
	 *
	 * @return array{terms:bool,terms_url:string,mode:string,fields:array<int,array<string,mixed>>}
	 */
	public function requirements(): array {
		$terms_page = (int) get_option( 'buddynext_terms_page_id', 0 );

		// A TERMS GATE WITH NO TERMS IS NOT A GATE.
		//
		// `buddynext_require_terms` defaults ON and `buddynext_terms_page_id` defaults to 0,
		// so on a FRESH INSTALL — the default state of every new site — every member was
		// made to tick "I agree to the Terms of Service" where the Terms of Service was a
		// link to nothing. The template renders the checkbox with no href at all when no
		// page is set. We were demanding consent to a document that did not exist: legally
		// worthless, and insulting to the member being asked.
		//
		// So the requirement binds only when there is something to agree TO. This is the
		// same rule this wave already settled for monetization — the entitlement gates bind
		// only when a default plan exists, because an unconfigured system must not take
		// anything away. An unconfigured terms gate must not either.
		//
		// The owner is not left guessing: TermsNotice puts an admin notice on screen when
		// consent is switched on with no page behind it, so "we are not enforcing this" is
		// stated out loud rather than discovered.
		$require_terms = (bool) get_option( 'buddynext_require_terms', true ) && $terms_page > 0;

		return array(
			'terms'        => $require_terms,
			'terms_url'    => $terms_page > 0 ? (string) get_permalink( $terms_page ) : '',
			'mode'         => (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() ),
			'fields'       => buddynext_service( 'profiles' )->get_registration_fields(),

			// ── What the signup form asks for ────────────────────────────────────────
			//
			// Two owner levers, and deliberately only two. This is a plugin for a site
			// owner, not a SaaS: the owner decides what their front door asks, and the
			// member is never offered a choice that fragments the product.
			//
			// The defaults are the fast door, because the member's goal is to get INTO the
			// community, not to fill in a form:
			//
			// ask_name     ON  — a community shows people's names. Facebook and LinkedIn
			// ask for this and nothing else identity-shaped.
			// ask_username OFF — nobody should have to invent a handle to join. It is
			// derived from the email, exactly as social signup already
			// does, and can be changed later in settings.
			//
			// An owner who wants the classic WordPress-shaped form (pick a username, no
			// name) turns one off and the other on. Anything BEYOND these two — extra
			// fields the owner's community actually needs — goes through the profile-field
			// system's `show_on_register`, which already works and is where that
			// flexibility belongs. We are not building a second form builder next to it.
			'ask_name'     => (bool) get_option( 'buddynext_reg_ask_name', true ),
			'ask_username' => (bool) get_option( 'buddynext_reg_ask_username', false ),
		);
	}

	/**
	 * Which requirements are still unmet by the supplied data?
	 *
	 * Returns keys, not errors. A door then decides what to do about them: a form
	 * that should have collected them rejects the submission, while social login
	 * — which cannot obtain terms consent or a custom field from an OAuth
	 * provider — parks a pending signup and finishes on a real form.
	 *
	 * @param array<string,mixed> $data Submitted signup data.
	 * @return string[] Unmet requirement keys, e.g. array( 'terms', 'bn_field_company' ).
	 */
	public function missing( array $data ): array {
		$missing = array();
		$req     = $this->requirements();

		if ( $req['terms'] && empty( $data['terms_agreed'] ) ) {
			$missing[] = 'terms';
		}

		foreach ( $req['fields'] as $field ) {
			if ( empty( $field['is_required'] ) ) {
				continue;
			}

			$key   = 'bn_field_' . (string) $field['field_key'];
			$value = $data[ $key ] ?? '';

			// No null branch: the ?? above already collapses a missing key to ''.
			if ( '' === $value || array() === $value ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * The human label for a requirement key returned by missing().
	 *
	 * Lets a door render "Company is required." rather than leaking the internal
	 * bn_field_company key at the member.
	 *
	 * @param string $requirement Requirement key, e.g. 'bn_field_company'.
	 * @return string Field label, or the key itself when it has no definition.
	 */
	public function label_for( string $requirement ): string {
		$field_key = str_starts_with( $requirement, 'bn_field_' )
			? substr( $requirement, strlen( 'bn_field_' ) )
			: $requirement;

		foreach ( $this->requirements()['fields'] as $field ) {
			if ( (string) $field['field_key'] === $field_key ) {
				return (string) $field['label'];
			}
		}

		return $requirement;
	}

	/**
	 * Sanitize and validate the custom registration fields.
	 *
	 * @param array<string,mixed> $data Submitted signup data (bn_field_* keys).
	 * @return array<string,mixed>|WP_Error Clean values keyed by field_key, or a
	 *                                      WP_Error whose data carries a `fields`
	 *                                      map for inline display.
	 */
	public function validate_data( array $data ): array|WP_Error {
		$values = array();
		$errors = array();

		foreach ( $this->requirements()['fields'] as $field ) {
			$key = (string) $field['field_key'];
			$raw = $data[ 'bn_field_' . $key ] ?? '';

			$value = FieldType::sanitize( $field, $raw );
			if ( is_wp_error( $value ) ) {
				$errors[ 'bn_field_' . $key ] = $value->get_error_message();
				continue;
			}

			$values[ $key ] = $value;
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'bn_reg_invalid_fields',
				__( 'Please correct the highlighted fields.', 'buddynext' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}

		return $values;
	}
}
