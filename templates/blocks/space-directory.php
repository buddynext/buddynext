<?php
/**
 * Block template: Space Directory (v2 design system).
 *
 * Compact directory of open spaces — wrapped in .bn-card and each row uses the
 * .bn-avatar primitive. Pagination uses the .bn-btn ghost variant.
 *
 * Variables:
 *   int    $per_page Number of spaces to display.
 *   string $layout   'grid' | 'list'.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_per_page = $per_page ?? 12;
$layout      = $layout ?? 'grid';
$layout      = in_array( $layout, array( 'grid', 'list' ), true ) ? $layout : 'grid';

$bn_sd_viewer = get_current_user_id();

$spaces   = buddynext_service( 'spaces' )->list_spaces(
	array(
		'per_page' => $bn_per_page,
		'type'     => 'open',
		'viewer'   => $bn_sd_viewer,
		'is_admin' => current_user_can( 'manage_options' ),
	)
);
$has_more = false;

/*
 * Membership for the whole page in ONE query, the way the directory itself
 * resolves it, so a twelve-space block costs one lookup rather than twelve.
 */
$bn_sd_membership = array();
if ( $bn_sd_viewer > 0 && ! empty( $spaces ) ) {
	$bn_sd_membership = ( new \BuddyNext\Spaces\SpaceMemberService() )->membership_map(
		$bn_sd_viewer,
		array_map( static fn( $s ): int => (int) ( $s['id'] ?? 0 ), $spaces )
	);
}

$bn_sd_cats = array();
foreach ( buddynext_service( 'spaces' )->categories_with_counts( 0, true ) as $bn_sd_cat ) {
	$bn_sd_cats[ (int) $bn_sd_cat['id'] ] = $bn_sd_cat;
}
?>
<section
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output, and buddynext_spaces_directory_context() returns pre-escaped JSON.
	echo get_block_wrapper_attributes(
		array(
			'class'                => 'bn-card bn-block-space-directory bn-block-space-directory--' . $layout,
			'data-layout'          => $layout,
			// The space cards below carry actions.joinSpace / actions.requestJoin /
			// actions.leaveSpace directives; they need this Interactivity ancestor
			// (namespace + restNonce/restUrl) or every Join button is inert. Same
			// store the /spaces/ directory provides — sourced from one helper so the
			// page and the block cannot drift.
			'data-wp-interactive'  => 'buddynext/spaces',
			'data-wp-context'      => buddynext_spaces_directory_context(),
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>
	<h3 class="bn-block-heading"><?php esc_html_e( 'Spaces', 'buddynext' ); ?></h3>
	<?php if ( empty( $spaces ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'hash',
				'title' => __( 'No spaces yet', 'buddynext' ),
				'body'  => __( 'Open spaces will appear here once they are created.', 'buddynext' ),
			)
		);
		?>
	<?php else : ?>
		<?php
		/*
		 * Render the SAME card the spaces directory renders.
		 *
		 * This block used to emit its own <ul class="bn-space-list"> with
		 * bn-space-item / bn-space-link children -- markup that no stylesheet
		 * ever defined, so on an ordinary page it rendered as a raw list with
		 * disc bullets and a 40px indent. The block.json compounded it by
		 * declaring the `bn-profile` handle, a stylesheet with nothing for this
		 * block in it, which looks like a copy-paste from a sibling.
		 *
		 * Delegating removes the whole class rather than defining styles for
		 * one-off classes: there is now no second implementation to drift, and
		 * the block inherits the directory's states, badges and actions for
		 * free. Same move that fixed the space card and the member card.
		 */
		?>
		<div class="bn-sd-grid" role="list" data-layout="<?php echo esc_attr( $layout ); ?>">
			<?php foreach ( $spaces as $bn_sd_space ) : ?>
				<?php
				buddynext_get_template(
					'parts/space-directory-card.php',
					array(
						'space'           => $bn_sd_space,
						'membership'      => $bn_sd_membership[ (int) ( $bn_sd_space['id'] ?? 0 ) ] ?? null,
						'current_user_id' => $bn_sd_viewer,
						'cat_by_id'       => $bn_sd_cats,
						'subspace_count'  => 0,
					)
				);
				?>
			<?php endforeach; ?>
		</div>
		<?php if ( $has_more ) : ?>
			<?php
			/*
			 * A LINK, for the same reason member-directory carries one: the button
			 * this replaces declared data-block / data-page / data-per-page that no
			 * script read, because this block registers no view script either. It
			 * was copied from the member-directory block along with the defect
			 * (Basecamp 10184505772).
			 */
			?>
			<a
				class="bn-btn"
				data-variant="secondary"
				data-size="sm"
				href="<?php echo esc_url( \BuddyNext\Core\PageRouter::hub_url( 'buddynext_slug_spaces', 'buddynext_page_spaces' ) ); ?>"
			>
				<?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?>
			</a>
		<?php endif; ?>
	<?php endif; ?>
</section>
