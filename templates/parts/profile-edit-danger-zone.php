<?php
/**
 * BuddyNext template part: profile-edit-danger-zone.
 *
 * Renders the danger-zone card on the edit-profile page (delete-account
 * button row). The delete-account modal stays adjacent to its semantic
 * owner in `templates/profile/edit.php` because the modal must render
 * *outside* the `<form>` element (so that clicking the confirm button
 * inside the modal doesn't implicitly submit the form) while this part
 * renders *inside* it.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array $actions Required. List of danger-zone action descriptors. Each item:
 *   - `id`       (string) Stable key.
 *   - `label`    (string) Button label.
 *   - `tone`     (string) `bn-btn` `data-variant` value (default 'danger').
 *   - `size`     (string) `bn-btn` `data-size` value (default 'md').
 *   - `action`   (string) `data-wp-on--click` Interactivity action.
 *   - `modal_id` (string) Optional id reference (informational; modal lives in composer).
 * @var string $title    Optional. Card heading. Defaults to "Danger zone".
 * @var string $subtitle Optional. Card subtitle. Defaults to the
 *                       "Permanently delete your account…" copy.
 * @var string $title_id Optional. id for the `<h2>` (default 'bn-ep-danger-title').
 * @var array  $classes  Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_danger_zone_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_danger_zone_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_danger_zone_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_danger_zone_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'actions'  => isset( $actions ) && is_array( $actions ) ? $actions : array(),
	'title'    => isset( $title ) ? (string) $title : __( 'Danger zone', 'buddynext' ),
	'subtitle' => isset( $subtitle ) ? (string) $subtitle : __( 'Permanently delete your account. This cannot be undone.', 'buddynext' ),
	'title_id' => isset( $title_id ) ? (string) $title_id : 'bn-ep-danger-title',
	'classes'  => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_danger_zone_args', $args );

if ( empty( $args['actions'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-ep-card', 'bn-ep-danger' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_danger_zone_classes', $bn_classes, $args );
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

$bn_title    = (string) $args['title'];
$bn_subtitle = (string) $args['subtitle'];
$bn_title_id = (string) $args['title_id'];

do_action( 'buddynext_part_profile_edit_danger_zone_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-labelledby="<?php echo esc_attr( $bn_title_id ); ?>">
	<header class="bn-ep-card-header">
		<h2 class="bn-ep-card-title" id="<?php echo esc_attr( $bn_title_id ); ?>">
			<?php echo esc_html( $bn_title ); ?>
		</h2>
		<?php if ( '' !== $bn_subtitle ) : ?>
			<p class="bn-ep-card-subtitle">
				<?php echo esc_html( $bn_subtitle ); ?>
			</p>
		<?php endif; ?>
	</header>
	<div class="bn-ep-card-body">
		<?php foreach ( (array) $args['actions'] as $bn_a ) : ?>
			<?php
			if ( ! is_array( $bn_a ) ) {
				continue;
			}
			$bn_a_label  = isset( $bn_a['label'] ) ? (string) $bn_a['label'] : '';
			$bn_a_action = isset( $bn_a['action'] ) ? (string) $bn_a['action'] : '';
			if ( '' === $bn_a_label ) {
				continue;
			}
			$bn_a_tone = isset( $bn_a['tone'] ) ? (string) $bn_a['tone'] : 'danger';
			$bn_a_size = isset( $bn_a['size'] ) ? (string) $bn_a['size'] : 'md';
			?>
			<button class="bn-btn"
				type="button"
				data-variant="<?php echo esc_attr( $bn_a_tone ); ?>"
				data-size="<?php echo esc_attr( $bn_a_size ); ?>"
				<?php
				if ( '' !== $bn_a_action ) :
					?>
					data-wp-on--click="<?php echo esc_attr( $bn_a_action ); ?>"<?php endif; ?>>
				<?php echo esc_html( $bn_a_label ); ?>
			</button>
		<?php endforeach; ?>
	</div>
</section>
<?php
do_action( 'buddynext_part_profile_edit_danger_zone_after', $args );
