<?php
/**
 * BuddyNext template part: the forums' email choices inside the member's
 * notification settings.
 *
 * Jetonomy sends forum email and owns where the choices are stored; this card is
 * the one place a member sets them. Each switch saves straight to Jetonomy's own
 * REST endpoint (PATCH jetonomy/v1/users/me), the same storage its own profile
 * screen writes, so the two never disagree. Only the email channel is offered:
 * the bell follows the Forums rows in Activity types above. Below the switches,
 * any field Jetonomy adds itself (its digest) saves through its own endpoint.
 * Rendered by JetonomyBridge::render_partner_prefs.
 *
 * Overridable: copy to {theme}/buddynext/parts/settings-partner-prefs.php.
 *
 * @package BuddyNext
 *
 * @var int    $bn_pp_user_id Member whose settings are open.
 * @var string $bn_pp_fields  Partner-rendered field markup ('' for none).
 * @var string $bn_pp_rest    Partner REST endpoint that saves $bn_pp_fields.
 * @var string $bn_pp_field   Name of the input whose change is saved.
 * @var string $bn_pp_key     Body key the endpoint expects the value under.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pp_user_id = (int) ( $bn_pp_user_id ?? get_current_user_id() );
$bn_pp_fields  = (string) ( $bn_pp_fields ?? '' );
$bn_pp_context = wp_json_encode( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );

// One switch per forum type this member can receive, as the Forums rows above list
// them (the catalogue already hides moderator-only types from members). The state
// is what Jetonomy will do: the member's own choice, else the site's default.
$bn_pp_types = array();
if ( class_exists( '\Jetonomy\Notifications\Notifier' ) && class_exists( '\Jetonomy\Models\UserProfile' ) ) {
	$bn_pp_profile  = \Jetonomy\Models\UserProfile::find_by_user( $bn_pp_user_id );
	$bn_pp_settings = $bn_pp_profile ? json_decode( (string) ( $bn_pp_profile->settings ?? '{}' ), true ) : array();
	$bn_pp_mine     = is_array( $bn_pp_settings['notifications'] ?? null ) ? $bn_pp_settings['notifications'] : array();
	$bn_pp_site     = (array) ( get_option( 'jetonomy_settings', array() )['notification_defaults'] ?? array() );
	$bn_pp_groups   = buddynext_service( 'notification_pref_catalogue' )->grouped();
	foreach ( (array) ( $bn_pp_groups['jetonomy'] ?? array() ) as $bn_pp_entry ) {
		$bn_pp_slug = substr( (string) ( $bn_pp_entry['slug'] ?? '' ), strlen( 'jetonomy.' ) );
		if ( '' === $bn_pp_slug ) {
			continue;
		}
		$bn_pp_types[ $bn_pp_slug ] = array(
			'label' => (string) ( $bn_pp_entry['label'] ?? $bn_pp_slug ),
			'hint'  => (string) ( $bn_pp_entry['description'] ?? '' ),
			'on'    => isset( $bn_pp_mine[ $bn_pp_slug ]['email'] )
				? ! empty( $bn_pp_mine[ $bn_pp_slug ]['email'] )
				: \Jetonomy\Notifications\Notifier::default_email( $bn_pp_slug, $bn_pp_site ),
		);
	}
}
$bn_pp_paused = (bool) get_user_meta( $bn_pp_user_id, 'jetonomy_email_opt_out', true );
?>
<section id="forum-email" class="bn-card bn-prefs-card bn-partner-prefs" data-v2
	aria-labelledby="bn-partner-prefs-title"
	data-wp-interactive="buddynext/notification-prefs"
	data-wp-context='<?php echo esc_attr( (string) $bn_pp_context ); ?>'
	data-wp-on--change="actions.setPartnerPref">
	<header class="bn-prefs-card__head">
		<h2 class="bn-prefs-card__title" id="bn-partner-prefs-title">
			<?php esc_html_e( 'Forum email', 'buddynext' ); ?>
		</h2>
		<p class="bn-prefs-card__sub">
			<?php esc_html_e( 'These emails come from the forums. Your choice saves as soon as you pick it.', 'buddynext' ); ?>
		</p>
	</header>

	<div class="bn-prefs-group" data-bn-partner-pref data-rest-url="<?php echo esc_url( rest_url( 'jetonomy/v1/users/me' ) ); ?>">
		<div class="bn-prefs-group__body" role="list">
			<div class="bn-prefs-row" role="listitem">
				<div class="bn-prefs-row__copy">
					<label class="bn-prefs-row__label" for="bn-forum-email-pause"><?php esc_html_e( 'Pause forum email', 'buddynext' ); ?></label>
					<p class="bn-prefs-row__desc" id="bn-forum-email-pause-hint"><?php esc_html_e( 'Stops every email from the forums, including the reminder to confirm your account email. Your choices below are kept for when you turn this off.', 'buddynext' ); ?></p>
				</div>
				<div class="bn-prefs-row__controls">
					<label class="bn-prefs-toggle">
						<input type="checkbox" id="bn-forum-email-pause"
							data-body-key="email_opt_out"
							aria-describedby="bn-forum-email-pause-hint"
							<?php checked( $bn_pp_paused ); ?>>
						<span class="bn-prefs-toggle__label"><?php esc_html_e( 'Paused', 'buddynext' ); ?></span>
					</label>
				</div>
			</div>
			<?php foreach ( $bn_pp_types as $bn_pp_slug => $bn_pp_type ) : ?>
				<div class="bn-prefs-row" role="listitem">
					<div class="bn-prefs-row__copy">
						<label class="bn-prefs-row__label" for="bn-forum-email-<?php echo esc_attr( $bn_pp_slug ); ?>"><?php echo esc_html( $bn_pp_type['label'] ); ?></label>
						<p class="bn-prefs-row__desc" id="bn-forum-email-<?php echo esc_attr( $bn_pp_slug ); ?>-hint"><?php echo esc_html( $bn_pp_type['hint'] ); ?></p>
					</div>
					<div class="bn-prefs-row__controls">
						<label class="bn-prefs-toggle">
							<input type="checkbox" id="bn-forum-email-<?php echo esc_attr( $bn_pp_slug ); ?>"
								data-body-key="<?php echo esc_attr( 'notification_preferences.' . $bn_pp_slug . '.email' ); ?>"
								aria-describedby="bn-forum-email-<?php echo esc_attr( $bn_pp_slug ); ?>-hint"
								<?php checked( $bn_pp_type['on'] ); ?>>
							<span class="bn-prefs-toggle__label"><?php esc_html_e( 'Email', 'buddynext' ); ?></span>
						</label>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if ( '' !== $bn_pp_fields ) : ?>
		<div class="bn-partner-prefs__fields" data-bn-partner-pref
			data-rest-url="<?php echo esc_url( (string) $bn_pp_rest ); ?>"
			data-field="<?php echo esc_attr( (string) $bn_pp_field ); ?>"
			data-body-key="<?php echo esc_attr( (string) $bn_pp_key ); ?>">
			<?php
			// Partner-owned markup, escaped where the partner builds it.
			echo $bn_pp_fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
	<?php endif; ?>
</section>
