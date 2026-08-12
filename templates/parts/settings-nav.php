<?php
/**
 * Settings tab strip — shared across every /settings/ section.
 *
 * Uses the `.bn-tabs` / `.bn-tab` primitive (styled in bn-base.css, including
 * the `[aria-selected="true"]` active state) so the Settings hub navigation
 * matches profile tabs and needs no JavaScript — each tab is a plain link to a
 * section route.
 *
 * @var string $bn_settings_active Current section slug (account|notifications|privacy|appearance).
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$bn_settings_active = isset( $bn_settings_active ) ? (string) $bn_settings_active : 'account';

$bn_settings_tabs = array(
	'account'       => __( 'Account', 'buddynext' ),
	'notifications' => __( 'Notifications', 'buddynext' ),
	'privacy'       => __( 'Privacy', 'buddynext' ),
	'appearance'    => __( 'Appearance', 'buddynext' ),
);

/**
 * Filter the Settings hub tab strip so addons can register their own sections.
 *
 * Each entry is `section-slug => Tab label`. The slug must resolve through
 * PageRouter::settings_url() (a bare slug maps to /settings/{slug}/) and the
 * addon is responsible for routing that URL + providing its section template.
 *
 * @param array<string,string> $bn_settings_tabs   Section slug => tab label.
 * @param string               $bn_settings_active Active section slug.
 */
$bn_settings_tabs = (array) apply_filters( 'buddynext_settings_tabs', $bn_settings_tabs, $bn_settings_active );
?>
<header class="bn-settings__head">
	<h1 class="bn-settings__title"><?php esc_html_e( 'Settings', 'buddynext' ); ?></h1>
	<p class="bn-settings__sub"><?php esc_html_e( 'Manage your account, notifications, privacy, and appearance.', 'buddynext' ); ?></p>
</header>
<?php // .bn-navgroup opts the strip into the shared overflow chevrons (shell/extras.js), the cross-browser "more tabs" affordance - the CSS edge-fade alone is Chrome-only. ?>
<div class="bn-navgroup">
<nav class="bn-tabs bn-settings-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'buddynext' ); ?>">
	<?php
	foreach ( $bn_settings_tabs as $bn_slug => $bn_label ) :
		$bn_is_active = ( $bn_slug === $bn_settings_active );
		?>
		<a
			class="bn-tab<?php echo $bn_is_active ? ' is-active' : ''; ?>"
			role="tab"
			aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
			href="<?php echo esc_url( PageRouter::settings_url( $bn_slug ) ); ?>"
		>
			<span class="bn-tab__label"><?php echo esc_html( $bn_label ); ?></span>
		</a>
	<?php endforeach; ?>
	<?php
	// Community Admin lives here (not in the left rail) to keep the rail clean.
	// It is a manager surface, so it is gated to moderators/admins and links out
	// to the routed hub rather than a /settings/{slug}/ section — hence rendered
	// as a special tab outside the slug -> settings_url() loop above.
	if ( function_exists( 'buddynext_can' ) && buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate' ) ) :
		?>
		<a
			class="bn-tab bn-settings-tabs__manage"
			role="tab"
			aria-selected="false"
			href="<?php echo esc_url( PageRouter::community_admin_url() ); ?>"
		>
			<span class="bn-tab__label"><?php esc_html_e( 'Community Admin', 'buddynext' ); ?></span>
		</a>
	<?php endif; ?>
</nav>
</div>
