<?php
/**
 * BuddyNext template part: empty-state.
 *
 * Reusable empty-state card rendered when a feed, list, table, or directory has
 * no items to show. Uses the `.bn-empty-state` v2 primitive declared in
 * `assets/css/bn-base.css`.
 *
 * Variables are extracted from $args by {@see TemplateLoader::render()}. Pass
 * them as top-level keys to {@see buddynext_get_template()}.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string $icon       Optional. Lucide-icon slug rendered by buddynext_icon().
 *                         Defaults to 'inbox'. Pass empty string to omit.
 * @var string $title      Required. Heading text. Already-translated.
 * @var string $body       Optional. Supporting copy.
 * @var string $cta_url    Optional. URL for the primary CTA button.
 * @var string $cta_label  Optional. Visible label for the CTA button.
 * @var string $cta_icon   Optional. Lucide-icon slug rendered inside the CTA.
 * @var array  $classes    Optional. Extra CSS classes appended to `.bn-empty-state`.
 * @var string $tone       Optional. Reserved for future variant ('default'|'muted').
 *
 * Fires:
 *   - do_action( 'buddynext_part_empty_state_before', $args )
 *   - do_action( 'buddynext_part_empty_state_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_empty_state_args',    array $args )
 *   - apply_filters( 'buddynext_part_empty_state_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'icon'      => isset( $icon ) ? (string) $icon : 'inbox',
	'title'     => isset( $title ) ? (string) $title : '',
	'body'      => isset( $body ) ? (string) $body : '',
	'cta_url'   => isset( $cta_url ) ? (string) $cta_url : '',
	'cta_label' => isset( $cta_label ) ? (string) $cta_label : '',
	'cta_icon'  => isset( $cta_icon ) ? (string) $cta_icon : '',
	'classes'   => isset( $classes ) ? (array) $classes : array(),
	'tone'      => isset( $tone ) ? (string) $tone : 'default',
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_empty_state_args', $args );

if ( '' === (string) $args['title'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-empty-state' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_empty_state_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_empty_state_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" data-tone="<?php echo esc_attr( (string) $args['tone'] ); ?>">
	<?php if ( '' !== (string) $args['icon'] && function_exists( 'buddynext_icon' ) ) : ?>
		<?php buddynext_icon( (string) $args['icon'] ); ?>
	<?php endif; ?>

	<div class="bn-empty-state__title"><?php echo esc_html( (string) $args['title'] ); ?></div>

	<?php if ( '' !== (string) $args['body'] ) : ?>
		<p><?php echo esc_html( (string) $args['body'] ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== (string) $args['cta_url'] && '' !== (string) $args['cta_label'] ) : ?>
		<a
			class="bn-btn"
			data-variant="secondary"
			data-size="sm"
			href="<?php echo esc_url( (string) $args['cta_url'] ); ?>"
		>
			<?php if ( '' !== (string) $args['cta_icon'] && function_exists( 'buddynext_icon' ) ) : ?>
				<?php buddynext_icon( (string) $args['cta_icon'] ); ?>
			<?php endif; ?>
			<?php echo esc_html( (string) $args['cta_label'] ); ?>
		</a>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_empty_state_after', $args );
