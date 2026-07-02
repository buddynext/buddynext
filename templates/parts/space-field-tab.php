<?php
/**
 * BuddyNext template part: space-field-tab.
 *
 * Body for a custom field that an owner has promoted to a space tab (U3). Renders
 * the field's value as readable content: a `url` field becomes a labelled CTA
 * link, a `textarea` field becomes a formatted content block. An empty tab shows
 * a manager-only nudge to add content (regular members never see an empty tab —
 * SpaceNav's condition hides it).
 *
 * @package BuddyNext
 * @since   1.0.4
 *
 * @var array  $field      Required. Field definition (key, label, type, description, …).
 * @var mixed  $value      Required. Current per-space field value.
 * @var int    $space_id   Required. Space ID.
 * @var string $space_slug Optional. Space slug (for the manage link).
 * @var bool   $can_manage Optional. Viewer can manage the space.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_ft_field = isset( $field ) && is_array( $field ) ? $field : null;
if ( null === $bn_ft_field ) {
	return;
}

$bn_ft_value  = isset( $value ) ? (string) $value : '';
$bn_ft_type   = (string) ( $bn_ft_field['type'] ?? '' );
$bn_ft_label  = (string) ( $bn_ft_field['label'] ?? '' );
$bn_ft_desc   = (string) ( $bn_ft_field['description'] ?? '' );
$bn_ft_manage = ! empty( $can_manage );
$bn_ft_slug   = isset( $space_slug ) ? (string) $space_slug : '';
?>
<div class="bn-card bn-space-field-tab">
	<header class="bn-space-field-tab__head">
		<h2 class="bn-space-field-tab__title"><?php echo esc_html( $bn_ft_label ); ?></h2>
		<?php if ( '' !== $bn_ft_desc ) : ?>
			<p class="bn-space-field-tab__desc"><?php echo esc_html( $bn_ft_desc ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( '' === trim( $bn_ft_value ) ) : ?>
		<div class="bn-space-field-tab__empty">
			<?php if ( $bn_ft_manage && '' !== $bn_ft_slug ) : ?>
				<p><?php esc_html_e( 'This tab has no content yet.', 'buddynext' ); ?></p>
				<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( buddynext_space_settings_url( $bn_ft_slug ) . '?bn_stab=fields' ); ?>">
					<?php esc_html_e( 'Add content', 'buddynext' ); ?>
				</a>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing here yet.', 'buddynext' ); ?></p>
			<?php endif; ?>
		</div>
	<?php elseif ( 'url' === $bn_ft_type ) : ?>
		<div class="bn-space-field-tab__cta">
			<a class="bn-btn" data-variant="primary" data-size="md" href="<?php echo esc_url( $bn_ft_value ); ?>" target="_blank" rel="noopener noreferrer">
				<?php buddynext_icon( 'external-link' ); ?>
				<?php echo esc_html( $bn_ft_label ); ?>
			</a>
			<p class="bn-space-field-tab__url"><?php echo esc_html( $bn_ft_value ); ?></p>
		</div>
	<?php else : ?>
		<div class="bn-space-field-tab__content"><?php echo wp_kses_post( wpautop( $bn_ft_value ) ); ?></div>
	<?php endif; ?>
</div>
