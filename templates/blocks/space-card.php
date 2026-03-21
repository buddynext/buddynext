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
?>
<div class="bn-block-space-card" data-space-id="<?php echo absint( $space_id ); ?>">
	<?php if ( ! empty( $space['cover_image_url'] ) ) : ?>
		<div class="bn-space-card__cover">
			<img src="<?php echo esc_url( $space['cover_image_url'] ); ?>" alt="" class="bn-space-cover" loading="lazy">
		</div>
	<?php endif; ?>
	<div class="bn-space-card__body">
		<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
			<img src="<?php echo esc_url( $space['avatar_url'] ); ?>" alt="" class="bn-space-avatar" width="48" height="48" loading="lazy">
		<?php endif; ?>
		<a href="<?php echo esc_url( home_url( '/spaces/' . rawurlencode( $space['slug'] ?? '' ) . '/' ) ); ?>" class="bn-space-card__name">
			<?php echo esc_html( $space['name'] ?? '' ); ?>
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
			<span class="bn-space-card__type"><?php echo esc_html( $space['type'] ?? 'open' ); ?></span>
		</div>
		<?php if ( $viewer_id && ! $is_member ) : ?>
			<button class="bn-btn bn-btn--sm bn-btn--primary"
				data-action="bn-join-space"
				data-space-id="<?php echo absint( $space_id ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'buddynext_join_space_' . $space_id ) ); ?>">
				<?php echo 'private' === ( $space['type'] ?? '' ) ? esc_html__( 'Request to join', 'buddynext' ) : esc_html__( 'Join', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	</div>
</div>
