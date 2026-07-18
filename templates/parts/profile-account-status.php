<?php
/**
 * Profile — account standing banner (strikes / suspension / shadow-ban).
 *
 * Privileged surface: rendered only when the shared
 * ModerationService::account_status_for() returned a payload (the profile owner
 * with an active sanction, or a moderator). Shadow-ban is present only for
 * moderators. A clean account renders nothing. Token-driven, dark-mode + RTL safe.
 *
 * @var array $status  Account status payload from ModerationService.
 * @var bool  $is_self Whether the viewer is the profile owner.
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$status  = ( isset( $status ) && is_array( $status ) ) ? $status : array();
$is_self = ! empty( $is_self );

$strikes      = (int) ( $status['strikes'] ?? 0 );
$is_suspended = ! empty( $status['is_suspended'] );
$suspension   = is_array( $status['suspension'] ?? null ) ? $status['suspension'] : array();
$is_moderator = ( ( $status['scope'] ?? '' ) === 'moderator' );
$is_shadow    = ! empty( $status['is_shadow_banned'] );

// Nothing to show for a spotless account (a moderator's clean-state payload
// still carries no banner — the standing is only surfaced when it is not clean).
if ( 0 === $strikes && ! $is_suspended && ! $is_shadow ) {
	return;
}

$heading = $is_self
	? __( 'Your account standing', 'buddynext' )
	: __( 'Account standing', 'buddynext' );
?>
<section class="bn-pf-standing bn-card" aria-label="<?php echo esc_attr( $heading ); ?>">
	<span class="bn-pf-standing__icon" aria-hidden="true">
		<?php buddynext_icon( $is_suspended ? 'ban' : 'shield' ); ?>
	</span>
	<div class="bn-pf-standing__body">
		<h2 class="bn-pf-standing__title"><?php echo esc_html( $heading ); ?></h2>
		<ul class="bn-pf-standing__list">
			<?php if ( $is_suspended ) : ?>
				<li class="bn-pf-standing__item">
					<?php
					$reason  = (string) ( $suspension['reason'] ?? '' );
					$expires = ! empty( $suspension['expires_at'] )
						? date_i18n( (string) get_option( 'date_format' ), (int) strtotime( (string) $suspension['expires_at'] ) )
						: '';
					if ( '' !== $expires ) {
						printf(
							/* translators: %s: suspension expiry date. */
							esc_html__( 'Suspended until %s.', 'buddynext' ),
							esc_html( $expires )
						);
					} else {
						esc_html_e( 'Suspended (no end date).', 'buddynext' );
					}
					if ( '' !== $reason ) {
						echo ' ' . esc_html(
							sprintf(
								/* translators: %s: moderator-supplied reason. */
								__( 'Reason: %s', 'buddynext' ),
								$reason
							)
						);
					}
					?>
				</li>
			<?php endif; ?>

			<?php if ( $strikes > 0 ) : ?>
				<li class="bn-pf-standing__item">
					<?php
					printf(
						esc_html(
							/* translators: %d: number of active strikes. */
							_n( '%d active strike.', '%d active strikes.', $strikes, 'buddynext' )
						),
						(int) $strikes
					);
					?>
				</li>
			<?php endif; ?>

			<?php if ( $is_moderator && $is_shadow ) : ?>
				<li class="bn-pf-standing__item">
					<?php esc_html_e( 'This account is shadow-banned.', 'buddynext' ); ?>
				</li>
			<?php endif; ?>
		</ul>
		<?php if ( $is_self ) : ?>
			<p class="bn-pf-standing__note"><?php esc_html_e( 'Only you and the community moderators can see this.', 'buddynext' ); ?></p>
		<?php endif; ?>
	</div>
</section>
