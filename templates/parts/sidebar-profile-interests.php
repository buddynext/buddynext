<?php
/**
 * BuddyNext template part: sidebar-profile-interests.
 *
 * Self-chromed "Interests" sidebar card. Extracted verbatim from the former
 * `templates/partials/profile-right-sidebar.php` so ProfileSidebarProvider
 * can render it as a `chrome => false` widget descriptor. Empty
 * `$interest_chips` self-hides the card (no output).
 *
 * @package BuddyNext
 *
 * @var array $interest_chips Non-empty interest picks: array{name:string, url:string}[].
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pf_int_chips = isset( $interest_chips ) && is_array( $interest_chips ) ? $interest_chips : array();

if ( $bn_pf_int_chips ) :
	?>
<div class="bn-widget">
	<div class="bn-widget-title"><?php esc_html_e( 'Interests', 'buddynext' ); ?></div>
	<div class="bn-skill-chips">
		<?php foreach ( $bn_pf_int_chips as $bn_pf_chip ) : ?>
			<?php if ( '' !== (string) ( $bn_pf_chip['url'] ?? '' ) ) : ?>
				<a class="bn-skill-chip" href="<?php echo esc_url( (string) $bn_pf_chip['url'] ); ?>"><?php echo esc_html( (string) ( $bn_pf_chip['name'] ?? '' ) ); ?></a>
			<?php else : ?>
				<span class="bn-skill-chip"><?php echo esc_html( (string) ( $bn_pf_chip['name'] ?? '' ) ); ?></span>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>
