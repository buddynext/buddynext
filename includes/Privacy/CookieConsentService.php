<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Cookie consent banner.
 *
 * Renders a dismissible cookie-consent banner on the front end when the
 * Settings → Privacy → "Cookie Consent Banner" option is enabled. Dismissal is
 * remembered in a first-party cookie so a returning visitor is not nagged again.
 *
 * @package BuddyNext\Privacy
 */

declare( strict_types=1 );

namespace BuddyNext\Privacy;

/**
 * Front-end cookie consent banner.
 */
class CookieConsentService {

	/**
	 * Cookie that records the visitor's acknowledgement.
	 */
	private const COOKIE = 'bn_cookie_consent';

	/**
	 * The built-in banner wording.
	 *
	 * Single source for the default copy: shown when the owner has not set a
	 * custom `buddynext_cookie_consent_text`, and offered as the placeholder /
	 * registered default of that option so editing is opt-in (plug-and-play).
	 *
	 * @return string
	 */
	public static function default_message(): string {
		return __( 'We use cookies to keep you signed in and to improve your experience. By continuing to browse, you agree to our use of cookies.', 'buddynext' );
	}

	/**
	 * Register hooks. No-op unless the banner is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! (bool) get_option( 'buddynext_cookie_consent', false ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Enqueue the banner behaviour script on the front end.
	 *
	 * Only loads when the banner will actually render (the visitor has not yet
	 * acknowledged it), so returning visitors pay no JS cost. The script is in
	 * assets/js/privacy/consent-banner.js — no inline script (UX-audit F2 rule).
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cookie presence check, no state change.
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		wp_enqueue_script(
			'bn-cookie-consent',
			BUDDYNEXT_URL . 'assets/js/privacy/consent-banner.js',
			array(),
			BUDDYNEXT_VERSION,
			true
		);
	}

	/**
	 * Output the banner — only when the visitor has not already acknowledged it.
	 *
	 * @return void
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cookie presence check, no state change.
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}

		$privacy_url = (int) get_option( 'wp_page_for_privacy_policy' ) > 0
			? get_privacy_policy_url()
			: '';
		$custom      = trim( (string) get_option( 'buddynext_cookie_consent_text', '' ) );
		$message     = '' !== $custom ? $custom : self::default_message();

		// Accept + policy-link labels are owner-editable (blank = the default).
		$custom_accept = trim( (string) get_option( 'buddynext_cookie_consent_accept_label', '' ) );
		$accept_label  = '' !== $custom_accept ? $custom_accept : __( 'Got it', 'buddynext' );
		$custom_policy = trim( (string) get_option( 'buddynext_cookie_consent_policy_label', '' ) );
		$policy_label  = '' !== $custom_policy ? $custom_policy : __( 'Privacy policy', 'buddynext' );
		?>
		<div class="bn-cookie-consent" role="region" aria-label="<?php esc_attr_e( 'Cookie notice', 'buddynext' ); ?>" data-bn-cookie-consent data-cookie-name="<?php echo esc_attr( self::COOKIE ); ?>" hidden>
			<p class="bn-cookie-consent__text">
				<?php echo esc_html( $message ); ?>
				<?php if ( '' !== $privacy_url ) : ?>
					<a class="bn-cookie-consent__link" href="<?php echo esc_url( $privacy_url ); ?>"><?php echo esc_html( $policy_label ); ?></a>
				<?php endif; ?>
			</p>
			<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-bn-cookie-accept>
				<?php echo esc_html( $accept_label ); ?>
			</button>
		</div>
		<?php
		// Reveal + accept behaviour lives in assets/js/privacy/consent-banner.js
		// (enqueued by enqueue_assets), reading the cookie name from the
		// data-cookie-name attribute above. No inline script — UX-audit F2 rule.
	}
}
