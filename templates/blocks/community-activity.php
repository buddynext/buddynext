<?php
/**
 * Block template: Community activity.
 *
 * "Here is how things are going." Proof the place is alive, which is the single
 * most persuasive thing on a community landing page.
 *
 * ALWAYS PUBLIC, AND NOT NEGOTIABLE. This reads explore_feed(), which selects
 * `privacy = 'public'` only — there is deliberately no scope option. The old
 * Activity Feed block offered a `home` scope, and that is what leaked a
 * personalised feed onto public pages: a guest must never be shown someone
 * else's home timeline.
 *
 * READ-ONLY. A window into the feed, not the feed itself — no composer, no
 * reactions, no comment box. Everything links through to the real surface.
 *
 * SELF-CONTAINED. Styled in assets/css/blocks.css on the bn-base token layer and
 * scoped under its own root, so it renders the same on any block surface in any
 * theme without a hub feature stylesheet.
 *
 * Variables:
 *   int    $count           How many entries to show (1-10).
 *   string $show            all | posts | discussions | media — facets explore_feed() validates.
 *   bool   $show_space_name Whether to name the space a post came from.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\AvatarService;

$bn_ca_count = isset( $count ) ? max( 1, min( 10, (int) $count ) ) : 5;
$bn_ca_show  = isset( $show ) ? sanitize_key( (string) $show ) : 'all';
$bn_ca_space = ! empty( $show_space_name );

// Only the facets FeedService::explore_feed() actually validates. An unknown
// value falls back to everything rather than silently filtering to nothing.
$bn_ca_show = in_array( $bn_ca_show, array( 'all', 'posts', 'discussions', 'media' ), true ) ? $bn_ca_show : 'all';

$bn_ca_result = buddynext_service( 'feed' )->explore_feed( null, $bn_ca_count, $bn_ca_show );
$bn_ca_items  = (array) ( $bn_ca_result['items'] ?? array() );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output.
?>
<section
<?php
echo get_block_wrapper_attributes(
	array(
		'class'      => 'bn-card bn-block-community-activity',
		'data-facet' => $bn_ca_show,
	)
);
?>
>
<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
	<h3 class="bn-block-heading"><?php esc_html_e( 'Recent activity', 'buddynext' ); ?></h3>

	<?php if ( empty( $bn_ca_items ) ) : ?>
		<?php
		// A quiet community is honest information for the OWNER: it tells them to
		// seed content rather than leaving a blank panel on their landing page.
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'megaphone',
				'title' => __( 'Nothing public yet', 'buddynext' ),
				'body'  => __( 'Public posts from the community will appear here.', 'buddynext' ),
			)
		);
		?>
	<?php else : ?>
		<ul class="bn-ca-list">
			<?php foreach ( $bn_ca_items as $bn_ca_item ) : ?>
				<?php
				$bn_ca_author = (int) ( $bn_ca_item['user_id'] ?? 0 );
				if ( $bn_ca_author <= 0 ) {
					continue;
				}

				$bn_ca_user    = get_userdata( $bn_ca_author );
				$bn_ca_name    = $bn_ca_user ? $bn_ca_user->display_name : __( 'Someone', 'buddynext' );
				$bn_ca_avatar  = (string) get_avatar_url( $bn_ca_author, array( 'size' => 80 ) );
				$bn_ca_pid     = (int) ( $bn_ca_item['id'] ?? 0 );
				$bn_ca_link    = $bn_ca_pid > 0 ? (string) PageRouter::post_url( $bn_ca_pid ) : '';
				$bn_ca_excerpt = wp_trim_words( wp_strip_all_tags( (string) ( $bn_ca_item['content'] ?? '' ) ), 18 );
				$bn_ca_when    = buddynext_time_ago( (string) ( $bn_ca_item['created_at'] ?? '' ) );

				$bn_ca_space_name = '';
				if ( $bn_ca_space && ! empty( $bn_ca_item['space_id'] ) ) {
					$bn_ca_space_row  = buddynext_service( 'spaces' )->get( (int) $bn_ca_item['space_id'] );
					$bn_ca_space_name = is_array( $bn_ca_space_row ) ? (string) ( $bn_ca_space_row['name'] ?? '' ) : '';
				}
				?>
				<li class="bn-ca-item">
					<span class="bn-avatar bn-ca-item__avatar" data-size="sm" aria-hidden="true">
						<?php if ( '' !== $bn_ca_avatar ) : ?>
							<img src="<?php echo esc_url( $bn_ca_avatar ); ?>" alt="" width="32" height="32" loading="lazy" decoding="async">
						<?php else : ?>
							<span class="bn-ca-item__initials"><?php echo esc_html( AvatarService::initials_for( $bn_ca_name ) ); ?></span>
						<?php endif; ?>
					</span>

					<span class="bn-ca-item__body">
						<span class="bn-ca-item__head">
							<a class="bn-ca-item__author" href="<?php echo esc_url( PageRouter::profile_url( $bn_ca_author ) ); ?>"><?php echo esc_html( $bn_ca_name ); ?></a>
							<?php if ( '' !== $bn_ca_space_name ) : ?>
								<?php
								printf(
									/* translators: %s: space name. */
									esc_html__( 'posted in %s', 'buddynext' ),
									'<span class="bn-ca-item__space">' . esc_html( $bn_ca_space_name ) . '</span>'
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'posted', 'buddynext' ); ?>
							<?php endif; ?>
						</span>

						<?php if ( '' !== $bn_ca_excerpt ) : ?>
							<?php if ( '' !== $bn_ca_link ) : ?>
								<a class="bn-ca-item__excerpt" href="<?php echo esc_url( $bn_ca_link ); ?>"><?php echo esc_html( $bn_ca_excerpt ); ?></a>
							<?php else : ?>
								<span class="bn-ca-item__excerpt"><?php echo esc_html( $bn_ca_excerpt ); ?></span>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( '' !== $bn_ca_when ) : ?>
							<span class="bn-ca-item__when"><?php echo esc_html( $bn_ca_when ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
