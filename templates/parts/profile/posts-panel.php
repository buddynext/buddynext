<?php
/**
 * BuddyNext template part: profile post-list panel (posts / scheduled / likes).
 *
 * The registry content seam for the three profile post-card tabs. One
 * parameterized panel — an optional owner composer (Posts only), then the
 * post-card list, then the per-kind empty state. Rendered by ProfileNav's
 * posts/scheduled/likes `render` callables, which self-fetch the rows; this part
 * only paints the bundle it is handed (no reveal wrapper).
 *
 * @package BuddyNext
 *
 * @var string       $kind         Required. 'posts' | 'scheduled' | 'likes'.
 * @var array        $posts        Post arrays for the list.
 * @var int          $viewer_id    Current viewer user ID.
 * @var bool         $is_owner     Whether the viewer owns this profile.
 * @var string       $display_name Profile display name (empty states).
 * @var WP_User|null $current_user Current WP_User (composer guard, Posts only).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pl_kind     = isset( $kind ) ? (string) $kind : 'posts';
$bn_pl_posts    = isset( $posts ) && is_array( $posts ) ? $posts : array();
$bn_pl_viewer   = isset( $viewer_id ) ? (int) $viewer_id : 0;
$bn_pl_is_owner = ! empty( $is_owner );
$bn_pl_name     = isset( $display_name ) ? (string) $display_name : '';
$bn_pl_user     = isset( $current_user ) ? $current_user : null;
$bn_pl_subject  = isset( $subject_id ) ? (int) $subject_id : 0;

// Posts tab: the owner posts directly from their activity tab via the shared
// composer (same Interactivity context as the home feed).
if ( 'posts' === $bn_pl_kind && $bn_pl_is_owner ) :
	buddynext_get_template(
		'partials/composer.php',
		array(
			'space_id'        => null,
			'current_user_id' => $bn_pl_viewer,
		)
	);
endif;

if ( ! empty( $bn_pl_posts ) ) :
	// Wrap in .bn-feed-stack so cards get the same vertical gap as the main feeds
	// (.bn-post-card carries no margin — spacing comes from the stack's gap).
	echo '<div class="bn-feed-stack">';
	foreach ( $bn_pl_posts as $bn_pl_post ) {
		$bn_pl_post = (array) $bn_pl_post;
		// Decode media_ids JSON string for the post-card partial.
		if ( isset( $bn_pl_post['media_ids'] ) && is_string( $bn_pl_post['media_ids'] ) ) {
			$bn_pl_post['media_ids'] = json_decode( $bn_pl_post['media_ids'], true );
		}
		buddynext_get_template(
			'partials/post-card.php',
			array(
				'post'            => $bn_pl_post,
				'current_user_id' => $bn_pl_viewer,
				'context'         => 'profile',
			)
		);
	}
	echo '</div>';
else :
	// Per-kind empty state.
	if ( 'scheduled' === $bn_pl_kind ) {
		$bn_pl_icon  = 'clock';
		$bn_pl_empty = __( 'You have no scheduled posts.', 'buddynext' );
	} elseif ( 'likes' === $bn_pl_kind ) {
		$bn_pl_icon  = 'heart';
		$bn_pl_empty = __( 'No liked posts yet.', 'buddynext' );
	} else {
		$bn_pl_icon  = 'edit';
		$bn_pl_empty = $bn_pl_is_owner
			? __( 'You have not posted anything yet.', 'buddynext' )
			/* translators: %s: member display name */
			: sprintf( __( '%s has not posted anything yet.', 'buddynext' ), $bn_pl_name );
	}

	// An empty list because the account is PRIVATE is not the same as an empty
	// list because the member has never posted, and saying the wrong one is not a
	// cosmetic slip.
	//
	// A member with ten posts, viewed by someone who does not follow them, was
	// told "… has not posted anything yet." That is untrue, it makes an active
	// member look inactive, and it removes the only reason a visitor had to send
	// a follow request - the feed is correctly hidden, and then the page argues
	// there is nothing behind it.
	//
	// Instagram and X both say the honest thing instead ("This account is
	// private. Follow to see their photos.", "These posts are protected."), which
	// explains the emptiness AND names the next step. Same here.
	$bn_pl_private = false;
	if ( ! $bn_pl_is_owner && $bn_pl_subject > 0 && function_exists( 'buddynext_service' ) ) {
		$bn_pl_private = ! buddynext_service( 'privacy' )->can_view_activity( $bn_pl_viewer, $bn_pl_subject );
	}

	if ( $bn_pl_private ) {
		$bn_pl_icon  = 'lock';
		$bn_pl_empty = __( 'This account is private.', 'buddynext' );
	}
	?>
	<div class="bn-empty-state">
		<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( $bn_pl_icon ); ?></div>
		<div class="bn-empty-title"><?php echo esc_html( $bn_pl_empty ); ?></div>
		<?php if ( $bn_pl_private ) : ?>
			<div class="bn-empty-sub"><?php esc_html_e( 'Follow this member to see their posts.', 'buddynext' ); ?></div>
		<?php endif; ?>
	</div>
	<?php
endif;
