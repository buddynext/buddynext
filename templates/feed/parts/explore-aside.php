<?php
/**
 * BuddyNext Explore aside — the community "heartbeat" sidebar.
 *
 * Distinct from the home/profile sidebar: this surfaces discovery signals —
 * trending tags, people to discover, and a category browser — so the Explore
 * page reads as "what's going on" rather than a personal feed rail.
 *
 * All data is real and bounded (top-N queries); the wireframe's speculative
 * community-pulse chart is left as a Pro injection seat
 * (buddynext_explore_aside_pulse) so the free build never fabricates data.
 *
 * Overridable: copy to {theme}/buddynext/feed/parts/explore-aside.php
 *
 * @package BuddyNext
 * @since   1.6.0
 *
 * @var int $current_user_id Viewing user ID (0 for guests).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\ExploreService;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\Spaces\SpaceService;

$bn_viewer = isset( $current_user_id ) ? (int) $current_user_id : get_current_user_id();

/**
 * Fires at the top of the Explore aside. Pro hooks a live community-pulse card
 * (the wireframe's chart) here.
 *
 * @since 1.6.0
 *
 * @param int $bn_viewer Viewing user ID.
 */
do_action( 'buddynext_explore_aside_pulse', $bn_viewer );

// ── Trending tags ──────────────────────────────────────────────────────────
// Cached, service-owned trending (24h rolling window + transient layer); the
// same source the hashtag REST endpoint serves.
$bn_trending = ( new HashtagService() )->trending( 5 );

if ( ! empty( $bn_trending ) ) {
	$bn_activity = trailingslashit( PageRouter::activity_url() );
	ob_start();
	?>
	<ol class="bn-ex-trend">
		<?php
		$bn_rank = 0;
		foreach ( $bn_trending as $bn_tag ) :
			$bn_tag_slug  = (string) ( $bn_tag['slug'] ?? '' );
			$bn_tag_count = (int) ( $bn_tag['post_count'] ?? 0 );
			if ( '' === $bn_tag_slug ) {
				continue;
			}
			++$bn_rank;
			$bn_tag_url = esc_url( $bn_activity . 'hashtag/' . rawurlencode( $bn_tag_slug ) . '/' );
			?>
			<li class="bn-ex-trend__row">
				<span class="bn-ex-trend__rank"><?php echo esc_html( number_format_i18n( $bn_rank ) ); ?></span>
				<a class="bn-ex-trend__info" href="<?php echo $bn_tag_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() applied above. ?>">
					<span class="bn-ex-trend__tag">#<?php echo esc_html( $bn_tag_slug ); ?></span>
					<span class="bn-ex-trend__stat">
						<?php
						/* translators: %s: formatted post count. */
						echo esc_html( sprintf( _n( '%s post', '%s posts', $bn_tag_count, 'buddynext' ), number_format_i18n( $bn_tag_count ) ) );
						?>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php
	buddynext_get_template(
		'parts/sidebar-card.php',
		array(
			'id'         => 'explore-trending',
			'title'      => __( 'Trending tags', 'buddynext' ),
			'title_icon' => 'trending-up',
			'body_html'  => (string) ob_get_clean(),
		)
	);
}

// ── People to discover ─────────────────────────────────────────────────────
$bn_people = ( new ExploreService() )->suggested_member_ids( 4 );
if ( ! empty( $bn_people ) ) {
	ob_start();
	?>
	<ul class="bn-ex-people">
		<?php
		foreach ( $bn_people as $bn_uid ) :
			$bn_user = get_userdata( $bn_uid );
			if ( ! $bn_user ) {
				continue;
			}
			$bn_url  = PageRouter::profile_url( $bn_uid );
			$bn_av   = (string) get_avatar_url( $bn_uid, array( 'size' => 40 ) );
			$bn_tone = array( 'violet', 'amber', 'emerald', 'rose', 'sky' )[ $bn_uid % 5 ];
			?>
			<li class="bn-ex-person">
				<a class="bn-ex-person__id" href="<?php echo esc_url( $bn_url ); ?>">
					<span class="bn-avatar" data-size="md" data-tone="<?php echo esc_attr( $bn_tone ); ?>">
						<?php if ( '' !== $bn_av ) : ?>
							<img src="<?php echo esc_url( $bn_av ); ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
						<?php else : ?>
							<?php echo esc_html( mb_strtoupper( mb_substr( (string) $bn_user->display_name, 0, 1 ) ) ); ?>
						<?php endif; ?>
					</span>
					<span class="bn-ex-person__text">
						<span class="bn-ex-person__name"><?php echo esc_html( $bn_user->display_name ); ?></span>
						<span class="bn-ex-person__meta">@<?php echo esc_html( $bn_user->user_nicename ); ?></span>
					</span>
				</a>
				<?php
				if ( $bn_viewer > 0 && $bn_viewer !== $bn_uid ) {
					$user_id = $bn_uid;
					buddynext_get_template( 'partials/follow-button.php', array( 'user_id' => $bn_uid ) );
				}
				?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
	buddynext_get_template(
		'parts/sidebar-card.php',
		array(
			'id'            => 'explore-people',
			'title'         => __( 'People to discover', 'buddynext' ),
			'title_icon'    => 'users',
			'body_html'     => (string) ob_get_clean(),
			'see_all_url'   => PageRouter::people_url(),
			'see_all_label' => __( 'All', 'buddynext' ),
		)
	);
}

// ── Browse by category ─────────────────────────────────────────────────────
// Service-owned categories with a live per-row space count (single owner of
// bn_space_categories); the directory chip row consumes the same source.
$bn_categories = ( new SpaceService() )->categories_with_counts( 6 );

if ( ! empty( $bn_categories ) ) {
	$bn_spaces_base = PageRouter::spaces_url();
	ob_start();
	?>
	<div class="bn-ex-cats">
		<?php
		foreach ( $bn_categories as $bn_cat ) :
			$bn_cat_slug  = (string) ( $bn_cat['slug'] ?? '' );
			$bn_cat_name  = (string) ( $bn_cat['name'] ?? '' );
			$bn_cat_count = (int) ( $bn_cat['space_count'] ?? 0 );
			if ( '' === $bn_cat_slug ) {
				continue;
			}
			?>
			<a class="bn-ex-cat" href="<?php echo esc_url( add_query_arg( 'category', $bn_cat_slug, $bn_spaces_base ) ); ?>">
				<span class="bn-ex-cat__name"><?php echo esc_html( $bn_cat_name ); ?></span>
				<span class="bn-ex-cat__count">
					<?php
					/* translators: %s: formatted space count. */
					echo esc_html( sprintf( _n( '%s space', '%s spaces', $bn_cat_count, 'buddynext' ), number_format_i18n( $bn_cat_count ) ) );
					?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
	buddynext_get_template(
		'parts/sidebar-card.php',
		array(
			'id'            => 'explore-browse',
			'title'         => __( 'Browse', 'buddynext' ),
			'title_icon'    => 'compass',
			'body_html'     => (string) ob_get_clean(),
			'see_all_url'   => PageRouter::spaces_url(),
			'see_all_label' => __( 'All spaces', 'buddynext' ),
		)
	);
}
