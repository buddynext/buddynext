<?php
/**
 * BuddyNext template part: space-directory-card.
 *
 * One space card for the directory grid (and the sectioned "My Spaces" groups).
 * Extracted from templates/spaces/directory.php so the flat grid and the
 * managed/joined sections render the IDENTICAL card from one source.
 *
 * Relies on helpers defined by the including directory template
 * (bn_space_cover_tone, bn_space_category_icon) and the global space-URL helpers.
 *
 * @package BuddyNext
 * @since   1.0.4
 *
 * @var array $space           Required. Hydrated space row.
 * @var array $membership       Optional. Viewer's membership for THIS space
 *                              (`[ 'role' => string, 'status' => string ]`) or null.
 * @var int   $current_user_id  Optional. Current viewer ID (0 = logged out).
 * @var array $cat_by_id        Optional. Category map keyed by id (`[ id => [name,slug] ]`).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\IconService;
use BuddyNext\Core\PageRouter;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceTypeRegistry;

$bn_dc_space = isset( $space ) && is_array( $space ) ? $space : null;
if ( null === $bn_dc_space ) {
	return;
}

$bn_dc_membership = isset( $membership ) && is_array( $membership ) ? $membership : null;
$bn_dc_uid        = isset( $current_user_id ) ? (int) $current_user_id : 0;
$bn_dc_cat_by_id  = isset( $cat_by_id ) && is_array( $cat_by_id ) ? $cat_by_id : array();

$space_id     = (int) $bn_dc_space['id'];
$is_admin_mod = $bn_dc_membership && in_array( $bn_dc_membership['role'], array( 'admin', 'moderator', 'owner' ), true ) && 'active' === $bn_dc_membership['status'];
$is_member    = $bn_dc_membership && 'active' === $bn_dc_membership['status'];
$is_pending   = $bn_dc_membership && 'pending' === $bn_dc_membership['status'];

$space_type    = (string) ( $bn_dc_space['type'] ?? 'open' );
$space_name    = (string) ( $bn_dc_space['name'] ?? '' );
$space_slug    = (string) ( $bn_dc_space['slug'] ?? '' );
$privacy_label = SpaceService::type_label( $space_type );
$privacy_tone  = SpaceTypeRegistry::instance()->tone( $space_type );

$bn_card_cat_id   = isset( $bn_dc_space['category_id'] ) ? (int) $bn_dc_space['category_id'] : 0;
$bn_card_cat      = $bn_card_cat_id && isset( $bn_dc_cat_by_id[ $bn_card_cat_id ] ) ? $bn_dc_cat_by_id[ $bn_card_cat_id ] : null;
$bn_card_cat_name = $bn_card_cat ? (string) $bn_card_cat['name'] : '';
$bn_card_cat_slug = $bn_card_cat ? (string) $bn_card_cat['slug'] : '';

$cover_tone   = bn_space_cover_tone( $space_id );
$cat_icon     = bn_space_category_icon( $bn_card_cat_slug );
$space_url    = buddynext_space_url( $space_slug );
$member_count = number_format_i18n( (int) ( $bn_dc_space['member_count'] ?? 0 ) );

// Emblem fallback chain: avatar → category icon → first-letter glyph.
$bn_card_emblem = '';
if ( ! empty( $bn_dc_space['avatar_url'] ) ) {
	$bn_card_emblem = sprintf( '<img src="%s" alt="" loading="lazy">', esc_url( (string) $bn_dc_space['avatar_url'] ) );
} elseif ( '' !== $bn_card_cat_slug ) {
	$bn_card_emblem = wp_kses( $cat_icon, IconService::allowed_tags() );
} else {
	$bn_card_emblem = sprintf( '<span class="bn-sd-card__emblem-letter">%s</span>', esc_html( mb_strtoupper( mb_substr( $space_name, 0, 1 ) ) ) );
}
?>
<article class="bn-card bn-sd-card" data-interactive role="listitem" aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space_name, $privacy_label ) ); ?>">
	<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-sd-card__cover-link">
		<div class="bn-sd-card__cover" data-tone="<?php echo esc_attr( $cover_tone ); ?>">
			<?php if ( ! empty( $bn_dc_space['cover_image_url'] ) ) : ?>
				<img src="<?php echo esc_url( (string) $bn_dc_space['cover_image_url'] ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<div class="bn-sd-card__emblem" aria-hidden="true"><?php echo $bn_card_emblem; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- branches above each escape their content. ?></div>
		</div>
	</a>

	<div class="bn-sd-card__body">
		<a href="<?php echo esc_url( $space_url ); ?>" class="bn-sd-card__name-link">
			<h2 class="bn-sd-card__name"
				aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space_name, $privacy_label ) ); ?>"
			><?php echo esc_html( $space_name ); ?><span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span></h2>
		</a>

		<?php if ( '' !== $bn_card_cat_name ) : ?>
			<div class="bn-sd-card__category">
				<?php buddynext_icon( 'hash' ); ?>
				<?php echo esc_html( $bn_card_cat_name ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $bn_dc_space['description'] ) ) : ?>
			<p class="bn-sd-card__desc"><?php echo esc_html( wp_trim_words( (string) $bn_dc_space['description'], 18 ) ); ?></p>
		<?php endif; ?>

		<div class="bn-sd-card__stats">
			<span class="bn-sd-card__stat">
				<?php
				// translators: %s: member count.
				printf( esc_html( _n( '%s member', '%s members', (int) ( $bn_dc_space['member_count'] ?? 0 ), 'buddynext' ) ), esc_html( $member_count ) );
				?>
			</span>
		</div>

		<div class="bn-sd-card__foot">
			<?php if ( 0 === $bn_dc_uid ) : ?>
				<a
					href="<?php echo esc_url( PageRouter::auth_url() . '?redirect_to=' . rawurlencode( buddynext_space_url( $space_slug ) ) ); ?>"
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
				><?php esc_html_e( 'Log in to join', 'buddynext' ); ?></a>

			<?php elseif ( $is_admin_mod ) : ?>
				<a
					href="<?php echo esc_url( buddynext_space_settings_url( $space_slug ) ); ?>"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
				><?php esc_html_e( 'Manage', 'buddynext' ); ?></a>

			<?php elseif ( $is_member ) : ?>
				<button
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
					data-current-state="joined"
					data-wp-on--click="actions.leaveSpace"
					data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
					aria-label="<?php esc_attr_e( 'Joined — click to leave', 'buddynext' ); ?>"
				><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Joined', 'buddynext' ); ?></button>

			<?php elseif ( $is_pending ) : ?>
				<button
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
					data-current-state="pending"
					data-wp-on--click="actions.cancelJoinRequest"
					data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
					aria-label="<?php esc_attr_e( 'Request pending — click to cancel', 'buddynext' ); ?>"
				><?php esc_html_e( 'Requested', 'buddynext' ); ?></button>

			<?php elseif ( 'direct' === SpaceTypeRegistry::instance()->join_method( $space_type ) ) : ?>
				<button
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
					data-current-state="join"
					data-wp-on--click="actions.joinSpace"
					data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
				><?php esc_html_e( 'Join', 'buddynext' ); ?></button>

			<?php else : ?>
				<button
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
					data-current-state="request"
					data-wp-on--click="actions.requestJoin"
					data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
				><?php esc_html_e( 'Request to join', 'buddynext' ); ?></button>
			<?php endif; ?>
		</div>
	</div>
</article>
