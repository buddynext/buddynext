<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Onboarding listener.
 *
 * Schedules and handles post-registration nudge emails. A 24-hour and a
 * 72-hour Action Scheduler single action are scheduled for every new user at
 * registration time (Action Scheduler keeps its own indexed store, so this
 * scales past the autoloaded WP-Cron option). Both are cancelled when the user
 * completes the onboarding flow, and the shared handler skips users who have
 * already finished onboarding.
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers onboarding nudge hooks and routes them to the email system.
 */
class OnboardingListener implements ListenerInterface {

	/**
	 * Register all onboarding event hook listeners.
	 *
	 * Called once during Plugin::init(), after the service container is
	 * bootstrapped, so buddynext_service() is available to every handler.
	 */
	public function register(): void {
		add_action( 'user_register', array( $this, 'reconcile_invites_on_register' ), 16, 1 );
		// Schedule the two onboarding nudges the moment the member registers, each as
		// an Action Scheduler single action due at its offset (card 10264295353).
		// Action Scheduler stores them in its own indexed tables, so 200k signups do
		// NOT grow the autoloaded WP-Cron option the way the retired per-user
		// wp_schedule_single_event() calls did.
		add_action( 'user_register', array( $this, 'enqueue_nudges' ), 17, 1 );
		add_action( 'buddynext_onboarding_completed', array( $this, 'on_onboarding_completed_cancel_nudges' ), 10, 1 );
		// The per-user nudge hooks. Action Scheduler fires these with array( $user_id );
		// pre-1.2.0 WP-Cron single events fire the same hooks until they age out.
		add_action( 'bn_onboarding_nudge_24h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
		add_action( 'bn_onboarding_nudge_72h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_to_onboarding' ), 5 );
		add_action( 'buddynext_async_send_invite_email', array( $this, 'handle_async_invite_email' ), 10, 1 );
	}

	/**
	 * Action Scheduler group shared with the rest of BuddyNext's jobs.
	 */
	private const NUDGE_GROUP = 'buddynext';

	/**
	 * The two per-user nudge hooks, keyed by delay from registration.
	 *
	 * @return array<string,int> hook => seconds after registration it is due.
	 */
	private function nudge_hooks(): array {
		return array(
			'bn_onboarding_nudge_24h' => DAY_IN_SECONDS,
			'bn_onboarding_nudge_72h' => 3 * DAY_IN_SECONDS,
		);
	}

	/**
	 * Schedule a member's two onboarding nudges (24h, 72h) at registration.
	 *
	 * Each becomes an Action Scheduler single action carrying array( $user_id ),
	 * deduped against a pending action so a repeated user_register never
	 * double-schedules. Action Scheduler owns the timing, batching and its own
	 * indexed store — there is no BuddyNext queue table and no sweep. Card
	 * 10264295353.
	 *
	 * @param int $user_id Newly-registered user.
	 * @return void
	 */
	public function enqueue_nudges( int $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		// Action Scheduler no-ops (with a _doing_it_wrong) when a single action is
		// scheduled BEFORE it boots on init. A user created that early — an importer
		// or SSO plugin registering users on plugins_loaded — would otherwise get no
		// nudges at all (the retired wp_schedule_single_event worked at any hook
		// depth). Defer to action_scheduler_init when AS has not booted yet; in the
		// normal case (registration well after init) it has, so schedule immediately.
		//
		// SCOPE, so the next reader does not over-trust this defer: it only rescues a
		// signup that lands after register() has attached this user_register listener
		// (Plugin::init(), on the `init` hook) but before AS finishes booting — i.e.
		// roughly plugins_loaded:15 -> init:1. A user created EARLIER than register()
		// runs (muplugins_loaded, or an early plugins_loaded before this plugin inits)
		// never fires enqueue_nudges() at all, so it gets no nudge. That is the
		// owner-accepted ceiling of the two-single-actions-per-signup design (card
		// 10264295353); widen it only by registering user_register earlier, not here.
		if ( did_action( 'action_scheduler_init' ) ) {
			$this->schedule_nudges( $user_id );
			return;
		}
		add_action(
			'action_scheduler_init',
			function () use ( $user_id ) {
				$this->schedule_nudges( $user_id );
			}
		);
	}

	/**
	 * Schedule the two per-user nudge actions, deduped against a pending action.
	 *
	 * Split from enqueue_nudges() so it can run either immediately or deferred to
	 * action_scheduler_init without duplicating the scheduling logic.
	 *
	 * @param int $user_id Newly-registered user.
	 * @return void
	 */
	private function schedule_nudges( int $user_id ): void {
		$args = array( $user_id );
		foreach ( $this->nudge_hooks() as $hook => $offset ) {
			if ( function_exists( 'as_next_scheduled_action' )
				&& false !== as_next_scheduled_action( $hook, $args, self::NUDGE_GROUP ) ) {
				continue; // Already pending for this member.
			}
			as_schedule_single_action( time() + $offset, $hook, $args, self::NUDGE_GROUP );
		}
	}

	/**
	 * Action Scheduler callback: send a deferred invite email.
	 *
	 * InviteService::create() enqueues this (one per invite) so a bulk/CSV import
	 * never blocks the request on a loop of synchronous wp_mail() calls.
	 *
	 * @param mixed $invite Invite payload { id, email, first_name, token }.
	 * @return void
	 */
	public function handle_async_invite_email( $invite ): void {
		if ( is_array( $invite ) ) {
			( new InviteService() )->deliver_invite_email( $invite );
		}
	}

	/**
	 * Send un-onboarded members to the welcome wizard on their next front-end view.
	 *
	 * The self-registration flow redirects to the wizard directly, but
	 * admin-created members, the email-verify flow, and ordinary logins never
	 * pass through it. This front-end gate is the canonical trigger: when the
	 * `onboarding` feature is enabled (FeatureRegistry — the authoritative
	 * toggle) and a logged-in member has not yet finished (or skipped) the
	 * wizard, the first non-onboarding front-end page view is redirected to it.
	 *
	 * Skipping the wizard marks it complete (OnboardingService::skip), so a
	 * dismissed wizard never loops back here.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_onboarding(): void {
		// Front-end GET views only — never admin, AJAX, REST, cron, or feeds.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		// Canonical on/off gate. Prefer the FeatureRegistry flag over the
		// legacy buddynext_show_onboarding option.
		if ( ! buddynext_service( 'features' )->is_enabled( 'onboarding' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( buddynext_service( 'onboarding' )->is_complete( $user_id ) ) {
			return;
		}

		// Grandfather the existing community. The wizard is for *new* members; it
		// must never retroactively trap members who were already on the site when
		// onboarding was switched on (admin, demo accounts, the whole back catalog
		// of registrations). We record the moment the gate first becomes live and
		// only redirect members who registered at or after it. Anyone older is
		// treated as already settled in.
		$gate_since = (int) get_option( 'buddynext_onboarding_gate_since', 0 );
		if ( 0 === $gate_since ) {
			$gate_since = time();
			// Autoload so the very next request sees it without a fresh DB read.
			update_option( 'buddynext_onboarding_gate_since', $gate_since );
		}

		$user_obj   = get_userdata( $user_id );
		$registered = $user_obj ? (int) strtotime( (string) $user_obj->user_registered . ' UTC' ) : 0;
		if ( $registered > 0 && $registered < $gate_since ) {
			return;
		}

		// Never redirect when the member is already on the onboarding wizard or
		// inside the auth flow (login / signup / email verify), to avoid loops.
		$hub = (string) get_query_var( 'bn_hub', '' );
		if ( in_array( $hub, array( 'onboarding', 'auth' ), true ) ) {
			return;
		}

		// Yield to a pending 2FA enrolment hold.
		//
		// TwoFactorService::enforce_enrolment() (template_redirect:7) holds a
		// member whose role requires 2FA on the settings screen until they enrol,
		// and exempts /settings so it does not loop on its own destination. This
		// gate runs first (priority 5) and used to redirect that very destination
		// to the wizard, so the two bounced forever: /settings -> /onboarding ->
		// /settings, and the member saw a dead redirect loop instead of either
		// screen. Each gate was individually loop-safe; together they were not.
		//
		// A gate must never hijack another gate's destination, and a security hold
		// outranks a welcome wizard - so onboarding stands down entirely while the
		// hold is live. Once 2FA is enrolled the hold clears and this gate resumes
		// on the next request, so the member still sees the wizard, just after the
		// thing that was actually blocking them.
		$user_obj_2fa = wp_get_current_user();
		if ( $user_obj_2fa instanceof \WP_User
			&& \BuddyNext\Auth\TwoFactorService::is_required_for( $user_obj_2fa )
			&& ! \BuddyNext\Auth\TwoFactorService::is_enabled( (int) $user_obj_2fa->ID )
		) {
			return;
		}

		$onboarding_url = \BuddyNext\Core\PageRouter::onboarding_url();

		// Loop guard for the edge case where the onboarding hub is the site's
		// static front page (bn_hub may be empty until dispatch resolves it):
		// bail when the current request path already matches the wizard path.
		$current_path    = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$onboarding_path = wp_parse_url( $onboarding_url, PHP_URL_PATH );
		if ( is_string( $current_path ) && is_string( $onboarding_path ) && untrailingslashit( $current_path ) === untrailingslashit( $onboarding_path ) ) {
			return;
		}

		/**
		 * Filter whether the current request should be redirected to onboarding.
		 *
		 * Returning false lets a specific route opt out of the welcome wizard
		 * gate without disabling the feature globally.
		 *
		 * @param bool $should_redirect Whether to redirect to the wizard.
		 * @param int  $user_id         The logged-in member's user ID.
		 * @param string $hub           The active bn_hub for this request.
		 */
		if ( ! (bool) apply_filters( 'buddynext_onboarding_should_redirect', true, $user_id, $hub ) ) {
			return;
		}

		wp_safe_redirect( $onboarding_url );
		exit;
	}

	/**
	 * Reconcile any pending email invitation when an invited address registers.
	 *
	 * Catches the registration paths that don't carry the invite token (direct
	 * sign-up, admin-created account, social login) — the token path already flips
	 * its specific invite in AuthController. Delegates to InviteService, which keys on
	 * the indexed email column. See InviteService::mark_registered_by_email().
	 *
	 * @param int $user_id Newly registered user ID.
	 * @return void
	 */
	public function reconcile_invites_on_register( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( $user && '' !== (string) $user->user_email ) {
			( new InviteService() )->mark_registered_by_email( (string) $user->user_email );
		}
	}

	/**
	 * Cancel pending nudge emails when a user completes onboarding.
	 *
	 * @param int $user_id User who completed onboarding.
	 */
	public function on_onboarding_completed_cancel_nudges( int $user_id ): void {
		$args = array( (int) $user_id );
		foreach ( array_keys( $this->nudge_hooks() ) as $hook ) {
			// Pre-1.2.0 WP-Cron single events, until they age out.
			wp_clear_scheduled_hook( $hook, $args );
			// The Action Scheduler single actions scheduled at registration — they
			// would no-op now that the member has onboarded, so drop them.
			if ( function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( $hook, $args, self::NUDGE_GROUP );
			}
		}
	}

	/**
	 * Send an onboarding nudge email if the user has not yet completed onboarding.
	 *
	 * Shared handler for both the 24h and 72h nudge cron hooks. Bails early
	 * when the user has already finished onboarding so no duplicate emails are sent.
	 *
	 * @param int $user_id User ID to nudge.
	 */
	public function handle_onboarding_nudge( int $user_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( buddynext_service( 'onboarding' )->is_complete( $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		buddynext_service( 'email_sender' )->send(
			$user_id,
			'bn.onboarding_nudge',
			array(
				'recipient_name' => $user->display_name,
				'onboarding_url' => \BuddyNext\Core\PageRouter::onboarding_url(),
			)
		);
	}
}
