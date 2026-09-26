<?php
/**
 * A drive's Trash: trashed folders with Restore (drive managers only).
 *
 * Rendered by RendersDriveFiles for `?bn_trash=1`. Each row is a trashed
 * subtree root; restoring it brings everything inside back with it.
 *
 * @package BuddyNext
 *
 * @var string                         $bn_sft_drive    Drive descriptor ('space:12').
 * @var string                         $bn_sft_base_url The Files URL (Back link).
 * @var array<int,array<string,mixed>> $bn_sft_items    Trashed folder rows.
 * @var int                            $bn_sft_page     Current page.
 * @var int                            $bn_sft_pages    Page count.
 */

defined( 'ABSPATH' ) || exit;

$bn_sft_items = isset( $bn_sft_items ) && is_array( $bn_sft_items ) ? $bn_sft_items : array();
$bn_sft_page  = isset( $bn_sft_page ) ? max( 1, (int) $bn_sft_page ) : 1;
$bn_sft_pages = isset( $bn_sft_pages ) ? max( 1, (int) $bn_sft_pages ) : 1;
$bn_sft_back  = remove_query_arg( array( 'bn_trash', 'bn_files_page' ), (string) $bn_sft_base_url );
?>
<div class="bn-space-files bn-files-trash"
	data-bn-folder-manage
	data-bn-folder-endpoint="<?php echo esc_url( rest_url( 'mvs-pro/v1/folders' ) ); ?>"
	data-bn-drive="<?php echo esc_attr( (string) $bn_sft_drive ); ?>"
	data-bn-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	data-bn-folder-strings="<?php echo esc_attr( (string) wp_json_encode( buddynext_drive_folder_strings() ) ); ?>"
>
	<nav class="bn-files__crumbs" aria-label="<?php esc_attr_e( 'Folder path', 'buddynext' ); ?>">
		<a class="bn-files__crumb" href="<?php echo esc_url( $bn_sft_back ); ?>"><?php esc_html_e( 'Files', 'buddynext' ); ?></a>
		<span class="bn-files__crumb-sep" aria-hidden="true"><?php echo buddynext_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?></span>
		<span class="bn-files__crumb bn-files__crumb--current" aria-current="page"><?php esc_html_e( 'Trash', 'buddynext' ); ?></span>
	</nav>

	<?php if ( empty( $bn_sft_items ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'trash',
				'title' => __( 'Trash is empty', 'buddynext' ),
				'body'  => __( 'Folders you move to the trash appear here, and you can restore them.', 'buddynext' ),
			)
		);
		?>
	<?php else : ?>
		<p class="bn-files__count"><?php esc_html_e( 'Restoring a folder brings back everything that was inside it.', 'buddynext' ); ?></p>
		<ul class="bn-files__list" role="list">
			<?php foreach ( $bn_sft_items as $bn_sft_f ) : ?>
				<?php
				$bn_sft_id = (int) ( $bn_sft_f['id'] ?? 0 );
				if ( $bn_sft_id <= 0 ) {
					continue;
				}
				?>
				<li class="bn-files__row bn-files__row--folder">
					<span class="bn-files__chip bn-files__chip--dir" aria-hidden="true"><?php buddynext_icon( 'folder' ); ?></span>
					<span class="bn-files__name"><?php echo esc_html( (string) ( $bn_sft_f['name'] ?? '' ) ); ?></span>
					<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-bn-folder-restore data-bn-id="<?php echo esc_attr( (string) $bn_sft_id ); ?>">
						<?php buddynext_icon( 'rotate-ccw' ); ?> <?php esc_html_e( 'Restore', 'buddynext' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $bn_sft_pages > 1 ) : ?>
			<nav class="bn-pagination" aria-label="<?php esc_attr_e( 'Trash pages', 'buddynext' ); ?>">
				<?php if ( $bn_sft_page > 1 ) : ?>
					<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'bn_files_page', $bn_sft_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'buddynext' ); ?></a>
				<?php endif; ?>
				<?php if ( $bn_sft_page < $bn_sft_pages ) : ?>
					<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'bn_files_page', $bn_sft_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'buddynext' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
