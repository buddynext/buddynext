<?php
/**
 * BuddyNext template part: space-tab-bar.
 *
 * Renders the `<nav class="bn-tabs bn-sh-hero__tabs">` navigation strip
 * shown below the space hero. The tab list is driven by the `tabs` arg so
 * Pro / bridge plugins (Jetonomy Discussions, WPMediaVerse Media) can
 * append additional rows by hooking `buddynext_part_space_tab_bar_args`.
 *
 * Tab entries accept two shapes:
 *   - `string` — a localized label. The href falls back to
 *     `add_query_arg( 'bn_tab', $slug )`.
 *   - `array`  — `[ 'label' => string, 'url' => string?, 'active' => bool?,
 *     'count' => int|string? ]`. When `url` is set the entry renders with
 *     `rel="noopener"` and is never marked active. When `count` is a
 *     positive integer or non-empty string it renders inside a
 *     `<span class="bn-tab__count">` chip next to the label (matches the
 *     v2 prototype tab-counter pattern across notifications / profile /
 *     hashtag tab bars). Integer counts >99 are abbreviated to `99+`;
 *     larger numbers may be passed pre-formatted as strings (e.g. `"2.4k"`).
 *
 * Used by: templates/parts/space-hero.php (default).
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int    $space_id   Required. Current space ID.
 * @var string $active_tab Optional. Slug of the initially-active tab. Default 'feed'.
 * @var array  $tabs       Required. Map of `slug => label|config`.
 * @var array  $classes    Optional. Extra CSS classes appended to `.bn-tabs`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_tab_bar_before', $args )
 *   - do_action( 'buddynext_part_space_tab_bar_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_tab_bar_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_tab_bar_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space_id'   => isset( $space_id ) ? (int) $space_id : 0,
	'active_tab' => isset( $active_tab ) ? (string) $active_tab : 'feed',
	'tabs'       => isset( $tabs ) && is_array( $tabs ) ? $tabs : array(),
	'classes'    => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_tab_bar_args', $args );

if ( empty( $args['tabs'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-tabs', 'bn-sh-hero__tabs' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_tab_bar_classes', $bn_classes, $args );
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

$bn_active = (string) $args['active_tab'];

do_action( 'buddynext_part_space_tab_bar_before', $args );
?>
<nav class="<?php echo esc_attr( $bn_class ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Space navigation', 'buddynext' ); ?>">
	<?php
	foreach ( (array) $args['tabs'] as $slug => $tab_data ) :
		$tab_count = null;
		if ( is_array( $tab_data ) ) {
			// External-link tab injected by an addon (or array-form built-in with count).
			$tab_label  = $tab_data['label'] ?? $slug;
			$tab_url    = $tab_data['url'] ?? add_query_arg( 'bn_tab', $slug );
			$tab_active = isset( $tab_data['url'] )
				? false
				: ( ! empty( $tab_data['active'] ) || $bn_active === (string) $slug );
			$tab_rel    = isset( $tab_data['url'] ) ? 'noopener' : '';
			if ( isset( $tab_data['count'] ) ) {
				$tab_count = $tab_data['count'];
			}
		} else {
			$tab_label  = $tab_data;
			$tab_url    = add_query_arg( 'bn_tab', $slug );
			$tab_active = ( $bn_active === (string) $slug );
			$tab_rel    = '';
		}

		// Format the count chip. Integers >99 collapse to "99+"; strings
		// are rendered verbatim so callers can pass abbreviations ("2.4k").
		$tab_count_label = '';
		if ( is_int( $tab_count ) ) {
			if ( $tab_count > 0 ) {
				$tab_count_label = $tab_count > 99 ? '99+' : (string) $tab_count;
			}
		} elseif ( is_string( $tab_count ) && '' !== trim( $tab_count ) ) {
			$tab_count_label = trim( $tab_count );
		}
		?>
		<a
			href="<?php echo esc_url( (string) $tab_url ); ?>"
			class="bn-tab<?php echo $tab_active ? ' is-active' : ''; ?>"
			role="tab"
			aria-selected="<?php echo $tab_active ? 'true' : 'false'; ?>"
			<?php echo $tab_rel ? 'rel="' . esc_attr( $tab_rel ) . '"' : ''; ?>
		>
			<span class="bn-tab__label"><?php echo esc_html( (string) $tab_label ); ?></span>
			<?php if ( '' !== $tab_count_label ) : ?>
				<span class="bn-tab__count"><?php echo esc_html( $tab_count_label ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>
<?php
do_action( 'buddynext_part_space_tab_bar_after', $args );
