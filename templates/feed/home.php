<?php
/**
 * BuddyNext home feed template (v2 inner).
 *
 * Personalised activity feed for the logged-in user. Renders inside the
 * shell main column (`<main class="bn-app__main">` — see
 * templates/shell/hub-shell.php) — this inner template does NOT own
 * the rail or the 2-column page grid. Sidebar widgets are
 * registered on the `buddynext_right_sidebar` action; the shell auto
 * renders the right column whenever the action has callbacks.
 *
 * Features: post composer, announcement banner, cursor pagination,
 * trending-hashtags sidebar widget, suggested-spaces sidebar widget.
 * Guests are redirected to the auth page.
 *
 * Overridable: copy to {theme}/buddynext/feed/home.php.
 *
 * REST endpoint: GET buddynext/v1/feed?scope=home
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\FeedService;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$current_user_id = get_current_user_id();

global $wpdb;

$posts_table     = $wpdb->prefix . 'bn_posts';
$follows_table   = $wpdb->prefix . 'bn_follows';
$hashtags_table  = $wpdb->prefix . 'bn_hashtags';
$spaces_table    = $wpdb->prefix . 'bn_spaces';
$space_mem_table = $wpdb->prefix . 'bn_space_members';
$user_meta_table = $wpdb->usermeta;

$bn_per_page = 15;

// The Spaces filter tab + its feed only make sense while the Spaces feature is
// enabled; when the owner turns it off we drop the tab and treat a stale
// ?filter=spaces as the default so the activity page has no dead Spaces UI.
$bn_spaces_on = function_exists( 'buddynext_service' )
	&& is_object( buddynext_service( 'features' ) )
	&& buddynext_service( 'features' )->is_enabled( 'spaces' );

// Filter tab — for-you (default) | following | spaces | network.
$allowed_filters = array( 'for-you', 'following', 'network' );
if ( $bn_spaces_on ) {
	$allowed_filters[] = 'spaces';
}
$raw_filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'for-you'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_filter  = in_array( $raw_filter, $allowed_filters, true ) ? $raw_filter : 'for-you';

// Cursor is base64( "created_at|id" ) — same format as FeedService::encode_cursor().
// Decode defensively; fall back to no cursor (first page) on any invalid input.
$raw_cursor     = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$decoded_cursor = null;
if ( '' !== $raw_cursor ) {
	$raw_decoded = base64_decode( $raw_cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( false !== $raw_decoded ) {
		$cursor_parts = explode( '|', $raw_decoded, 2 );
		if ( 2 === count( $cursor_parts ) && '' !== $cursor_parts[0] && ctype_digit( $cursor_parts[1] ) ) {
			$decoded_cursor = array(
				'created_at' => $cursor_parts[0],
				'id'         => (int) $cursor_parts[1],
			);
		}
	}
}

// ── Suspended / shadow-banned exclusion ────────────────────────────────────
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$suspended_ids = $wpdb->get_col(
	"SELECT user_id FROM {$user_meta_table} WHERE meta_key = 'bn_suspended' AND meta_value = '1'"
);
$shadow_ids    = $wpdb->get_col(
	"SELECT user_id FROM {$user_meta_table} WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$excluded_ids = array_unique(
	array_filter(
		array_map( 'intval', array_merge( $suspended_ids ?? array(), $shadow_ids ?? array() ) ),
		fn( int $id ) => $id !== $current_user_id // Viewer can always see their own posts.
	)
);

$exclusion_sql = '';
if ( ! empty( $excluded_ids ) ) {
	$placeholders  = implode( ',', array_fill( 0, count( $excluded_ids ), '%d' ) );
	$exclusion_sql = $wpdb->prepare( " AND p.user_id NOT IN ({$placeholders})", $excluded_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ── Pinned announcement ─────────────────────────────────────────────────────
// Dismissals live in user_meta (FeedService::DISMISSED_ANNOUNCEMENTS_META) so
// the REST dismiss endpoint and PHP render share one source of truth.
$dismissed_ids = FeedService::dismissed_announcement_ids( $current_user_id );
$exclude_sql   = '';
$exclude_args  = array();
if ( ! empty( $dismissed_ids ) ) {
	$placeholders = implode( ',', array_fill( 0, count( $dismissed_ids ), '%d' ) );
	$exclude_sql  = " AND p.id NOT IN ({$placeholders})";
	$exclude_args = $dismissed_ids;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$announcement_sql = "SELECT p.id, p.user_id, p.content, p.created_at
		   FROM {$posts_table} p
		  WHERE p.type = 'announcement'
			AND p.is_announcement = 1
			AND p.status = 'published'
			AND (p.site_pin_expires_at IS NULL OR p.site_pin_expires_at > NOW()){$exclude_sql}
		  ORDER BY p.created_at DESC
		  LIMIT %d";
$announcement     = $wpdb->get_row(
	empty( $exclude_args )
		? $wpdb->prepare( $announcement_sql, 1 )
		: $wpdb->prepare( $announcement_sql, array_merge( $exclude_args, array( 1 ) ) )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$show_announcement = null !== $announcement;

// ── Home feed posts ─────────────────────────────────────────────────────────
// Sources:
// 1. Viewer's own posts (any privacy).
// 2. Public/followers posts from followed users.
// 3. Published posts from spaces the viewer has actively joined.
// 4. Published posts containing a hashtag the viewer follows.
// Cursor: compound keyset on (created_at DESC, id DESC) — base64("created_at|id").
// $exclusion_sql is built above via $wpdb->prepare() with %d placeholders — safe.
$cursor_sql    = '';
$cursor_params = array();
if ( null !== $decoded_cursor ) {
	$cursor_sql    = 'AND (p.created_at < %s OR (p.created_at = %s AND p.id < %d))';
	$cursor_params = array( $decoded_cursor['created_at'], $decoded_cursor['created_at'], $decoded_cursor['id'] );
}

$hashtag_follows_table = $wpdb->prefix . 'bn_post_hashtags';
$ht_follows_table      = $wpdb->prefix . 'bn_hashtag_follows';

// All filters — including the default `for-you` view — route through the
// container-bound feed service so the SQL stays in one place AND any rebind
// (notably the Pro AiRankedFeedService, which fires buddynext_feed_query_args /
// buddynext_feed_order_by) re-ranks the first SSR paint. Previously the
// `for-you` default ran inline chronological SQL here and bypassed the Pro
// override entirely, so the AI toggle had no effect on the page a member opens.
//
// $feed_service is resolved defensively: if the container is unavailable (e.g.
// the front-end isolation harness strips the bootstrap), the inline fallback
// query below serves the chronological feed rather than fataling.
$feed_service_filtered = false;
$feed_posts            = array();
$next_cursor           = '';
$has_more              = false;

$bn_feed_service_obj = function_exists( 'buddynext_service' ) ? buddynext_service( 'feed' ) : null;

if ( $bn_feed_service_obj instanceof FeedService ) {
	$feed_service_filtered = true;
	$service_result        = $bn_feed_service_obj->home_feed(
		$current_user_id,
		'' !== $raw_cursor ? $raw_cursor : null,
		$bn_per_page,
		$bn_filter
	);
	$feed_posts            = array();
	foreach ( (array) ( $service_result['items'] ?? array() ) as $hydrated ) {
		$feed_posts[] = (object) array(
			'id'                   => (int) ( $hydrated['id'] ?? 0 ),
			'user_id'              => (int) ( $hydrated['user_id'] ?? 0 ),
			'space_id'             => isset( $hydrated['space_id'] ) ? (int) $hydrated['space_id'] : null,
			'shared_post_id'       => isset( $hydrated['shared_post_id'] ) ? (int) $hydrated['shared_post_id'] : null,
			'content'              => (string) ( $hydrated['content'] ?? '' ),
			'type'                 => (string) ( $hydrated['type'] ?? 'text' ),
			'privacy'              => (string) ( $hydrated['privacy'] ?? 'public' ),
			'media_ids'            => isset( $hydrated['media_ids'] ) ? wp_json_encode( $hydrated['media_ids'] ) : null,
			'link_url'             => $hydrated['link_url'] ?? null,
			'link_meta'            => isset( $hydrated['link_meta'] ) ? wp_json_encode( $hydrated['link_meta'] ) : null,
			'is_pinned'            => (int) ( $hydrated['is_pinned'] ?? 0 ),
			'is_announcement'      => (int) ( $hydrated['is_announcement'] ?? 0 ),
			'content_warning'      => (int) ( $hydrated['content_warning'] ?? 0 ),
			'content_warning_type' => $hydrated['content_warning_type'] ?? null,
			'reaction_count'       => (int) ( $hydrated['reaction_count'] ?? 0 ),
			'comment_count'        => (int) ( $hydrated['comment_count'] ?? 0 ),
			'share_count'          => (int) ( $hydrated['share_count'] ?? 0 ),
			'edited_at'            => $hydrated['edited_at'] ?? null,
			'created_at'           => (string) ( $hydrated['created_at'] ?? '' ),
			'updated_at'           => $hydrated['updated_at'] ?? null,
		);
	}
	$next_cursor = (string) ( $service_result['next_cursor'] ?? '' );
	$has_more    = '' !== $next_cursor;
}

if ( ! $feed_service_filtered ) :
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$feed_posts = $wpdb->get_results(
		$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			"SELECT p.id, p.user_id, p.space_id, p.shared_post_id, p.content, p.type, p.privacy,
				p.media_ids, p.link_url, p.link_meta,
				p.is_pinned, p.is_announcement, p.content_warning, p.content_warning_type,
				p.reaction_count, p.comment_count, p.share_count,
				p.edited_at, p.created_at, p.updated_at
		   FROM {$posts_table} p
		  WHERE p.status = 'published'
			AND (p.scheduled_at IS NULL OR p.scheduled_at <= UTC_TIMESTAMP())
			AND (
				  p.user_id = %d
			   OR (
					p.user_id IN (
					  SELECT f.following_id
						FROM {$follows_table} f
					   WHERE f.follower_id = %d
					)
					AND p.privacy IN ('public','followers')
				  )
			   OR p.space_id IN (
					SELECT m.space_id
					  FROM {$space_mem_table} m
					 WHERE m.user_id = %d AND m.status = 'active'
				  )
			   OR p.id IN (
					SELECT ph.post_id
					  FROM {$hashtag_follows_table} ph
					 WHERE ph.object_type = 'post'
					   AND ph.hashtag_id IN (
							 SELECT hf.hashtag_id
							   FROM {$ht_follows_table} hf
							  WHERE hf.user_id = %d
						   )
				  )
				   OR (
						p.privacy = 'public'
						AND (
							p.space_id IS NULL
							OR p.space_id = 0
							OR p.space_id IN (
								SELECT s.id FROM {$spaces_table} s WHERE s.type = 'open'
							)
						)
					  )
				)
			{$exclusion_sql}
			{$cursor_sql}
		  ORDER BY p.created_at DESC, p.id DESC
		  LIMIT %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...array_merge(
				array( $current_user_id, $current_user_id, $current_user_id, $current_user_id ),
				$cursor_params,
				array( $bn_per_page + 1 )
			)
		)
	);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$has_more = count( $feed_posts ) > $bn_per_page;
	if ( $has_more ) {
		array_pop( $feed_posts );
	}

	// Encode next cursor as base64("created_at|id") matching FeedService::encode_cursor().
	if ( $has_more && ! empty( $feed_posts ) ) {
		$last_post   = end( $feed_posts );
		$next_cursor = base64_encode( $last_post->created_at . '|' . $last_post->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
endif; // ! $feed_service_filtered

// ── Tab counts ──────────────────────────────────────────────────────────────
$bn_tab_counts = array(
	'for_you'   => 0,
	'following' => 0,
	'spaces'    => 0,
	'network'   => 0,
);
if ( function_exists( 'buddynext_service' ) ) {
	$bn_feed_service = buddynext_service( 'feed' );
	if ( $bn_feed_service ) {
		$bn_tab_counts = $bn_feed_service->home_feed_counts( $current_user_id );
	}
}

// ── REST nonce + URLs ───────────────────────────────────────────────────────
$rest_nonce  = wp_create_nonce( 'wp_rest' );

// ── Right sidebar widgets ────────────────────────────────────────────────
// Register sidebar widget callbacks on the shared hub-shell action. The shell
// detects via has_action() (after this template's output buffer flushes) and
// renders the right column automatically. The action is registered before any
// output, so the detection in templates/shell/hub-shell.php sees it.
add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id ) {
		buddynext_get_template(
			'partials/sidebar.php',
			array(
				'sidebar_user_id' => $current_user_id,
			)
		);
	}
);

/**
 * Fires before the home feed inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_feed_home_before', $current_user_id );
?>
<div class="bn-feed-stack"
	data-bn-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-bn-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1' ) ); ?>">

	<!-- Post composer -->
	<?php
	buddynext_get_template(
		'partials/composer.php',
		array(
			'space_id'        => null,
			'current_user_id' => $current_user_id,
		)
	);
	?>

	<!-- Home feed filter tabs (For you / Following / Spaces / Network).
		The redundant Home/Explore hub-tab row was removed: Explore lives in the
		left rail and "Home" was just the current page — two full-width tab rows
		for that cost vertical space (worst at 390px) for no IA value. -->
	<!-- Carries the .bn-tabs/.bn-tab primitive so it matches the Home/Explore row
		and every other tab bar (font, focus ring, overflow scroll-fade); the
		.bn-feed-filter-tab* classes + aria-current are kept for the feed-tabs JS. -->
	<div class="bn-tabs bn-feed-filter-tabs"
		role="tablist"
		aria-label="<?php esc_attr_e( 'Filter home feed', 'buddynext' ); ?>"
		data-wp-interactive="buddynext/feed-tabs"
		data-wp-context='
		<?php
		echo esc_attr(
			wp_json_encode(
				array(
					'filter'    => $bn_filter,
					'tabCounts' => array(
						'for-you'   => (int) $bn_tab_counts['for_you'],
						'following' => (int) $bn_tab_counts['following'],
						'spaces'    => (int) $bn_tab_counts['spaces'],
						'network'   => (int) $bn_tab_counts['network'],
					),
					'restUrl'   => rest_url( 'buddynext/v1' ),
					'nonce'     => $rest_nonce,
					'busy'      => false,
				)
			)
		);
		?>
		'>
		<?php
		$filter_tabs = array(
			'for-you'   => array(
				'label' => __( 'For you', 'buddynext' ),
				'count' => (int) $bn_tab_counts['for_you'],
			),
			'following' => array(
				'label' => __( 'Following', 'buddynext' ),
				'count' => (int) $bn_tab_counts['following'],
			),
			'spaces'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'count' => (int) $bn_tab_counts['spaces'],
			),
			'network'   => array(
				'label' => __( 'Network', 'buddynext' ),
				'count' => (int) $bn_tab_counts['network'],
			),
		);
		// Hide the Spaces tab when the feature is disabled (mirrors $allowed_filters).
		if ( ! $bn_spaces_on ) {
			unset( $filter_tabs['spaces'] );
		}
		foreach ( $filter_tabs as $tab_slug => $tab_meta ) :
			$is_active = $tab_slug === $bn_filter;
			$tab_url   = add_query_arg( 'filter', $tab_slug, PageRouter::activity_url() );
			?>
			<a
				class="bn-tab bn-feed-filter-tab"
				role="tab"
				href="<?php echo esc_url( $tab_url ); ?>"
				data-filter="<?php echo esc_attr( $tab_slug ); ?>"
				aria-current="<?php echo $is_active ? 'true' : 'false'; ?>"
				data-wp-on--click="actions.setFilter"
			>
				<span class="bn-tab__label"><?php echo esc_html( $tab_meta['label'] ); ?></span>
				<?php
				if ( $tab_meta['count'] > 0 ) :
					// Cap the badge so a primary feed tab never shows a noisy 4-digit
					// total (e.g. "3,014"); premium feeds cap count chips at 99+.
					$bn_count_label = $tab_meta['count'] > 99 ? '99+' : number_format_i18n( $tab_meta['count'] );
					?>
					<span class="bn-tab__count"><?php echo esc_html( $bn_count_label ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="bn-feed-skeleton"
		hidden
		aria-hidden="true"
		data-bn-feed-skeleton>
		<?php for ( $sk = 0; $sk < 3; $sk++ ) : ?>
			<div class="bn-skeleton-card">
				<span class="bn-skeleton bn-skeleton-avatar"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--title"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--subtitle"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--body"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--body-short"></span>
			</div>
		<?php endfor; ?>
	</div>

	<div class="bn-feed-error"
		role="alert"
		hidden
		data-bn-feed-error>
		<span class="bn-feed-error__icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
		<span class="bn-feed-error__text"><?php esc_html_e( 'Could not load this view. Try again.', 'buddynext' ); ?></span>
		<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-bn-feed-retry>
			<?php esc_html_e( 'Retry', 'buddynext' ); ?>
		</button>
	</div>

	<?php if ( $show_announcement && $announcement ) : ?>
		<div class="bn-announcement"
			data-wp-interactive="buddynext/announcement"
			data-wp-context='{"announcementId":<?php echo (int) $announcement->id; ?>}'>
			<span class="bn-announcement__icon" aria-hidden="true"><?php buddynext_icon( 'megaphone' ); ?></span>
			<div class="bn-announcement__body">
				<?php echo wp_kses_post( $announcement->content ); ?>
			</div>
			<button
				class="bn-announcement__dismiss"
				data-wp-on--click="actions.dismiss"
				aria-label="<?php esc_attr_e( 'Dismiss announcement', 'buddynext' ); ?>">
				&times;
			</button>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $feed_posts ) ) : ?>
		<div class="bn-feed-list" role="feed" aria-label="<?php esc_attr_e( 'Home feed', 'buddynext' ); ?>">
			<?php foreach ( $feed_posts as $row ) : ?>
				<?php
				$home_post = array(
					'id'                   => (int) $row->id,
					'user_id'              => (int) $row->user_id,
					'type'                 => $row->type ?? 'text',
					'content'              => $row->content ?? '',
					'privacy'              => $row->privacy ?? 'public',
					'space_id'             => isset( $row->space_id ) ? (int) $row->space_id : null,
					'shared_post_id'       => isset( $row->shared_post_id ) ? (int) $row->shared_post_id : null,
					'media_ids'            => isset( $row->media_ids ) ? json_decode( (string) $row->media_ids, true ) : null,
					'link_url'             => $row->link_url ?? null,
					'link_meta'            => $row->link_meta ?? null,
					'poll_options'         => array(),
					'is_pinned'            => (int) ( $row->is_pinned ?? 0 ),
					'is_announcement'      => (int) ( $row->is_announcement ?? 0 ),
					'content_warning'      => (bool) ( $row->content_warning ?? false ),
					'content_warning_type' => $row->content_warning_type ?? null,
					'reaction_count'       => absint( $row->reaction_count ?? 0 ),
					'comment_count'        => absint( $row->comment_count ?? 0 ),
					'share_count'          => absint( $row->share_count ?? 0 ),
					'edited_at'            => $row->edited_at ?? null,
					'created_at'           => $row->created_at ?? '',
					'updated_at'           => $row->updated_at ?? null,
				);
				// Hydrate poll options for poll-type posts via the one shared path
				// (PostService::attach_poll_options) so the home, hashtag, and
				// REST-pagination feeds can never drift apart again.
				$home_post = \BuddyNext\Feed\PostService::attach_poll_options( $home_post );
				buddynext_get_template(
					'partials/post-card.php',
					array(
						'post'            => $home_post,
						'current_user_id' => $current_user_id,
						'context'         => 'home',
					)
				);
				?>
			<?php endforeach; ?>
		</div>

			<?php if ( $has_more && '' !== $next_cursor ) : ?>
				<div
					class="bn-load-more"
					id="bn-infinite-trigger"
					data-bn-infinite-feed="home"
					data-bn-feed-target=".bn-feed-list"
					data-next-cursor="<?php echo esc_attr( $next_cursor ); ?>"
					data-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1/feed/home/page' ) ); ?>"
					data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
					data-filter="<?php echo esc_attr( $bn_filter ); ?>"
					data-per-page="<?php echo esc_attr( (string) $bn_per_page ); ?>"
				>
					<div class="bn-load-more__spinner" hidden aria-live="polite">
						<span class="bn-skeleton bn-load-more__spinner-line"></span>
						<span class="bn-load-more__spinner-text"><?php esc_html_e( 'Loading more posts…', 'buddynext' ); ?></span>
					</div>
					<noscript>
						<a
							href="<?php echo esc_url( add_query_arg( 'cursor', rawurlencode( $next_cursor ), PageRouter::activity_url() ) ); ?>"
							class="bn-btn bn-load-more__btn"
							data-variant="secondary"
						>
							<?php esc_html_e( 'Load more', 'buddynext' ); ?>
						</a>
					</noscript>
				</div>
			<?php else : ?>
				<div class="bn-feed-end" role="status">
					<span class="bn-feed-end__text"><?php esc_html_e( "You've reached the end.", 'buddynext' ); ?></span>
				</div>
			<?php endif; ?>

	<?php else : ?>
		<?php
		$empty_states = array(
			'for-you'   => array(
				'icon'  => 'users',
				'title' => __( 'Your feed is empty', 'buddynext' ),
				'text'  => __( 'Follow members or join spaces to start seeing posts here.', 'buddynext' ),
				'cta'   => __( 'Discover members', 'buddynext' ),
				'url'   => PageRouter::people_url(),
			),
			'following' => array(
				'icon'  => 'follow',
				'title' => __( "You aren't following anyone yet", 'buddynext' ),
				'text'  => __( 'Once you follow people their latest posts will show up here.', 'buddynext' ),
				'cta'   => __( 'Find people to follow', 'buddynext' ),
				'url'   => PageRouter::people_url(),
			),
			'spaces'    => array(
				'icon'  => 'grid',
				'title' => __( 'Join your first space', 'buddynext' ),
				'text'  => __( 'Posts from spaces you join appear here.', 'buddynext' ),
				'cta'   => __( 'Browse spaces', 'buddynext' ),
				'url'   => home_url( '/spaces/' ),
			),
			'network'   => array(
				'icon'  => 'users',
				'title' => __( 'Build your network', 'buddynext' ),
				'text'  => __( 'Send a few connection requests to see posts from your network.', 'buddynext' ),
				'cta'   => __( 'Find people to connect with', 'buddynext' ),
				'url'   => PageRouter::people_url(),
			),
		);
		$empty        = $empty_states[ $bn_filter ] ?? $empty_states['for-you'];
		?>
		<div class="bn-feed-empty" role="status" data-filter="<?php echo esc_attr( $bn_filter ); ?>">
			<div class="bn-feed-empty__icon" aria-hidden="true"><?php buddynext_icon( $empty['icon'] ); ?></div>
			<div class="bn-feed-empty__title">
				<?php echo esc_html( $empty['title'] ); ?>
			</div>
			<p class="bn-feed-empty__text">
				<?php echo esc_html( $empty['text'] ); ?>
			</p>
			<a href="<?php echo esc_url( $empty['url'] ); ?>" class="bn-btn bn-feed-empty__cta" data-variant="primary">
				<?php echo esc_html( $empty['cta'] ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
		</div>
	<?php endif; ?>

	<?php
	buddynext_get_template(
		'partials/share-modal.php',
		array( 'current_user_id' => $current_user_id )
	);
	?>

</div>
<?php
/**
 * Fires after the home feed inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_feed_home_after', $current_user_id );
