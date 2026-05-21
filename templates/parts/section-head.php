<?php
/**
 * BuddyNext template part: section-head.
 *
 * Hub-level section header rendered above a content area. Pairs a heading and
 * an optional meta row with a slot for actions on the right. Used by admin
 * sub-pages, directory hubs, and other top-of-page chrome.
 *
 * Layout (logical properties only):
 *   ┌──────────────────────────────────────────────────────────────────────┐
 *   │  <icon>  <title>          <subtitle/meta>          <actions slot>     │
 *   └──────────────────────────────────────────────────────────────────────┘
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string   $title         Required. Heading text.
 * @var string   $subtitle      Optional. Smaller supporting text shown beneath the title.
 * @var string   $title_icon    Optional. Lucide-icon slug rendered before the title.
 * @var string   $heading_level Optional. One of 'h1'|'h2'|'h3'. Default 'h1'.
 * @var string   $actions_html  Optional. Pre-built HTML for the actions slot.
 * @var string   $actions_action Optional. do_action() hook fired inside the actions slot.
 * @var array    $meta          Optional. Array of meta items, each
 *                              { 'icon' => string, 'label' => string }.
 * @var array    $classes       Optional. Extra CSS classes appended to `.bn-section-head`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_section_head_before',    $args )
 *   - do_action( 'buddynext_part_section_head_after',     $args )
 *   - do_action( $actions_action, $args )    (if set)
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_section_head_args',    array $args )
 *   - apply_filters( 'buddynext_part_section_head_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_allowed_levels = array( 'h1', 'h2', 'h3' );

$args = array(
	'title'          => isset( $title ) ? (string) $title : '',
	'subtitle'       => isset( $subtitle ) ? (string) $subtitle : '',
	'title_icon'     => isset( $title_icon ) ? (string) $title_icon : '',
	'heading_level'  => ( isset( $heading_level ) && in_array( (string) $heading_level, $bn_allowed_levels, true ) ) ? (string) $heading_level : 'h1',
	'actions_html'   => isset( $actions_html ) ? (string) $actions_html : '',
	'actions_action' => isset( $actions_action ) ? (string) $actions_action : '',
	'meta'           => isset( $meta ) && is_array( $meta ) ? $meta : array(),
	'classes'        => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_section_head_args', $args );

if ( '' === (string) $args['title'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-section-head' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_section_head_classes', $bn_classes, $args );
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

$bn_h = (string) $args['heading_level'];

do_action( 'buddynext_part_section_head_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<div class="bn-section-head__lead">
		<?php if ( '' !== (string) $args['title_icon'] && function_exists( 'buddynext_icon' ) ) : ?>
			<span class="bn-section-head__icon" aria-hidden="true">
				<?php buddynext_icon( (string) $args['title_icon'] ); ?>
			</span>
		<?php endif; ?>
		<div class="bn-section-head__text">
			<<?php echo esc_html( $bn_h ); ?> class="bn-section-head__title">
				<?php echo esc_html( (string) $args['title'] ); ?>
			</<?php echo esc_html( $bn_h ); ?>>
			<?php if ( '' !== (string) $args['subtitle'] ) : ?>
				<p class="bn-section-head__subtitle"><?php echo esc_html( (string) $args['subtitle'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $args['meta'] ) ) : ?>
				<div class="bn-section-head__meta">
					<?php foreach ( (array) $args['meta'] as $bn_meta_item ) : ?>
						<?php
						if ( ! is_array( $bn_meta_item ) ) {
							continue;
						}
						$bn_meta_icon  = isset( $bn_meta_item['icon'] ) ? (string) $bn_meta_item['icon'] : '';
						$bn_meta_label = isset( $bn_meta_item['label'] ) ? (string) $bn_meta_item['label'] : '';
						if ( '' === $bn_meta_label ) {
							continue;
						}
						?>
						<span class="bn-section-head__meta-item">
							<?php if ( '' !== $bn_meta_icon && function_exists( 'buddynext_icon' ) ) : ?>
								<?php buddynext_icon( $bn_meta_icon ); ?>
							<?php endif; ?>
							<?php echo esc_html( $bn_meta_label ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( '' !== (string) $args['actions_html'] || '' !== (string) $args['actions_action'] ) : ?>
		<div class="bn-section-head__actions">
			<?php
			if ( '' !== (string) $args['actions_html'] ) {
				// Caller-supplied HTML; caller is responsible for escaping.
				echo $args['actions_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( '' !== (string) $args['actions_action'] ) {
				do_action( (string) $args['actions_action'], $args );
			}
			?>
		</div>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_section_head_after', $args );
