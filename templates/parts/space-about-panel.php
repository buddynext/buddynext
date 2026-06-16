<?php
/**
 * BuddyNext template part: space-about-panel.
 *
 * Renders the About tab body of the space-home template: long description,
 * house-rules ordered list (one rule per non-empty line of `space->rules`),
 * category chip, and the `<dl>` metadata block (visibility / created /
 * members).
 *
 * Used by: templates/spaces/home.php.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space            Required. Space row (description, rules,
 *                               category_name, category_slug, created_at).
 * @var string $description_html Optional. Pre-rendered description HTML. When
 *                               empty the raw `space->description` is wrapped
 *                               in `wpautop()`.
 * @var array  $meta             Optional. Caller-supplied meta-row values:
 *                               `[ 'privacy_label' => string,
 *                                  'privacy_tone'  => string,
 *                                  'member_count_fmt' => string ]`.
 * @var array  $classes          Optional. Extra CSS classes appended to `.bn-sh-about`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_about_panel_before', $args )
 *   - do_action( 'buddynext_part_space_about_panel_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_about_panel_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_about_panel_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'space'            => isset( $space ) ? $space : null,
	'description_html' => isset( $description_html ) ? (string) $description_html : '',
	'meta'             => isset( $meta ) && is_array( $meta ) ? $meta : array(),
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_about_panel_args', $args );

if ( null === $args['space'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-sh-about' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_about_panel_classes', $bn_classes, $args );
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

$bn_space     = $args['space'];
$bn_desc_html = (string) $args['description_html'];
$bn_meta      = (array) $args['meta'];

$bn_privacy_label = isset( $bn_meta['privacy_label'] ) ? (string) $bn_meta['privacy_label'] : '';
$bn_privacy_tone  = isset( $bn_meta['privacy_tone'] ) ? (string) $bn_meta['privacy_tone'] : 'info';
$bn_count_fmt     = isset( $bn_meta['member_count_fmt'] ) ? (string) $bn_meta['member_count_fmt'] : '';

$bn_rules_raw = isset( $bn_space->rules ) ? (string) $bn_space->rules : '';
$bn_rules     = array();
if ( '' !== trim( $bn_rules_raw ) ) {
	foreach ( preg_split( "/\r\n|\n|\r/", $bn_rules_raw ) as $bn_rule_line ) {
		$bn_rule_line = trim( $bn_rule_line );
		if ( '' !== $bn_rule_line ) {
			$bn_rules[] = $bn_rule_line;
		}
	}
}

do_action( 'buddynext_part_space_about_panel_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<h2 class="bn-sh-about__title"><?php esc_html_e( 'About', 'buddynext' ); ?></h2>
	<?php if ( '' !== $bn_desc_html ) : ?>
		<div class="bn-sh-about__desc"><?php echo wp_kses_post( $bn_desc_html ); ?></div>
	<?php elseif ( ! empty( $bn_space->description ) ) : ?>
		<div class="bn-sh-about__desc"><?php echo wp_kses_post( wpautop( $bn_space->description ) ); ?></div>
	<?php else : ?>
		<p class="bn-sh-about__desc"><?php esc_html_e( 'No description yet.', 'buddynext' ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $bn_rules ) ) : ?>
		<section class="bn-sh-about__rules">
			<h3 class="bn-sh-about__section-title"><?php esc_html_e( 'House rules', 'buddynext' ); ?></h3>
			<ol class="bn-sh-about__rules-list">
				<?php foreach ( $bn_rules as $bn_rule ) : ?>
					<li><?php echo esc_html( $bn_rule ); ?></li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $bn_space->category_name ) && ! empty( $bn_space->category_slug ) ) : ?>
		<section class="bn-sh-about__categories">
			<h3 class="bn-sh-about__section-title"><?php esc_html_e( 'Category', 'buddynext' ); ?></h3>
			<div class="bn-sh-about__cat-chips">
				<a
					href="<?php echo esc_url( add_query_arg( 'bn_cat', $bn_space->category_slug, PageRouter::spaces_url() ) ); ?>"
					class="bn-tab bn-sd-chip"
				>
					<span class="bn-sd-chip__icon" aria-hidden="true"><?php echo wp_kses_data( bn_space_category_icon( $bn_space->category_slug ) ); ?></span>
					<?php echo esc_html( $bn_space->category_name ); ?>
				</a>
			</div>
		</section>
	<?php endif; ?>

	<dl class="bn-sh-about__meta">
		<div>
			<dt><?php esc_html_e( 'Visibility', 'buddynext' ); ?></dt>
			<dd><span class="bn-badge" data-tone="<?php echo esc_attr( $bn_privacy_tone ); ?>"><?php echo esc_html( $bn_privacy_label ); ?></span></dd>
		</div>
		<?php if ( ! empty( $bn_space->created_at ) ) : ?>
			<div>
				<dt><?php esc_html_e( 'Created', 'buddynext' ); ?></dt>
				<dd><?php echo buddynext_date_local( (string) $bn_space->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_date_local() returns esc_html()'d output. ?></dd>
			</div>
		<?php endif; ?>
		<div>
			<dt><?php esc_html_e( 'Members', 'buddynext' ); ?></dt>
			<dd><?php echo esc_html( $bn_count_fmt ); ?></dd>
		</div>
	</dl>
</div>
<?php
do_action( 'buddynext_part_space_about_panel_after', $args );
