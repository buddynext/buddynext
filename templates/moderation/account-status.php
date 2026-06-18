<?php
/**
 * BuddyNext account-status hub template.
 *
 * The destination for moderation notifications about the viewer's OWN account
 * (suspension, strikes, warnings, appeal decisions). Previously these
 * notifications deep-linked to the recipient's profile Posts tab, which carried
 * no information about the action — this page explains what happened, why, for
 * how long, and what restrictions apply.
 *
 * Renders the viewer's current standing only; all reads go through
 * ModerationService (no direct SQL here). Shadow-bans are intentionally NOT
 * surfaced — they are silent by design.
 *
 * Overridable: copy to {theme}/buddynext/moderation/account-status.php
 *
 * @package BuddyNext
 * @since   1.5.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$bn_as_user_id = get_current_user_id();
$bn_as_mod     = function_exists( 'buddynext_service' ) ? buddynext_service( 'moderation' ) : null;

$bn_as_suspension = $bn_as_mod ? $bn_as_mod->get_active_suspension( $bn_as_user_id ) : null;
$bn_as_strikes    = $bn_as_mod ? $bn_as_mod->get_strikes( $bn_as_user_id ) : array();
$bn_as_warnings   = $bn_as_mod ? $bn_as_mod->get_warnings( $bn_as_user_id ) : array();
$bn_as_appeals    = $bn_as_mod ? $bn_as_mod->get_user_appeals( $bn_as_user_id ) : array();

$bn_as_clean = empty( $bn_as_suspension ) && empty( $bn_as_strikes ) && empty( $bn_as_warnings );

/**
 * Format a UTC datetime string (as stored in the bn_* moderation tables) into
 * the site's local date + time. Returns '' for empty/invalid input.
 *
 * @param mixed $utc UTC 'Y-m-d H:i:s' string.
 * @return string
 */
$bn_as_date = static function ( $utc ): string {
	$utc = (string) $utc;
	if ( '' === $utc ) {
		return '';
	}
	$fmt = get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );
	return (string) wp_date( $fmt, (int) ( strtotime( $utc . ' UTC' ) ?: 0 ) );
};

/**
 * Map an appeal status slug to a badge variant + label.
 *
 * @param string $status Appeal status.
 * @return array{0:string,1:string} [ tone, label ]
 */
$bn_as_appeal_badge = static function ( string $status ): array {
	switch ( $status ) {
		case 'approved':
			return array( 'success', __( 'Approved', 'buddynext' ) );
		case 'denied':
		case 'rejected':
			return array( 'danger', __( 'Denied', 'buddynext' ) );
		default:
			return array( 'warn', __( 'Under review', 'buddynext' ) );
	}
};

// A pending appeal exists for the active suspension? Used to tailor the copy.
$bn_as_has_pending_appeal = false;
foreach ( $bn_as_appeals as $bn_as_ap ) {
	if ( 'pending' === (string) ( $bn_as_ap['status'] ?? '' ) ) {
		$bn_as_has_pending_appeal = true;
		break;
	}
}
?>
<div class="bn-mod-shell bn-acct">

	<header class="bn-mod-header bn-acct__header">
		<div class="bn-mod-header__copy">
			<h1 class="bn-mod-title"><?php esc_html_e( 'Account status', 'buddynext' ); ?></h1>
			<p class="bn-mod-sub">
				<?php esc_html_e( 'A summary of your account standing and any moderation actions. Only you can see this page.', 'buddynext' ); ?>
			</p>
		</div>
	</header>

	<?php if ( $bn_as_clean ) : ?>
		<div class="bn-acct-clean" role="status">
			<span class="bn-acct-clean__icon" aria-hidden="true"><?php buddynext_icon( 'check-circle' ); ?></span>
			<div class="bn-acct-clean__title"><?php esc_html_e( 'Your account is in good standing', 'buddynext' ); ?></div>
			<p class="bn-acct-clean__text">
				<?php esc_html_e( 'There are no active suspensions, strikes, or warnings on your account.', 'buddynext' ); ?>
			</p>
			<a href="<?php echo esc_url( PageRouter::activity_url() ); ?>" class="bn-btn bn-acct-clean__cta" data-variant="primary">
				<?php esc_html_e( 'Back to the feed', 'buddynext' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $bn_as_suspension ) ) : ?>
		<?php
		$bn_as_since   = $bn_as_date( $bn_as_suspension['created_at'] ?? '' );
		$bn_as_expires = $bn_as_date( $bn_as_suspension['expires_at'] ?? '' );
		$bn_as_reason  = trim( (string) ( $bn_as_suspension['reason'] ?? '' ) );
		?>
		<section class="bn-acct-banner" data-severity="urgent" aria-labelledby="bn-acct-susp-title">
			<span class="bn-acct-banner__icon" aria-hidden="true"><?php buddynext_icon( 'ban' ); ?></span>
			<div class="bn-acct-banner__body">
				<h2 class="bn-acct-banner__title" id="bn-acct-susp-title">
					<?php esc_html_e( 'Your account is suspended', 'buddynext' ); ?>
				</h2>
				<p class="bn-acct-banner__lead">
					<?php
					if ( '' !== $bn_as_expires ) {
						printf(
							/* translators: %s: local date and time the suspension lifts. */
							esc_html__( 'Your account is suspended until %s.', 'buddynext' ),
							'<strong>' . esc_html( $bn_as_expires ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value escaped inline.
						);
					} else {
						esc_html_e( 'Your account is suspended indefinitely.', 'buddynext' );
					}
					?>
				</p>

				<dl class="bn-acct-details">
					<?php if ( '' !== $bn_as_reason ) : ?>
						<div class="bn-acct-details__row">
							<dt class="bn-acct-details__key"><?php esc_html_e( 'Reason', 'buddynext' ); ?></dt>
							<dd class="bn-acct-details__val"><?php echo esc_html( $bn_as_reason ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $bn_as_since ) : ?>
						<div class="bn-acct-details__row">
							<dt class="bn-acct-details__key"><?php esc_html_e( 'Suspended on', 'buddynext' ); ?></dt>
							<dd class="bn-acct-details__val"><?php echo esc_html( $bn_as_since ); ?></dd>
						</div>
					<?php endif; ?>
					<div class="bn-acct-details__row">
						<dt class="bn-acct-details__key"><?php esc_html_e( 'Ends', 'buddynext' ); ?></dt>
						<dd class="bn-acct-details__val">
							<?php echo '' !== $bn_as_expires ? esc_html( $bn_as_expires ) : esc_html__( 'No end date (indefinite)', 'buddynext' ); ?>
						</dd>
					</div>
					<div class="bn-acct-details__row">
						<dt class="bn-acct-details__key"><?php esc_html_e( 'Restrictions', 'buddynext' ); ?></dt>
						<dd class="bn-acct-details__val">
							<?php
							if ( ! empty( $bn_as_suspension['hide_posts'] ) ) {
								esc_html_e( 'You cannot post, comment, or react, and your existing posts are hidden while suspended.', 'buddynext' );
							} else {
								esc_html_e( 'You cannot post, comment, or react while suspended.', 'buddynext' );
							}
							?>
						</dd>
					</div>
				</dl>

				<p class="bn-acct-banner__appeal">
					<?php if ( $bn_as_has_pending_appeal ) : ?>
						<?php esc_html_e( 'You have submitted an appeal. The moderation team will review it and notify you of the decision.', 'buddynext' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'If you believe this action was a mistake, you can appeal it below.', 'buddynext' ); ?>
					<?php endif; ?>
				</p>

				<?php
				/**
				 * Fires inside the suspension banner, after the appeal note.
				 *
				 * The appeal submission form (front-end) hooks here so the
				 * "appeal this suspension" control renders directly beneath the
				 * details a suspended member is reading.
				 *
				 * @param array<string,mixed> $suspension       Active suspension row.
				 * @param bool                $has_pending_appeal Whether a pending appeal already exists.
				 * @param int                 $user_id          Viewer ID.
				 */
				do_action( 'buddynext_account_status_appeal', $bn_as_suspension, $bn_as_has_pending_appeal, $bn_as_user_id );
				?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $bn_as_strikes ) ) : ?>
		<section class="bn-acct-section" aria-labelledby="bn-acct-strikes-title">
			<h2 class="bn-acct-section__title" id="bn-acct-strikes-title">
				<span class="bn-acct-section__icon" aria-hidden="true"><?php buddynext_icon( 'flag' ); ?></span>
				<?php
				printf(
					/* translators: %d: number of active strikes. */
					esc_html( _n( '%d active strike', '%d active strikes', count( $bn_as_strikes ), 'buddynext' ) ),
					(int) count( $bn_as_strikes )
				);
				?>
			</h2>
			<ul class="bn-acct-list">
				<?php foreach ( $bn_as_strikes as $bn_as_strike ) : ?>
					<?php
					$bn_as_s_reason = trim( (string) ( $bn_as_strike['reason'] ?? '' ) );
					$bn_as_s_date   = $bn_as_date( $bn_as_strike['created_at'] ?? '' );
					?>
					<li class="bn-acct-list__item">
						<span class="bn-acct-list__icon" aria-hidden="true"><?php buddynext_icon( 'flag' ); ?></span>
						<div class="bn-acct-list__body">
							<div class="bn-acct-list__reason">
								<?php echo '' !== $bn_as_s_reason ? esc_html( $bn_as_s_reason ) : esc_html__( 'Community guideline strike', 'buddynext' ); ?>
							</div>
							<?php if ( '' !== $bn_as_s_date ) : ?>
								<div class="bn-acct-list__meta"><?php echo esc_html( $bn_as_s_date ); ?></div>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $bn_as_warnings ) ) : ?>
		<section class="bn-acct-section" aria-labelledby="bn-acct-warn-title">
			<h2 class="bn-acct-section__title" id="bn-acct-warn-title">
				<span class="bn-acct-section__icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
				<?php
				printf(
					/* translators: %d: number of warnings. */
					esc_html( _n( '%d warning', '%d warnings', count( $bn_as_warnings ), 'buddynext' ) ),
					(int) count( $bn_as_warnings )
				);
				?>
			</h2>
			<ul class="bn-acct-list">
				<?php foreach ( $bn_as_warnings as $bn_as_warn ) : ?>
					<?php
					$bn_as_w_note = trim( (string) ( $bn_as_warn['note'] ?? '' ) );
					$bn_as_w_date = $bn_as_date( $bn_as_warn['created_at'] ?? '' );
					?>
					<li class="bn-acct-list__item">
						<span class="bn-acct-list__icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
						<div class="bn-acct-list__body">
							<div class="bn-acct-list__reason">
								<?php echo '' !== $bn_as_w_note ? esc_html( $bn_as_w_note ) : esc_html__( 'Community guideline warning', 'buddynext' ); ?>
							</div>
							<?php if ( '' !== $bn_as_w_date ) : ?>
								<div class="bn-acct-list__meta"><?php echo esc_html( $bn_as_w_date ); ?></div>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $bn_as_appeals ) ) : ?>
		<section class="bn-acct-section" aria-labelledby="bn-acct-appeals-title">
			<h2 class="bn-acct-section__title" id="bn-acct-appeals-title">
				<span class="bn-acct-section__icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
				<?php esc_html_e( 'Your appeals', 'buddynext' ); ?>
			</h2>
			<ul class="bn-acct-list">
				<?php foreach ( $bn_as_appeals as $bn_as_ap ) : ?>
					<?php
					$bn_as_ap_msg                           = trim( (string) ( $bn_as_ap['message'] ?? '' ) );
					$bn_as_ap_date                          = $bn_as_date( $bn_as_ap['created_at'] ?? '' );
					list( $bn_as_ap_tone, $bn_as_ap_label ) = $bn_as_appeal_badge( (string) ( $bn_as_ap['status'] ?? '' ) );
					?>
					<li class="bn-acct-list__item">
						<div class="bn-acct-list__body">
							<div class="bn-acct-list__head">
								<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_as_ap_tone ); ?>"><?php echo esc_html( $bn_as_ap_label ); ?></span>
								<?php if ( '' !== $bn_as_ap_date ) : ?>
									<span class="bn-acct-list__meta"><?php echo esc_html( $bn_as_ap_date ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( '' !== $bn_as_ap_msg ) : ?>
								<div class="bn-acct-list__reason"><?php echo esc_html( $bn_as_ap_msg ); ?></div>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

</div>
