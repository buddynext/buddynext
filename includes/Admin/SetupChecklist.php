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
	 * Top-level BuddyNext admin page slug (the landing this card belongs to).
	 *
	 * @var string
	 */
	private const LANDING_SLUG = 'buddynext';

	/**
	 * Register the dismiss handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_dismiss_setup', array( $this, 'handle_dismiss' ) );
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
				'cta'       => admin_url( 'admin.php?page=buddynext&tab=pages' ),
				'cta_label' => __( 'Review pages', 'buddynext' ),
				'icon'      => 'link',
			),
			array(
				'key'       => 'features',
				'label'     => __( 'Choose your features', 'buddynext' ),
				'desc'      => __( 'Turn the parts of the community you want on or off.', 'buddynext' ),
				'done'      => false !== get_option( 'buddynext_features', false ),
				'cta'       => admin_url( 'admin.php?page=buddynext&tab=features' ),
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
				'cta'       => home_url( '/spaces/' ),
				'cta_label' => __( 'Create a space', 'buddynext' ),
				'icon'      => 'users',
			),
			array(
				'key'       => 'brand',
				'label'     => __( 'Brand it', 'buddynext' ),
				'desc'      => __( 'Set your accent colour so the community feels like yours.', 'buddynext' ),
				'done'      => false !== get_option( 'buddynext_brand_color', false ),
				'cta'       => admin_url( 'admin.php?page=buddynext&tab=appearance' ),
				'cta_label' => __( 'Customise', 'buddynext' ),
				'icon'      => 'palette',
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
							<a class="bn-setup-step__cta" href="<?php echo esc_url( (string) $step['cta'] ); ?>"><?php echo esc_html( (string) $step['cta_label'] ); ?></a>
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
