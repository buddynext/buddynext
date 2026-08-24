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

use BuddyNext\Admin\Settings\Field;
use BuddyNext\Admin\Settings\Section;
use BuddyNext\Admin\Settings\SettingsDriver;
use BuddyNext\Admin\Settings\SettingsRegistry;
use BuddyNext\Contracts\ProvidesSettings;
use BuddyNext\Privacy\CookieConsentService;

/**
 * Registers and renders the BuddyNext admin settings page.
 */
class Settings extends AdminPageBase implements ProvidesSettings {

	/**
	 * Tabs whose option rows are declared via settings_fields() and rendered by
	 * the descriptor-driven render_sections() path. Tabs not listed here keep a
	 * bespoke render_tab_*() method (Registration, Webhooks, Features) but still
	 * declare their options in settings_fields() for registration + search.
	 *
	 * @var string[]
	 */
	private const DESCRIPTOR_TABS = array( 'social', 'spaces', 'moderation', 'notifications', 'email', 'privacy' );

	/**
	 * Option name for the webhook shared secret.
	 */
	private const OPTION_WEBHOOK_SECRET = 'buddynext_webhook_secret';

	/**
	 * Tabs that render NO Settings-API inputs and therefore must not be
	 * wrapped in the options.php form (which always appends a "Save Settings"
	 * button with nothing to save). Integrations is a read-only companion
	 * grid whose actions are one-click REST/nonce links. Tools is NOT listed
	 * because it never routes through this class — ToolsTab renders its own
	 * admin-post action forms.
	 *
	 * @var string[]
	 */
	private const NO_FORM_TABS = array( 'integrations' );

	// Every plain option is declared via the descriptor registry
	// (settings_fields()) and registered by SettingsDriver — including the
	// bespoke-rendered Registration and Webhooks tabs, whose descriptors register
	// + index while their custom UI still renders the controls. The three array
	// options (features, social_login, enabled_reactions) are registered
	// explicitly in register_settings(). There is no SETTINGS_MAP any more.

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Hook the admin menu and settings registration into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		// Declare this page's descriptor-driven options into the shared registry
		// (single source for register/sanitize/save-group + the ⌘K search index).
		SettingsRegistry::register( $this );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_buddynext_apply_recommended', array( $this, 'handle_apply_recommended' ) );
		add_action( 'admin_post_buddynext_dismiss_recommended', array( $this, 'handle_dismiss_recommended' ) );

		// The users_can_register mirror lives in Auth\CoreRegistration, which boots
		// on every request. Hooking it here meant it only ran in wp-admin, so a mode
		// set by WP-CLI or by code never reached the core flag.

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
			'integrations'  => __( 'Add-ons', 'buddynext' ),
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
		$is_descriptor = in_array( $slug, self::DESCRIPTOR_TABS, true );
		$method        = 'render_tab_' . $slug;
		if ( ! $is_descriptor && ! method_exists( $this, $method ) ) {
			echo '<p>' . esc_html__( 'Unknown settings tab.', 'buddynext' ) . '</p>';
			return;
		}

		// Settings API success notice — options.php redirects back here with
		// ?settings-updated=true after a save; surface the confirmation.
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error( 'buddynext_messages', 'buddynext_settings_saved', __( 'Settings saved.', 'buddynext' ), 'updated' );
		}
		settings_errors( 'buddynext_messages' );

		// Tabs with no Settings-API inputs render bare — no options.php form,
		// no save bar. The settings group stays registered in register_settings()
		// so nothing changes for the tabs that keep the form.
		if ( in_array( $slug, self::NO_FORM_TABS, true ) ) {
			$this->$method();
			return;
		}
		?>
		<?php
		// Table/manager screens need the full panel width; the 840px reading cap
		// is for stacked field forms only (owner: "why are we not using full
		// width here" on Webhooks).
		$bn_wide_tabs  = array( 'webhooks' );
		$bn_form_class = in_array( $slug, $bn_wide_tabs, true ) ? 'bn-settings-form bn-settings-form--wide' : 'bn-settings-form';
		?>
		<form method="post" action="options.php" class="<?php echo esc_attr( $bn_form_class ); ?>">
			<?php settings_fields( 'buddynext_' . $slug ); ?>
			<?php // Explicit referer so options.php redirects back to THIS tab after save. WP 6.7+ no longer guarantees settings_fields() emits _wp_http_referer, so without this the redirect drops ?tab= and falls back to General. ?>
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'settings-updated', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
			<?php
			if ( $is_descriptor ) {
				$this->render_sections( $this->sections_for_tab( $slug ) );
			} else {
				$this->$method();
			}
			?>
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

		wp_safe_redirect( AdminHub::tab_url( 'settings', 'general', array( 'bn_recommended' => 'applied' ) ) );
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

		wp_safe_redirect( AdminHub::tab_url( 'settings', 'general', array( 'bn_recommended' => 'dismissed' ) ) );
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
			AdminPageBase::render_notice( __( 'Recommended settings applied. Your community is ready to go.', 'buddynext' ), 'success' );
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
				<a class="bn-btn" data-variant="primary" href="<?php echo esc_url( $apply_url ); ?>"><?php esc_html_e( 'Apply recommended settings', 'buddynext' ); ?></a>
				<a class="bn-btn" data-variant="secondary" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'buddynext' ); ?></a>
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
			array( __( 'Private/gated spaces, post approval, paywall, membership plans', 'buddynext' ), false ),
			array( __( 'Advanced profile fields + custom member labels', 'buddynext' ), false ),
			array( __( 'AI feed ranking + AI content moderation', 'buddynext' ), false ),
			array( __( 'Saved searches + advanced filters', 'buddynext' ), false ),
		);
		$this->open_section( __( 'Free vs Pro', 'buddynext' ) );
		?>
		<table class="bn-table widefat">
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
			'integrations'  => __( 'Wbcom companion plugins that light up extra features - install and activate in one click.', 'buddynext' ),
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
			array( 'wp-i18n' ),
			(string) filemtime( $abs ),
			true
		);
		wp_set_script_translations( 'buddynext-admin-settings', 'buddynext', BUDDYNEXT_DIR . 'languages' );
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
	 * @return string Valid hex colour, or Appearance::DEFAULT_BRAND.
	 */
	public static function sanitize_brand_color( $value ): string {
		$hex = sanitize_hex_color( (string) $value );
		return '' !== (string) $hex ? (string) $hex : \BuddyNext\Theme\Appearance::DEFAULT_BRAND;
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
		// The blocklist refuses sign-in (Auth\LoginGuard), and it does not exempt
		// administrators — a blocklist with a hole for the most valuable accounts
		// is not a blocklist. That makes one typo catastrophic: an owner who pastes
		// their own address in locks themselves out of wp-login.php on their own
		// site, with no way back through the browser. So the mistake is made
		// impossible here rather than survivable later: the saving user's current
		// address is dropped from the list, and they are told why.
		$own = \BuddyNext\Moderation\SafeguardService::client_ip();

		$parts   = preg_split( '/[\r\n,]+/', (string) $value );
		$out     = array();
		$refused = false;
		foreach ( is_array( $parts ) ? $parts : array() as $line ) {
			$ip = trim( (string) $line );
			if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) || in_array( $ip, $out, true ) ) {
				continue;
			}
			if ( '' !== $own && $ip === $own ) {
				$refused = true;
				continue;
			}
			$out[] = $ip;
		}

		if ( $refused ) {
			add_settings_error(
				'buddynext_blocked_ips',
				'bn_blocked_ips_self',
				sprintf(
					/* translators: %s: the administrator's own IP address. */
					__( 'Your own address (%s) was removed from the blocked list. Blocking it would have locked you out of your own site — the blocklist refuses sign-in, and it does not make an exception for administrators.', 'buddynext' ),
					$own
				),
				'warning'
			);
		}

		return implode( "\n", $out );
	}

	/**
	 * AdminHub section key the settings tabs render under (for tab_url()).
	 *
	 * @return string
	 */
	public function settings_page_section(): string {
		return 'settings';
	}

	/**
	 * Descriptor declaration for every plain option across all tabs.
	 *
	 * Single source of truth: register/sanitize, save-grouping, and the ⌘K search
	 * index all derive from this for every option. DESCRIPTOR_TABS also render
	 * from it; the bespoke tabs (Registration, Webhooks, Features) render their
	 * own controls but still declare here so their options register + are found.
	 *
	 * @return Section[]
	 */
	public function settings_fields(): array {
		return array_merge(
			$this->fields_general(),
			$this->fields_registration(),
			$this->fields_social(),
			$this->fields_spaces(),
			$this->fields_moderation(),
			$this->fields_notifications(),
			$this->fields_email(),
			$this->fields_privacy(),
			$this->fields_webhooks(),
			$this->fields_features()
		);
	}

	/**
	 * Privacy & Data option descriptors.
	 *
	 * @return Section[]
	 */
	private function fields_privacy(): array {
		return array(
			new Section(
				'privacy',
				__( 'Private Community', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_private_community',
							'type'    => 'toggle',
							'label'   => __( 'Require login to view the community', 'buddynext' ),
							'hint'    => __( 'When on, every BuddyNext page — feed, members, profiles, spaces, notifications, settings, search — and its REST data require login; logged-out visitors are sent to the login page. Only the login / register / password-reset page stays public. Use this for a fully private, members-only community.', 'buddynext' ),
							'default' => false,
						)
					),
				)
			),
			new Section(
				'privacy',
				__( 'Search Engine Indexing', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_google_indexing',
							'type'    => 'select',
							'label'   => __( 'Allow search engines to index', 'buddynext' ),
							'default' => 'public_posts',
							'choices' => array(
								'all'          => __( 'Everything — public posts, profiles, and spaces', 'buddynext' ),
								'public_posts' => __( 'Public posts only', 'buddynext' ),
								'none'         => __( 'Nothing — noindex all community pages', 'buddynext' ),
							),
							'hint'    => __( 'Controls the robots meta tag on BuddyNext front-end pages. Profiles and spaces always respect their own privacy settings regardless of this setting.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'privacy',
				__( 'Cookie Consent', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_cookie_consent',
							'type'    => 'toggle',
							'label'   => __( 'Show cookie consent notice', 'buddynext' ),
							'hint'    => __( 'Display a consent banner on first visit. Required in some jurisdictions (EU/GDPR). BuddyNext itself sets only functional cookies.', 'buddynext' ),
							'default' => false,
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_cookie_consent_text',
							'type'    => 'textarea',
							'label'   => __( 'Notice text', 'buddynext' ),
							'hint'    => __( 'Wording shown in the banner. Leave blank to use the default. No effect unless the notice is turned on above.', 'buddynext' ),
							'default' => CookieConsentService::default_message(),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_cookie_consent_accept_label',
							'type'    => 'text',
							'label'   => __( 'Accept button label', 'buddynext' ),
							'hint'    => __( 'Text on the dismiss button. Leave blank for the default ("Got it").', 'buddynext' ),
							'default' => '',
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_cookie_consent_policy_label',
							'type'    => 'text',
							'label'   => __( 'Privacy-policy link label', 'buddynext' ),
							'hint'    => __( 'Text of the link to your privacy policy (shown only when a Privacy Policy page is set in Settings → Privacy). Leave blank for the default ("Privacy policy").', 'buddynext' ),
							'default' => '',
						)
					),
				)
			),
			new Section(
				'privacy',
				__( 'Data Retention', 'buddynext' ),
				array(
					new Field(
						array(
							'key'          => 'buddynext_data_retention_days',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Delete records after a set time', 'buddynext' ),
							'label'        => __( 'Data retention (days)', 'buddynext' ),
							'default'      => 365,
							'min'          => 0,
							'max'          => 3650,
							// Name every table this deletes, and ONLY the ones it actually
							// deletes. It used to promise "read notifications, email log" as
							// well — and it never governed them: LogRetentionService pruned
							// both on its own, shorter window, so an owner could set 365 here
							// and still lose notifications at 60. Those two tables now have
							// their own setting, below, and this hint no longer claims them.
							//
							// An owner cannot consent to a deletion they were never told about,
							// and they cannot rely on a promise the code does not keep.
							'hint'         => __( 'Automatically delete records older than this: activity log, closed moderation reports, and (with Pro) analytics events. Open reports and the moderation log are never deleted.', 'buddynext' ),
						)
					),
					// NOTE: a second control for buddynext_log_retention_days used to sit
					// here, labelled "Notification & email log retention" against the one
					// below's "Notification and email log retention". Two fields, one
					// option, different choice text - so the screen showed the same
					// setting twice, disagreeing with itself, and whichever the owner
					// changed silently moved the other.
					//
					// The survivor is the one below. It keys off
					// LogRetentionService::OPTION rather than repeating the literal, takes
					// its default from DEFAULT_WINDOW, and its hint says the three things
					// that actually surprise people: that it DELETES, that unread
					// notifications are exempt, and that it cannot be undone.
					new Field(
						array(
							'key'     => \BuddyNext\Core\LogRetentionService::OPTION,
							'type'    => 'select',
							'label'   => __( 'Notification and email log retention', 'buddynext' ),
							'default' => (string) \BuddyNext\Core\LogRetentionService::DEFAULT_WINDOW,
							'choices' => array(
								'30' => __( '30 days', 'buddynext' ),
								'60' => __( '60 days (recommended)', 'buddynext' ),
								'90' => __( '90 days', 'buddynext' ),
							),
							// The hint has to say three things, because all three surprise people:
							// that it DELETES, that unread is treated differently, and that it
							// cannot be undone. There is deliberately no "keep forever" option —
							// these two tables are append-only and are the largest on a big site.
							'hint'    => sprintf(
								/* translators: %d: the hard maximum, in days, that unread notifications are kept. */
								__( 'Permanently deletes READ notifications and email-log entries older than this. Unread notifications are always kept for the full %d days, whatever you choose here, so nothing a member has not seen is removed early. Runs once a day in the background. This cannot be undone — these tables are a log, not member content.', 'buddynext' ),
								\BuddyNext\Core\LogRetentionService::UNREAD_MAX_DAYS
							),
						)
					),
				)
			),
			new Section(
				'privacy',
				__( 'Member Rights', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_allow_data_export',
							'type'    => 'toggle',
							'label'   => __( 'Allow members to export their data', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Adds a "Download my data" option on member profile settings. Generates a JSON archive of posts, reactions, and profile fields.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_allow_account_deletion',
							'type'    => 'toggle',
							'label'   => __( 'Allow members to delete their account', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Adds a "Delete account" option on member profile settings. Admins can always delete accounts regardless of this setting.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * General tab option descriptors.
	 *
	 * @return Section[]
	 */
	private function fields_general(): array {
		$dir_cols = array(
			'auto' => __( 'Auto (fit to width)', 'buddynext' ),
			'2'    => __( '2 columns', 'buddynext' ),
			'3'    => __( '3 columns', 'buddynext' ),
			'4'    => __( '4 columns', 'buddynext' ),
		);
		return array(
			new Section(
				'general',
				__( 'Community Identity', 'buddynext' ),
				array(
					new Field(
						array(
							'key'            => 'buddynext_site_name',
							'type'           => 'text',
							'label'          => __( 'Community Name', 'buddynext' ),
							'hint'           => __( 'Displayed in the site header, emails, and browser title.', 'buddynext' ),
							'value_callback' => static fn() => (string) get_option( 'buddynext_site_name', get_bloginfo( 'name' ) ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_description',
							'type'  => 'textarea',
							'label' => __( 'Community Description', 'buddynext' ),
							'hint'  => __( 'Short description shown on the community landing page and in meta tags.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'general',
				__( 'Discovery', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_public_explore',
							'type'    => 'toggle',
							'label'   => __( 'Public explore feed', 'buddynext' ),
							'hint'    => __( 'Allow guests to browse the explore feed without logging in.', 'buddynext' ),
							'default' => true,
						)
					),
					new Field(
						array(
							'key'               => 'buddynext_media_single_pages',
							'type'              => 'select',
							'label'             => __( 'Media links', 'buddynext' ),
							'default'           => 'activity',
							'choices'           => array(
								'activity'  => __( 'Open the activity it was posted in', 'buddynext' ),
								'dedicated' => __( 'Open a dedicated media page', 'buddynext' ),
							),
							'disabled_callback' => static fn() => ! class_exists( 'WPMediaVerse\\Core\\Plugin' ),
							'hint'              => __( 'Members post media as activity updates. "Open the activity" keeps every media link inside the feed: its /media/ page redirects to the post it was shared in, so media is not exposed as a separate public URL. "Open a dedicated media page" keeps a standalone page per item, for gallery-style sites.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'general',
				__( 'Direct Messaging', 'buddynext' ),
				array(
					new Field(
						array(
							'key'               => 'buddynext_enable_dm',
							'type'              => 'toggle',
							'label'             => __( 'Enable direct messaging', 'buddynext' ),
							'default'           => true,
							'value_callback'    => static fn() => class_exists( 'WPMediaVerse\\Core\\Plugin' ) && (bool) get_option( 'buddynext_enable_dm', true ),
							'disabled_callback' => static fn() => ! class_exists( 'WPMediaVerse\\Core\\Plugin' ),
							'hint_callback'     => static fn() => class_exists( 'WPMediaVerse\\Core\\Plugin' )
								? __( 'Allow members to send private messages. Requires the WPMediaVerse plugin.', 'buddynext' )
								: __( 'Direct Messaging requires the WPMediaVerse plugin. Install and activate it to enable this feature.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_default_dm_access',
							'type'    => 'select',
							'label'   => __( 'Who can DM me (default)', 'buddynext' ),
							'default' => 'everyone',
							'choices' => array(
								'everyone'    => __( 'Everyone', 'buddynext' ),
								'members'     => __( 'Members only', 'buddynext' ),
								'connections' => __( 'Connections only', 'buddynext' ),
								'nobody'      => __( 'No one', 'buddynext' ),
							),
							'hint'    => __( 'Default privacy applied to new accounts. Members can override this in their own privacy settings.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'general',
				__( 'Directory columns', 'buddynext' ),
				array(
					new Field(
						array(
							'key'      => 'buddynext_member_dir_columns',
							'type'     => 'select',
							'label'    => __( 'Member directory columns (desktop)', 'buddynext' ),
							'default'  => '3',
							'choices'  => $dir_cols,
							'sanitize' => array( self::class, 'sanitize_dir_columns' ),
							'hint'     => __( 'How many member cards per row on desktop. A fixed value caps the row and still steps down to fewer columns on tablet and mobile; Auto fits as many as the width allows.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'      => 'buddynext_spaces_dir_columns',
							'type'     => 'select',
							'label'    => __( 'Space directory columns (desktop)', 'buddynext' ),
							'default'  => '3',
							'choices'  => $dir_cols,
							'sanitize' => array( self::class, 'sanitize_dir_columns' ),
							'hint'     => __( 'How many space cards per row on desktop in the Spaces directory. A fixed value caps the row and still steps down on tablet and mobile; Auto fits as many as the width allows.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'general',
				__( 'Community menu', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_enable_community_nav',
							'type'    => 'toggle',
							'label'   => __( 'Auto-place the community menu in your theme', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Drops the Feed / Members / Spaces menu into your theme automatically. Turn off to use your theme\'s own menu instead. To rename, reorder, or hide individual items, use the Navigation tab.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_enable_community_rail',
							'type'    => 'toggle',
							'label'   => __( 'Show the desktop sidebar rail', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'The left navigation rail on desktop hub pages. Turn off to hide the desktop rail while keeping the mobile bottom bar. Only applies when community navigation (above) is on.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_enable_community_mobile_nav',
							'type'    => 'toggle',
							'label'   => __( 'Show the mobile bottom tab bar', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'The bottom navigation bar on mobile hub pages. Turn off to hide the mobile bar while keeping the desktop rail. Only applies when community navigation (above) is on.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * Social tab option descriptors.
	 *
	 * @return Section[]
	 */
	private function fields_social(): array {
		return array(
			new Section(
				'social',
				__( 'Activity Feed', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_default_post_privacy',
							'type'    => 'select',
							'label'   => __( 'Default post visibility', 'buddynext' ),
							'default' => 'public',
							'choices' => array(
								'public'      => __( 'Public', 'buddynext' ),
								'followers'   => __( 'Followers only', 'buddynext' ),
								'connections' => __( 'Connections only', 'buddynext' ),
								'private'     => __( 'Only me', 'buddynext' ),
							),
							'hint'    => __( 'Members can override this in their own post composer.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'      => 'buddynext_allow_polls',
							'type'     => 'toggle',
							'label'    => __( 'Allow polls', 'buddynext' ),
							'default'  => '1',
							'sanitize' => array( self::class, 'sanitize_bool_flag' ),
							'hint'     => __( 'Members can attach a poll to their posts.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'      => 'buddynext_allow_shares',
							'type'     => 'toggle',
							'label'    => __( 'Allow re-shares', 'buddynext' ),
							'default'  => '1',
							'sanitize' => array( self::class, 'sanitize_bool_flag' ),
							'hint'     => __( 'Members can share other members\' posts to their own feed.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'      => 'buddynext_allow_bookmarks',
							'type'     => 'toggle',
							'label'    => __( 'Allow bookmarks', 'buddynext' ),
							'default'  => '1',
							'sanitize' => array( self::class, 'sanitize_bool_flag' ),
							'hint'     => __( 'Members can save posts to a private bookmarks list.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_enable_link_preview',
							'type'    => 'toggle',
							'label'   => __( 'Enable link previews', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'When a post contains a URL, fetch and display its Open Graph preview (title, image, description).', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_enable_emoji_picker',
							'type'    => 'toggle',
							'label'   => __( 'Enable emoji picker', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Show the emoji picker button in the post composer and comment editor.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_feed_new_posts_indicator',
							'type'    => 'toggle',
							'label'   => __( 'Show new-posts indicator', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Show a "new posts" pill on the activity feed when fresh posts arrive. While the feed tab is open it checks for new posts about once a minute (paused when the tab is hidden); turn this off to stop those background checks entirely. Developers can tune the cadence with the buddynext_feed_new_count_interval filter.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_post_edit_window',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Close the edit window after a while', 'buddynext' ),
							'label'        => __( 'Post edit window (minutes)', 'buddynext' ),
							'default'      => 60,
							'min'          => 0,
							'hint'         => __( 'How many minutes after posting a member can edit their post.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'             => 'buddynext_reactions_palette',
							'type'            => 'custom',
							'render_callback' => array( $this, 'render_reaction_palette' ),
						)
					),
				)
			),
			new Section(
				'social',
				__( 'Connections', 'buddynext' ),
				array(
					new Field(
						array(
							'key'      => 'buddynext_connection_require_note',
							'type'     => 'toggle',
							'label'    => __( 'Ask for a note when connecting', 'buddynext' ),
							'default'  => '0',
							'sanitize' => array( self::class, 'sanitize_bool_flag' ),

							/*
							 * The hint has to say when the setting cannot take
							 * effect, because the note has no home of its own: it
							 * is delivered as a direct-message request and nothing
							 * else ever displays it. With messaging inactive the
							 * toggle used to look on while members wrote notes that
							 * reached nobody. The owner is told here, on the control
							 * itself, rather than the member discovering it by
							 * being ignored (Basecamp 10185178801).
							 */
							'hint'     => self::connection_note_hint(),
						)
					),
				)
			),
		);
	}

	/**
	 * Hint for the connection-note toggle, including its delivery dependency.
	 *
	 * @since 1.1.3
	 *
	 * @return string
	 */
	private static function connection_note_hint(): string {
		$hint = __( 'Off (default): one click sends the connection request, like Facebook. On: the member is asked to add a short note with their request, like LinkedIn - and that note is delivered to the recipient as a direct-message request so they can decide whether to engage before accepting.', 'buddynext' );

		if ( ! \BuddyNext\Bridges\WPMediaVerseBridge::can_deliver_connection_note() ) {
			$hint .= ' ' . __( 'Not available right now: the note is delivered through direct messages, and the messaging plugin (WPMediaVerse) is not active. Until it is, members are not asked for a note and connect stays one click - the setting has no effect rather than collecting notes nobody can read.', 'buddynext' );
		}

		return $hint;
	}

	/**
	 * Render the reaction-palette control (bespoke composite, wired to the
	 * buddynext_enabled_reactions array option). Extracted from the former
	 * render_tab_social() so it can be a `custom` field in fields_social().
	 *
	 * @return void
	 */
	public function render_reaction_palette(): void {
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
	}

	/**
	 * Spaces tab option descriptors.
	 *
	 * @return Section[]
	 */
	private function fields_spaces(): array {
		return array(
			new Section(
				'spaces',
				__( 'Creation & limits', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_space_creation_role',
							'type'    => 'select',
							'label'   => __( 'Who can create spaces', 'buddynext' ),
							'default' => 'member',
							'choices' => array(
								'member' => __( 'Any member', 'buddynext' ),
								'admin'  => __( 'Admins only', 'buddynext' ),
							),
							'hint'    => __( 'Restricting to admins prevents members from creating unmoderated spaces.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_space_max_per_member',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Cap how many spaces a member can create', 'buddynext' ),
							'label'        => __( 'Max spaces per member', 'buddynext' ),
							'min'          => 0,
							'hint'         => __( 'Maximum number of spaces a single member can create. Admins are exempt.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'      => 'buddynext_space_allow_sub',
							'type'     => 'toggle',
							'label'    => __( 'Allow sub-spaces', 'buddynext' ),
							'default'  => '1',
							'sanitize' => array( self::class, 'sanitize_bool_flag' ),
							'hint'     => __( 'Let space owners create spaces nested inside their own. Turn off to keep every space top-level.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_space_max_sub_spaces',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Cap sub-spaces per space', 'buddynext' ),
							'label'        => __( 'Max sub-spaces per space', 'buddynext' ),
							'min'          => 0,
							'hint'         => __( 'Maximum number of sub-spaces a space owner can create inside their space.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'spaces',
				__( 'New-space defaults', 'buddynext' ),
				array(
					new Field(
						array(
							'key'              => 'buddynext_space_default_type',
							'type'             => 'select',
							'label'            => __( 'Default visibility for new spaces', 'buddynext' ),
							'default'          => 'open',
							'choices_callback' => static function () {
								$type_options = array();
								foreach ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->all() as $slug => $cfg ) {
									$type_options[ $slug ] = (string) ( $cfg['label'] ?? ucfirst( $slug ) );
								}
								return $type_options;
							},
							'hint'             => __( 'The visibility a space starts with when created. Owners can still change it per space.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'              => 'buddynext_space_default_category',
							'type'             => 'select',
							'label'            => __( 'Default category for new spaces', 'buddynext' ),
							'default'          => 0,
							'value_callback'   => static fn() => (string) (int) get_option( 'buddynext_space_default_category', 0 ),
							'choices_callback' => static function () {
								$category_options = array( '0' => __( '— None —', 'buddynext' ) );
								$spaces_service   = function_exists( 'buddynext_service' ) ? buddynext_service( 'spaces' ) : null;
								if ( is_object( $spaces_service ) && method_exists( $spaces_service, 'get_categories' ) ) {
									foreach ( $spaces_service->get_categories() as $cat_id => $cat_name ) {
										$category_options[ (string) $cat_id ] = $cat_name;
									}
								}
								return $category_options;
							},
							'hint'             => __( 'New spaces without a chosen category are filed here. Manage the list under Spaces → Directory → Categories.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * Moderation tab option descriptors.
	 *
	 * @return Section[]
	 */
	private function fields_moderation(): array {
		return array(
			new Section(
				'moderation',
				__( 'Post Approval (Pre-Moderation)', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_premod_mode',
							'type'    => 'select',
							'label'   => __( 'Hold posts for approval', 'buddynext' ),
							'default' => 'off',
							'choices' => array(
								'off'         => __( 'Off — every member posts instantly (recommended)', 'buddynext' ),
								'new_members' => __( 'New members only — hold their first posts until approved', 'buddynext' ),
								'links'       => __( 'Posts with links — hold anything containing a URL', 'buddynext' ),
								'all'         => __( 'Everything — hold every post until a moderator approves', 'buddynext' ),
							),
							'hint'    => __( 'Held posts wait in the Moderation > Pending queue and never appear in feeds until approved. Off by default — a community grows by welcoming people, so only turn this up if you start seeing spam. Admins and moderators are never held.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_premod_new_member_count',
							'type'    => 'number',
							'label'   => __( 'New-member posts to review', 'buddynext' ),
							'default' => 1,
							'min'     => 1,
							'hint'    => __( 'When holding "New members only", review this many of a member\'s first posts before they post freely. Used only by the New members mode.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'moderation',
				__( 'Auto-Moderation Thresholds', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_auto_hide_threshold',
							'type'    => 'number',
							'label'   => __( 'Auto-hide after N reports', 'buddynext' ),
							'default' => 5,
							'min'     => 1,
							'hint'    => __( 'Content is hidden automatically once it reaches this number of reports. Reviewable in the moderation queue.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_mod_queue_alert_threshold',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Email admins when the queue builds up', 'buddynext' ),
							'label'        => __( 'Queue alert threshold', 'buddynext' ),
							'default'      => 20,
							// 1, not 0: 0 is the value the unticked checkbox stores and
							// means "off". Offering it as a typeable minimum let an owner
							// set a threshold that reads as "alert on everything" and
							// behaves as "alert never".
							'min'          => 1,
							'hint'         => __( 'Send a daily email to admins when the moderation queue exceeds this many unreviewed items.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'moderation',
				__( 'Strike System', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_strike_warn_threshold',
							'type'    => 'number',
							'label'   => __( 'Strikes before warning', 'buddynext' ),
							'default' => 2,
							'min'     => 1,
							'hint'    => __( 'A warning email is sent to the member after this many active strikes.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_strike_suspend_threshold',
							'type'    => 'number',
							'label'   => __( 'Strikes before suspension', 'buddynext' ),
							'default' => 5,
							'min'     => 1,
							'hint'    => __( 'The member is automatically suspended after this many active strikes.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_strike_perma_ban_threshold',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Permanently ban after enough strikes', 'buddynext' ),
							'label'        => __( 'Strikes before permanent ban', 'buddynext' ),
							'default'      => 0,
							'min'          => 0,
							'hint'         => __( 'The member is permanently banned after this many lifetime strikes.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'moderation',
				__( 'Content Safeguards', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => 'buddynext_banned_words',
							'type'  => 'textarea',
							'label' => __( 'Banned words', 'buddynext' ),
							'hint'  => __( 'One word or phrase per line. A post using any of them is rejected. Whole words only, so "art" does not block "start" or "particle". Add * to catch variants: "spam*" also blocks "spammer".', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_banned_hashtags',
							'type'  => 'textarea',
							'label' => __( 'Banned hashtags', 'buddynext' ),
							'hint'  => __( 'One hashtag per line (without the # sign). Posts using these tags are rejected.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_blocked_domains',
							'type'  => 'textarea',
							'label' => __( 'Blocked link domains', 'buddynext' ),
							'hint'  => __( 'One domain per line (e.g. spam.example.com). Posts linking to these domains are rejected.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'      => 'buddynext_blocked_ips',
							'type'     => 'textarea',
							'label'    => __( 'Blocked IP addresses', 'buddynext' ),
							'sanitize' => array( self::class, 'sanitize_ip_list' ),
							'hint'     => __( 'One IP address per line (IPv4 or IPv6). These addresses cannot sign in, register, post, or comment - including on accounts they already hold. Your own address cannot be added. Invalid entries are dropped on save.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_post_rate_limit',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Rate-limit posting', 'buddynext' ),
							'label'        => __( 'Post rate limit (per minute)', 'buddynext' ),
							'default'      => 10,
							'min'          => 0,
							'hint'         => __( 'Maximum number of posts a member can create per minute.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_comment_rate_limit',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Rate-limit commenting', 'buddynext' ),
							'label'        => __( 'Comment rate limit (per minute)', 'buddynext' ),
							'default'      => 30,
							'min'          => 0,
							'hint'         => __( 'Maximum number of comments a member can post per minute.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_duplicate_post_window',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Hold duplicate posts for review', 'buddynext' ),
							'label'        => __( 'Duplicate post window (minutes)', 'buddynext' ),
							'default'      => 0,
							'min'          => 0,
							'hint'         => __( 'Hold a post for review when the member has already posted identical content within this many minutes.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_new_member_post_threshold',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Review posts from new members', 'buddynext' ),
							'label'        => __( 'New member review threshold', 'buddynext' ),
							'default'      => 0,
							'min'          => 0,
							'hint'         => __( 'Posts by members with fewer than this many published posts are held for review.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * Notifications tab option descriptors.
	 *
	 * @return Section[]
	 */
	private function fields_notifications(): array {
		return array(
			new Section(
				'notifications',
				__( 'Default Notification Preferences', 'buddynext' ),
				array(
					new Field(
						array(
							'key'     => 'buddynext_notif_default_follow',
							'type'    => 'toggle',
							'label'   => __( 'New follower', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Notify users by default when someone follows them.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_notif_default_connection',
							'type'    => 'toggle',
							'label'   => __( 'Connection request', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Notify users by default when they receive a connection request.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_notif_default_reaction',
							'type'    => 'toggle',
							'label'   => __( 'Reaction on post', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Notify users by default when someone reacts to their post.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_notif_default_comment',
							'type'    => 'toggle',
							'label'   => __( 'Comment on post', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Notify users by default when someone comments on their post.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_notif_default_mention',
							'type'    => 'toggle',
							'label'   => __( '@mention in post or comment', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Notify users by default when they are mentioned.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'     => 'buddynext_notif_default_space_join',
							'type'    => 'toggle',
							'label'   => __( 'New space member', 'buddynext' ),
							'default' => true,
							'hint'    => __( 'Notify space owners by default when someone joins their space.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'notifications',
				__( 'Email Digest', 'buddynext' ),
				array(
					new Field(
						array(
							'key'            => 'buddynext_digest_frequency',
							'type'           => 'select',
							'label'          => __( 'Digest emails', 'buddynext' ),

							/*
							 * TWO choices, not three. This offered Daily / Weekly / Disabled
							 * and the first two did exactly the same thing: the ONLY code that
							 * reads this option is EmailSender::digests_enabled(), which asks
							 * `'never' !== $value`. Both cron jobs are scheduled unconditionally
							 * (CronScheduler::maybe_schedule for JOB_DAILY_DIGEST and
							 * JOB_WEEKLY_DIGEST), and the cadence a member actually receives
							 * comes from their own `email_freq` preference. So an owner who
							 * picked Daily changed nothing, and the hint - "how often BuddyNext
							 * sends a digest" - described something the control does not do.
							 *
							 * The alternative was to make the site setting real by capping the
							 * per-member preference. That was rejected: the default here is
							 * 'weekly', so on every existing install a cap would silently stop
							 * digests for every member who chose daily - a regression shipped
							 * as a fix. It also is not the model the product follows; the
							 * platform offers cadences and the member picks one.
							 */
							'default'        => 'weekly',
							'value_callback' => static function (): string {
								// Legacy sites store 'daily' here. It is not among the choices
								// any more, so the select would match nothing, preselect the
								// first option, and the next save would write it back - turning
								// digests OFF on a site that had them ON. Normalise on display;
								// stored data is left alone until the owner saves.
								return 'never' === (string) get_option( 'buddynext_digest_frequency', 'weekly' )
									? 'never'
									: 'weekly';
							},
							'choices'        => array(
								'weekly' => __( 'Enabled', 'buddynext' ),
								'never'  => __( 'Disabled — no digest emails', 'buddynext' ),
							),
							'hint'           => __( 'Whether BuddyNext sends digests of unread notifications at all. Each member chooses daily or weekly in their own notification preferences.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'notifications',
				__( 'Admin Alerts', 'buddynext' ),
				array(
					new Field(
						array(
							'key'            => 'buddynext_admin_alert_email',
							'type'           => 'text',
							'label'          => __( 'Admin alert email', 'buddynext' ),
							'sanitize'       => 'sanitize_email',
							'value_callback' => static fn() => (string) get_option( 'buddynext_admin_alert_email', get_option( 'admin_email', '' ) ),
							'hint'           => __( 'Receives daily alerts when the moderation queue or pending registration count is high. Defaults to WordPress admin email.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * Email tab option descriptors.
	 *
	 * @return Section[]
	 */
	private function fields_email(): array {
		return array(
			new Section(
				'email',
				__( 'Sender Identity', 'buddynext' ),
				array(
					new Field(
						array(
							'key'            => 'buddynext_email_from_name',
							'type'           => 'text',
							'label'          => __( 'From name', 'buddynext' ),
							'value_callback' => static fn() => \BuddyNext\Notifications\EmailSender::from_name(),
							'hint'           => __( 'Display name shown in the "From:" field of all community emails. Defaults to your site name.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'            => 'buddynext_email_from_address',
							'type'           => 'text',
							'label'          => __( 'From address', 'buddynext' ),
							'sanitize'       => 'sanitize_email',
							'value_callback' => static fn() => \BuddyNext\Notifications\EmailSender::from_address(),
							'hint'           => __( 'Sending address for all BuddyNext system emails. Defaults to your admin email; use a verified domain for best deliverability.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'      => 'buddynext_email_reply_to',
							'type'     => 'text',
							'label'    => __( 'Reply-To address', 'buddynext' ),
							'sanitize' => 'sanitize_email',
							'hint'     => __( 'Optional. If set, replies to community emails go here instead of the From address. Applied to every BuddyNext email.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'email',
				__( 'Email Footer', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => 'buddynext_email_footer_text',
							'type'  => 'textarea',
							'label' => __( 'Footer text', 'buddynext' ),
							'hint'  => __( 'Appended to the bottom of every BuddyNext email. Plain text, plus the placeholders {{site_name}}, {{site_url}}, and {{current_year}}.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * Registration tab option descriptors.
	 *
	 * The Registration tab keeps its bespoke render_tab_registration() (conditional
	 * email-verify UI, social-login credential cards, legal-page info block), so
	 * these descriptors exist to register + index its options only — never set a
	 * registered default here, so the bespoke render's inline get_option()
	 * fallbacks (some dynamic) are preserved exactly.
	 *
	 * @return Section[]
	 */
	private function fields_registration(): array {
		return array(
			new Section(
				'registration',
				__( 'Registration Settings', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => 'buddynext_reg_mode',
							'type'  => 'select',
							'label' => __( 'Registration Mode', 'buddynext' ),
							'hint'  => __( 'Controls who can create a new account on your community.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_email_verify',
							'type'  => 'toggle',
							'label' => __( 'Require email verification', 'buddynext' ),
							'hint'  => __( 'Ask new members to confirm their email address. Choose how strictly it is enforced below.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_verify_enforcement',
							'type'  => 'select',
							'label' => __( 'How strictly to enforce verification', 'buddynext' ),
							'hint'  => __( 'Restricted (recommended): members can look around but cannot post or comment until they confirm. Full: they cannot use the community at all until they confirm.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_require_terms',
							'type'  => 'toggle',
							'label' => __( 'Require members to accept your terms', 'buddynext' ),
							'hint'  => __( 'Shows a consent checkbox on every sign-up route. On by default.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_reg_ask_name',
							'type'  => 'toggle',
							'label' => __( 'Ask new members for their name', 'buddynext' ),
							'hint'  => __( 'On by default. This is the name other members see. Turn it off only if your community wants handles rather than names.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_reg_ask_username',
							'type'  => 'toggle',
							'label' => __( 'Let members choose their own username', 'buddynext' ),
							'hint'  => __( 'Off by default: a username is generated from their email so nobody has to invent one to join, and they can change it later in Settings. Turn this on to ask for one at sign-up.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_allow_core_registration',
							'type'  => 'toggle',
							'label' => __( 'Also allow the WordPress sign-up form', 'buddynext' ),
							'hint'  => __( 'Off by default: wp-login.php sign-ups are sent to your BuddyNext sign-up page instead. Turn this on if another plugin relies on the WordPress form. It is protected by your settings either way.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'registration',
				__( 'Login &amp; Sign-up Panel', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => 'buddynext_auth_panel_show',
							'type'  => 'toggle',
							'label' => __( 'Show the branding panel', 'buddynext' ),
							'hint'  => __( 'Displays a branded side panel next to the login and sign-up forms.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_auth_panel_heading',
							'type'  => 'text',
							'label' => __( 'Panel heading', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_auth_panel_tagline',
							'type'  => 'textarea',
							'label' => __( 'Panel tagline', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_auth_panel_quote',
							'type'  => 'textarea',
							'label' => __( 'Featured quote', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_auth_panel_image',
							'type'  => 'url',
							'label' => __( 'Panel banner image URL', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_signup_subtitle',
							'type'  => 'text',
							'label' => __( 'Sign-up form subtitle', 'buddynext' ),
							'hint'  => __( 'Shown under "Join the community" on the sign-up form.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'registration',
				__( 'Legal Pages', 'buddynext' ),
				array(
					new Field(
						array(
							'key'      => 'buddynext_terms_page_id',
							'type'     => 'select',
							'label'    => __( 'Terms of Service page', 'buddynext' ),
							'sanitize' => 'absint',
							'hint'     => __( 'Linked from the sign-up consent line.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'registration',
				__( 'Spam &amp; Abuse Protection', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => 'buddynext_2fa_required',
							'type'  => 'select',
							'label' => __( 'Require two-factor authentication', 'buddynext' ),
							'hint'  => __( 'Members are always free to switch two-factor on themselves. This makes it mandatory for the roles you choose.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_reg_spam_protection',
							'type'  => 'toggle',
							'label' => __( 'Protect the sign-up form', 'buddynext' ),
							'hint'  => __( 'In-house rate limit, honeypot, and time-trap. On by default.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_reg_challenge',
							'type'  => 'toggle',
							'label' => __( 'Show a human-verification question', 'buddynext' ),
							'hint'  => __( 'Adds an accessible verification question to the sign-up form.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'          => 'buddynext_reg_rate_limit',
							'type'         => 'optional_limit',
							'toggle_label' => __( 'Rate-limit sign-ups per IP', 'buddynext' ),
							'label'        => __( 'Sign-ups per hour per IP', 'buddynext' ),
							'min'          => 0,
							'max'          => 100,
							'hint'         => __( 'Maximum sign-up attempts from one IP per hour.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'registration',
				__( 'Access Restrictions', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => 'buddynext_allowed_domains',
							'type'  => 'textarea',
							'label' => __( 'Allowed email domains', 'buddynext' ),
							'hint'  => __( 'One domain per line. When set, only these domains can register.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_blocked_email_domains',
							'type'  => 'textarea',
							'label' => __( 'Blocked email domains', 'buddynext' ),
							'hint'  => __( 'One domain per line. Addresses from these domains cannot register.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'registration',
				__( 'Redirects', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => 'buddynext_login_redirect',
							'type'  => 'url',
							'label' => __( 'After login', 'buddynext' ),
							'hint'  => __( 'Where members go after logging in. Blank = activity feed.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_logout_redirect',
							'type'  => 'url',
							'label' => __( 'After logout', 'buddynext' ),
							'hint'  => __( 'Where members go after logging out. Blank = the login page.', 'buddynext' ),
						)
					),
					new Field(
						array(
							'key'   => 'buddynext_onboarding_redirect',
							'type'  => 'url',
							'label' => __( 'After onboarding', 'buddynext' ),
							'hint'  => __( 'Where new members go after onboarding. Blank = their profile.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'registration',
				__( 'Social Login', 'buddynext' ),
				array(
					// Index-only pointer: the social-login provider cards are bespoke,
					// and buddynext_social_login is registered explicitly as an array
					// option. This readonly entry just makes "Social Login" findable.
					new Field(
						array(
							'key'   => 'buddynext_social_login',
							'type'  => 'readonly',
							'label' => __( 'Social Login', 'buddynext' ),
							'hint'  => __( 'Sign in with Google, Facebook, and more.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * Webhooks tab option descriptors.
	 *
	 * The secret keeps its bespoke reveal/copy/generate control in
	 * render_tab_webhooks(); this descriptor registers + indexes it only.
	 *
	 * @return Section[]
	 */
	private function fields_webhooks(): array {
		return array(
			new Section(
				'webhooks',
				__( 'Webhook Secret', 'buddynext' ),
				array(
					new Field(
						array(
							'key'   => self::OPTION_WEBHOOK_SECRET,
							'type'  => 'secret',
							'label' => __( 'Shared Secret', 'buddynext' ),
							'hint'  => __( 'Verifies inbound access requests only. Outgoing webhooks are signed with the per-endpoint secret set under Registered endpoints.', 'buddynext' ),
						)
					),
				)
			),
			new Section(
				'webhooks',
				__( 'Signature verification', 'buddynext' ),
				array(
					// Renders bespoke in render_tab_webhooks() via render_toggle_row();
					// this descriptor registers it (group buddynext_webhooks, boolean
					// sanitize) and indexes it for search. On by default (1.1.6) — the
					// inline get_option( …, true ) fallback means an upgraded site with
					// no row is strict, matching a fresh install; an owner mid-migration
					// turns it OFF here to keep accepting the legacy body-only scheme.
					new Field(
						array(
							'key'   => \BuddyNext\Outbound\AccessWebhookController::OPT_STRICT_SIGNATURES,
							'type'  => 'toggle',
							'label' => __( 'Require replay-proof webhook signatures', 'buddynext' ),
							'hint'  => __( 'On by default: only the timestamped signature scheme (with an X-BuddyNext-Timestamp header) is accepted and the older body-only scheme is rejected. The body-only scheme cannot be replay-checked, so a captured request stays valid indefinitely. Turn this OFF only while migrating a service that still sends body-only signatures, and re-enable it once every caller sends a timestamp.', 'buddynext' ),
						)
					),
				)
			),
		);
	}

	/**
	 * Features tab search pointers — one index-only entry per feature.
	 *
	 * Features are stored in the single buddynext_features array option and
	 * rendered bespoke; these readonly descriptors make each feature findable by
	 * name in ⌘K. Not registered (readonly) and not rendered (Features is not a
	 * DESCRIPTOR_TAB). Returns empty if the feature service is unavailable.
	 *
	 * @return Section[]
	 */
	private function fields_features(): array {
		$registry = function_exists( 'buddynext_service' ) ? buddynext_service( 'features' ) : null;
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'by_group' ) ) {
			return array();
		}
		$fields = array();
		foreach ( $registry->by_group() as $features ) {
			foreach ( (array) $features as $feature ) {
				$slug  = isset( $feature['slug'] ) ? (string) $feature['slug'] : '';
				$label = isset( $feature['label'] ) ? (string) $feature['label'] : '';
				if ( '' === $slug || '' === $label ) {
					continue;
				}
				$fields[] = new Field(
					array(
						'key'   => 'buddynext_feature_' . $slug,
						'type'  => 'readonly',
						'label' => $label,
						'hint'  => isset( $feature['description'] ) ? (string) $feature['description'] : '',
					)
				);
			}
		}
		return array( new Section( 'features', __( 'Features', 'buddynext' ), $fields ) );
	}

	/**
	 * Sections declared for a single tab.
	 *
	 * @param string $tab Tab slug.
	 * @return Section[]
	 */
	private function sections_for_tab( string $tab ): array {
		return array_values(
			array_filter(
				$this->settings_fields(),
				static fn( Section $section ) => $section->tab === $tab
			)
		);
	}

	/**
	 * Register all settings with the WordPress Settings API.
	 *
	 * Every plain option is derived from settings_fields() by SettingsDriver and
	 * registered under its tab's own group (buddynext_{tab}) so a save only
	 * touches the active tab's options. The three array options are registered
	 * explicitly below. Registering here also ensures the sanitize_callback runs
	 * on save even though bespoke tabs render their controls manually.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Every plain option (all tabs, incl. the bespoke-rendered Registration
		// and Webhooks) is declared in settings_fields() and registered here from
		// those descriptors. The three array options below are registered
		// explicitly because they carry bespoke composite UI.
		SettingsDriver::register_page( $this, 'buddynext' );

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
	 * Sanitize the social-login option ([provider => {enabled, credential fields}]).
	 *
	 * The field list per provider comes from the definition's `credentials`
	 * descriptor when present (Apple: client_id, team_id, key_id, private_key)
	 * and defaults to the classic client_id + client_secret pair. Fields the
	 * descriptor flags `secret` render write-only, so a blank submit keeps the
	 * stored value instead of wiping the credential.
	 *
	 * @param mixed $raw Submitted value.
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize_social_login_option( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		// The currently stored config, so a blank (write-only) secret field keeps
		// the saved credential rather than wiping it.
		$existing = (array) get_option( 'buddynext_social_login', array() );

		// Iterate the same provider list the form renders (get_providers()) so a
		// provider can never be dropped on save by drifting from a hardcoded list.
		foreach ( \BuddyNext\Auth\SocialLogin::get_providers() as $id => $def ) {
			$p = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();

			$fields = isset( $def['credentials'] ) && is_array( $def['credentials'] )
				? $def['credentials']
				: array(
					'client_id'     => array( 'type' => 'text' ),
					'client_secret' => array(
						'type'   => 'password',
						'secret' => true,
					),
				);

			$clean = array( 'enabled' => ! empty( $p['enabled'] ) );

			foreach ( $fields as $field => $descriptor ) {
				$is_textarea = 'textarea' === (string) ( $descriptor['type'] ?? 'text' );
				$submitted   = isset( $p[ $field ] )
					? ( $is_textarea ? sanitize_textarea_field( (string) $p[ $field ] ) : sanitize_text_field( (string) $p[ $field ] ) )
					: '';

				if ( ! empty( $descriptor['secret'] ) ) {
					// Write-only: the field renders empty, so a blank submit means
					// "leave it alone" — not "wipe my credentials".
					$stored = isset( $existing[ $id ][ $field ] ) ? (string) $existing[ $id ][ $field ] : '';

					// A pasted private key that OpenSSL cannot read would break the
					// provider silently at the token exchange, days later. Refuse it
					// NOW, tell the owner, and keep whatever worked before.
					if ( '' !== $submitted && 'private_key' === $field && false === openssl_pkey_get_private( $submitted ) ) {
						add_settings_error(
							'buddynext_social_login',
							'bn_social_bad_p8',
							__( 'The pasted Apple .p8 private key could not be read, so the previous key was kept. Paste the full file contents, including the BEGIN and END lines.', 'buddynext' )
						);
						$submitted = '';
					}

					$clean[ $field ] = '' !== $submitted ? $submitted : $stored;
				} else {
					$clean[ $field ] = $submitted;
				}
			}

			$out[ $id ] = $clean;
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
		// Settings moved off the top-level `buddynext` slug (now the Get Started
		// Home) to its own section slug; resolve it through the map so this base
		// URL follows the section rather than hard-coding the old landing slug.
		$base_url = admin_url( 'admin.php?page=' . AdminHub::section_slug( 'settings' ) );

		$tabs = array(
			'general'       => __( 'General', 'buddynext' ),
			'features'      => __( 'Features', 'buddynext' ),
			'registration'  => __( 'Registration', 'buddynext' ),
			'social'        => __( 'Social', 'buddynext' ),
			'spaces'        => __( 'Spaces', 'buddynext' ),
			'notifications' => __( 'Notifications', 'buddynext' ),
			'email'         => __( 'Email', 'buddynext' ),
			'moderation'    => __( 'Moderation', 'buddynext' ),
			'integrations'  => __( 'Add-ons', 'buddynext' ),
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

		// Same opt-out as render_settings_tab(): tabs with no Settings-API
		// inputs render without the options.php form + save bar.
		if ( in_array( $active_tab, self::NO_FORM_TABS, true ) ) {
			$this->{'render_tab_' . $active_tab}();
			$this->close_tab_panel();
			return;
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'buddynext_' . $active_tab ); ?>
			<?php // Explicit referer so options.php redirects back to THIS tab after save (WP 6.7+ may not emit _wp_http_referer via settings_fields()). ?>
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( remove_query_arg( 'settings-updated', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); ?>">
			<?php
			if ( in_array( $active_tab, self::DESCRIPTOR_TABS, true ) ) {
				$this->render_sections( $this->sections_for_tab( $active_tab ) );
			} else {
				$this->{'render_tab_' . $active_tab}();
			}
			?>
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
		// General keeps a thin bespoke wrapper only for the one-time "recommended
		// settings" prompt banner above the form; every option row is declared in
		// fields_general() and rendered from descriptors (deep-link anchors + one
		// source of truth). enable_dm's dynamic hint / disabled state is carried
		// by the field's hint_callback + disabled_callback.
		$this->render_recommended_prompt();
		$this->render_sections( $this->sections_for_tab( 'general' ) );
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

		// Integration bridges are configured on the Integrations tab (per-aspect
		// toggles), not here — the Features tab has no 'bridges' group.
		$group_labels = array(
			'core'         => __( 'Core (always on)', 'buddynext' ),
			'community'    => __( 'Community features', 'buddynext' ),
			'integrations' => __( 'Power-user integrations', 'buddynext' ),
		);

		// S5: the page H1 + subhead already say "Features" / "Pick which features
		// your community uses" — an empty title suppresses the card header, and
		// the intro keeps only the one sentence the subhead does not carry.
		$this->open_section( '' );

		echo '<p class="bn-field-hint bn-a-flush-top">' .
			esc_html__( 'You can enable or disable everything except core features from this tab - changes apply immediately on save.', 'buddynext' ) .
			'</p>';

		// Search box: this tab lists many toggles across several groups, so let the
		// owner jump straight to one. Filtering is client-side (assets/js/admin/
		// settings.js) over the feature rows; groups with no match hide, and an
		// empty-result note shows.
		echo '<div class="bn-feature-search" data-bn-feature-search>';
		printf(
			'<input type="search" class="bn-feature-search__input" data-bn-feature-search-input placeholder="%s" aria-label="%s" autocomplete="off">',
			esc_attr__( 'Search features...', 'buddynext' ),
			esc_attr__( 'Search features', 'buddynext' )
		);
		echo '<p class="bn-feature-search__empty" data-bn-feature-search-empty hidden>' . esc_html__( 'No features match your search.', 'buddynext' ) . '</p>';
		echo '</div>';

		// Scoped note: the "Power-user integrations" group below turns connected
		// apps on/off; where they appear is a separate concern on Integration Settings.
		echo '<p class="bn-field-hint">';
		printf(
			/* translators: %s: link to the Integration Settings tab. */
			esc_html__( 'The Power-user integrations group below turns connected apps on or off; control where they appear under %s.', 'buddynext' ),
			'<a href="' . esc_url( AdminHub::tab_url( 'settings', 'integration-controls' ) ) . '">' . esc_html__( 'Integration Settings', 'buddynext' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href is esc_url'd and the link text is esc_html'd.
		);
		echo '</p>';

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

				// Bridge features whose partner plugin is absent: the toggle is
				// inoperable, so render it disabled with a "Requires X" notice
				// instead of a configurable switch.
				$required_plugin = (string) ( $feature['required_plugin'] ?? '' );
				$dep_missing     = '' !== $required_plugin
					&& array_key_exists( 'presence_met', $feature )
					&& ! $feature['presence_met'];

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
						<?php if ( $dep_missing ) : ?>
							<p class="bn-feature-row__deps">
								<?php
								printf(
									/* translators: %s: required plugin name */
									esc_html__( 'Requires the %s plugin — install and activate it to enable this integration.', 'buddynext' ),
									esc_html( $required_plugin )
								);
								?>
							</p>
						<?php elseif ( ! empty( $feature['depends_on'] ) ) : ?>
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
						<?php elseif ( $dep_missing ) : ?>
							<?php
							// Re-post the stored value untouched so saving while the
							// plugin is absent never strands the feature off — when the
							// plugin returns, the owner's prior intent (or the tier
							// default) resolves normally.
							if ( array_key_exists( $slug, $state ) ) :
								?>
								<input
									type="hidden"
									name="buddynext_features[<?php echo esc_attr( $slug ); ?>]"
									value="<?php echo $state[ $slug ] ? '1' : '0'; ?>">
							<?php endif; ?>
							<label class="bn-toggle-label" title="<?php echo esc_attr( sprintf( /* translators: %s: required plugin name */ __( 'Requires the %s plugin', 'buddynext' ), $required_plugin ) ); ?>">
								<input
									type="checkbox"
									value="1"
									disabled
									role="switch"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: feature label */ __( '%s (unavailable — required plugin not active)', 'buddynext' ), $feature['label'] ) ); ?>">
								<span class="bn-toggle--inline"></span>
							</label>
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

		// AdminHub clears admin_notices on every BuddyNext screen so third-party
		// setup nags do not crowd the settings UI. That is deliberate and stays —
		// but remove_all_actions() cannot tell a foreign nag from one of ours, so
		// BuddyNext's own registration warnings were cleared as collateral on the
		// exact screen that owns those settings. The Setup Checklist links the owner
		// here, so the guidance vanished at the moment they acted on it.
		//
		// Rendered inline instead: beside the control each one concerns, which is
		// better than a global banner regardless, and the suppression is untouched.
		\BuddyNext\Auth\CoreRegistration::render_desync_inline();

		$this->render_select_row(
			'buddynext_reg_mode',
			__( 'Registration Mode', 'buddynext' ),
			(string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() ),
			array(
				'open'     => __( 'Open — anyone can register', 'buddynext' ),
				'invite'   => __( 'Invite Only — requires an invitation', 'buddynext' ),
				'approval' => __( 'Admin Approval — admin reviews each request', 'buddynext' ),
				'closed'   => __( 'Closed — nobody can create an account', 'buddynext' ),
			),
			__( 'Controls who can create a new account on your community.', 'buddynext' )
		);

		// The "Require email verification" sub-toggle only has any effect when the
		// Email Verification feature is enabled on the Features tab. When the
		// feature is off, hide the toggle (it would be saved but silently ignored)
		// and point the admin to where the master switch lives.
		\BuddyNext\Auth\CoreRegistration::render_terms_inline();

		$this->render_toggle_row(
			'buddynext_require_terms',
			__( 'Require members to accept your terms', 'buddynext' ),
			__( 'Shows a consent checkbox on every sign-up route. On by default.', 'buddynext' ),
			(bool) get_option( 'buddynext_require_terms', true )
		);

		// What the front door asks for. Two levers, deliberately: the owner decides what
		// their sign-up form collects, and anything beyond these goes through the profile
		// fields' "show on register" switch, which already exists. We are not building a
		// second form builder alongside it.
		$this->render_toggle_row(
			'buddynext_reg_ask_name',
			__( 'Ask new members for their name', 'buddynext' ),
			__( 'On by default. This is the name other members see. Turn it off only if your community wants handles rather than names.', 'buddynext' ),
			(bool) get_option( 'buddynext_reg_ask_name', true )
		);

		$this->render_toggle_row(
			'buddynext_reg_ask_username',
			__( 'Let members choose their own username', 'buddynext' ),
			__( 'Off by default: a username is generated from their email so nobody has to invent one to join, and they can change it later in Settings. Turn this on to ask for one at sign-up.', 'buddynext' ),
			(bool) get_option( 'buddynext_reg_ask_username', false )
		);

		$this->render_toggle_row(
			\BuddyNext\Auth\CoreRegistration::OPT_ALLOW,
			__( 'Also allow the WordPress sign-up form', 'buddynext' ),
			__( 'Off by default: wp-login.php sign-ups are sent to your BuddyNext sign-up page instead. Turn this on if another plugin relies on the WordPress form. It is protected by your settings either way.', 'buddynext' ),
			\BuddyNext\Auth\CoreRegistration::is_allowed()
		);

		$bn_verification_on = buddynext_feature_enabled( 'verification' );
		if ( $bn_verification_on ) {
			$this->render_toggle_row(
				'buddynext_email_verify',
				__( 'Require email verification', 'buddynext' ),
				__( 'Ask new members to confirm their email address.', 'buddynext' ),
				(bool) get_option( 'buddynext_email_verify', false )
			);

			// How strictly. The old copy promised members "must verify before
			// accessing the community" while the code only ever blocked posting and
			// commenting. Rather than pick one behaviour and break half the fleet,
			// the strictness is the owner's — with the safe middle as the default.
			$this->render_select_row(
				'buddynext_verify_enforcement',
				__( 'How strictly to enforce verification', 'buddynext' ),
				\BuddyNext\Auth\VerificationListener::enforcement(),
				array(
					'restricted' => __( 'Restricted — they can look around, but cannot post or comment until they confirm', 'buddynext' ),
					'full'       => __( 'Full — they cannot use the community at all until they confirm', 'buddynext' ),
				),
				__( 'Restricted is recommended: a hard gate costs you sign-ups, because confirmation emails land in spam folders more often than you would like.', 'buddynext' )
			);
		} else {
			$bn_features_url = AdminHub::tab_url( 'settings', 'features' );
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

		$this->render_text_row(
			'buddynext_signup_subtitle',
			__( 'Sign-up form subtitle', 'buddynext' ),
			buddynext_auth_panel_value( 'buddynext_signup_subtitle' ),
			__( 'Shown under "Join the community" on the sign-up form. Reword it if joining your community is not free - for example when you only sell paid plans.', 'buddynext' )
		);

		$this->close_section();

		$this->open_section( __( 'Legal Pages', 'buddynext' ) );

		// Terms picker so the owner links the sign-up consent to a real page on
		// their site — no slug guessing, no code. Build the option list from the
		// site's published pages; "None" leaves the wording unlinked.
		$bn_legal_page_options = array( '0' => __( '— None —', 'buddynext' ) );
		foreach ( get_pages( array( 'sort_column' => 'post_title' ) ) as $bn_legal_page ) {
			$bn_legal_page_options[ (string) $bn_legal_page->ID ] = $bn_legal_page->post_title;
		}

		$this->render_select_row(
			'buddynext_terms_page_id',
			__( 'Terms of Service page', 'buddynext' ),
			(string) (int) get_option( 'buddynext_terms_page_id', 0 ),
			$bn_legal_page_options,
			__( 'Linked from the "I agree to the Terms of Service" line on the sign-up form. First create a page (Pages → Add New) with your terms, then choose it here — no code, no URL to paste. Leave as None to show the wording without a link.', 'buddynext' )
		);

		// Privacy reuses WordPress core's own Privacy Policy page setting rather
		// than duplicating it — point the owner there and show the current page.
		$bn_privacy_page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$bn_privacy_admin   = admin_url( 'options-privacy.php' );
		$bn_privacy_status  = __( 'No Privacy Policy page is set yet.', 'buddynext' );
		if ( $bn_privacy_page_id > 0 ) {
			$bn_privacy_status = sprintf(
				/* translators: %s: the current Privacy Policy page title */
				__( 'Currently using "%s".', 'buddynext' ),
				get_the_title( $bn_privacy_page_id )
			);
			// WordPress creates the Privacy Policy page as a draft; warn the owner
			// so they know to publish it (the sign-up link points to it either way).
			if ( 'publish' !== get_post_status( $bn_privacy_page_id ) ) {
				$bn_privacy_status .= ' ' . __( 'That page is not published yet — publish it so members can open the link.', 'buddynext' );
			}
		}
		echo '<div class="bn-field"><label>' . esc_html__( 'Privacy Policy page', 'buddynext' ) . '</label>';
		echo '<p class="bn-field-hint">' . wp_kses_post(
			sprintf(
				/* translators: 1: current privacy page status sentence, 2: link to the WordPress Privacy settings screen */
				__( 'The "Privacy Policy" link uses your site\'s Privacy Policy page. %1$s Set or change it under %2$s.', 'buddynext' ),
				esc_html( $bn_privacy_status ),
				'<a href="' . esc_url( $bn_privacy_admin ) . '">' . esc_html__( 'Settings → Privacy', 'buddynext' ) . '</a>'
			)
		) . '</p></div>';

		$this->close_section();

		$this->open_section( __( 'Spam &amp; Abuse Protection', 'buddynext' ) );

		// Two-factor had NO admin surface at all — grep for '2fa' in includes/Admin
		// returned nothing. The only control was a developer filter that was read
		// and then ignored, so an owner could "require 2FA for administrators" and
		// simply be wrong. Default is none: forcing an authenticator app on owners
		// during an update would be a lockout event across the fleet.
		$this->render_select_row(
			'buddynext_2fa_required',
			__( 'Require two-factor authentication', 'buddynext' ),
			(string) get_option( 'buddynext_2fa_required', 'none' ),
			array(
				'none'   => __( 'Nobody — members can still switch it on themselves', 'buddynext' ),
				'admins' => __( 'Administrators', 'buddynext' ),
				'staff'  => __( 'Administrators and editors', 'buddynext' ),
				'all'    => __( 'Everyone', 'buddynext' ),
			),
			__( 'Anyone in a required role is asked to set two-factor up the next time they sign in, and cannot use the community until they do.', 'buddynext' )
		);

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

		// The Registration tab renders this option itself rather than through
		// render_sections(), so converting the descriptor alone would have left
		// the magic zero on the only screen an owner actually sees it.
		$this->render_optional_limit_row(
			'buddynext_reg_rate_limit',
			__( 'Sign-ups per hour per IP', 'buddynext' ),
			(int) get_option( 'buddynext_reg_rate_limit', 5 ),
			__( 'Rate-limit sign-ups per IP', 'buddynext' ),
			__( 'Maximum sign-up attempts allowed from one IP address per hour.', 'buddynext' )
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

		// There was no email-domain BLOCKlist at all — only the allowlist above. An
		// owner who wanted to shut out one abusive domain had to switch to an
		// allowlist and enumerate every permitted domain on earth instead.
		// Not to be confused with "Blocked link domains" on the Moderation tab,
		// which is about links in post content and never touched registration — a
		// name collision that actively misled people.
		$this->render_textarea_row(
			'buddynext_blocked_email_domains',
			__( 'Blocked email domains', 'buddynext' ),
			(string) get_option( 'buddynext_blocked_email_domains', '' ),
			__( 'One domain per line. Addresses from these domains cannot register. Use this to shut out a single abusive domain without having to allow-list every other domain on earth.', 'buddynext' ),
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

		$this->open_section( __( 'Redirects', 'buddynext' ) );

		$this->render_text_row(
			\BuddyNext\Core\RedirectSettings::OPT_LOGIN,
			__( 'After login', 'buddynext' ),
			(string) get_option( \BuddyNext\Core\RedirectSettings::OPT_LOGIN, '' ),
			__( 'Where members go after logging in. Leave blank for the activity feed (default). A link a member was sent to (e.g. a gated page) always takes priority.', 'buddynext' )
		);

		$this->render_text_row(
			\BuddyNext\Core\RedirectSettings::OPT_LOGOUT,
			__( 'After logout', 'buddynext' ),
			(string) get_option( \BuddyNext\Core\RedirectSettings::OPT_LOGOUT, '' ),
			// RedirectSettings::filter_logout_redirect() defaults a blank value to
			// PageRouter::auth_url() — the branded login screen, not the home page.
			__( 'Where members go after logging out. Leave blank for the login page (default), ready to sign back in.', 'buddynext' )
		);

		$this->render_text_row(
			\BuddyNext\Core\RedirectSettings::OPT_ONBOARDING,
			__( 'After onboarding', 'buddynext' ),
			(string) get_option( \BuddyNext\Core\RedirectSettings::OPT_ONBOARDING, '' ),
			__( 'Where new members go after finishing onboarding. Leave blank for their profile (default).', 'buddynext' )
		);

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
			$cfg     = isset( $social[ $pid ] ) && is_array( $social[ $pid ] ) ? $social[ $pid ] : array();
			$enabled = ! empty( $cfg['enabled'] );

			// Field set from the definition's credentials descriptor; the classic
			// id + secret pair when a provider declares none.
			$cred_fields = isset( $def['credentials'] ) && is_array( $def['credentials'] )
				? $def['credentials']
				: array(
					'client_id'     => array(
						'label' => __( 'Client ID', 'buddynext' ),
						'type'  => 'text',
					),
					'client_secret' => array(
						'label'  => __( 'Client Secret', 'buddynext' ),
						'type'   => 'password',
						'secret' => true,
					),
				);

			$has_keys = true;
			foreach ( array_keys( $cred_fields ) as $cred_key ) {
				if ( '' === (string) ( $cfg[ $cred_key ] ?? '' ) ) {
					$has_keys = false;
					break;
				}
			}

			$needs_https = 'post' === (string) ( $def['callback_method'] ?? 'get' ) && ! is_ssl();

			$cb      = \BuddyNext\Auth\SocialLogin::callback_url( $pid );
			$label   = (string) ( $def['label'] ?? ucfirst( $pid ) );
			$icon    = (string) ( $def['icon'] ?? 'globe' );
			$console = (string) ( $def['console_url'] ?? '' );
			$steps   = isset( $def['setup_steps'] ) && is_array( $def['setup_steps'] ) ? $def['setup_steps'] : array();
			$cb_id   = 'bn-redir-' . sanitize_key( $pid );

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
					<?php if ( $needs_https ) : ?>
						<p class="bn-field-hint bn-social-card__warning">
							<?php
							/* translators: %s: provider name (e.g. Apple). */
							echo esc_html( sprintf( __( '%s sign-in returns to your site over a secure cross-site request, so it only works on HTTPS. This site is not using HTTPS, so the button will stay hidden even when configured.', 'buddynext' ), $label ) );
							?>
						</p>
					<?php endif; ?>

					<?php
					foreach ( $cred_fields as $cred_key => $descriptor ) :
						$cred_id     = 'bn-' . sanitize_key( $pid ) . '-' . sanitize_key( (string) $cred_key );
						$cred_name   = 'buddynext_social_login[' . $pid . '][' . $cred_key . ']';
						$cred_label  = (string) ( $descriptor['label'] ?? ucfirst( str_replace( '_', ' ', (string) $cred_key ) ) );
						$cred_type   = (string) ( $descriptor['type'] ?? 'text' );
						$cred_secret = ! empty( $descriptor['secret'] ) || 'password' === $cred_type;
						$cred_stored = (string) ( $cfg[ $cred_key ] ?? '' );

						// WRITE-ONLY secrets. The stored value is never echoed back into
						// the page — type="password" is not a secret store; the
						// plaintext sat in the DOM and View Source, exposed to any
						// admin-side XSS or browser extension. A blank submit keeps the
						// saved value (see sanitize_social_login_option), so the owner
						// can edit everything else without retyping credentials.
						$cred_saved_ph = esc_attr__( 'Saved. Leave blank to keep it, or paste a new one to replace it.', 'buddynext' );
						/* translators: %s: credential field label (e.g. Client ID). */
						$cred_empty_ph = esc_attr( sprintf( __( 'Paste the %s here', 'buddynext' ), $cred_label ) );
						?>
						<div class="bn-field">
							<label for="<?php echo esc_attr( $cred_id ); ?>"><?php echo esc_html( $cred_label ); ?></label>
							<?php if ( 'textarea' === $cred_type ) : ?>
								<textarea class="bn-input" id="<?php echo esc_attr( $cred_id ); ?>"
									name="<?php echo esc_attr( $cred_name ); ?>"
									rows="4"
									placeholder="<?php echo $cred_secret && '' !== $cred_stored ? $cred_saved_ph : $cred_empty_ph; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>"
									autocomplete="off" spellcheck="false"><?php echo $cred_secret ? '' : esc_textarea( $cred_stored ); ?></textarea>
							<?php else : ?>
								<input type="<?php echo esc_attr( $cred_secret ? 'password' : 'text' ); ?>" class="bn-input" id="<?php echo esc_attr( $cred_id ); ?>"
									name="<?php echo esc_attr( $cred_name ); ?>"
									value="<?php echo $cred_secret ? '' : esc_attr( $cred_stored ); ?>"
									placeholder="<?php echo $cred_secret && '' !== $cred_stored ? $cred_saved_ph : $cred_empty_ph; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>"
									autocomplete="off" spellcheck="false" />
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
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
	 * Render the Integrations settings tab.
	 *
	 * Shows the Wbcom family header + a card grid of companion plugins.
	 * Each card resolves to a real, state-aware action: Active companions show
	 * "Connected"; installed-but-inactive get a one-click Activate; not-installed
	 * get a one-click "Install free" via the EDD store (CompanionInstaller,
	 * install_plugins-gated). A "Learn more" link to the store page sits alongside
	 * every action.
	 *
	 * @return void
	 */
	private function render_tab_integrations(): void {
		$companions   = \BuddyNext\Integrations\CompanionRegistry::all();
		$can_install  = current_user_can( 'install_plugins' );
		$can_activate = current_user_can( 'activate_plugins' );
		$logo_base    = defined( 'BUDDYNEXT_URL' ) ? (string) constant( 'BUDDYNEXT_URL' ) : '';
		$logo_base   .= 'assets/img/companions/';

		echo '<p class="bn-field-hint">';
		printf(
			/* translators: %s: link to the Features tab. */
			esc_html__( 'Install companions here; enable them under %s.', 'buddynext' ),
			'<a href="' . esc_url( AdminHub::tab_url( 'settings', 'features' ) ) . '">' . esc_html__( 'Features', 'buddynext' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href is esc_url'd and the link text is esc_html'd.
		);
		echo '</p>';
		?>

		<div class="bn-fam-header">
			<img
				class="bn-fam-header__mark"
				src="<?php echo esc_url( $logo_base . 'wbcom.svg' ); ?>"
				alt="<?php esc_attr_e( 'Wbcom', 'buddynext' ); ?>"
				width="52"
				height="52"
			/>
			<div class="bn-fam-header__body">
				<h2 class="bn-fam-header__title"><?php esc_html_e( 'Part of the Wbcom family', 'buddynext' ); ?></h2>
				<p class="bn-fam-header__desc">
					<?php esc_html_e( 'BuddyNext is the community engine of the Wbcom stack: gamification, discussions, courses, messaging, listings, jobs, and more. Every plugin works on its own, and you can add any of them below in one click. The family keeps growing, so check back for new releases.', 'buddynext' ); ?>
				</p>
				<a class="bn-fam-header__link" href="https://wbcomdesigns.com/downloads/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Explore the full Wbcom family', 'buddynext' ); ?>
					<?php buddynext_icon( 'arrow-right' ); ?>
				</a>
			</div>
		</div>

		<div class="bn-companion-grid"
			data-bn-companions
			data-rest="<?php echo esc_url( rest_url( 'buddynext/v1/companions/install' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-i18n-installing="<?php esc_attr_e( 'Installing...', 'buddynext' ); ?>"
			data-i18n-installed="<?php esc_attr_e( 'Installed - reloading...', 'buddynext' ); ?>"
			data-i18n-failed="<?php esc_attr_e( 'Install failed.', 'buddynext' ); ?>"
			data-i18n-network="<?php esc_attr_e( 'Install failed - network error.', 'buddynext' ); ?>">
			<?php
			foreach ( $companions as $bn_slug => $bn_c ) :
				$bn_status   = \BuddyNext\Integrations\CompanionRegistry::status( (string) $bn_slug );
				$bn_label    = (string) ( $bn_c['label'] ?? $bn_slug );
				$bn_why      = (string) ( $bn_c['why'] ?? '' );
				$bn_unlocks  = (string) ( $bn_c['unlocks'] ?? '' );
				$bn_store    = (string) ( $bn_c['store_url'] ?? '' );
				$bn_basename = (string) ( $bn_c['free']['basename'] ?? '' );
				$bn_logo     = $logo_base . sanitize_file_name( (string) $bn_slug ) . '.svg';

				if ( 'active' === $bn_status ) {
					$bn_badge_class = 'bn-companion-badge bn-companion-badge--success';
					$bn_badge_label = __( 'Connected', 'buddynext' );
				} elseif ( 'inactive' === $bn_status ) {
					$bn_badge_class = 'bn-companion-badge bn-companion-badge--warning';
					$bn_badge_label = __( 'Installed, activate', 'buddynext' );
				} else {
					$bn_badge_class = 'bn-companion-badge bn-companion-badge--muted';
					$bn_badge_label = __( 'Not installed', 'buddynext' );
				}
				?>
			<div class="bn-companion-card" data-status="<?php echo esc_attr( $bn_status ); ?>" data-slug="<?php echo esc_attr( $bn_slug ); ?>">
				<div class="bn-companion-card__head">
					<img
						class="bn-companion-card__logo"
						src="<?php echo esc_url( $bn_logo ); ?>"
						alt="<?php echo esc_attr( $bn_label ); ?>"
						width="40"
						height="40"
						loading="lazy"
					/>
					<h3 class="bn-companion-card__title"><?php echo esc_html( $bn_label ); ?></h3>
					<span class="<?php echo esc_attr( $bn_badge_class ); ?>"><?php echo esc_html( $bn_badge_label ); ?></span>
				</div>

				<?php if ( '' !== $bn_why ) : ?>
					<p class="bn-companion-card__why"><?php echo esc_html( $bn_why ); ?></p>
				<?php endif; ?>

				<?php if ( 'active' === $bn_status && '' !== $bn_unlocks ) : ?>
					<p class="bn-companion-card__unlocks">
						<?php buddynext_icon( 'check' ); ?>
						<?php echo esc_html( $bn_unlocks ); ?>
					</p>
				<?php endif; ?>

				<div class="bn-companion-card__actions">
					<?php // Active companions already carry the "Connected" status badge in the card head, so the actions row shows only "Learn more". ?>
					<?php if ( 'inactive' === $bn_status && $can_activate && '' !== $bn_basename ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $bn_basename ) . '&plugin_status=all' ), 'activate-plugin_' . $bn_basename ) ); ?>"
							class="bn-addon-row__action"><?php esc_html_e( 'Activate', 'buddynext' ); ?></a>
					<?php elseif ( 'not_installed' === $bn_status && $can_install ) : ?>
						<button type="button" class="bn-addon-row__action bn-companion-install" data-slug="<?php echo esc_attr( $bn_slug ); ?>">
							<?php esc_html_e( 'Install free', 'buddynext' ); ?>
						</button>
					<?php endif; ?>

					<?php if ( '' !== $bn_store ) : ?>
						<a href="<?php echo esc_url( $bn_store ); ?>"
							class="bn-addon-row__action bn-addon-row__action--ghost"
							target="_blank"
							rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'buddynext' ); ?></a>
					<?php endif; ?>

					<span class="bn-companion-msg" role="status" aria-live="polite"></span>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<p class="bn-companion-foot description">
			<?php esc_html_e( 'Every product above is a standalone Wbcom plugin. BuddyNext detects each one and lights up the matching features automatically when it is active.', 'buddynext' ); ?>
		</p>

		<?php
		// Recommended themes. BuddyNext's full experience (dark-mode bridge,
		// community chrome, tested layouts) needs a purpose-built theme; other
		// themes can work but often need custom styling. Framed as a strong
		// suggestion the owner can dismiss, never a hard requirement.
		$bn_theme_recommended = array( 'buddyx', 'buddyx-pro', 'reign', 'reign-theme' );
		$bn_active_template   = strtolower( (string) get_template() );
		$bn_themes            = array(
			array(
				'label'   => 'BuddyX',
				'why'     => __( 'Free community theme built for BuddyNext - dark mode and chrome tuned to match.', 'buddynext' ),
				'active'  => in_array( $bn_active_template, array( 'buddyx' ), true ),
				'install' => admin_url( 'theme-install.php?search=buddyx' ),
				'store'   => 'https://wbcomdesigns.com/downloads/buddyx-theme/',
				'tier'    => __( 'Free', 'buddynext' ),
			),
			array(
				'label'  => 'BuddyX Pro',
				'why'    => __( 'Premium BuddyX with deeper layout, header, and community controls.', 'buddynext' ),
				'active' => in_array( $bn_active_template, array( 'buddyx-pro' ), true ),
				'store'  => 'https://buddyxtheme.com/',
				'tier'   => __( 'Premium', 'buddynext' ),
			),
			array(
				'label'  => 'Reign',
				'why'    => __( 'Premium multi-purpose community theme with rich BuddyNext layouts.', 'buddynext' ),
				'active' => in_array( $bn_active_template, array( 'reign', 'reign-theme' ), true ),
				'store'  => 'https://wbcomdesigns.com/downloads/reign-theme/',
				'tier'   => __( 'Premium', 'buddynext' ),
			),
		);
		?>
		<div class="bn-fam-header bn-fam-header--themes">
			<div class="bn-fam-header__body">
				<h2 class="bn-fam-header__title"><?php esc_html_e( 'Recommended themes', 'buddynext' ); ?></h2>
				<p class="bn-fam-header__desc">
					<?php esc_html_e( 'The full BuddyNext experience - dark mode, community chrome, and layouts tested on every surface - needs a purpose-built theme, and not every theme offers dark mode. Other themes can work but often need custom styling.', 'buddynext' ); ?>
				</p>
			</div>
		</div>

		<div class="bn-companion-grid">
			<?php foreach ( $bn_themes as $bn_t ) : ?>
			<div class="bn-companion-card" data-status="<?php echo $bn_t['active'] ? 'active' : 'not_installed'; ?>">
				<div class="bn-companion-card__head">
					<h3 class="bn-companion-card__title"><?php echo esc_html( (string) $bn_t['label'] ); ?></h3>
					<?php if ( $bn_t['active'] ) : ?>
						<span class="bn-companion-badge bn-companion-badge--success"><?php esc_html_e( 'Active', 'buddynext' ); ?></span>
					<?php else : ?>
						<span class="bn-companion-badge bn-companion-badge--muted"><?php echo esc_html( (string) $bn_t['tier'] ); ?></span>
					<?php endif; ?>
				</div>

				<p class="bn-companion-card__why"><?php echo esc_html( (string) $bn_t['why'] ); ?></p>

				<div class="bn-companion-card__actions">
					<?php if ( ! $bn_t['active'] && ! empty( $bn_t['install'] ) && $can_install ) : ?>
						<a href="<?php echo esc_url( (string) $bn_t['install'] ); ?>" class="bn-addon-row__action"><?php esc_html_e( 'Install free', 'buddynext' ); ?></a>
					<?php endif; ?>
					<a href="<?php echo esc_url( (string) $bn_t['store'] ); ?>" class="bn-addon-row__action bn-addon-row__action--ghost" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'buddynext' ); ?></a>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<?php
		// The companion installer behaviour lives in assets/js/admin/settings.js
		// (initCompanions), wired to the data-* attributes on [data-bn-companions]
		// above. No inline script - see the UX-audit F2 rule.
		//
		// The Jetonomy discussion-activity toggle moved to the unified Integration
		// Display tab (BuddyNext -> Platform -> Integration Display), which owns the
		// nav + feed toggle for every integration. It is intentionally NOT duplicated
		// here so the admin sees a single control, not two.
	}

	/**
	 * Render the Webhooks settings tab.
	 *
	 * Two sections: the inbound-verification secret (consumed ONLY by
	 * AccessWebhookController for POST /webhook/access — outbound payloads
	 * are signed with the per-endpoint secret generated at registration,
	 * never with this option), and the endpoint manager (list / add / test
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
				<?php
				// State-aware: the button reads "Generate" until a secret exists, then "Rotate".
				if ( $has_secret ) {
					esc_html_e( 'Verifies INBOUND requests to POST buddynext/v1/webhook/access, which is the only thing this secret does. Outgoing webhooks are signed with the secret you set on each endpoint under Registered endpoints, not with this one - do not copy this value into Slack or Zapier. Click Rotate for a new strong secret, then Save; the old value stops working immediately, so update whatever calls that endpoint. Left blank, the inbound endpoint refuses every request.', 'buddynext' );
				} else {
					esc_html_e( 'Verifies INBOUND requests to POST buddynext/v1/webhook/access, which is the only thing this secret does. Outgoing webhooks are signed with the secret you set on each endpoint under Registered endpoints, not with this one - do not copy this value into Slack or Zapier. Click Generate, then Save, and give the value to whatever service calls that endpoint. Left blank, the inbound endpoint refuses every request.', 'buddynext' );
				}
				?>
			</span>
			<span class="bn-secret-msg" role="status" aria-live="polite" data-bn-secret-msg></span>
		</div>
		<?php
		$this->close_section();

		$this->open_section( __( 'Signature verification', 'buddynext' ) );

		// On by default (1.1.6): get_option( …, true ) mirrors the enforcement
		// default in AccessWebhookController::verify_signature(), so the toggle shows
		// the actual state on an upgraded site with no stored row (strict). An owner
		// mid-migration turns it OFF to keep accepting the legacy body-only scheme.
		$this->render_toggle_row(
			\BuddyNext\Outbound\AccessWebhookController::OPT_STRICT_SIGNATURES,
			__( 'Require replay-proof webhook signatures', 'buddynext' ),
			__( 'On by default: only the timestamped signature scheme (with an X-BuddyNext-Timestamp header) is accepted and the older body-only scheme is rejected. The body-only scheme cannot be replay-checked, so a captured request stays valid indefinitely. Turn this OFF only while migrating a service that still sends body-only signatures, and re-enable it once every caller sends a timestamp.', 'buddynext' ),
			(bool) get_option( \BuddyNext\Outbound\AccessWebhookController::OPT_STRICT_SIGNATURES, true )
		);

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

			<?php
			/*
			 * Scroll wrapper, because the Endpoint column holds a URL that is kept on
			 * one line (see .bn-ep-code in bn-admin.css). Without it the table is wider
			 * than .bn-settings-section, which carries overflow-x: hidden for its card
			 * corners - so the Actions column was clipped away with no way to reach it.
			 * Measured before this wrapper: table 1263px inside a 602px section at a
			 * 1024px viewport, Send test / View log / Remove all cut off.
			 */
			?>
			<div class="bn-table-wrap__scroll">
			<table class="bn-table widefat" data-bn-webhook-table>
				<thead>
					<tr>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Endpoint', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Events', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created', 'buddynext' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
					</tr>
				</thead>
				<tbody data-bn-webhook-tbody>
				<?php if ( empty( $webhooks ) ) : ?>
					<tr data-bn-webhook-empty>
						<td colspan="5">
							<div class="bn-empty">
								<p class="bn-empty__title"><?php esc_html_e( 'No webhooks registered yet', 'buddynext' ); ?></p>
								<p class="bn-empty__sub"><?php esc_html_e( 'Add an endpoint below to start receiving events.', 'buddynext' ); ?></p>
							</div>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $webhooks as $hook ) :
						$hook_events = is_array( $hook['events'] ?? null )
							? $hook['events']
							: (array) json_decode( (string) ( $hook['events'] ?? '[]' ), true );
						?>
						<tr data-bn-webhook-row="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>">
							<td class="column-primary" data-colname="<?php esc_attr_e( 'Endpoint', 'buddynext' ); ?>">
								<strong><?php echo esc_html( (string) ( $hook['label'] ?? '' ) ); ?></strong><br>
								<code class="bn-ep-code" title="<?php echo esc_attr( (string) ( $hook['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $hook['url'] ?? '' ) ); ?></code>
								<button type="button" class="toggle-row">
									<?php buddynext_icon( 'chevron-down', 'bn-toggle-row__icon' ); ?>
									<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'buddynext' ); ?></span>
								</button>
							</td>
							<td data-colname="<?php esc_attr_e( 'Events', 'buddynext' ); ?>">
								<?php
								// No sanitize_key() here. It is an INPUT sanitiser that strips
								// everything outside [a-z0-9_-] - including the dot in every
								// event slug - so "member.registered" rendered as
								// "memberregistered", and two events as one unreadable run
								// ("postcreatedpostdeleted"). esc_html() is what makes this
								// safe to print; the map only corrupted it.
								echo esc_html( implode( ', ', array_map( 'strval', $hook_events ) ) );
								?>
							</td>
							<td data-colname="<?php esc_attr_e( 'Status', 'buddynext' ); ?>">
								<?php
								if ( ! empty( $hook['is_active'] ) ) {
									echo '<span class="bn-badge" data-tone="success">' . esc_html__( 'Active', 'buddynext' ) . '</span>';
								} else {
									echo '<span class="bn-badge" data-tone="warn">' . esc_html__( 'Disabled', 'buddynext' ) . '</span>';
								}
								?>
							</td>
							<td data-colname="<?php esc_attr_e( 'Created', 'buddynext' ); ?>"><?php echo esc_html( (string) ( $hook['created_at'] ?? '' ) ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Actions', 'buddynext' ); ?>">
								<div class="bn-row-actions">
									<button type="button"
										class="bn-btn"
										data-variant="secondary"
										data-size="sm"
										data-bn-webhook-test="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>"
									><?php esc_html_e( 'Send test', 'buddynext' ); ?></button>
									<button type="button"
										class="bn-btn"
										data-variant="secondary"
										data-size="sm"
										data-bn-webhook-log="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>"
										aria-expanded="false"
									><?php esc_html_e( 'View log', 'buddynext' ); ?></button>
									<button type="button"
										class="bn-btn"
										data-variant="danger"
										data-size="sm"
										data-bn-webhook-remove="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>"
									><?php esc_html_e( 'Remove', 'buddynext' ); ?></button>
								</div>
							</td>
						</tr>
						<tr class="bn-webhook-log-row" data-bn-webhook-log-row="<?php echo esc_attr( (string) (int) $hook['id'] ); ?>" hidden>
							<td colspan="5" class="bn-webhook-log-cell"></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</div>

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
			/**
			 * Filter the outbound-webhook event catalogue (slug => label).
			 *
			 * Pro adds its membership / payment events here so a site owner can
			 * subscribe an endpoint to purchase.* / membership.* the same way as the
			 * built-in social events.
			 *
			 * @param array<string,string> $catalogue Event slug => human label.
			 */
			$catalogue = (array) apply_filters( 'buddynext_webhook_event_catalogue', $catalogue );
			?>

			<div class="bn-field bn-a-gap-top">
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

			<?php
			// The button + its live status are an action cluster, not a form field:
			// .bn-field is the label/input/hint wrapper (it stacks its children and
			// carries a field's bottom margin), so the button and the status text
			// sat in the same box with no defined gap and no wrap rule. Use the
			// shared .bn-row-actions primitive (bn-admin.css) that every other admin
			// action cluster consumes: one flex row, one gap, wraps as a unit.
			?>
			<div class="bn-row-actions bn-a-gap-top">
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
