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
 * @var int   $subspace_count   Optional. Visibility-scoped sub-space count for this space. Default 0.
 * @var bool  $compact          Optional. Drop the cover band and set the emblem beside the name,
 *                              for narrow columns. Default false (the directory's full card).
 * @var bool  $show_action      Optional. Render the footer action (Join / Requested / Manage /
 *                              Log in to join). Default true.
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
$bn_dc_subspaces  = isset( $subspace_count ) ? (int) $subspace_count : 0;

// Two presentation knobs, both defaulted to the directory's own card so every
// existing caller is byte-identical. The featured-space block is the only caller
// that sets them today: a sidebar is too narrow for an 80px cover band, and an
// owner may want a card that presents a space without asking anyone to join it.
// They live here rather than in a block-local copy of this markup because a
// second copy is exactly how the featured card and the directory card drifted
// apart last time (see the block template's header).
$bn_dc_compact = ! empty( $compact );
$bn_dc_action  = ! isset( $show_action ) || (bool) $show_action;

$space_id = (int) $bn_dc_space['id'];
// 'admin' is not a space role (ALLOWED_ROLES = owner|moderator|member); site
// admins are gated server-side via manage_options. Dead branch removed.
$is_admin_mod = $bn_dc_membership && in_array( $bn_dc_membership['role'], array( 'moderator', 'owner' ), true ) && 'active' === $bn_dc_membership['status'];
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

// The category's own colour pair (bn_space_categories.color / .text_color, both
// sanitize_hex_color()'d on write by SpaceCategoryService and defaulted there, so the
// pair is always a legible background/foreground combination whatever the owner picks).
// Same treatment the admin category list and the member-type badges already give it;
// the directory card was the one surface that dropped the owner's colour on the floor.
// Both halves come from the row, so the badge is self-contained in light AND dark.
$bn_card_cat_bg    = $bn_card_cat ? (string) ( $bn_card_cat['color'] ?? '' ) : '';
$bn_card_cat_fg    = $bn_card_cat ? (string) ( $bn_card_cat['text_color'] ?? '' ) : '';
$bn_card_cat_style = ( '' !== $bn_card_cat_bg && '' !== $bn_card_cat_fg )
	? sprintf( 'background:%1$s;color:%2$s;', $bn_card_cat_bg, $bn_card_cat_fg )
	: '';

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
<?php
// The join method, printed explicitly. Without it the JS falls back to inferring the
// method from the privacy badge's TONE — and that inference only holds for the three
// built-in types (open -> success -> direct). A custom space type configured as
// direct-join but given any other tone was inferred as "request", so after leaving it
// the member was re-offered "Request to join" on a space they could simply join.
// The tone fallback stays for old markup; this is the signal it prefers.
$bn_dc_join_method = SpaceTypeRegistry::instance()->join_method( (string) $space_type );
?>
<article class="bn-card bn-sd-card" data-interactive role="listitem" data-size="<?php echo $bn_dc_compact ? 'compact' : 'full'; ?>" data-join-method="<?php echo esc_attr( $bn_dc_join_method ); ?>" aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space_name, $privacy_label ) ); ?>">
	<?php if ( ! $bn_dc_compact ) : ?>
	<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-sd-card__cover-link">
		<div class="bn-sd-card__cover" data-tone="<?php echo esc_attr( $cover_tone ); ?>">
			<?php if ( ! empty( $bn_dc_space['cover_image_url'] ) ) : ?>
				<img src="<?php echo esc_url( (string) $bn_dc_space['cover_image_url'] ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<div class="bn-sd-card__emblem" aria-hidden="true"><?php echo $bn_card_emblem; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- branches above each escape their content. ?></div>
		</div>
	</a>
	<?php endif; ?>

	<div class="bn-sd-card__body">
		<?php if ( $bn_dc_compact ) : ?>
			<?php
			// The compact card keeps the emblem and drops only the cover band, so a
			// space stays visually identifiable in a sidebar. It is the same emblem
			// markup with the same fallback chain; CSS lifts it out of the cover's
			// absolute positioning and sets it beside the name.
			?>
			<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-sd-card__emblem-link">
				<span class="bn-sd-card__emblem"><?php echo $bn_card_emblem; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- branches above each escape their content. ?></span>
			</a>
		<?php endif; ?>

		<a href="<?php echo esc_url( $space_url ); ?>" class="bn-sd-card__name-link">
			<h2 class="bn-sd-card__name"
				aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space_name, $privacy_label ) ); ?>"
			><?php echo esc_html( $space_name ); ?><span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span></h2>
		</a>

		<?php if ( '' !== $bn_card_cat_name ) : ?>
			<?php
			// The category name, and only the name. This used to be prefixed with a
			// generic Lucide `hash` glyph, so the SAME category read "#General" on a card
			// but "General" in the directory's filter chips. The `#` is not part of the
			// stored name (bn_space_categories.name holds "General") and it is not the
			// category's own icon either (categories carry icon_svg / CATEGORY_ICON_MAP,
			// which this card already renders as the cover emblem). It was decoration
			// that contradicted both. The colour pair below is the category signal.
			?>
			<div class="bn-sd-card__category">
				<span class="bn-badge bn-sd-card__cat" data-tone="neutral"
					<?php echo '' !== $bn_card_cat_style ? 'style="' . esc_attr( $bn_card_cat_style ) . '"' : ''; ?>
				><?php echo esc_html( $bn_card_cat_name ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $bn_dc_space['description'] ) ) : ?>
			<p class="bn-sd-card__desc"><?php echo esc_html( wp_trim_words( (string) $bn_dc_space['description'], 18 ) ); ?></p>
		<?php endif; ?>

		<div class="bn-sd-card__stats">
			<span class="bn-sd-card__stat">
				<?php
				// translators: %s: formatted member count.
				printf( esc_html( _n( '%s member', '%s members', (int) ( $bn_dc_space['member_count'] ?? 0 ), 'buddynext' ) ), esc_html( $member_count ) );
				?>
			</span>
			<?php if ( $bn_dc_subspaces > 0 ) : ?>
				<span class="bn-sd-card__stat">
					<?php
					// translators: %s: sub-space count.
					printf( esc_html( _n( '%s sub-space', '%s sub-spaces', $bn_dc_subspaces, 'buddynext' ) ), esc_html( number_format_i18n( $bn_dc_subspaces ) ) );
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( $bn_dc_action ) : ?>
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

			<?php elseif ( ! buddynext_service( 'space_members' )->can_join( $bn_dc_space, $bn_dc_uid ) ) : ?>
				<?php
				// The gate refuses this member, so neither join CTA belongs on the
				// card. Same rule as space-hero.php: can_join() runs the
				// buddynext_can_join_space filter, which is the plan gate rather than
				// the open/private distinction — a plain private space answers true
				// and still reaches "Request to join" below.
				//
				// Without this a plan-gated space sat in the directory offering a
				// button that returns 403, which is worse than a locked-looking card:
				// it reads as available until the member spends a click on it.
				?>

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
		<?php endif; ?>
	</div>
</article>
