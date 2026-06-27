<?php
/**
 * BuddyNext template part: profile Replies panel.
 *
 * The registry content seam for the profile Replies tab — each reply links back
 * to the activity it was posted on (/p/{id}/), so the tab is a navigable history.
 * Rendered by ProfileNav's replies `render` callable, which self-fetches the
 * rows; this part only paints them (no reveal wrapper).
 *
 * @package BuddyNext
 *
 * @var object[] $replies Reply rows (->object_id / ->content / ->post_author_name / ->post_content / ->created_at).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_rp_replies = isset( $replies ) && is_array( $replies ) ? $replies : array();

if ( empty( $bn_rp_replies ) ) :
	?>
	<div class="bn-empty-state">
		<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
		<div class="bn-empty-title"><?php esc_html_e( 'No replies yet.', 'buddynext' ); ?></div>
	</div>
	<?php
	return;
endif;

foreach ( $bn_rp_replies as $bn_rp_reply ) :
	$bn_rp_url = \BuddyNext\Core\PageRouter::post_url( (int) $bn_rp_reply->object_id );
	?>
	<a class="bn-reply-card bn-reply-card--link" href="<?php echo esc_url( $bn_rp_url ); ?>">
		<div class="bn-reply-card__meta">
			<?php buddynext_icon( 'message-circle' ); ?>
			<span><?php echo esc_html( sprintf( /* translators: %s: author name */ __( 'Replied to %s', 'buddynext' ), $bn_rp_reply->post_author_name ) ); ?></span>
			<span class="bn-reply-card__time"><?php echo buddynext_time_ago( (string) $bn_rp_reply->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_time_ago() returns esc_html()'d output. ?></span>
		</div>
		<div class="bn-reply-card__content"><?php echo wp_kses_post( wp_trim_words( $bn_rp_reply->content, 30 ) ); ?></div>
		<div class="bn-reply-card__context"><?php echo wp_kses_post( wp_trim_words( $bn_rp_reply->post_content, 15 ) ); ?></div>
	</a>
	<?php
endforeach;
