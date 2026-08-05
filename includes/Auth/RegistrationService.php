<?php
/**
 * Registration creation pipeline — the one way a BuddyNext member is created.
 *
 * Doors must not create the account themselves. Social login used to, and as
 * a direct result it skipped the default DM-privacy seed, the registration
 * profile fields, and the canonical registration hooks — silently, for every
 * member who signed up with Google. Centralising creation is what stops a door
 * from forgetting a step nobody remembered it owned.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Onboarding\InviteService;
use BuddyNext\Spaces\SpaceMemberService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a member and runs every post-create step exactly once.
 */
class RegistrationService {

	/**
	 * A free, human-ish username derived from an email address.
	 *
	 * Shared by every door. Social signup has always used this — the member never sees a
	 * username field and never has to invent one — while the email form made them think one
	 * up, to rules ("3-24 characters: letters, numbers, underscore"), and let them fail on
	 * "already taken". The same plugin asked more of the slower path and captured less. This
	 * is the capability that closes that gap; it was here all along, just locked inside
	 * SocialLogin.
	 *
	 * One query, not N. The naive `while ( username_exists( $login ) )` costs a query per
	 * collision, and corporate domains collide hard on the same local part (info@, admin@,
	 * contact@, hello@) — so a community with N members called info* paid N queries on the
	 * next info@ signup.
	 *
	 * @since 1.0.8
	 *
	 * @param string $email Email address to derive from.
	 * @return string A username not currently in use.
	 */
	public static function unique_login( string $email ): string {
		global $wpdb;

		$base = sanitize_user( current( explode( '@', $email ) ), true );
		$base = '' !== $base ? $base : 'member';

		if ( ! username_exists( $base ) ) {
			return $base;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$taken = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE %s",
				$wpdb->esc_like( $base ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$taken = array_flip( array_map( 'strval', (array) $taken ) );

		$n = 2;
		while ( isset( $taken[ $base . $n ] ) ) {
			++$n;
		}

		return $base . $n;
	}

	/**
	 * Create a member from validated signup data.
	 *
	 * Callers MUST have already cleared RegistrationPolicy::check_access(). The owner's
	 * remaining requirements (terms consent, required profile fields) are enforced HERE, at
	 * the door, so no caller can skip them.
	 *
	 * @param array<string,mixed> $data Signup data. Required: email, password. Optional:
	 *                                  name (display name), user_login (derived from the
	 *                                  email when absent), terms_agreed, invite (token),
	 *                                  bn_field_* values, and social =>
	 *                                  array{provider:string,uid:string,email_verified:bool}.
	 * @return int|WP_Error New user id, or WP_Error on failure.
	 */
	public function create( array $data ): int|WP_Error {
		$policy = new RegistrationPolicy();

		// A member should not have to invent a username to join a community.
		//
		// Facebook and LinkedIn ask for your NAME — the thing other humans see — and have
		// never once asked you to think up a handle. We did the inverse: demanded an
		// invented identity before the member had seen a single post, and never learned what
		// they were called. So members appeared to each other as @jsmith, while anyone who
		// happened to sign up with GitHub got "John Smith", because the social path already
		// derived the username silently and captured the name from the provider.
		//
		// Now every door behaves like the good one. When no username is supplied we derive
		// it; the owner can still ask for one explicitly (buddynext_reg_ask_username) if
		// their community wants handles chosen at the door.
		if ( '' === trim( (string) ( $data['user_login'] ?? '' ) ) ) {
			$data['user_login'] = self::unique_login( (string) ( $data['email'] ?? '' ) );
		}

		// The owner's requirements bind HERE, at the door itself — not only in the two
		// callers that happen to remember to ask.
		//
		// validate_data() checked the profile FIELDS and said nothing about terms consent,
		// so this method would happily mint an account for a payload with no `terms_agreed`
		// at all. The two real doors (the REST form, and social's park-then-finish) each
		// validate consent themselves, so nothing was exploitable through them — but the
		// shared service beneath them did not enforce the policy it exists to centralise,
		// and the next caller to arrive would have inherited the hole rather than the rule.
		// A lever that reads but does not enforce is worse than no lever.
		$missing = $policy->missing( $data );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'bn_reg_incomplete',
				__( 'Some required information is missing.', 'buddynext' ),
				array(
					'status'  => 422,
					'missing' => $missing,
				)
			);
		}

		$values = $policy->validate_data( $data );
		if ( is_wp_error( $values ) ) {
			return $values;
		}

		// RESOLVE THE INVITE BEFORE THE ACCOUNT EXISTS.
		//
		// Inserting the user fires `user_register`, and OnboardingListener hooks it at
		// priority 16 to reconcile invites BY EMAIL — flipping the invitee's invite to
		// `registered`. redeem_invite() then looked the token up with
		// InviteService::get_by_token(), whose SQL requires status = 'pending', got
		// null, and returned silently WITHOUT joining the member to the space their
		// invitation named.
		//
		// So a legitimate invitee joined the community but never landed in the space
		// they were invited to — and it was invisible, because the invite still ended
		// up `registered`, just written by the wrong party.
		//
		// Read it here, while it is still pending. Nothing downstream can race us.
		$invite = $this->find_invite( $data );

		// The name is what a community actually shows. Set it when we were given one; the
		// social path already did this from the provider profile, and the email path did
		// not, so the same member looked like a person or like a handle depending on which
		// button they happened to click.
		//
		// It goes in with the INSERT, not in an update afterwards. wp_create_user()
		// takes only login/password/email, so core defaulted display_name to the
		// username and fired `user_register` with that in place; the real name landed
		// a moment later. Anything listening to user_register therefore saw the
		// handle - which is why the verification email greeted "Hi qatest," while
		// the welcome email, sent later, correctly greeted "Hi QA123,".
		//
		// Fixing the send order would have fixed one email. Setting the name before
		// the account is announced fixes it for every listener, including the ones
		// nobody has written yet.
		//
		// wp_insert_user() is what wp_create_user() calls; it expects slashed data,
		// which is the slashing wp_create_user() was doing for us.
		$name = trim( (string) ( $data['name'] ?? '' ) );

		$userdata = array(
			'user_login' => wp_slash( (string) $data['user_login'] ),
			'user_pass'  => (string) $data['password'],
			'user_email' => wp_slash( (string) $data['email'] ),
		);

		if ( '' !== $name ) {
			$userdata['display_name'] = wp_slash( sanitize_text_field( $name ) );
		}

		$user_id = wp_insert_user( $userdata );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user_id = (int) $user_id;

		$this->redeem_invite( $user_id, $invite );

		// Reuse the canonical seeder rather than duplicating the audience list.
		AuthController::seed_default_dm_access( $user_id );

		$this->record_terms_consent( $user_id, $data, $policy );
		$this->save_fields( $user_id, $values, $policy );
		$this->link_social( $user_id, $data );
		$this->maybe_hold_for_approval( $user_id, (string) $data['email'] );

		return $user_id;
	}

	/**
	 * Record that this member accepted the terms, and which terms they accepted.
	 *
	 * Consent was ENFORCED and never WRITTEN DOWN. RegistrationPolicy refuses to register
	 * anyone without `terms_agreed` — the gate works, and a social signup that cannot
	 * supply consent is correctly parked and finished on a real form. But nothing then
	 * stored the fact. The owner had a consent gate with no consent record: nothing to show
	 * if a member disputes it, and no way to tell who accepted WHICH version once the terms
	 * change and everyone needs re-prompting.
	 *
	 * It also made the flow unauditable in the plainest sense — asked whether a specific
	 * member had been shown the terms, the honest answer was "we have no idea", because
	 * there was nothing in the database that could say either way.
	 *
	 * Recorded HERE because create() is the single door every registration passes through
	 * — the web form, the social completion form, and the native app all end up here — so
	 * consent cannot be recorded on one path and quietly missed on another.
	 *
	 * The terms PAGE ID and its last-modified time are stored alongside the timestamp: "they
	 * agreed" is worth little without "to what". If the owner edits the terms page, the
	 * stored modified-time no longer matches the live one, and a site that needs to
	 * re-prompt can find exactly who is stale.
	 *
	 * @param int                 $user_id New user id.
	 * @param array<string,mixed> $data    Signup data (carries terms_agreed).
	 * @param RegistrationPolicy  $policy  Policy (tells us whether terms were required).
	 * @return void
	 */
	private function record_terms_consent( int $user_id, array $data, RegistrationPolicy $policy ): void {
		$required = ! empty( $policy->requirements()['terms'] );

		// Nothing was asked of them, so there is nothing to record. Writing "consented" for
		// a member who was never shown a terms checkbox would be a worse lie than silence.
		if ( ! $required || empty( $data['terms_agreed'] ) ) {
			return;
		}

		$terms_page = (int) get_option( 'buddynext_terms_page_id', 0 );

		update_user_meta( $user_id, 'bn_terms_accepted_at', gmdate( 'Y-m-d H:i:s' ) );
		update_user_meta( $user_id, 'bn_terms_page_id', $terms_page );

		if ( $terms_page > 0 ) {
			$modified = get_post_field( 'post_modified_gmt', $terms_page );
			if ( is_string( $modified ) && '' !== $modified ) {
				update_user_meta( $user_id, 'bn_terms_version', $modified );
			}
		}

		/**
		 * Fires when a member's terms consent is recorded.
		 *
		 * A site with a stricter compliance duty (a written audit log, an external consent
		 * store) can listen here rather than re-deriving consent from registration.
		 *
		 * @since 1.0.8
		 *
		 * @param int    $user_id    Member who consented.
		 * @param int    $terms_page Terms page id (0 when the owner set none).
		 * @param string $source     Registration source, e.g. 'social' or 'form'.
		 */
		do_action(
			'buddynext_terms_consent_recorded',
			$user_id,
			$terms_page,
			(string) ( $data['source'] ?? '' )
		);
	}

	/**
	 * Resolve the presented invitation, if any.
	 *
	 * Called BEFORE the account is inserted — see create(). Once the account exists, the
	 * `user_register` email-reconcile listener has already flipped the row out of
	 * `pending` and this lookup would find nothing.
	 *
	 * @param array<string,mixed> $data Signup data.
	 * @return array<string,mixed>|null Invite row, or null when none was presented.
	 */
	private function find_invite( array $data ): ?array {
		$token = (string) ( $data['invite'] ?? '' );
		if ( '' === $token ) {
			return null;
		}

		return ( new InviteService() )->get_by_token( $token );
	}

	/**
	 * Spend the invitation: mark it registered, and join the member to the space it names.
	 *
	 * Takes the invite row RESOLVED BEFORE the account existed (see create()), because
	 * `user_register` fires an email-reconcile listener that flips the row to
	 * `registered` — so re-reading it here by token would find nothing.
	 *
	 * @param int                      $user_id New member id.
	 * @param array<string,mixed>|null $invite  Invite row captured before the account was inserted, or null.
	 * @return void
	 */
	private function redeem_invite( int $user_id, ?array $invite ): void {
		if ( null === $invite ) {
			return;
		}

		$invites = new InviteService();

		// BIND THE INVITE HERE TOO, not only in RegistrationPolicy.
		//
		// The policy gate only inspects the invite when the site is in `invite`
		// mode. This method runs in EVERY mode — so on an OPEN-registration site a
		// stranger holding a leaked space-scoped token was silently joined to the
		// space it points at, which may be PRIVATE. That is a privacy breach with
		// no invite-only mode involved, and the policy gate cannot see it.
		//
		// Checking the redeemed identity against the invited address closes both
		// doors from one place: the invite is spent only by the person it names.
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! $invites->is_for_email( $invite, (string) $user->user_email ) ) {
			return;
		}

		$invites->mark_registered( (int) $invite['id'] );

		if ( ! empty( $invite['space_id'] ) ) {
			$joined = ( new SpaceMemberService() )->join( (int) $invite['space_id'], $user_id );

			// Do not swallow the failure. This return value used to be discarded, which
			// is why a member silently failing to land in the space they were invited to
			// produced no error anywhere for an entire release.
			if ( is_wp_error( $joined ) ) {
				do_action( 'buddynext_invite_space_join_failed', $user_id, (int) $invite['space_id'], $joined );
			}
		}
	}

	/**
	 * Persist the custom registration field values onto the new member.
	 *
	 * @param int                 $user_id New user id.
	 * @param array<string,mixed> $values  Clean values keyed by field_key.
	 * @param RegistrationPolicy  $policy  Policy, for the field definitions.
	 * @return void
	 */
	private function save_fields( int $user_id, array $values, RegistrationPolicy $policy ): void {
		if ( empty( $values ) ) {
			return;
		}

		// Reuse the canonical writer: it splits DB-backed fields (bn_profile_values
		// + the searchable usermeta mirror) from virtual/programmatic ones (which
		// have no row and go straight to bn_field_{key} usermeta), and it fires
		// buddynext_registration_fields_saved. Reimplementing it here would have
		// silently dropped every code-registered field.
		AuthController::save_registration_fields(
			$user_id,
			$policy->requirements()['fields'],
			$values,
			buddynext_service( 'profiles' )
		);
	}

	/**
	 * Record the provider link for a social signup.
	 *
	 * Written here, at the end of a COMPLETED signup — never mid-flight. Writing
	 * it early is what let a visitor defeat admin-approval mode by clicking the
	 * social button twice: the link persisted, so the second attempt matched an
	 * existing owner and sailed past the approval branch entirely.
	 *
	 * @param int                 $user_id New user id.
	 * @param array<string,mixed> $data    Signup data.
	 * @return void
	 */
	private function link_social( int $user_id, array $data ): void {
		$social = $data['social'] ?? null;
		if ( ! is_array( $social ) || empty( $social['provider'] ) || empty( $social['uid'] ) ) {
			return;
		}

		// Writes BOTH the readable meta and the indexed lookup key. Writing the meta
		// directly here would create an account that every future sign-in has to
		// find by scanning, because it would carry no indexed key.
		SocialLogin::link_identity(
			$user_id,
			(string) $social['provider'],
			(string) $social['uid']
		);

		// A provider-verified email satisfies BuddyNext's own email check. Use the
		// canonical key VerificationService reads/writes.
		if ( ! empty( $social['email_verified'] ) ) {
			update_user_meta( $user_id, 'buddynext_email_verified', 1 );
		}
	}

	/**
	 * Flag the member for admin approval when the owner requires it.
	 *
	 * The flag is all this does. Enforcement lives on the core authenticate
	 * chain (Plugin.php) and is applied by SessionIssuer, so no door can hand out
	 * a session to a member the owner has not approved.
	 *
	 * @param int    $user_id New user id.
	 * @param string $email   Member email, for the notification listeners.
	 * @return void
	 */
	private function maybe_hold_for_approval( int $user_id, string $email ): void {
		$mode = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );
		if ( 'approval' !== $mode ) {
			return;
		}

		update_user_meta( $user_id, 'bn_pending_approval', '1' );

		/** This action is documented in AuthController::register(). */
		do_action( 'buddynext_registration_pending', $user_id, $email );
	}
}
