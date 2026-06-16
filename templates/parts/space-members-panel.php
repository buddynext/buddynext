<?php
/**
 * BuddyNext template part: space-members-panel.
 *
 * Renders the Members tab body of the space-home template: header
 * (title + count), role-filter chip row (All / Owners / Moderators /
 * Members), and the role-filtered member grid with follow + connection
 * action buttons.
 *
 * Used by: templates/spaces/home.php.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space            Required. Space row (with member_count).
 * @var array  $members          Required. Full members list, each row containing
 *                               `user_id`, `role`, `display_name`, `user_login`.
 * @var array  $top_contributors Optional. Top contributors list. Default [].
 * @var int    $viewer_id        Optional. Current user ID. Default 0.
 * @var string $member_count_fmt Required. Localized total-member count string.
 * @var string $active_role      Optional. Active role filter slug. Default ''.
 * @var array  $classes          Optional. Extra CSS classes appended to `.bn-sh-members`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_members_panel_before', $args )
 *   - do_action( 'buddynext_part_space_members_panel_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_members_panel_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_members_panel_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'            => isset( $space ) ? $space : null,
	'members'          => isset( $members ) && is_array( $members ) ? $members : array(),
	'top_contributors' => isset( $top_contributors ) && is_array( $top_contributors ) ? $top_contributors : array(),
	'viewer_id'        => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'member_count_fmt' => isset( $member_count_fmt ) ? (string) $member_count_fmt : '',
	'active_role'      => isset( $active_role ) ? (string) $active_role : '',
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_members_panel_args', $args );

if ( null === $args['space'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-sh-members' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_members_panel_classes', $bn_classes, $args );
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

$bn_space       = $args['space'];
$bn_members_all = (array) $args['members'];
$bn_viewer_id   = (int) $args['viewer_id'];
$bn_count_fmt   = (string) $args['member_count_fmt'];
$bn_active_role = (string) $args['active_role'];

if ( ! in_array( $bn_active_role, array( '', 'owner', 'moderator', 'member' ), true ) ) {
	$bn_active_role = '';
}

$bn_filtered = array();
foreach ( $bn_members_all as $bn_pm ) {
	if ( '' === $bn_active_role || $bn_pm->role === $bn_active_role ) {
		$bn_filtered[] = $bn_pm;
	}
}

$bn_member_filters = array(
	''          => __( 'All', 'buddynext' ),
	'owner'     => __( 'Owners', 'buddynext' ),
	'moderator' => __( 'Moderators', 'buddynext' ),
	'member'    => __( 'Members', 'buddynext' ),
);

$bn_filter_empty_messages = array(
	''          => __( 'No members yet.', 'buddynext' ),
	'owner'     => __( 'No owners yet.', 'buddynext' ),
	'moderator' => __( 'No moderators yet.', 'buddynext' ),
	'member'    => __( 'No members in this role yet.', 'buddynext' ),
);

do_action( 'buddynext_part_space_members_panel_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<header class="bn-sh-members__head">
		<h2 class="bn-sh-members__title"><?php esc_html_e( 'Members', 'buddynext' ); ?></h2>
		<p class="bn-sh-members__count">
			<?php
			printf(
				/* translators: %s: formatted member count. */
				esc_html( _n( '%s member', '%s members', (int) $bn_space->member_count, 'buddynext' ) ),
				esc_html( $bn_count_fmt )
			);
			?>
		</p>
	</header>

	<nav class="bn-tabs bn-sh-members__filter-chips" role="tablist" aria-label="<?php esc_attr_e( 'Filter members by role', 'buddynext' ); ?>">
		<?php foreach ( $bn_member_filters as $bn_role_val => $bn_role_label_chip ) : ?>
			<a
				href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'bn_tab'  => 'members',
							'bn_role' => $bn_role_val,
						)
					)
				);
				?>
						"
				class="bn-tab bn-sd-chip"
				role="tab"
				aria-selected="<?php echo ( $bn_active_role === $bn_role_val ) ? 'true' : 'false'; ?>"
			><?php echo esc_html( $bn_role_label_chip ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( empty( $bn_filtered ) ) : ?>
		<p class="bn-sh-members__empty"><?php echo esc_html( $bn_filter_empty_messages[ $bn_active_role ] ?? $bn_filter_empty_messages[''] ); ?></p>
	<?php else : ?>
		<ul class="bn-sh-members__grid" role="list">
			<?php foreach ( $bn_filtered as $bn_fm ) : ?>
				<?php
				$bn_fm_uid    = (int) $bn_fm->user_id;
				$bn_fm_name   = ! empty( $bn_fm->display_name ) ? $bn_fm->display_name : $bn_fm->user_login;
				$bn_fm_avatar = get_avatar_url( $bn_fm_uid, array( 'size' => 80 ) );
				$bn_fm_role   = in_array( $bn_fm->role, array( 'owner', 'moderator', 'member' ), true ) ? $bn_fm->role : 'member';
				$bn_role_tone = match ( $bn_fm_role ) {
					'owner'     => 'accent',
					'moderator' => 'info',
					default     => 'default',
				};
				$bn_role_label = match ( $bn_fm_role ) {
					'owner'     => __( 'Owner', 'buddynext' ),
					'moderator' => __( 'Moderator', 'buddynext' ),
					default     => __( 'Member', 'buddynext' ),
				};
				// Canonical BuddyNext profile permalink (/members/{slug}/). The old
				// get_author_posts_url() fallback pointed at the WP author archive,
				// which 404s on a BuddyNext community.
				$bn_fm_profile = \BuddyNext\Core\PageRouter::profile_url( $bn_fm_uid );
	?>
				<li class="bn-sh-members__card" role="listitem">
					<a href="<?php echo esc_url( $bn_fm_profile ); ?>" class="bn-sh-members__avatar-link">
						<span class="bn-avatar" data-size="md" aria-hidden="true">
							<?php if ( $bn_fm_avatar ) : ?>
								<img src="<?php echo esc_url( $bn_fm_avatar ); ?>" alt="" loading="lazy">
							<?php else : ?>
								<?php echo esc_html( strtoupper( mb_substr( $bn_fm_name, 0, 1 ) ) ); ?>
							<?php endif; ?>
						</span>
					</a>
					<div class="bn-sh-members__info">
						<a href="<?php echo esc_url( $bn_fm_profile ); ?>" class="bn-sh-members__name">
							<?php echo esc_html( $bn_fm_name ); ?>
						</a>
						<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_role_tone ); ?>"><?php echo esc_html( $bn_role_label ); ?></span>
					</div>
					<?php if ( $bn_viewer_id && $bn_viewer_id !== $bn_fm_uid ) : ?>
						<div class="bn-sh-members__actions">
							<?php
							buddynext_get_template(
								'partials/follow-button.php',
								array( 'user_id' => $bn_fm_uid )
							);
							buddynext_get_template(
								'partials/connection-button.php',
								array( 'user_id' => $bn_fm_uid )
							);
							?>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_space_members_panel_after', $args );
