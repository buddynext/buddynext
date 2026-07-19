<?php
/**
 * BuddyNext template part: sidebar-profile-skills.
 *
 * Self-chromed "Skills" sidebar card. Extracted verbatim from the former
 * `templates/partials/profile-right-sidebar.php` so ProfileSidebarProvider
 * can render it as a `chrome => false` widget descriptor. Empty `$skills`
 * self-hides the card (no output).
 *
 * @package BuddyNext
 *
 * @var array $skills Non-empty skill strings (field 25, comma-separated text).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_skills = isset( $skills ) && is_array( $skills ) ? $skills : array();

if ( $bn_pf_skills ) :
	?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Skills', 'buddynext' ); ?></div>
	<div class="bn-skill-chips">
		<?php foreach ( $bn_pf_skills as $bn_pf_skill ) : ?>
			<span class="bn-skill-chip"><?php echo esc_html( (string) $bn_pf_skill ); ?></span>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>
