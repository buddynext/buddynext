<?php
/**
 * BuddyNext template part: sidebar-card.
 *
 * Sidebar widget shell using the `.bn-sidebar-card` v2 primitive
 * (see `assets/css/bn-base.css`). Holds a heading and an arbitrary body.
 *
 * Body content can be supplied two ways:
 *  - $body_html: pre-rendered HTML string (escaped by the caller).
 *  - $body_action: do_action() hook name to fire inside the body slot.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string   $id           Optional. Suffix used in the contextual hook names.
 *                             Lets a host plugin distinguish multiple cards on a page
 *                             (e.g. 'trending', 'suggested'). Sanitized with sanitize_key().
 * @var string   $title        Required. Heading text.
 * @var string   $title_icon   Optional. Lucide-icon slug rendered next to the title.
 * @var string   $body_html    Optional. Pre-built body HTML.
 * @var string   $body_action  Optional. do_action() hook fired inside the body slot.
 * @var string   $see_all_url  Optional. URL for a "see all" link rendered after the body.
 * @var string   $see_all_label Optional. Label for the see-all link.
 * @var array    $classes      Optional. Extra CSS classes appended to `.bn-sidebar-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_sidebar_card_before', $args )
 *   - do_action( 'buddynext_part_sidebar_card_after',  $args )
 *   - do_action( $body_action, $args )                        (if $body_action set)
 *   - do_action( "buddynext_part_sidebar_card_body__{$id}", $args ) (if $id set)
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_sidebar_card_args',    array $args )
 *   - apply_filters( 'buddynext_part_sidebar_card_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'id'            => isset( $id ) ? sanitize_key( (string) $id ) : '',
	'title'         => isset( $title ) ? (string) $title : '',
	'title_icon'    => isset( $title_icon ) ? (string) $title_icon : '',
	'body_html'     => isset( $body_html ) ? (string) $body_html : '',
	'body_action'   => isset( $body_action ) ? (string) $body_action : '',
	'see_all_url'   => isset( $see_all_url ) ? (string) $see_all_url : '',
	'see_all_label' => isset( $see_all_label ) ? (string) $see_all_label : '',
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_sidebar_card_args', $args );

if ( '' === (string) $args['title'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-sidebar-card' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_sidebar_card_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_sidebar_card_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<div class="bn-sidebar-card__header">
		<?php if ( '' !== (string) $args['title_icon'] && function_exists( 'buddynext_icon' ) ) : ?>
			<?php buddynext_icon( (string) $args['title_icon'] ); ?>
		<?php endif; ?>
		<?php echo esc_html( (string) $args['title'] ); ?>
	</div>

	<div class="bn-sidebar-card__body">
		<?php
		if ( '' !== (string) $args['body_html'] ) {
			// Caller is responsible for escaping inside $body_html; we forward it verbatim.
			echo $args['body_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( '' !== (string) $args['body_action'] ) {
			do_action( (string) $args['body_action'], $args );
		}

		if ( '' !== (string) $args['id'] ) {
			do_action( 'buddynext_part_sidebar_card_body__' . (string) $args['id'], $args );
		}

		if ( '' !== (string) $args['see_all_url'] && '' !== (string) $args['see_all_label'] ) :
			?>
			<a class="bn-sidebar-see-all" href="<?php echo esc_url( (string) $args['see_all_url'] ); ?>">
				<?php echo esc_html( (string) $args['see_all_label'] ); ?>
			</a>
			<?php
		endif;
		?>
	</div>
</div>
<?php
do_action( 'buddynext_part_sidebar_card_after', $args );
