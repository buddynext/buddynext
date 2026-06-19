<?php
/**
 * BuddyNext template part: profile-stats-strip.
 *
 * Renders the 4-cell stat band that sits at the bottom of the profile
 * hero card (posts / followers / following / connections) plus any extra
 * tiles injected by bridge plugins through `buddynext_profile_extra_data`.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int   $profile_user_id  Required. ID of the profile being viewed.
 * @var bool  $is_owner         Optional. Whether viewer owns this profile.
 * @var array $stats            Required. Stat descriptors. Each entry is
 *                              `[ 'slug', 'label', 'value' (int|string),
 *                                 'href' (string), 'icon' (string), 'tone',
 *                                 'delta' (string?, e.g. '+12'),
 *                                 'trend' (string? 'up'|'down'|'flat') ]`.
 *                              The composer supplies the 4 canonical rows
 *                              (`posts`, `followers`, `following`,
 *                              `connections`); bridge plugins can append
 *                              additional descriptors via
 *                              `buddynext_part_profile_stats_strip_args`.
 *                              The optional `delta` renders inside a
 *                              `<span class="bn-pf-stat__delta" data-trend>`
 *                              chip — v2 prototype week-over-week pattern.
 * @var array $classes          Optional. Extra CSS classes appended to `.bn-pf-stats`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_stats_strip_before', $args )
 *   - do_action( 'buddynext_part_profile_stats_strip_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_stats_strip_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_stats_strip_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'profile_user_id' => isset( $profile_user_id ) ? (int) $profile_user_id : 0,
	'is_owner'        => isset( $is_owner ) ? (bool) $is_owner : false,
	'stats'           => isset( $stats ) && is_array( $stats ) ? $stats : array(),
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_stats_strip_args', $args );

if ( empty( $args['stats'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-pf-stats' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_stats_strip_classes', $bn_classes, $args );
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

$bn_pf_uid = (int) $args['profile_user_id'];

do_action( 'buddynext_part_profile_stats_strip_before', $args );
?>
		<!-- Stats: one pill row. Core social pills lead (emphasized); optional
			feature pills (gamification, forum, any plugin) follow, muted. -->
		<div class="bn-pf-pills" role="list">
			<?php
			foreach ( (array) $args['stats'] as $bn_stat ) :
				if ( ! is_array( $bn_stat ) || '' === ( $bn_stat['label'] ?? '' ) ) {
					continue;
				}
				$bn_label    = (string) $bn_stat['label'];
				$bn_value    = isset( $bn_stat['value'] ) ? (string) $bn_stat['value'] : '';
				$bn_href     = isset( $bn_stat['href'] ) ? (string) $bn_stat['href'] : '';
				$bn_aria     = isset( $bn_stat['aria_label'] ) ? (string) $bn_stat['aria_label'] : '';
				$bn_wp_text  = isset( $bn_stat['wp_text'] ) ? (string) $bn_stat['wp_text'] : '';
				$bn_wp_on    = isset( $bn_stat['wp_on_click'] ) ? (string) $bn_stat['wp_on_click'] : '';
				$bn_data_tab = isset( $bn_stat['data_tab'] ) ? (string) $bn_stat['data_tab'] : '';
				$bn_delta    = isset( $bn_stat['delta'] ) ? trim( (string) $bn_stat['delta'] ) : '';
				$bn_trend    = isset( $bn_stat['trend'] ) && in_array( (string) $bn_stat['trend'], array( 'up', 'down', 'flat' ), true ) ? (string) $bn_stat['trend'] : 'flat';

				$bn_delta_html = '' !== $bn_delta
					? sprintf( '<span class="bn-pf-pill__delta" data-trend="%1$s">%2$s</span>', esc_attr( $bn_trend ), esc_html( $bn_delta ) )
					: '';
				$bn_val_attr   = '' !== $bn_wp_text ? ' data-wp-text="' . esc_attr( $bn_wp_text ) . '"' : '';

				if ( '' !== $bn_wp_on && '' !== $bn_href ) :
					// Stat maps to a tab AND has a standalone URL (followers/following/
					// connections). Render a real link carrying the tab handler: a plain
					// click switches the in-page tab (setTab preventDefaults), while a
					// modifier / middle click falls through to the href so the standalone
					// list opens in a new tab — the Twitter/LinkedIn stat affordance.
					?>
					<a class="bn-pf-pill bn-pf-pill--primary" href="<?php echo esc_url( $bn_href ); ?>" data-wp-on--click="<?php echo esc_attr( $bn_wp_on ); ?>"<?php echo '' !== $bn_data_tab ? ' data-tab="' . esc_attr( $bn_data_tab ) . '"' : ''; ?><?php echo '' !== $bn_aria ? ' aria-label="' . esc_attr( $bn_aria ) . '"' : ''; ?>>
						<span class="bn-pf-pill__value"<?php echo $bn_val_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attr pre-escaped. ?>><?php echo esc_html( $bn_value ); ?></span>
						<span class="bn-pf-pill__label"><?php echo esc_html( $bn_label ); ?></span>
						<?php echo $bn_delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
					</a>
				<?php elseif ( '' !== $bn_wp_on ) : ?>
					<button type="button" class="bn-pf-pill bn-pf-pill--primary" data-wp-on--click="<?php echo esc_attr( $bn_wp_on ); ?>"<?php echo '' !== $bn_data_tab ? ' data-tab="' . esc_attr( $bn_data_tab ) . '"' : ''; ?><?php echo '' !== $bn_aria ? ' aria-label="' . esc_attr( $bn_aria ) . '"' : ''; ?>>
						<span class="bn-pf-pill__value"><?php echo esc_html( $bn_value ); ?></span>
						<span class="bn-pf-pill__label"><?php echo esc_html( $bn_label ); ?></span>
						<?php echo $bn_delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
					</button>
				<?php elseif ( '' !== $bn_href ) : ?>
					<a class="bn-pf-pill bn-pf-pill--primary" href="<?php echo esc_url( $bn_href ); ?>">
						<span class="bn-pf-pill__value"<?php echo $bn_val_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attr pre-escaped. ?>><?php echo esc_html( $bn_value ); ?></span>
						<span class="bn-pf-pill__label"><?php echo esc_html( $bn_label ); ?></span>
						<?php echo $bn_delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
					</a>
				<?php else : ?>
					<span class="bn-pf-pill bn-pf-pill--primary" role="listitem">
						<span class="bn-pf-pill__value"><?php echo esc_html( $bn_value ); ?></span>
						<span class="bn-pf-pill__label"><?php echo esc_html( $bn_label ); ?></span>
						<?php echo $bn_delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
					</span>
					<?php
				endif;
			endforeach;

			/**
			 * Optional, feature-specific stat pills (gamification points/level/
			 * badges, forum discussions, or any plugin). The single extension hook
			 * for adding a profile stat pill. Each entry is
			 * ['label' => string, 'value' => string|int]. Rendered muted so the
			 * core social pills lead; absent entirely when nothing is contributed.
			 *
			 * @param array[] $extra   Array of ['label' => string, 'value' => string|int].
			 * @param int     $user_id ID of the profile being viewed.
			 */
			$bn_extra_stats = array_values(
				array_filter(
					(array) apply_filters( 'buddynext_profile_extra_data', array(), $bn_pf_uid ),
					static function ( $stat ): bool {
						return is_array( $stat ) && ! empty( $stat['label'] ) && isset( $stat['value'] ) && '' !== (string) $stat['value'];
					}
				)
			);
			foreach ( $bn_extra_stats as $bn_extra_stat ) :
				$bn_xs_value = (string) $bn_extra_stat['value'];
				$bn_xs_label = (string) $bn_extra_stat['label'];
				// A feature stat that maps to a profile tab carries `wp_on_click` +
				// `data_tab` to jump to that tab on click. Feature stats with no
				// matching tab stay static count text. A stat that is a first-class
				// content count (e.g. Discussions, which already owns a tab in the
				// bar) can opt into the emphasized `--primary` treatment so it reads
				// as a count alongside Posts/Followers rather than a muted control;
				// genuinely-secondary metrics (gamification Points / Level) stay muted.
				$bn_xs_on   = isset( $bn_extra_stat['wp_on_click'] ) ? (string) $bn_extra_stat['wp_on_click'] : '';
				$bn_xs_tab  = isset( $bn_extra_stat['data_tab'] ) ? (string) $bn_extra_stat['data_tab'] : '';
				$bn_xs_tone = ! empty( $bn_extra_stat['primary'] ) ? 'bn-pf-pill--primary' : 'bn-pf-pill--muted';
				if ( '' !== $bn_xs_on ) :
					?>
					<button type="button" class="bn-pf-pill <?php echo esc_attr( $bn_xs_tone ); ?>" data-wp-on--click="<?php echo esc_attr( $bn_xs_on ); ?>"<?php echo '' !== $bn_xs_tab ? ' data-tab="' . esc_attr( $bn_xs_tab ) . '"' : ''; ?>>
						<span class="bn-pf-pill__value"><?php echo esc_html( $bn_xs_value ); ?></span>
						<span class="bn-pf-pill__label"><?php echo esc_html( $bn_xs_label ); ?></span>
					</button>
				<?php else : ?>
					<span class="bn-pf-pill <?php echo esc_attr( $bn_xs_tone ); ?>" role="listitem">
						<span class="bn-pf-pill__value"><?php echo esc_html( $bn_xs_value ); ?></span>
						<span class="bn-pf-pill__label"><?php echo esc_html( $bn_xs_label ); ?></span>
					</span>
					<?php
				endif;
			endforeach;
			?>
		</div>
<?php
do_action( 'buddynext_part_profile_stats_strip_after', $args );
