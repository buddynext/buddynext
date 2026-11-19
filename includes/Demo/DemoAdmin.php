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
use BuddyNext\Admin\SetupChecklist;

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
				<span class="bn-ss-title"><?php esc_html_e( 'Demo data', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
				<p class="bn-av-section-desc">
					<?php esc_html_e( 'Populate the community with realistic members, spaces, posts, comments, reactions, follows and connections: using bundled offline images. Use it to evaluate every surface on a fresh install, then remove it all with one click.', 'buddynext' ); ?>
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
						<button type="submit" class="bn-btn" data-variant="danger"><?php esc_html_e( 'Remove demo data', 'buddynext' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bn_demo_seed">
						<?php wp_nonce_field( 'bn_demo_seed' ); ?>
						<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Install demo data', 'buddynext' ); ?></button>
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
		if ( ! $seeded && SetupChecklist::is_done() ) {
			return; // Setup is over: offer no demo, but keep "Remove demo data" for one that is on the site.
		}

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
					<input type="hidden" name="bn_demo_origin" value="get-started">
					<?php wp_nonce_field( $action ); ?>
					<button type="submit" class="bn-btn" data-variant="<?php echo $seeded ? 'danger' : 'primary'; ?>"><?php echo esc_html( $label ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * The Wbcom Demo Installer card, ahead of BuddyNext's own sample community.
	 *
	 * The installer builds a whole demo (theme, every Wbcom product, a designed home page)
	 * and works from its own wizard, so this card only reads its status and links in. It
	 * never copies its content. BuddyNext's built-in seeder stays below for sites that run
	 * another theme or no installer.
	 *
	 * @return void
	 */
	public function render_installer_card(): void {
		$wizard = admin_url( 'themes.php?page=wbcom-demo' );
		$state  = function_exists( 'wbcom_demo_status' ) ? wbcom_demo_status() : null;
		// Installer 1.1.x is active but predates wbcom_demo_status(): no state to show, only the way in.
		$status = null !== $state ? (string) ( $state['status'] ?? 'idle' ) : ( defined( 'WBCOM_DEMO_DIR' ) ? 'idle' : 'missing' );
		$demo   = null === $state ? '' : (string) ( $state['demo_label'] ?? '' );
		$left   = null === $state ? 0 : (int) ( $state['samples_left'] ?? 0 );

		// Setup that is already done is not offered again. A demo that is building or built stays
		// visible, because the owner still has to finish, take it live or remove it.
		$in_play = in_array( $status, array( 'building', 'finishing', 'built' ), true );
		if ( ! $in_play && ( 'finished' === $status || SetupChecklist::is_done() ) ) {
			return;
		}

		if ( 'missing' === $status ) {
			$title = __( 'Preview your whole site with the Wbcom Demo Installer', 'buddynext' );
			$sub   = __( 'A free plugin that sets up your theme and the Wbcom products with sample people and content and a designed home page, then removes it all in one click.', 'buddynext' );
			$label = __( 'Get the installer', 'buddynext' );
			$url   = 'https://github.com/buddynext/wbcom-demo-installer/releases/latest';
			$ext   = true;
		} elseif ( in_array( $status, array( 'building', 'finishing' ), true ) ) {
			$title = __( 'Your demo is being set up', 'buddynext' );
			$sub   = __( 'The installer is working. Open it to follow the progress.', 'buddynext' );
			$label = __( 'Open the installer', 'buddynext' );
			$url   = $wizard;
			$ext   = false;
		} elseif ( 'built' === $status ) {
			$title = '' !== $demo
				/* translators: %s: demo name */
				? sprintf( __( 'Your %s demo is ready', 'buddynext' ), $demo )
				: __( 'Your demo is ready', 'buddynext' );
			$sub = sprintf(
				/* translators: %d: number of sample items */
				_n( '%d sample item is on the site. Open the installer to look around, take it live or remove everything.', '%d sample items are on the site. Open the installer to look around, take it live or remove everything.', max( 1, $left ), 'buddynext' ),
				$left
			);
			$label = __( 'Manage the demo', 'buddynext' );
			$url   = $wizard;
			$ext   = false;
		} else {
			$title = __( 'Preview your whole site with the Wbcom Demo Installer', 'buddynext' );
			$sub   = __( 'Pick what your site is for and the installer builds a demo with your theme and the Wbcom products, then removes it in one click.', 'buddynext' );
			$label = __( 'Set up a demo', 'buddynext' );
			$url   = $wizard;
			$ext   = false;
		}
		?>
		<div class="bn-setup-card bn-demo-promo bn-demo-installer">
			<div class="bn-setup-card__head">
				<div class="bn-setup-card__heading">
					<h2 class="bn-setup-card__title"><?php echo esc_html( $title ); ?></h2>
					<p class="bn-setup-card__sub"><?php echo esc_html( $sub ); ?></p>
				</div>
				<a class="bn-btn" data-variant="primary" href="<?php echo esc_url( $url ); ?>"<?php echo $ext ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $label ); ?></a>
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
		// Return the owner to where they launched the seed/cleanup from. The promo on
		// the Get Started landing carries bn_demo_origin=get-started; anything else
		// (the full Tools tab) keeps the historical Tools destination. The nonce was
		// already verified by the calling handler.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller ran check_admin_referer().
		$origin = isset( $_POST['bn_demo_origin'] ) ? sanitize_key( wp_unslash( $_POST['bn_demo_origin'] ) ) : '';
		if ( 'wizard' === $origin ) {
			// Back to the wizard's Done step, which shows what was added.
			$url = add_query_arg(
				array(
					'page'    => 'buddynext-setup',
					'step'    => 'done',
					'bn_demo' => $status,
				),
				admin_url( 'admin.php' )
			);
		} elseif ( 'get-started' === $origin ) {
			$slug = \BuddyNext\Admin\AdminHub::section_slug( 'get-started' );
			$base = '' === $slug ? admin_url( 'admin.php' ) : admin_url( 'admin.php?page=' . $slug );
			$url  = add_query_arg( 'bn_demo', $status, $base );
		} else {
			$url = \BuddyNext\Admin\AdminHub::tab_url( 'settings', 'tools', array( 'bn_demo' => $status ) );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
