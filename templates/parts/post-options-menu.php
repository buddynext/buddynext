<?php
/**
 * BuddyNext template part: post-options-menu.
 *
 * Renders the kebab toggle and dropdown options menu (Edit, Pin/Unpin, Report,
 * Delete) that sits at the trailing edge of the post-card head row. Mirrors
 * the markup previously inlined in `templates/partials/post-card.php` between
 * `<div class="bn-post-card__menu-wrap">` and the matching closing `</div>`.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array $bn_post     Hydrated post array.
 * @var int   $bn_post_id  Post ID.
 * @var bool  $can_edit    Whether the viewer can edit the post.
 * @var bool  $can_pin     Whether the viewer can pin/unpin the post.
 * @var bool  $can_report  Whether the viewer can report the post.
 * @var bool  $has_reported Whether the viewer has already reported the post.
 * @var bool  $can_delete  Whether the viewer can delete the post.
 * @var bool  $is_pinned   Whether the post is currently pinned.
 * @var array $classes     Optional extra CSS classes for the wrap.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_options_menu_before', $args )
 *   - do_action( 'buddynext_part_post_options_menu_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_options_menu_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_options_menu_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'bn_post'      => isset( $bn_post ) && is_array( $bn_post ) ? $bn_post : array(),
	'bn_post_id'   => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'can_edit'     => ! empty( $can_edit ),
	'can_pin'      => ! empty( $can_pin ),
	'can_report'   => ! empty( $can_report ),
	'has_reported' => ! empty( $has_reported ),
	'can_delete'   => ! empty( $can_delete ),
	'is_pinned'    => ! empty( $is_pinned ),
	'classes'      => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_options_menu_args', $args );

if ( 0 === (int) $args['bn_post_id'] ) {
	return;
}

// Suppress the whole control (kebab + dropdown) when no action qualifies for
// this viewer/post. A three-dot toggle that opens an empty menu reads as
// "the menu doesn't open" — so when Edit/Pin/Report/Delete all gate out (e.g. a
// viewer with no rights over a tool/service-generated activity), render nothing
// rather than a dead affordance.
if ( empty( $args['can_edit'] ) && empty( $args['can_pin'] ) && empty( $args['can_report'] ) && empty( $args['can_delete'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-post-card__menu-wrap' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_options_menu_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

do_action( 'buddynext_part_post_options_menu_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<button
		type="button"
		class="bn-post-card__menu"
		aria-label="<?php esc_attr_e( 'Post actions', 'buddynext' ); ?>"
		aria-haspopup="true"
		aria-expanded="false"
		data-wp-on--click="actions.toggleOptionsMenu"
		data-wp-bind--aria-expanded="state.optionsOpen"
	><?php buddynext_icon( 'more-horizontal' ); ?></button>

	<div
		class="bn-post-card__options-menu"
		role="menu"
		data-wp-bind--hidden="!state.optionsOpen"
		hidden
	>
		<?php if ( ! empty( $args['can_edit'] ) ) : ?>
			<button
				type="button"
				class="bn-post-card__menu-item"
				role="menuitem"
				data-wp-on--click="actions.editPost"
			><?php buddynext_icon( 'edit' ); ?> <?php esc_html_e( 'Edit', 'buddynext' ); ?></button>
		<?php endif; ?>

		<?php if ( ! empty( $args['can_pin'] ) ) : ?>
			<button
				type="button"
				class="bn-post-card__menu-item"
				role="menuitem"
				data-wp-on--click="actions.pinPost"
			><?php buddynext_icon( 'bookmark' ); ?> <?php echo ! empty( $args['is_pinned'] ) ? esc_html__( 'Unpin', 'buddynext' ) : esc_html__( 'Pin to profile', 'buddynext' ); ?></button>
		<?php endif; ?>

		<?php if ( ! empty( $args['can_report'] ) ) : ?>
			<?php // Both items are present so the menu can flip Report -> Reported reactively after a report (state.hasReported), with the server-rendered hidden attribute matching the initial state to avoid a flash. ?>
			<button
				type="button"
				class="bn-post-card__menu-item bn-post-card__menu-item--danger"
				role="menuitem"
				data-wp-on--click="actions.reportPost"
				data-wp-bind--hidden="state.hasReported"
				<?php echo ! empty( $args['has_reported'] ) ? 'hidden' : ''; ?>
			><?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Report', 'buddynext' ); ?></button>
			<span
				class="bn-post-card__menu-item bn-post-card__menu-item--reported"
				role="menuitem"
				aria-disabled="true"
				data-wp-bind--hidden="!state.hasReported"
				<?php echo empty( $args['has_reported'] ) ? 'hidden' : ''; ?>
			><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Reported', 'buddynext' ); ?></span>
		<?php endif; ?>

		<?php if ( ! empty( $args['can_delete'] ) ) : ?>
			<button
				type="button"
				class="bn-post-card__menu-item bn-post-card__menu-item--danger"
				role="menuitem"
				data-wp-on--click="actions.deletePost"
			><?php buddynext_icon( 'trash' ); ?> <?php esc_html_e( 'Delete', 'buddynext' ); ?></button>
		<?php endif; ?>
	</div>
</div>
<?php
do_action( 'buddynext_part_post_options_menu_after', $args );
