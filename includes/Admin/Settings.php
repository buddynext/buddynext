<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext admin settings page.
 *
 * Registers the top-level BuddyNext menu and all settings tabs:
 * General, Registration, Social, Spaces, Moderation, Webhooks.
 * Settings are stored in wp_options with the buddynext_ prefix.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Registers and renders the BuddyNext admin settings page.
 */
class Settings extends AdminPageBase {

	/**
	 * Option name for the webhook shared secret.
	 */
	private const OPTION_WEBHOOK_SECRET = 'buddynext_webhook_secret';

	/**
	 * All settings registered by this class.
	 * Format: option_name => [ type, sanitize_callback ].
	 *
	 * Defaults are intentionally NOT carried here. WordPress only honours a
	 * register_setting() 'default' for get_option() reads while the setting is
	 * registered (admin_init), and every read site already passes its own
	 * inline get_option( $option, $fallback ) default — so a 'default' column
	 * here would just be a second, drift-prone copy. The inline fallbacks at
	 * the read sites are the single source of truth.
	 *
	 * @var array<string, array{string, callable|string}>
	 */
	private const SETTINGS_MAP = array(
		// General.
		'buddynext_site_name'                  => array( 'string', 'sanitize_text_field' ),
		'buddynext_brand_color'                => array( 'string', array( self::class, 'sanitize_brand_color' ) ),
		'buddynext_description'                => array( 'string', 'sanitize_textarea_field' ),
		'buddynext_public_explore'             => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_enable_dm'                  => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_default_dm_access'          => array( 'string', 'sanitize_key' ),
		'buddynext_enable_community_nav'       => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_member_dir_columns'         => array( 'string', array( self::class, 'sanitize_dir_columns' ) ),

		// Registration.
		'buddynext_reg_mode'                   => array( 'string', 'sanitize_key' ),
		'buddynext_email_verify'               => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_reg_spam_protection'        => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_reg_challenge'              => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_reg_rate_limit'             => array( 'integer', 'absint' ),
		// Login & sign-up split-panel branding (plug-and-play: blank falls back to site identity).
		'buddynext_auth_panel_show'            => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_auth_panel_heading'         => array( 'string', 'sanitize_text_field' ),
		'buddynext_auth_panel_tagline'         => array( 'string', 'sanitize_textarea_field' ),
		'buddynext_auth_panel_quote'           => array( 'string', 'sanitize_textarea_field' ),
		'buddynext_auth_panel_image'           => array( 'string', 'esc_url_raw' ),
		'buddynext_allowed_domains'            => array( 'string', 'sanitize_textarea_field' ),

		// Social.
		'buddynext_default_post_privacy'       => array( 'string', 'sanitize_key' ),
		'buddynext_allow_polls'                => array( 'string', array( self::class, 'sanitize_bool_flag' ) ),
		'buddynext_allow_shares'               => array( 'string', array( self::class, 'sanitize_bool_flag' ) ),
		'buddynext_allow_bookmarks'            => array( 'string', array( self::class, 'sanitize_bool_flag' ) ),
		'buddynext_enable_link_preview'        => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_enable_emoji_picker'        => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_post_edit_window'           => array( 'integer', 'absint' ),

		// Spaces.
		'buddynext_space_creation_role'        => array( 'string', 'sanitize_key' ),
		'buddynext_space_max_sub_spaces'       => array( 'integer', 'absint' ),
		'buddynext_space_max_per_member'       => array( 'integer', 'absint' ),
		'buddynext_space_allow_sub'            => array( 'string', array( self::class, 'sanitize_bool_flag' ) ),
		'buddynext_space_default_type'         => array( 'string', 'sanitize_key' ),
		'buddynext_space_default_category'     => array( 'integer', 'absint' ),
		'buddynext_spaces_dir_columns'         => array( 'string', array( self::class, 'sanitize_dir_columns' ) ),

		// Moderation.
		'buddynext_auto_hide_threshold'        => array( 'integer', 'absint' ),
		'buddynext_strike_warn_threshold'      => array( 'integer', 'absint' ),
		'buddynext_strike_suspend_threshold'   => array( 'integer', 'absint' ),
		'buddynext_strike_perma_ban_threshold' => array( 'integer', 'absint' ),
		'buddynext_mod_queue_alert_threshold'  => array( 'integer', 'absint' ),
		'buddynext_banned_words'               => array( 'string', 'sanitize_textarea_field' ),
		'buddynext_blocked_domains'            => array( 'string', 'sanitize_textarea_field' ),
		'buddynext_blocked_ips'                => array( 'string', array( self::class, 'sanitize_ip_list' ) ),
		'buddynext_banned_hashtags'            => array( 'string', 'sanitize_textarea_field' ),
		'buddynext_post_rate_limit'            => array( 'integer', 'absint' ),
		'buddynext_new_member_post_threshold'  => array( 'integer', 'absint' ),
		'buddynext_duplicate_post_window'      => array( 'integer', 'absint' ),
		'buddynext_premod_mode'                => array( 'string', 'sanitize_key' ),
		'buddynext_premod_new_member_count'    => array( 'integer', 'absint' ),

		// Notifications.
		'buddynext_notif_default_follow'       => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_notif_default_connection'   => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_notif_default_reaction'     => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_notif_default_comment'      => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_notif_default_mention'      => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_notif_default_space_join'   => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_digest_frequency'           => array( 'string', 'sanitize_key' ),
		'buddynext_admin_alert_email'          => array( 'string', 'sanitize_email' ),

		// Email.
		'buddynext_email_from_name'            => array( 'string', 'sanitize_text_field' ),
		'buddynext_email_from_address'         => array( 'string', 'sanitize_email' ),
		'buddynext_email_reply_to'             => array( 'string', 'sanitize_email' ),
		'buddynext_email_footer_text'          => array( 'string', 'sanitize_textarea_field' ),

		// Integrations.
		'buddynext_jetonomy_feed_sync'         => array( 'string', array( self::class, 'sanitize_bool_flag' ) ),

		// Privacy & Data.
		'buddynext_google_indexing'            => array( 'string', 'sanitize_key' ),
		'buddynext_cookie_consent'             => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_data_retention_days'        => array( 'integer', 'absint' ),
		'buddynext_allow_data_export'          => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_allow_account_deletion'     => array( 'boolean', 'rest_sanitize_boolean' ),
		'buddynext_anonymize_on_delete'        => array( 'boolean', 'rest_sanitize_boolean' ),

		// Webhooks.
		'buddynext_webhook_secret'             => array( 'string', 'sanitize_text_field' ),
	);

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Hook the admin menu and settings registration into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_buddynext_apply_recommended', array( $this, 'handle_apply_recommended' ) );
		add_action( 'admin_post_buddynext_dismiss_recommended', array( $this, 'handle_dismiss_recommended' ) );

		// Each settings panel registers as its own Hub tab. The labels here are
		// the canonical wording; AdminHub's central placement map owns which
		// section each tab lands in and its order, so this class stays agnostic
		// of the final information architecture.
		$tabs = array(
			'general'       => __( 'General', 'buddynext' ),
			'features'      => __( 'Features', 'buddynext' ),
			'registration'  => __( 'Registration & Login', 'buddynext' ),
			'social'        => __( 'Social', 'buddynext' ),
			'spaces'        => __( 'Settings', 'buddynext' ),
			'notifications' => __( 'Notifications', 'buddynext' ),
			'email'         => __( 'Email', 'buddynext' ),
			'moderation'    => __( 'Controls', 'buddynext' ),
			'integrations'  => __( 'Integrations', 'buddynext' ),
			'privacy'       => __( 'Privacy & Data', 'buddynext' ),
			'webhooks'      => __( 'Webhooks', 'buddynext' ),
		);
		foreach ( $tabs as $slug => $label ) {
			AdminHub::register_tab(
				'settings',
				$slug,
				$label,
				function () use ( $slug ): void {
					$this->render_settings_tab( $slug );
				},
				array(
					'subtitle' => $this->get_tab_subtitle( $slug ),
				)
			);
		}

		// License tab — registered only while Pro is active, and placed in the
		// Monetization section by the central map. The free plugin's own key is
		// preset and managed automatically, so without Pro there is nothing for
		// the admin to manage here. The license form posts directly and is
		// handled on admin_init by its owner, so this tab renders outside the
		// options.php form wrapper.
		if ( defined( 'BUDDYNEXTPRO_VERSION' ) ) {
			AdminHub::register_tab(
				'settings',
				'license',
				__( 'License', 'buddynext' ),
				function (): void {
					$this->render_license_tab();
				},
				array(
					'subtitle' => __( 'Manage license keys for automatic plugin updates.', 'buddynext' ),
				)
			);
		}

		// Free standalone: surface a Free vs Pro comparison so owners can see
		// what the Pro upgrade unlocks. Hidden automatically once Pro is active.
		if ( ! defined( 'BUDDYNEXTPRO_VERSION' ) ) {
			/**
			 * Filter the "Upgrade to Pro" destination URL.
			 *
			 * @param string $url Product page URL.
			 */
			$upgrade_url    = (string) apply_filters( 'buddynext_pro_upgrade_url', 'https://wbcomdesigns.com/downloads/buddynext-pro/' );
			$upgrade_action = sprintf(
				'<a class="bn-btn" data-variant="primary" data-size="sm" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $upgrade_url ),
				esc_html__( 'Upgrade to BuddyNext Pro', 'buddynext' )
			);
			AdminHub::register_tab(
				'upgrade',
				'compare',
				__( 'Free vs Pro', 'buddynext' ),
				function (): void {
					$this->render_upgrade_tab();
				},
				array(
					'subtitle' => __( "You're on BuddyNext Free. Upgrade to Pro to unlock automation, analytics, monetization, and real-time.", 'buddynext' ),
					'action'   => $upgrade_action,
				)
			);
		}
	}

	/**
	 * Render one Settings tab inside its options.php form wrapper.
	 *
	 * Hub paints the section H1 + tab strip + the standardized sub-header bar
	 * (the tab's subtitle, declared via register_tab()). This method paints
	 * only the form: the Settings API fields, the active tab's body, and the
	 * save bar. It must NOT print its own subtitle — that lives in the Hub
	 * sub-header now, per the unified header contract.
	 *
	 * @param string $slug Tab slug.
	 * @return void
	 */
	private function render_settings_tab( string $slug ): void {
		$method = 'render_tab_' . $slug;
		if ( ! method_exists( $this, $method ) ) {
			echo '<p>' . esc_html__( 'Unknown settings tab.', 'buddynext' ) . '</p>';
			return;
		}

		// Settings API success notice — options.php redirects back here with
		// ?settings-updated=true after a save; surface the confirmation.
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error( 'buddynext_messages', 'buddynext_settings_saved', __( 'Settings saved.', 'buddynext' ), 'updated' );
		}
		settings_errors( 'buddynext_messages' );
		?>
		<form method="post" action="options.php" class="bn-settings-form">
			<?php settings_fields( 'buddynext_' . $slug ); ?>
			<?php $this->$method(); ?>
			<?php $this->render_save_bar(); ?>
		</form>
		<?php
	}

	/**
	 * Render the License tab.
	 *
	 * The free plugin's key is preset and managed automatically, so the tab
	 * only hosts content contributed by Pro (and any future add-on) via the
	 * action below. License state authorises update downloads only — it
	 * never gates functionality.
	 *
	 * @return void
	 */
	private function render_license_tab(): void {
		// The subtitle is rendered by AdminHub's sub-header bar (declared via the
		// register_tab() 'subtitle' arg), so the body prints only Pro's form.
		/**
		 * Fires inside the Settings > License tab.
		 *
		 * BuddyNext Pro hooks this to render its license activation form.
		 */
		do_action( 'buddynext_admin_license_tab_content' );
	}

	/**
	 * Apply the recommended first-run defaults, then dismiss the prompt.
	 *
	 * @return void
	 */
	public function handle_apply_recommended(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'buddynext_apply_recommended' );

		\BuddyNext\Core\RecommendedDefaults::apply();
		update_option( 'buddynext_recommended_dismissed', '1' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'buddynext',
					'bn_recommended' => 'applied',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Dismiss the recommended-defaults prompt without applying it.
	 *
	 * @return void
	 */
	public function handle_dismiss_recommended(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'buddynext_dismiss_recommended' );

		update_option( 'buddynext_recommended_dismissed', '1' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'buddynext',
					'bn_recommended' => 'dismissed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the "Recommended for new communities" prompt at the top of the
	 * General tab. A one-click way to switch on the full community experience
	 * (discovery, DM, engagement surfaces, default notifications). Hidden once
	 * applied or dismissed. The buttons are nonce-protected GET links so they
	 * can live inside the surrounding options.php form without nesting a form.
	 *
	 * @return void
	 */
	private function render_recommended_prompt(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice routing.
		$notice = isset( $_GET['bn_recommended'] ) ? sanitize_key( wp_unslash( (string) $_GET['bn_recommended'] ) ) : '';
		if ( 'applied' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recommended settings applied. Your community is ready to go.', 'buddynext' ) . '</p></div>';
		}

		if ( get_option( 'buddynext_recommended_dismissed' ) ) {
			return;
		}

		$apply_url   = wp_nonce_url( admin_url( 'admin-post.php?action=buddynext_apply_recommended' ), 'buddynext_apply_recommended' );
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=buddynext_dismiss_recommended' ), 'buddynext_dismiss_recommended' );
		?>
		<div class="bn-card bn-recommended-card">
			<h2 class="bn-recommended-card__title"><?php esc_html_e( 'Recommended for new communities', 'buddynext' ); ?></h2>
			<p class="bn-recommended-card__text">
				<?php esc_html_e( 'Turn on the full community experience in one click — public discovery, direct messaging, polls, reactions, shares, bookmarks, link previews, emoji, default notifications, and baseline spam protection. You can fine-tune everything afterwards.', 'buddynext' ); ?>
			</p>
			<p class="bn-recommended-card__actions">
				<a class="button button-primary" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply recommended settings', 'buddynext' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'buddynext' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Free vs Pro comparison (free standalone only).
	 *
	 * Shows the site owner exactly what the current install already includes
	 * and what the Pro upgrade unlocks, so the Pro feature set is discoverable
	 * even though Pro-only sections stay hidden until Pro is installed.
	 *
	 * @return void
	 */
	private function render_upgrade_tab(): void {
		// The subtitle + the "Upgrade to BuddyNext Pro" button are rendered by
		// AdminHub's sub-header bar (declared via the register_tab() 'subtitle'
		// and 'action' args), so the body prints only the comparison table.

		// Comparison rows: label + whether the Free plan already includes it.
		// Pro includes every row. Sourced from docs/specs/features/FREE-VS-PRO.md.
		$rows = array(
			array( __( 'Activity feed — posts, polls, reactions, comments, shares, bookmarks', 'buddynext' ), true ),
			array( __( 'Spaces, member directory, profiles, full-text search', 'buddynext' ), true ),
			array( __( '1:1 direct messages (via WPMediaVerse)', 'buddynext' ), true ),
			array( __( 'In-app bell + transactional email notifications', 'buddynext' ), true ),
			array( __( 'Report queue, strikes, suspensions, appeals', 'buddynext' ), true ),
			array( __( 'REST API, Gutenberg blocks, 1 outbound webhook', 'buddynext' ), true ),
			array( __( 'Scheduled & recurring posts, up to 10 pinned posts', 'buddynext' ), false ),
			array( __( 'Custom reaction emoji set (up to 20)', 'buddynext' ), false ),
			array( __( 'Broadcast email campaigns + drip welcome sequences', 'buddynext' ), false ),
			array( __( 'Group DM + real-time delivery, typing, read receipts', 'buddynext' ), false ),
			array( __( 'Real-time feed updates + online presence', 'buddynext' ), false ),
			array( __( 'Advanced moderation — keyword/link rules, AI, bulk actions', 'buddynext' ), false ),
			array( __( 'Site + per-space analytics with CSV export', 'buddynext' ), false ),
			array( __( 'Private/gated spaces, post approval, paywall, member tiers', 'buddynext' ), false ),
			array( __( 'Advanced profile fields + custom member labels', 'buddynext' ), false ),
			array( __( 'AI feed ranking + AI content moderation', 'buddynext' ), false ),
			array( __( 'Saved searches + advanced filters', 'buddynext' ), false ),
		);
		$this->open_section( __( 'Free vs Pro', 'buddynext' ) );
		?>
		<table class="bn-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Feature', 'buddynext' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Free', 'buddynext' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pro', 'buddynext' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$label    = (string) $row[0];
					$in_free  = (bool) $row[1];
					$yes_icon = '<span class="bn-feature-check">' . \BuddyNext\Core\IconService::render( 'check' ) . '</span>';
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td>
							<?php
							if ( $in_free ) {
								echo $yes_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd.
							} else {
								echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not included', 'buddynext' ) . '</span>';
							}
							?>
						</td>
						<td><?php echo $yes_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService output is wp_kses'd. ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->close_section();
	}

	/**
	 * Return a per-tab subtitle so admins see what *this* tab does, not the
	 * generic "Configure your community platform" repeated everywhere.
	 *
	 * Filterable so extensions can change wording without editing core.
	 *
	 * @param string $slug Tab slug.
	 * @return string
	 */
	private function get_tab_subtitle( string $slug ): string {
		$map = array(
			'general'       => __( 'Brand identity, discovery defaults, and direct messaging baseline.', 'buddynext' ),
			'features'      => __( 'Pick which features your community uses. Core features always run.', 'buddynext' ),
			'registration'  => __( 'Control who can sign up and how new accounts are verified.', 'buddynext' ),
			'social'        => __( 'Follow, connect, and block — the relationships that drive the feed.', 'buddynext' ),
			'spaces'        => __( 'Defaults for the Spaces module: who can create, how deep they nest.', 'buddynext' ),
			'notifications' => __( 'In-app + email notification rules and the events that trigger them.', 'buddynext' ),
			'email'         => __( 'Sender identity and delivery configuration for outgoing community email.', 'buddynext' ),
			'moderation'    => __( 'Site-wide moderation toggles: reporting, auto-hide thresholds, mod roles.', 'buddynext' ),
			'integrations'  => __( 'Outbound integrations — Slack, Discord, webhooks, third-party identity.', 'buddynext' ),
			'privacy'       => __( 'Data retention, export, and member privacy controls.', 'buddynext' ),
			'webhooks'      => __( 'Push community events to external services in real time.', 'buddynext' ),
		);
		/**
		 * Filter the Settings → tab subtitle copy.
		 *
		 * @param array<string,string> $map  Slug → subtitle map.
		 */
		$map = apply_filters( 'buddynext_settings_tab_subtitles', $map );
		return isset( $map[ $slug ] ) ? (string) $map[ $slug ] : $this->get_subtitle();
	}

	/**
	 * Enqueue the Settings page JS — admin search + webhook table CRUD.
	 *
	 * Both scripts are wp-only (no module imports) so they run on the
	 * vanilla admin shell. Gated to the BuddyNext Settings hook suffix so
	 * we don't ship JS to unrelated admin screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// The webhook-table CRUD + admin search JS must load wherever this
		// class's tabs are routed (Webhooks now lives in Platform, Registration
		// in Members, etc.), so gate on any BuddyNext hub screen rather than a
		// single hardcoded page.
		if ( ! AdminHub::is_hub_screen( $hook_suffix ) ) {
			return;
		}
		$plugin_root = dirname( __DIR__, 2 );
		$rel         = '/assets/js/admin/settings.js';
		$abs         = $plugin_root . $rel;
		if ( ! file_exists( $abs ) ) {
			return;
		}
		$plugin_url = plugins_url( '', $plugin_root . '/buddynext.php' );
		wp_enqueue_script(
			'buddynext-admin-settings',
			$plugin_url . $rel,
			array(),
			(string) filemtime( $abs ),
			true
		);
	}

	/**
	 * Sanitize the brand colour, falling back to the default when empty/invalid.
	 *
	 * An empty submission makes sanitize_hex_color() return '', which would
	 * persist as an empty option and wipe the brand colour (read sites only
	 * fall back to the default when the option is absent, not when it is ''),
	 * so clearing the field permanently broke the colour. Reset to the
	 * documented default instead.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Valid hex colour, or the default '#0073aa'.
	 */
	public static function sanitize_brand_color( $value ): string {
		$hex = sanitize_hex_color( (string) $value );
		return '' !== (string) $hex ? (string) $hex : '#0073aa';
	}

	/**
	 * Sanitize a directory column-count choice.
	 *
	 * Whitelists the supported values: 'auto' (responsive auto-fill, the
	 * default) or a fixed desktop column count of 2, 3 or 4. Anything else
	 * falls back to 'auto'.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string One of 'auto', '2', '3', '4'.
	 */
	public static function sanitize_dir_columns( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'auto', '2', '3', '4' ), true ) ? $value : 'auto';
	}

	/**
	 * Sanitize a checkbox flag to the string '1' or '0'.
	 *
	 * Stored as a string on purpose: a boolean `false` option collides with
	 * get_option()'s "missing → default" path (a `true` default then reads an
	 * explicit off-state back as on), so an on-by-default toggle could never be
	 * switched off. The strings '1'/'0' round-trip exactly, independent of the
	 * read default, so the toggle persists reliably.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string '1' or '0'.
	 */
	public static function sanitize_bool_flag( $value ): string {
		return rest_sanitize_boolean( $value ) ? '1' : '0';
	}

	/**
	 * Sanitize the blocked-IP list: keep one valid IP per line, drop the rest.
	 *
	 * Accepts newline- or comma-separated input (as typed in the textarea),
	 * validates each entry with FILTER_VALIDATE_IP (IPv4 or IPv6), de-duplicates,
	 * and returns a clean newline-separated list. Invalid entries are silently
	 * dropped so the stored option only ever contains real addresses the
	 * enforcement check can match.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Newline-separated list of valid IP addresses.
	 */
	public static function sanitize_ip_list( $value ): string {
		$parts = preg_split( '/[\r\n,]+/', (string) $value );
		$out   = array();
		foreach ( is_array( $parts ) ? $parts : array() as $line ) {
			$ip = trim( (string) $line );
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) && ! in_array( $ip, $out, true ) ) {
				$out[] = $ip;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Which settings tab owns each option.
	 *
	 * Every tab registers its options under its OWN option group
	 * ("buddynext_{tab}") and its form submits that same group, so saving one
	 * tab only ever processes that tab's options. Previously every option shared
	 * the single "buddynext" group, so options.php iterated all of them on every
	 * save and null-sanitized the ones not on the active tab — silently wiping
	 * other tabs' values. This map is the single source of truth for the
	 * option→group assignment; a new option MUST be added to the tab that
	 * renders it (option_group() falls back to "buddynext" for anything missing).
	 *
	 * @var array<string, string[]>
	 */
	private const TAB_OPTIONS = array(
		'general'       => array(
			'buddynext_site_name',
			'buddynext_brand_color',
			'buddynext_description',
			'buddynext_public_explore',
			'buddynext_enable_dm',
			'buddynext_default_dm_access',
			'buddynext_enable_community_nav',
			'buddynext_member_dir_columns',
			'buddynext_spaces_dir_columns',
		),
		'features'      => array(
			'buddynext_features',
		),
		'registration'  => array(
			'buddynext_reg_mode',
			'buddynext_email_verify',
			'buddynext_reg_spam_protection',
			'buddynext_reg_challenge',
			'buddynext_reg_rate_limit',
			'buddynext_auth_panel_show',
			'buddynext_auth_panel_heading',
			'buddynext_auth_panel_tagline',
			'buddynext_auth_panel_quote',
			'buddynext_auth_panel_image',
			'buddynext_allowed_domains',
			'buddynext_social_login',
		),
		'social'        => array(
			'buddynext_default_post_privacy',
			'buddynext_allow_polls',
			'buddynext_allow_shares',
			'buddynext_allow_bookmarks',
			'buddynext_enable_link_preview',
			'buddynext_enable_emoji_picker',
			'buddynext_post_edit_window',
			'buddynext_enabled_reactions',
		),
		'spaces'        => array(
			'buddynext_space_creation_role',
			'buddynext_space_max_per_member',
			'buddynext_space_allow_sub',
			'buddynext_space_max_sub_spaces',
			'buddynext_space_default_type',
			'buddynext_space_default_category',
		),
		'moderation'    => array(
			'buddynext_premod_mode',
			'buddynext_premod_new_member_count',
			'buddynext_banned_words',
			'buddynext_banned_hashtags',
			'buddynext_blocked_domains',
			'buddynext_blocked_ips',
			'buddynext_post_rate_limit',
			'buddynext_duplicate_post_window',
			'buddynext_new_member_post_threshold',
			'buddynext_auto_hide_threshold',
			'buddynext_mod_queue_alert_threshold',
			'buddynext_strike_warn_threshold',
			'buddynext_strike_suspend_threshold',
			'buddynext_strike_perma_ban_threshold',
		),
		'notifications' => array(
			'buddynext_notif_default_follow',
			'buddynext_notif_default_connection',
			'buddynext_notif_default_reaction',
			'buddynext_notif_default_comment',
			'buddynext_notif_default_mention',
			'buddynext_notif_default_space_join',
			'buddynext_digest_frequency',
			'buddynext_admin_alert_email',
		),
		'email'         => array(
			'buddynext_email_from_name',
			'buddynext_email_from_address',
			'buddynext_email_reply_to',
			'buddynext_email_footer_text',
		),
		'privacy'       => array(
			'buddynext_cookie_consent',
			'buddynext_google_indexing',
			'buddynext_allow_data_export',
			'buddynext_allow_account_deletion',
			'buddynext_anonymize_on_delete',
			'buddynext_data_retention_days',
		),
		'integrations'  => array(
			'buddynext_jetonomy_feed_sync',
		),
		'webhooks'      => array(
			'buddynext_webhook_secret',
		),
	);

	/**
	 * Resolve the option group (settings-tab scope) an option belongs to.
	 *
	 * Returns "buddynext_{tab}" when the option is mapped in TAB_OPTIONS, or the
	 * legacy "buddynext" group as a safe fallback for any unmapped option.
	 *
	 * @param string $option Option name.
	 * @return string Settings group / option_page name.
	 */
	public static function option_group( string $option ): string {
		foreach ( self::TAB_OPTIONS as $tab => $options ) {
			if ( in_array( $option, $options, true ) ) {
				return 'buddynext_' . $tab;
			}
		}

		return 'buddynext';
	}

	/**
	 * Register all settings with the WordPress Settings API.
	 *
	 * Each option is registered under its tab's own group (see TAB_OPTIONS) so a
	 * save only touches the active tab's options. Registering here also ensures
	 * the sanitize_callback runs on save even though rendering is manual.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( self::SETTINGS_MAP as $option => $config ) {
			list( $type, $sanitize ) = $config;
			register_setting(
				self::option_group( $option ),
				$option,
				array(
					'type'              => $type,
					'sanitize_callback' => $sanitize,
				)
			);
		}

		// FeatureRegistry catalog persisted as a single map of slug=>bool.
		// Mandatory features are filtered out by the registry; only
		// default_on + opt_in feature states land in the option.
		register_setting(
			'buddynext_features',
			'buddynext_features',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_features_option' ),
				'default'           => array(),
			)
		);

		// Social login (OAuth2) per-provider credentials.
		register_setting(
			'buddynext_registration',
			'buddynext_social_login',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_social_login_option' ),
				'default'           => array(),
			)
		);

		// Reaction palette — owner-chosen subset of the canonical six reactions.
		register_setting(
			'buddynext_social',
			'buddynext_enabled_reactions',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_enabled_reactions' ),
				'default'           => \BuddyNext\Reactions\ReactionService::REACTION_TYPES,
			)
		);
	}

	/**
	 * Sanitize the enabled-reactions option: keep only canonical reaction slugs,
	 * in canonical order. Never allow an empty set (that would disable all
	 * reactions), so an empty submission falls back to the full set.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string[]
	 */
	public function sanitize_enabled_reactions( $value ): array {
		$all    = \BuddyNext\Reactions\ReactionService::REACTION_TYPES;
		$chosen = array_values( array_intersect( $all, array_map( 'sanitize_key', (array) $value ) ) );

		return empty( $chosen ) ? $all : $chosen;
	}

	/**
	 * Sanitize the social-login option ([provider => {enabled,client_id,client_secret}]).
	 *
	 * @param mixed $raw Submitted value.
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize_social_login_option( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		// Iterate the same provider list the form renders (get_providers()) so a
		// provider can never be dropped on save by drifting from a hardcoded list.
		foreach ( array_keys( \BuddyNext\Auth\SocialLogin::get_providers() ) as $id ) {
			$p          = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
			$out[ $id ] = array(
				'enabled'       => ! empty( $p['enabled'] ),
				'client_id'     => isset( $p['client_id'] ) ? sanitize_text_field( (string) $p['client_id'] ) : '',
				'client_secret' => isset( $p['client_secret'] ) ? sanitize_text_field( (string) $p['client_secret'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Sanitize callback for the buddynext_features option.
	 *
	 * Coerces the submitted POST array into a slug=>bool map and persists
	 * via FeatureRegistry so the dependency + tier rules are applied.
	 *
	 * @param mixed $value Raw input.
	 * @return array<string,bool>
	 */
	public function sanitize_features_option( $value ): array {
		$cleaned = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $slug => $on ) {
				$slug             = sanitize_key( (string) $slug );
				$cleaned[ $slug ] = ! empty( $on );
			}
		}
		// Apply the registry's tier rules (drop mandatory slugs) and RETURN the
		// result — the Settings API persists whatever we return. We must NOT call
		// persist()/update_option() here: this runs as the sanitize_callback for
		// the buddynext_features option, so writing the option again re-enters
		// this callback and recurses until the request exhausts memory.
		if ( function_exists( 'buddynext_service' ) ) {
			$container = \BuddyNext\Core\Container::instance();
			if ( $container->has( 'features' ) ) {
				return $container->get( 'features' )->clean_state( $cleaned );
			}
		}
		return $cleaned;
	}

	// ── Static helper ─────────────────────────────────────────────────────────

	/**
	 * Get a BuddyNext setting value.
	 *
	 * @param string $key      Setting key without the buddynext_ prefix.
	 * @param mixed  $fallback Default value if the option is not set.
	 * @return mixed
	 */
	public static function get_setting( string $key, mixed $fallback = '' ): mixed {
		return get_option( 'buddynext_' . $key, $fallback );
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'BuddyNext Settings', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Configure your community platform', 'buddynext' );
	}

	/**
	 * Render the settings page content: tab bar + form with section cards.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) );
		$base_url   = admin_url( 'admin.php?page=buddynext' );

		$tabs = array(
			'general'       => __( 'General', 'buddynext' ),
			'features'      => __( 'Features', 'buddynext' ),
			'registration'  => __( 'Registration', 'buddynext' ),
			'social'        => __( 'Social', 'buddynext' ),
			'spaces'        => __( 'Spaces', 'buddynext' ),
			'notifications' => __( 'Notifications', 'buddynext' ),
			'email'         => __( 'Email', 'buddynext' ),
			'moderation'    => __( 'Moderation', 'buddynext' ),
			'integrations'  => __( 'Integrations', 'buddynext' ),
			'privacy'       => __( 'Privacy & Data', 'buddynext' ),
			'webhooks'      => __( 'Webhooks', 'buddynext' ),
		);

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'general';
		}

		?>
		<div class="bn-admin-search" role="search">
			<label class="screen-reader-text" for="bn-admin-search-input">
				<?php esc_html_e( 'Search BuddyNext settings', 'buddynext' ); ?>
			</label>
			<input
				type="search"
				id="bn-admin-search-input"
				class="bn-input regular-text"
				placeholder="<?php esc_attr_e( 'Search settings (Cmd/Ctrl + K)…', 'buddynext' ); ?>"
				data-bn-admin-search
				autocomplete="off"
			>
			<span class="bn-admin-search__hint" data-bn-admin-search-status></span>
		</div>
		<?php
		$this->render_tab_bar( $tabs, $active_tab, $base_url );
		$this->open_tab_panel( $active_tab );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'buddynext_' . $active_tab ); ?>
			<?php $this->{'render_tab_' . $active_tab}(); ?>
			<?php $this->render_save_bar(); ?>
		</form>
		<?php
		$this->close_tab_panel();
	}

	// ── Tab renderers ─────────────────────────────────────────────────────────

	/**
	 * Render the General settings tab.
	 *
	 * @return void
	 */
	private function render_tab_general(): void {
		$this->render_recommended_prompt();

		$this->open_section( __( 'Community Identity', 'buddynext' ) );

		$this->render_text_row(
			'buddynext_site_name',
			__( 'Community Name', 'buddynext' ),
			(string) get_option( 'buddynext_site_name', get_bloginfo( 'name' ) ),
			__( 'Displayed in the site header, emails, and browser title.', 'buddynext' ),
			360
		);

		$this->render_color_row(
			'buddynext_brand_color',
			__( 'Brand color', 'buddynext' ),
			(string) get_option( 'buddynext_brand_color', '#0073aa' ),
			__( 'Your community accent — used for buttons, links, active tabs, and badges across every member-facing screen. Click the swatch to pick, or paste a hex code.', 'buddynext' )
		);

		$this->render_textarea_row(
			'buddynext_description',
			__( 'Community Description', 'buddynext' ),
			(string) get_option( 'buddynext_description', '' ),
			__( 'Short description shown on the community landing page and in meta tags.', 'buddynext' ),
			3,
			540
		);

		$this->close_section();

		$this->open_section( __( 'Discovery', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_public_explore',
			__( 'Public explore feed', 'buddynext' ),
			__( 'Allow guests to browse the explore feed without logging in.', 'buddynext' ),
			(bool) get_option( 'buddynext_public_explore', true )
		);

		$this->close_section();

		$this->open_section( __( 'Direct Messaging', 'buddynext' ) );

		// Direct messaging runs on the WPMediaVerse engine — gate the toggle when
		// it is not active so owners can't enable a feature that can't function.
		$bn_dm_available = class_exists( 'WPMediaVerse\\Core\\Plugin' );
		$this->render_toggle_row(
			'buddynext_enable_dm',
			__( 'Enable direct messaging', 'buddynext' ),
			$bn_dm_available
				? __( 'Allow members to send private messages. Requires the WPMediaVerse plugin.', 'buddynext' )
				: __( 'Direct Messaging requires the WPMediaVerse plugin. Install and activate it to enable this feature.', 'buddynext' ),
			$bn_dm_available && (bool) get_option( 'buddynext_enable_dm', true ),
			! $bn_dm_available
		);

		$this->render_select_row(
			'buddynext_default_dm_access',
			__( 'Who can DM me (default)', 'buddynext' ),
			(string) get_option( 'buddynext_default_dm_access', 'everyone' ),
			array(
				'everyone'    => __( 'Everyone', 'buddynext' ),
				'members'     => __( 'Members only', 'buddynext' ),
				'connections' => __( 'Connections only', 'buddynext' ),
				'nobody'      => __( 'No one', 'buddynext' ),
			),
			__( 'Default privacy applied to new accounts. Members can override this in their own privacy settings.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Directory columns', 'buddynext' ) );

		$bn_dir_col_choices = array(
			'auto' => __( 'Auto (fit to width)', 'buddynext' ),
			'2'    => __( '2 columns', 'buddynext' ),
			'3'    => __( '3 columns', 'buddynext' ),
			'4'    => __( '4 columns', 'buddynext' ),
		);

		$this->render_select_row(
			'buddynext_member_dir_columns',
			__( 'Member directory columns (desktop)', 'buddynext' ),
			(string) get_option( 'buddynext_member_dir_columns', '3' ),
			$bn_dir_col_choices,
			__( 'How many member cards per row on desktop. A fixed value caps the row and still steps down to fewer columns on tablet and mobile; Auto fits as many as the width allows.', 'buddynext' )
		);

		$this->render_select_row(
			'buddynext_spaces_dir_columns',
			__( 'Space directory columns (desktop)', 'buddynext' ),
			(string) get_option( 'buddynext_spaces_dir_columns', '3' ),
			$bn_dir_col_choices,
			__( 'How many space cards per row on desktop in the Spaces directory. A fixed value caps the row and still steps down on tablet and mobile; Auto fits as many as the width allows.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Community menu', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_enable_community_nav',
			__( 'Auto-place the community menu in your theme', 'buddynext' ),
			__( 'Drops the Feed / Members / Spaces menu into your theme automatically. Turn off to use your theme\'s own menu instead. To rename, reorder, or hide individual items, use the Navigation tab.', 'buddynext' ),
			(bool) get_option( 'buddynext_enable_community_nav', true )
		);

		$this->close_section();
	}

	/**
	 * Render the Features settings tab.
	 *
	 * Site-owner control over which Layer 2 features are active. Catalogue
	 * comes from FeatureRegistry. Mandatory tier is rendered as disabled
	 * (always-on, no toggle). Default-on + opt-in tier render as live
	 * toggles backed by the buddynext_features option.
	 *
	 * @return void
	 */
	private function render_tab_features(): void {
		$container = \BuddyNext\Core\Container::instance();
		if ( ! $container->has( 'features' ) ) {
			return;
		}
		$registry = $container->get( 'features' );
		$state    = (array) get_option( 'buddynext_features', array() );
		$groups   = $registry->by_group();

		$group_labels = array(
			'core'         => __( 'Core (always on)', 'buddynext' ),
			'community'    => __( 'Community features', 'buddynext' ),
			'bridges'      => __( 'Integration bridges', 'buddynext' ),
			'integrations' => __( 'Power-user integrations', 'buddynext' ),
		);

		$this->open_section( __( 'Features', 'buddynext' ) );

		echo '<p class="bn-field-hint" style="margin-top:0">' .
			esc_html__( 'Pick which features your community uses. Core features always run. You can enable or disable everything else from this tab — changes apply immediately on save.', 'buddynext' ) .
			'</p>';

		foreach ( $groups as $group_key => $features ) {
			$group_label = $group_labels[ $group_key ] ?? ucfirst( (string) $group_key );
			echo '<h3 class="bn-feature-group">' . esc_html( $group_label ) . '</h3>';
			echo '<div class="bn-feature-grid">';

			foreach ( $features as $feature ) {
				$slug         = (string) $feature['slug'];
				$tier         = (string) $feature['tier'];
				$is_mandatory = ( \BuddyNext\Core\FeatureRegistry::TIER_MANDATORY === $tier );
				$current      = $registry->is_enabled( $slug );
				$is_locked    = $is_mandatory;

				$badge_label = $is_mandatory
					? __( 'Always on', 'buddynext' )
					: ( \BuddyNext\Core\FeatureRegistry::TIER_DEFAULT_ON === $tier
						? __( 'Default on', 'buddynext' )
						: __( 'Opt-in', 'buddynext' )
					);
				$badge_tone  = $is_mandatory ? 'accent' : ( \BuddyNext\Core\FeatureRegistry::TIER_DEFAULT_ON === $tier ? 'success' : 'info' );

				?>
				<div class="bn-feature-row" data-tier="<?php echo esc_attr( $tier ); ?>">
					<div class="bn-feature-row__copy">
						<div class="bn-feature-row__head">
							<span class="bn-feature-row__label"><?php echo esc_html( $feature['label'] ); ?></span>
							<span class="bn-badge" data-tone="<?php echo esc_attr( $badge_tone ); ?>"><?php echo esc_html( $badge_label ); ?></span>
						</div>
						<p class="bn-feature-row__desc"><?php echo esc_html( $feature['description'] ); ?></p>
						<?php if ( ! empty( $feature['depends_on'] ) ) : ?>
							<p class="bn-feature-row__deps">
								<?php
								printf(
									/* translators: %s: list of dependency slugs */
									esc_html__( 'Requires: %s', 'buddynext' ),
									esc_html( implode( ', ', $feature['depends_on'] ) )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
					<div class="bn-feature-row__toggle">
						<?php if ( $is_locked ) : ?>
							<span class="bn-feature-row__locked" aria-label="<?php esc_attr_e( 'This feature is always on and cannot be disabled.', 'buddynext' ); ?>">
								<?php buddynext_icon( 'lock' ); ?>
							</span>
						<?php else : ?>
							<label class="bn-toggle-label">
								<input
									type="hidden"
									name="buddynext_features[<?php echo esc_attr( $slug ); ?>]"
									value="0">
								<input
									type="checkbox"
									name="buddynext_features[<?php echo esc_attr( $slug ); ?>]"
									value="1"
									<?php checked( $current, true ); ?>
									role="switch"
									aria-label="<?php echo esc_attr( $feature['label'] ); ?>">
								<span class="bn-toggle--inline"></span>
							</label>
						<?php endif; ?>
					</div>
				</div>
				<?php
			}

			echo '</div>';
		}

		$this->close_section();
	}

	/**
	 * Render the Registration settings tab.
	 *
	 * @return void
	 */
	private function render_tab_registration(): void {
		$this->open_section( __( 'Registration Settings', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_reg_mode',
			__( 'Registration Mode', 'buddynext' ),
			(string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() ),
			array(
				'open'     => __( 'Open — anyone can register', 'buddynext' ),
				'invite'   => __( 'Invite Only — requires an invitation', 'buddynext' ),
				'approval' => __( 'Admin Approval — admin reviews each request', 'buddynext' ),
			),
			__( 'Controls who can create a new account on your community.', 'buddynext' )
		);

		// The "Require email verification" sub-toggle only has any effect when the
		// Email Verification feature is enabled on the Features tab. When the
		// feature is off, hide the toggle (it would be saved but silently ignored)
		// and point the admin to where the master switch lives.
		$bn_verification_on = buddynext_feature_enabled( 'verification' );
		if ( $bn_verification_on ) {
			$this->render_toggle_row(
				'buddynext_email_verify',
				__( 'Require email verification', 'buddynext' ),
				__( 'New registrations must verify their email before accessing the community.', 'buddynext' ),
				(bool) get_option( 'buddynext_email_verify', false )
			);
		} else {
			$bn_features_url = add_query_arg(
				array(
					'page' => 'buddynext',
					'tab'  => 'features',
				),
				admin_url( 'admin.php' )
			);
			echo '<p class="bn-field-hint">' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the Features settings tab */
					__( 'Email verification is turned off under %s. Enable the Email Verification feature there to require it for new registrations.', 'buddynext' ),
					'<a href="' . esc_url( $bn_features_url ) . '">' . esc_html__( 'Features', 'buddynext' ) . '</a>'
				)
			) . '</p>';
		}

		$this->close_section();

		$this->open_section( __( 'Login &amp; Sign-up Panel', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_auth_panel_show',
			__( 'Show the branding panel', 'buddynext' ),
			__( 'Displays a branded side panel next to the login and sign-up forms. Turn off for a centered form only.', 'buddynext' ),
			(bool) get_option( 'buddynext_auth_panel_show', true )
		);

		// Fields are pre-filled with the product-level defaults (the same values
		// the live panel uses, via buddynext_auth_panel_value) so nothing is ever
		// blank — a plug-and-play setup the owner can simply edit.
		$this->render_text_row(
			'buddynext_auth_panel_heading',
			__( 'Panel heading', 'buddynext' ),
			buddynext_auth_panel_value( 'buddynext_auth_panel_heading' ),
			__( 'Shown large on the branding panel. Defaults to your site title.', 'buddynext' )
		);

		$this->render_textarea_row(
			'buddynext_auth_panel_tagline',
			__( 'Panel tagline', 'buddynext' ),
			buddynext_auth_panel_value( 'buddynext_auth_panel_tagline' ),
			__( 'A short line beneath the heading. Defaults to your site tagline.', 'buddynext' ),
			2
		);

		$this->render_textarea_row(
			'buddynext_auth_panel_quote',
			__( 'Featured quote', 'buddynext' ),
			buddynext_auth_panel_value( 'buddynext_auth_panel_quote' ),
			__( 'A short quote shown prominently on the panel (e.g. a welcome line or member testimonial).', 'buddynext' ),
			3
		);

		$this->render_text_row(
			'buddynext_auth_panel_image',
			__( 'Panel banner image URL', 'buddynext' ),
			buddynext_auth_panel_value( 'buddynext_auth_panel_image' ),
			__( 'A full-bleed banner image behind the panel. Defaults to the built-in network-textured gradient.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Spam &amp; Abuse Protection', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_reg_spam_protection',
			__( 'Protect the sign-up form', 'buddynext' ),
			__( 'In-house, no third-party service: a per-IP rate limit, a honeypot field, and a time-trap that rejects implausibly fast or forged submissions. On by default.', 'buddynext' ),
			(bool) get_option( 'buddynext_reg_spam_protection', true )
		);

		$this->render_toggle_row(
			'buddynext_reg_challenge',
			__( 'Show a human-verification question', 'buddynext' ),
			__( 'Adds an accessible "what is three plus five?" question to the sign-up form, verified with a signed token. No images, no cookies, no external captcha. Requires spam protection to be on.', 'buddynext' ),
			(bool) get_option( 'buddynext_reg_challenge', true )
		);

		$this->render_number_row(
			'buddynext_reg_rate_limit',
			__( 'Sign-ups per hour per IP', 'buddynext' ),
			(int) get_option( 'buddynext_reg_rate_limit', 5 ),
			__( 'Maximum sign-up attempts allowed from one IP address per hour. Set to 0 to disable the rate limit.', 'buddynext' ),
			0,
			100
		);

		$this->close_section();

		$this->open_section( __( 'Access Restrictions', 'buddynext' ) );

		$this->render_textarea_row(
			'buddynext_allowed_domains',
			__( 'Allowed email domains', 'buddynext' ),
			(string) get_option( 'buddynext_allowed_domains', '' ),
			__( 'One domain per line (e.g. mycompany.com). When set, only addresses from these domains can register. Leave blank to allow all domains.', 'buddynext' ),
			4,
			400
		);

		$invite_url = admin_url( 'admin.php?page=buddynext-members&tab=invites' );
		?>
		<div class="bn-field">
			<span class="bn-field-label" id="bn-invite-mgmt-label">
				<?php esc_html_e( 'Invite management', 'buddynext' ); ?>
			</span>
			<a href="<?php echo esc_url( $invite_url ); ?>"
				class="bn-btn"
				data-variant="secondary"
				data-size="sm"
				aria-describedby="bn-invite-mgmt-hint"
				aria-labelledby="bn-invite-mgmt-label bn-invite-mgmt-action">
				<span id="bn-invite-mgmt-action">
					<?php esc_html_e( 'Manage invitations', 'buddynext' ); ?>
				</span>
				<?php buddynext_icon( 'external-link', 'bn-btn__icon' ); ?>
			</a>
			<span class="bn-field-hint" id="bn-invite-mgmt-hint">
				<?php esc_html_e( 'Create, resend, and revoke invitations. Active in Invite Only mode.', 'buddynext' ); ?>
			</span>
		</div>
		<?php

		$this->close_section();

		// ── Social login (OAuth2) ──────────────────────────────────────────
		$this->open_section( __( 'Social Login', 'buddynext' ) );
		$social = (array) get_option( 'buddynext_social_login', array() );
		?>
		<p class="bn-field-hint bn-social-intro">
			<?php esc_html_e( 'Let people sign in with an account they already have. For each network you want, create a free "developer app" on their site, paste the two keys it gives you (a Client ID and a Client Secret), and copy the redirect link below back into their app. Once enabled, a button appears on your Login and Sign-up screens. No coding required.', 'buddynext' ); ?>
		</p>
		<?php
		foreach ( \BuddyNext\Auth\SocialLogin::get_providers() as $pid => $def ) {
			$cfg      = isset( $social[ $pid ] ) && is_array( $social[ $pid ] ) ? $social[ $pid ] : array();
			$enabled  = ! empty( $cfg['enabled'] );
			$cid      = isset( $cfg['client_id'] ) ? (string) $cfg['client_id'] : '';
			$secret   = isset( $cfg['client_secret'] ) ? (string) $cfg['client_secret'] : '';
			$has_keys = '' !== $cid && '' !== $secret;
			$cb       = \BuddyNext\Auth\SocialLogin::callback_url( $pid );
			$label    = (string) ( $def['label'] ?? ucfirst( $pid ) );
			$icon     = (string) ( $def['icon'] ?? 'globe' );
			$console  = (string) ( $def['console_url'] ?? '' );
			$steps    = isset( $def['setup_steps'] ) && is_array( $def['setup_steps'] ) ? $def['setup_steps'] : array();
			$cb_id    = 'bn-redir-' . sanitize_key( $pid );

			if ( $enabled && $has_keys ) {
				$status_class = 'is-ready';
				$status_text  = __( 'Active', 'buddynext' );
			} elseif ( $has_keys ) {
				$status_class = 'is-paused';
				$status_text  = __( 'Configured (off)', 'buddynext' );
			} else {
				$status_class = 'is-empty';
				$status_text  = __( 'Not set up', 'buddynext' );
			}
			?>
			<div class="bn-social-card">
				<div class="bn-social-card__head">
					<span class="bn-social-card__icon"><?php echo \BuddyNext\Core\IconService::render( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-sanitized via wp_kses(). ?></span>
					<span class="bn-social-card__name"><?php echo esc_html( $label ); ?></span>
					<span class="bn-social-card__status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span>
					<label class="bn-toggle-inline bn-social-card__toggle">
						<input type="checkbox"
							name="<?php echo esc_attr( 'buddynext_social_login[' . $pid . '][enabled]' ); ?>"
							value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Show this button', 'buddynext' ); ?>
					</label>
				</div>

				<div class="bn-social-card__body">
					<div class="bn-field">
						<label for="<?php echo esc_attr( 'bn-cid-' . $pid ); ?>"><?php esc_html_e( 'Client ID', 'buddynext' ); ?></label>
						<input type="text" class="bn-input" id="<?php echo esc_attr( 'bn-cid-' . $pid ); ?>"
							name="<?php echo esc_attr( 'buddynext_social_login[' . $pid . '][client_id]' ); ?>"
							value="<?php echo esc_attr( $cid ); ?>"
							placeholder="<?php esc_attr_e( 'Paste the Client ID here', 'buddynext' ); ?>"
							autocomplete="off" spellcheck="false" />
					</div>
					<div class="bn-field">
						<label for="<?php echo esc_attr( 'bn-sec-' . $pid ); ?>"><?php esc_html_e( 'Client Secret', 'buddynext' ); ?></label>
						<input type="password" class="bn-input" id="<?php echo esc_attr( 'bn-sec-' . $pid ); ?>"
							name="<?php echo esc_attr( 'buddynext_social_login[' . $pid . '][client_secret]' ); ?>"
							value="<?php echo esc_attr( $secret ); ?>"
							placeholder="<?php esc_attr_e( 'Paste the Client Secret here', 'buddynext' ); ?>"
							autocomplete="off" spellcheck="false" />
					</div>
					<div class="bn-field">
						<label for="<?php echo esc_attr( $cb_id ); ?>">
							<?php
							/* translators: %s: provider name (e.g. Google). */
							echo esc_html( sprintf( __( 'Redirect link (paste this into %s)', 'buddynext' ), $label ) );
							?>
						</label>
						<div class="bn-copy-row">
							<input type="text" class="bn-input" id="<?php echo esc_attr( $cb_id ); ?>" value="<?php echo esc_attr( $cb ); ?>" readonly onfocus="this.select()" />
							<button type="button" class="bn-btn bn-copy-btn" data-variant="secondary" data-size="sm" data-bn-copy="<?php echo esc_attr( $cb_id ); ?>"><?php esc_html_e( 'Copy', 'buddynext' ); ?></button>
						</div>
					</div>

					<?php if ( ! empty( $steps ) ) : ?>
						<details class="bn-social-help">
							<summary>
								<?php
								/* translators: %s: provider name. */
								echo esc_html( sprintf( __( 'How to get your %s keys', 'buddynext' ), $label ) );
								?>
							</summary>
							<ol class="bn-social-help__steps">
								<?php foreach ( $steps as $step ) : ?>
									<li><?php echo esc_html( (string) $step ); ?></li>
								<?php endforeach; ?>
							</ol>
							<?php if ( '' !== $console ) : ?>
								<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( $console ); ?>" target="_blank" rel="noopener noreferrer">
									<?php
									/* translators: %s: provider name. */
									echo esc_html( sprintf( __( 'Open the %s developer site', 'buddynext' ), $label ) );
									?>
									<?php buddynext_icon( 'external-link', 'bn-btn__icon' ); ?>
								</a>
							<?php endif; ?>
						</details>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
		$this->close_section();
	}

	/**
	 * Render the Social settings tab.
	 *
	 * @return void
	 */
	private function render_tab_social(): void {
		$this->open_section( __( 'Activity Feed', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_default_post_privacy',
			__( 'Default post visibility', 'buddynext' ),
			(string) get_option( 'buddynext_default_post_privacy', 'public' ),
			array(
				'public'      => __( 'Public', 'buddynext' ),
				'followers'   => __( 'Followers only', 'buddynext' ),
				'connections' => __( 'Connections only', 'buddynext' ),
				'private'     => __( 'Only me', 'buddynext' ),
			),
			__( 'Members can override this in their own post composer.', 'buddynext' )
		);

		$this->render_toggle_row(
			'buddynext_allow_polls',
			__( 'Allow polls', 'buddynext' ),
			__( 'Members can attach a poll to their posts.', 'buddynext' ),
			'0' !== (string) get_option( 'buddynext_allow_polls', '1' )
		);

		$this->render_toggle_row(
			'buddynext_allow_shares',
			__( 'Allow re-shares', 'buddynext' ),
			__( 'Members can share other members\' posts to their own feed.', 'buddynext' ),
			'0' !== (string) get_option( 'buddynext_allow_shares', '1' )
		);

		$this->render_toggle_row(
			'buddynext_allow_bookmarks',
			__( 'Allow bookmarks', 'buddynext' ),
			__( 'Members can save posts to a private bookmarks list.', 'buddynext' ),
			'0' !== (string) get_option( 'buddynext_allow_bookmarks', '1' )
		);

		$this->render_toggle_row(
			'buddynext_enable_link_preview',
			__( 'Enable link previews', 'buddynext' ),
			__( 'When a post contains a URL, fetch and display its Open Graph preview (title, image, description).', 'buddynext' ),
			(bool) get_option( 'buddynext_enable_link_preview', true )
		);

		$this->render_toggle_row(
			'buddynext_enable_emoji_picker',
			__( 'Enable emoji picker', 'buddynext' ),
			__( 'Show the emoji picker button in the post composer and comment editor.', 'buddynext' ),
			(bool) get_option( 'buddynext_enable_emoji_picker', true )
		);

		$this->render_number_row(
			'buddynext_post_edit_window',
			__( 'Post edit window (minutes)', 'buddynext' ),
			(int) get_option( 'buddynext_post_edit_window', 60 ),
			__( 'How many minutes after posting a member can edit their post. Set to 0 for no limit.', 'buddynext' ),
			0
		);

		// Reaction palette — which of the canonical reactions members may use.
		// This "which six emoji" control only has meaning while the Reactions
		// feature itself is enabled (Settings → Features). When that master toggle
		// is off, the whole reaction surface is removed front-end + REST, so the
		// palette is disabled here with a pointer to the feature toggle.
		$bn_all_reactions        = \BuddyNext\Reactions\ReactionService::REACTION_TYPES;
		$bn_enabled_reactions    = (array) get_option( 'buddynext_enabled_reactions', $bn_all_reactions );
		$bn_features             = function_exists( 'buddynext_service' ) ? buddynext_service( 'features' ) : null;
		$bn_reactions_on         = ! is_object( $bn_features ) || ! method_exists( $bn_features, 'is_enabled' ) || $bn_features->is_enabled( 'reactions' );
		$bn_reaction_field_class = $bn_reactions_on ? 'bn-field bn-reaction-field' : 'bn-field bn-reaction-field is-disabled';
		?>
		<div class="<?php echo esc_attr( $bn_reaction_field_class ); ?>">
			<span class="bn-tl-title"><?php esc_html_e( 'Reactions', 'buddynext' ); ?></span>
			<span class="bn-tl-desc"><?php esc_html_e( 'Choose which reactions members can use on posts and comments. At least one is always kept.', 'buddynext' ); ?></span>
			<?php if ( ! $bn_reactions_on ) : ?>
				<p class="bn-field-note bn-reaction-field__off-note">
					<?php esc_html_e( 'Reactions are turned off under Platform → Features. Enable the Reactions feature there to choose which emoji members can use.', 'buddynext' ); ?>
				</p>
			<?php endif; ?>
			<div class="bn-reaction-palette">
				<?php foreach ( $bn_all_reactions as $bn_reaction ) : ?>
					<label class="bn-reaction-palette__item">
						<input
							type="checkbox"
							name="buddynext_enabled_reactions[]"
							value="<?php echo esc_attr( $bn_reaction ); ?>"
							<?php checked( in_array( $bn_reaction, $bn_enabled_reactions, true ) ); ?>
							<?php disabled( ! $bn_reactions_on ); ?>
						>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService emoji markup is wp_kses'd.
						echo \BuddyNext\Core\IconService::render_emoji( $bn_reaction, 'bn-reaction-palette__emoji' );
						?>
						<span><?php echo esc_html( ucfirst( $bn_reaction ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		$this->close_section();
	}

	/**
	 * Render the Spaces settings tab.
	 *
	 * @return void
	 */
	private function render_tab_spaces(): void {
		// ── Creation & limits ──────────────────────────────────────────────
		$this->open_section( __( 'Creation & limits', 'buddynext' ) );

		// The on/off switch for the Spaces hub lives on the Features tab
		// (FeatureRegistry 'spaces'), which is the single source of truth and
		// already route-guards the hub. No duplicate toggle here.
		$this->render_select_row(
			'buddynext_space_creation_role',
			__( 'Who can create spaces', 'buddynext' ),
			(string) get_option( 'buddynext_space_creation_role', 'member' ),
			array(
				'member' => __( 'Any member', 'buddynext' ),
				'admin'  => __( 'Admins only', 'buddynext' ),
			),
			__( 'Restricting to admins prevents members from creating unmoderated spaces.', 'buddynext' )
		);

		$this->render_number_row(
			'buddynext_space_max_per_member',
			__( 'Max spaces per member', 'buddynext' ),
			(int) get_option( 'buddynext_space_max_per_member', 0 ),
			__( 'Maximum number of spaces a single member can create. Set to 0 for no limit. Admins are exempt.', 'buddynext' ),
			0
		);

		$this->render_toggle_row(
			'buddynext_space_allow_sub',
			__( 'Allow sub-spaces', 'buddynext' ),
			__( 'Let space owners create spaces nested inside their own. Turn off to keep every space top-level.', 'buddynext' ),
			'0' !== (string) get_option( 'buddynext_space_allow_sub', '1' )
		);

		$this->render_number_row(
			'buddynext_space_max_sub_spaces',
			__( 'Max sub-spaces per space', 'buddynext' ),
			(int) get_option( 'buddynext_space_max_sub_spaces', 0 ),
			__( 'Maximum number of sub-spaces a space owner can create inside their space. Set to 0 for no limit.', 'buddynext' ),
			0
		);

		$this->close_section();

		// ── New-space defaults ─────────────────────────────────────────────
		$this->open_section( __( 'New-space defaults', 'buddynext' ) );

		$type_options = array();
		foreach ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->all() as $slug => $cfg ) {
			$type_options[ $slug ] = (string) ( $cfg['label'] ?? ucfirst( $slug ) );
		}
		$this->render_select_row(
			'buddynext_space_default_type',
			__( 'Default visibility for new spaces', 'buddynext' ),
			(string) get_option( 'buddynext_space_default_type', 'open' ),
			$type_options,
			__( 'The visibility a space starts with when created. Owners can still change it per space.', 'buddynext' )
		);

		$category_options = array( '0' => __( '— None —', 'buddynext' ) );
		$spaces_service   = function_exists( 'buddynext_service' ) ? buddynext_service( 'spaces' ) : null;
		if ( is_object( $spaces_service ) && method_exists( $spaces_service, 'get_categories' ) ) {
			foreach ( $spaces_service->get_categories() as $cat_id => $cat_name ) {
				$category_options[ (string) $cat_id ] = $cat_name;
			}
		}
		$this->render_select_row(
			'buddynext_space_default_category',
			__( 'Default category for new spaces', 'buddynext' ),
			(string) (int) get_option( 'buddynext_space_default_category', 0 ),
			$category_options,
			__( 'New spaces without a chosen category are filed here. Manage the list under Spaces → Directory → Categories.', 'buddynext' )
		);

		$this->close_section();

		// Directory columns (member + space) live together under
		// General → Directory columns, so the two layout controls are configured
		// in one place rather than split across tabs.
	}

	/**
	 * Render the Moderation settings tab.
	 *
	 * @return void
	 */
	private function render_tab_moderation(): void {
		$this->open_section( __( 'Post Approval (Pre-Moderation)', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_premod_mode',
			__( 'Hold posts for approval', 'buddynext' ),
			(string) get_option( 'buddynext_premod_mode', 'off' ),
			array(
				'off'         => __( 'Off — every member posts instantly (recommended)', 'buddynext' ),
				'new_members' => __( 'New members only — hold their first posts until approved', 'buddynext' ),
				'links'       => __( 'Posts with links — hold anything containing a URL', 'buddynext' ),
				'all'         => __( 'Everything — hold every post until a moderator approves', 'buddynext' ),
			),
			__( 'Held posts wait in the Moderation > Pending queue and never appear in feeds until approved. Off by default — a community grows by welcoming people, so only turn this up if you start seeing spam. Admins and moderators are never held.', 'buddynext' )
		);

		$this->render_number_row(
			'buddynext_premod_new_member_count',
			__( 'New-member posts to review', 'buddynext' ),
			(int) get_option( 'buddynext_premod_new_member_count', 1 ),
			__( 'When holding "New members only", review this many of a member\'s first posts before they post freely. Used only by the New members mode.', 'buddynext' ),
			1
		);

		$this->close_section();

		$this->open_section( __( 'Auto-Moderation Thresholds', 'buddynext' ) );

		$this->render_number_row(
			'buddynext_auto_hide_threshold',
			__( 'Auto-hide after N reports', 'buddynext' ),
			(int) get_option( 'buddynext_auto_hide_threshold', 5 ),
			__( 'Content is hidden automatically once it reaches this number of reports. Reviewable in the moderation queue.', 'buddynext' ),
			1
		);

		$this->render_number_row(
			'buddynext_mod_queue_alert_threshold',
			__( 'Queue alert threshold', 'buddynext' ),
			(int) get_option( 'buddynext_mod_queue_alert_threshold', 20 ),
			__( 'Send a daily email to admins when the moderation queue exceeds this many unreviewed items. Set to 0 to disable.', 'buddynext' ),
			0
		);

		$this->close_section();

		$this->open_section( __( 'Strike System', 'buddynext' ) );

		$this->render_number_row(
			'buddynext_strike_warn_threshold',
			__( 'Strikes before warning', 'buddynext' ),
			(int) get_option( 'buddynext_strike_warn_threshold', 2 ),
			__( 'A warning email is sent to the member after this many active strikes.', 'buddynext' ),
			1
		);

		$this->render_number_row(
			'buddynext_strike_suspend_threshold',
			__( 'Strikes before suspension', 'buddynext' ),
			(int) get_option( 'buddynext_strike_suspend_threshold', 5 ),
			__( 'The member is automatically suspended after this many active strikes.', 'buddynext' ),
			1
		);

		$this->render_number_row(
			'buddynext_strike_perma_ban_threshold',
			__( 'Strikes before permanent ban', 'buddynext' ),
			(int) get_option( 'buddynext_strike_perma_ban_threshold', 0 ),
			__( 'The member is permanently banned after this many lifetime strikes. Set to 0 to disable automatic permanent bans.', 'buddynext' ),
			0
		);

		$this->close_section();

		$this->open_section( __( 'Content Safeguards', 'buddynext' ) );

		$this->render_textarea_row(
			'buddynext_banned_words',
			__( 'Banned words', 'buddynext' ),
			(string) get_option( 'buddynext_banned_words', '' ),
			__( 'One word or phrase per line. Posts containing any of these are rejected. Case-insensitive substring match.', 'buddynext' ),
			5,
			480
		);

		$this->render_textarea_row(
			'buddynext_banned_hashtags',
			__( 'Banned hashtags', 'buddynext' ),
			(string) get_option( 'buddynext_banned_hashtags', '' ),
			__( 'One hashtag per line (without the # sign). Posts using these tags are rejected.', 'buddynext' ),
			4,
			480
		);

		$this->render_textarea_row(
			'buddynext_blocked_domains',
			__( 'Blocked link domains', 'buddynext' ),
			(string) get_option( 'buddynext_blocked_domains', '' ),
			__( 'One domain per line (e.g. spam.example.com). Posts linking to these domains are rejected.', 'buddynext' ),
			4,
			480
		);

		$this->render_textarea_row(
			'buddynext_blocked_ips',
			__( 'Blocked IP addresses', 'buddynext' ),
			(string) get_option( 'buddynext_blocked_ips', '' ),
			__( 'One IP address per line (IPv4 or IPv6). Members posting or commenting from these addresses are blocked. Invalid entries are dropped on save.', 'buddynext' ),
			4,
			480
		);

		$this->render_number_row(
			'buddynext_post_rate_limit',
			__( 'Post rate limit (per minute)', 'buddynext' ),
			(int) get_option( 'buddynext_post_rate_limit', 10 ),
			__( 'Maximum number of posts a member can create per minute. Set to 0 to disable rate limiting.', 'buddynext' ),
			0
		);

		$this->render_number_row(
			'buddynext_duplicate_post_window',
			__( 'Duplicate post window (minutes)', 'buddynext' ),
			(int) get_option( 'buddynext_duplicate_post_window', 0 ),
			__( 'Hold a post for review when the member has already posted identical content within this many minutes. Set to 0 to disable.', 'buddynext' ),
			0
		);

		$this->render_number_row(
			'buddynext_new_member_post_threshold',
			__( 'New member review threshold', 'buddynext' ),
			(int) get_option( 'buddynext_new_member_post_threshold', 0 ),
			__( 'Posts by members with fewer than this many published posts are held for review. Set to 0 to disable.', 'buddynext' ),
			0
		);

		$this->close_section();
	}

	/**
	 * Render the Notifications settings tab.
	 *
	 * Default notification preferences applied to new user accounts.
	 * Individual users can override these from their own notification settings.
	 *
	 * @return void
	 */
	private function render_tab_notifications(): void {
		$this->open_section( __( 'Default Notification Preferences', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_notif_default_follow',
			__( 'New follower', 'buddynext' ),
			__( 'Notify users by default when someone follows them.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_follow', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_connection',
			__( 'Connection request', 'buddynext' ),
			__( 'Notify users by default when they receive a connection request.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_connection', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_reaction',
			__( 'Reaction on post', 'buddynext' ),
			__( 'Notify users by default when someone reacts to their post.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_reaction', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_comment',
			__( 'Comment on post', 'buddynext' ),
			__( 'Notify users by default when someone comments on their post.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_comment', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_mention',
			__( '@mention in post or comment', 'buddynext' ),
			__( 'Notify users by default when they are mentioned.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_mention', true )
		);

		$this->render_toggle_row(
			'buddynext_notif_default_space_join',
			__( 'New space member', 'buddynext' ),
			__( 'Notify space owners by default when someone joins their space.', 'buddynext' ),
			(bool) get_option( 'buddynext_notif_default_space_join', true )
		);

		$this->close_section();

		$this->open_section( __( 'Email Digest', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_digest_frequency',
			__( 'Digest frequency', 'buddynext' ),
			(string) get_option( 'buddynext_digest_frequency', 'weekly' ),
			array(
				'never'  => __( 'Disabled — no digest emails', 'buddynext' ),
				'daily'  => __( 'Daily', 'buddynext' ),
				'weekly' => __( 'Weekly', 'buddynext' ),
			),
			__( 'How often BuddyNext sends a digest of unread notifications. Individual users can opt out.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Admin Alerts', 'buddynext' ) );

		$this->render_text_row(
			'buddynext_admin_alert_email',
			__( 'Admin alert email', 'buddynext' ),
			(string) get_option( 'buddynext_admin_alert_email', get_option( 'admin_email', '' ) ),
			__( 'Receives daily alerts when the moderation queue or pending registration count is high. Defaults to WordPress admin email.', 'buddynext' ),
			320
		);

		$this->close_section();
	}

	/**
	 * Render the Email settings tab.
	 *
	 * Controls the sender identity and footer for all BuddyNext system emails.
	 *
	 * @return void
	 */
	private function render_tab_email(): void {
		$this->open_section( __( 'Sender Identity', 'buddynext' ) );

		// Surface the EFFECTIVE values (resolvers fall back to site name / admin
		// email) so the fields are never blank and show exactly what every email
		// will actually use.
		$this->render_text_row(
			'buddynext_email_from_name',
			__( 'From name', 'buddynext' ),
			\BuddyNext\Notifications\EmailSender::from_name(),
			__( 'Display name shown in the "From:" field of all community emails. Defaults to your site name.', 'buddynext' ),
			300
		);

		$this->render_text_row(
			'buddynext_email_from_address',
			__( 'From address', 'buddynext' ),
			\BuddyNext\Notifications\EmailSender::from_address(),
			__( 'Sending address for all BuddyNext system emails. Defaults to your admin email; use a verified domain for best deliverability.', 'buddynext' ),
			300
		);

		$this->render_text_row(
			'buddynext_email_reply_to',
			__( 'Reply-To address', 'buddynext' ),
			(string) get_option( 'buddynext_email_reply_to', '' ),
			__( 'Optional. If set, replies to community emails go here instead of the From address. Applied to every BuddyNext email.', 'buddynext' ),
			300
		);

		$this->close_section();

		$this->open_section( __( 'Email Footer', 'buddynext' ) );

		$this->render_textarea_row(
			'buddynext_email_footer_text',
			__( 'Footer text', 'buddynext' ),
			(string) get_option( 'buddynext_email_footer_text', '' ),
			__( 'Appended to the bottom of every BuddyNext email. Plain text, plus the placeholders {{site_name}}, {{site_url}}, and {{current_year}}.', 'buddynext' ),
			3,
			540
		);

		$this->close_section();
	}

	/**
	 * Render the Privacy & Data settings tab.
	 *
	 * Controls data retention, member-initiated data export, and account deletion behaviour.
	 *
	 * @return void
	 */
	private function render_tab_privacy(): void {
		$this->open_section( __( 'Search Engine Indexing', 'buddynext' ) );

		$this->render_select_row(
			'buddynext_google_indexing',
			__( 'Allow search engines to index', 'buddynext' ),
			(string) get_option( 'buddynext_google_indexing', 'public_posts' ),
			array(
				'all'          => __( 'Everything — public posts, profiles, and spaces', 'buddynext' ),
				'public_posts' => __( 'Public posts only', 'buddynext' ),
				'none'         => __( 'Nothing — noindex all community pages', 'buddynext' ),
			),
			__( 'Controls the robots meta tag on BuddyNext front-end pages. Profiles and spaces always respect their own privacy settings regardless of this setting.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Cookie Consent', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_cookie_consent',
			__( 'Show cookie consent notice', 'buddynext' ),
			__( 'Display a consent banner on first visit. Required in some jurisdictions (EU/GDPR). BuddyNext itself sets only functional cookies.', 'buddynext' ),
			(bool) get_option( 'buddynext_cookie_consent', false )
		);

		$this->close_section();

		$this->open_section( __( 'Data Retention', 'buddynext' ) );

		$this->render_number_row(
			'buddynext_data_retention_days',
			__( 'Activity log retention (days)', 'buddynext' ),
			(int) get_option( 'buddynext_data_retention_days', 365 ),
			__( 'BuddyNext activity log entries older than this are purged automatically. Set to 0 to retain indefinitely.', 'buddynext' ),
			0,
			3650
		);

		$this->close_section();

		$this->open_section( __( 'Member Rights', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_allow_data_export',
			__( 'Allow members to export their data', 'buddynext' ),
			__( 'Adds a "Download my data" option on member profile settings. Generates a JSON archive of posts, reactions, and profile fields.', 'buddynext' ),
			(bool) get_option( 'buddynext_allow_data_export', true )
		);

		$this->render_toggle_row(
			'buddynext_allow_account_deletion',
			__( 'Allow members to delete their account', 'buddynext' ),
			__( 'Adds a "Delete account" option on member profile settings. Admins can always delete accounts regardless of this setting.', 'buddynext' ),
			(bool) get_option( 'buddynext_allow_account_deletion', true )
		);

		$this->render_toggle_row(
			'buddynext_anonymize_on_delete',
			__( 'Anonymise posts on account deletion', 'buddynext' ),
			__( 'When enabled, posts by deleted members are reassigned to an anonymous author rather than hard-deleted. Preserves community discussion threads.', 'buddynext' ),
			(bool) get_option( 'buddynext_anonymize_on_delete', true )
		);

		$this->close_section();
	}

	/**
	 * Render the Integrations settings tab.
	 *
	 * Shows the status of each addon and the cross-plugin feature toggles that
	 * are configured here (per spec 16-admin-settings.md — Integrations section).
	 *
	 * @return void
	 */
	private function render_tab_integrations(): void {
		// Companion catalog — one declarative source of truth (CompanionRegistry).
		// Each row resolves to a real, state-aware action: Active features show
		// "Installed"; installed-but-inactive get a one-click Activate; not-installed
		// get a one-click "Install free" that pulls the plugin straight from the EDD
		// store (CompanionInstaller, install_plugins-gated). No more dead upload links.
		$companions   = \BuddyNext\Integrations\CompanionRegistry::all();
		$can_install  = current_user_can( 'install_plugins' );
		$can_activate = current_user_can( 'activate_plugins' );

		$this->open_section( __( 'Companion plugins', 'buddynext' ) );
		?>
		<div class="bn-addon-list"
			data-bn-companions
			data-rest="<?php echo esc_url( rest_url( 'buddynext/v1/companions/install' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-i18n-installing="<?php esc_attr_e( 'Installing…', 'buddynext' ); ?>"
			data-i18n-installed="<?php esc_attr_e( 'Installed — reloading…', 'buddynext' ); ?>"
			data-i18n-failed="<?php esc_attr_e( 'Install failed.', 'buddynext' ); ?>"
			data-i18n-network="<?php esc_attr_e( 'Install failed — network error.', 'buddynext' ); ?>">
			<?php
			foreach ( $companions as $bn_slug => $bn_c ) :
				$bn_status   = \BuddyNext\Integrations\CompanionRegistry::status( (string) $bn_slug );
				$bn_label    = (string) ( $bn_c['label'] ?? '' );
				$bn_why      = (string) ( $bn_c['why'] ?? '' );
				$bn_store    = (string) ( $bn_c['store_url'] ?? '' );
				$bn_basename = (string) ( $bn_c['free']['basename'] ?? '' );
				?>
			<div class="bn-addon-row" data-status="<?php echo esc_attr( $bn_status ); ?>" data-slug="<?php echo esc_attr( $bn_slug ); ?>">
				<span class="bn-addon-row__status">
					<?php if ( 'active' === $bn_status ) : ?>
						<span class="bn-badge" data-tone="success"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
					<?php elseif ( 'inactive' === $bn_status ) : ?>
						<span class="bn-badge"><?php esc_html_e( 'Inactive', 'buddynext' ); ?></span>
					<?php else : ?>
						<span class="bn-badge"><?php esc_html_e( 'Not installed', 'buddynext' ); ?></span>
					<?php endif; ?>
				</span>
				<div class="bn-addon-row__meta">
					<strong class="bn-addon-row__label"><?php echo esc_html( $bn_label ); ?></strong>
					<p class="bn-addon-row__desc"><?php echo esc_html( $bn_why ); ?></p>
					<span class="bn-companion-msg" role="status" aria-live="polite"></span>
				</div>
				<span class="bn-addon-row__actions">
					<?php if ( 'active' === $bn_status ) : ?>
						<span class="bn-badge" data-tone="muted"><?php esc_html_e( 'Installed', 'buddynext' ); ?></span>
					<?php elseif ( 'inactive' === $bn_status && $can_activate && '' !== $bn_basename ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $bn_basename ) . '&plugin_status=all' ), 'activate-plugin_' . $bn_basename ) ); ?>"
							class="bn-addon-row__action"><?php esc_html_e( 'Activate', 'buddynext' ); ?></a>
					<?php elseif ( 'not_installed' === $bn_status && $can_install ) : ?>
						<button type="button" class="bn-addon-row__action bn-companion-install" data-slug="<?php echo esc_attr( $bn_slug ); ?>">
							<?php esc_html_e( 'Install free', 'buddynext' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( '' !== $bn_store ) : ?>
						<a href="<?php echo esc_url( $bn_store ); ?>" class="bn-addon-row__action bn-addon-row__action--ghost"
							target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Pro', 'buddynext' ); ?></a>
					<?php endif; ?>
				</span>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
		// The companion installer behaviour lives in assets/js/admin/settings.js
		// (initCompanions), wired to the data-* attributes on [data-bn-companions]
		// above. No inline script — see the UX-audit F2 rule.
		$this->close_section();

		$this->open_section( __( 'Jetonomy Settings', 'buddynext' ) );

		$this->render_toggle_row(
			'buddynext_jetonomy_feed_sync',
			__( 'Surface new Jetonomy discussions in activity feed', 'buddynext' ),
			__( 'When enabled, new Jetonomy forum posts appear as feed cards in the BuddyNext activity feed. On by default. Can be overridden per space.', 'buddynext' ),
			'0' !== (string) get_option( 'buddynext_jetonomy_feed_sync', '1' )
		);

		$this->close_section();
	}

	/**
	 * Render the Webhooks settings tab.
	 *
	 * Two sections: the shared HMAC secret used for outbound signing +
	 * inbound verification, and the endpoint manager (list / add / test
	 * / delete) wired to the OutboundWebhookService REST API.
	 *
	 * @return void
	 */
	private function render_tab_webhooks(): void {
		$this->open_section( __( 'Webhook Secret', 'buddynext' ) );

		$webhook_secret = (string) get_option( self::OPTION_WEBHOOK_SECRET, '' );
		$has_secret     = '' !== $webhook_secret;
		?>
		<div class="bn-field" data-bn-secret-group>
			<label for="bn-webhook-secret"><?php esc_html_e( 'Shared Secret', 'buddynext' ); ?></label>
			<div class="bn-input-group">
				<input type="password"
						id="bn-webhook-secret"
						name="<?php echo esc_attr( self::OPTION_WEBHOOK_SECRET ); ?>"
						value="<?php echo esc_attr( $webhook_secret ); ?>"
						class="bn-text-input"
						autocomplete="new-password"
						spellcheck="false">
				<button type="button"
						class="bn-btn"
						data-variant="secondary"
						data-bn-secret-reveal="bn-webhook-secret"
						data-show-label="<?php esc_attr_e( 'Show', 'buddynext' ); ?>"
						data-hide-label="<?php esc_attr_e( 'Hide', 'buddynext' ); ?>"
						aria-pressed="false"><?php esc_html_e( 'Show', 'buddynext' ); ?></button>
				<button type="button"
						class="bn-btn"
						data-variant="secondary"
						data-bn-copy="bn-webhook-secret"><?php esc_html_e( 'Copy', 'buddynext' ); ?></button>
				<button type="button"
						class="bn-btn"
						data-variant="secondary"
						data-bn-secret-generate="bn-webhook-secret"
						data-generated-label="<?php esc_attr_e( 'New secret generated. Click Save Settings to apply, then copy it into your receiving service.', 'buddynext' ); ?>">
					<?php echo $has_secret ? esc_html__( 'Rotate', 'buddynext' ) : esc_html__( 'Generate', 'buddynext' ); ?>
				</button>
			</div>
			<span class="bn-field-hint">
				<?php esc_html_e( 'A shared secret BuddyNext uses to sign outgoing webhooks (HMAC-SHA256) and to verify inbound access requests at POST buddynext/v1/webhook/access. Click Generate for a strong secret, copy it into your receiving service (Slack, Zapier, your endpoint), then Save. Rotating invalidates the old value until you update it there. Leave blank to disable signature verification.', 'buddynext' ); ?>
			</span>
			<span class="bn-secret-msg" role="status" aria-live="polite" data-bn-secret-msg></span>
		</div>
		<?php
		$this->close_section();

		$this->render_webhook_endpoints();
	}

	/**
	 * Render the registered-endpoints manager card. Pulls live state via
	 * the OutboundWebhookService and exposes a small Add/Delete/Test UI
	 * wired to the existing /webhooks REST routes.
	 *
	 * @return void
	 */
	private function render_webhook_endpoints(): void {
		// The webhook CRUD REST routes only register when the opt-in webhooks
		// feature is enabled (Router gates them on is_enabled('webhooks')).
		// Rendering the endpoint manager when the feature is off would surface
		// Register/Test/Remove/Log buttons whose fetches all 404. Show a pointer
		// to the Features tab instead; the shared-secret field above stays.
		$bn_features = function_exists( 'buddynext_service' ) ? buddynext_service( 'features' ) : null;
		$webhooks_on = ! is_object( $bn_features ) || ! method_exists( $bn_features, 'is_enabled' ) || $bn_features->is_enabled( 'webhooks' );

		if ( ! $webhooks_on ) {
			$this->open_section( __( 'Registered endpoints', 'buddynext' ) );
			$features_url = admin_url( 'admin.php?page=buddynext-platform&tab=features' );
			echo '<div class="bn-card"><p class="bn-field-hint">';
			printf(
				/* translators: %s: link to the Features settings tab. */
				esc_html__( 'Webhooks are turned off. Enable the Webhooks feature in %s to register and manage endpoints.', 'buddynext' ),
				'<a href="' . esc_url( $features_url ) . '">' . esc_html__( 'Platform → Features', 'buddynext' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href is esc_url'd and the link text is esc_html'd.
			);
			echo '</p></div>';
			return;
		}

		$webhooks = function_exists( 'buddynext_service' )
			? (array) buddynext_service( 'webhooks' )->list_all()
			: array();

		$webhook_limit = (int) apply_filters( 'buddynext_outbound_webhook_limit', 1 );
		$at_limit      = $webhook_limit > 0 && count( $webhooks ) >= $webhook_limit;
		$rest_url      = rest_url( 'buddynext/v1/webhooks' );
		$rest_nonce    = wp_create_nonce( 'wp_rest' );

		$this->open_section( __( 'Registered endpoints', 'buddynext' ) );
		?>
		<div class="bn-card"
			data-bn-webhooks
			data-bn-rest-url="<?php echo esc_attr( $rest_url ); ?>"
			data-bn-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>">

			<p class="bn-field-hint">
				<?php
				if ( $at_limit ) {
					printf(
						/* translators: %d: limit count. */
						esc_html__( 'You have %d endpoint registered (Free limit). Pro lifts this cap via the buddynext_outbound_webhook_limit filter.', 'buddynext' ),
						(int) $webhook_limit
					);
				} else {
					esc_html_e( 'Each request is signed with the shared secret above. The host receives a JSON payload with `event`, `payload`, `timestamp`, and a verifying `X-BuddyNext-Signature` header.', 'buddynext' );
				}
				?>
			</p>

			<table class="bn-table widefat striped" data-bn-webhook-table>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Endpoint', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Events', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
					</tr>
				</thead>
				<tbody data-bn-webhook-tbody>
				<?php if ( empty( $webhooks ) ) : ?>
					<tr data-bn-webhook-empty>
						<td colspan="5"><?php esc_html_e( 'No webhooks registered yet.', 'buddynext' ); ?></td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $webhooks as $hook ) :
						$hook_events = is_array( $hook['events'] ?? null )
							? $hook['events']
							: (array) json_decode( (string) ( $hook['events'] ?? '[]' ), true );
						?>
						<tr data-bn-webhook-row="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>">
							<td>
								<strong><?php echo esc_html( (string) ( $hook['label'] ?? '' ) ); ?></strong><br>
								<code class="bn-ep-code"><?php echo esc_html( (string) ( $hook['url'] ?? '' ) ); ?></code>
							</td>
							<td>
								<?php echo esc_html( implode( ', ', array_map( 'sanitize_key', $hook_events ) ) ); ?>
							</td>
							<td>
								<?php
								if ( ! empty( $hook['is_active'] ) ) {
									echo '<span class="bn-badge" data-tone="success">' . esc_html__( 'Active', 'buddynext' ) . '</span>';
								} else {
									echo '<span class="bn-badge" data-tone="warn">' . esc_html__( 'Disabled', 'buddynext' ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( (string) ( $hook['created_at'] ?? '' ) ); ?></td>
							<td>
								<button type="button"
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									data-bn-webhook-test="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>"
								><?php esc_html_e( 'Send test', 'buddynext' ); ?></button>
								<button type="button"
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									data-bn-webhook-log="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>"
									aria-expanded="false"
								><?php esc_html_e( 'View log', 'buddynext' ); ?></button>
								<button type="button"
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									data-bn-webhook-remove="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>"
								><?php esc_html_e( 'Remove', 'buddynext' ); ?></button>
							</td>
						</tr>
						<tr class="bn-webhook-log-row" data-bn-webhook-log-row="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>" hidden>
							<td colspan="5" class="bn-webhook-log-cell"></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php
			$catalogue = array(
				'member.registered'      => __( 'New member registered', 'buddynext' ),
				'member.verified'        => __( 'Member email verified', 'buddynext' ),
				'user.suspended'         => __( 'Member suspended', 'buddynext' ),
				'user.unsuspended'       => __( 'Member unsuspended', 'buddynext' ),
				'member.ability_granted' => __( 'Member ability granted', 'buddynext' ),
				'member.ability_revoked' => __( 'Member ability revoked', 'buddynext' ),
				'post.created'           => __( 'New post created', 'buddynext' ),
				'post.deleted'           => __( 'Post deleted', 'buddynext' ),
				'comment.created'        => __( 'New comment created', 'buddynext' ),
				'reaction.added'         => __( 'Reaction added', 'buddynext' ),
				'user.followed'          => __( 'New follow', 'buddynext' ),
				'connection.accepted'    => __( 'Connection accepted', 'buddynext' ),
				'space.joined'           => __( 'Space joined', 'buddynext' ),
				'space.left'             => __( 'Space left', 'buddynext' ),
			);
			?>

			<div class="bn-field" style="margin-top:16px;">
				<label for="bn-webhook-add-url"><?php esc_html_e( 'New endpoint URL', 'buddynext' ); ?></label>
				<input type="url"
					id="bn-webhook-add-url"
					class="bn-input bn-text-input regular-text"
					placeholder="https://example.com/webhook"
					data-bn-webhook-url
					<?php echo $at_limit ? 'disabled' : ''; ?>>
				<span class="bn-field-hint"><?php esc_html_e( 'HTTPS endpoint that receives the JSON payload.', 'buddynext' ); ?></span>
			</div>

			<fieldset class="bn-field" <?php echo $at_limit ? 'disabled' : ''; ?>>
				<legend><?php esc_html_e( 'Events to forward', 'buddynext' ); ?></legend>
				<?php foreach ( $catalogue as $slug => $label ) : ?>
					<label class="bn-webhook-event-row">
						<input type="checkbox"
							value="<?php echo esc_attr( $slug ); ?>"
							data-bn-webhook-event
							<?php echo $at_limit ? 'disabled' : ''; ?>>
						<?php echo esc_html( $label ); ?>
						<code><?php echo esc_html( $slug ); ?></code>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="bn-field">
				<button type="button"
					class="bn-btn"
					data-variant="primary"
					data-bn-webhook-add
					<?php echo $at_limit ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Register endpoint', 'buddynext' ); ?>
				</button>
				<span class="bn-field-hint" role="status" data-bn-webhook-status aria-live="polite"></span>
			</div>
		</div>
		<?php
		$this->close_section();
	}
}
