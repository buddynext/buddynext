<?php
/**
 * Block template: Space Card
 *
 * Variables:
 *   int $space_id Space ID to display
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$space_id = $space_id ?? 0;

if ( ! $space_id ) {
	return;
}

$space = buddynext_service( 'spaces' )->get( $space_id );

if ( ! $space ) {
	return;
}

$viewer_id = get_current_user_id();
$is_member = $viewer_id
	? buddynext_service( 'space_members' )->is_member( $space_id, $viewer_id )
	: false;

$bn_type         = $space['type'] ?? 'open';
$bn_privacy_tone = match ( $bn_type ) {
	'open'    => 'info',
	'private' => 'warn',
	default   => 'danger',
};
$bn_privacy_label = match ( $bn_type ) {
	'open'    => __( 'Public', 'buddynext' ),
	'private' => __( 'Private', 'buddynext' ),
	default   => __( 'Invite-only', 'buddynext' ),
};
?>
<article class="bn-card bn-block-space-card" data-interactive data-space-id="<?php echo absint( $space_id ); ?>">
	<?php if ( ! empty( $space['cover_image_url'] ) ) : ?>
		<div class="bn-space-card__cover">
			<img src="<?php echo esc_url( $space['cover_image_url'] ); ?>" alt="" class="bn-space-cover" loading="lazy">
		</div>
	<?php endif; ?>
	<div class="bn-space-card__body">
		<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
			<div class="bn-avatar bn-space-card__avatar" data-size="lg" aria-hidden="true">
				<img src="<?php echo esc_url( $space['avatar_url'] ); ?>" alt="" width="48" height="48" loading="lazy">
			</div>
		<?php endif; ?>
		<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::space_url( $space_id ) ); ?>" class="bn-space-card__name-link">
			<h3 class="bn-space-card__name"><?php echo esc_html( $space['name'] ?? '' ); ?></h3>
		</a>
		<?php if ( ! empty( $space['description'] ) ) : ?>
			<p class="bn-space-card__description"><?php echo esc_html( wp_trim_words( $space['description'], 15 ) ); ?></p>
		<?php endif; ?>
		<div class="bn-space-card__meta">
			<span class="bn-space-card__members">
				<?php
				$bn_count = absint( $space['member_count'] ?? 0 );
				printf(
					/* translators: %d: member count */
					esc_html( _n( '%d member', '%d members', $bn_count, 'buddynext' ) ),
					absint( $bn_count )
				);
				?>
			</span>
			<span class="bn-badge bn-space-card__type" data-tone="<?php echo esc_attr( $bn_privacy_tone ); ?>"><?php echo esc_html( $bn_privacy_label ); ?></span>
		</div>
		<?php if ( $viewer_id && ! $is_member ) : ?>
			<?php $bn_is_private = 'private' === $bn_type; ?>
			<button
				class="bn-btn"
				data-variant="primary"
				data-size="sm"
				data-current-state="<?php echo $bn_is_private ? 'request' : 'join'; ?>"
				data-action="bn-join-space"
				data-space-id="<?php echo absint( $space_id ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'buddynext_join_space_' . $space_id ) ); ?>"
			>
				<?php echo $bn_is_private ? esc_html__( 'Request to join', 'buddynext' ) : esc_html__( 'Join', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	</div>
</article>
