<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Demo data admin tab (Settings → Advanced → Demo Data).
 *
 * Surfaces the same DemoDataService the CLI uses behind two buttons: seed and
 * clean up. Both run synchronously via admin-post and redirect back with a
 * notice. There is no second seeding code path — this is purely a UI over the
 * shared engine.
 *
 * @package BuddyNext\Demo
 */

declare( strict_types=1 );

namespace BuddyNext\Demo;

use BuddyNext\Admin\AdminPageBase;

/**
 * Registers the Demo Data admin-post actions and renders its Tools section.
 */
class DemoAdmin {

	/**
	 * Register hooks + the admin-hub tab.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_demo_seed', array( $this, 'handle_seed' ) );
		add_action( 'admin_post_bn_demo_cleanup', array( $this, 'handle_cleanup' ) );
	}

	/**
	 * Render the Demo Data section (embedded inside Settings → Tools).
	 *
	 * @return void
	 */
	public function render_section(): void {
		$service = new DemoDataService();
		$seeded  = $service->is_seeded();
		$summary = $service->summary();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['bn_demo'] ) ? sanitize_key( wp_unslash( (string) $_GET['bn_demo'] ) ) : '';
		if ( 'seeded' === $notice ) {
			AdminPageBase::render_notice( __( 'Demo data installed.', 'buddynext' ), 'success' );
		} elseif ( 'cleaned' === $notice ) {
			AdminPageBase::render_notice( __( 'Demo data removed.', 'buddynext' ), 'success' );
		}
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Demo Data', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Populate the community with realistic members, spaces, posts, comments, reactions, follows and connections — using bundled offline images. Use it to evaluate every surface on a fresh install, then remove it all with one click.', 'buddynext' ); ?>
				</p>

				<?php if ( $seeded ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: members, 2: spaces, 3: posts, 4: profile fields */
								__( 'Installed: %1$d members, %2$d spaces, %3$d posts, %4$d profile fields.', 'buddynext' ),
								$summary['users'],
								$summary['spaces'],
								$summary['posts'],
								$summary['fields']
							)
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						data-bn-confirm="<?php echo esc_attr__( 'Remove all demo data? This cannot be undone.', 'buddynext' ); ?>" data-bn-confirm-tone="danger">
						<input type="hidden" name="action" value="bn_demo_cleanup">
						<?php wp_nonce_field( 'bn_demo_cleanup' ); ?>
						<button type="submit" class="bn-btn" data-variant="danger"><?php esc_html_e( 'Remove Demo Data', 'buddynext' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_demo_seed">
						<?php wp_nonce_field( 'bn_demo_seed' ); ?>
						<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Install Demo Data', 'buddynext' ); ?></button>
					</form>
					<p class="bn-av-section-desc" style="margin-top:8px;">
						<?php esc_html_e( 'Tip: the same engine is available on the command line via', 'buddynext' ); ?>
						<code>wp buddynext demo seed</code>.
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Compact demo-data promo for the admin landing.
	 *
	 * The full seed/remove UI lives on Platform -> Tools, which a first-time owner
	 * never opens - so a fresh install is an empty ghost town with the one action
	 * that would populate it hidden three levels deep. This surfaces the demo
	 * control right on the landing in BOTH states: a one-click "Load a demo
	 * community" when empty, and a "Remove demo data" once seeded (so the owner
	 * can clear the sample content from the same place they added it, without
	 * hunting through Platform -> Tools). Reuses the same bn_demo_seed /
	 * bn_demo_cleanup handlers + nonces as render_section().
	 *
	 * @return void
	 */
	public function render_promo(): void {
		$service = new DemoDataService();
		$seeded  = $service->is_seeded();

		$title  = $seeded
			? __( 'Demo data is active', 'buddynext' )
			: __( 'See your community with demo data', 'buddynext' );
		$sub    = $seeded ? '' : __( 'Not sure what a live community looks like on your site? Add realistic sample members, spaces, and posts in one click - then remove it all any time.', 'buddynext' );
		$action = $seeded ? 'bn_demo_cleanup' : 'bn_demo_seed';
		$label  = $seeded ? __( 'Remove demo data', 'buddynext' ) : __( 'Load a demo community', 'buddynext' );

		if ( $seeded ) {
			$summary = $service->summary();
			$sub     = sprintf(
				/* translators: 1: members, 2: spaces, 3: posts */
				__( 'Sample content is loaded: %1$d members, %2$d spaces, %3$d posts. Remove it any time - your real content is never touched.', 'buddynext' ),
				$summary['users'],
				$summary['spaces'],
				$summary['posts']
			);
		}
		?>
		<div class="bn-setup-card bn-demo-promo">
			<div class="bn-setup-card__head">
				<div class="bn-setup-card__heading">
					<h2 class="bn-setup-card__title"><?php echo esc_html( $title ); ?></h2>
					<p class="bn-setup-card__sub"><?php echo esc_html( $sub ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-demo-promo__form"
					<?php if ( $seeded ) : ?>
					data-bn-confirm="<?php echo esc_attr__( 'Remove all demo data? This cannot be undone.', 'buddynext' ); ?>" data-bn-confirm-tone="danger"
					<?php endif; ?>>
					<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
					<?php wp_nonce_field( $action ); ?>
					<button type="submit" class="bn-btn" data-variant="<?php echo $seeded ? 'danger' : 'primary'; ?>"><?php echo esc_html( $label ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the seed button.
	 *
	 * @return void
	 */
	public function handle_seed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_demo_seed' );

		( new DemoDataService() )->seed();
		\BuddyNext\Admin\Insights::flush();

		$this->redirect_back( 'seeded' );
	}

	/**
	 * Handle the cleanup button.
	 *
	 * @return void
	 */
	public function handle_cleanup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}
		check_admin_referer( 'bn_demo_cleanup' );

		( new DemoDataService() )->cleanup();
		\BuddyNext\Admin\Insights::flush();

		$this->redirect_back( 'cleaned' );
	}

	/**
	 * Redirect back to the Demo Data tab with a status flag.
	 *
	 * @param string $status seeded|cleaned.
	 * @return void
	 */
	private function redirect_back( string $status ): void {
		wp_safe_redirect( \BuddyNext\Admin\AdminHub::tab_url( 'settings', 'tools', array( 'bn_demo' => $status ) ) );
		exit;
	}
}
