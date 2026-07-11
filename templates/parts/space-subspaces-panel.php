<?php
/**
 * BuddyNext — Sub-spaces panel (space tab).
 *
 * The viewport-independent home for a space's children. Their only entry point
 * used to be a card in the right sidebar, which is `display: none` below 1024px
 * — so on every phone and tablet a parent space offered no path at all to its
 * own sub-spaces, and a manager could not even create one (the "Add sub-space"
 * CTA lives in that same hidden card). This tab is reachable at every width.
 *
 * The list is already visibility-scoped by SpaceService::get_subspaces(), so a
 * secret child the viewer may not see never reaches this template.
 *
 * Context variables:
 *   $space_id   (int)   — parent space id.
 *   $viewer_id  (int)   — viewer user id (0 = logged out).
 *   $subspaces  (array) — visible children.
 *   $can_manage (bool)  — viewer may add a sub-space here.
 *
 * Overridable: copy to {theme}/buddynext/parts/space-subspaces-panel.php
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_sp_space_id   = isset( $space_id ) ? absint( $space_id ) : 0;
$bn_sp_subspaces  = isset( $subspaces ) && is_array( $subspaces ) ? $subspaces : array();
$bn_sp_can_manage = ! empty( $can_manage );

if ( $bn_sp_space_id <= 0 ) {
	return;
}
?>

<div class="bn-space-subspaces">

	<?php if ( ! empty( $bn_sp_subspaces ) ) : ?>

		<ul class="bn-space-subspaces__list" role="list">
			<?php
			foreach ( $bn_sp_subspaces as $bn_sp_sub ) :
				$bn_sp_sub_id    = (int) ( $bn_sp_sub['id'] ?? 0 );
				$bn_sp_sub_name  = (string) ( $bn_sp_sub['name'] ?? __( 'Space', 'buddynext' ) );
				$bn_sp_sub_slug  = (string) ( $bn_sp_sub['slug'] ?? '' );
				$bn_sp_sub_desc  = (string) ( $bn_sp_sub['description'] ?? '' );
				$bn_sp_sub_count = (int) ( $bn_sp_sub['member_count'] ?? 0 );

				if ( '' === $bn_sp_sub_slug ) {
					continue;
				}
				?>
				<li class="bn-card bn-space-subspaces__item">
					<a class="bn-space-subspaces__link" href="<?php echo esc_url( buddynext_space_url( $bn_sp_sub_slug ) ); ?>">
						<span class="bn-avatar bn-space-subspaces__emblem" data-size="md" aria-hidden="true">
							<?php echo esc_html( mb_strtoupper( mb_substr( $bn_sp_sub_name, 0, 1 ) ) ); ?>
						</span>
						<span class="bn-space-subspaces__body">
							<span class="bn-space-subspaces__name"><?php echo esc_html( $bn_sp_sub_name ); ?></span>
							<?php if ( '' !== $bn_sp_sub_desc ) : ?>
								<span class="bn-space-subspaces__desc"><?php echo esc_html( wp_trim_words( $bn_sp_sub_desc, 18 ) ); ?></span>
							<?php endif; ?>
							<span class="bn-space-subspaces__meta">
								<?php
								printf(
									/* translators: %s: number of members in the sub-space. */
									esc_html( _n( '%s member', '%s members', $bn_sp_sub_count, 'buddynext' ) ),
									esc_html( number_format_i18n( $bn_sp_sub_count ) )
								);
								?>
							</span>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php elseif ( $bn_sp_can_manage ) : ?>

		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'layers',
				'title' => __( 'No sub-spaces yet', 'buddynext' ),
				'body'  => __( 'Organize this space into focused sub-spaces members can join on their own.', 'buddynext' ),
			)
		);
		?>

	<?php endif; ?>

	<?php if ( $bn_sp_can_manage ) : ?>
		<div class="bn-space-subspaces__cta" data-wp-interactive="buddynext/spaces">
			<button
				type="button"
				class="bn-btn bn-space-subspaces__add"
				data-variant="primary"
				data-wp-on--click="actions.openCreate"
				data-bn-create-space-trigger
			>
				<?php buddynext_icon( 'plus' ); ?>
				<?php esc_html_e( 'Add sub-space', 'buddynext' ); ?>
			</button>
		</div>
	<?php endif; ?>

</div>
