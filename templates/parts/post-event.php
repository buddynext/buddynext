<?php
/**
 * BuddyNext template part: post-event.
 *
 * Renders the body of an `event` activity card — a date pill (month / day)
 * beside the event title, a date · time meta line, and an optional location.
 * The structured fields live in the post's `link_meta` JSON column populated
 * by the composer event tool: `{ title, location, event_at }` (see
 * `assets/js/feed/store.js` submitEvent()). Mirrors the v2 prototype event
 * rows in `docs/v2 Plans/v2/home-feed.html` and the parsing already proven by
 * `templates/parts/sidebar-upcoming-events.php`.
 *
 * Without this branch events fall through post-body's default text handler, so
 * the date / time / location the author entered never appear in the feed.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int    $bn_post_id   Post ID.
 * @var array  $link_meta    Decoded event metadata { title, location, event_at }.
 * @var string $post_content Raw post content ("Title\n\nDescription" from the composer).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_ev_post_id = isset( $bn_post_id ) ? absint( $bn_post_id ) : 0;
$bn_ev_meta    = isset( $link_meta ) && is_array( $link_meta ) ? $link_meta : array();
$bn_ev_content = isset( $post_content ) ? (string) $post_content : '';

$bn_ev_title    = isset( $bn_ev_meta['title'] ) ? trim( (string) $bn_ev_meta['title'] ) : '';
$bn_ev_location = isset( $bn_ev_meta['location'] ) ? trim( (string) $bn_ev_meta['location'] ) : '';
$bn_ev_event_at = isset( $bn_ev_meta['event_at'] ) ? trim( (string) $bn_ev_meta['event_at'] ) : '';

// The composer stores content as "Title\n\nDescription"; recover just the
// description so the title is not repeated under the structured card.
$bn_ev_description = '';
$bn_ev_split       = explode( "\n\n", $bn_ev_content, 2 );
if ( isset( $bn_ev_split[1] ) ) {
	$bn_ev_description = trim( $bn_ev_split[1] );
} elseif ( '' !== $bn_ev_content && $bn_ev_content !== $bn_ev_title ) {
	$bn_ev_description = $bn_ev_content;
}

// Fall back to a trimmed content snippet when the title was not captured.
if ( '' === $bn_ev_title ) {
	$bn_ev_title = wp_trim_words( wp_strip_all_tags( $bn_ev_content ), 10 );
}

// Resolve the date pill + meta line from event_at. Treated as site wall-clock
// (that is what the composer writes), matching sidebar-upcoming-events.php.
$bn_ev_ts = '' !== $bn_ev_event_at ? (int) strtotime( $bn_ev_event_at ) : 0;

$bn_ev_meta_bits = array();
if ( $bn_ev_ts > 0 ) {
	$bn_ev_today_ymd    = wp_date( 'Y-m-d' );
	$bn_ev_tomorrow_ymd = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
	$bn_ev_ymd          = wp_date( 'Y-m-d', $bn_ev_ts );

	if ( $bn_ev_ymd === $bn_ev_today_ymd ) {
		$bn_ev_relative = __( 'Today', 'buddynext' );
	} elseif ( $bn_ev_ymd === $bn_ev_tomorrow_ymd ) {
		$bn_ev_relative = __( 'Tomorrow', 'buddynext' );
	} else {
		$bn_ev_relative = wp_date( 'l, M j', $bn_ev_ts );
	}

	$bn_ev_meta_bits[] = $bn_ev_relative;
	$bn_ev_meta_bits[] = wp_date( (string) get_option( 'time_format', 'g:i a' ), $bn_ev_ts );

	$bn_ev_pill_month = mb_strtoupper( wp_date( 'M', $bn_ev_ts ) );
	$bn_ev_pill_day   = wp_date( 'j', $bn_ev_ts );
} else {
	$bn_ev_pill_month = '';
	$bn_ev_pill_day   = '';
}
?>
<?php if ( '' !== $bn_ev_description ) : ?>
	<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_ev_description ) ) ); ?></div>
<?php endif; ?>
<div class="bn-post-card__event">
	<?php if ( '' !== $bn_ev_pill_day ) : ?>
		<div class="bn-post-card__event-date" aria-hidden="true">
			<span class="bn-post-card__event-month"><?php echo esc_html( $bn_ev_pill_month ); ?></span>
			<span class="bn-post-card__event-day"><?php echo esc_html( $bn_ev_pill_day ); ?></span>
		</div>
	<?php else : ?>
		<div class="bn-post-card__event-date bn-post-card__event-date--tbd" aria-hidden="true">
			<span class="bn-post-card__event-icon"><?php buddynext_icon( 'calendar' ); ?></span>
		</div>
	<?php endif; ?>
	<div class="bn-post-card__event-info">
		<p class="bn-post-card__event-title"><?php echo esc_html( $bn_ev_title ); ?></p>
		<?php if ( ! empty( $bn_ev_meta_bits ) ) : ?>
			<p class="bn-post-card__event-meta">
				<span class="bn-post-card__event-meta-icon" aria-hidden="true"><?php buddynext_icon( 'clock' ); ?></span>
				<?php echo esc_html( implode( ' · ', $bn_ev_meta_bits ) ); ?>
			</p>
		<?php endif; ?>
		<?php if ( '' !== $bn_ev_location ) : ?>
			<p class="bn-post-card__event-meta">
				<span class="bn-post-card__event-meta-icon" aria-hidden="true"><?php buddynext_icon( 'map-pin' ); ?></span>
				<?php echo esc_html( $bn_ev_location ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
