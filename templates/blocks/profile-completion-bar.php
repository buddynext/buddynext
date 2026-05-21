<?php
/**
 * Block template: Profile Completion Bar (v2 design system).
 *
 * Sidebar nudge — current completion score plus inline tips. Wrapped in a
 * .bn-card and the inline percent uses a .bn-badge accent tone.
 *
 * Variables:
 *   int $user_id WordPress user ID (0 = current user).
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$user_id = $user_id ?? 0;

if ( ! $user_id ) {
	$user_id = get_current_user_id();
}

if ( ! $user_id ) {
	return;
}

$completion          = buddynext_service( 'profiles' )->get_completion_score( $user_id );
$percent             = max( 0, min( 100, (int) ( $completion['percent'] ?? 0 ) ) );
$missing_required    = ( $completion['required_total'] ?? 0 ) - ( $completion['required_filled'] ?? 0 );
$missing_recommended = ( $completion['recommended_total'] ?? 0 ) - ( $completion['recommended_filled'] ?? 0 );
$edit_url            = \BuddyNext\Core\PageRouter::edit_profile_url();
$bar_tone            = $percent >= 100 ? 'success' : 'accent';

// translators: %d: profile completion percentage (0-100).
$progress_label = sprintf( __( 'Profile %d%% complete', 'buddynext' ), $percent );
?>
<section class="bn-card bn-block-profile-completion" data-user-id="<?php echo absint( $user_id ); ?>">
	<div class="bn-completion-header">
		<span class="bn-completion-label"><?php esc_html_e( 'Profile completion', 'buddynext' ); ?></span>
		<span
			class="bn-badge bn-completion-percent"
			data-tone="<?php echo esc_attr( $bar_tone ); ?>"
		>
			<?php echo esc_html( $percent ); ?>%
		</span>
	</div>
	<div
		class="bn-completion-bar"
		role="progressbar"
		aria-label="<?php echo esc_attr( $progress_label ); ?>"
		aria-valuenow="<?php echo absint( $percent ); ?>"
		aria-valuemin="0"
		aria-valuemax="100"
	>
		<div
			class="bn-completion-bar__fill"
			data-tone="<?php echo esc_attr( $bar_tone ); ?>"
			style="width:<?php echo absint( $percent ); ?>%"
		></div>
	</div>
	<?php if ( $percent < 100 && ( $missing_required > 0 || $missing_recommended > 0 ) ) : ?>
		<ul class="bn-completion-tips">
			<?php if ( $missing_required > 0 ) : ?>
				<li class="bn-completion-tip">
					<span class="bn-completion-tip__text">
						<?php
						printf(
							/* translators: %d: number of required fields not yet filled */
							esc_html( _n( '%d required field still needs to be filled in.', '%d required fields still need to be filled in.', $missing_required, 'buddynext' ) ),
							absint( $missing_required )
						);
						?>
					</span>
					<a
						href="<?php echo esc_url( $edit_url ); ?>"
						class="bn-btn bn-completion-tip__cta"
						data-variant="ghost"
						data-size="sm"
					>
						<?php esc_html_e( 'Add', 'buddynext' ); ?>
					</a>
				</li>
			<?php endif; ?>
			<?php if ( $missing_recommended > 0 ) : ?>
				<li class="bn-completion-tip">
					<span class="bn-completion-tip__text">
						<?php
						printf(
							/* translators: %d: number of recommended fields not yet filled */
							esc_html( _n( '%d recommended field will help others find you.', '%d recommended fields will help others find you.', $missing_recommended, 'buddynext' ) ),
							absint( $missing_recommended )
						);
						?>
					</span>
					<a
						href="<?php echo esc_url( $edit_url ); ?>"
						class="bn-btn bn-completion-tip__cta"
						data-variant="ghost"
						data-size="sm"
					>
						<?php esc_html_e( 'Add', 'buddynext' ); ?>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>
</section>
