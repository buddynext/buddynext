<?php
/**
 * Block template: Space Card.
 *
 * Standalone v2-aligned space card. Mirrors the inline card rendered by
 * `templates/spaces/directory.php` and the markup pattern in
 * `docs/v2 Plans/v2/spaces-directory.html`. Used by sidebars, mini lists,
 * and any surface that wants a single space-card by id.
 *
 * Variables:
 *   int $space_id Space ID to display.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

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
$bn_privacy_label = \BuddyNext\Spaces\SpaceService::type_label( (string) $bn_type );

if ( ! function_exists( 'bn_space_cover_tone' ) ) {
	/**
	 * Return a cover-tone slug from a deterministic palette.
	 *
	 * @param int $space_id Space ID used to pick a tone.
	 * @return string Tone slug consumed by `.bn-sd-card__cover[data-tone]`.
	 */
	function bn_space_cover_tone( int $space_id ): string {
		$tones = array( 'sky', 'cyan', 'emerald', 'lime', 'amber', 'coral' );
		return $tones[ $space_id % count( $tones ) ];
	}
}

$bn_cover_tone = bn_space_cover_tone( (int) $space_id );
$bn_space_url  = \BuddyNext\Core\PageRouter::space_url( (int) $space_id );
$bn_count      = absint( $space['member_count'] ?? 0 );
?>
<article class="bn-card bn-sd-card"
	data-interactive
	data-space-id="<?php echo absint( $space_id ); ?>"
	data-wp-interactive="buddynext/spaces"
	data-wp-context='<?php echo esc_attr( (string) wp_json_encode( array( 'restNonce' => wp_create_nonce( 'wp_rest' ), 'restUrl' => rest_url( 'buddynext/v1' ) ) ) ); ?>'>
	<a href="<?php echo esc_url( $bn_space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-sd-card__cover-link">
		<div class="bn-sd-card__cover" data-tone="<?php echo esc_attr( $bn_cover_tone ); ?>">
			<?php if ( ! empty( $space['cover_image_url'] ) ) : ?>
				<img src="<?php echo esc_url( $space['cover_image_url'] ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<?php if ( ! empty( $space['avatar_url'] ) ) : ?>
				<div class="bn-sd-card__emblem" aria-hidden="true">
					<img src="<?php echo esc_url( $space['avatar_url'] ); ?>" alt="" width="48" height="48" loading="lazy">
				</div>
			<?php endif; ?>
		</div>
	</a>

	<div class="bn-sd-card__body">
		<a href="<?php echo esc_url( $bn_space_url ); ?>" class="bn-sd-card__name-link">
			<h3 class="bn-sd-card__name">
				<?php echo esc_html( $space['name'] ?? '' ); ?>
				<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_privacy_tone ); ?>"><?php echo esc_html( $bn_privacy_label ); ?></span>
			</h3>
		</a>

		<?php if ( ! empty( $space['description'] ) ) : ?>
			<p class="bn-sd-card__desc"><?php echo esc_html( wp_trim_words( $space['description'], 15 ) ); ?></p>
		<?php endif; ?>

		<div class="bn-sd-card__stats">
			<span class="bn-sd-card__stat">
				<?php
				printf(
					/* translators: %d: member count */
					esc_html( _n( '%d member', '%d members', $bn_count, 'buddynext' ) ),
					absint( $bn_count )
				);
				?>
			</span>
		</div>

		<?php if ( $viewer_id && ! $is_member ) : ?>
			<?php $bn_is_request = 'request' === \BuddyNext\Spaces\SpaceTypeRegistry::instance()->join_method( (string) $bn_type ); ?>
			<div class="bn-sd-card__foot">
				<button
					type="button"
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
					data-current-state="<?php echo $bn_is_request ? 'request' : 'join'; ?>"
					data-space-id="<?php echo absint( $space_id ); ?>"
					data-wp-on--click="actions.joinSpace"
				>
					<?php echo $bn_is_request ? esc_html__( 'Request to join', 'buddynext' ) : esc_html__( 'Join', 'buddynext' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
</article>
