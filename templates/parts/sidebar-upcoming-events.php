<?php
/**
 * BuddyNext template part: sidebar-upcoming-events.
 *
 * "This week" right-sidebar card. Lists upcoming events scheduled within
 * the next 7 days, sorted by start time ascending. Each row shows a
 * date pill (e.g. `MAY 02`) and a meta line (`Today · 4pm · Voice room`).
 *
 * Source: `bn_posts` rows with `type='event'`, `status='published'`, and
 * `link_meta.event_at` in the next 7 days. Event metadata is stored in
 * the `link_meta` JSON column populated by the composer-event-modal:
 *   `{ title, location, event_at }`.
 *
 * Mirrors the v2 prototype right-sidebar "This week" card in
 * `docs/v2 Plans/v2/home-feed.html`.
 *
 * @package BuddyNext
 *
 * @var int   $user_id Optional. Viewer ID (0 for anonymous — widget still
 *                     renders for guests since events are public). Default 0.
 * @var int   $limit   Optional. Max rows to show. Default 5.
 * @var array $classes Optional. Extra CSS classes appended to `.bn-sidebar-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_sidebar_upcoming_events_before', $args )
 *   - do_action( 'buddynext_part_sidebar_upcoming_events_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_sidebar_upcoming_events_args',    array $args )
 *   - apply_filters( 'buddynext_part_sidebar_upcoming_events_classes', array $classes, array $args )
 *   - apply_filters( 'buddynext_upcoming_events_query_window_days', int $days )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'user_id' => isset( $user_id ) ? (int) $user_id : 0,
	'limit'   => isset( $limit ) ? max( 1, (int) $limit ) : 5,
	'classes' => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_sidebar_upcoming_events_args', $args );

$bn_uid    = (int) $args['user_id'];
$bn_limit  = (int) $args['limit'];
$bn_window = (int) apply_filters( 'buddynext_upcoming_events_query_window_days', 7 );

// Pull candidate event posts from the trailing 90 days. We then filter
// in PHP by parsing `link_meta.event_at` because JSON_EXTRACT semantics
// vary across MySQL / MariaDB versions; the post-creation window keeps
// the candidate set small (events scheduled more than 90 days ago that
// somehow still match in-future are rare).
global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// No bound parameters — the query is fully constant, so prepare() would
// be a no-op and trip the "no placeholders" warning. Table prefix is
// validated by $wpdb itself; no user input touches this string.
$bn_event_candidates = $wpdb->get_results(
	"SELECT id, user_id, content, link_meta, created_at
	   FROM {$wpdb->prefix}bn_posts
	  WHERE type = 'event'
	    AND status = 'published'
	    AND privacy = 'public'
	    AND created_at >= DATE_SUB( NOW(), INTERVAL 90 DAY )
	  ORDER BY created_at DESC
	  LIMIT 50",
	ARRAY_A
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// `time()` returns UTC; we treat the stored event_at as wall-clock
// site-time (that's what the composer writes), so comparisons go
// through strtotime() which the WordPress runtime resolves against the
// site timezone. Within an hour-resolution "next 7 days" window the
// possible TZ offset is irrelevant.
$bn_now_ts    = time();
$bn_cutoff_ts = $bn_now_ts + ( $bn_window * DAY_IN_SECONDS );
$bn_events    = array();

foreach ( (array) $bn_event_candidates as $bn_row ) {
	$bn_meta = json_decode( (string) ( $bn_row['link_meta'] ?? '' ), true );
	if ( ! is_array( $bn_meta ) ) {
		continue;
	}
	$bn_event_at = isset( $bn_meta['event_at'] ) ? (string) $bn_meta['event_at'] : '';
	if ( '' === $bn_event_at ) {
		continue;
	}
	$bn_event_ts = (int) strtotime( $bn_event_at );
	if ( $bn_event_ts <= 0 || $bn_event_ts < $bn_now_ts || $bn_event_ts > $bn_cutoff_ts ) {
		continue;
	}

	$bn_events[] = array(
		'id'       => (int) $bn_row['id'],
		'title'    => isset( $bn_meta['title'] ) && '' !== (string) $bn_meta['title']
			? (string) $bn_meta['title']
			: wp_trim_words( (string) $bn_row['content'], 8 ),
		'location' => isset( $bn_meta['location'] ) ? (string) $bn_meta['location'] : '',
		'event_ts' => $bn_event_ts,
	);
}

// Sort ascending by event time so the soonest is at the top.
usort(
	$bn_events,
	static function ( array $a, array $b ): int {
		return $a['event_ts'] <=> $b['event_ts'];
	}
);

$bn_events = array_slice( $bn_events, 0, $bn_limit );

$bn_classes = array_merge( array( 'bn-card', 'bn-sidebar-card', 'bn-upcoming-events' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_sidebar_upcoming_events_classes', $bn_classes, $args );
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

$bn_compose_url = home_url( '/activity/?bn_action=event' );

do_action( 'buddynext_part_sidebar_upcoming_events_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Upcoming events', 'buddynext' ); ?>">
	<header class="bn-upcoming-events__head">
		<h3 class="bn-upcoming-events__title"><?php esc_html_e( 'This week', 'buddynext' ); ?></h3>
		<?php if ( $bn_uid > 0 ) : ?>
			<a class="bn-upcoming-events__cta" href="<?php echo esc_url( $bn_compose_url ); ?>">
				<?php esc_html_e( 'Schedule', 'buddynext' ); ?>
			</a>
		<?php endif; ?>
	</header>

	<?php if ( empty( $bn_events ) ) : ?>
		<p class="bn-upcoming-events__empty">
			<?php esc_html_e( 'No events scheduled this week.', 'buddynext' ); ?>
		</p>
	<?php else : ?>
		<ol class="bn-upcoming-events__list">
			<?php
			$bn_today_ymd    = wp_date( 'Y-m-d', $bn_now_ts );
			$bn_tomorrow_ymd = wp_date( 'Y-m-d', $bn_now_ts + DAY_IN_SECONDS );
			$bn_time_format  = (string) get_option( 'time_format', 'g:i a' );

			foreach ( $bn_events as $bn_ev ) :
				$bn_ymd        = wp_date( 'Y-m-d', $bn_ev['event_ts'] );
				$bn_pill_month = mb_strtoupper( wp_date( 'M', $bn_ev['event_ts'] ) );
				$bn_pill_day   = wp_date( 'j', $bn_ev['event_ts'] );

				if ( $bn_ymd === $bn_today_ymd ) {
					$bn_relative_day = esc_html__( 'Today', 'buddynext' );
				} elseif ( $bn_ymd === $bn_tomorrow_ymd ) {
					$bn_relative_day = esc_html__( 'Tomorrow', 'buddynext' );
				} else {
					$bn_relative_day = esc_html( wp_date( 'l', $bn_ev['event_ts'] ) );
				}

				$bn_time = wp_date( $bn_time_format, $bn_ev['event_ts'] );

				$bn_meta_bits = array( $bn_relative_day, $bn_time );
				if ( '' !== $bn_ev['location'] ) {
					$bn_meta_bits[] = $bn_ev['location'];
				}
				?>
				<li class="bn-upcoming-events__item">
					<div class="bn-upcoming-events__pill" aria-hidden="true">
						<span class="bn-upcoming-events__pill-month"><?php echo esc_html( $bn_pill_month ); ?></span>
						<span class="bn-upcoming-events__pill-day"><?php echo esc_html( $bn_pill_day ); ?></span>
					</div>
					<div class="bn-upcoming-events__body">
						<a class="bn-upcoming-events__name"
							href="<?php echo esc_url( \BuddyNext\Core\PageRouter::post_url( $bn_ev['id'] ) ); ?>"
						>
							<?php echo esc_html( $bn_ev['title'] ); ?>
						</a>
						<span class="bn-upcoming-events__meta">
							<?php echo esc_html( implode( ' · ', $bn_meta_bits ) ); ?>
						</span>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</section>
<?php
do_action( 'buddynext_part_sidebar_upcoming_events_after', $args );
