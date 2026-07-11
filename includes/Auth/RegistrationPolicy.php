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

		return array(
			'terms'     => (bool) get_option( 'buddynext_require_terms', true ),
			'terms_url' => $terms_page > 0 ? (string) get_permalink( $terms_page ) : '',
			'mode'      => (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() ),
			'fields'    => buddynext_service( 'profiles' )->get_registration_fields(),
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

			if ( '' === $value || null === $value || array() === $value ) {
				$missing[] = $key;
			}
		}

		return $missing;
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
