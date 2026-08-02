<?php
/**
 * BuddyNext template part: profile Articles panel (WB Member Blog).
 *
 * The registry content seam for the profile Articles tab. The bridge owns all post
 * access and hands this part the resolved payload; the part only renders it.
 * Rendered by MemberBlogBridge::render_profile_articles_panel.
 *
 * Reference, not embed: every row links OUT to the post, and the owner's controls
 * link OUT to Member Blog's dashboard. BuddyNext never edits a post.
 *
 * @package BuddyNext
 *
 * @var int        $profile_user_id Profile being viewed.
 * @var int        $viewer_id       Viewer user id (0 = logged out).
 * @var bool       $is_owner        Whether the viewer is the profile owner.
 * @var \WP_Post[] $articles        Posts for this page.
 * @var int        $article_count   Total matching posts across all pages.
 * @var int        $page            Current page (1-based).
 * @var int        $total_pages     Total pages.
 * @var string     $dashboard_url   Where the owner writes/manages ('' = no affordance).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_ar_uid       = isset( $profile_user_id ) ? (int) $profile_user_id : 0;
$bn_ar_owner     = ! empty( $is_owner );
$bn_ar_rows      = isset( $articles ) && is_array( $articles ) ? $articles : array();
$bn_ar_total     = isset( $article_count ) ? (int) $article_count : count( $bn_ar_rows );
$bn_ar_page      = isset( $page ) ? max( 1, (int) $page ) : 1;
$bn_ar_pages     = isset( $total_pages ) ? max( 1, (int) $total_pages ) : 1;
$bn_ar_dashboard = isset( $dashboard_url ) ? (string) $dashboard_url : '';

// The owner's route back to where they write. Shown ABOVE the list and also in the
// empty state, because a member with nothing published is exactly who needs it.
$bn_ar_manage = $bn_ar_owner && '' !== $bn_ar_dashboard;

if ( empty( $bn_ar_rows ) ) :
	?>
	<div class="bn-empty-state">
		<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'file-text' ); ?></div>
		<div class="bn-empty-title">
			<?php
			echo $bn_ar_owner
				? esc_html__( 'You have not published an article yet.', 'buddynext' )
				: esc_html__( 'No articles yet.', 'buddynext' );
			?>
		</div>
		<?php if ( $bn_ar_manage ) : ?>
			<div class="bn-empty-sub">
				<a class="bn-btn bn-btn--primary" href="<?php echo esc_url( $bn_ar_dashboard ); ?>">
					<?php esc_html_e( 'Write your first article', 'buddynext' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;
?>

<?php if ( $bn_ar_manage ) : ?>
	<div class="bn-articles__manage">
		<a class="bn-btn bn-btn--primary" href="<?php echo esc_url( $bn_ar_dashboard ); ?>">
			<?php buddynext_icon( 'edit' ); ?>
			<?php esc_html_e( 'Write a new article', 'buddynext' ); ?>
		</a>
		<a class="bn-btn bn-btn--secondary" href="<?php echo esc_url( $bn_ar_dashboard ); ?>">
			<?php esc_html_e( 'Manage articles', 'buddynext' ); ?>
		</a>
	</div>
<?php endif; ?>

<ul class="bn-articles" role="list">
	<?php
	foreach ( $bn_ar_rows as $bn_ar_post ) :
		if ( ! $bn_ar_post instanceof WP_Post ) {
			continue;
		}

		$bn_ar_id     = (int) $bn_ar_post->ID;
		$bn_ar_link   = (string) get_permalink( $bn_ar_id );
		$bn_ar_title  = get_the_title( $bn_ar_id );
		$bn_ar_title  = '' !== trim( (string) $bn_ar_title ) ? $bn_ar_title : __( '(untitled)', 'buddynext' );
		$bn_ar_thumb  = (string) get_the_post_thumbnail_url( $bn_ar_id, 'medium' );
		$bn_ar_status = (string) $bn_ar_post->post_status;
		?>
		<li class="bn-articles__item">
			<article class="bn-card bn-article-card">
				<?php if ( '' !== $bn_ar_thumb ) : ?>
					<a class="bn-article-card__media" href="<?php echo esc_url( $bn_ar_link ); ?>" tabindex="-1" aria-hidden="true">
						<img src="<?php echo esc_url( $bn_ar_thumb ); ?>" alt="" loading="lazy" decoding="async" />
					</a>
				<?php endif; ?>

				<div class="bn-article-card__body">
					<h3 class="bn-article-card__title">
						<a href="<?php echo esc_url( $bn_ar_link ); ?>"><?php echo esc_html( $bn_ar_title ); ?></a>
						<?php if ( 'publish' !== $bn_ar_status ) : ?>
							<?php
							// Only the owner (and editors) ever reach a non-published row -
							// the bridge does not return them to anyone else - but the badge
							// is what stops an unfinished draft reading as live.
							$bn_ar_obj   = get_post_status_object( $bn_ar_status );
							$bn_ar_label = $bn_ar_obj->label ?? $bn_ar_status;
							?>
							<span class="bn-badge bn-badge--muted"><?php echo esc_html( $bn_ar_label ); ?></span>
						<?php endif; ?>
					</h3>

					<div class="bn-article-card__meta">
						<time datetime="<?php echo esc_attr( (string) get_the_date( 'c', $bn_ar_id ) ); ?>">
							<?php echo esc_html( (string) get_the_date( '', $bn_ar_id ) ); ?>
						</time>
					</div>

					<?php
					$bn_ar_excerpt = trim( wp_strip_all_tags( (string) get_the_excerpt( $bn_ar_id ) ) );
					if ( '' !== $bn_ar_excerpt ) :
						?>
						<p class="bn-article-card__excerpt"><?php echo esc_html( wp_trim_words( $bn_ar_excerpt, 28 ) ); ?></p>
					<?php endif; ?>

					<?php if ( $bn_ar_owner && '' !== $bn_ar_dashboard ) : ?>
						<div class="bn-article-card__actions">
							<a class="bn-btn bn-btn--secondary bn-btn--sm" href="<?php echo esc_url( $bn_ar_dashboard ); ?>">
								<?php esc_html_e( 'Edit', 'buddynext' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</article>
		</li>
	<?php endforeach; ?>
</ul>

<?php
// Pagination. A member who has written for years is the normal case for this tab,
// not an edge one, so the list is bounded from the first release rather than after
// somebody reports a slow profile.
if ( $bn_ar_pages > 1 ) :
	$bn_ar_base = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $bn_ar_uid ) ) . 'articles/';
	?>
	<nav class="bn-pagination" aria-label="<?php esc_attr_e( 'Articles pagination', 'buddynext' ); ?>">
		<?php if ( $bn_ar_page > 1 ) : ?>
			<a class="bn-btn bn-btn--secondary" href="<?php echo esc_url( add_query_arg( 'bn_page', $bn_ar_page - 1, $bn_ar_base ) ); ?>" rel="prev">
				<?php esc_html_e( 'Previous', 'buddynext' ); ?>
			</a>
		<?php endif; ?>

		<span class="bn-pagination__status">
			<?php
			printf(
				/* translators: 1: current page number, 2: total number of pages. */
				esc_html__( 'Page %1$s of %2$s', 'buddynext' ),
				esc_html( number_format_i18n( $bn_ar_page ) ),
				esc_html( number_format_i18n( $bn_ar_pages ) )
			);
			?>
		</span>

		<?php if ( $bn_ar_page < $bn_ar_pages ) : ?>
			<a class="bn-btn bn-btn--secondary" href="<?php echo esc_url( add_query_arg( 'bn_page', $bn_ar_page + 1, $bn_ar_base ) ); ?>" rel="next">
				<?php esc_html_e( 'Next', 'buddynext' ); ?>
			</a>
		<?php endif; ?>
	</nav>
<?php endif; ?>
