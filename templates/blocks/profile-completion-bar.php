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

$completion = buddynext_service( 'profiles' )->get_completion_score( $user_id );
$percent    = (int) ( $completion['percent'] ?? 0 );
$missing    = $completion['missing'] ?? array();
?>
<div class="bn-block-profile-completion" data-user-id="<?php echo absint( $user_id ); ?>">
	<div class="bn-completion-header">
		<span class="bn-completion-label"><?php esc_html_e( 'Profile completion', 'buddynext' ); ?></span>
		<span class="bn-completion-percent"><?php echo absint( $percent ); ?>%</span>
	</div>
	<div class="bn-completion-bar" role="progressbar" aria-valuenow="<?php echo absint( $percent ); ?>" aria-valuemin="0" aria-valuemax="100">
		<div class="bn-completion-bar__fill" style="width:<?php echo absint( $percent ); ?>%"></div>
	</div>
	<?php if ( $percent < 100 && ! empty( $missing ) ) : ?>
		<ul class="bn-completion-tips">
			<?php foreach ( array_slice( $missing, 0, 3 ) as $tip ) : ?>
				<li class="bn-completion-tip">
					<?php echo esc_html( $tip['label'] ?? '' ); ?>
					<?php if ( ! empty( $tip['url'] ) ) : ?>
						<a href="<?php echo esc_url( $tip['url'] ); ?>"><?php esc_html_e( 'Add', 'buddynext' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
