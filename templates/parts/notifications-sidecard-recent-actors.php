<?php
/**
 * BuddyNext template part: notifications-sidecard-recent-actors.
 *
 * Sidebar widget showing avatars (or initials fallback) for the most recent
 * actors who triggered a notification for the viewing user. Each tile links
 * to the actor's profile.
 *
 * Used by: templates/notifications/index.php (right sidebar).
 *
 * @package BuddyNext
 *
 * @var array $recent_actors Required. Map keyed by user id, each value
 *                           { 'display_name' => string, 'initials' => string, 'avatar_url' => string }.
 * @var array $classes       Optional. Extra CSS classes appended to `.bn-notif-sidecard`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_sidecard_recent_actors_before', $args )
 *   - do_action( 'buddynext_part_notifications_sidecard_recent_actors_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_sidecard_recent_actors_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_sidecard_recent_actors_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'recent_actors' => isset( $recent_actors ) ? (array) $recent_actors : array(),
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_sidecard_recent_actors_args', $args );

if ( empty( $args['recent_actors'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-notif-sidecard' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_sidecard_recent_actors_classes', $bn_classes, $args );
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

$bn_recent_actors = (array) $args['recent_actors'];

do_action( 'buddynext_part_notifications_sidecard_recent_actors_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" data-v2 aria-labelledby="bn-notif-side-recent">
	<header id="bn-notif-side-recent" class="bn-notif-sidecard__head"><?php esc_html_e( 'Recent actors', 'buddynext' ); ?></header>
	<div class="bn-notif-sidecard__actors">
		<?php foreach ( $bn_recent_actors as $actor_id => $actor ) : ?>
			<a href="<?php echo esc_url( PageRouter::profile_url( (int) $actor_id ) ); ?>"
				class="bn-notif-sidecard__actor"
				title="<?php echo esc_attr( $actor['display_name'] ); ?>">
				<?php if ( ! empty( $actor['avatar_url'] ) ) : ?>
					<img src="<?php echo esc_url( $actor['avatar_url'] ); ?>" alt="<?php echo esc_attr( $actor['display_name'] ); ?>" width="32" height="32" loading="lazy">
				<?php else : ?>
					<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $actor['initials'] ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php
do_action( 'buddynext_part_notifications_sidecard_recent_actors_after', $args );
