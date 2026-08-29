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
use BuddyNext\Feed\PostService;
use BuddyNext\Profile\AvatarService;

$bn_post         = $post ?? array();
$current_user_id = absint( $current_user_id ?? 0 );
$context         = in_array( $context ?? '', array( 'home', 'explore', 'profile', 'space', 'single', 'bookmarks' ), true )
	? ( $context )
	: 'home';

if ( empty( $bn_post ) || empty( $bn_post['id'] ) ) {
	return;
}

// ── Post meta ──────────────────────────────────────────────────────────────────
$bn_post_id     = absint( $bn_post['id'] );
$bn_post_type   = $bn_post['type'] ?? 'text';
$bn_space_id    = absint( $bn_post['space_id'] ?? 0 );
$post_author_id = absint( $bn_post['user_id'] ?? 0 );
$post_content   = wp_specialchars_decode( $bn_post['content'] ?? '', ENT_QUOTES );
$post_privacy   = $bn_post['privacy'] ?? 'public';
$post_privacy   = in_array( $post_privacy, array( 'public', 'followers', 'connections', 'space_members', 'private' ), true )
	? $post_privacy
	: 'public';
$is_pinned      = ! empty( $bn_post['is_pinned'] );
// The "Pinned" badge is surface-relative: a post is pinned to a member's PROFILE
// strip or a SPACE strip, never to the global home/explore/single/bookmarks feed.
// The raw is_pinned column travels with every hydrated row, so without this gate a
// profile- or space-pinned post shows "Pinned" wherever it also appears. Keep the
// raw is_pinned for the pin/unpin action + options menu; gate only the display.
$show_pin_badge  = $is_pinned && in_array( $context, array( 'profile', 'space' ), true );
$is_announcement = ! empty( $bn_post['is_announcement'] );
$edited_at       = $bn_post['edited_at'] ?? null;
$created_at      = $bn_post['created_at'] ?? '';
$reaction_count  = absint( $bn_post['reaction_count'] ?? 0 );
$comment_count   = absint( $bn_post['comment_count'] ?? 0 );
$share_count     = absint( $bn_post['share_count'] ?? 0 );

// Top-3 reaction types for the engagement-summary chip strip (v2 prototype
// pattern: `<emoji> 24 · <emoji> 12 · <emoji> 8`). Skipped entirely when
// the post has no reactions — no query overhead on engagement-less posts.
// Ordering + counts both come from ReactionService (cached): top_reactions()
// gives the canonical DESC order, get_counts() the per-slug totals — they share
// the same cache key, so this is one query the first time and cache hits after.
$top_reactions = array();
if ( $reaction_count > 0 && $bn_post_id > 0 ) {
	$bn_reaction_svc    = buddynext_service( 'reactions' );
	$bn_reaction_order  = $bn_reaction_svc->top_reactions( 'post', $bn_post_id, 3 );
	$bn_reaction_counts = $bn_reaction_svc->get_counts( 'post', $bn_post_id );
	foreach ( $bn_reaction_order as $bn_reaction_slug ) {
		$top_reactions[] = array(
			'slug'  => sanitize_key( (string) $bn_reaction_slug ),
			'count' => (int) ( $bn_reaction_counts[ $bn_reaction_slug ] ?? 0 ),
		);
	}
}
if ( ! function_exists( 'bn_post_card_to_array' ) ) {
	/**
	 * Normalise a JSON-or-array column into an array.
	 *
	 * The bn_posts table stores media_ids and link_meta as JSON strings. Some
	 * callers decode them before rendering, the feed query does not — so accept
	 * either and decode a string here, otherwise a posted link preview rendered as
	 * an empty shell (title/description/thumbnail all missing).
	 *
	 * @param mixed $value Raw column value (JSON string or already-decoded array).
	 * @return array<mixed>
	 */
	function bn_post_card_to_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}
}
$media_ids    = bn_post_card_to_array( $bn_post['media_ids'] ?? null );
$link_url     = $bn_post['link_url'] ?? '';
$link_meta    = bn_post_card_to_array( $bn_post['link_meta'] ?? null );
$poll_options = is_array( $bn_post['poll_options'] ?? null ) ? $bn_post['poll_options'] : array();

// Content warning.
$has_cw      = ! empty( $bn_post['content_warning'] );
$cw_type_raw = $bn_post['content_warning_type'] ?? '';
$cw_type     = in_array( $cw_type_raw, array( 'nsfw', 'spoilers', 'violence', 'language' ), true ) ? $cw_type_raw : '';

// ── Shared post (type='share' only) ─────────────────────────────────────────────
// Hydrated via PostService::get() (the same accessor the REST controller and
// single-post template use), so the embed reads canonical, decoded fields — no
// raw SELECT, no JSON-decode workaround. filter_visible() gates it to published
// posts the viewer may actually see (it enforces published-status, blocks,
// secret-space, followers-only, private and author-suspension), so an embed for
// a removed or hidden original collapses to the "no longer available" fallback.
$shared_post    = null;
$shared_post_id = absint( $bn_post['shared_post_id'] ?? 0 );
if ( 'share' === $bn_post_type && $shared_post_id > 0 ) {
	$bn_post_service = new PostService();
	$bn_shared_ok    = in_array( $shared_post_id, $bn_post_service->filter_visible( array( $shared_post_id ), $current_user_id ), true );
	if ( $bn_shared_ok ) {
		$shared_post = $bn_post_service->get( $shared_post_id );
	}
}

// ── Author ─────────────────────────────────────────────────────────────────────
$author       = get_userdata( $post_author_id );
$display_name = $author ? $author->display_name : __( 'Community Member', 'buddynext' );
$username     = $author ? $author->user_nicename : '';
$avatar_url   = get_avatar_url( $post_author_id, array( 'size' => 68 ) );
$profile_link = $post_author_id > 0 ? PageRouter::profile_url( $post_author_id ) : '#';

// Initials fallback — shared canonical helper (supersedes the inline copy).
$initials = AvatarService::initials_for( (string) $display_name );

// ── Timestamps ─────────────────────────────────────────────────────────────────
// Relative times come from the canonical buddynext_time_ago() helper (UTC-anchored).
$post_time    = buddynext_time_ago( $created_at );
$edited_label = $edited_at ? esc_html__( '(edited)', 'buddynext' ) : '';

// ── Permissions ────────────────────────────────────────────────────────────────
$is_own_post = ( $current_user_id > 0 && $current_user_id === $post_author_id );

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
$is_admin = ( $current_user_id > 0 && user_can( $current_user_id, 'manage_options' ) );

// The Edit affordance must respect the same window the REST update path enforces
// (PostService::update): a non-admin author may only edit within
// buddynext_post_edit_window minutes of posting. Hiding the menu item once the
// window closes keeps the UI honest instead of showing Edit and failing with a
// 403 on click. 0 = unlimited; admins are exempt (mirrors the server check).
//
// A post nobody has been allowed to read yet is exempt from the window, exactly as
// PostService::update is: the window guards against rewriting history members have
// already read. Both halves ask PostService::is_pre_publication() rather than each
// restating the list - restating it is why they disagreed, the server testing only
// 'scheduled' behind a variable named $is_pending and this template mirroring the
// same mistake, so a post held for moderation lost its Edit control while it waited.
$bn_is_unpublished  = \BuddyNext\Feed\PostService::is_pre_publication( (string) ( $bn_post['status'] ?? 'published' ) );
$within_edit_window = true;
if ( ! $is_admin && ! $bn_is_unpublished ) {
	$edit_window = (int) get_option( 'buddynext_post_edit_window', 60 );
	if ( $edit_window > 0 && '' !== (string) $created_at ) {
		$created_ts         = (int) strtotime( (string) $created_at . ' UTC' );
		$within_edit_window = $created_ts > 0 && ( time() - $created_ts ) <= $edit_window * MINUTE_IN_SECONDS;
	}
}
$can_edit = ( $is_own_post && $within_edit_window ) || $is_admin;
// Mirror the server (PostController::delete_post): deleting your own post is
// always allowed; deleting anyone else's requires buddynext-feed/delete-any-post.
// buddynext_can() already grants that to WP admins (manage_options bypass) AND
// community moderators, so the affordance now shows for mods, not just admins.
$can_delete = $is_own_post || ( $current_user_id > 0 && buddynext_can( $current_user_id, 'buddynext-feed/delete-any-post' ) );

/*
 * Pin is CONTEXTUAL, and the card already knew that two lines below: the pin
 * BADGE renders only in the profile and space feeds, because that is where
 * FeedService surfaces pins ("Contextual pins are NOT floated here … Pins now
 * surface only in their own context (profile / space feeds)").
 *
 * The ACTION never got the same rule. In the global feed a member saw a bare
 * "Pin" on their own post, which reads as pinning to the top of the whole
 * community. It does not: the pin lands on their profile, invisible from the
 * surface where they clicked it — no badge appears, and the feed order does not
 * change. An action whose entire effect is elsewhere should be offered where the
 * effect is.
 *
 * Same context list as $show_pin_badge below, deliberately: one rule, so the
 * control and its badge cannot disagree about where pinning means something.
 */
$can_pin    = ( $is_own_post || $is_admin ) && in_array( $context, array( 'profile', 'space' ), true );
$can_report = ( $current_user_id > 0 && ! $is_own_post );

// Reactions are a site-owner-toggleable feature (Settings → Features, default on).
// When the owner disables it the React button + emoji picker and the engagement
// reaction-summary chips must not render — the canonical flag is the
// FeatureRegistry 'reactions' feature (the REST toggle path enforces the same gate).
$bn_reactions_enabled = ! function_exists( 'buddynext_service' )
	|| ! is_object( buddynext_service( 'features' ) )
	|| buddynext_service( 'features' )->is_enabled( 'reactions' );
$can_react            = ( $current_user_id > 0 && $bn_reactions_enabled );

// Comments are a site-owner-toggleable feature (Settings → Features, default on).
// When the owner disables it the Comment button, the comment composer, and the
// thread expand region must not render — the canonical flag is the FeatureRegistry
// 'comments' feature (the REST write paths enforce the same gate).
$bn_comments_enabled = ! function_exists( 'buddynext_service' )
	|| ! is_object( buddynext_service( 'features' ) )
	|| buddynext_service( 'features' )->is_enabled( 'comments' );
$can_comment         = ( $current_user_id > 0 && $bn_comments_enabled );

// Re-shares and bookmarks are site-owner toggles (BuddyNext → Social). When the
// owner disables a feature the corresponding action control must disappear, not
// just no-op — both default ON when the option is unset.
$can_share    = ( $current_user_id > 0 && buddynext_feature_enabled( 'shares' ) );
$can_bookmark = ( $current_user_id > 0 && buddynext_feature_enabled( 'bookmarks' ) );

// A post that is not published yet has nothing to engage with. React, Comment,
// Share and Save render on the author's own Scheduled and Pending tabs, and none
// of them makes sense there: nobody else can see the post, so a share reaches an
// empty audience and a comment sits under content that may never publish - or,
// on a held post, may be rejected with the comment still attached to it.
//
// Same principle the Edit control already follows a few lines up: show the
// affordance only where the action is real. The server half is closed too as of
// 255661bd: visibility_error() gates on publication state and every engagement
// write endpoint calls it, so hiding these controls now agrees with what the REST
// routes actually do rather than being cosmetic.
if ( $bn_is_unpublished ) {
	$can_react    = false;
	$can_comment  = false;
	$can_share    = false;
	$can_bookmark = false;
}

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
$poll_closed        = false;
$poll_end_date      = '';
if ( 'poll' === $bn_post_type && ! empty( $poll_options ) ) {
	// Poll deadline lives on the option rows (same value on each). Closed when
	// that UTC deadline is in the past — voting is then disabled in the UI and
	// rejected by PollService::vote().
	$poll_end_date = (string) ( $poll_options[0]['end_date'] ?? '' );
	if ( '' !== $poll_end_date ) {
		$poll_closed = strtotime( $poll_end_date . ' UTC' ) <= time();
	}
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
if ( $current_user_id > 0 && $bn_reactions_enabled ) {
	$my_reaction_type = buddynext_service( 'reactions' )->get_user_reaction( $current_user_id, 'post', $bn_post_id );
}

// ── User's bookmark state ──────────────────────────────────────────────────────
$is_bookmarked = false;
if ( $current_user_id > 0 ) {
	$is_bookmarked = buddynext_service( 'bookmarks' )->is_bookmarked( $current_user_id, $bn_post_id );
}

// ── User's report state ────────────────────────────────────────────────────────
// When the viewer has already reported this post the action menu surfaces a
// disabled "Reported" item instead of re-offering Report (the server rejects
// duplicates via a UNIQUE KEY; this keeps the UI honest). Resolved per reportable
// post via an index seek on that key — same per-viewer pattern as $is_bookmarked,
// and only when $can_report is already true (logged in + not the author).
$has_reported = $can_report
	&& buddynext_service( 'moderation' )->user_has_reported( $current_user_id, 'post', $bn_post_id );

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
// Strip a leading "www." PREFIX. This was ltrim( $host, 'www.' ), which takes a
// character MASK, not a prefix: it ate every leading w and . until it hit
// something else, so wbcomdesigns.com rendered as "bcomdesigns.com" and
// wordpress.org as "ordpress.org". Any domain starting with w loses letters.
$link_domain = $link_domain ? (string) preg_replace( '/^www\./i', '', (string) $link_domain ) : '';

// ── Article CSS classes ────────────────────────────────────────────────────────
$card_classes = array( 'bn-post-card' );
if ( $is_announcement ) {
	$card_classes[] = 'bn-post-card--announcement';
}
if ( $show_pin_badge ) {
	$card_classes[] = 'bn-post-card--pinned';
}
if ( 'poll' === $bn_post_type ) {
	$card_classes[] = 'bn-post-card--poll';
}

$card_class_attr = implode( ' ', array_map( 'sanitize_html_class', $card_classes ) );

// ── Dead-source reshare ─────────────────────────────────────────────────────────
// The original is gone (deleted, hidden, blocked, or its author suspended), so
// `filter_visible()` returned nothing and the embed falls back to "no longer
// available". Rendering that as a FULL card — byline, quote block, React /
// Comment / Share / Save — advertises content that does not exist and, at a few
// per screen, hollows out the feed.
//
// The distinction that matters is whether the SHARER wrote anything. Collapsing
// every dead reshare to one line, as first proposed, would delete a member's own
// writing from the feed along with the dead quote — their commentary is real
// content and the only reason the post still has a reason to exist. So:
//
// With commentary, the card stays and only the quote block collapses, via the
// compact notice in parts/post-body.php. With nothing but the quote, the card
// has no content left: one muted line and no action bar, because resharing or
// reacting to a tombstone would only propagate it.
$bn_dead_share   = 'share' === $bn_post_type && $shared_post_id > 0 && null === $shared_post;
$bn_hollow_share = $bn_dead_share
	&& '' === trim( wp_strip_all_tags( (string) $post_content ) )
	&& empty( $media_ids )
	&& '' === (string) $link_url
	&& empty( $poll_options );

/**
 * Whether to drop a dead-source reshare from the feed entirely.
 *
 * Default false — collapse to a tombstone, so the sharer's action stays legible
 * and the feed does not silently lose posts. An owner who would rather they
 * vanish returns true; this is a presentation preference with two defensible
 * answers, not a correctness question.
 *
 * @since 1.1.6
 *
 * @param bool  $hide     Whether to hide the reshare.
 * @param array $bn_post  The reshare row.
 */
if ( $bn_dead_share && (bool) apply_filters( 'buddynext_hide_dead_reshares', false, $bn_post ) ) {
	return;
}

?>
<?php if ( $bn_hollow_share ) : ?>
<article class="bn-post-card bn-post-card--tombstone">
	<span class="bn-post-card__tombstone-icon" aria-hidden="true"><?php buddynext_icon( 'share' ); ?></span>
	<p class="bn-post-card__tombstone-text">
		<?php
		printf(
			/* translators: %s: name of the member who shared the post. */
			esc_html__( '%s shared a post that is no longer available.', 'buddynext' ),
			'<a href="' . esc_url( $profile_link ) . '">' . esc_html( $display_name ) . '</a>'
		);
		?>
	</p>
	<time class="bn-post-card__tombstone-time" datetime="<?php echo esc_attr( $created_at ); ?>"><?php echo esc_html( $post_time ); ?></time>
</article>
	<?php
	return;
	endif;
?>
<article
	class="<?php echo esc_attr( $card_class_attr ); ?>"
	data-wp-interactive="buddynext/post-card"
	data-wp-class--bn-post-card--pinned="state.pinBadgeVisible"
		data-wp-init="callbacks.initPostCard"
	data-wp-on-document--click="actions.closePopups"
	data-wp-on-document--keydown="actions.closePopupsOnEscape"
	data-wp-context='
	<?php
		echo wp_json_encode(
			array(
				'postId'            => $bn_post_id,
				'spaceId'           => $bn_space_id,
				'authorId'          => $post_author_id,
				'currentUserId'     => $current_user_id,
				'postType'          => $bn_post_type,
				'showContent'       => ! $has_cw,
				// Raw pin state (drives the pin/unpin action + options menu label);
				// showPinBadge gates whether the "Pinned" label is shown on this
				// surface. The badge is visible only when both are true — so pinning
				// a post from the home feed never surfaces the label there.
				'isPinned'          => $is_pinned,
				'showPinBadge'      => in_array( $context, array( 'profile', 'space' ), true ),
				'bookmarked'        => $is_bookmarked,
				'hasReported'       => $has_reported,
				'reactionType'      => $my_reaction_type,
				// Translatable label shown on the React button: the current
				// reaction's name when reacted, else the default "React". Kept in
				// sync with the icon by the post-card store so picking a reaction
				// updates the label too (data-wp-text="state.reactionLabel").
				'reactionLabel'     => ( $my_reaction_type && class_exists( '\BuddyNext\Reactions\ReactionService' ) )
					? \BuddyNext\Reactions\ReactionService::label( (string) $my_reaction_type )
					: __( 'React', 'buddynext' ),
				'reactDefaultLabel' => __( 'React', 'buddynext' ),
				'reactNonce'        => $react_nonce,
				'shareNonce'        => $share_nonce,
				'bookmarkNonce'     => $bookmark_nonce,
				'reportNonce'       => $report_nonce,
				'dismissNonce'      => $dismiss_nonce,
				'pollNonce'         => $poll_nonce,
				'pollOptions'       => $poll_options_ctx,
				'pollVotedOptionId' => $my_voted_option_id,
				'pollTotalVotes'    => $poll_total_votes,
				// The permalink opens with its thread EXPANDED; every other surface
				// starts closed.
				//
				// /p/{id}/ is the page whose entire purpose is the conversation, and
				// "Someone commented on your post" notifications deep-link straight
				// to it (NotificationMessageService resolves to PageRouter::post_url()).
				// Landing there and seeing zero comments — with the replies you were
				// just told about hidden behind a button — is the opposite of what the
				// notification promised, and of what every comparable product does with
				// a post permalink.
				//
				// This was previously hardcoded false on every surface because seeding
				// 'single' open relied on the auto-load-on-mount path, which raced
				// empty on a freshly loaded permalink. That retreat removed the
				// behaviour rather than fixing the race, and left initPostCard()
				// unreachable. The mount path now defers a tick and delegates to the
				// same loader the click path uses, so there is one loader and no race.
				'commentsOpen'      => 'single' === $context,
				'commentCount'      => $comment_count,
				'shareCount'        => $share_count,
				'shareShared'       => false,
				// Reactor "who reacted" popover — SSR-present, toggled
				// reactively (state.reactorsHidden). reactionCount seeds the
				// popover heading before the fetched list resolves.
				'reactorsOpen'      => false,
				'reactorsLoaded'    => false,
				'reactionCount'     => $reaction_count,
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

	<?php if ( ! $is_announcement ) : ?>
		<?php // Always in the DOM so the label can appear/disappear reactively when a member pins/unpins without a reload; hidden bound to context.isPinned. ?>
		<div class="bn-post-card__pin-label" aria-label="<?php esc_attr_e( 'Pinned post', 'buddynext' ); ?>" data-wp-bind--hidden="!state.pinBadgeVisible"<?php echo $show_pin_badge ? '' : ' hidden'; ?>>
			<?php buddynext_icon( 'pin' ); ?> <?php esc_html_e( 'Pinned', 'buddynext' ); ?>
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
			'degree'            => $byline_degree,
			'show_follow'       => $byline_show_follow,
			'created_at'        => $created_at,
			'post_time'         => $post_time,
			'edited_label'      => $edited_label,
			'privacy_label'     => $privacy_label,
			'privacy_icon'      => $privacy_icon,
			'profile_link'      => $profile_link,
			'options_menu_args' => array(
				'bn_post'      => $bn_post,
				'bn_post_id'   => $bn_post_id,
				'can_edit'     => $can_edit,
				'can_pin'      => $can_pin,
				'can_report'   => $can_report,
				'has_reported' => $has_reported,
				'can_delete'   => $can_delete,
				'is_pinned'    => $is_pinned,
			),
		)
	);

	// Scheduled posts (the owner's "Scheduled" profile tab) show WHEN they will
	// publish — the byline timestamp is the creation time, not the useful figure
	// here. scheduled_at is stored in UTC; wp_date() renders it in the site's zone.
	// Narrower than $bn_is_unpublished on purpose: a PENDING post is unpublished
	// too, but it has no publish time to show - it publishes when a moderator says
	// so, not on a clock.
	$bn_pc_is_scheduled = 'scheduled' === (string) ( $bn_post['status'] ?? '' );
	$bn_pc_scheduled_at = (string) ( $bn_post['scheduled_at'] ?? '' );
	if ( $bn_pc_is_scheduled && '' !== $bn_pc_scheduled_at ) {
		$bn_pc_sched_ts = strtotime( $bn_pc_scheduled_at . ' UTC' );
		if ( $bn_pc_sched_ts ) {
			$bn_pc_sched_fmt = wp_date(
				(string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ),
				$bn_pc_sched_ts
			);
			?>
			<div class="bn-post-card__scheduled">
				<?php buddynext_icon( 'clock' ); ?>
				<span>
				<?php
					/* translators: %s: formatted scheduled publish date and time. */
					echo esc_html( sprintf( __( 'Scheduled for %s', 'buddynext' ), (string) $bn_pc_sched_fmt ) );
				?>
				</span>
			</div>
			<?php
		}
	}

	// Held for approval (the owner's "Pending" tab). Without this the card is
	// indistinguishable from a published post, and the author is left to guess
	// why it never reached the feed - which is the state the Pending tab exists
	// to end (Basecamp 10239861865). Only the author ever renders this: the
	// panel behind it is owner-only, and a pending post is not in anyone else's
	// feed to begin with.
	if ( 'pending' === (string) ( $bn_post['status'] ?? '' ) ) {
		?>
		<div class="bn-post-card__pending">
			<?php buddynext_icon( 'shield' ); ?>
			<span><?php esc_html_e( 'Waiting for a moderator to review this post.', 'buddynext' ); ?></span>
		</div>
		<?php
	}

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
			// The body needs to know whether it is being PREVIEWED in a feed or
			// READ on its own page — those want opposite things from a long post.
			'context'           => $context,
			'link_preview'      => array(
				'url'    => $link_url,
				'title'  => $link_title,
				'desc'   => $link_desc,
				'thumb'  => $link_thumb,
				'domain' => $link_domain,
			),
			'link_meta'         => $link_meta,
			'poll_data'         => array(
				'options'            => $poll_options,
				'total_votes'        => $poll_total_votes,
				'my_voted_option_id' => $my_voted_option_id,
				'closed'             => $poll_closed,
				'end_date'           => $poll_end_date,
			),
			'media_attachments' => $media_ids,
			'is_pinned'         => $is_pinned,
			'has_cw'            => $has_cw,
			'shared_post'       => $shared_post,
		)
	);

	// The reaction-summary chip strip shows existing reactions; suppress it when
	// the site owner has disabled the Reactions feature so no reaction surface
	// remains on the card.
	if ( $bn_reactions_enabled ) {
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
	}

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
	<?php if ( $bn_comments_enabled ) : ?>
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
	<?php endif; ?>

</article>
