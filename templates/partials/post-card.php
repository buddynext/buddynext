<?php
/**
 * BuddyNext — Post card partial.
 *
 * Reusable post card rendered across home, explore, profile, and space feeds.
 * Receives $post (hydrated array from FeedService/PostService),
 * $current_user_id (int, 0 for guests), and $context
 * (string: home|explore|profile|space) — variables extracted by TemplateLoader::render().
 *
 * Supported post types: text, photo, file, link, poll, announcement,
 *   activity, media, discussion, job, share.
 *
 * Overridable: copy to {theme}/buddynext/partials/post-card.php
 *
 * @package BuddyNext
 * @since   1.0.0
 *
 * @var array    $post            Hydrated post array.
 * @var int       $current_user_id Viewing user ID.
 * @var string    $context         Feed context (home|explore|profile|space).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$bn_post         = $post ?? array();
$current_user_id = absint( $current_user_id ?? 0 );
$context         = in_array( $context ?? '', array( 'home', 'explore', 'profile', 'space' ), true )
	? ( $context )
	: 'home';

if ( empty( $bn_post ) || empty( $bn_post['id'] ) ) {
	return;
}

// ── Post meta ──────────────────────────────────────────────────────────────────
$bn_post_id      = absint( $bn_post['id'] );
$bn_post_type    = $bn_post['type'] ?? 'text';
$post_author_id  = absint( $bn_post['user_id'] ?? 0 );
$post_content    = $bn_post['content'] ?? '';
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
$media_ids       = is_array( $bn_post['media_ids'] ?? null ) ? $bn_post['media_ids'] : array();
$link_url        = $bn_post['link_url'] ?? '';
$link_meta       = is_array( $bn_post['link_meta'] ?? null ) ? $bn_post['link_meta'] : array();
$poll_options    = is_array( $bn_post['poll_options'] ?? null ) ? $bn_post['poll_options'] : array();

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

// Deterministic avatar colour by user ID.
$av_palette   = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );
$avatar_color = $av_palette[ $post_author_id % count( $av_palette ) ];

// Member type badge.
$member_type_slug  = get_user_meta( $post_author_id, 'bn_member_type', true );
$member_type_label = $member_type_slug ? get_user_meta( $post_author_id, 'bn_member_type_label', true ) : '';

// ── Timestamps ─────────────────────────────────────────────────────────────────
/**
 * Format a MySQL datetime as a relative time string.
 *
 * @param string $datetime UTC MySQL datetime.
 * @return string Escaped relative label.
 */
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
$is_admin     = ( $current_user_id > 0 && user_can( $current_user_id, 'manage_options' ) );
$can_edit     = $is_own_post || $is_admin;
$can_delete   = $is_own_post || $is_admin;
$can_pin      = $is_own_post || $is_admin;
$can_report   = ( $current_user_id > 0 && ! $is_own_post );
$can_bookmark = ( $current_user_id > 0 );

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
	'followers'     => __( 'Followers only', 'buddynext' ),
	'connections'   => __( 'Connections only', 'buddynext' ),
	'space_members' => __( 'Space members', 'buddynext' ),
	'private'       => __( 'Only me', 'buddynext' ),
);
$privacy_icons  = array(
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
	data-wp-context='
	<?php
		echo wp_json_encode(
			array(
				'postId'            => $bn_post_id,
				'authorId'          => $post_author_id,
				'currentUserId'     => $current_user_id,
				'postType'          => $bn_post_type,
				'showContent'       => ! $has_cw,
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
				'commentsOpen'      => false,
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
	aria-labelledby="bn-post-author-<?php echo absint( $bn_post_id ); ?>"
>

	<?php if ( $is_announcement ) : ?>
		<!-- Announcement banner bar -->
		<div class="bn-post-card__announcement-bar" role="banner">
			<span class="bn-post-card__ann-badge">
				<?php buddynext_icon( 'megaphone' ); ?> <?php esc_html_e( 'Announcement', 'buddynext' ); ?>
			</span>
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

	<!-- Post header: avatar + author + timestamp + actions -->
	<div class="bn-post-card__header">
		<!-- Avatar -->
		<?php if ( $avatar_url ) : ?>
			<a href="<?php echo esc_url( $profile_link ); ?>" tabindex="-1" aria-hidden="true">
				<img
					src="<?php echo esc_url( $avatar_url ); ?>"
					alt="<?php echo esc_attr( $display_name ); ?>"
					class="bn-post-card__avatar"
					width="40"
					height="40"
					loading="lazy"
				>
			</a>
		<?php else : ?>
			<a href="<?php echo esc_url( $profile_link ); ?>" tabindex="-1" aria-hidden="true">
				<div
					class="bn-post-card__avatar bn-post-card__avatar--initials"
					style="background:<?php echo esc_attr( $avatar_color ); ?>;"
					aria-hidden="true"
				><?php echo esc_html( $initials ); ?></div>
			</a>
		<?php endif; ?>

		<!-- Author info -->
		<div class="bn-post-card__author-wrap">
			<div class="bn-post-card__author-line">
				<a
					id="bn-post-author-<?php echo absint( $bn_post_id ); ?>"
					href="<?php echo esc_url( $profile_link ); ?>"
					class="bn-post-card__author-name"
				><?php echo esc_html( $display_name ); ?></a>

				<?php if ( $username ) : ?>
					<span class="bn-post-card__username">@<?php echo esc_html( $username ); ?></span>
				<?php endif; ?>

				<?php if ( $member_type_label ) : ?>
					<span class="bn-post-card__member-type"><?php echo esc_html( (string) $member_type_label ); ?></span>
				<?php endif; ?>
			</div>

			<div class="bn-post-card__meta-line">
				<time
					class="bn-post-card__time"
					datetime="<?php echo esc_attr( $created_at ); ?>"
					title="<?php echo esc_attr( $created_at ); ?>"
				><?php echo $post_time; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside function ?></time>

				<?php if ( $edited_label ) : ?>
					<span class="bn-post-card__edited"><?php echo esc_html( $edited_label ); ?></span>
				<?php endif; ?>

				<?php if ( $privacy_label ) : ?>
					<span class="bn-post-card__privacy-badge" aria-label="<?php echo esc_attr( $privacy_label ); ?>">
						<?php echo $privacy_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML entities ?>
						<?php echo esc_html( $privacy_label ); ?>
					</span>
				<?php endif; ?>
			</div>
		</div><!-- .bn-post-card__author-wrap -->

		<!-- Post options menu button -->
		<div class="bn-post-card__options-wrap">
			<button
				type="button"
				class="bn-post-card__options-btn"
				aria-label="<?php esc_attr_e( 'Post options', 'buddynext' ); ?>"
				aria-haspopup="true"
				aria-expanded="false"
				data-wp-on--click="actions.toggleOptionsMenu"
				data-wp-bind--aria-expanded="state.optionsOpen"
			><?php buddynext_icon( 'more-horizontal' ); ?></button>

			<div
				class="bn-post-card__options-menu"
				role="menu"
				data-wp-bind--hidden="!state.optionsOpen"
			>
				<?php if ( $can_edit ) : ?>
					<button
						type="button"
						class="bn-post-card__menu-item"
						role="menuitem"
						data-wp-on--click="actions.editPost"
					><?php buddynext_icon( 'edit' ); ?> <?php esc_html_e( 'Edit', 'buddynext' ); ?></button>
				<?php endif; ?>

				<?php if ( $can_pin ) : ?>
					<button
						type="button"
						class="bn-post-card__menu-item"
						role="menuitem"
						data-wp-on--click="actions.pinPost"
					><?php buddynext_icon( 'bookmark' ); ?> <?php echo $is_pinned ? esc_html__( 'Unpin', 'buddynext' ) : esc_html__( 'Pin to profile', 'buddynext' ); ?></button>
				<?php endif; ?>

				<?php if ( $can_report ) : ?>
					<button
						type="button"
						class="bn-post-card__menu-item bn-post-card__menu-item--danger"
						role="menuitem"
						data-wp-on--click="actions.reportPost"
					><?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Report', 'buddynext' ); ?></button>
				<?php endif; ?>

				<?php if ( $can_delete ) : ?>
					<button
						type="button"
						class="bn-post-card__menu-item bn-post-card__menu-item--danger"
						role="menuitem"
						data-wp-on--click="actions.deletePost"
					><?php buddynext_icon( 'trash' ); ?> <?php esc_html_e( 'Delete', 'buddynext' ); ?></button>
				<?php endif; ?>
			</div>
		</div><!-- .bn-post-card__options-wrap -->
	</div><!-- .bn-post-card__header -->

	<!-- Content warning overlay -->
	<?php if ( $has_cw ) : ?>
		<div
			class="bn-post-card__cw-overlay"
			data-wp-bind--hidden="state.showContent"
		>
			<div class="bn-post-card__cw-inner">
				<span class="bn-post-card__cw-icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
				<p class="bn-post-card__cw-label"><?php echo esc_html( $cw_display ); ?></p>
				<button
					type="button"
					class="bn-post-card__cw-reveal"
					data-wp-on--click="actions.revealContent"
				><?php esc_html_e( 'Show anyway', 'buddynext' ); ?></button>
			</div>
		</div>
	<?php endif; ?>

	<!-- Post body — hidden when content warning is active -->
	<div
		class="bn-post-card__body"
		<?php if ( $has_cw ) : ?>
			data-wp-bind--class="state.bodyClass"
		<?php endif; ?>
	>

		<?php if ( 'text' === $bn_post_type || 'activity' === $bn_post_type ) : ?>
			<!-- Text / activity post content -->
			<div class="bn-post-card__content">
				<?php
				echo wp_kses(
					nl2br( buddynext_format_content( $post_content ) ),
					array(
						'br'     => array(),
						'a'      => array(
							'href'  => array(),
							'class' => array(),
						),
						'strong' => array(),
						'em'     => array(),
					)
				);
				?>
			</div>

		<?php elseif ( 'photo' === $bn_post_type ) : ?>
			<!-- Photo post -->
			<?php if ( $post_content ) : ?>
				<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( $post_content ) ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $media_ids ) ) : ?>
				<div class="bn-post-card__media-grid bn-post-card__media-grid--<?php echo count( $media_ids ) >= 4 ? '4' : esc_attr( (string) count( $media_ids ) ); ?>">
					<?php foreach ( array_slice( $media_ids, 0, 4 ) as $media_id ) : ?>
						<div class="bn-post-card__media-item" data-media-id="<?php echo absint( $media_id ); ?>">
							<span class="bn-post-card__media-placeholder" aria-hidden="true"><?php buddynext_icon( 'camera' ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		<?php elseif ( 'file' === $bn_post_type ) : ?>
			<!-- File post -->
			<?php if ( $post_content ) : ?>
				<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( $post_content ) ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $media_ids ) ) : ?>
				<div class="bn-post-card__file-list">
					<?php foreach ( $media_ids as $file_media_id ) : ?>
						<div class="bn-post-card__file-item" data-media-id="<?php echo absint( $file_media_id ); ?>">
							<span class="bn-post-card__file-icon" aria-hidden="true"><?php buddynext_icon( 'copy' ); ?></span>
							<span class="bn-post-card__file-label"><?php esc_html_e( 'Attached file', 'buddynext' ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		<?php elseif ( 'link' === $bn_post_type ) : ?>
			<!-- Link post -->
			<?php if ( $post_content ) : ?>
				<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( $post_content ) ); ?></div>
			<?php endif; ?>
			<?php if ( $link_url ) : ?>
				<a
					href="<?php echo esc_url( $link_url ); ?>"
					class="bn-post-card__link-preview"
					target="_blank"
					rel="noopener noreferrer"
					aria-label="<?php echo esc_attr( $link_title ? $link_title : $link_domain ); ?>"
				>
					<?php if ( $link_thumb ) : ?>
						<div class="bn-post-card__link-thumb">
							<img
								src="<?php echo esc_url( $link_thumb ); ?>"
								alt=""
								loading="lazy"
							>
						</div>
					<?php endif; ?>
					<div class="bn-post-card__link-info">
						<?php if ( $link_domain ) : ?>
							<span class="bn-post-card__link-domain"><?php echo esc_html( $link_domain ); ?></span>
						<?php endif; ?>
						<?php if ( $link_title ) : ?>
							<p class="bn-post-card__link-title"><?php echo esc_html( $link_title ); ?></p>
						<?php endif; ?>
						<?php if ( $link_desc ) : ?>
							<p class="bn-post-card__link-desc"><?php echo esc_html( $link_desc ); ?></p>
						<?php endif; ?>
					</div>
				</a>
			<?php endif; ?>

		<?php elseif ( 'poll' === $bn_post_type ) : ?>
			<!-- Poll post -->
			<?php if ( $post_content ) : ?>
				<div class="bn-post-card__content bn-post-card__poll-question">
					<?php echo wp_kses_post( nl2br( $post_content ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $poll_options ) ) : ?>
				<div
					class="bn-post-card__poll"
					role="group"
					aria-label="<?php esc_attr_e( 'Poll options', 'buddynext' ); ?>"
				>
					<?php foreach ( $poll_options as $option ) : ?>
						<?php
						$opt_id    = absint( $option['id'] );
						$opt_text  = $option['option_text'] ?? '';
						$opt_votes = absint( $option['vote_count'] );
						$opt_pct   = $poll_total_votes > 0 ? (int) round( ( $opt_votes / $poll_total_votes ) * 100 ) : 0;
						$opt_voted = ( $my_voted_option_id === $opt_id && $opt_id > 0 );
						?>
						<button
							type="button"
							class="bn-post-card__poll-option<?php echo $opt_voted ? ' is-voted' : ''; ?>"
							data-wp-context='<?php echo wp_json_encode( array( 'optionId' => $opt_id ) ); ?>'
							data-wp-bind--class="state.pollOptionBtnClass"
							data-wp-on--click="actions.votePoll"
							data-option-id="<?php echo absint( $opt_id ); ?>"
							aria-label="<?php echo esc_attr( sprintf( '%s — %d%%', $opt_text, $opt_pct ) ); ?>"
						>
							<div
								class="bn-post-card__poll-fill"
								style="width:<?php echo absint( $opt_pct ); ?>%"
								data-wp-bind--style="state.pollFillStyle"
								aria-hidden="true"
							></div>
							<span class="bn-post-card__poll-option-text"><?php echo esc_html( $opt_text ); ?></span>
							<span
								class="bn-post-card__poll-pct"
								data-wp-text="state.pollOptionPctText"
								aria-hidden="true"
							><?php echo absint( $opt_pct ); ?>%</span>
						</button>
					<?php endforeach; ?>
					<p
						class="bn-post-card__poll-total"
						data-wp-text="state.pollTotalVotesText"
					>
						<?php
						/* translators: %d: total vote count */
						echo esc_html( sprintf( _n( '%d vote', '%d votes', $poll_total_votes, 'buddynext' ), $poll_total_votes ) );
						?>
					</p>
				</div>
			<?php endif; ?>

		<?php elseif ( 'announcement' === $bn_post_type ) : ?>
			<!-- Announcement body -->
			<div class="bn-post-card__content bn-post-card__content--announcement">
				<?php echo wp_kses_post( nl2br( $post_content ) ); ?>
			</div>

		<?php elseif ( 'media' === $bn_post_type ) : ?>
			<!-- WPMediaVerse bridge: media card -->
			<?php if ( $post_content ) : ?>
				<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( $post_content ) ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $media_ids ) ) : ?>
				<div class="bn-post-card__media-bridge" data-mvs-ids="<?php echo esc_attr( implode( ',', array_map( 'absint', $media_ids ) ) ); ?>">
					<span class="bn-post-card__media-placeholder" aria-hidden="true"><?php buddynext_icon( 'camera' ); ?></span>
					<span class="bn-post-card__bridge-label"><?php esc_html_e( 'Media', 'buddynext' ); ?></span>
				</div>
			<?php endif; ?>

		<?php elseif ( 'discussion' === $bn_post_type ) : ?>
			<!-- Jetonomy bridge: discussion card -->
			<div class="bn-post-card__bridge-card bn-post-card__bridge-card--jetonomy">
				<span class="bn-post-card__bridge-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
				<div class="bn-post-card__bridge-content">
					<span class="bn-post-card__bridge-source">Jetonomy</span>
					<p class="bn-post-card__bridge-text"><?php echo wp_kses_post( wp_trim_words( $post_content, 20 ) ); ?></p>
				</div>
			</div>

		<?php elseif ( 'job' === $bn_post_type ) : ?>
			<!-- Career Board bridge: job card -->
			<div class="bn-post-card__bridge-card bn-post-card__bridge-card--job">
				<span class="bn-post-card__bridge-icon" aria-hidden="true"><?php buddynext_icon( 'briefcase' ); ?></span>
				<div class="bn-post-card__bridge-content">
					<span class="bn-post-card__bridge-source"><?php esc_html_e( 'Job Listing', 'buddynext' ); ?></span>
					<p class="bn-post-card__bridge-text"><?php echo wp_kses_post( wp_trim_words( $post_content, 20 ) ); ?></p>
				</div>
			</div>

		<?php elseif ( 'share' === $bn_post_type ) : ?>
			<!-- Share card: optional note + embedded original post -->
			<?php if ( ! empty( $post_content ) ) : ?>
				<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( $post_content ) ); ?></div>
			<?php endif; ?>
			<?php if ( null !== $shared_post ) : ?>
				<?php
				$orig_author   = get_userdata( (int) ( $shared_post['user_id'] ?? 0 ) );
				$orig_name     = $orig_author ? esc_html( $orig_author->display_name ) : esc_html__( 'Community Member', 'buddynext' );
				$orig_username = $orig_author ? esc_html( $orig_author->user_nicename ) : '';
				$orig_avatar   = get_avatar_url( (int) ( $shared_post['user_id'] ?? 0 ), array( 'size' => 40 ) );
				$orig_time     = bn_post_card_relative_time( $shared_post['created_at'] ?? '' );
				$orig_content  = $shared_post['content'] ?? '';
				$orig_post_url = PageRouter::profile_url( (int) ( $shared_post['user_id'] ?? 0 ) );
				$orig_parts    = array_filter( explode( ' ', trim( (string) $orig_name ) ) );
				if ( count( $orig_parts ) >= 2 ) {
					$orig_initials = strtoupper( substr( (string) reset( $orig_parts ), 0, 1 ) . substr( (string) end( $orig_parts ), 0, 1 ) );
				} else {
					$orig_initials = strtoupper( substr( (string) $orig_name, 0, 2 ) );
				}
				$orig_palette = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );
				$orig_color   = $orig_palette[ (int) ( $shared_post['user_id'] ?? 0 ) % count( $orig_palette ) ];
				?>
				<div class="bn-post-card__shared-embed" role="article" aria-label="<?php esc_attr_e( 'Shared post', 'buddynext' ); ?>">
					<div class="bn-post-card__shared-header">
						<a href="<?php echo esc_url( $orig_post_url ); ?>" class="bn-post-card__shared-avatar" aria-hidden="true">
							<?php if ( $orig_avatar ) : ?>
								<img src="<?php echo esc_attr( $orig_avatar ); ?>" alt="<?php echo esc_attr( $orig_name ); ?>" width="40" height="40">
							<?php else : ?>
								<span style="background:<?php echo esc_attr( $orig_color ); ?>;"><?php echo esc_html( $orig_initials ); ?></span>
							<?php endif; ?>
						</a>
						<div class="bn-post-card__shared-meta">
							<a href="<?php echo esc_url( $orig_post_url ); ?>" class="bn-post-card__shared-name"><?php echo esc_html( $orig_name ); ?></a>
							<?php if ( $orig_username ) : ?>
								<span class="bn-post-card__shared-username">@<?php echo esc_html( $orig_username ); ?></span>
							<?php endif; ?>
							<span class="bn-post-card__shared-time"><?php echo esc_html( $orig_time ); ?></span>
						</div>
					</div>
					<?php if ( ! empty( $orig_content ) ) : ?>
						<div class="bn-post-card__shared-content"><?php echo wp_kses_post( nl2br( wp_trim_words( $orig_content, 60 ) ) ); ?></div>
					<?php else : ?>
						<p class="bn-post-card__shared-empty"><?php esc_html_e( '[No text content]', 'buddynext' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="bn-post-card__shared-missing">
					<span><?php buddynext_icon( 'share' ); ?></span>
					<p><?php esc_html_e( 'Original post is no longer available.', 'buddynext' ); ?></p>
				</div>
			<?php endif; ?>

		<?php else : ?>
			<!-- Fallback for unknown/future types -->
			<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( $post_content ) ); ?></div>
		<?php endif; ?>

	</div><!-- .bn-post-card__body -->

	<!-- Reaction bar -->
	<?php if ( $reaction_count > 0 || $comment_count > 0 || $share_count > 0 ) : ?>
		<div class="bn-post-card__reaction-summary" aria-label="<?php esc_attr_e( 'Post summary', 'buddynext' ); ?>">
			<?php if ( $reaction_count > 0 ) : ?>
				<span class="bn-post-card__summary-chip">
					<?php buddynext_icon( 'heart' ); ?> <?php echo esc_html( (string) $reaction_count ); ?>
				</span>
			<?php endif; ?>
			<?php if ( $comment_count > 0 ) : ?>
				<span class="bn-post-card__summary-chip">
					<?php buddynext_icon( 'message-circle' ); ?> <?php echo esc_html( (string) $comment_count ); ?>
				</span>
			<?php endif; ?>
			<?php if ( $share_count > 0 ) : ?>
				<span class="bn-post-card__summary-chip">
					<?php buddynext_icon( 'share' ); ?> <?php echo esc_html( (string) $share_count ); ?>
				</span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Action bar -->
	<div class="bn-post-card__actions" role="toolbar" aria-label="<?php esc_attr_e( 'Post actions', 'buddynext' ); ?>">

		<!-- Reaction picker trigger -->
		<div class="bn-post-card__react-wrap">
			<button
				type="button"
				class="bn-post-card__action-btn bn-post-card__action-btn--react"
				aria-label="<?php esc_attr_e( 'React to post', 'buddynext' ); ?>"
				data-wp-on--click="actions.toggleReactionPicker"
				data-wp-bind--class="state.reactBtnClass"
			>
				<span data-wp-bind--class="state.reactionIconClass" aria-hidden="true"><?php buddynext_icon( 'heart' ); ?></span>
				<?php esc_html_e( 'React', 'buddynext' ); ?>
			</button>

			<!-- Reaction emoji picker (hover/click expand) -->
			<div
				class="bn-post-card__emoji-picker"
				role="toolbar"
				aria-label="<?php esc_attr_e( 'Choose reaction', 'buddynext' ); ?>"
				data-wp-bind--hidden="!state.showReactionPicker"
			>
				<?php
				$reaction_icons = array(
					'like'  => 'thumbs-up',
					'love'  => 'heart',
					'haha'  => 'reaction-haha',
					'wow'   => 'reaction-wow',
					'sad'   => 'reaction-sad',
					'angry' => 'reaction-angry',
				);
				foreach ( $reaction_icons as $reaction_key => $icon_slug ) :
					?>
					<button
						type="button"
						class="bn-post-card__emoji-btn"
						aria-label="<?php echo esc_attr( $reaction_key ); ?>"
						data-wp-on--click="actions.setReaction"
						data-reaction-type="<?php echo esc_attr( $reaction_key ); ?>"
					><span class="bn-reaction-icon bn-reaction-icon--<?php echo esc_attr( $reaction_key ); ?>" aria-hidden="true"><?php buddynext_icon( $icon_slug ); ?></span></button>
				<?php endforeach; ?>
			</div>
		</div><!-- .bn-post-card__react-wrap -->

		<!-- Comment -->
		<button
			type="button"
			class="bn-post-card__action-btn"
			aria-label="
			<?php
				/* translators: %d: comment count */
				echo esc_attr( sprintf( _n( '%d comment', '%d comments', $comment_count, 'buddynext' ), $comment_count ) );
			?>
			"
			data-wp-on--click="actions.openComments"
			data-post-id="<?php echo absint( $bn_post_id ); ?>"
		>
			<?php buddynext_icon( 'message-circle' ); ?>
			<?php esc_html_e( 'Comment', 'buddynext' ); ?>
			<?php if ( $comment_count > 0 ) : ?>
				<span class="bn-comment-count"><?php echo esc_html( (string) $comment_count ); ?></span>
			<?php else : ?>
				<span class="bn-comment-count" style="display:none">0</span>
			<?php endif; ?>
		</button>

		<!-- Share -->
		<?php if ( 'share' !== $bn_post_type ) : ?>
		<button
			type="button"
			class="bn-post-card__action-btn"
			data-wp-bind--class="state.shareBtnClass"
			aria-label="<?php esc_attr_e( 'Share post', 'buddynext' ); ?>"
			data-wp-on--click="actions.sharePost"
			data-post-id="<?php echo absint( $bn_post_id ); ?>"
		>
			<?php buddynext_icon( 'share' ); ?>
			<span data-wp-text="state.shareLabel"></span>
		</button>
		<?php endif; ?>

		<!-- Bookmark -->
		<?php if ( $can_bookmark ) : ?>
			<button
				type="button"
				class="bn-post-card__action-btn"
				aria-label="<?php esc_attr_e( 'Bookmark post', 'buddynext' ); ?>"
				data-wp-on--click="actions.toggleBookmark"
				data-post-id="<?php echo absint( $bn_post_id ); ?>"
				data-wp-bind--class="state.bookmarkBtnClass"
			>
				<span data-wp-bind--aria-pressed="state.bookmarked"><?php buddynext_icon( 'bookmark' ); ?></span>
				<?php esc_html_e( 'Save', 'buddynext' ); ?>
			</button>
		<?php endif; ?>

	</div><!-- .bn-post-card__actions -->

	<!-- ── Comments section ─────────────────────────────────────────────── -->
	<div
		class="bn-post-card__comments"
		hidden
		data-wp-bind--hidden="state.commentsHidden"
		data-post-id="<?php echo absint( $bn_post_id ); ?>"
	>
		<div class="bn-comment-list" data-comment-list="<?php echo absint( $bn_post_id ); ?>"></div>

		<?php if ( $current_user_id > 0 ) : ?>
		<div class="bn-comment-form">
			<div class="bn-comment-form__avatar" aria-hidden="true">
				<?php
				$current_display_name = (string) get_the_author_meta( 'display_name', $current_user_id );
				$name_for_initials    = '' !== $current_display_name ? $current_display_name : 'U';
				$current_initials     = implode( '', array_map( fn( string $w ) => strtoupper( mb_substr( $w, 0, 1 ) ), explode( ' ', $name_for_initials ) ) );
				echo esc_html( mb_substr( $current_initials, 0, 2 ) );
				?>
			</div>
			<textarea
				class="bn-comment-form__input"
				placeholder="<?php esc_attr_e( 'Write a comment...', 'buddynext' ); ?>"
				aria-label="<?php esc_attr_e( 'Comment text', 'buddynext' ); ?>"
				data-comment-input="<?php echo absint( $bn_post_id ); ?>"
				rows="1"
			></textarea>
			<button
				type="button"
				class="bn-comment-form__submit"
				data-wp-on--click="actions.submitComment"
				aria-label="<?php esc_attr_e( 'Post comment', 'buddynext' ); ?>"
			>
				<?php buddynext_icon( 'send' ); ?>
			</button>
		</div>
		<?php endif; ?>
	</div><!-- .bn-post-card__comments -->

</article>
