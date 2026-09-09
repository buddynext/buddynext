<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Onboarding listener.
 *
 * Schedules and handles post-registration nudge emails. A 24-hour and a
 * 72-hour cron event are queued for every new user at registration time.
 * Both events are cancelled when the user completes the onboarding flow,
 * and the shared handler skips users who have already finished onboarding.
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
		// Enqueue the two onboarding nudges the moment the member registers, each with
		// its due time, so the recurring sweep reads a small indexed queue instead of
		// range-scanning wp_users on every run (card 10264295353). Only registrations
		// AFTER this ships are queued, which naturally replaces the old baseline guard
		// that kept the sweep from nudging pre-existing members.
		add_action( 'user_register', array( $this, 'enqueue_nudges' ), 17, 1 );
		add_action( 'buddynext_onboarding_completed', array( $this, 'on_onboarding_completed_cancel_nudges' ), 10, 1 );
		// Legacy per-user events (pre-1.2.0) still fire this until the upgrade
		// unschedules them; keep the handler so any in-flight ones still send.
		add_action( 'bn_onboarding_nudge_24h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
		add_action( 'bn_onboarding_nudge_72h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_to_onboarding' ), 5 );
		add_action( 'buddynext_async_send_invite_email', array( $this, 'handle_async_invite_email' ), 10, 1 );

		// One recurring Action Scheduler sweep replaces the two per-user WP-Cron
		// events that used to be scheduled on every registration — those grew the
		// autoloaded cron option without bound (305 KB after 1,500 signups).
		add_action( self::NUDGE_SWEEP_HOOK, array( $this, 'run_nudge_sweep' ) );
		add_action( 'init', array( __CLASS__, 'arm_nudge_sweep' ) );
	}

	/**
	 * Recurring Action Scheduler hook that sends due onboarding nudges.
	 */
	public const NUDGE_SWEEP_HOOK = 'buddynext_onboarding_nudge_sweep';

	/**
	 * Action Scheduler group (shared with the rest of BuddyNext's jobs).
	 */
	private const NUDGE_SWEEP_GROUP = 'buddynext';

	/**
	 * Option holding the moment the recurring sweep took over from the legacy
	 * per-user cron events, set once by the upgrade migration. The sweep never
	 * nudges anyone registered before it, which is what prevents the transition
	 * cohort being nudged twice. Absent (0) on a fresh install.
	 */
	public const NUDGE_BASELINE_OPTION = 'bn_onboarding_nudge_baseline';

	/**
	 * Users processed per batch while draining a window.
	 */
	private const NUDGE_BATCH = 200;

	/**
	 * Safety ceiling on users processed per window per run. A backlog beyond this
	 * drains over the next 6-hourly runs instead of one long request.
	 */
	private const NUDGE_MAX_PER_RUN = 5000;

	/**
	 * Arm the recurring nudge sweep exactly once.
	 *
	 * Mirrors LogRetentionService::arm(): self-arming, guarded so it does not
	 * re-enqueue on every request. Every 6 hours is frequent enough that a user
	 * is caught inside the 24h-wide due window below.
	 *
	 * @return void
	 */
	public static function arm_nudge_sweep(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::NUDGE_SWEEP_HOOK, array(), self::NUDGE_SWEEP_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + HOUR_IN_SECONDS,
			6 * HOUR_IN_SECONDS,
			self::NUDGE_SWEEP_HOOK,
			array(),
			self::NUDGE_SWEEP_GROUP
		);
	}

	/**
	 * Send the 24h and 72h onboarding nudges to users who are due one.
	 *
	 * Selects by registration date within a bounded window (so the query is
	 * cheap regardless of total user count, and a user registered weeks ago is
	 * never mass-nudged on upgrade) and skips anyone already nudged or already
	 * onboarded. A per-user meta flag is set after processing so nobody is
	 * considered twice. Batched to keep one pass bounded.
	 *
	 * @return void
	 */
	public function run_nudge_sweep(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'bn_onboarding_nudges';

		// Drain due, unsent nudges off the (sent, due_at) index — no wp_users scan.
		// Batched with a per-run cap; a backlog drains over the next runs. Marking a
		// row sent removes it from the next batch, so pages advance by themselves.
		$processed = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, kind FROM {$table}
					 WHERE sent = 0 AND due_at <= UTC_TIMESTAMP()
					 ORDER BY due_at ASC, user_id ASC
					 LIMIT %d",
					self::NUDGE_BATCH
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$batch_size = count( (array) $rows );
			foreach ( (array) $rows as $row ) {
				$user_id = (int) $row['user_id'];
				// handle_onboarding_nudge() sends the due nudge and no-ops an
				// already-onboarded user; either way the row is marked sent so it
				// leaves the queue for good.
				$this->handle_onboarding_nudge( $user_id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array( 'sent' => 1 ),
					array(
						'user_id' => $user_id,
						'kind'    => (string) $row['kind'],
					),
					array( '%d' ),
					array( '%d', '%s' )
				);
				++$processed;
			}
		} while ( self::NUDGE_BATCH === $batch_size && $processed < self::NUDGE_MAX_PER_RUN );

		// Prune sent rows so the queue does not grow without bound. Bounded per run.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sent = 1 LIMIT %d", self::NUDGE_MAX_PER_RUN ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Queue a member's two onboarding nudges (24h, 72h) at registration.
	 *
	 * INSERT IGNORE against the (user_id, kind) primary key, so a duplicate
	 * user_register (or a re-run) never double-queues. due_at is the registration
	 * moment plus the offset; the recurring sweep sends each when UTC_TIMESTAMP()
	 * reaches it. Card 10264295353.
	 *
	 * @param int $user_id Newly-registered user.
	 * @return void
	 */
	public function enqueue_nudges( int $user_id ): void {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . 'bn_onboarding_nudges';
		$now   = time();
		$due   = array(
			'24h' => DAY_IN_SECONDS,
			'72h' => 3 * DAY_IN_SECONDS,
		);
		foreach ( $due as $kind => $offset ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} ( user_id, kind, due_at, sent ) VALUES ( %d, %s, %s, 0 )",
					$user_id,
					$kind,
					gmdate( 'Y-m-d H:i:s', $now + $offset )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
		// Legacy per-user events (pre-1.2.0 sites, until the migration unschedules them).
		wp_clear_scheduled_hook( 'bn_onboarding_nudge_24h', array( $user_id ) );
		wp_clear_scheduled_hook( 'bn_onboarding_nudge_72h', array( $user_id ) );

		// Cancel this member's still-pending queued nudges — they onboarded, so the
		// remaining nudges would no-op anyway; marking them sent drops them from the
		// sweep now (card 10264295353).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_onboarding_nudges',
			array( 'sent' => 1 ),
			array(
				'user_id' => (int) $user_id,
				'sent'    => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
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
