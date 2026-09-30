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
				<?php
				$bn_sft_name    = (string) ( $bn_sft_f['name'] ?? '' );
				$bn_sft_manage  = ! isset( $bn_sft_f['can_manage'] ) || (bool) $bn_sft_f['can_manage'];
				$bn_sft_items_n = (int) ( $bn_sft_f['item_count'] ?? 0 );
				// Trash info straight from WPMediaVerse (card 10343724177): who, when,
				// how much, and when it is purged. Each part only when it was sent.
				$bn_sft_info = array();
				if ( ! empty( $bn_sft_f['trashed_at'] ) ) {
					$bn_sft_when   = (int) strtotime( (string) $bn_sft_f['trashed_at'] . ' UTC' );
					$bn_sft_by     = (int) ( $bn_sft_f['trashed_by'] ?? 0 );
					$bn_sft_by_nm  = $bn_sft_by > 0 ? (string) get_the_author_meta( 'display_name', $bn_sft_by ) : '';
					$bn_sft_info[] = '' !== $bn_sft_by_nm
						/* translators: 1: time ago, 2: member name. */
						? sprintf( __( 'Trashed %1$s ago by %2$s', 'buddynext' ), human_time_diff( $bn_sft_when ), $bn_sft_by_nm )
						/* translators: %s: time ago. */
						: sprintf( __( 'Trashed %s ago', 'buddynext' ), human_time_diff( $bn_sft_when ) );
				}
				if ( isset( $bn_sft_f['item_count'] ) ) {
					/* translators: %d: number of items. */
					$bn_sft_info[] = sprintf( _n( '%d item', '%d items', $bn_sft_items_n, 'buddynext' ), $bn_sft_items_n );
				}
				if ( ! empty( $bn_sft_f['purge_at'] ) ) {
					/* translators: %s: time until purge. */
					$bn_sft_info[] = sprintf( __( 'removed in %s', 'buddynext' ), human_time_diff( time(), (int) strtotime( (string) $bn_sft_f['purge_at'] ) ) );
				}
				?>
				<li class="bn-files__row bn-files__row--folder bn-files-trash__row">
					<span class="bn-files__chip bn-files__chip--dir" aria-hidden="true"><?php buddynext_icon( 'folder' ); ?></span>
					<span class="bn-files-trash__main">
						<span class="bn-files__name"><?php echo esc_html( $bn_sft_name ); ?></span>
						<?php if ( $bn_sft_info ) : ?>
							<span class="bn-files-trash__info"><?php echo esc_html( implode( ' · ', $bn_sft_info ) ); ?></span>
						<?php endif; ?>
					</span>
					<?php if ( $bn_sft_manage ) : ?>
					<span class="bn-files-trash__actions">
						<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-bn-folder-restore data-bn-id="<?php echo esc_attr( (string) $bn_sft_id ); ?>">
							<?php buddynext_icon( 'rotate-ccw' ); ?> <?php esc_html_e( 'Restore', 'buddynext' ); ?>
						</button>
						<?php if ( array_key_exists( 'purge_at', $bn_sft_f ) ) : // A WPMediaVerse that reports purge info also deletes permanently. ?>
						<button type="button" class="bn-btn" data-variant="ghost" data-tone="danger" data-size="sm" data-bn-folder-purge data-bn-id="<?php echo esc_attr( (string) $bn_sft_id ); ?>" data-bn-name="<?php echo esc_attr( $bn_sft_name ); ?>" data-bn-items="<?php echo esc_attr( (string) $bn_sft_items_n ); ?>">
							<?php buddynext_icon( 'trash' ); ?> <?php esc_html_e( 'Delete now', 'buddynext' ); ?>
						</button>
						<?php endif; ?>
					</span>
					<?php endif; ?>
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
