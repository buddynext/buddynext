<?php
/**
 * BuddyNext template part: profile Discussions panel (Jetonomy).
 *
 * The registry content seam for the profile Discussions tab. The bridge owns ALL
 * jt_* access and hands this part the resolved rows; the part only renders them
 * (no reveal wrapper). Rendered by JetonomyBridge's profile discussions `render`.
 *
 * @package BuddyNext
 *
 * @var int      $profile_user_id Profile being viewed (avatar).
 * @var object[] $discussions     Rows from JetonomyBridge::user_discussions().
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_pd_uid  = isset( $profile_user_id ) ? (int) $profile_user_id : 0;
$bn_pd_rows = isset( $discussions ) && is_array( $discussions ) ? $discussions : array();

if ( empty( $bn_pd_rows ) ) :
	?>
	<div class="bn-empty-state">
		<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'messages-square' ); ?></div>
		<div class="bn-empty-title"><?php esc_html_e( 'No discussions yet.', 'buddynext' ); ?></div>
	</div>
	<?php
	return;
endif;

foreach ( $bn_pd_rows as $bn_pd_disc ) :
	$bn_pd_slug = '' !== (string) $bn_pd_disc->space_slug ? (string) $bn_pd_disc->space_slug : 'general';
	$bn_pd_name = '' !== (string) $bn_pd_disc->space_name ? (string) $bn_pd_disc->space_name : __( 'General', 'buddynext' );
	?>
	<a href="<?php echo esc_url( home_url( '/community/s/' . $bn_pd_slug . '/t/' . $bn_pd_disc->slug . '/' ) ); ?>" class="bn-reply-card bn-reply-card--link bn-reply-card--avatar">
		<span class="bn-reply-card__avatar" aria-hidden="true">
			<img src="<?php echo esc_url( get_avatar_url( $bn_pd_uid, array( 'size' => 80 ) ) ); ?>" alt="" loading="lazy" width="40" height="40" />
		</span>
		<div class="bn-reply-card__meta">
			<span><?php echo esc_html( $bn_pd_name ); ?></span>
			<span class="bn-reply-card__time"><?php echo esc_html( sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( strtotime( $bn_pd_disc->created_at ) ) ) ); ?></span>
		</div>
		<div class="bn-reply-card__content bn-reply-card__content--strong"><?php echo esc_html( $bn_pd_disc->title ); ?></div>
		<div class="bn-reply-card__context">
			<?php
			$bn_pd_rc = (int) $bn_pd_disc->reply_count;
			/* translators: %d: number of replies */ printf( esc_html( _n( '%d reply', '%d replies', $bn_pd_rc, 'buddynext' ) ), (int) $bn_pd_rc );
			?>
			<span aria-hidden="true">&middot;</span>
			<?php
			$bn_pd_vc = (int) $bn_pd_disc->vote_score;
			/* translators: %d: number of votes */ printf( esc_html( _n( '%d vote', '%d votes', $bn_pd_vc, 'buddynext' ) ), (int) $bn_pd_vc );
			?>
		</div>
	</a>
	<?php
endforeach;
