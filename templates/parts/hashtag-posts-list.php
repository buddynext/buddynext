<?php
/**
 * BuddyNext template part: hashtag-posts-list.
 *
 * Renders the post-card list for a hashtag feed, OR the Jetonomy
 * bridge-discussion list when no native posts exist (and the
 * `buddynext_hashtag_related_discussions` filter contributes rows).
 * When neither is available the part returns silently so the composer
 * can render the empty-state part instead.
 *
 * Used by: templates/hashtags/feed.php.
 *
 * @package BuddyNext
 *
 * @var array  $hashtag_posts   Optional. Post rows (each with id, user_id, type,
 *                              content, created_at, reaction_count, comment_count,
 *                              share_count). Default [].
 * @var int    $current_user_id Optional. Viewing user ID. Default 0.
 * @var string $hashtag_slug    Optional. Hashtag slug for the bridge filter. Default ''.
 * @var array  $classes         Optional. Extra CSS classes on the list wrapper.
 *
 * Fires:
 *   - do_action( 'buddynext_part_hashtag_posts_list_before', $args )
 *   - do_action( 'buddynext_part_hashtag_posts_list_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_hashtag_posts_list_args',    array $args )
 *   - apply_filters( 'buddynext_part_hashtag_posts_list_classes', array $classes, array $args )
 *   - apply_filters( 'buddynext_hashtag_related_discussions', array $discussions, string $hashtag_slug )
 *     (existing Free filter, retained verbatim for bridge contributions.)
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'hashtag_posts'   => isset( $hashtag_posts ) ? (array) $hashtag_posts : array(),
	'current_user_id' => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'hashtag_slug'    => isset( $hashtag_slug ) ? (string) $hashtag_slug : '',
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_hashtag_posts_list_args', $args );

$bn_posts     = (array) $args['hashtag_posts'];
$bn_viewer_id = (int) $args['current_user_id'];
$bn_slug      = (string) $args['hashtag_slug'];

// Bridge contribution (Jetonomy discussions) — only consulted when there are no native posts.
$bn_bridge_posts = array();
if ( empty( $bn_posts ) ) {
	$bn_bridge_posts = (array) apply_filters( 'buddynext_hashtag_related_discussions', array(), $bn_slug );
}

// Nothing to render — composer should fall through to empty-state.
if ( empty( $bn_posts ) && empty( $bn_bridge_posts ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-feed-list', 'bn-hashtag-feed-list' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_hashtag_posts_list_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_hashtag_posts_list_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" role="feed" aria-label="<?php esc_attr_e( 'Hashtag feed', 'buddynext' ); ?>">
<?php
if ( ! empty( $bn_posts ) ) :
	// Map each row through the canonical PostService::hydrate() — same shape as
	// the home/REST feeds, with poll options, decoded media + link meta, and
	// real privacy. The previous hand-rolled array hardcoded privacy=public and
	// dropped media/link data, so hashtag cards silently differed from the feed.
	$bn_ht_post_service = new \BuddyNext\Feed\PostService();
	foreach ( $bn_posts as $post_row ) :
		$ht_post = $bn_ht_post_service->hydrate( (array) $post_row );
		buddynext_get_template(
			'partials/post-card.php',
			array(
				'post'            => $ht_post,
				'current_user_id' => $bn_viewer_id,
				'context'         => 'home',
			)
		);
	endforeach;
else :
	foreach ( $bn_bridge_posts as $jt_post ) :
		$jt_post    = (object) $jt_post;
		$jt_id      = (int) ( $jt_post->id ?? 0 );
		$jt_title   = (string) ( $jt_post->title ?? '' );
		$jt_author  = (string) ( $jt_post->author_name ?? __( 'Community Member', 'buddynext' ) );
		$jt_uid     = (int) ( $jt_post->author_id ?? 0 );
		$jt_init    = \BuddyNext\Profile\AvatarService::initials_for( $jt_author );
		$jt_avatar  = $jt_uid ? get_avatar_url( $jt_uid, array( 'size' => 72 ) ) : '';
		$jt_replies = absint( $jt_post->reply_count ?? 0 );
		$jt_votes   = (int) ( $jt_post->vote_score ?? 0 );

		// The bridge (JetonomyBridge::get_related_discussions) supplies a ready
		// public URL, so the template needs no jt_* table access.
		$jt_url = ! empty( $jt_post->url )
			? (string) $jt_post->url
			: home_url( '/community/' );
		?>
		<article class="bn-card bn-card-bridge bn-card-bridge--jetonomy" data-interactive>
			<header class="bn-card-bridge__source">
				<span class="bn-badge" data-tone="jetonomy">
					<?php buddynext_icon( 'message-circle' ); ?>
					<?php esc_html_e( 'Discussion', 'buddynext' ); ?>
				</span>
			</header>
			<div class="bn-card-bridge__head">
				<?php if ( $jt_avatar ) : ?>
					<img
						src="<?php echo esc_url( $jt_avatar ); ?>"
						alt=""
						class="bn-avatar"
						data-size="md"
						loading="lazy"
						width="36"
						height="36"
					>
				<?php else : ?>
					<div class="bn-avatar" data-size="md" aria-hidden="true">
						<?php echo esc_html( $jt_init ); ?>
					</div>
				<?php endif; ?>
				<div class="bn-card-bridge__byline">
					<div class="bn-card-bridge__author"><?php echo esc_html( $jt_author ); ?></div>
					<div class="bn-card-bridge__meta"><?php esc_html_e( 'Started a discussion', 'buddynext' ); ?></div>
				</div>
			</div>
			<a class="bn-card-bridge__title" href="<?php echo esc_url( $jt_url ); ?>">
				<?php echo esc_html( $jt_title ); ?>
			</a>
			<footer class="bn-card-bridge__footer">
				<button class="bn-btn" data-variant="ghost" data-size="sm" type="button" data-wp-on--click="actions.voteJt" data-jt-id="<?php echo esc_attr( (string) $jt_id ); ?>" data-direction="up">
					<?php buddynext_icon( 'arrow-up' ); ?>
					<span><?php echo esc_html( (string) max( 0, $jt_votes ) ); ?></span>
				</button>
				<span class="bn-card-bridge__stat">
					<?php buddynext_icon( 'message-circle' ); ?>
					<?php
					printf(
						/* translators: %d: reply count */
						esc_html( _n( '%d reply', '%d replies', $jt_replies, 'buddynext' ) ),
						(int) $jt_replies
					);
					?>
				</span>
				<a class="bn-btn bn-card-bridge__open" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $jt_url ); ?>">
					<span><?php esc_html_e( 'Open', 'buddynext' ); ?></span>
					<?php buddynext_icon( 'arrow-right' ); ?>
				</a>
			</footer>
		</article>
		<?php
	endforeach;
endif;
?>
</div>
<?php
do_action( 'buddynext_part_hashtag_posts_list_after', $args );
