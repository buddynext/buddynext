<?php
/**
 * BuddyNext template part: member-directory-grid.
 *
 * Renders the directory grid wrapper (`.bn-md-grid`) and iterates the
 * member list, delegating each row to `parts/member-card.php`. Per-member
 * state — follow / connection / mutual / muted — is resolved here so
 * `member-card.php` remains a pure render unit reusable by other
 * surfaces (search results, space members, etc.).
 *
 * Used by: templates/directory/members.php.
 *
 * @package BuddyNext
 *
 * @var array    $members        Required. Array of WP_User objects to render.
 * @var int      $viewer_id      Optional. Currently-viewing user ID. Default 0.
 * @var string   $view_mode      Optional. Reserved for future grid/list view modes. Default 'grid'.
 * @var array    $avatar_tones   Optional. Avatar tone palette cycled by user ID. Default [].
 * @var array    $type_map       Optional. slug => member_type data map. Default [].
 * @var string   $messages_base  Optional. Base messages URL. Default ''.
 * @var callable $initials_fn    Required. Helper that returns initials for a display name.
 * @var callable $is_online_fn   Required. Helper that returns bool for a user ID.
 * @var callable $is_following_fn Required. Helper that returns bool for a target user ID.
 * @var callable $mutual_count_fn Required. Helper that returns int mutual count for (viewer, target).
 * @var array    $classes        Optional. Extra CSS classes appended to `.bn-md-grid`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_member_directory_grid_before', $args )
 *   - do_action( 'buddynext_part_member_directory_grid_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_member_directory_grid_args',    array $args )
 *   - apply_filters( 'buddynext_part_member_directory_grid_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'members'         => isset( $members ) ? (array) $members : array(),
	'viewer_id'       => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'view_mode'       => isset( $view_mode ) ? (string) $view_mode : 'grid',
	'avatar_tones'    => isset( $avatar_tones ) ? (array) $avatar_tones : array(),
	'type_map'        => isset( $type_map ) ? (array) $type_map : array(),
	'messages_base'   => isset( $messages_base ) ? (string) $messages_base : '',
	'initials_fn'     => isset( $initials_fn ) && is_callable( $initials_fn ) ? $initials_fn : null,
	'is_online_fn'    => isset( $is_online_fn ) && is_callable( $is_online_fn ) ? $is_online_fn : null,
	'is_following_fn' => isset( $is_following_fn ) && is_callable( $is_following_fn ) ? $is_following_fn : null,
	'mutual_count_fn' => isset( $mutual_count_fn ) && is_callable( $mutual_count_fn ) ? $mutual_count_fn : null,
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_directory_grid_args', $args );

$bn_classes = array_merge( array( 'bn-md-grid' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_directory_grid_classes', $bn_classes, $args );
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

$bn_members         = (array) $args['members'];
$bn_viewer_id       = (int) $args['viewer_id'];
$bn_tones           = (array) $args['avatar_tones'];
$bn_type_map        = (array) $args['type_map'];
$bn_messages_base   = (string) $args['messages_base'];
$bn_initials_fn     = $args['initials_fn'];
$bn_is_online_fn    = $args['is_online_fn'];
$bn_is_following_fn = $args['is_following_fn'];
$bn_mutual_fn       = $args['mutual_count_fn'];

if ( null === $bn_initials_fn || null === $bn_is_online_fn || null === $bn_is_following_fn || null === $bn_mutual_fn ) {
	return;
}

do_action( 'buddynext_part_member_directory_grid_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	role="list"
	data-wp-bind--hidden="state.gridHidden"
	<?php echo empty( $bn_members ) ? 'hidden' : ''; ?>
>
	<?php foreach ( $bn_members as $bn_member_obj ) : ?>
		<?php
		$bn_member_id    = (int) $bn_member_obj->ID;
		$bn_display_name = (string) $bn_member_obj->display_name;
		$bn_bio          = (string) get_user_meta( $bn_member_id, 'bn_field_bio', true );
		if ( '' === $bn_bio ) {
			$bn_bio = (string) get_user_meta( $bn_member_id, 'description', true );
		}
		$bn_profile_url   = \BuddyNext\Core\PageRouter::profile_url( $bn_member_id );
		$bn_avatar_url    = (string) get_avatar_url( $bn_member_id, array( 'size' => 96 ) );
		$bn_is_online     = (bool) $bn_is_online_fn( $bn_member_id );
		$bn_is_following  = (bool) $bn_is_following_fn( $bn_member_id );
		$bn_mutual        = (int) $bn_mutual_fn( $bn_viewer_id, $bn_member_id );
		$bn_degree        = $bn_viewer_id > 0 && $bn_viewer_id !== $bn_member_id
			? (int) buddynext_service( 'connections' )->connection_degree( $bn_viewer_id, $bn_member_id )
			: 0;
		$bn_type_slug     = (string) get_user_meta( $bn_member_id, 'bn_member_type', true );
		$bn_type_data     = '' !== $bn_type_slug ? ( $bn_type_map[ $bn_type_slug ] ?? null ) : null;
		$bn_type_label    = ( is_array( $bn_type_data ) && isset( $bn_type_data['name'] ) ) ? (string) $bn_type_data['name'] : '';
		$bn_messages_url  = add_query_arg( array( 'recipient' => $bn_member_id ), $bn_messages_base );
		$bn_conn_status   = $bn_viewer_id > 0
			? buddynext_service( 'connections' )->status( $bn_viewer_id, $bn_member_id )
			: null;
		$bn_tone_count    = max( 1, count( $bn_tones ) );
		$bn_avatar_tone   = ! empty( $bn_tones ) ? (string) $bn_tones[ $bn_member_id % $bn_tone_count ] : 'accent';
		$bn_presence_attr = $bn_is_online ? 'online' : 'offline';
		$bn_initials_text = (string) $bn_initials_fn( $bn_display_name );

		// Resolve direction-aware connection state for the 5-state Connect button.
		$bn_conn_state = 'none';
		if ( 'accepted' === $bn_conn_status ) {
			$bn_conn_state = 'accepted';
		} elseif ( 'pending' === $bn_conn_status && $bn_viewer_id > 0 ) {
			$bn_sent_ids   = buddynext_service( 'connections' )->pending_sent( $bn_viewer_id );
			$bn_conn_state = in_array( $bn_member_id, $bn_sent_ids, true ) ? 'pending-sent' : 'pending-received';
		}

		$bn_is_muted = $bn_viewer_id > 0
			? (bool) buddynext_service( 'blocks' )->is_muted( $bn_viewer_id, $bn_member_id )
			: false;

		buddynext_get_template(
			'parts/member-card.php',
			array(
				'member'            => $bn_member_obj,
				'viewer_id'         => $bn_viewer_id,
				'is_following'      => $bn_is_following,
				'connection_state'  => $bn_conn_state,
				'connection_status' => null === $bn_conn_status ? 'none' : (string) $bn_conn_status,
				'is_muted'          => $bn_is_muted,
				'mutual_count'      => $bn_mutual,
				'degree'            => $bn_degree,
				'presence'          => $bn_presence_attr,
				'member_type_label' => $bn_type_label,
				'avatar_tone'       => $bn_avatar_tone,
				'bio'               => $bn_bio,
				'profile_url'       => $bn_profile_url,
				'avatar_url'        => $bn_avatar_url,
				'initials'          => $bn_initials_text,
				'messages_url'      => $bn_messages_url,
			)
		);
		?>
	<?php endforeach; ?>
</div>
<?php
do_action( 'buddynext_part_member_directory_grid_after', $args );
