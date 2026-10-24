<?php
/**
 * BuddyNext template part: space-settings-panel-links.
 *
 * The "Linked to" settings tab: every object (a course, a group, a store) from any
 * addon that this space is linked to, with a way to remove each link. Shown only
 * while the space has a link. Removing a link keeps the space and its members.
 *
 * Theme-overridable at {child-theme}/buddynext/parts/space-settings-panel-links.php.
 *
 * @package BuddyNext
 * @since   1.2.7
 *
 * @var object $space          Required. Space row.
 * @var array  $links_settings Required. Bundle:
 *   - `space_id` (int)
 *   - `links`    (array) From SpaceLinksController::describe().
 * @var array  $classes        Optional. Extra CSS classes appended to `.bn-card`.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'          => isset( $space ) ? $space : null,
	'links_settings' => isset( $links_settings ) && is_array( $links_settings ) ? $links_settings : array(),
	'classes'        => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_links_args', $args );

if ( ! $args['space'] ) {
	return;
}

$bn_lnk          = (array) $args['links_settings'];
$bn_lnk_space_id = isset( $bn_lnk['space_id'] ) ? (int) $bn_lnk['space_id'] : 0;
$bn_lnk_rows     = isset( $bn_lnk['links'] ) && is_array( $bn_lnk['links'] ) ? $bn_lnk['links'] : array();

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel', 'bn-links-panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_links_classes', $bn_classes, $args );
$bn_class   = trim( implode( ' ', array_unique( array_filter( $bn_classes, static fn( $c ) => is_string( $c ) && '' !== $c ) ) ) );

do_action( 'buddynext_part_space_settings_panel_links_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	data-bn-links-panel
	data-space-id="<?php echo esc_attr( (string) $bn_lnk_space_id ); ?>"
>
	<header class="bn-space-settings__panel-head">
		<h2 class="bn-space-settings__panel-title"><?php buddynext_icon( 'link' ); ?> <?php esc_html_e( 'Linked to', 'buddynext' ); ?></h2>
		<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'This space follows the access of what it is linked to: people who get access are added. Removing a link keeps the space and everyone in it.', 'buddynext' ); ?></p>
	</header>

	<ul class="bn-links-panel__list">
		<?php foreach ( $bn_lnk_rows as $bn_row ) : ?>
			<li class="bn-links-panel__row">
				<div class="bn-links-panel__body">
					<?php if ( '' !== (string) $bn_row['name'] && '' !== (string) $bn_row['url'] ) : ?>
						<a class="bn-links-panel__name" href="<?php echo esc_url( (string) $bn_row['url'] ); ?>"><?php echo esc_html( (string) $bn_row['name'] ); ?></a>
					<?php else : ?>
						<span class="bn-links-panel__name">
							<?php
							echo esc_html(
								'' !== (string) $bn_row['name']
									? (string) $bn_row['name']
									/* translators: 1: link type, 2: object id. */
									: sprintf( __( '%1$s #%2$d', 'buddynext' ), ucfirst( (string) $bn_row['type'] ), (int) $bn_row['id'] )
							);
							?>
						</span>
					<?php endif; ?>
					<span class="bn-links-panel__meta">
						<span class="bn-badge"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $bn_row['source'] ) ) ); ?></span>
						<?php if ( ! empty( $bn_row['managed'] ) ) : ?>
							<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Created for this link', 'buddynext' ); ?></span>
						<?php endif; ?>
						<?php if ( empty( $bn_row['available'] ) ) : ?>
							<span class="bn-badge" data-tone="warn"><?php esc_html_e( 'Plugin not active', 'buddynext' ); ?></span>
						<?php endif; ?>
					</span>
				</div>
				<button
					type="button"
					class="bn-btn"
					data-variant="ghost"
					data-size="md"
					data-tone="danger"
					data-bn-link-remove
					data-source="<?php echo esc_attr( (string) $bn_row['source'] ); ?>"
					data-type="<?php echo esc_attr( (string) $bn_row['type'] ); ?>"
					data-object="<?php echo esc_attr( (string) $bn_row['id'] ); ?>"
					data-wp-on--click="actions.removeSpaceLink"
				><?php buddynext_icon( 'x-circle' ); ?> <?php esc_html_e( 'Remove link', 'buddynext' ); ?></button>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_links_after', $args );
