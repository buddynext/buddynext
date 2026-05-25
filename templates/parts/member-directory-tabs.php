<?php
/**
 * BuddyNext template part: member-directory-tabs.
 *
 * Renders the member-type pill row sitting above the directory filter
 * bar. Wraps `.bn-md-pill-row`. The `member_types` list is filterable
 * via the standard `_args` filter, letting Pro / bridge plugins register
 * additional pseudo-types (e.g. "Verified", "Staff") without touching
 * Free templates.
 *
 * Each pill descriptor: `{ slug, label, count }`.
 *
 * Used by: templates/directory/members.php.
 *
 * @package BuddyNext
 *
 * @var array  $member_types Optional. Pill list of `{ slug, label, count }` rows. Default [].
 * @var string $active_type  Optional. Currently-active type slug ('' = all). Default ''.
 * @var string $current_url  Optional. Base URL used when building pill links (reserved). Default ''.
 * @var array  $classes      Optional. Extra CSS classes appended to `.bn-md-pill-row`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_member_directory_tabs_before', $args )
 *   - do_action( 'buddynext_part_member_directory_tabs_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_member_directory_tabs_args',    array $args )
 *   - apply_filters( 'buddynext_part_member_directory_tabs_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'member_types' => isset( $member_types ) ? (array) $member_types : array(),
	'active_type'  => isset( $active_type ) ? (string) $active_type : '',
	'current_url'  => isset( $current_url ) ? (string) $current_url : '',
	'classes'      => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_directory_tabs_args', $args );

if ( empty( $args['member_types'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-md-pill-row' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_directory_tabs_classes', $bn_classes, $args );
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

$bn_types       = (array) $args['member_types'];
$bn_active_type = (string) $args['active_type'];
$bn_all_active  = ( '' === $bn_active_type );

do_action( 'buddynext_part_member_directory_tabs_before', $args );
?>
<nav class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Filter by member type', 'buddynext' ); ?>">
	<button
		type="button"
		class="bn-md-pill<?php echo $bn_all_active ? ' is-active' : ''; ?>"
		data-type-slug=""
		aria-pressed="<?php echo $bn_all_active ? 'true' : 'false'; ?>"
		data-wp-on--click="actions.selectMemberType"
		data-wp-bind--aria-pressed="state.allPillPressed"
		data-wp-bind--class="state.allPillClass"
	>
		<span class="bn-md-pill__label"><?php esc_html_e( 'All members', 'buddynext' ); ?></span>
	</button>
	<?php
	foreach ( $bn_types as $bn_type ) :
		if ( ! is_array( $bn_type ) ) {
			continue;
		}
		$bn_pill_slug  = isset( $bn_type['slug'] ) ? (string) $bn_type['slug'] : '';
		$bn_pill_name  = isset( $bn_type['label'] ) ? (string) $bn_type['label'] : '';
		$bn_pill_count = isset( $bn_type['count'] ) ? (int) $bn_type['count'] : 0;
		if ( '' === $bn_pill_slug || '' === $bn_pill_name ) {
			continue;
		}
		$bn_pill_active = ( $bn_pill_slug === $bn_active_type );
		?>
		<button
			type="button"
			class="bn-md-pill<?php echo $bn_pill_active ? ' is-active' : ''; ?>"
			data-type-slug="<?php echo esc_attr( $bn_pill_slug ); ?>"
			aria-pressed="<?php echo $bn_pill_active ? 'true' : 'false'; ?>"
			data-wp-on--click="actions.selectMemberType"
		>
			<span class="bn-md-pill__label"><?php echo esc_html( $bn_pill_name ); ?></span>
			<?php if ( $bn_pill_count > 0 ) : ?>
				<span class="bn-md-pill__count"><?php echo esc_html( number_format_i18n( $bn_pill_count ) ); ?></span>
			<?php endif; ?>
		</button>
	<?php endforeach; ?>
</nav>
<?php
do_action( 'buddynext_part_member_directory_tabs_after', $args );
