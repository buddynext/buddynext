<?php
/**
 * Member Blog's Add new post and My Blog Dashboard pages, inside the shell.
 *
 * BuddyNext frames the page (heading, two tabs) and renders the page's own
 * content: Member Blog's form or dashboard, unchanged. A member Member Blog does
 * not let write gets no tabs, and the page answers with Member Blog's own
 * "not available" notice. A suspended or pending-approval member, or an edit
 * link to a post that is missing or not theirs, gets BuddyNext's notice instead
 * of the page (MemberBlogBridge::write_hub_notice()). Routed by
 * MemberBlogBridge::route_write_pages().
 *
 * Overridable: copy to {theme}/buddynext/blog/write-hub.php.
 *
 * @package BuddyNext
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

use BuddyNext\Bridges\MemberBlogBridge;

$bn_write_page = MemberBlogBridge::write_hub_page();
if ( ! $bn_write_page instanceof WP_Post ) {
	return;
}
$bn_write_tabs    = MemberBlogBridge::write_hub_tabs();
$bn_write_notice  = MemberBlogBridge::write_hub_notice();
$bn_write_prefill = MemberBlogBridge::write_hub_prefill_title();
?>
<?php // data-bn-prefill-title: the title typed in the composer's Article tab; assets/js/blog/write-prefill.js puts it in Member Blog's empty title field. ?>
<div class="bn-blog-write"<?php echo '' !== $bn_write_prefill ? ' data-bn-prefill-title="' . esc_attr( $bn_write_prefill ) . '"' : ''; ?>>
	<header class="bn-blog-write__head">
		<?php if ( array() !== $bn_write_tabs ) : ?>
			<a class="bn-blog-write__back" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::activity_url() ); ?>">
				<?php buddynext_icon( 'arrow-left' ); ?>
				<span><?php esc_html_e( "Back to What's New", 'buddynext' ); ?></span>
			</a>
		<?php endif; ?>
		<h1 class="bn-blog-write__title"><?php echo esc_html( MemberBlogBridge::write_hub_heading() ); ?></h1>
		<?php if ( array() !== $bn_write_tabs ) : ?>
			<p class="bn-blog-write__sub"><?php esc_html_e( 'Share an article with the community. It appears on your profile and in the feed.', 'buddynext' ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( array() !== $bn_write_tabs ) : ?>
		<?php // Each tab is its own page, so this is page navigation (links + aria-current), not an ARIA tab widget. ?>
		<nav class="bn-tabs bn-blog-write__tabs" aria-label="<?php esc_attr_e( 'Writing', 'buddynext' ); ?>">
			<?php foreach ( $bn_write_tabs as $bn_write_tab ) : ?>
				<a
					class="bn-tab<?php echo $bn_write_tab['current'] ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $bn_write_tab['url'] ); ?>"
					<?php echo $bn_write_tab['current'] ? 'aria-current="page"' : ''; ?>
				>
					<span class="bn-tab__label"><?php echo esc_html( $bn_write_tab['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="bn-blog-write__body">
		<?php if ( null !== $bn_write_notice ) : ?>
			<div role="status">
				<?php buddynext_get_template( 'parts/empty-state.php', $bn_write_notice ); ?>
			</div>
		<?php else : ?>
			<?php
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the page's own content needs it as the current post, restored below.
			$GLOBALS['post'] = $bn_write_page;
			setup_postdata( $bn_write_page );
			// Member Blog renders its form only inside the main loop (it refuses
			// out-of-loop the_content calls, which is how feed excerpts are built).
			// This IS the page's main content, so mark the loop for the call.
			$bn_write_in_loop                 = $GLOBALS['wp_query']->in_the_loop;
			$GLOBALS['wp_query']->in_the_loop = true;
			the_content();
			$GLOBALS['wp_query']->in_the_loop = $bn_write_in_loop;
			wp_reset_postdata();
			?>
		<?php endif; ?>
	</div>
</div>
