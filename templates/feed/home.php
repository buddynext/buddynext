<?php
/**
 * BuddyNext home feed template.
 *
 * Renders the personalised activity feed for the logged-in user: a post
 * composer, a stream of posts from followed users and the user's own posts,
 * and sidebars with suggested people, trending hashtags, and popular spaces.
 *
 * Guests are redirected to the login page.
 *
 * Overridable: copy to {theme}/buddynext/feed/home.php
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

// ── Auth gate ──────────────────────────────────────────────────────────────
$current_user_id = get_current_user_id();
if ( 0 === $current_user_id ) {
	wp_safe_redirect( PageRouter::auth_url() );
	exit;
}

global $wpdb;

// ── Feed service (cursor-paginated) ───────────────────────────────────────
$feed_service = buddynext_service( 'feed' );
$cursor       = sanitize_text_field( wp_unslash( $_GET['cursor'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cursor_val   = '' !== $cursor ? $cursor : null;
$feed_data    = $feed_service->home_feed( $current_user_id, $cursor_val );
$feed_posts   = $feed_data['items'];
$next_cursor  = $feed_data['next_cursor'];

// ── Sidebar: suggested people ──────────────────────────────────────────────
$users_table = $wpdb->prefix . 'users';
$meta_table  = $wpdb->prefix . 'usermeta';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$suggested_users = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT u.ID, u.display_name, u.user_login FROM {$users_table} u WHERE u.ID != %d LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current_user_id
	)
);

// ── Sidebar: trending hashtags ────────────────────────────────────────────
$hashtags_table = $wpdb->prefix . 'bn_hashtags';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$trending_tags = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT slug, post_count FROM {$hashtags_table} WHERE post_count > 0 ORDER BY post_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		5
	)
);

// ── Sidebar: popular spaces ───────────────────────────────────────────────
$spaces_table = $wpdb->prefix . 'bn_spaces';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$popular_spaces = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, name, avatar_url, member_count FROM {$spaces_table} WHERE type = 'open' ORDER BY member_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		3
	)
);

// ── REST nonce ─────────────────────────────────────────────────────────────
$rest_nonce = wp_create_nonce( 'wp_rest' );

// ── Avatar colour palette (deterministic by user ID) ──────────────────────
$avatar_colours = array( 'av-brand', 'av-green', 'av-purple', 'av-orange', 'av-pink', 'av-jt', 'av-mvs' );

$bn_viewer    = get_userdata( $current_user_id );
$display_name = $bn_viewer ? $bn_viewer->display_name : '';
$initials     = '' !== $display_name ? strtoupper( mb_substr( $display_name, 0, 1 ) ) : '?';
$av_colour    = $avatar_colours[ $current_user_id % count( $avatar_colours ) ];

/**
 * Format a relative timestamp.
 *
 * @param string $datetime MySQL datetime.
 * @return string
 */
if ( ! function_exists( 'bn_home_relative_time' ) ) {
	/**
	 * Format a relative timestamp.
	 *
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	function bn_home_relative_time( string $datetime ): string {
		$ts   = (int) strtotime( $datetime );
		$diff = max( 0, time() - $ts );

		if ( $diff < 60 ) {
			return esc_html__( 'just now', 'buddynext' );
		}
		if ( $diff < 3600 ) {
			$m = (int) floor( $diff / 60 );
			// translators: %d = minutes.
			return esc_html( sprintf( _n( '%dm', '%dm', $m, 'buddynext' ), $m ) );
		}
		if ( $diff < 86400 ) {
			$h = (int) floor( $diff / 3600 );
			// translators: %d = hours.
			return esc_html( sprintf( _n( '%dh', '%dh', $h, 'buddynext' ), $h ) );
		}
		return esc_html( date_i18n( 'M j', $ts ) );
	}
}
?>
<style>
/* ── BuddyNext Home Feed Layout ─────────────────────────────────────── */
.bn-feed-page{--bn-max:900px;display:flex;gap:var(--s6);max-width:calc(var(--bn-max) + 280px);margin:0 auto;padding:var(--s6) var(--s4);}
.bn-feed-main{flex:1;min-width:0;}
.bn-feed-sidebar{width:260px;flex-shrink:0;}

/* Composer */
.bn-composer{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:var(--s4);margin-bottom:var(--s4);}
.bn-composer-row{display:flex;gap:var(--s3);align-items:flex-start;}
.bn-composer-avatar{width:38px;height:38px;border-radius:var(--r-full);display:flex;align-items:center;justify-content:center;font-size:var(--text-sm);font-weight:700;color:#fff;flex-shrink:0;}
.bn-composer-input{flex:1;border:1px solid var(--border);border-radius:var(--r-md);padding:var(--s3) var(--s4);font-size:var(--text-base);background:var(--bg-subtle);color:var(--text-1);resize:none;cursor:pointer;min-height:44px;}
.bn-composer-input:focus{outline:2px solid var(--brand);outline-offset:-1px;background:var(--surface);}
.bn-composer-actions{display:flex;gap:var(--s2);margin-top:var(--s3);padding-top:var(--s3);border-top:1px solid var(--border-soft);}
.bn-composer-btn{display:flex;align-items:center;gap:var(--s2);padding:var(--s2) var(--s3);border-radius:var(--r-md);font-size:var(--text-sm);color:var(--text-2);background:none;border:none;cursor:pointer;}
.bn-composer-btn:hover{background:var(--bg-hover);color:var(--text-1);}
.bn-composer-submit{margin-left:auto;padding:var(--s2) var(--s4);background:var(--brand);color:#fff;border:none;border-radius:var(--r-md);font-size:var(--text-sm);font-weight:600;cursor:pointer;}
.bn-composer-submit:hover{background:var(--brand-hover);}
.bn-composer-submit:disabled{opacity:.5;cursor:default;}

/* Feed stream */
.bn-feed-stream{display:flex;flex-direction:column;gap:var(--s4);}
.bn-feed-empty{text-align:center;padding:var(--s12) var(--s4);background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);}
.bn-feed-empty h3{font-size:var(--text-lg);color:var(--text-1);margin:0 0 var(--s2);}
.bn-feed-empty p{color:var(--text-2);font-size:var(--text-sm);}
.bn-load-more{display:block;width:100%;padding:var(--s3);margin-top:var(--s4);background:var(--surface);border:1px solid var(--border);border-radius:var(--r-md);color:var(--brand);font-size:var(--text-sm);font-weight:600;cursor:pointer;text-align:center;}
.bn-load-more:hover{background:var(--brand-light);}

/* Sidebar widgets */
.bn-widget{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:var(--s4);margin-bottom:var(--s4);}
.bn-widget-title{font-size:var(--text-sm);font-weight:700;color:var(--text-1);margin:0 0 var(--s3);}
.bn-widget-people-item{display:flex;align-items:center;gap:var(--s3);padding:var(--s2) 0;}
.bn-widget-people-item + .bn-widget-people-item{border-top:1px solid var(--border-soft);}
.bn-widget-avatar{width:34px;height:34px;border-radius:var(--r-full);display:flex;align-items:center;justify-content:center;font-size:var(--text-xs);font-weight:700;color:#fff;flex-shrink:0;}
.bn-widget-name{flex:1;font-size:var(--text-sm);font-weight:600;color:var(--text-1);}
.bn-widget-follow-btn{padding:var(--s1) var(--s3);border:1px solid var(--brand);border-radius:var(--r-full);font-size:var(--text-xs);font-weight:600;color:var(--brand);background:none;cursor:pointer;}
.bn-widget-follow-btn:hover{background:var(--brand-light);}
.bn-tag-list{display:flex;flex-direction:column;gap:var(--s2);}
.bn-tag-row{display:flex;align-items:center;justify-content:space-between;}
.bn-tag-link{font-size:var(--text-sm);color:var(--brand);font-weight:500;text-decoration:none;}
.bn-tag-link:hover{text-decoration:underline;}
.bn-tag-count{font-size:var(--text-xs);color:var(--text-3);}
.bn-space-row{display:flex;align-items:center;gap:var(--s3);padding:var(--s2) 0;}
.bn-space-row + .bn-space-row{border-top:1px solid var(--border-soft);}
.bn-space-icon{width:34px;height:34px;border-radius:var(--r-md);background:var(--brand-light);display:flex;align-items:center;justify-content:center;font-size:var(--text-sm);flex-shrink:0;}
.bn-space-info{flex:1;min-width:0;}
.bn-space-name{font-size:var(--text-sm);font-weight:600;color:var(--text-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bn-space-count{font-size:var(--text-xs);color:var(--text-3);}

/* Dark mode */
[data-theme="dark"] .bn-composer-input{background:var(--bg-subtle);color:var(--text-1);}

/* Mobile ≤640px */
@media (max-width:640px){
	.bn-feed-page{flex-direction:column;padding:var(--s3);}
	.bn-feed-sidebar{width:100%;order:2;}
	.bn-feed-main{order:1;}
}
</style>

<div class="bn-feed-page">

	<!-- Main feed column -->
	<div class="bn-feed-main">

		<!-- Post composer -->
		<div class="bn-composer"
			data-wp-interactive="buddynext/post-composer"
			data-wp-context='{"submitting":false,"content":"","privacy":"public"}'>
			<div class="bn-composer-row">
				<div class="bn-composer-avatar <?php echo esc_attr( $av_colour ); ?>">
					<?php echo esc_html( $initials ); ?>
				</div>
				<textarea
					class="bn-composer-input"
					data-wp-on--input="actions.onInput"
					placeholder="<?php esc_attr_e( "What's on your mind?", 'buddynext' ); ?>"
					rows="1"></textarea>
			</div>
			<div class="bn-composer-actions">
				<button class="bn-composer-btn" type="button">📷 <?php esc_html_e( 'Photo', 'buddynext' ); ?></button>
				<button class="bn-composer-btn" type="button">📊 <?php esc_html_e( 'Poll', 'buddynext' ); ?></button>
				<button class="bn-composer-btn" type="button">🔗 <?php esc_html_e( 'Link', 'buddynext' ); ?></button>
				<button
					class="bn-composer-submit"
					type="button"
					data-wp-on--click="actions.submit"
					data-wp-bind--disabled="state.isSubmitting"
					data-nonce="<?php echo esc_attr( $rest_nonce ); ?>">
					<?php esc_html_e( 'Post', 'buddynext' ); ?>
				</button>
			</div>
		</div>

		<!-- Feed stream -->
		<div class="bn-feed-stream" id="bn-feed-stream">
			<?php if ( empty( $feed_posts ) ) : ?>
				<div class="bn-feed-empty">
					<h3><?php esc_html_e( 'Your feed is empty', 'buddynext' ); ?></h3>
					<p><?php esc_html_e( 'Follow people and join spaces to see posts here.', 'buddynext' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $feed_posts as $feed_post ) : ?>
					<?php
					buddynext_get_template(
						'partials/post-card',
						array(
							'post'            => (array) $feed_post,
							'current_user_id' => $current_user_id,
							'context'         => 'home',
						)
					);
					?>
				<?php endforeach; ?>

				<?php if ( $next_cursor ) : ?>
					<button
						class="bn-load-more"
						type="button"
						data-cursor="<?php echo esc_attr( $next_cursor ); ?>"
						onclick="bnLoadMore(this)">
						<?php esc_html_e( 'Load more posts', 'buddynext' ); ?>
					</button>
				<?php endif; ?>
			<?php endif; ?>
		</div>

	</div><!-- /.bn-feed-main -->

	<!-- Sidebar -->
	<aside class="bn-feed-sidebar">

		<?php if ( ! empty( $suggested_users ) ) : ?>
		<div class="bn-widget">
			<p class="bn-widget-title"><?php esc_html_e( 'People you may know', 'buddynext' ); ?></p>
			<?php foreach ( $suggested_users as $su ) : ?>
				<?php
				$su_id      = absint( $su->ID );
				$su_name    = $su->display_name;
				$su_initial = '' !== $su_name ? strtoupper( mb_substr( $su_name, 0, 1 ) ) : '?';
				$su_colour  = $avatar_colours[ $su_id % count( $avatar_colours ) ];
				?>
				<div class="bn-widget-people-item">
					<div class="bn-widget-avatar <?php echo esc_attr( $su_colour ); ?>">
						<?php echo esc_html( $su_initial ); ?>
					</div>
					<span class="bn-widget-name"><?php echo esc_html( $su_name ); ?></span>
					<button
						class="bn-widget-follow-btn"
						type="button"
						data-user="<?php echo esc_attr( (string) $su_id ); ?>"
						onclick="bnFollow(this)">
						<?php esc_html_e( 'Follow', 'buddynext' ); ?>
					</button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $trending_tags ) ) : ?>
		<div class="bn-widget">
			<p class="bn-widget-title"><?php esc_html_e( 'Trending hashtags', 'buddynext' ); ?></p>
			<div class="bn-tag-list">
				<?php foreach ( $trending_tags as $bn_tag ) : ?>
					<div class="bn-tag-row">
						<a
							href="<?php echo esc_url( PageRouter::hashtag_feed_url( (string) $bn_tag->slug ) ); ?>"
							class="bn-tag-link">
							#<?php echo esc_html( $bn_tag->slug ); ?>
						</a>
						<span class="bn-tag-count">
							<?php
							printf(
								/* translators: %d = post count */
								esc_html( _n( '%d post', '%d posts', absint( $bn_tag->post_count ), 'buddynext' ) ),
								absint( $bn_tag->post_count )
							);
							?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $popular_spaces ) ) : ?>
		<div class="bn-widget">
			<p class="bn-widget-title"><?php esc_html_e( 'Popular spaces', 'buddynext' ); ?></p>
			<?php foreach ( $popular_spaces as $sp ) : ?>
				<?php
				$sp_id   = absint( $sp->id );
				$sp_name = $sp->name;
				?>
				<div class="bn-space-row">
					<div class="bn-space-icon">🏠</div>
					<div class="bn-space-info">
						<div class="bn-space-name"><?php echo esc_html( $sp_name ); ?></div>
						<div class="bn-space-count">
							<?php
							printf(
								/* translators: %d = member count */
								esc_html( _n( '%d member', '%d members', absint( $sp->member_count ), 'buddynext' ) ),
								absint( $sp->member_count )
							);
							?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

	</aside><!-- /.bn-feed-sidebar -->

</div><!-- /.bn-feed-page -->

<script>
function bnLoadMore(btn) {
	var cursor = btn.dataset.cursor;
	if (!cursor) return;
	btn.textContent = '<?php echo esc_js( __( 'Loading…', 'buddynext' ) ); ?>';
	btn.disabled = true;
	fetch('<?php echo esc_js( rest_url( 'buddynext/v1/feed' ) ); ?>?scope=home&cursor=' + encodeURIComponent(cursor), {
		headers: { 'X-WP-Nonce': '<?php echo esc_js( $rest_nonce ); ?>' }
	})
	.then(function(r){ return r.json(); })
	.then(function(data){
		var stream = document.getElementById('bn-feed-stream');
		if (stream) {
			btn.remove();
			// REST response; full re-render not possible here — reload with cursor.
			window.location.href = window.location.pathname + '?cursor=' + encodeURIComponent(cursor);
		}
	})
	.catch(function(){ btn.disabled = false; btn.textContent = '<?php echo esc_js( __( 'Load more posts', 'buddynext' ) ); ?>'; });
}

function bnFollow(btn) {
	var userId = btn.dataset.user;
	if (!userId) return;
	btn.disabled = true;
	fetch('<?php echo esc_js( rest_url( 'buddynext/v1/users/' ) ); ?>' + userId + '/follow', {
		method: 'POST',
		headers: { 'X-WP-Nonce': '<?php echo esc_js( $rest_nonce ); ?>', 'Content-Type': 'application/json' }
	})
	.then(function(r){ return r.json(); })
	.then(function(){ btn.textContent = '<?php echo esc_js( __( 'Following', 'buddynext' ) ); ?>'; })
	.catch(function(){ btn.disabled = false; });
}
</script>
