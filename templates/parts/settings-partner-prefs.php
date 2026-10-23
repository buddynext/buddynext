<?php
/**
 * BuddyNext template part: a partner plugin's own email choices inside the
 * member's notification settings.
 *
 * The partner renders the fields and owns the storage; this card is only the
 * frame and the save call. Rendered by JetonomyBridge::render_partner_prefs.
 *
 * @package BuddyNext
 *
 * @var string $bn_pp_fields Partner-rendered field markup.
 * @var string $bn_pp_rest   Partner REST endpoint that saves a change.
 * @var string $bn_pp_field  Name of the input whose change is saved.
 * @var string $bn_pp_key    Body key the endpoint expects the value under.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pp_context = wp_json_encode( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
?>
<section class="bn-card bn-prefs-card bn-partner-prefs" data-v2
	aria-labelledby="bn-partner-prefs-title"
	data-wp-interactive="buddynext/notification-prefs"
	data-wp-context='<?php echo esc_attr( (string) $bn_pp_context ); ?>'
	data-bn-partner-pref
	data-rest-url="<?php echo esc_url( $bn_pp_rest ); ?>"
	data-field="<?php echo esc_attr( $bn_pp_field ); ?>"
	data-body-key="<?php echo esc_attr( $bn_pp_key ); ?>"
	data-wp-on--change="actions.setPartnerPref">
	<header class="bn-prefs-card__head">
		<h2 class="bn-prefs-card__title" id="bn-partner-prefs-title">
			<?php esc_html_e( 'Forum email', 'buddynext' ); ?>
		</h2>
		<p class="bn-prefs-card__sub">
			<?php esc_html_e( 'These emails come from the forums. Your choice saves as soon as you pick it.', 'buddynext' ); ?>
		</p>
	</header>
	<?php
	// Partner-owned markup, escaped where the partner builds it.
	echo $bn_pp_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</section>
