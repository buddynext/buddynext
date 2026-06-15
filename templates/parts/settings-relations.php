<?php
/**
 * BuddyNext template part: settings-relations.
 *
 * The "Blocked, restricted & muted" section — relocated verbatim from the
 * profile editor (templates/profile/edit.php). Server-rendered lists with
 * REST-driven unblock/unrestrict/unmute buttons, pulled live from
 * BlockService so a member manages every relationship in one place.
 * Self-contained: it resolves the current user and computes its own lists.
 *
 * Overridable: copy to {theme}/buddynext/parts/settings-relations.php.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$user_id = get_current_user_id();

// Blocked & muted section — server-rendered lists with REST-
// driven unblock/unmute buttons. Pulls the live state from
// BlockService so the user can manage their relationships in
// one place rather than re-finding people in the directory.
$bn_blocked_ids    = (array) buddynext_service( 'blocks' )->blocked_users( $user_id );
$bn_muted_ids      = (array) buddynext_service( 'blocks' )->muted_users( $user_id );
$bn_restricted_ids = (array) buddynext_service( 'blocks' )->restricted_users( $user_id );

$bn_relations_html = '';

if ( ! empty( $bn_blocked_ids ) || ! empty( $bn_muted_ids ) || ! empty( $bn_restricted_ids ) ) {
	$bn_render_row = static function ( int $target_id, string $action ): string {
		$u = get_userdata( $target_id );
		if ( ! $u ) {
			return '';
		}
		$avatar = (string) get_avatar_url( $target_id, array( 'size' => 40 ) );
		if ( 'block' === $action ) {
			$action_label = __( 'Unblock', 'buddynext' );
		} elseif ( 'restrict' === $action ) {
			$action_label = __( 'Unrestrict', 'buddynext' );
		} else {
			$action_label = __( 'Unmute', 'buddynext' );
		}
		return sprintf(
			'<li class="bn-ep-relation" data-user-id="%1$d" data-relation="%2$s">' .
				'<img src="%3$s" alt="" width="40" height="40" class="bn-avatar">' .
				'<span class="bn-ep-relation__name">%4$s</span>' .
				'<span class="bn-ep-relation__handle">@%5$s</span>' .
				'<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-bn-relation-remove>%6$s</button>' .
			'</li>',
			(int) $target_id,
			esc_attr( $action ),
			esc_url( $avatar ),
			esc_html( $u->display_name ),
			esc_html( $u->user_nicename ),
			esc_html( $action_label )
		);
	};

	$bn_relations_html .= '<div class="bn-ep-relations">';

	$bn_relations_html .= '<div class="bn-ep-relations__group">';
	$bn_relations_html .= '<h3 class="bn-ep-relations__title">' . esc_html__( 'Blocked', 'buddynext' ) . '</h3>';
	if ( empty( $bn_blocked_ids ) ) {
		$bn_relations_html .= '<p class="bn-ep-relations__empty">' . esc_html__( 'You haven\'t blocked anyone.', 'buddynext' ) . '</p>';
	} else {
		$bn_relations_html .= '<ul class="bn-ep-relations__list">';
		foreach ( $bn_blocked_ids as $bid ) {
			$bn_relations_html .= $bn_render_row( (int) $bid, 'block' );
		}
		$bn_relations_html .= '</ul>';
	}
	$bn_relations_html .= '</div>';

	$bn_relations_html .= '<div class="bn-ep-relations__group">';
	$bn_relations_html .= '<h3 class="bn-ep-relations__title">' . esc_html__( 'Restricted', 'buddynext' ) . '</h3>';
	if ( empty( $bn_restricted_ids ) ) {
		$bn_relations_html .= '<p class="bn-ep-relations__empty">' . esc_html__( "You haven't restricted anyone.", 'buddynext' ) . '</p>';
	} else {
		$bn_relations_html .= '<ul class="bn-ep-relations__list">';
		foreach ( $bn_restricted_ids as $rid ) {
			$bn_relations_html .= $bn_render_row( (int) $rid, 'restrict' );
		}
		$bn_relations_html .= '</ul>';
	}
	$bn_relations_html .= '</div>';

	$bn_relations_html .= '<div class="bn-ep-relations__group">';
	$bn_relations_html .= '<h3 class="bn-ep-relations__title">' . esc_html__( 'Muted', 'buddynext' ) . '</h3>';
	if ( empty( $bn_muted_ids ) ) {
		$bn_relations_html .= '<p class="bn-ep-relations__empty">' . esc_html__( 'You haven\'t muted anyone.', 'buddynext' ) . '</p>';
	} else {
		$bn_relations_html .= '<ul class="bn-ep-relations__list">';
		foreach ( $bn_muted_ids as $mid ) {
			$bn_relations_html .= $bn_render_row( (int) $mid, 'mute' );
		}
		$bn_relations_html .= '</ul>';
	}
	$bn_relations_html .= '</div>';

	$bn_relations_html .= '</div>';
} else {
	$bn_relations_html = '<p class="bn-ep-relations__empty">' . esc_html__( "You haven't blocked, restricted, or muted anyone.", 'buddynext' ) . '</p>';
}

buddynext_get_template(
	'parts/profile-edit-section.php',
	array(
		'title'     => __( 'Blocked, restricted & muted', 'buddynext' ),
		'subtitle'  => __( 'Remove a relationship to clear it. Add new ones from each member\'s card menu.', 'buddynext' ),
		'body_html' => $bn_relations_html,
	)
);
