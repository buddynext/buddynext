<?php
/**
 * BuddyNext template part: profile-tab-bar.
 *
 * Renders the `.bn-tabs` tab navigation for the profile-view page. The
 * tab list is driven by the `tabs` arg so bridge/Pro plugins can append
 * additional rows by hooking `buddynext_part_profile_tab_bar_args`.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int    $profile_user_id Required. ID of the profile being viewed.
 * @var int    $viewer_id       Optional. ID of the current viewer.
 * @var string $active_tab      Optional. Slug of the initially-active tab. Default `posts`.
 * @var array  $tabs            Required. List of tab descriptors. Each entry is
 *                              `[ 'slug' => string, 'label' => string,
 *                                 'count' => int|string?, 'href' => string?,
 *                                 'icon' => string? ]`. When `href` is omitted
 *                              the tab is rendered as a `<button>` that flips
 *                              the Interactivity API context tab.
 * @var array  $classes         Optional. Extra CSS classes appended to `.bn-tabs`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_tab_bar_before', $args )
 *   - do_action( 'buddynext_part_profile_tab_bar_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_tab_bar_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_tab_bar_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'profile_user_id' => isset( $profile_user_id ) ? (int) $profile_user_id : 0,
	'viewer_id'       => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'active_tab'      => isset( $active_tab ) ? (string) $active_tab : 'posts',
	'tabs'            => isset( $tabs ) && is_array( $tabs ) ? $tabs : array(),
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_tab_bar_args', $args );

if ( empty( $args['tabs'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-tabs', 'bn-pf-tabs' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_tab_bar_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_profile_tab_bar_before', $args );
?>
		<!-- Tab bar (v2 .bn-tabs primitive) -->
		<div class="<?php echo esc_attr( $bn_class ); ?>" role="tablist">
			<?php
			foreach ( (array) $args['tabs'] as $bn_tab ) :
				if ( ! is_array( $bn_tab ) ) {
					continue;
				}
				$bn_tab_slug  = isset( $bn_tab['slug'] ) ? (string) $bn_tab['slug'] : '';
				$bn_tab_label = isset( $bn_tab['label'] ) ? (string) $bn_tab['label'] : '';
				$bn_tab_count = isset( $bn_tab['count'] ) ? (string) $bn_tab['count'] : '';
				$bn_tab_href  = isset( $bn_tab['href'] ) ? (string) $bn_tab['href'] : '';
				$bn_tab_icon  = isset( $bn_tab['icon'] ) ? (string) $bn_tab['icon'] : '';
				if ( '' === $bn_tab_slug || '' === $bn_tab_label ) {
					continue;
				}
				$bn_is_active = ( $bn_active === $bn_tab_slug );

				if ( '' !== $bn_tab_href ) :
					?>
					<a class="bn-tab"
						role="tab"
						aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
						href="<?php echo esc_url( $bn_tab_href ); ?>">
						<?php if ( '' !== $bn_tab_icon && function_exists( 'buddynext_icon' ) ) : ?>
							<?php buddynext_icon( $bn_tab_icon ); ?>
						<?php endif; ?>
						<?php echo esc_html( $bn_tab_label ); ?>
						<?php if ( '' !== $bn_tab_count ) : ?>
							<span class="bn-tab__count"><?php echo esc_html( $bn_tab_count ); ?></span>
						<?php endif; ?>
					</a>
				<?php else : ?>
					<button class="bn-tab"
						role="tab"
						type="button"
						aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
						data-wp-on--click="actions.setTab"
						data-tab="<?php echo esc_attr( $bn_tab_slug ); ?>">
						<?php if ( '' !== $bn_tab_icon && function_exists( 'buddynext_icon' ) ) : ?>
							<?php buddynext_icon( $bn_tab_icon ); ?>
						<?php endif; ?>
						<?php echo esc_html( $bn_tab_label ); ?>
						<?php if ( '' !== $bn_tab_count ) : ?>
							<span class="bn-tab__count"><?php echo esc_html( $bn_tab_count ); ?></span>
						<?php endif; ?>
					</button>
					<?php
				endif;
			endforeach;
			?>
		</div>
<?php
do_action( 'buddynext_part_profile_tab_bar_after', $args );
