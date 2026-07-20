<?php
/**
 * BuddyNext template part: sidebar-explore-browse.
 *
 * Self-chromed "Browse" (categories) card for the Explore aside. Extracted
 * verbatim from the former `templates/feed/parts/explore-aside.php`
 * (browse-by-category block) so ExploreSidebarProvider can render it as a
 * `chrome => false` widget descriptor — markup, gating, and data source
 * unchanged, only the data now arrives via the provider's render closure
 * instead of the partial fetching it inline.
 *
 * @package BuddyNext
 * @since   1.6.0
 *
 * @var array<int,array<string,mixed>> $bn_categories Rows from SpaceService::categories_with_counts(). Empty renders nothing.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$bn_categories = isset( $bn_categories ) ? (array) $bn_categories : array();

if ( empty( $bn_categories ) ) {
	return;
}

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
