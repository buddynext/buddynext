<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Shared markup for the one-click install of the free GitHub site tools.
 *
 * The Home demo card and the Add-ons rows both drive the same script
 * (assets/js/admin/companion-install.js) through the data-* attributes and the
 * manual fallback rendered here, so the install, failure and "Check again"
 * behaviour reads the same in both places.
 *
 * @package BuddyNext\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Integrations;

/**
 * View helpers for GitHub tool installs.
 */
final class CompanionGithubUi {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'bn-companion-install';

	/**
	 * Enqueue the install script. Called from the renders that print an install control,
	 * so it loads only on the screens that show one.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$url = defined( 'BUDDYNEXT_URL' ) ? (string) constant( 'BUDDYNEXT_URL' ) : '';
		wp_enqueue_script(
			self::HANDLE,
			$url . 'assets/js/admin/companion-install.js',
			array(),
			defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0',
			true
		);
	}

	/**
	 * The data-* attributes the install script reads, escaped and ready to print.
	 *
	 * @param string                $slug   Tool slug.
	 * @param string                $status Current state: not_installed | inactive.
	 * @param array<string, string> $done   What the control becomes once active: title, sub, label, url, badge.
	 * @return string
	 */
	public static function attrs( string $slug, string $status, array $done = array() ): string {
		$attrs = array(
			'data-bn-gh-install'    => '',
			'data-slug'             => $slug,
			'data-rest-install'     => rest_url( 'buddynext/v1/companions/install' ),
			'data-rest-status'      => rest_url( 'buddynext/v1/companions/status' ),
			'data-nonce'            => wp_create_nonce( 'wp_rest' ),
			'data-i18n-installing'  => __( 'Installing', 'buddynext' ),
			'data-i18n-activating'  => __( 'Activating', 'buddynext' ),
			'data-i18n-downloading' => __( 'Downloading the latest release from GitHub. This takes a few seconds.', 'buddynext' ),
			'data-i18n-switching'   => __( 'Switching it on. This takes a moment.', 'buddynext' ),
			'data-i18n-done'        => __( 'Installed and switched on.', 'buddynext' ),
			'data-i18n-failed'      => __( 'The install did not finish.', 'buddynext' ),
			'data-i18n-network'     => __( 'The install did not finish: the connection to your site dropped.', 'buddynext' ),
			'data-i18n-missing'     => __( 'It is not installed yet. Upload the zip, then check again.', 'buddynext' ),
			'data-i18n-checking'    => __( 'Checking', 'buddynext' ),
			'data-state'            => $status,
		);
		foreach ( $done as $key => $value ) {
			$attrs[ 'data-done-' . sanitize_key( $key ) ] = $value;
		}

		$out = '';
		foreach ( $attrs as $name => $value ) {
			$value = str_starts_with( $name, 'data-rest-' ) || 'data-done-url' === $name ? esc_url( $value ) : esc_attr( $value );
			$out  .= sprintf( ' %s="%s"', $name, $value );
		}
		return $out;
	}

	/**
	 * Why a host that disables plugin installs (DISALLOW_FILE_MODS) gets the manual steps.
	 *
	 * @return string
	 */
	public static function blocked_reason(): string {
		return __( 'This site does not allow installing plugins from the dashboard, so it cannot be installed here.', 'buddynext' );
	}

	/**
	 * The manual-install fallback: the reason, then Download, Upload, Come back.
	 *
	 * Rendered hidden for the script to reveal on failure, or open with the reason when
	 * the host disables plugin installs (then there is nothing to try again).
	 *
	 * @param string $slug   Tool slug.
	 * @param string $reason Why the one-click install cannot run; empty renders it hidden.
	 * @return void
	 */
	public static function render_fallback( string $slug, string $reason = '' ): void {
		$manual = CompanionRegistry::manual( $slug );
		$open   = '' !== $reason;
		?>
		<div class="bn-gh-fallback" data-bn-gh-fallback<?php echo $open ? '' : ' hidden'; ?>>
			<p class="bn-gh-fallback__reason" role="alert" data-bn-gh-reason><?php echo esc_html( $reason ); ?></p>
			<p class="bn-gh-fallback__lead"><?php esc_html_e( 'You can install it by hand instead:', 'buddynext' ); ?></p>
			<ol class="bn-gh-fallback__steps">
				<li>
					<strong><?php esc_html_e( 'Download', 'buddynext' ); ?></strong>
					<a href="<?php echo esc_url( $manual['zip_url'] ); ?>" data-bn-gh-zip target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'the latest release zip from GitHub', 'buddynext' ); ?></a>
				</li>
				<li>
					<strong><?php esc_html_e( 'Upload', 'buddynext' ); ?></strong>
					<a href="<?php echo esc_url( $manual['upload_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Plugins > Add New > Upload Plugin', 'buddynext' ); ?></a>
				</li>
				<li>
					<strong><?php esc_html_e( 'Come back', 'buddynext' ); ?></strong>
					<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-bn-gh-check><?php esc_html_e( 'Check again', 'buddynext' ); ?></button>
				</li>
			</ol>
			<?php if ( ! $open ) : ?>
				<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-bn-gh-retry><?php esc_html_e( 'Try again', 'buddynext' ); ?></button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The live status line and progress bar under an install control.
	 *
	 * @return void
	 */
	public static function render_status(): void {
		?>
		<p class="bn-gh-status" role="status" aria-live="polite" data-bn-gh-status></p>
		<div class="bn-gh-progress" data-bn-gh-progress hidden><span class="bn-gh-progress__bar"></span></div>
		<?php
	}
}
