<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Get Started (Home) admin screen.
 *
 * The admin landing. A fresh install used to open on Settings -> General - a
 * configuration form, before any orientation - which is where first-time owners
 * reported getting lost. This screen owns the top-level `buddynext` page slug
 * instead (see AdminHub::default_sections()): the owner lands on a welcome with
 * a "where do I go next" quick-link grid, above the setup checklist and demo
 * promo that AdminHub::render_section() already surfaces on the landing.
 *
 * It registers exactly one tab (`home`) under the `get-started` section and adds
 * no settings of its own - purely an orientation surface.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Core\IconService;

/**
 * Registers and renders the Get Started / Home landing.
 */
class GetStartedScreen {

	/**
	 * Register the Home tab under the get-started section.
	 *
	 * @return void
	 */
	public function register(): void {
		AdminHub::register_tab(
			'get-started',
			'home',
			__( 'Home', 'buddynext' ),
			array( $this, 'render' ),
			array(
				'position' => 10,
				'icon'     => 'home',
				'subtitle' => __( 'Set up your community and find your way around.', 'buddynext' ),
			)
		);
	}

	/**
	 * Render the Home body: welcome + orientation quick-links.
	 *
	 * The setup checklist and demo-data promo already render above this body on
	 * the landing (AdminHub::render_section()), so this stays focused on "what is
	 * this and where do I go next" rather than repeating those calls to action.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="bn-setup-card bn-home-welcome">
			<div class="bn-setup-card__head">
				<div class="bn-setup-card__heading">
					<h2 class="bn-setup-card__title"><?php esc_html_e( 'Welcome to BuddyNext', 'buddynext' ); ?></h2>
					<p class="bn-setup-card__sub">
						<?php esc_html_e( 'This is your community control room. Work through the setup checklist, load demo data to see how everything looks, then jump to any area below.', 'buddynext' ); ?>
					</p>
				</div>
			</div>
		</div>

		<?php
		// All landing orientation lives here in the Home body (below the header),
		// not as section chrome above it. The setup checklist self-hides once
		// dismissed or complete; the demo card owns its own seeded/empty state and
		// was previously buried three levels deep on Platform -> Tools.
		SetupChecklist::maybe_render( AdminHub::section_slug( 'get-started' ) );
		( new \BuddyNext\Demo\DemoAdmin() )->render_promo();
		$this->render_theme_card();
		?>

		<div class="bn-home-links">
			<?php
			foreach ( $this->links() as $link ) {
				$icon = IconService::has( $link['icon'] ) ? IconService::render( $link['icon'], 'bn-home-link__icon' ) : '';
				printf(
					'<a class="bn-home-link" href="%1$s"%2$s>%3$s<span class="bn-home-link__body"><span class="bn-home-link__title">%4$s</span><span class="bn-home-link__desc">%5$s</span></span></a>',
					esc_url( $link['url'] ),
					! empty( $link['external'] ) ? ' target="_blank" rel="noopener"' : '',
					$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService::render returns kses-safe SVG.
					esc_html( $link['title'] ),
					esc_html( $link['desc'] )
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Theme recommendation card.
	 *
	 * BuddyNext's full experience - dark mode, community chrome, tested layouts -
	 * depends on a purpose-built theme, and not every theme offers dark mode. This
	 * is a persistent suggestion on the Home (unlike the setup checklist step, which
	 * self-hides once dismissed) and stays visible even ON a community theme so the
	 * owner always has the upgrade path to BuddyX Pro or Reign. Only the copy adapts
	 * to the active theme; it reads the same recommended-theme source (SetupChecklist)
	 * so there is one list to maintain.
	 *
	 * @return void
	 */
	private function render_theme_card(): void {
		$on_recommended = SetupChecklist::using_recommended_theme();
		?>
		<div class="bn-setup-card bn-home-theme">
			<div class="bn-setup-card__head">
				<div class="bn-setup-card__heading">
					<h2 class="bn-setup-card__title">
						<?php
						echo $on_recommended
							? esc_html__( 'Explore premium community themes', 'buddynext' )
							: esc_html__( 'Get a community-ready theme', 'buddynext' );
						?>
					</h2>
					<p class="bn-setup-card__sub">
						<?php
						echo $on_recommended
							? esc_html__( 'You are on a community theme - nicely done. BuddyX Pro and Reign build on it with deeper layouts, headers, and community controls.', 'buddynext' )
							: esc_html__( 'The full BuddyNext experience - dark mode, community chrome, and layouts tested on every surface - needs a purpose-built theme, and not every theme offers dark mode. Start with BuddyX (free), or step up to BuddyX Pro or Reign.', 'buddynext' );
						?>
					</p>
				</div>
			</div>
			<div class="bn-home-theme__actions">
				<?php
				foreach ( SetupChecklist::theme_links() as $i => $link ) {
					printf(
						'<a class="bn-btn %1$s" href="%2$s"%3$s>%4$s</a>',
						0 === $i ? 'bn-btn-primary' : 'bn-btn-secondary',
						esc_url( $link['url'] ),
						! empty( $link['external'] ) ? ' target="_blank" rel="noopener"' : '',
						esc_html( $link['label'] )
					);
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Orientation destinations for a first-time owner.
	 *
	 * Internal links resolve through AdminHub so they follow the section/tab map
	 * (slug-independent): section landings via section_slug(), specific tabs via
	 * tab_url() with the tab's origin section.
	 *
	 * @return array<int, array{title:string, desc:string, icon:string, url:string, external?:bool}>
	 */
	private function links(): array {
		$section_url = static function ( string $section ): string {
			$slug = AdminHub::section_slug( $section );
			return '' === $slug ? admin_url( 'admin.php' ) : admin_url( 'admin.php?page=' . $slug );
		};

		return array(
			array(
				'title' => __( 'Members', 'buddynext' ),
				'desc'  => __( 'Directory, roles, registration and privacy.', 'buddynext' ),
				'icon'  => 'users',
				'url'   => $section_url( 'members' ),
			),
			array(
				'title' => __( 'Spaces', 'buddynext' ),
				'desc'  => __( 'Create and manage groups your members join.', 'buddynext' ),
				'icon'  => 'grid',
				'url'   => $section_url( 'spaces' ),
			),
			array(
				'title' => __( 'Add-ons and themes', 'buddynext' ),
				'desc'  => __( 'Connect apps and pick a community-ready theme.', 'buddynext' ),
				'icon'  => 'store',
				'url'   => AdminHub::tab_url( 'settings', 'integrations' ),
			),
			array(
				'title' => __( 'Appearance', 'buddynext' ),
				'desc'  => __( 'Colors, dark mode and community chrome.', 'buddynext' ),
				'icon'  => 'sparkles',
				'url'   => AdminHub::tab_url( 'settings', 'appearance' ),
			),
			array(
				'title' => __( 'Settings', 'buddynext' ),
				'desc'  => __( 'Identity, messaging and discovery defaults.', 'buddynext' ),
				'icon'  => 'settings',
				'url'   => $section_url( 'settings' ),
			),
			array(
				'title'    => __( 'View your community', 'buddynext' ),
				'desc'     => __( 'Open the live site your members see.', 'buddynext' ),
				'icon'     => 'external-link',
				'url'      => home_url( '/' ),
				'external' => true,
			),
		);
	}
}
