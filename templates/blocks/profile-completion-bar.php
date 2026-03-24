<?php
/**
 * Block template: Profile Completion Bar
 *
 * Variables:
 *   int $user_id WordPress user ID (0 = current user)
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
$percent             = (int) ( $completion['percent'] ?? 0 );
$missing_required    = ( $completion['required_total'] ?? 0 ) - ( $completion['required_filled'] ?? 0 );
$missing_recommended = ( $completion['recommended_total'] ?? 0 ) - ( $completion['recommended_filled'] ?? 0 );
?>
<div class="bn-block-profile-completion" data-user-id="<?php echo absint( $user_id ); ?>">
	<div class="bn-completion-header">
		<span class="bn-completion-label"><?php esc_html_e( 'Profile completion', 'buddynext' ); ?></span>
		<span class="bn-completion-percent"><?php echo absint( $percent ); ?>%</span>
	</div>
	<div class="bn-completion-bar" role="progressbar" aria-valuenow="<?php echo absint( $percent ); ?>" aria-valuemin="0" aria-valuemax="100">
		<div class="bn-completion-bar__fill" style="width:<?php echo absint( $percent ); ?>%"></div>
	</div>
	<?php if ( $percent < 100 && ( $missing_required > 0 || $missing_recommended > 0 ) ) : ?>
		<ul class="bn-completion-tips">
			<?php if ( $missing_required > 0 ) : ?>
				<li class="bn-completion-tip">
					<?php
					printf(
						/* translators: %d: number of required fields not yet filled */
						esc_html( _n( '%d required field still needs to be filled in.', '%d required fields still need to be filled in.', $missing_required, 'buddynext' ) ),
						absint( $missing_required )
					);
					?>
					<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"><?php esc_html_e( 'Add', 'buddynext' ); ?></a>
				</li>
			<?php endif; ?>
			<?php if ( $missing_recommended > 0 ) : ?>
				<li class="bn-completion-tip">
					<?php
					printf(
						/* translators: %d: number of recommended fields not yet filled */
						esc_html( _n( '%d recommended field will help others find you.', '%d recommended fields will help others find you.', $missing_recommended, 'buddynext' ) ),
						absint( $missing_recommended )
					);
					?>
					<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"><?php esc_html_e( 'Add', 'buddynext' ); ?></a>
				</li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>
</div>
