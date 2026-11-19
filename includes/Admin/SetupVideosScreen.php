<?php
/**
 * Setup videos tab on Home.
 *
 * Plays the short setup films (each under five minutes) in a player on the page, with the
 * film for the active theme first. The longer guide videos stay on buddynext.com, linked
 * below the films. No settings, no storage: a list of films and links.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Core\IconService;

/**
 * Registers and renders the Setup videos tab under the get-started section.
 */
class SetupVideosScreen {

	/**
	 * Wbcom Designs channel.
	 */
	private const CHANNEL_URL = 'https://www.youtube.com/@WbcomDesigns';

	/**
	 * Guide-video series on buddynext.com: path under /videos/ => label.
	 *
	 * @return array<string,string>
	 */
	private static function series(): array {
		return array(
			'tutorials-start-here' => __( 'Start here', 'buddynext' ),
			'tutorials-spaces'     => __( 'Spaces', 'buddynext' ),
			'tutorials-members'    => __( 'Members', 'buddynext' ),
			'tutorials-community'  => __( 'Community', 'buddynext' ),
			'tutorials-pro'        => __( 'BuddyNext Pro', 'buddynext' ),
			'tutorials-whats-new'  => __( 'What\'s new', 'buddynext' ),
		);
	}

	/**
	 * The setup films, in the order the cards appear.
	 *
	 * `theme` is the theme the film is shot on (buddyx, buddyxpro, reign); `live` marks the
	 * film that applies to every theme. `id` is the YouTube video id.
	 *
	 * @return array<int,array{id:string,theme:string,live:bool,length:string,title:string,blurb:string}>
	 */
	public static function films(): array {
		return array(
			array(
				'id'     => 'Q1MPFOxc388',
				'theme'  => 'buddyx',
				'live'   => false,
				'length' => '3:46',
				'title'  => __( 'Set up a complete community site in 5 minutes', 'buddynext' ),
				'blurb'  => __( 'Starts on a fresh site with only the theme and the free Demo Installer. One click installs every plugin and builds the site.', 'buddynext' ),
			),
			array(
				'id'     => 'WijuvjG6WiE',
				'theme'  => 'buddyx',
				'live'   => true,
				'length' => '2:38',
				'title'  => __( 'Take your demo site live and keep your setup', 'buddynext' ),
				'blurb'  => __( 'Remove the sample people and content and keep the pages, menus and settings you set up.', 'buddynext' ),
			),
			array(
				'id'     => 'W2rs9rpKEYk',
				'theme'  => 'buddyxpro',
				'live'   => false,
				'length' => '3:12',
				'title'  => __( 'Build an all-in-one community site on BuddyX Pro', 'buddynext' ),
				'blurb'  => __( 'Community, courses, events, jobs and a directory on BuddyX Pro, built in one run.', 'buddynext' ),
			),
			array(
				'id'     => 'jLANmm3uAGI',
				'theme'  => 'reign',
				'live'   => false,
				'length' => '3:09',
				'title'  => __( 'Build an all-in-one community site on Reign', 'buddynext' ),
				'blurb'  => __( 'The whole Wbcom suite on Reign: community, courses, events, jobs, a directory and services.', 'buddynext' ),
			),
			array(
				'id'     => 'vxM1m22RxJ8',
				'theme'  => 'reign',
				'live'   => false,
				'length' => '3:10',
				'title'  => __( 'Build an online community with forums and media', 'buddynext' ),
				'blurb'  => __( 'Members, spaces, forums and photo and video sharing on Reign.', 'buddynext' ),
			),
			array(
				'id'     => 'csWX1FwJ3XY',
				'theme'  => 'reign',
				'live'   => false,
				'length' => '3:00',
				'title'  => __( 'Build an online course site with quizzes', 'buddynext' ),
				'blurb'  => __( 'Courses, lessons and quizzes with a learning community around them.', 'buddynext' ),
			),
			array(
				'id'     => 'IQkm4nDu4IM',
				'theme'  => 'reign',
				'live'   => false,
				'length' => '2:49',
				'title'  => __( 'Build an events site with RSVPs', 'buddynext' ),
				'blurb'  => __( 'Events, RSVPs and a calendar for your members.', 'buddynext' ),
			),
			array(
				'id'     => 'I_lc8uBm8Q8',
				'theme'  => 'reign',
				'live'   => false,
				'length' => '2:55',
				'title'  => __( 'Build a job board site', 'buddynext' ),
				'blurb'  => __( 'Job listings, applications and employer profiles.', 'buddynext' ),
			),
			array(
				'id'     => '6vXdtmoZ-uc',
				'theme'  => 'reign',
				'live'   => false,
				'length' => '3:02',
				'title'  => __( 'Build a business directory with reviews', 'buddynext' ),
				'blurb'  => __( 'A searchable directory with listings and reviews.', 'buddynext' ),
			),
			array(
				'id'     => 'WmfIJOV8Tgc',
				'theme'  => 'reign',
				'live'   => false,
				'length' => '2:56',
				'title'  => __( 'Build a freelance services marketplace', 'buddynext' ),
				'blurb'  => __( 'Sellers, service listings and orders in a community marketplace.', 'buddynext' ),
			),
		);
	}

	/**
	 * Which of the films' themes the site runs: buddyx, buddyxpro or reign.
	 *
	 * Any other theme gets BuddyX, the free community theme BuddyNext recommends.
	 *
	 * @return string
	 */
	public static function site_theme(): string {
		$template = get_template();
		return match ( true ) {
			str_starts_with( $template, 'reign' ) => 'reign',
			str_starts_with( $template, 'buddyx-pro' ), 'buddyxpro' === $template => 'buddyxpro',
			default => 'buddyx',
		};
	}

	/**
	 * The film that plays first: the all-in-one film for the theme, or the BuddyX starter.
	 *
	 * @param string $theme Theme key from site_theme().
	 * @return array{id:string,theme:string,live:bool,length:string,title:string,blurb:string}
	 */
	public static function featured( string $theme ): array {
		$films = self::films();
		foreach ( $films as $film ) {
			if ( ! $film['live'] && $film['theme'] === $theme ) {
				return $film;
			}
		}
		return $films[0];
	}

	/**
	 * Register the tab under Home.
	 *
	 * @return void
	 */
	public function register(): void {
		AdminHub::register_tab(
			'get-started',
			'videos',
			__( 'Setup videos', 'buddynext' ),
			array( $this, 'render' ),
			array(
				'position' => 20,
				'icon'     => 'play',
				'subtitle' => __( 'Watch a site come together in under five minutes. Each film starts on a fresh site.', 'buddynext' ),
			)
		);
	}

	/**
	 * Render the player, the film cards and the links to the longer guides.
	 *
	 * @return void
	 */
	public function render(): void {
		$theme    = self::site_theme();
		$featured = self::featured( $theme );
		$films    = self::films();
		$base     = defined( 'BUDDYNEXT_URL' ) ? constant( 'BUDDYNEXT_URL' ) : plugins_url( '', __DIR__ );
		$version  = defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0';
		wp_enqueue_script( 'bn-admin-setup-videos', $base . 'assets/js/admin/setup-videos.js', array(), $version, true );

		$installer = defined( 'WBCOM_DEMO_DIR' );
		$demo_url  = $installer ? admin_url( 'themes.php?page=wbcom-demo' ) : 'https://github.com/buddynext/wbcom-demo-installer/releases/latest';
		$show_demo = ! SetupChecklist::is_done();
		?>
		<div class="bn-videos" data-bn-videos data-theme="<?php echo esc_attr( $theme ); ?>">
			<div class="bn-videos__stage">
				<div class="bn-videos__main bn-setup-card">
					<div class="bn-videos__frame" data-bn-video-frame>
						<?php $this->render_poster( $featured ); ?>
					</div>
					<div class="bn-videos__info">
						<p class="bn-videos__eyebrow" data-bn-video-meta><?php echo esc_html( $this->meta( $featured ) ); ?></p>
						<h2 class="bn-videos__title" data-bn-video-title><?php echo esc_html( $featured['title'] ); ?></h2>
						<p class="bn-videos__blurb" data-bn-video-blurb><?php echo esc_html( $featured['blurb'] ); ?></p>
						<?php if ( $show_demo ) : ?>
							<p class="bn-videos__cta"><a class="bn-btn" data-variant="primary" href="<?php echo esc_url( $demo_url ); ?>"<?php echo $installer ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo esc_html( $installer ? __( 'Set up a demo', 'buddynext' ) : __( 'Get the Demo Installer', 'buddynext' ) ); ?></a></p>
						<?php endif; ?>
					</div>
				</div>

				<aside class="bn-videos__list bn-setup-card" aria-label="<?php esc_attr_e( 'Setup films', 'buddynext' ); ?>">
					<div class="bn-videos__filters" role="group" aria-label="<?php esc_attr_e( 'Filter the films', 'buddynext' ); ?>">
						<button type="button" class="bn-videos__chip is-active" data-bn-video-filter="theme" aria-pressed="true"><?php esc_html_e( 'For your theme', 'buddynext' ); ?></button>
						<button type="button" class="bn-videos__chip" data-bn-video-filter="all" aria-pressed="false">
							<?php
							/* translators: %d: number of films */
							echo esc_html( sprintf( __( 'All (%d)', 'buddynext' ), count( $films ) ) );
							?>
						</button>
						<button type="button" class="bn-videos__chip" data-bn-video-filter="live" aria-pressed="false"><?php esc_html_e( 'Going live', 'buddynext' ); ?></button>
					</div>
					<ul class="bn-videos__rows">
						<?php foreach ( $films as $film ) : ?>
							<li class="bn-videos__item" data-theme="<?php echo esc_attr( $film['theme'] ); ?>" data-live="<?php echo $film['live'] ? '1' : '0'; ?>">
								<a
									class="bn-videos__row<?php echo $film['id'] === $featured['id'] ? ' is-current' : ''; ?>"
									href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . $film['id'] ); ?>"
									target="_blank" rel="noopener"
									data-id="<?php echo esc_attr( $film['id'] ); ?>"
									data-title="<?php echo esc_attr( $film['title'] ); ?>"
									data-meta="<?php echo esc_attr( $this->meta( $film ) ); ?>"
									data-blurb="<?php echo esc_attr( $film['blurb'] ); ?>"
								>
									<span class="bn-videos__thumb"><img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $film['id'] . '/mqdefault.jpg' ); ?>" alt="" width="320" height="180" loading="lazy" decoding="async"></span>
									<span class="bn-videos__row-body">
										<span class="bn-videos__now"><?php esc_html_e( 'Now playing', 'buddynext' ); ?></span>
										<span class="bn-videos__row-title"><?php echo esc_html( $film['title'] ); ?></span>
										<span class="bn-videos__row-meta"><?php echo esc_html( $this->meta( $film ) ); ?></span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</aside>
			</div>

			<div class="bn-setup-card bn-videos__more">
				<div class="bn-setup-card__head">
					<div class="bn-setup-card__heading">
						<h2 class="bn-setup-card__title"><?php esc_html_e( 'All BuddyNext tutorials', 'buddynext' ); ?></h2>
						<p class="bn-setup-card__sub"><?php esc_html_e( 'The longer guide videos, in series, on buddynext.com.', 'buddynext' ); ?></p>
					</div>
					<a class="bn-btn" data-variant="primary" href="https://buddynext.com/videos/" target="_blank" rel="noopener"><?php esc_html_e( 'Browse all videos', 'buddynext' ); ?></a>
				</div>
				<ul class="bn-videos__series">
					<?php foreach ( self::series() as $path => $label ) : ?>
						<li><a class="bn-videos__chip" href="<?php echo esc_url( 'https://buddynext.com/videos/' . $path . '/' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="bn-setup-card bn-videos__more">
				<div class="bn-setup-card__head">
					<div class="bn-setup-card__heading">
						<h2 class="bn-setup-card__title"><?php esc_html_e( 'More from the Wbcom Designs channel', 'buddynext' ); ?></h2>
						<p class="bn-setup-card__sub"><?php esc_html_e( 'Themes, add-ons and the rest of the Wbcom products, on YouTube.', 'buddynext' ); ?></p>
					</div>
					<a class="bn-btn" data-variant="secondary" href="<?php echo esc_url( self::CHANNEL_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the channel', 'buddynext' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The poster shown before play: the thumbnail and a play button. The script swaps it for the player.
	 *
	 * @param array{id:string,length:string,title:string} $film Film.
	 * @return void
	 */
	private function render_poster( array $film ): void {
		?>
		<button type="button" class="bn-videos__poster" data-id="<?php echo esc_attr( $film['id'] ); ?>">
			<img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $film['id'] . '/maxresdefault.jpg' ); ?>" alt="" width="1280" height="720" decoding="async">
			<span class="bn-videos__play"><?php echo IconService::render( 'play', 'bn-videos__play-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns wp_kses'd SVG markup. ?></span>
			<span class="screen-reader-text">
				<?php
				/* translators: %s: film title */
				echo esc_html( sprintf( __( 'Play: %s', 'buddynext' ), $film['title'] ) );
				?>
			</span>
		</button>
		<?php
	}

	/**
	 * The small line above a film's title: the theme it is shot on and its length.
	 *
	 * @param array{theme:string,live:bool,length:string} $film Film.
	 * @return string
	 */
	private function meta( array $film ): string {
		$themes = array(
			'buddyx'    => 'BuddyX',
			'buddyxpro' => 'BuddyX Pro',
			'reign'     => 'Reign',
		);
		return ( $themes[ $film['theme'] ] ?? '' ) . ' · ' . $film['length'];
	}
}
