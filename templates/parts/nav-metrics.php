<?php
/**
 * BuddyNext template part: nav-metrics — the shared stat / metric row.
 *
 * Renders the count-pill row from resolved Nav metric items (NavItem[]). Used by
 * BOTH the member profile and the space surface so the stat row is consistent: a
 * metric is display-only by default; it may deep-link (item->tab jumps to a tab/
 * sub-tab, item->url opens a list) but the whole row reads as counts, never a
 * second navigation. Optional week-over-week delta/trend chip.
 *
 * @package BuddyNext\Nav
 *
 * @var \BuddyNext\Nav\NavItem[] $items       Required. Metric items.
 * @var string                   $extra_class Optional. Extra class on the wrapper.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Nav\NavItem;

$bn_metrics = isset( $items ) && is_array( $items ) ? $items : array();
if ( empty( $bn_metrics ) ) {
	return;
}
$bn_metrics_extra = isset( $extra_class ) ? trim( (string) $extra_class ) : '';
?>
<div class="bn-nav-metrics<?php echo '' !== $bn_metrics_extra ? ' ' . esc_attr( $bn_metrics_extra ) : ''; ?>">
	<?php
	foreach ( $bn_metrics as $bn_m ) :
		if ( ! ( $bn_m instanceof NavItem ) ) {
			continue;
		}
		$bn_m_value = null !== $bn_m->count_value ? (string) $bn_m->count_value : '';
		$bn_m_trend = in_array( (string) $bn_m->trend, array( 'up', 'down', 'flat' ), true ) ? (string) $bn_m->trend : 'flat';
		$bn_m_delta = null !== $bn_m->delta && '' !== $bn_m->delta
			? sprintf( '<span class="bn-nav-metric__delta" data-trend="%1$s">%2$s</span>', esc_attr( $bn_m_trend ), esc_html( $bn_m->delta ) )
			: '';
		$bn_m_aria  = '' !== $bn_m_value ? sprintf( '%s %s', $bn_m_value, $bn_m->label ) : $bn_m->label;
		?>
		<?php if ( null !== $bn_m->tab ) : ?>
			<button class="bn-nav-metric" type="button" data-wp-on--click="actions.setTab" data-tab="<?php echo esc_attr( $bn_m->tab ); ?>" aria-label="<?php echo esc_attr( $bn_m_aria ); ?>">
				<span class="bn-nav-metric__value"><?php echo esc_html( $bn_m_value ); ?></span>
				<span class="bn-nav-metric__label"><?php echo esc_html( $bn_m->label ); ?></span>
				<?php echo $bn_m_delta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>
			</button>
		<?php elseif ( null !== $bn_m->url ) : ?>
			<a class="bn-nav-metric" href="<?php echo esc_url( $bn_m->url ); ?>" aria-label="<?php echo esc_attr( $bn_m_aria ); ?>">
				<span class="bn-nav-metric__value"><?php echo esc_html( $bn_m_value ); ?></span>
				<span class="bn-nav-metric__label"><?php echo esc_html( $bn_m->label ); ?></span>
				<?php echo $bn_m_delta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>
			</a>
		<?php else : ?>
			<span class="bn-nav-metric">
				<span class="bn-nav-metric__value"><?php echo esc_html( $bn_m_value ); ?></span>
				<span class="bn-nav-metric__label"><?php echo esc_html( $bn_m->label ); ?></span>
				<?php echo $bn_m_delta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>
			</span>
		<?php endif; ?>
	<?php endforeach; ?>
</div>
