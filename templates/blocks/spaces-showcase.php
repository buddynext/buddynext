<?php
/**
 * Block template: Spaces showcase.
 *
 * A few spaces on a landing or About page, chosen by a criterion the site owner
 * picks. Renders the SAME card the spaces directory renders
 * (`parts/space-directory-card.php`), so a showcase grid and the directory can
 * never drift apart.
 *
 * Built for visitors who have NOT joined: every card renders logged-out, the
 * cover/emblem fall back deterministically when a space has no artwork, and an
 * empty result says so instead of leaving a blank gap on the owner's page.
 *
 * Variables:
 *   string $source          'popular' | 'newest' | 'name' | 'picked'.
 *   int    $category_id     Restrict to one category. 0 = any.
 *   int[]  $space_ids       Hand-picked space IDs, used when $source is 'picked'.
 *   int    $count           How many to show (1-6).
 *   string $layout          'grid' | 'list'.
 *   bool   $show_description Render each space's description.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$source           = isset( $source ) ? sanitize_key( (string) $source ) : 'popular';
$category_id      = isset( $category_id ) ? (int) $category_id : 0;
$space_ids        = isset( $space_ids ) && is_array( $space_ids ) ? array_map( 'intval', $space_ids ) : array();
$count            = isset( $count ) ? max( 1, min( 6, (int) $count ) ) : 3;
$layout           = isset( $layout ) && 'list' === $layout ? 'list' : 'grid';
$show_description = isset( $show_description ) ? (bool) $show_description : true;

$bn_ss_service = new \BuddyNext\Spaces\SpaceService();
$bn_ss_viewer  = get_current_user_id();
$bn_ss_admin   = current_user_can( 'manage_options' );

/*
 * 'popular' is member_count and 'newest' is created_at — both index-backed.
 *
 * There is deliberately no "most active" option: bn_spaces has no maintained
 * activity column (only a small fraction of rows carry last_active_at), so the
 * sort would rank most spaces as equally inactive. The directory template
 * records the same decision — without a denormalised column it is popularity
 * relabelled.
 */
$bn_ss_orderby = 'created_at';
if ( 'popular' === $source ) {
	$bn_ss_orderby = 'member_count';
} elseif ( 'name' === $source ) {
	$bn_ss_orderby = 'name';
}

$bn_ss_args = array(
	'per_page' => $count,
	'page'     => 1,
	'orderby'  => $bn_ss_orderby,
	'order'    => 'name' === $bn_ss_orderby ? 'ASC' : 'DESC',
	'viewer'   => $bn_ss_viewer,
	'is_admin' => $bn_ss_admin,
);
if ( $category_id > 0 ) {
	$bn_ss_args['category_id'] = $category_id;
}

if ( 'picked' === $source ) {
	// Hand-picked keeps the owner's ORDER, which is the whole point of picking:
	// they are curating, not sorting. Each id is fetched through the service so
	// visibility (secret, archived) is enforced per space exactly as the
	// directory enforces it — a picked id can never leak a space the viewer
	// could not otherwise see.
	//
	// Branching on the source ALONE is deliberate. This used to also require a
	// non-empty list, so an owner who chose "Hand-picked" and had not picked
	// anything yet fell through to the popularity query and was shown three
	// spaces they had not chosen, presented as their featured selection. An
	// empty selection is a selection: it renders the empty state below, the
	// same as a category that matches nothing. members-showcase already
	// branches this way.
	$bn_ss_spaces = array();
	foreach ( array_slice( $space_ids, 0, $count ) as $bn_ss_pick ) {
		$bn_ss_row = $bn_ss_service->get( $bn_ss_pick );
		if ( $bn_ss_row && \BuddyNext\Spaces\SpaceVisibility::can_view_space( $bn_ss_row, $bn_ss_viewer ) ) {
			$bn_ss_spaces[] = $bn_ss_row;
		}
	}
} else {
	$bn_ss_spaces = $bn_ss_service->list_spaces( $bn_ss_args );
}

// Membership for every card in one query — the directory's own approach, so a
// showcase of six spaces costs one lookup rather than six.
$bn_ss_membership_map = array();
if ( $bn_ss_viewer > 0 && ! empty( $bn_ss_spaces ) ) {
	$bn_ss_membership_map = ( new \BuddyNext\Spaces\SpaceMemberService() )->membership_map(
		$bn_ss_viewer,
		array_map( static fn( $s ): int => (int) $s['id'], $bn_ss_spaces )
	);
}

$bn_ss_cat_by_id = array();
foreach ( $bn_ss_service->categories_with_counts( 0, true ) as $bn_ss_cat ) {
	$bn_ss_cat_by_id[ (int) $bn_ss_cat['id'] ] = $bn_ss_cat;
}
?>
<div 
<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output.
echo get_block_wrapper_attributes(
	array(
		'class'       => 'bn-spaces-showcase',
		'data-layout' => $layout,
	)
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
>
	<?php if ( empty( $bn_ss_spaces ) ) : ?>
		<?php
		/*
		 * A visible empty state, never a blank gap. The owner placed this block
		 * on a page and needs to know it rendered and found nothing — silence
		 * reads as "the block is broken".
		 */
		?>
		<div class="bn-empty-state bn-empty-state--inline">
			<p class="bn-empty-state__title"><?php esc_html_e( 'No spaces to show yet', 'buddynext' ); ?></p>
			<p class="bn-empty-state__text">
				<?php esc_html_e( 'Spaces will appear here once they match this block’s settings.', 'buddynext' ); ?>
			</p>
			<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::hub_url( 'buddynext_slug_spaces', 'buddynext_page_spaces' ) ); ?>">
				<?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?>
			</a>
		</div>
	<?php else : ?>
		<div class="bn-sd-grid" role="list" data-layout="<?php echo esc_attr( $layout ); ?>">
			<?php
			foreach ( $bn_ss_spaces as $bn_ss_space ) {
				$bn_ss_row = $bn_ss_space;
				if ( ! $show_description ) {
					// The part renders a description when the row carries one;
					// clearing it here is how the owner's toggle is honoured
					// without the part needing to know about blocks.
					$bn_ss_row['description'] = '';
				}
				buddynext_get_template(
					'parts/space-directory-card.php',
					array(
						'space'           => $bn_ss_row,
						'membership'      => $bn_ss_membership_map[ (int) $bn_ss_space['id'] ] ?? null,
						'current_user_id' => $bn_ss_viewer,
						'cat_by_id'       => $bn_ss_cat_by_id,
						'subspace_count'  => 0,
					)
				);
			}
			?>
		</div>
	<?php endif; ?>
</div>
