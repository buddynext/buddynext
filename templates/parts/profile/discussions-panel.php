<?php
/**
 * BuddyNext template part: profile Discussions panel (Jetonomy).
 *
 * The registry content seam for the profile Discussions tab. The bridge owns ALL
 * jt_* access and hands this part the resolved payload; the part only renders it
 * (no reveal wrapper). Rendered by JetonomyBridge::render_profile_discussions_panel.
 *
 * Credential-first: the member's outcomes (accepted answers + reputation) lead;
 * the recent-discussions list is supporting context, not the headline.
 *
 * @package BuddyNext
 *
 * @var int      $profile_user_id  Profile being viewed (avatar).
 * @var object[] $discussions      Rows from JetonomyBridge::user_discussions() (each carries a base-slug-aware `url`).
 * @var int      $discussion_count Total published discussions the member started.
 * @var int      $accepted_answers Replies of this member marked as the accepted answer.
 * @var int      $reputation       Jetonomy reputation score.
 * @var int      $trust_level      Jetonomy trust level (0-4); surfaced via REST, not the strip.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pd_uid      = isset( $profile_user_id ) ? (int) $profile_user_id : 0;
$bn_pd_rows     = isset( $discussions ) && is_array( $discussions ) ? $discussions : array();
$bn_pd_accepted = isset( $accepted_answers ) ? (int) $accepted_answers : 0;
$bn_pd_rep      = isset( $reputation ) ? (int) $reputation : 0;
$bn_pd_count    = isset( $discussion_count ) ? (int) $discussion_count : count( $bn_pd_rows );

// Nothing worth showing — no credentials and no discussions.
if ( empty( $bn_pd_rows ) && 0 === $bn_pd_accepted && 0 === $bn_pd_rep && 0 === $bn_pd_count ) :
	?>
	<div class="bn-empty-state">
		<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'messages-square' ); ?></div>
		<div class="bn-empty-title"><?php esc_html_e( 'No discussions yet.', 'buddynext' ); ?></div>
	</div>
	<?php
	return;
endif;

// Credential strip (outcomes first). Accepted answers is the real credential —
// this member solved someone's question — not just activity volume.
?>
<div class="bn-stat-grid bn-jt-cred-strip">
	<div class="bn-stat">
		<span class="bn-stat__value"><?php echo esc_html( number_format_i18n( $bn_pd_accepted ) ); ?></span>
		<span class="bn-stat__label"><?php esc_html_e( 'Accepted answers', 'buddynext' ); ?></span>
	</div>
	<div class="bn-stat">
		<span class="bn-stat__value"><?php echo esc_html( number_format_i18n( $bn_pd_rep ) ); ?></span>
		<span class="bn-stat__label"><?php esc_html_e( 'Reputation', 'buddynext' ); ?></span>
	</div>
	<div class="bn-stat">
		<span class="bn-stat__value"><?php echo esc_html( number_format_i18n( $bn_pd_count ) ); ?></span>
		<span class="bn-stat__label"><?php esc_html_e( 'Discussions', 'buddynext' ); ?></span>
	</div>
</div>

<?php
if ( empty( $bn_pd_rows ) ) {
	return;
}

foreach ( $bn_pd_rows as $bn_pd_disc ) :
	$bn_pd_name = '' !== (string) $bn_pd_disc->space_name ? (string) $bn_pd_disc->space_name : __( 'General', 'buddynext' );
	$bn_pd_url  = isset( $bn_pd_disc->url ) ? (string) $bn_pd_disc->url : '';
	?>
	<a href="<?php echo esc_url( $bn_pd_url ); ?>" class="bn-reply-card bn-reply-card--link bn-reply-card--avatar">
		<span class="bn-reply-card__avatar" aria-hidden="true">
			<img src="<?php echo esc_url( get_avatar_url( $bn_pd_uid, array( 'size' => 80 ) ) ); ?>" alt="" loading="lazy" width="40" height="40" />
		</span>
		<div class="bn-reply-card__meta">
			<span><?php echo esc_html( $bn_pd_name ); ?></span>
			<span class="bn-reply-card__time"><?php echo esc_html( sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours". */ __( '%s ago', 'buddynext' ), human_time_diff( strtotime( $bn_pd_disc->created_at ) ) ) ); ?></span>
		</div>
		<div class="bn-reply-card__content bn-reply-card__content--strong"><?php echo esc_html( $bn_pd_disc->title ); ?></div>
		<div class="bn-reply-card__context">
			<?php
			$bn_pd_rc = (int) $bn_pd_disc->reply_count;
			/* translators: %d: number of replies. */ printf( esc_html( _n( '%d reply', '%d replies', $bn_pd_rc, 'buddynext' ) ), (int) $bn_pd_rc );
			?>
			<span aria-hidden="true">&middot;</span>
			<?php
			$bn_pd_vc = (int) $bn_pd_disc->vote_score;
			/* translators: %d: number of votes. */ printf( esc_html( _n( '%d vote', '%d votes', $bn_pd_vc, 'buddynext' ) ), (int) $bn_pd_vc );
			?>
		</div>
	</a>
	<?php
endforeach;
