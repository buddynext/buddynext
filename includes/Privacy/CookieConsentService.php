<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Cookie consent banner.
 *
 * Renders a dismissible cookie-consent banner on the front end when the
 * Settings → Privacy → "Cookie Consent Banner" option is enabled. Dismissal is
 * remembered in a first-party cookie holding the version acknowledged, so a
 * returning visitor is not asked again until the notice or the policy changes.
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
	 * Version of what the visitor is agreeing to.
	 *
	 * Built from the notice text and the privacy policy page, including when
	 * that page was last edited. Acknowledging stores this version, so editing
	 * the notice or the policy shows the notice to everyone again.
	 *
	 * @return string
	 */
	private function version(): string {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy' );
		$parts   = array(
			(string) get_option( 'buddynext_cookie_consent_text', '' ),
			(string) $page_id,
			$page_id > 0 ? (string) get_post_field( 'post_modified_gmt', $page_id ) : '',
		);
		return substr( md5( implode( '|', $parts ) ), 0, 12 );
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
	 * Loads for every visitor while the notice is on: whether this visitor
	 * already acknowledged is decided in the browser, so a page cache can never
	 * store a copy without the notice. Tiny classic script, in the footer.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		wp_enqueue_script(
			'bn-cookie-consent',
			BUDDYNEXT_URL . 'assets/js/privacy/consent-banner.js',
			array(),
			BUDDYNEXT_VERSION,
			true
		);
	}

	/**
	 * Output the banner, hidden; the script reveals it unless the visitor's
	 * cookie holds the current version.
	 *
	 * @return void
	 */
	public function render(): void {
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
		<?php
		// The notice renders on every page, but the host-theme palette only applies
		// under [data-bn-theme], which <html> carries on BuddyNext pages alone. The
		// attribute here keeps the site's colours on ordinary pages too; "inherit"
		// matches no light/dark rule, so the page's own mode still flows in.
		?>
		<div class="bn-cookie-consent" data-bn-theme="inherit" role="region" aria-label="<?php esc_attr_e( 'Cookie notice', 'buddynext' ); ?>" data-bn-cookie-consent data-cookie-name="<?php echo esc_attr( self::COOKIE ); ?>" data-cookie-version="<?php echo esc_attr( $this->version() ); ?>" hidden>
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
