<?php
/**
 * BuddyNext — Post card partial (v2 pattern).
 *
 * Reusable post card rendered across home, explore, profile, and space feeds.
 * Receives $post (hydrated array from FeedService/PostService),
 * $current_user_id (int, 0 for guests), and $context
 * (string: home|explore|profile|space) — variables extracted by TemplateLoader::render().
 *
 * This file is now a thin composer: it resolves shared state once and
 * delegates each UI region to a `templates/parts/post-*.php` template part.
 * The 8 region parts each expose the standard 4-hook contract
 * (`buddynext_part_post_{name}_{args|classes|before|after}`) — see
 * `docs/specs/TEMPLATE-PARTS.md`.
 *
 * Markup follows the v2 prototype in `docs/v2 Plans/v2/home-feed.html`
 * (in-feed post variant) and `docs/v2 Plans/v2/post-detail.html` (full post).
 *
 * Supported post types: text, photo, file, link, poll, announcement,
 *   activity, media, discussion, job, share.
 *
 * Overridable: copy to {theme}/buddynext/partials/post-card.php
 *
 * @package BuddyNext
 * @since   1.0.0
 *
 * @var array     $post            Hydrated post array.
 * @var int       $current_user_id Viewing user ID.
 * @var string    $context         Feed context (home|explore|profile|space).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$bn_post         = $post ?? array();
$current_user_id = absint( $current_user_id ?? 0 );
$context         = in_array( $context ?? '', array( 'home', 'explore', 'profile', 'space', 'single', 'bookmarks' ), true )
	? ( $context )
	: 'home';

if ( empty( $bn_post ) || empty( $bn_post['id'] ) ) {
	return;
}

// ── Post meta ──────────────────────────────────────────────────────────────────
$bn_post_id      = absint( $bn_post['id'] );
$bn_post_type    = $bn_post['type'] ?? 'text';
$post_author_id  = absint( $bn_post['user_id'] ?? 0 );
$post_content    = wp_specialchars_decode( $bn_post['content'] ?? '', ENT_QUOTES );
$post_privacy    = $bn_post['privacy'] ?? 'public';
$post_privacy    = in_array( $post_privacy, array( 'public', 'followers', 'connections', 'space_members', 'private' ), true )
	? $post_privacy
	: 'public';
$is_pinned       = ! empty( $bn_post['is_pinned'] );
$is_announcement = ! empty( $bn_post['is_announcement'] );
$edited_at       = $bn_post['edited_at'] ?? null;
$created_at      = $bn_post['created_at'] ?? '';
$reaction_count  = absint( $bn_post['reaction_count'] ?? 0 );
$comment_count   = absint( $bn_post['comment_count'] ?? 0 );
$share_count     = absint( $bn_post['share_count'] ?? 0 );

// Top-3 reaction types for the engagement-summary chip strip (v2 prototype
// pattern: `<emoji> 24 · <emoji> 12 · <emoji> 8`). Skipped entirely when
// the post has no reactions — no query overhead on engagement-less posts.
$top_reactions = array();
if ( $reaction_count > 0 && $bn_post_id > 0 ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT emoji, COUNT(*) AS n
			   FROM {$wpdb->prefix}bn_reactions
			  WHERE object_type = 'post' AND object_id = %d
			  GROUP BY emoji
			  ORDER BY n DESC, emoji ASC
			  LIMIT 3",
			$bn_post_id
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	foreach ( (array) $rows as $row ) {
		$top_reactions[] = array(
			'slug'  => sanitize_key( (string) $row['emoji'] ),
			'count' => (int) $row['n'],
		);
	}
}
$media_ids    = is_array( $bn_post['media_ids'] ?? null ) ? $bn_post['media_ids'] : array();
$link_url     = $bn_post['link_url'] ?? '';
$link_meta    = is_array( $bn_post['link_meta'] ?? null ) ? $bn_post['link_meta'] : array();
$poll_options = is_array( $bn_post['poll_options'] ?? null ) ? $bn_post['poll_options'] : array();

// Content warning.
$has_cw      = ! empty( $bn_post['content_warning'] );
$cw_type_raw = $bn_post['content_warning_type'] ?? '';
$cw_type     = in_array( $cw_type_raw, array( 'nsfw', 'spoilers', 'violence', 'language' ), true ) ? $cw_type_raw : '';

// ── Shared post (type='share' only) ─────────────────────────────────────────────
$shared_post    = null;
$shared_post_id = absint( $bn_post['shared_post_id'] ?? 0 );
if ( 'share' === $bn_post_type && $shared_post_id > 0 ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$shared_post = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts WHERE id = %d AND status = 'published' LIMIT 1",
			$shared_post_id
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// ── Author ─────────────────────────────────────────────────────────────────────
$author       = get_userdata( $post_author_id );
$display_name = $author ? $author->display_name : __( 'Community Member', 'buddynext' );
$username     = $author ? $author->user_nicename : '';
$avatar_url   = get_avatar_url( $post_author_id, array( 'size' => 68 ) );
$profile_link = $post_author_id > 0 ? PageRouter::profile_url( $post_author_id ) : '#';

// Initials fallback.
$name_parts = array_filter( explode( ' ', trim( $display_name ) ) );
if ( count( $name_parts ) >= 2 ) {
	$initials = strtoupper( substr( (string) reset( $name_parts ), 0, 1 ) . substr( (string) end( $name_parts ), 0, 1 ) );
} else {
	$initials = strtoupper( substr( $display_name, 0, 2 ) );
}

// Member type badge.
$member_type_slug  = get_user_meta( $post_author_id, 'bn_member_type', true );
$member_type_label = $member_type_slug ? get_user_meta( $post_author_id, 'bn_member_type_label', true ) : '';

// ── Timestamps ─────────────────────────────────────────────────────────────────
if ( ! function_exists( 'bn_post_card_relative_time' ) ) :
	/**
	 * Return a human-readable relative time string for a datetime.
	 *
	 * @param string $datetime MySQL datetime string.
	 * @return string
	 */
	function bn_post_card_relative_time( string $datetime ): string {
		$diff = time() - (int) strtotime( $datetime );
		if ( $diff < 60 ) {
			return esc_html__( 'just now', 'buddynext' );
		}
		if ( $diff < 3600 ) {
			$mins = (int) round( $diff / 60 );
			/* translators: %d: number of minutes */
			return esc_html( sprintf( _n( '%dm ago', '%dm ago', $mins, 'buddynext' ), $mins ) );
		}
		if ( $diff < 86400 ) {
			$hours = (int) round( $diff / 3600 );
			/* translators: %d: number of hours */
			return esc_html( sprintf( _n( '%dh ago', '%dh ago', $hours, 'buddynext' ), $hours ) );
		}
		$days = (int) round( $diff / 86400 );
		/* translators: %d: number of days */
		return esc_html( sprintf( _n( '%dd ago', '%dd ago', $days, 'buddynext' ), $days ) );
	}
endif;

$post_time    = bn_post_card_relative_time( $created_at );
$edited_label = $edited_at ? esc_html__( '(edited)', 'buddynext' ) : '';

// ── Permissions ────────────────────────────────────────────────────────────────
$is_own_post  = ( $current_user_id > 0 && $current_user_id === $post_author_id );

// ── Connection degree (1st / 2nd) for the byline badge ──────────────────────────
// Mirrors the degree pill already shipped on parts/profile-hero + parts/member-card.
// Only computed for other people's posts when the viewer is signed in. Memoized per
// (viewer:author) for the request so a feed of N cards costs at most one degree
// lookup per UNIQUE author, not N — the viewer's own connection set is fetched once
// inside ConnectionService and reused. Same request-cache approach the space
// attribution below already relies on via SpaceService::get().
$byline_degree = 0;
if ( $current_user_id > 0 && ! $is_own_post && $post_author_id > 0 ) {
	if ( ! isset( $GLOBALS['bn_byline_degree_memo'] ) ) {
		$GLOBALS['bn_byline_degree_memo'] = array();
	}
	$bn_degree_key = $current_user_id . ':' . $post_author_id;
	if ( ! array_key_exists( $bn_degree_key, $GLOBALS['bn_byline_degree_memo'] ) ) {
		$GLOBALS['bn_byline_degree_memo'][ $bn_degree_key ] = (int) buddynext_service( 'connections' )->connection_degree( $current_user_id, $post_author_id );
	}
	$byline_degree = (int) $GLOBALS['bn_byline_degree_memo'][ $bn_degree_key ];
}

// ── Inline byline Follow ────────────────────────────────────────────────────
// Surface a Follow button on the byline ONLY for authors the viewer does not
// already follow — so a follow-based home feed isn't cluttered with
// "Following" buttons, while explore / discovery surfaces stay actionable
// (matches the v2 prototype, which shows Follow only on unfollowed authors).
// is_following is memoized per author for the request; the resolved state is
// handed to the follow-button partial via known_following so it never
// re-queries. Filterable so a site can suppress byline follow entirely.
$byline_show_follow = false;
if (
	$current_user_id > 0
	&& ! $is_own_post
	&& $post_author_id > 0
	&& (bool) apply_filters( 'buddynext_byline_show_follow', true, $post_author_id, $bn_post_id )
) {
	if ( ! isset( $GLOBALS['bn_byline_follow_memo'] ) ) {
		$GLOBALS['bn_byline_follow_memo'] = array();
	}
	$bn_follow_key = $current_user_id . ':' . $post_author_id;
	if ( ! array_key_exists( $bn_follow_key, $GLOBALS['bn_byline_follow_memo'] ) ) {
		$GLOBALS['bn_byline_follow_memo'][ $bn_follow_key ] = (bool) buddynext_service( 'follows' )->is_following( $current_user_id, $post_author_id );
	}
	// Show the button only when NOT already following.
	$byline_show_follow = ! $GLOBALS['bn_byline_follow_memo'][ $bn_follow_key ];
}
$is_admin     = ( $current_user_id > 0 && user_can( $current_user_id, 'manage_options' ) );
$can_edit     = $is_own_post || $is_admin;
$can_delete   = $is_own_post || $is_admin;
$can_pin      = $is_own_post || $is_admin;
$can_report   = ( $current_user_id > 0 && ! $is_own_post );
$can_react    = ( $current_user_id > 0 );
$can_comment  = ( $current_user_id > 0 );

// Re-shares and bookmarks are site-owner toggles (BuddyNext → Social). When the
// owner disables a feature the corresponding action control must disappear, not
// just no-op — both default ON when the option is unset.
$can_share    = ( $current_user_id > 0 && (bool) get_option( 'buddynext_allow_shares', true ) );
$can_bookmark = ( $current_user_id > 0 && (bool) get_option( 'buddynext_allow_bookmarks', true ) );

// ── Nonces — all REST calls use the wp_rest nonce ──────────────────────────────
$rest_nonce     = $current_user_id > 0 ? wp_create_nonce( 'wp_rest' ) : '';
$react_nonce    = $rest_nonce;
$share_nonce    = $rest_nonce;
$bookmark_nonce = $rest_nonce;
$report_nonce   = $can_report ? $rest_nonce : '';
$dismiss_nonce  = $is_announcement ? $rest_nonce : '';
$poll_nonce     = ( 'poll' === $bn_post_type && $current_user_id > 0 ) ? $rest_nonce : '';

// ── Poll totals + reactive context ─────────────────────────────────────────────
$poll_total_votes   = 0;
$poll_options_ctx   = array();
$my_voted_option_id = 0;
if ( 'poll' === $bn_post_type && ! empty( $poll_options ) ) {
	foreach ( $poll_options as $opt ) {
		$poll_total_votes += absint( $opt['vote_count'] );
	}
	foreach ( $poll_options as $opt ) {
		$v                  = absint( $opt['vote_count'] );
		$p                  = $poll_total_votes > 0 ? (int) round( ( $v / $poll_total_votes ) * 100 ) : 0;
		$poll_options_ctx[] = array(
			'id'    => absint( $opt['id'] ),
			'text'  => (string) ( $opt['option_text'] ?? '' ),
			'votes' => $v,
			'pct'   => $p,
		);
	}
	if ( $current_user_id > 0 ) {
		$my_voted_option_id = (int) ( buddynext_service( 'polls' )->user_vote( $current_user_id, $bn_post_id ) ?? 0 );
	}
}

// ── User's existing reaction ───────────────────────────────────────────────────
$my_reaction_type = null;
if ( $current_user_id > 0 ) {
	$my_reaction_type = buddynext_service( 'reactions' )->get_user_reaction( $current_user_id, 'post', $bn_post_id );
}

// ── User's bookmark state ──────────────────────────────────────────────────────
$is_bookmarked = false;
if ( $current_user_id > 0 ) {
	$is_bookmarked = buddynext_service( 'bookmarks' )->is_bookmarked( $current_user_id, $bn_post_id );
}

// ── Privacy label ──────────────────────────────────────────────────────────────
$privacy_labels = array(
	'public'        => __( 'Public', 'buddynext' ),
	'followers'     => __( 'Followers only', 'buddynext' ),
	'connections'   => __( 'Connections only', 'buddynext' ),
	'space_members' => __( 'Space members', 'buddynext' ),
	'private'       => __( 'Only me', 'buddynext' ),
);
$privacy_icons  = array(
	'public'        => buddynext_get_icon( 'globe' ),
	'followers'     => buddynext_get_icon( 'user' ),
	'connections'   => buddynext_get_icon( 'users' ),
	'space_members' => buddynext_get_icon( 'lock' ),
	'private'       => buddynext_get_icon( 'lock' ),
);
$privacy_label  = isset( $privacy_labels[ $post_privacy ] ) ? esc_html( $privacy_labels[ $post_privacy ] ) : '';
$privacy_icon   = $privacy_icons[ $post_privacy ] ?? '';

// ── Content warning label ──────────────────────────────────────────────────────
$cw_labels  = array(
	'nsfw'     => esc_html__( 'NSFW', 'buddynext' ),
	'spoilers' => esc_html__( 'Spoilers', 'buddynext' ),
	'violence' => esc_html__( 'Violence', 'buddynext' ),
	'language' => esc_html__( 'Strong Language', 'buddynext' ),
);
$cw_display = $cw_type && isset( $cw_labels[ $cw_type ] ) ? $cw_labels[ $cw_type ] : esc_html__( 'Sensitive content', 'buddynext' );

// ── Link preview fields ────────────────────────────────────────────────────────
$link_title  = $link_meta['title'] ?? '';
$link_desc   = $link_meta['description'] ?? '';
$link_thumb  = $link_meta['thumbnail'] ?? '';
$link_domain = $link_url ? wp_parse_url( $link_url, PHP_URL_HOST ) : '';
$link_domain = $link_domain ? ltrim( (string) $link_domain, 'www.' ) : '';

// ── Article CSS classes ────────────────────────────────────────────────────────
$card_classes = array( 'bn-post-card' );
if ( $is_announcement ) {
	$card_classes[] = 'bn-post-card--announcement';
}
if ( $is_pinned ) {
	$card_classes[] = 'bn-post-card--pinned';
}
if ( 'poll' === $bn_post_type ) {
	$card_classes[] = 'bn-post-card--poll';
}

$card_class_attr = implode( ' ', array_map( 'sanitize_html_class', $card_classes ) );
?>
<article
	class="<?php echo esc_attr( $card_class_attr ); ?>"
	data-wp-interactive="buddynext/post-card"
	data-wp-init="callbacks.initPostCard"
	data-wp-context='
	<?php
		echo wp_json_encode(
			array(
				'postId'            => $bn_post_id,
				'authorId'          => $post_author_id,
				'currentUserId'     => $current_user_id,
				'postType'          => $bn_post_type,
				'showContent'       => ! $has_cw,
				'isPinned'          => $is_pinned,
				'bookmarked'        => $is_bookmarked,
				'reactionType'      => $my_reaction_type,
				'reactNonce'        => $react_nonce,
				'shareNonce'        => $share_nonce,
				'bookmarkNonce'     => $bookmark_nonce,
				'reportNonce'       => $report_nonce,
				'dismissNonce'      => $dismiss_nonce,
				'pollNonce'         => $poll_nonce,
				'pollOptions'       => $poll_options_ctx,
				'pollVotedOptionId' => $my_voted_option_id,
				'pollTotalVotes'    => $poll_total_votes,
				'commentsOpen'      => 'single' === $context,
				'commentCount'      => $comment_count,
				'shareCount'        => $share_count,
				'shareShared'       => false,
				'restUrl'           => rest_url( 'buddynext/v1' ),
				'context'           => $context,
			)
		);
		?>
	'
	data-post-id="<?php echo absint( $bn_post_id ); ?>"
	data-post-type="<?php echo esc_attr( $bn_post_type ); ?>"
	aria-labelledby="bn-post-author-<?php echo absint( $bn_post_id ); ?>"
>

	<?php if ( $is_announcement ) : ?>
		<div class="bn-post-card__announcement-bar" role="banner">
			<span class="bn-post-card__ann-badge">
				<?php buddynext_icon( 'megaphone' ); ?> <?php esc_html_e( 'Announcement', 'buddynext' ); ?>
			</span>
			<?php if ( $is_admin ) : ?>
			<button
				type="button"
				class="bn-post-card__ann-end bn-btn"
				data-size="sm"
				data-variant="ghost"
				data-wp-on--click="actions.endAnnouncement"
			><?php esc_html_e( 'End', 'buddynext' ); ?></button>
			<?php endif; ?>
			<button
				type="button"
				class="bn-post-card__ann-dismiss"
				aria-label="<?php esc_attr_e( 'Dismiss announcement', 'buddynext' ); ?>"
				data-wp-on--click="actions.dismissAnnouncement"
			><?php buddynext_icon( 'x' ); ?></button>
		</div>
	<?php endif; ?>

	<?php if ( $is_pinned && ! $is_announcement ) : ?>
		<div class="bn-post-card__pin-label" aria-label="<?php esc_attr_e( 'Pinned post', 'buddynext' ); ?>">
			<?php buddynext_icon( 'bookmark' ); ?> <?php esc_html_e( 'Pinned', 'buddynext' ); ?>
		</div>
	<?php endif; ?>

	<?php
	// Explore cover — surface a media thumbnail or link-OG image at the
	// top of the card on the /activity/explore/ grid so the visual
	// surface actually looks visual. Falls back silently when the post
	// has neither (text-only posts keep their text-first layout).
	if ( 'explore' === $context ) :
		$bn_cover_url = '';
		$bn_cover_alt = '';
		$bn_first_mid = isset( $media_ids[0] ) ? absint( $media_ids[0] ) : 0;
		if ( $bn_first_mid > 0 ) {
			// Resolve via the engine (signed URL); BuddyNext never reads WP
			// attachments for media — all media lives in mvs_media_index.
			$bn_cover_desc = \BuddyNext\Media\MediaUrlResolver::descriptor( $bn_first_mid );
			if ( $bn_cover_desc ) {
				$bn_cover_url = (string) ( '' !== $bn_cover_desc['thumb'] ? $bn_cover_desc['thumb'] : $bn_cover_desc['url'] );
				$bn_cover_alt = (string) $bn_cover_desc['title'];
			}
		}
		if ( '' === $bn_cover_url && ! empty( $link_meta['thumbnail'] ) ) {
			$bn_cover_url = (string) $link_meta['thumbnail'];
			$bn_cover_alt = (string) ( $link_meta['title'] ?? '' );
		}
		if ( '' !== $bn_cover_url ) :
			?>
			<a class="bn-post-card__cover" href="<?php echo esc_url( PageRouter::post_url( $bn_post_id ) ); ?>" aria-hidden="true" tabindex="-1">
				<img
					src="<?php echo esc_url( $bn_cover_url ); ?>"
					alt="<?php echo esc_attr( $bn_cover_alt ); ?>"
					loading="lazy"
					decoding="async"
				>
			</a>
			<?php
		endif;
	endif;
	?>

	<?php
	// Head row — byline part renders the options-menu inline so the flex
	// container preserves byte-identical sibling ordering.
	buddynext_get_template(
		'parts/post-byline.php',
		array(
			'bn_post'           => $bn_post,
			'bn_post_id'        => $bn_post_id,
			'author_id'         => $post_author_id,
			'display_name'      => $display_name,
			'username'          => $username,
			'avatar_url'        => $avatar_url,
			'initials'          => $initials,
			'member_type_label' => $member_type_label,
			'degree'            => $byline_degree,
			'show_follow'       => $byline_show_follow,
			'created_at'        => $created_at,
			'post_time'         => $post_time,
			'edited_label'      => $edited_label,
			'privacy_label'     => $privacy_label,
			'privacy_icon'      => $privacy_icon,
			'profile_link'      => $profile_link,
			'options_menu_args' => array(
				'bn_post'    => $bn_post,
				'bn_post_id' => $bn_post_id,
				'can_edit'   => $can_edit,
				'can_pin'    => $can_pin,
				'can_report' => $can_report,
				'can_delete' => $can_delete,
				'is_pinned'  => $is_pinned,
			),
		)
	);

	buddynext_get_template(
		'parts/post-cw-overlay.php',
		array(
			'has_cw'     => $has_cw,
			'cw_type'    => $cw_type,
			'cw_label'   => $cw_display,
			'bn_post_id' => $bn_post_id,
		)
	);

	buddynext_get_template(
		'parts/post-body.php',
		array(
			'bn_post'           => $bn_post,
			'bn_post_id'        => $bn_post_id,
			'bn_post_type'      => $bn_post_type,
			'post_content'      => $post_content,
			'link_preview'      => array(
				'url'    => $link_url,
				'title'  => $link_title,
				'desc'   => $link_desc,
				'thumb'  => $link_thumb,
				'domain' => $link_domain,
			),
			'poll_data'         => array(
				'options'            => $poll_options,
				'total_votes'        => $poll_total_votes,
				'my_voted_option_id' => $my_voted_option_id,
			),
			'media_attachments' => $media_ids,
			'is_pinned'         => $is_pinned,
			'has_cw'            => $has_cw,
			'shared_post'       => $shared_post,
		)
	);

	buddynext_get_template(
		'parts/post-reaction-summary.php',
		array(
			'reaction_count' => $reaction_count,
			'comment_count'  => $comment_count,
			'share_count'    => $share_count,
			'top_reactions'  => $top_reactions,
			'bn_post_id'     => $bn_post_id,
		)
	);

	buddynext_get_template(
		'parts/post-actions.php',
		array(
			'bn_post'       => $bn_post,
			'bn_post_id'    => $bn_post_id,
			'bn_post_type'  => $bn_post_type,
			'user_reaction' => $my_reaction_type,
			'is_bookmarked' => $is_bookmarked,
			'can_react'     => $can_react,
			'can_comment'   => $can_comment,
			'can_share'     => $can_share,
			'can_bookmark'  => $can_bookmark,
			'comment_count' => $comment_count,
			'share_count'   => $share_count,
		)
	);
	?>

	<!-- Comments expand region -->
	<div
		class="bn-post-card__comments"
		hidden
		data-wp-bind--hidden="state.commentsHidden"
		data-post-id="<?php echo absint( $bn_post_id ); ?>"
	>
		<?php
		buddynext_get_template(
			'parts/post-comments-list.php',
			array(
				'bn_post'    => $bn_post,
				'bn_post_id' => $bn_post_id,
				'comments'   => array(),
				'viewer_id'  => $current_user_id,
			)
		);

		if ( $current_user_id > 0 ) {
			buddynext_get_template(
				'parts/post-comment-form.php',
				array(
					'bn_post'     => $bn_post,
					'bn_post_id'  => $bn_post_id,
					'user_id'     => $current_user_id,
					'placeholder' => __( 'Write a comment...', 'buddynext' ),
				)
			);
		}
		?>
	</div>

</article>
