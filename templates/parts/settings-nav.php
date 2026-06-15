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

// Only sections that have fully migrated into the hub are linked. Account +
// Privacy remain on the profile editor until their save flow is moved here.
$bn_settings_tabs = array(
	'notifications' => __( 'Notifications', 'buddynext' ),
	'appearance'    => __( 'Appearance', 'buddynext' ),
);
?>
<header class="bn-settings__head">
	<h1 class="bn-settings__title"><?php esc_html_e( 'Settings', 'buddynext' ); ?></h1>
	<p class="bn-settings__sub"><?php esc_html_e( 'Manage your account, notifications, privacy, and appearance.', 'buddynext' ); ?></p>
</header>
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
</nav>
