<?php
/**
 * The WordPress core registration form, brought under BuddyNext's policy.
 *
 * BuddyNext force-enabled wp-login.php?action=register and then protected none
 * of it: no captcha, no honeypot, no time-trap, no rate limit, no allowed-domain
 * check, and in invite-only mode no invite requirement. Everything the owner
 * configured in Settings governed exactly one of the doors into their community.
 *
 * Two things are fixed here.
 *
 * 1. We are a plugin, not a SaaS. BuddyNext must not overwrite a decision the
 *    owner made on their own site. `closed` is now a real registration mode, and
 *    an owner who closes registration finds it still closed.
 *
 * 2. By default BuddyNext is the only front door and the core form redirects to
 *    it. An owner may re-enable the core form — some sites have other plugins
 *    hooking register_form — and when they do, it still passes through the shared
 *    gate. The choice is which signup UI, never whether the owner's policy applies.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Governs wp-login.php?action=register.
 */
class CoreRegistration {

	/**
	 * Option: may the WordPress core registration form be used at all?
	 */
	public const OPT_ALLOW = 'buddynext_allow_core_registration';

	/**
	 * Admin-post action that reconciles users_can_register to the BuddyNext mode.
	 */
	private const ACTION_RECONCILE = 'buddynext_reconcile_registration';

	/**
	 * Wire the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'login_form_register', array( $this, 'maybe_redirect_to_buddynext' ) );
		add_filter( 'registration_errors', array( $this, 'apply_policy' ), 10, 3 );

		// Mirror the registration mode onto WP core's users_can_register. This used
		// to live in Admin\Settings, so it only ran inside wp-admin — a mode set by
		// WP-CLI or by code never reached the core flag.
		add_action( 'update_option_buddynext_reg_mode', array( $this, 'sync_core_registration' ), 10, 2 );
		add_action( 'add_option_buddynext_reg_mode', array( $this, 'sync_core_registration' ), 10, 2 );

		// The mirror only fires when the mode CHANGES, so the two can drift: another
		// plugin (or Settings -> General) flips users_can_register and nothing ever
		// reconciles it. reg_mode=open + users_can_register=0 means every signup is
		// refused while the owner sees "Open" selected — silent lost registrations.
		// Surface the mismatch and offer to fix it; do NOT force-write it back, which
		// is the override this class exists to have stopped doing.
		add_action( 'admin_notices', array( $this, 'render_desync_notice' ) );
		add_action( 'admin_post_' . self::ACTION_RECONCILE, array( $this, 'handle_reconcile' ) );

		// The other way registration config lies to the owner: terms consent switched on
		// with no terms page behind it.
		add_action( 'admin_notices', array( $this, 'render_terms_notice' ) );
	}

	/**
	 * Warn when terms consent is required but there is no terms page to consent TO.
	 *
	 * This is the DEFAULT state of a fresh install: `buddynext_require_terms` defaults on
	 * and `buddynext_terms_page_id` defaults to 0. Until this was fixed, every member was
	 * made to tick "I agree to the Terms of Service" against a link that went nowhere.
	 *
	 * RegistrationPolicy now declines to enforce a gate with nothing behind it, so signups
	 * are not blocked — but the owner MUST be told, or they believe they have a consent
	 * record they do not have. Silently not-enforcing is the same trap as silently
	 * enforcing; the only honest option is to say so.
	 *
	 * @since 1.0.8
	 *
	 * @return bool True when consent is demanded with no page behind it.
	 */
	public static function has_terms_gap(): bool {
		// Only when the owner ASKED for consent and gave us nothing to point at.
		if ( ! (bool) get_option( 'buddynext_require_terms', true ) ) {
			return false;
		}

		return (int) get_option( 'buddynext_terms_page_id', 0 ) < 1;
	}

	/**
	 * The same consent warning, rendered INSIDE the Registration panel.
	 *
	 * The global notice is cleared on BuddyNext screens along with every foreign
	 * one — and its own button linked HERE, so following it made the message
	 * disappear. Inline there is no button: the setting is on this screen, so it
	 * says which control to set instead of sending the owner in a circle.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_terms_inline(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::has_terms_gap() ) {
			return;
		}
		?>
		<div class="bn-notice bn-notice-error">
			<p>
				<strong><?php esc_html_e( 'Terms consent is switched on, but no Terms page is set.', 'buddynext' ); ?></strong>
				<?php esc_html_e( 'Members would be asked to agree to a document that does not exist, so consent is NOT being collected or enforced. Choose a Terms page below, or switch the consent requirement off.', 'buddynext' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The consent warning as a global admin notice.
	 *
	 * Suppressed on BuddyNext's own screens by AdminHub, which is why
	 * {@see self::render_terms_inline()} exists for the panel that owns the setting.
	 *
	 * @return void
	 */
	public function render_terms_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::has_terms_gap() ) {
			return;
		}
		?>
		<?php
		// The notice told the owner to "choose a Terms page" and then gave them nowhere to do it.
		// A warning about consent not being collected is precisely the one an owner needs to act
		// on immediately; making them hunt for the setting is how it gets dismissed instead.
		// Same URL the Setup Checklist already uses for this setting.
		$bn_terms_url = admin_url( 'admin.php?page=buddynext-members&tab=registration' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'BuddyNext: terms consent is switched on, but no Terms page is set.', 'buddynext' ); ?></strong>
				<?php esc_html_e( 'Members would have been asked to agree to a document that does not exist, so consent is NOT being collected or enforced.', 'buddynext' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $bn_terms_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Choose a Terms page', 'buddynext' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether BuddyNext's registration mode and WP's users_can_register disagree.
	 *
	 * They disagree when core would refuse a signup BuddyNext believes is open (or
	 * the reverse). Either way the owner's stated intent is not what the site does.
	 *
	 * @return bool True when the two settings contradict each other.
	 */
	public static function is_desynced(): bool {
		$mode     = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );
		$expected = 'closed' === $mode ? '0' : '1';
		$actual   = (string) get_option( 'users_can_register', '0' );

		// Normalise: users_can_register is stored as '0'/'1' but can arrive as bool/int.
		return ( $actual ? '1' : '0' ) !== $expected;
	}

	/**
	 * Warn the owner when the two registration settings contradict each other.
	 *
	 * Rendered on every admin screen, not just BuddyNext's — a site silently
	 * refusing every registration is worth interrupting whatever the owner is
	 * doing, and they will not think to look at the Registration tab precisely
	 * because it shows the mode they expect.
	 *
	 * @return void
	 */
	/**
	 * The desync warning's content, or null when the two settings agree.
	 *
	 * Extracted so the global notice and the inline panel warning cannot drift
	 * apart. They MUST say the same thing: the inline copy exists precisely because
	 * the global one is invisible on BuddyNext's own screens, and an owner reading
	 * two different explanations of one problem is worse than reading none.
	 *
	 * @since 1.1.0
	 *
	 * @return array{headline:string,explain:string,mode:string,core_open:bool,action:string}|null
	 */
	public static function desync_state(): ?array {
		if ( ! self::is_desynced() ) {
			return null;
		}

		$mode         = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );
		$core_open    = (bool) get_option( 'users_can_register', false );
		$bn_wants_off = ( 'closed' === $mode );

		if ( $bn_wants_off ) {
			$headline = __( 'BuddyNext: accounts can still be created.', 'buddynext' );
			$explain  = __( 'BuddyNext registration is set to Closed, but WordPress still allows anyone to register. New accounts can still be created.', 'buddynext' );
		} elseif ( 'invite' === $mode ) {
			$headline = __( 'BuddyNext: your invitations are not working.', 'buddynext' );
			$explain  = __( 'Invite Only still needs WordPress registration switched on: an invited person creates their account through the normal signup form. With it off, every invitation fails with "Registration is closed on this community" — your invites are not working. Your community stays private either way, because only people holding a valid invitation can get through.', 'buddynext' );
		} elseif ( 'approval' === $mode ) {
			$headline = __( 'BuddyNext: nobody can request an account.', 'buddynext' );
			$explain  = __( 'Admin Approval still needs WordPress registration switched on: a request is created through the normal signup form and then waits for your review. With it off, nobody can even submit a request. Your community stays gated either way, because you approve every account.', 'buddynext' );
		} else {
			$headline = __( 'BuddyNext: every signup is being refused.', 'buddynext' );
			$explain  = __( 'WordPress registration is switched off, so every signup is being refused — even though BuddyNext shows registration as open. Members trying to join are being turned away.', 'buddynext' );
		}

		return array(
			'headline'  => $headline,
			'explain'   => $explain,
			'mode'      => $mode,
			'core_open' => $core_open,
			'action'    => $bn_wants_off
				? __( 'Turn WordPress registration off', 'buddynext' )
				: __( 'Turn WordPress registration on', 'buddynext' ),
		);
	}

	/**
	 * The desync warning as a global admin notice.
	 *
	 * Content comes from {@see self::desync_state()} so this and the inline panel
	 * warning cannot drift apart.
	 *
	 * @return void
	 */
	public function render_desync_notice(): void {
		$state = current_user_can( 'manage_options' ) ? self::desync_state() : null;
		if ( null === $state ) {
			return;
		}
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong><?php echo esc_html( $state['headline'] ); ?></strong>
				<?php echo esc_html( $state['explain'] ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: BuddyNext registration mode, 2: WordPress "Anyone can register" state. */
					esc_html__( 'BuddyNext mode: %1$s. WordPress "Anyone can register": %2$s.', 'buddynext' ),
					'<code>' . esc_html( $state['mode'] ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
					'<code>' . esc_html( $state['core_open'] ? __( 'on', 'buddynext' ) : __( 'off', 'buddynext' ) ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::reconcile_url() ); ?>">
					<?php echo esc_html( $state['action'] ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The same desync warning, rendered INSIDE the Registration panel.
	 *
	 * AdminHub clears `admin_notices` on every BuddyNext screen so third-party
	 * setup nags do not crowd the settings UI — deliberate, and staying. But
	 * `remove_all_actions()` cannot tell a foreign nag from one of ours, so this
	 * warning vanished on the one screen where the owner is configuring the very
	 * setting it is about. The Setup Checklist even links them here, so the
	 * guidance disappeared at the moment they acted on it.
	 *
	 * Rendering it inline fixes that without touching the suppression: the warning
	 * sits beside the control it concerns, which is better than a global banner
	 * anyway.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_desync_inline(): void {
		$state = current_user_can( 'manage_options' ) ? self::desync_state() : null;
		if ( null === $state ) {
			return;
		}
		?>
		<div class="bn-notice bn-notice-error">
			<p>
				<strong><?php echo esc_html( $state['headline'] ); ?></strong>
				<?php echo esc_html( $state['explain'] ); ?>
			</p>
			<p>
				<?php
				// Say where the other half of the setting lives. It is NOT on this
				// screen — users_can_register is WordPress core's, under
				// Settings > General — so an owner told only "switch it on" has
				// nowhere to go from here.
				printf(
					/* translators: 1: registration mode, 2: on/off, 3: link to WordPress Settings > General. */
					esc_html__( 'BuddyNext mode: %1$s. WordPress "Anyone can register": %2$s — that switch lives in %3$s.', 'buddynext' ),
					'<code>' . esc_html( $state['mode'] ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
					'<code>' . esc_html( $state['core_open'] ? __( 'on', 'buddynext' ) : __( 'off', 'buddynext' ) ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
					'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'buddynext' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::reconcile_url() ); ?>">
					<?php echo esc_html( $state['action'] ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Nonced URL for the one-click "make the two settings agree" action.
	 *
	 * @return string
	 */
	private static function reconcile_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_RECONCILE ),
			self::ACTION_RECONCILE
		);
	}

	/**
	 * Apply the owner's decision: set users_can_register to match the BN mode.
	 *
	 * Only ever runs from an explicit click on the notice — the owner is choosing
	 * the reconciliation, which is why this is safe where the old unconditional
	 * force-write was not.
	 *
	 * @return void
	 */
	public function handle_reconcile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'buddynext' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_RECONCILE );

		$mode = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );
		update_option( 'users_can_register', 'closed' === $mode ? '0' : '1' );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Mirror the BuddyNext registration mode onto WP core's users_can_register.
	 *
	 * MIRROR, not override. 'closed' is a real, selectable mode and an owner who
	 * closes registration finds it still closed. Previously the UI could only
	 * produce open|invite|approval, so this forced users_can_register to 1 on every
	 * save: an owner could not turn registration off through BuddyNext at all, and
	 * one who turned it off in Settings -> General had it silently switched back on
	 * by us. We are a plugin, not a SaaS; that decision is theirs.
	 *
	 * @param mixed $unused_or_old First hook arg (old value on update, option name on add) — unused.
	 * @param mixed $value         The saved registration mode.
	 * @return void
	 */
	public function sync_core_registration( $unused_or_old, $value ): void {
		unset( $unused_or_old );

		update_option( 'users_can_register', 'closed' === (string) $value ? '0' : '1' );
	}

	/**
	 * Is the core registration form available on this site?
	 *
	 * @return bool
	 */
	public static function is_allowed(): bool {
		return (bool) get_option( self::OPT_ALLOW, false );
	}

	/**
	 * Send visitors to the BuddyNext signup route unless the owner opted in.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_buddynext(): void {
		if ( self::is_allowed() ) {
			return;
		}

		wp_safe_redirect( \BuddyNext\Core\PageRouter::signup_url() );
		exit;
	}

	/**
	 * Run the shared registration policy on the core form.
	 *
	 * Applies even when the owner has re-enabled the form: opting into a different
	 * signup UI must never mean opting out of the spam protection and access
	 * policy they configured.
	 *
	 * @param WP_Error $errors Existing registration errors.
	 * @param string   $login  Submitted username (unused; the policy keys on email).
	 * @param string   $email  Submitted email address.
	 * @return WP_Error
	 */
	public function apply_policy( $errors, $login, $email ) {
		unset( $login );

		if ( ! $errors instanceof WP_Error ) {
			$errors = new WP_Error();
		}

		$policy = buddynext_service( 'registration_policy' );

		$access = $policy->check_access( (string) $email, null, RegistrationPolicy::SOURCE_CORE );
		if ( is_wp_error( $access ) ) {
			$errors->add( $access->get_error_code(), $access->get_error_message() );
			return $errors;
		}

		$guard = ( new RegistrationGuard() )->check(
			array(
				'source' => RegistrationPolicy::SOURCE_CORE,
				'email'  => (string) $email,
				'ip'     => AuthController::client_ip(),
			)
		);
		if ( is_wp_error( $guard ) ) {
			$errors->add( $guard->get_error_code(), $guard->get_error_message() );
		}

		return $errors;
	}
}
