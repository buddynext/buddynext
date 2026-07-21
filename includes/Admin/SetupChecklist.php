<?php
/**
 * First-run setup checklist.
 *
 * A dismissible "Get your community live" card shown at the top of the BuddyNext
 * admin landing until the owner dismisses it or every step auto-completes. Each
 * step's done-state is derived from real config/data (self-verifying) — the owner
 * never ticks a box by hand.
 *
 * @package BuddyNext
 */

namespace BuddyNext\Admin;

use BuddyNext\Core\IconService;
use BuddyNext\Core\PageRouter;

defined( 'ABSPATH' ) || exit;

/**
 * Renders + resolves the owner setup checklist.
 */
final class SetupChecklist {

	/**
	 * Per-site dismiss flag. Set once any admin dismisses the card.
	 *
	 * @var string
	 */
	private const OPT_DISMISSED = 'buddynext_setup_dismissed';

	/**
	 * Set once the owner acknowledges the theme tip ("Keep my theme"), so the
	 * recommendation stops nagging owners who deliberately run another theme.
	 *
	 * @var string
	 */
	private const OPT_THEME_ACK = 'buddynext_theme_tip_ack';

	/**
	 * Themes purpose-built for BuddyNext (parent slugs; child themes inherit the
	 * template slug, so a BuddyX child still matches).
	 *
	 * @var string[]
	 */
	private const RECOMMENDED_THEMES = array( 'buddyx', 'buddyx-pro', 'reign', 'reign-theme' );

	/**
	 * Top-level BuddyNext admin page slug (the landing this card belongs to).
	 *
	 * @var string
	 */
	private const LANDING_SLUG = 'buddynext';

	/**
	 * Register the dismiss handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_dismiss_setup', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_post_bn_ack_theme_tip', array( $this, 'handle_ack_theme_tip' ) );
	}

	/**
	 * Render the card when appropriate (top of the landing, not dismissed, not
	 * complete). No-ops otherwise so it can be called unconditionally.
	 *
	 * @param string $page Current admin page slug.
	 * @return void
	 */
	public static function maybe_render( string $page ): void {
		if ( self::LANDING_SLUG !== $page || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::OPT_DISMISSED ) ) {
			return;
		}

		$steps = self::steps();
		$done  = count( array_filter( $steps, static fn( array $s ): bool => $s['done'] ) );
		$total = count( $steps );
		if ( $done >= $total ) {
			return; // Everything set up — hide the card without needing a dismiss.
		}

		self::render_card( $steps, $done, $total );
	}

	/**
	 * The setup steps, each with a self-verifying done-state and a deep-link CTA.
	 *
	 * @return array<int,array{key:string,label:string,desc:string,done:bool,cta:string,cta_label:string,icon:string}>
	 */
	public static function steps(): array {
		return array(
			array(
				'key'       => 'pages',
				'label'     => __( 'Community pages created', 'buddynext' ),
				'desc'      => __( 'The feed, members and spaces pages your community lives on.', 'buddynext' ),
				'done'      => absint( get_option( 'buddynext_page_activity' ) ) > 0,
				'cta'       => AdminHub::tab_url( 'settings', 'pages' ),
				'cta_label' => __( 'Review pages', 'buddynext' ),
				'icon'      => 'link',
			),
			array(
				'key'       => 'features',
				'label'     => __( 'Choose your features', 'buddynext' ),
				'desc'      => __( 'Turn the parts of the community you want on or off.', 'buddynext' ),
				'done'      => false !== get_option( 'buddynext_features', false ),
				'cta'       => AdminHub::tab_url( 'settings', 'features' ),
				'cta_label' => __( 'Set features', 'buddynext' ),
				'icon'      => 'sparkles',
			),
			array(
				'key'       => 'profiles',
				'label'     => __( 'Set up member profiles', 'buddynext' ),
				'desc'      => __( 'Add the profile fields your members fill in — build your own group or edit the starter kit.', 'buddynext' ),
				'done'      => self::has_custom_profile_group(),
				'cta'       => admin_url( 'admin.php?page=buddynext-members&tab=profile-fields' ),
				'cta_label' => __( 'Build profiles', 'buddynext' ),
				'icon'      => 'user',
			),
			array(
				'key'       => 'registration',
				'label'     => __( 'Registration & login', 'buddynext' ),
				'desc'      => __( 'Decide who can join and how new members sign up.', 'buddynext' ),
				'done'      => false !== get_option( 'buddynext_reg_mode', false ),
				'cta'       => admin_url( 'admin.php?page=buddynext-members&tab=registration' ),
				'cta_label' => __( 'Configure', 'buddynext' ),
				'icon'      => 'lock',
			),
			array(
				'key'       => 'space',
				'label'     => __( 'Create your first space', 'buddynext' ),
				'desc'      => __( 'Give members somewhere to gather. Create a space from the community front end.', 'buddynext' ),
				'done'      => self::has_space(),
				'cta'       => PageRouter::spaces_url(),
				'cta_label' => __( 'Create a space', 'buddynext' ),
				'icon'      => 'users',
			),
			array(
				'key'       => 'brand',
				'label'     => __( 'Brand it', 'buddynext' ),
				'desc'      => __( 'Set your accent colour so the community feels like yours.', 'buddynext' ),
				'done'      => false !== get_option( 'buddynext_brand_color', false ),
				'cta'       => AdminHub::tab_url( 'settings', 'appearance' ),
				'cta_label' => __( 'Customise', 'buddynext' ),
				'icon'      => 'palette',
			),
			array(
				'key'       => 'theme',
				'label'     => self::using_recommended_theme()
					? __( 'Community theme', 'buddynext' )
					: __( 'Pick a community theme', 'buddynext' ),
				'desc'      => self::using_recommended_theme()
					? __( 'You are on a theme built for BuddyNext - dark mode and community chrome are tuned to match.', 'buddynext' )
					: __( 'The full BuddyNext experience - dark mode, community chrome, and layouts tested on every surface - needs a purpose-built theme, and not every theme offers dark mode. Start with BuddyX (free), or step up to BuddyX Pro or Reign.', 'buddynext' ),
				'done'      => self::using_recommended_theme() || (bool) get_option( self::OPT_THEME_ACK, false ),
				'cta'       => admin_url( 'theme-install.php?search=buddyx' ),
				'cta_label' => __( 'Get BuddyX (free)', 'buddynext' ),
				'icon'      => 'layout',
				'links'     => self::theme_links(),
				// Marks this step as ack-dismissible ("Keep my theme"), so owners on
				// another theme can complete the checklist without switching.
				'dismiss'   => 'theme_tip',
			),
		);
	}

	/**
	 * True when the active theme (or its parent) is one built for BuddyNext.
	 *
	 * Public so other landing surfaces (the Get Started Home theme card) read the
	 * same source of truth instead of re-deriving "is this a community theme".
	 *
	 * @return bool
	 */
	public static function using_recommended_theme(): bool {
		return in_array( strtolower( (string) get_template() ), self::RECOMMENDED_THEMES, true )
			|| in_array( strtolower( (string) get_stylesheet() ), self::RECOMMENDED_THEMES, true );
	}

	/**
	 * The recommended-theme links - the single source shared by the checklist's
	 * theme step and the Get Started Home theme card. BuddyX installs in one click
	 * from wp.org; BuddyX Pro and Reign are premium (link out to the store).
	 *
	 * @return array<int, array{label:string, url:string, external?:bool}>
	 */
	public static function theme_links(): array {
		return array(
			array(
				'label' => __( 'BuddyX (free)', 'buddynext' ),
				'url'   => admin_url( 'theme-install.php?search=buddyx' ),
			),
			array(
				'label'    => __( 'BuddyX Pro', 'buddynext' ),
				'url'      => 'https://wbcomdesigns.com/downloads/buddyx-pro-theme/',
				'external' => true,
			),
			array(
				'label'    => __( 'Reign', 'buddynext' ),
				'url'      => 'https://wbcomdesigns.com/downloads/reign-theme/',
				'external' => true,
			),
		);
	}

	/**
	 * True once the owner has created a profile group beyond the seed starter set.
	 *
	 * @return bool
	 */
	private static function has_custom_profile_group(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_profile_groups
			  WHERE is_system = 0
			    AND group_key NOT IN ('social_links','work_experience','education','skills')"
		);
		return $count > 0;
	}

	/**
	 * True once at least one non-archived space exists.
	 *
	 * @return bool
	 */
	private static function has_space(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE is_archived = 0" ) > 0;
	}

	/**
	 * Handle admin_post_bn_dismiss_setup — persist the per-site dismiss flag.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_dismiss_setup' );
		update_option( self::OPT_DISMISSED, 1, false );
		wp_safe_redirect( admin_url( 'admin.php?page=buddynext' ) );
		exit;
	}

	/**
	 * Handle admin_post_bn_ack_theme_tip — the owner chose to keep their theme, so
	 * the recommendation step self-completes and stops nagging.
	 *
	 * @return void
	 */
	public function handle_ack_theme_tip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_ack_theme_tip' );
		update_option( self::OPT_THEME_ACK, 1, false );
		wp_safe_redirect( admin_url( 'admin.php?page=buddynext' ) );
		exit;
	}

	/**
	 * Render the checklist card markup.
	 *
	 * @param array<int,array<string,mixed>> $steps Resolved steps.
	 * @param int                            $done  Completed count.
	 * @param int                            $total Total count.
	 * @return void
	 */
	private static function render_card( array $steps, int $done, int $total ): void {
		$pct = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0;
		?>
		<div class="bn-setup-card">
			<div class="bn-setup-card__head">
				<div class="bn-setup-card__heading">
					<h2 class="bn-setup-card__title"><?php esc_html_e( 'Get your community live', 'buddynext' ); ?></h2>
					<p class="bn-setup-card__sub">
						<?php
						printf(
							/* translators: 1: completed steps, 2: total steps */
							esc_html__( '%1$d of %2$d done', 'buddynext' ),
							(int) $done,
							(int) $total
						);
						?>
					</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-setup-card__dismiss">
					<input type="hidden" name="action" value="bn_dismiss_setup">
					<?php wp_nonce_field( 'bn_dismiss_setup' ); ?>
					<button type="submit" class="bn-setup-card__dismiss-btn" title="<?php esc_attr_e( 'Dismiss setup checklist', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Dismiss setup checklist', 'buddynext' ); ?>">&times;</button>
				</form>
			</div>

			<div class="bn-setup-card__progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
				<span class="bn-setup-card__progress-bar" style="width:<?php echo esc_attr( (string) $pct ); ?>%;"></span>
			</div>

			<ul class="bn-setup-list" role="list">
				<?php foreach ( $steps as $step ) : ?>
					<li class="bn-setup-step<?php echo $step['done'] ? ' is-done' : ''; ?>">
						<span class="bn-setup-step__check" aria-hidden="true">
							<?php echo $step['done'] ? IconService::render( 'check' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
						</span>
						<span class="bn-setup-step__body">
							<span class="bn-setup-step__label"><?php echo esc_html( (string) $step['label'] ); ?></span>
							<span class="bn-setup-step__desc"><?php echo esc_html( (string) $step['desc'] ); ?></span>
						</span>
						<?php if ( ! $step['done'] ) : ?>
							<span class="bn-setup-step__actions">
								<?php if ( ! empty( $step['links'] ) && is_array( $step['links'] ) ) : ?>
									<?php foreach ( $step['links'] as $bn_link ) : ?>
										<a class="bn-setup-step__cta" href="<?php echo esc_url( (string) $bn_link['url'] ); ?>"<?php echo empty( $bn_link['external'] ) ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo esc_html( (string) $bn_link['label'] ); ?></a>
									<?php endforeach; ?>
								<?php else : ?>
									<a class="bn-setup-step__cta" href="<?php echo esc_url( (string) $step['cta'] ); ?>"><?php echo esc_html( (string) $step['cta_label'] ); ?></a>
								<?php endif; ?>
								<?php if ( '' !== (string) ( $step['dismiss'] ?? '' ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-setup-step__ack">
										<input type="hidden" name="action" value="bn_ack_theme_tip">
										<?php wp_nonce_field( 'bn_ack_theme_tip' ); ?>
										<button type="submit" class="bn-setup-step__ack-btn"><?php esc_html_e( 'Keep my theme', 'buddynext' ); ?></button>
									</form>
								<?php endif; ?>
							</span>
						<?php else : ?>
							<span class="bn-setup-step__status"><?php esc_html_e( 'Done', 'buddynext' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
