<?php
/**
 * BuddyNext template part: space-header — the unified space hero + nav bar.
 *
 * The single header every space sub-page renders, so the header + tab nav are
 * uniform across Feed / Members / About / Media / Moderation (and any
 * integration tab). Standalone pages (spaces/members.php, spaces/moderation.php)
 * include this instead of hand-rolling a simplified header — it resolves the
 * membership state, stats and the Nav-registry tabs from just the space id +
 * viewer, then delegates to parts/space-hero.php (which renders parts/nav-bar.php).
 *
 * @package BuddyNext
 *
 * @var int    $space_id   Required. The space's primary key.
 * @var string $active_tab Optional. Active tab id (feed|members|about|media|moderation).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_sh_space_id = isset( $space_id ) ? absint( $space_id ) : 0;
if ( $bn_sh_space_id <= 0 ) {
	return;
}

$bn_sh_record = ( new \BuddyNext\Spaces\SpaceService() )->get( $bn_sh_space_id );
if ( null === $bn_sh_record ) {
	return;
}
$bn_sh_space  = (object) $bn_sh_record;
$bn_sh_active = isset( $active_tab ) && '' !== (string) $active_tab ? (string) $active_tab : 'feed';
$bn_sh_viewer = get_current_user_id();

// Membership state (mirrors spaces/home.php) — drives the hero action cluster.
$bn_sh_member_svc = new \BuddyNext\Spaces\SpaceMemberService();
$bn_sh_role       = $bn_sh_viewer ? (string) $bn_sh_member_svc->get_role( $bn_sh_space_id, $bn_sh_viewer ) : '';
$bn_sh_status     = $bn_sh_viewer ? (string) $bn_sh_member_svc->get_status( $bn_sh_space_id, $bn_sh_viewer ) : '';
$bn_sh_is_member  = 'active' === $bn_sh_status;
$bn_sh_is_owner   = $bn_sh_is_member && in_array( $bn_sh_role, array( 'owner', 'moderator' ), true );
$bn_sh_is_pending = 'pending' === $bn_sh_status;
$bn_sh_is_invited = 'invited' === $bn_sh_status;
$bn_sh_is_guest   = 0 === (int) $bn_sh_viewer;

// Header stats — Members / Posts (when > 0) / Created, mirroring the space home.
$bn_sh_member_count = (int) ( $bn_sh_record['member_count'] ?? 0 );
$bn_sh_post_count   = (int) buddynext_service( 'feed' )->space_post_count( $bn_sh_space_id );
$bn_sh_stats        = array();
if ( $bn_sh_member_count > 0 ) {
	$bn_sh_stats[] = array(
		'label' => __( 'Members', 'buddynext' ),
		'value' => number_format_i18n( $bn_sh_member_count ),
		'icon'  => 'users',
	);
}
if ( $bn_sh_post_count > 0 ) {
	$bn_sh_stats[] = array(
		'label' => __( 'Posts', 'buddynext' ),
		'value' => number_format_i18n( $bn_sh_post_count ),
		'icon'  => 'message-circle',
	);
}
$bn_sh_stats[] = array(
	'label' => __( 'Created', 'buddynext' ),
	'value' => ! empty( $bn_sh_space->created_at ) ? buddynext_date_local( (string) $bn_sh_space->created_at, 'M Y' ) : '—',
	'icon'  => 'calendar',
);

// Unified nav tabs from the registry (SpaceNav + bridges), gated for this role.
$bn_sh_nav       = buddynext_nav( new \BuddyNext\Nav\NavContext( 'space', $bn_sh_space_id, (int) $bn_sh_viewer, $bn_sh_is_member ? $bn_sh_role : '' ) );
$bn_sh_nav_items = $bn_sh_nav->layer( 'primary' );

buddynext_get_template(
	'parts/space-hero.php',
	array(
		'space'           => $bn_sh_space,
		'space_id'        => $bn_sh_space_id,
		'current_user_id' => (int) $bn_sh_viewer,
		'is_member'       => $bn_sh_is_member,
		'is_owner'        => $bn_sh_is_owner,
		'is_pending'      => $bn_sh_is_pending,
		'is_invited'      => $bn_sh_is_invited,
		'is_guest'        => $bn_sh_is_guest,
		'privacy_label'   => \BuddyNext\Spaces\SpaceService::type_label( (string) $bn_sh_space->type ),
		'privacy_tone'    => \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( (string) $bn_sh_space->type ),
		'notif_pref'      => $bn_sh_viewer ? $bn_sh_member_svc->get_notification_pref( $bn_sh_space_id, $bn_sh_viewer ) : '',
		'stats'           => $bn_sh_stats,
		'active_tab'      => $bn_sh_active,
		'nav_items'       => $bn_sh_nav_items,
	)
);
