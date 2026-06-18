<?php
/**
 * BuddyNext template part: sidebar-greeting-streak.
 *
 * Personalized "Good morning, {name}" greeting card with the viewer's
 * current activity streak and a 7-day strip showing which days they were
 * active. Mirrors the v2 prototype right-sidebar opener in
 * `docs/v2 Plans/v2/home-feed.html`.
 *
 * Render shape:
 *
 *   ┌────────────────────────────────────────────────┐
 *   │ Good afternoon, Varun                          │
 *   │ You've been showing up. 5 days in a row —      │
 *   │ best streak this month.                        │
 *   │ [M] [T] [W] [T] [F] [S] [S]                    │
 *   └────────────────────────────────────────────────┘
 *
 * Returns silently when the viewer is anonymous (`user_id <= 0`) — the
 * card is personal so it never renders for guests.
 *
 * @package BuddyNext
 *
 * @var int   $user_id  Required. Viewer ID. Card hides when 0.
 * @var array $classes  Optional. Extra CSS classes appended to `.bn-sidebar-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_sidebar_greeting_streak_before', $args )
 *   - do_action( 'buddynext_part_sidebar_greeting_streak_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_sidebar_greeting_streak_args',    array $args )
 *   - apply_filters( 'buddynext_part_sidebar_greeting_streak_classes', array $classes, array $args )
 *   - apply_filters( 'buddynext_greeting_string', string $greeting, int $hour, WP_User $user )
 *
 * Gamification-bridge seams (wb-gamification or any equivalent plugin
 * can hook these to provide canonical streak / activity data instead of
 * BN's inline-computed fallback):
 *   - apply_filters( 'buddynext_user_active_dates',
 *                    array|null $dates, int $user_id, int $window_days )
 *     Return a list of `YYYY-MM-DD` date strings the user counted as
 *     "active" within the trailing window. Return `null` (default) to
 *     let BN's UNION query take over.
 *   - apply_filters( 'buddynext_user_activity_streak',
 *                    int $streak, int $user_id )
 *     Override the computed consecutive-trailing-days count.
 *   - apply_filters( 'buddynext_user_activity_best_month_streak',
 *                    int $best, int $user_id )
 *     Override the longest-run-in-current-month value.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'user_id' => isset( $user_id ) ? (int) $user_id : 0,
	'classes' => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_sidebar_greeting_streak_args', $args );

$bn_uid = (int) $args['user_id'];
if ( $bn_uid <= 0 ) {
	return;
}

$bn_user = get_userdata( $bn_uid );
if ( ! $bn_user instanceof WP_User ) {
	return;
}

// Greeting derived from local server time (the closest proxy we have to
// the viewer's wall clock without a per-user TZ field). Filterable so a
// Pro per-user-tz widget can replace the heuristic.
$bn_hour = (int) current_time( 'G' );
if ( $bn_hour < 5 ) {
	/* translators: %s: viewer's first name */
	$bn_greeting_tpl = __( 'Good night, %s', 'buddynext' );
} elseif ( $bn_hour < 12 ) {
	/* translators: %s: viewer's first name */
	$bn_greeting_tpl = __( 'Good morning, %s', 'buddynext' );
} elseif ( $bn_hour < 17 ) {
	/* translators: %s: viewer's first name */
	$bn_greeting_tpl = __( 'Good afternoon, %s', 'buddynext' );
} elseif ( $bn_hour < 21 ) {
	/* translators: %s: viewer's first name */
	$bn_greeting_tpl = __( 'Good evening, %s', 'buddynext' );
} else {
	/* translators: %s: viewer's first name */
	$bn_greeting_tpl = __( 'Good night, %s', 'buddynext' );
}

$bn_first_name = trim( (string) $bn_user->first_name );
if ( '' === $bn_first_name ) {
	// Fall back to the first word of display name (matches the v2 mock
	// using just "Varun", not the full "varundubey").
	$bn_first_name = (string) strtok( (string) $bn_user->display_name, ' ' );
}
if ( '' === $bn_first_name ) {
	$bn_first_name = $bn_user->user_login;
}

$bn_greeting = sprintf( $bn_greeting_tpl, $bn_first_name );

/**
 * Filter the greeting line.
 *
 * @param string  $greeting Pre-built greeting.
 * @param int     $hour     Local-server-time hour (0-23).
 * @param WP_User $user     Viewing user.
 */
$bn_greeting = (string) apply_filters( 'buddynext_greeting_string', $bn_greeting, $bn_hour, $bn_user );

// Streak summary (current streak, best-this-month, 7-day strip, active-date
// map) comes from Engagement\StreakService::summary() — the 30-day UNION and
// the consecutive-day math live there, cached per-user, and it keeps the
// buddynext_user_active_dates / *_activity_streak / *_best_month_streak
// gamification filters so wb-gamification (or any plugin) can supply the
// canonical values. This template stays SQL-free.
$bn_streak_summary = ( new \BuddyNext\Engagement\StreakService() )->summary( $bn_uid );
$bn_streak         = (int) $bn_streak_summary['streak'];
$bn_best           = (int) $bn_streak_summary['best'];
$bn_strip          = (array) $bn_streak_summary['strip'];

$bn_classes = array_merge( array( 'bn-card', 'bn-sidebar-card', 'bn-greeting-streak' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_sidebar_greeting_streak_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_sidebar_greeting_streak_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Your activity', 'buddynext' ); ?>">
	<h3 class="bn-greeting-streak__heading"><?php echo esc_html( $bn_greeting ); ?></h3>

	<?php if ( $bn_streak > 0 ) : ?>
		<p class="bn-greeting-streak__body">
			<?php
			$bn_streak_label = sprintf(
				/* translators: %d: number of consecutive active days */
				_n( '%d day in a row', '%d days in a row', $bn_streak, 'buddynext' ),
				$bn_streak
			);
			if ( $bn_streak >= $bn_best && $bn_streak >= 2 ) {
				echo wp_kses(
					sprintf(
						/* translators: %s: e.g. "5 days in a row" — already-localized via _n() above */
						__( "You've been showing up. <strong>%s</strong> — best streak this month.", 'buddynext' ),
						$bn_streak_label
					),
					array( 'strong' => array() )
				);
			} else {
				echo wp_kses(
					sprintf(
						/* translators: %s: e.g. "3 days in a row" */
						__( 'On a roll — <strong>%s</strong>. Keep it going.', 'buddynext' ),
						$bn_streak_label
					),
					array( 'strong' => array() )
				);
			}
			?>
		</p>
	<?php else : ?>
		<p class="bn-greeting-streak__body">
			<?php esc_html_e( 'Post or comment today to start a streak.', 'buddynext' ); ?>
		</p>
	<?php endif; ?>

	<ol class="bn-greeting-streak__strip" aria-label="<?php esc_attr_e( 'Last 7 days', 'buddynext' ); ?>">
		<?php
		foreach ( $bn_strip as $bn_cell ) :
			$bn_cell_classes = array( 'bn-greeting-streak__cell' );
			if ( $bn_cell['active'] ) {
				$bn_cell_classes[] = 'is-active';
			}
			if ( $bn_cell['is_today'] ) {
				$bn_cell_classes[] = 'is-today';
			}
			$bn_cell_aria = sprintf(
				/* translators: 1: date, 2: active/inactive */
				_x( '%1$s — %2$s', 'streak cell aria label', 'buddynext' ),
				date_i18n( get_option( 'date_format' ), strtotime( $bn_cell['date'] ) ),
				$bn_cell['active']
					? __( 'active', 'buddynext' )
					: __( 'no activity', 'buddynext' )
			);
			?>
			<li
				class="<?php echo esc_attr( implode( ' ', $bn_cell_classes ) ); ?>"
				aria-label="<?php echo esc_attr( $bn_cell_aria ); ?>"
				title="<?php echo esc_attr( $bn_cell_aria ); ?>"
			>
				<?php echo esc_html( mb_strtoupper( (string) $bn_cell['letter'] ) ); ?>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
<?php
do_action( 'buddynext_part_sidebar_greeting_streak_after', $args );
