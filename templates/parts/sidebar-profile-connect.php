<?php
/**
 * BuddyNext template part: sidebar-profile-connect.
 *
 * Self-chromed "Connect" sidebar card listing the profile's social links.
 * Extracted verbatim from the former
 * `templates/partials/profile-right-sidebar.php` so ProfileSidebarProvider
 * can render it as a `chrome => false` widget descriptor. Empty
 * `$social_links` self-hides the card (no output).
 *
 * @package BuddyNext
 *
 * @var array $social_links Non-empty social-link field rows (label/value).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_social = isset( $social_links ) && is_array( $social_links ) ? $social_links : array();

if ( $bn_pf_social ) :
	?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Connect', 'buddynext' ); ?></div>
	<?php foreach ( $bn_pf_social as $field ) : ?>
		<div class="bn-field-row">
			<span class="bn-field-label"><?php echo esc_html( $field['label'] ); ?></span>
			<span class="bn-field-value">
				<a href="<?php echo esc_url( (string) ( $field['value'] ?? '' ) ); ?>"
					target="_blank" rel="noopener noreferrer me">
					<?php
					$parsed_host = wp_parse_url( (string) ( $field['value'] ?? '' ), PHP_URL_HOST );
					echo esc_html( $parsed_host ? $parsed_host : (string) ( $field['value'] ?? '' ) );
					?>
				</a>
			</span>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>
