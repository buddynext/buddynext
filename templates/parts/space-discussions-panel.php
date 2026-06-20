<?php
/**
 * BuddyNext template part: space Discussions panel (Jetonomy, in-hub).
 *
 * The space counterpart to the profile Discussions tab. Lists the linked
 * Jetonomy forum's published threads inside the space hub, each row linking out
 * to the thread on the Community forum (a deny-listed, full-load route — the
 * shell never tries to client-nav into the partner surface). When no forum is
 * linked yet, an empty state offers the on-demand provision trigger so the forum
 * is created on first use (no empty forums).
 *
 * The bridge owns ALL jt_* access and link-option/nonce plumbing; this template
 * only renders the bundle it is handed.
 *
 * Variables are extracted from $args by {@see TemplateLoader::render()}.
 *
 * @package BuddyNext
 *
 * @var object   $space        Current space row (needs ->name).
 * @var object[] $discussions  Rows from JetonomyBridge::space_discussions().
 * @var string   $forum_url    Linked forum URL ('' when no forum is linked).
 * @var bool     $forum_linked Whether a forum is linked yet.
 * @var string   $provision_url On-demand provision-trigger URL (empty-state CTA).
 * @var bool     $can_post     Whether the viewer may start the forum / post.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_space        = isset( $space ) && is_object( $space ) ? $space : null;
$bn_discussions  = isset( $discussions ) && is_array( $discussions ) ? $discussions : array();
$bn_forum_url    = isset( $forum_url ) ? (string) $forum_url : '';
$bn_forum_linked = isset( $forum_linked ) ? (bool) $forum_linked : ( '' !== $bn_forum_url );
$bn_provision    = isset( $provision_url ) ? (string) $provision_url : '';
$bn_can_post     = isset( $can_post ) ? (bool) $can_post : false;

// Thread permalinks derive from the forum URL (which already carries the
// Jetonomy space slug), never the BN space slug — the two can differ.
$bn_thread_base = '' !== $bn_forum_url ? trailingslashit( $bn_forum_url ) . 't/' : '';
?>
<div class="bn-space-discussions">
	<?php if ( $bn_discussions ) : ?>
		<?php if ( '' !== $bn_forum_url ) : ?>
			<header class="bn-space-discussions__head">
				<a href="<?php echo esc_url( $bn_forum_url ); ?>" class="bn-btn" data-variant="ghost" data-size="sm">
					<?php buddynext_icon( 'external-link' ); ?>
					<?php esc_html_e( 'Open in Community', 'buddynext' ); ?>
				</a>
			</header>
		<?php endif; ?>

		<div class="bn-space-discussions__list">
			<?php
			foreach ( $bn_discussions as $bn_disc ) :
				if ( ! is_object( $bn_disc ) ) {
					continue;
				}
				$bn_author = '' !== (string) ( $bn_disc->author_name ?? '' )
					? (string) $bn_disc->author_name
					: (string) ( $bn_disc->author_login ?? __( 'Member', 'buddynext' ) );
				$bn_href   = '' !== $bn_thread_base ? $bn_thread_base . rawurlencode( (string) $bn_disc->slug ) . '/' : $bn_forum_url;
				?>
				<a href="<?php echo esc_url( $bn_href ); ?>" class="bn-reply-card bn-reply-card--link bn-reply-card--avatar">
					<span class="bn-reply-card__avatar" aria-hidden="true">
						<img src="<?php echo esc_url( get_avatar_url( (int) ( $bn_disc->author_id ?? 0 ), array( 'size' => 80 ) ) ); ?>" alt="" loading="lazy" width="40" height="40" />
					</span>
					<div class="bn-reply-card__meta">
						<span><?php echo esc_html( $bn_author ); ?></span>
						<span class="bn-reply-card__time"><?php echo esc_html( sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( strtotime( (string) $bn_disc->created_at ) ) ) ); ?></span>
					</div>
					<div class="bn-reply-card__content bn-reply-card__content--strong"><?php echo esc_html( (string) $bn_disc->title ); ?></div>
					<div class="bn-reply-card__context">
						<?php echo esc_html( (string) ( $bn_disc->reply_count ?? 0 ) ); ?> <?php esc_html_e( 'replies', 'buddynext' ); ?>
						<span aria-hidden="true">&middot;</span>
						<?php echo esc_html( (string) ( $bn_disc->vote_score ?? 0 ) ); ?> <?php esc_html_e( 'votes', 'buddynext' ); ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	<?php elseif ( $bn_forum_linked ) : ?>
		<?php
		// Forum exists but has no published threads yet.
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'      => 'messages-square',
				'title'     => __( 'No discussions yet', 'buddynext' ),
				'body'      => __( 'Be the first to start a conversation in this space.', 'buddynext' ),
				'cta_url'   => '' !== $bn_forum_url ? $bn_forum_url : '',
				'cta_label' => '' !== $bn_forum_url ? __( 'Start a discussion', 'buddynext' ) : '',
				'cta_icon'  => 'plus',
			)
		);
		?>
	<?php else : ?>
		<?php
		// No forum linked yet — offer the on-demand provision trigger to members.
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'      => 'messages-square',
				'title'     => __( 'Discussions are not set up yet', 'buddynext' ),
				'body'      => $bn_can_post
					? __( 'Start the first discussion to open a forum for this space.', 'buddynext' )
					: __( 'A forum opens here once a member starts the first discussion.', 'buddynext' ),
				'cta_url'   => $bn_can_post ? $bn_provision : '',
				'cta_label' => $bn_can_post ? __( 'Start the first discussion', 'buddynext' ) : '',
				'cta_icon'  => 'plus',
			)
		);
		?>
	<?php endif; ?>
</div>
