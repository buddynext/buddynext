<?php
/**
 * BuddyNext template part: notifications-sidecard-muted.
 *
 * Sidebar widget listing the people the viewing user has muted. Each row
 * shows avatar + display name + handle and a small "Unmute" button that
 * DELETEs /users/{id}/mute via the same data-bn-relation-remove handler
 * used on the Privacy section of profile-edit. A "Manage muted" link at
 * the foot deep-links to the full management list.
 *
 * Renders nothing when the viewer has muted no one — keeps the sidebar
 * clean for the vast majority of users who never use mute.
 *
 * Used by: templates/notifications/index.php (right sidebar).
 *
 * @package BuddyNext
 *
 * @var int   $user_id  Required. Viewer ID. Widget returns silently when 0.
 * @var array $classes  Optional. Extra CSS classes appended to `.bn-notif-sidecard`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_sidecard_muted_before', $args )
 *   - do_action( 'buddynext_part_notifications_sidecard_muted_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_sidecard_muted_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_sidecard_muted_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'user_id' => isset( $user_id ) ? (int) $user_id : 0,
	'classes' => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_sidecard_muted_args', $args );

if ( $args['user_id'] <= 0 || ! function_exists( 'buddynext_service' ) ) {
	return;
}

$bn_muted_ids = (array) buddynext_service( 'blocks' )->muted_users( $args['user_id'] );
if ( empty( $bn_muted_ids ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-notif-sidecard', 'bn-notif-sidecard--muted' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_sidecard_muted_classes', $bn_classes, $args );
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

$bn_manage_url = PageRouter::edit_profile_url() . '#bn-ep-privacy-title';
$bn_rest_nonce = wp_create_nonce( 'wp_rest' );

do_action( 'buddynext_part_notifications_sidecard_muted_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" data-v2 aria-labelledby="bn-notif-side-muted">
	<header id="bn-notif-side-muted" class="bn-notif-sidecard__head">
		<?php echo esc_html( sprintf( /* translators: %d: muted count */ _n( 'Muted (%d)', 'Muted (%d)', count( $bn_muted_ids ), 'buddynext' ), count( $bn_muted_ids ) ) ); ?>
	</header>

	<ul class="bn-notif-sidecard__muted-list" role="list">
		<?php
		// Cap the visible rows so a power-muter doesn't make the sidebar
		// scroll forever; the Manage link below covers the rest.
		$bn_visible = array_slice( $bn_muted_ids, 0, 5 );
		foreach ( $bn_visible as $bn_muted_id ) :
			$bn_muted_user = get_userdata( (int) $bn_muted_id );
			if ( ! $bn_muted_user ) {
				continue;
			}
			?>
			<li class="bn-notif-sidecard__muted-row"
				data-user-id="<?php echo absint( (int) $bn_muted_id ); ?>"
				data-relation="mute"
				data-bn-nonce="<?php echo esc_attr( $bn_rest_nonce ); ?>"
			>
				<a class="bn-notif-sidecard__muted-avatar"
					href="<?php echo esc_url( PageRouter::profile_url( (int) $bn_muted_id ) ); ?>"
					aria-hidden="true"
					tabindex="-1">
					<img src="<?php echo esc_url( get_avatar_url( (int) $bn_muted_id, array( 'size' => 32 ) ) ); ?>"
						alt=""
						width="32"
						height="32"
						loading="lazy">
				</a>
				<div class="bn-notif-sidecard__muted-id">
					<a class="bn-notif-sidecard__muted-name"
						href="<?php echo esc_url( PageRouter::profile_url( (int) $bn_muted_id ) ); ?>">
						<?php echo esc_html( $bn_muted_user->display_name ); ?>
					</a>
					<span class="bn-notif-sidecard__muted-handle">@<?php echo esc_html( $bn_muted_user->user_nicename ); ?></span>
				</div>
				<button type="button"
					class="bn-btn bn-btn--sm bn-notif-sidecard__muted-cta"
					data-variant="ghost"
					data-bn-relation-remove
					aria-label="<?php
					/* translators: %s: muted user's display name */
					echo esc_attr( sprintf( __( 'Unmute %s', 'buddynext' ), $bn_muted_user->display_name ) );
					?>">
					<?php esc_html_e( 'Unmute', 'buddynext' ); ?>
				</button>
			</li>
		<?php endforeach; ?>
	</ul>

	<footer class="bn-notif-sidecard__foot">
		<a class="bn-notif-sidecard__manage" href="<?php echo esc_url( $bn_manage_url ); ?>">
			<?php esc_html_e( 'Manage muted', 'buddynext' ); ?>
		</a>
	</footer>
</section>
<?php
do_action( 'buddynext_part_notifications_sidecard_muted_after', $args );
