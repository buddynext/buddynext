<?php
/**
 * BuddyNext template part: filter-strip.
 *
 * Renders a top-of-list filter row composing the `.bn-tabs` primitive with an
 * optional `.bn-input` search field and one or more `.bn-select` dropdowns.
 *
 * Used by feed, member directory, spaces directory, search results,
 * notifications, and moderation queue.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array  $tabs {
 *     Optional. Tab descriptors. Each entry:
 *     @type string $key    Slug used in data-tab + active comparison.
 *     @type string $label  Translated label.
 *     @type string $href   Optional. URL — when present, tab is an <a>.
 *     @type string $count  Optional. Numeric badge displayed alongside the label.
 *     @type string $icon   Optional. Lucide-icon slug.
 * }
 * @var string $active        Optional. Key of the currently-active tab.
 * @var array  $search {
 *     Optional. Search field config.
 *     @type string $name        Required when $search is set. Form field name.
 *     @type string $value       Optional. Current value.
 *     @type string $placeholder Optional. Placeholder text.
 *     @type string $aria_label  Optional. Override the default aria-label.
 * }
 * @var array  $selects {
 *     Optional. One or more select-field configs. Each entry:
 *     @type string $name     Required. Form field name.
 *     @type string $value    Optional. Current value.
 *     @type array  $options  Required. Map of value => label.
 *     @type string $aria_label Optional. ARIA label override.
 * }
 * @var string $form_action   Optional. When any of $search / $selects is set, the wrapping
 *                            <form> action attribute. Default empty (current URL).
 * @var string $form_method   Optional. Form method. Default 'get'.
 * @var array  $hidden        Optional. Map of name => value pairs to render as hidden inputs.
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-filter-strip`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_filter_strip_before', $args )
 *   - do_action( 'buddynext_part_filter_strip_after',  $args )
 *   - do_action( 'buddynext_part_filter_strip_extras', $args )  Inside the form, after fields.
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_filter_strip_args',    array $args )
 *   - apply_filters( 'buddynext_part_filter_strip_classes', array $classes, array $args )
 *   - apply_filters( 'buddynext_part_filter_strip_tabs',    array $tabs,    array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'tabs'        => isset( $tabs ) && is_array( $tabs ) ? $tabs : array(),
	'active'      => isset( $active ) ? (string) $active : '',
	'search'      => isset( $search ) && is_array( $search ) ? $search : array(),
	'selects'     => isset( $selects ) && is_array( $selects ) ? $selects : array(),
	'form_action' => isset( $form_action ) ? (string) $form_action : '',
	'form_method' => isset( $form_method ) && in_array( strtolower( (string) $form_method ), array( 'get', 'post' ), true )
		? strtolower( (string) $form_method )
		: 'get',
	'hidden'      => isset( $hidden ) && is_array( $hidden ) ? $hidden : array(),
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_filter_strip_args', $args );

$bn_classes = array_merge( array( 'bn-filter-strip' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_filter_strip_classes', $bn_classes, $args );
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

/** Computed tab list. @var array<int,array> $bn_tabs */
$bn_tabs     = (array) apply_filters( 'buddynext_part_filter_strip_tabs', (array) $args['tabs'], $args );
$bn_has_form = ! empty( $args['search'] ) || ! empty( $args['selects'] );

do_action( 'buddynext_part_filter_strip_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">

	<?php if ( ! empty( $bn_tabs ) ) : ?>
		<div class="bn-tabs" role="tablist">
			<?php
			foreach ( $bn_tabs as $bn_tab ) :
				if ( ! is_array( $bn_tab ) ) {
					continue;
				}
				$bn_key   = isset( $bn_tab['key'] ) ? sanitize_key( (string) $bn_tab['key'] ) : '';
				$bn_label = isset( $bn_tab['label'] ) ? (string) $bn_tab['label'] : '';
				if ( '' === $bn_key || '' === $bn_label ) {
					continue;
				}
				$bn_href     = isset( $bn_tab['href'] ) ? (string) $bn_tab['href'] : '';
				$bn_count    = isset( $bn_tab['count'] ) ? (string) $bn_tab['count'] : '';
				$bn_icon     = isset( $bn_tab['icon'] ) ? (string) $bn_tab['icon'] : '';
				$bn_active   = ( (string) $args['active'] === $bn_key );
				$bn_tab_tag  = '' !== $bn_href ? 'a' : 'button';
				$bn_tab_attr = '';
				if ( 'a' === $bn_tab_tag ) {
					$bn_tab_attr .= ' href="' . esc_url( $bn_href ) . '"';
				} else {
					$bn_tab_attr .= ' type="button"';
				}
				$bn_tab_attr .= ' data-tab="' . esc_attr( $bn_key ) . '"';
				$bn_tab_attr .= ' role="tab"';
				$bn_tab_attr .= ' aria-selected="' . ( $bn_active ? 'true' : 'false' ) . '"';
				$bn_tab_class = 'bn-tab' . ( $bn_active ? ' is-active' : '' );
				?>
				<<?php echo esc_html( $bn_tab_tag ); ?> class="<?php echo esc_attr( $bn_tab_class ); ?>"<?php echo $bn_tab_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<?php if ( '' !== $bn_icon && function_exists( 'buddynext_icon' ) ) : ?>
						<?php buddynext_icon( $bn_icon ); ?>
					<?php endif; ?>
					<span class="bn-tab__label"><?php echo esc_html( $bn_label ); ?></span>
					<?php if ( '' !== $bn_count ) : ?>
						<span class="bn-badge" data-tone="neutral"><?php echo esc_html( $bn_count ); ?></span>
					<?php endif; ?>
				</<?php echo esc_html( $bn_tab_tag ); ?>>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $bn_has_form ) : ?>
		<form
			class="bn-filter-strip__form"
			<?php echo '' !== (string) $args['form_action'] ? 'action="' . esc_url( (string) $args['form_action'] ) . '"' : ''; ?>
			method="<?php echo esc_attr( (string) $args['form_method'] ); ?>"
			role="search"
		>
			<?php foreach ( (array) $args['hidden'] as $bn_h_name => $bn_h_val ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $bn_h_name ); ?>" value="<?php echo esc_attr( (string) $bn_h_val ); ?>">
			<?php endforeach; ?>

			<?php if ( ! empty( $args['search'] ) ) : ?>
				<?php
				$bn_s_name = isset( $args['search']['name'] ) ? (string) $args['search']['name'] : 's';
				$bn_s_val  = isset( $args['search']['value'] ) ? (string) $args['search']['value'] : '';
				$bn_s_ph   = isset( $args['search']['placeholder'] ) ? (string) $args['search']['placeholder'] : __( 'Search…', 'buddynext' );
				$bn_s_aria = isset( $args['search']['aria_label'] ) ? (string) $args['search']['aria_label'] : $bn_s_ph;
				?>
				<input
					type="search"
					class="bn-input"
					name="<?php echo esc_attr( $bn_s_name ); ?>"
					value="<?php echo esc_attr( $bn_s_val ); ?>"
					placeholder="<?php echo esc_attr( $bn_s_ph ); ?>"
					aria-label="<?php echo esc_attr( $bn_s_aria ); ?>"
				>
			<?php endif; ?>

			<?php foreach ( (array) $args['selects'] as $bn_sel ) : ?>
				<?php
				if ( ! is_array( $bn_sel ) ) {
					continue;
				}
				$bn_sel_name    = isset( $bn_sel['name'] ) ? (string) $bn_sel['name'] : '';
				$bn_sel_value   = isset( $bn_sel['value'] ) ? (string) $bn_sel['value'] : '';
				$bn_sel_options = isset( $bn_sel['options'] ) && is_array( $bn_sel['options'] ) ? $bn_sel['options'] : array();
				$bn_sel_aria    = isset( $bn_sel['aria_label'] ) ? (string) $bn_sel['aria_label'] : $bn_sel_name;
				if ( '' === $bn_sel_name || empty( $bn_sel_options ) ) {
					continue;
				}
				?>
				<select
					class="bn-select"
					name="<?php echo esc_attr( $bn_sel_name ); ?>"
					aria-label="<?php echo esc_attr( $bn_sel_aria ); ?>"
				>
					<?php foreach ( $bn_sel_options as $bn_opt_val => $bn_opt_lbl ) : ?>
						<option
							value="<?php echo esc_attr( (string) $bn_opt_val ); ?>"
							<?php selected( $bn_sel_value, (string) $bn_opt_val ); ?>
						><?php echo esc_html( (string) $bn_opt_lbl ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endforeach; ?>

			<?php do_action( 'buddynext_part_filter_strip_extras', $args ); ?>

			<button type="submit" class="bn-btn" data-variant="secondary" data-size="sm">
				<?php esc_html_e( 'Apply', 'buddynext' ); ?>
			</button>
		</form>
	<?php endif; ?>

</div>
<?php
do_action( 'buddynext_part_filter_strip_after', $args );
