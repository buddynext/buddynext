<?php
/**
 * BuddyNext single-post permalink template.
 *
 * Resolves `/p/{id}/` to a dedicated detail page: breadcrumb, the full post
 * card, an expanded comment thread, and head meta tags (OG / Twitter /
 * canonical) so the URL deep-links cleanly into chat clients and social
 * networks. Renders inside the shell main column via templates/shell/hub-shell.php.
 *
 * Visibility is enforced server-side by ASKING the shared gate rather than
 * re-deriving it: `PostService::visibility_error()` — the same method
 * `PostController::get_post()`, the comment / reaction / poll read endpoints and
 * the space feed all call — covers blocks, space-content membership,
 * followers-only and private posts. This template adds only the two gates that
 * live outside it: unpublished status, and a suspended / shadow-banned author.
 *
 * It used to hand-roll all four, and its space gate tested a literal
 * `'secret' === $type`, so a post in a PRIVATE (request-to-join) space rendered
 * in full to an anonymous visitor holding the link.
 *
 * Anything that fails a gate renders the 404 state ("This post is private or
 * unavailable.") rather than disclosing that the ID resolves to a real row.
 *
 * Overridable: copy to {theme}/buddynext/feed/single-post.php
 *
 * @package BuddyNext
 * @since   1.5.0
 *
 * @var int $post_id Post ID resolved from the /p/{id}/ rewrite.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\PostService;

// Declares this fine-grained surface for the sidebar registry (SidebarRegistry
// reads it via Surface::current()) before the shell renders the right column.
\BuddyNext\Sidebar\Surface::set( 'single-post' );

$bn_post_id     = isset( $post_id ) ? (int) $post_id : (int) get_query_var( 'bn_post_id', 0 );
$bn_viewer_id   = get_current_user_id();
$bn_post_record = $bn_post_id > 0 ? ( new PostService() )->get( $bn_post_id ) : null;

// ── Visibility gates (shared with PostController::get_post()) ─────────────
$bn_visible = null !== $bn_post_record;

if ( $bn_visible ) {
	$bn_author_id = (int) ( $bn_post_record['user_id'] ?? 0 );

	// Gate: status must be published.
	if ( isset( $bn_post_record['status'] ) && 'published' !== $bn_post_record['status'] && $bn_viewer_id !== $bn_author_id ) {
		$bn_visible = false;
	}

	// Gates 1-4: blocks, space-content membership, followers-only and private,
	// answered by the one shared method every REST read path also calls. The page
	// and the app cannot disagree because they ask the same question.
	if ( $bn_visible && ( new PostService() )->visibility_error( $bn_post_id, $bn_viewer_id ) instanceof WP_Error ) {
		$bn_visible = false;
	}

	// Gate 5: author suspended or shadow-banned (skip for the author + admins).
	if ( $bn_visible && $bn_author_id > 0 && $bn_author_id !== $bn_viewer_id && ! user_can( $bn_viewer_id, 'manage_options' ) ) {
		$bn_author_suspended = (bool) get_user_meta( $bn_author_id, 'bn_suspended', true );
		$bn_author_shadow    = (bool) get_user_meta( $bn_author_id, 'bn_shadow_banned', true );
		if ( $bn_author_suspended || $bn_author_shadow ) {
			$bn_visible = false;
		}
	}
}

// Head meta tags (OG / Twitter / canonical / document title) are emitted
// by PageRouter::maybe_register_single_post_meta() at template_redirect,
// before get_header() fires wp_head. They can't be wired from here because
// by the time this template runs, wp_head() has already been printed.

if ( ! $bn_visible ) {
	global $wp_query;
	$wp_query->is_404 = true;
	status_header( 404 );
	?>
	<div class="bn-single-post bn-single-post--missing" role="region" aria-label="<?php esc_attr_e( 'Post not found', 'buddynext' ); ?>">
		<div class="bn-feed-empty" role="status" data-state="missing">
			<div class="bn-feed-empty__icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></div>
			<div class="bn-feed-empty__title">
				<?php esc_html_e( 'This post is private or unavailable.', 'buddynext' ); ?>
			</div>
			<p class="bn-feed-empty__text">
				<?php esc_html_e( 'It may have been deleted, made private, or restricted to a space you are not in.', 'buddynext' ); ?>
			</p>
			<a href="<?php echo esc_url( PageRouter::activity_url() ); ?>" class="bn-btn bn-feed-empty__cta" data-variant="primary">
				<?php esc_html_e( 'Back to feed', 'buddynext' ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
		</div>
	</div>
	<?php
	return;
}

// ── Hydrate post for the card partial ──────────────────────────────────────
$bn_post_author_id = (int) ( $bn_post_record['user_id'] ?? 0 );
$bn_post_author    = $bn_post_author_id > 0 ? get_userdata( $bn_post_author_id ) : null;
$bn_author_label   = $bn_post_author ? $bn_post_author->display_name : __( 'Community member', 'buddynext' );
$bn_author_url     = $bn_post_author_id > 0 ? PageRouter::profile_url( $bn_post_author_id ) : PageRouter::activity_url();

$bn_rest_nonce = wp_create_nonce( 'wp_rest' );
?>
<article class="bn-single-post"
	data-bn-rest-nonce="<?php echo esc_attr( $bn_rest_nonce ); ?>"
	data-bn-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1' ) ); ?>"
	data-post-id="<?php echo absint( $bn_post_record['id'] ); ?>">

	<nav class="bn-single-post__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'buddynext' ); ?>">
		<ol class="bn-single-post__crumbs">
			<li>
				<a href="<?php echo esc_url( PageRouter::activity_url() ); ?>">
					<?php esc_html_e( 'Activity', 'buddynext' ); ?>
				</a>
			</li>
			<li aria-hidden="true" class="bn-single-post__crumb-sep">&rsaquo;</li>
			<li>
				<a href="<?php echo esc_url( $bn_author_url ); ?>">
					<?php
					/* translators: %s: post author display name */
					echo esc_html( sprintf( __( '@%s', 'buddynext' ), $bn_post_author ? $bn_post_author->user_nicename : $bn_author_label ) );
					?>
				</a>
			</li>
			<li aria-hidden="true" class="bn-single-post__crumb-sep">&rsaquo;</li>
			<li aria-current="page"><?php esc_html_e( 'Post', 'buddynext' ); ?></li>
		</ol>
	</nav>

	<?php
	buddynext_get_template(
		'partials/post-card.php',
		array(
			'post'            => $bn_post_record,
			'current_user_id' => $bn_viewer_id,
			'context'         => 'single',
		)
	);
	?>

	<?php
	/**
	 * Fires after the single-post card. Bridge plugins (e.g. WPMediaverse,
	 * Jetonomy) can render an alternative thread UI here. The default
	 * post-card partial auto-expands its inline comment thread when the
	 * context is 'single', so most callers will not need to hook this.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $post    Hydrated post record.
	 */
	do_action( 'buddynext_single_post_thread', (int) $bn_post_record['id'], $bn_post_record );
	?>

	<?php
	if ( $bn_viewer_id > 0 ) {
		buddynext_get_template(
			'partials/share-modal.php',
			array( 'current_user_id' => $bn_viewer_id )
		);
	}
	?>
</article>
