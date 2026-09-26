<?php
/**
 * BuddyNext template partial: the Files tab, for either document drive.
 *
 * BuddyNext owns the document-drive UI (WPMediaVerse ships none — see
 * docs/architecture/pro/BUDDYNEXT-DRIVE-BRIDGE.md §6). This is a browse +
 * download view of ONE drive at one folder: folders first, then documents,
 * with a breadcrumb and pagination. The same file serves the space drive and
 * the member's own drive (RendersDriveFiles points it at either), so anything
 * that names the drive branches on `$bn_sf_drive_type` — hardcoding "this
 * space" here puts space copy on a member's own profile.
 *
 * Contribution (adding a file) arrives through the activity composer, the same
 * way media reaches a space through a post — the Files tab is the view, not
 * the uploader. `$bn_sf_can_write` therefore gates the empty state's "how to
 * add one" line, not an upload control.
 *
 * Rows-not-tiles, exactly like MediaVerse's own personal drive, and it stacks
 * into cards at 640px. No JavaScript: folders are links, downloads are links.
 *
 * @package BuddyNext
 *
 * @var int                                    $bn_sf_space_id    The drive id (space id, or profile owner id).
 * @var string                                 $bn_sf_drive_type  'space' or 'user'.
 * @var string                                 $bn_sf_base_url    The Files tab URL for this drive.
 * @var array<int,array<string,mixed>>         $bn_sf_folders     MVS folder objects at this level.
 * @var array<int,array<string,mixed>>         $bn_sf_documents   MVS document objects at this level.
 * @var array<int,array{id:int,name:string}>   $bn_sf_breadcrumbs Trail incl. current folder.
 * @var int                                    $bn_sf_folder      Current folder id (0 = root).
 * @var int                                    $bn_sf_page        1-based page.
 * @var int                                    $bn_sf_pages       Total pages.
 * @var int                                    $bn_sf_total       Total documents in this folder.
 * @var bool                                   $bn_sf_can_write   Whether the viewer may add to this drive.
 */

defined( 'ABSPATH' ) || exit;

$bn_sf_folders     = isset( $bn_sf_folders ) && is_array( $bn_sf_folders ) ? $bn_sf_folders : array();
$bn_sf_documents   = isset( $bn_sf_documents ) && is_array( $bn_sf_documents ) ? $bn_sf_documents : array();
$bn_sf_breadcrumbs = isset( $bn_sf_breadcrumbs ) && is_array( $bn_sf_breadcrumbs ) ? $bn_sf_breadcrumbs : array();
$bn_sf_base_url    = isset( $bn_sf_base_url ) ? (string) $bn_sf_base_url : '';
$bn_sf_folder      = isset( $bn_sf_folder ) ? (int) $bn_sf_folder : 0;
$bn_sf_page        = isset( $bn_sf_page ) ? max( 1, (int) $bn_sf_page ) : 1;
$bn_sf_pages       = isset( $bn_sf_pages ) ? max( 1, (int) $bn_sf_pages ) : 1;
$bn_sf_total       = isset( $bn_sf_total ) ? (int) $bn_sf_total : count( $bn_sf_documents );
$bn_sf_fpage       = isset( $bn_sf_folder_page ) ? max( 1, (int) $bn_sf_folder_page ) : 1;
$bn_sf_fpages      = isset( $bn_sf_folder_pages ) ? max( 1, (int) $bn_sf_folder_pages ) : 1;
$bn_sf_ftotal      = isset( $bn_sf_folder_total ) ? (int) $bn_sf_folder_total : count( $bn_sf_folders );
$bn_sf_search_q    = isset( $bn_sf_search_q ) ? (string) $bn_sf_search_q : '';
$bn_sf_search_rdy  = isset( $bn_sf_search_ready ) ? (bool) $bn_sf_search_ready : true;
$bn_sf_is_search   = '' !== $bn_sf_search_q;
$bn_sf_is_space    = 'user' !== ( isset( $bn_sf_drive_type ) ? (string) $bn_sf_drive_type : 'space' );
// Search results never reach the "no files yet" state, so that path passes no
// write level; defaulting false keeps the "how to add one" line off a view that
// cannot know whether the viewer may contribute.
$bn_sf_can_write = isset( $bn_sf_can_write ) ? (bool) $bn_sf_can_write : false;
// New folder / Rename / Trash / Restore: the drive's managers only (RendersDriveFiles).
$bn_sf_can_manage_folders = ! empty( $bn_sf_can_manage_folders );

// Document-upload config (enabled / accept / max_size). The Files-tab uploader is
// offered only to a contributor (can_write) when documents are enabled + writable.
$bn_sf_doc_config = isset( $bn_sf_doc_config ) && is_array( $bn_sf_doc_config ) ? $bn_sf_doc_config : array();
$bn_sf_can_upload = $bn_sf_can_write && ! empty( $bn_sf_doc_config['enabled'] ) && ! $bn_sf_is_search;
// A viewer the drive grants write to, but for whom the composer is off because
// documents are read-only on this site (unlicensed MVS Pro — writes 403), would
// otherwise meet a silent read-only tab. Show a one-line explanation instead.
$bn_sf_docs_read_only = ! empty( $bn_sf_docs_read_only );
$bn_sf_show_ro_notice = $bn_sf_can_write && ! $bn_sf_can_upload && ! $bn_sf_is_search && $bn_sf_docs_read_only;
$bn_sf_folder         = isset( $bn_sf_folder ) ? (int) $bn_sf_folder : 0;
$bn_sf_up_i18n        = (string) wp_json_encode(
	array(
		'uploading' => __( 'Uploading…', 'buddynext' ),
		'done'      => __( 'Uploaded.', 'buddynext' ),
		'fail'      => __( 'That file could not be uploaded.', 'buddynext' ),
		'tooBig'    => __( 'That file is over the allowed size.', 'buddynext' ),
	)
);

// A short type chip, the same shorthand MediaVerse uses so the two libraries
// read the same. Unknown types fall back to FILE rather than guessing.
$bn_sf_chip = static function ( $doc_type ): string {
	$map = array(
		'pdf'              => 'PDF',
		'word'             => 'DOC',
		'excel'            => 'XLS',
		'powerpoint'       => 'PPT',
		'odf_text'         => 'ODT',
		'odf_sheet'        => 'ODS',
		'odf_presentation' => 'ODP',
		'text'             => 'TXT',
		'markdown'         => 'MD',
		'csv'              => 'CSV',
		'rtf'              => 'RTF',
	);
	$key = (string) $doc_type;
	return isset( $map[ $key ] ) ? $map[ $key ] : 'FILE';
};

$bn_sf_folder_url = static function ( int $fid ) use ( $bn_sf_base_url ): string {
	return $fid > 0 ? add_query_arg( 'bn_folder', $fid, $bn_sf_base_url ) : $bn_sf_base_url;
};

$bn_sf_page_url = static function ( int $p ) use ( $bn_sf_base_url, $bn_sf_folder, $bn_sf_fpage, $bn_sf_is_search, $bn_sf_search_q ): string {
	// In search mode the page cursor rides on the query, not the folder.
	if ( $bn_sf_is_search ) {
		return add_query_arg(
			array(
				'bn_q'          => $bn_sf_search_q,
				'bn_files_page' => $p,
			),
			$bn_sf_base_url
		);
	}
	$url = $bn_sf_folder > 0 ? add_query_arg( 'bn_folder', $bn_sf_folder, $bn_sf_base_url ) : $bn_sf_base_url;
	if ( $bn_sf_fpage > 1 ) {
		$url = add_query_arg( 'bn_folder_page', $bn_sf_fpage, $url );
	}
	return add_query_arg( 'bn_files_page', $p, $url );
};

$bn_sf_fpage_url = static function ( int $p ) use ( $bn_sf_base_url, $bn_sf_folder, $bn_sf_page ): string {
	$url = $bn_sf_folder > 0 ? add_query_arg( 'bn_folder', $bn_sf_folder, $bn_sf_base_url ) : $bn_sf_base_url;
	if ( $bn_sf_page > 1 ) {
		$url = add_query_arg( 'bn_files_page', $bn_sf_page, $url );
	}
	return add_query_arg( 'bn_folder_page', $p, $url );
};

$bn_sf_doc_query = ! empty( $bn_sf_doc_query );
$bn_sf_doc_url   = static function ( int $did ) use ( $bn_sf_base_url, $bn_sf_doc_query ): string {
	// Clean URL on the Files tab: /spaces/{slug}/files/{id}/ (base_url already ends
	// in files/). An embed on any other page uses the ?bn_doc= alias instead.
	return $bn_sf_doc_query ? add_query_arg( 'bn_doc', $did, $bn_sf_base_url ) : trailingslashit( $bn_sf_base_url ) . $did . '/';
};

// A cookie-authenticated browser needs a nonce on a REST GET, so a plain
// download link carries `_wpnonce` — otherwise the request reads as logged-out
// and a private document 403s.
$bn_sf_rest_nonce = wp_create_nonce( 'wp_rest' );
$bn_sf_dl_url     = static function ( array $doc ) use ( $bn_sf_rest_nonce ): string {
	$url = isset( $doc['links']['download'] ) ? (string) $doc['links']['download'] : '';
	return '' === $url ? '' : add_query_arg( '_wpnonce', $bn_sf_rest_nonce, $url );
};

// Owner names in one batched priming, not a query per row.
$bn_sf_author_ids = array();
foreach ( $bn_sf_documents as $bn_sf_doc ) {
	$aid = isset( $bn_sf_doc['author'] ) ? (int) $bn_sf_doc['author'] : 0;
	if ( $aid > 0 ) {
		$bn_sf_author_ids[] = $aid;
	}
}
$bn_sf_author_ids = array_values( array_unique( $bn_sf_author_ids ) );
if ( ! empty( $bn_sf_author_ids ) ) {
	cache_users( $bn_sf_author_ids );
}

$bn_sf_viewer   = get_current_user_id();
$bn_sf_date_fmt = (string) get_option( 'date_format' );
$bn_sf_empty    = empty( $bn_sf_folders ) && empty( $bn_sf_documents );

/*
 * The Remove control means different things on the two drives, and this is the
 * distinction the whole feature turns on:
 *
 *   - Space drive -> UNLINK. The file leaves the space and returns to its owner's
 *     own Files (BuddyNext's unlink endpoint re-homes the row to the owner's
 *     personal drive as private). It is NOT deleted; the owner deletes it from
 *     their own Files if they want it gone. Its owner (reclaiming it) or a space
 *     moderator (removing anyone's) may do this.
 *   - Personal drive -> DELETE. This IS the owner's own Files, so Remove trashes
 *     the document (MediaVerse keeps a 30-day restore). Its author or a documents
 *     admin may do this.
 *
 * Per-row authority is finished in the loop (author compare); the tab-wide pieces
 * — which endpoint, which copy, and the moderator authority — are settled here.
 */
$bn_sf_can_moderate = isset( $bn_sf_can_moderate ) ? (bool) $bn_sf_can_moderate : false;
$bn_sf_can_manage   = current_user_can( 'manage_mvs_documents' ) || current_user_can( 'manage_options' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- capability owned by the WPMediaVerse companion plugin.

if ( $bn_sf_is_space ) {
	$bn_sf_rm_action   = 'unlink';
	$bn_sf_rm_endpoint = rest_url( 'buddynext/v1/spaces/' . (int) $bn_sf_space_id . '/media/' );
	$bn_sf_rm_i18n     = (string) wp_json_encode(
		array(
			'confirmTitle' => __( 'Remove from this space?', 'buddynext' ),
			'confirmBody'  => __( 'The file returns to its owner’s own Files: it is not deleted. They can remove it from there.', 'buddynext' ),
			'confirm'      => __( 'Remove', 'buddynext' ),
			'cancel'       => __( 'Cancel', 'buddynext' ),
			'done'         => __( 'File removed from the space.', 'buddynext' ),
			'fail'         => __( 'That file could not be removed.', 'buddynext' ),
		)
	);
	// A LINKED file (its home is another drive) is removed differently: only the
	// link is dropped, its original stays exactly where it lives. Same button,
	// honest copy — the mechanism differs, the member's outcome ("no longer in
	// this space, not deleted") does not.
	$bn_sf_rm_linked_i18n = (string) wp_json_encode(
		array(
			'confirmTitle' => __( 'Remove from this space?', 'buddynext' ),
			'confirmBody'  => __( 'Only the link is removed. The original file stays where it lives and is not deleted.', 'buddynext' ),
			'confirm'      => __( 'Remove', 'buddynext' ),
			'cancel'       => __( 'Cancel', 'buddynext' ),
			'done'         => __( 'File removed from the space.', 'buddynext' ),
			'fail'         => __( 'That file could not be removed.', 'buddynext' ),
		)
	);
} else {
	$bn_sf_rm_action   = 'delete';
	$bn_sf_rm_endpoint = rest_url( 'mvs-pro/v1/documents/' );
	$bn_sf_rm_i18n     = (string) wp_json_encode(
		array(
			'confirmTitle' => __( 'Remove this file?', 'buddynext' ),
			'confirmBody'  => __( 'It moves to trash and leaves this list. You can restore it within 30 days.', 'buddynext' ),
			'confirm'      => __( 'Remove', 'buddynext' ),
			'cancel'       => __( 'Cancel', 'buddynext' ),
			'done'         => __( 'File removed.', 'buddynext' ),
			'fail'         => __( 'That file could not be removed.', 'buddynext' ),
		)
	);
}
?>
<div class="bn-space-files"
	<?php if ( $bn_sf_can_manage_folders ) : ?>
	data-bn-folder-manage
	data-bn-folder-endpoint="<?php echo esc_url( rest_url( 'mvs-pro/v1/folders' ) ); ?>"
	data-bn-drive="<?php echo esc_attr( ( $bn_sf_is_space ? 'space' : 'user' ) . ':' . (int) $bn_sf_space_id ); ?>"
	data-bn-parent="<?php echo esc_attr( (string) $bn_sf_folder ); ?>"
	data-bn-folder-strings="<?php echo esc_attr( (string) wp_json_encode( buddynext_drive_folder_strings() ) ); ?>"
	<?php endif; ?>
	<?php if ( $bn_sf_viewer > 0 ) : ?>
	data-bn-files-actions
	data-bn-action="<?php echo esc_attr( $bn_sf_rm_action ); ?>"
	data-bn-endpoint="<?php echo esc_url( $bn_sf_rm_endpoint ); ?>"
	data-bn-nonce="<?php echo esc_attr( $bn_sf_rest_nonce ); ?>"
	data-bn-strings="<?php echo esc_attr( $bn_sf_rm_i18n ); ?>"
	<?php endif; ?>
>

	<?php
	// Search, Upload and Link a file share ONE compact toolbar strip. On a wide
	// screen all three sit on one line; when the row is too narrow, search keeps
	// the top line and Upload + Link drop TOGETHER onto the next line (grouped in
	// .bn-files__tools) rather than stacking one-per-row with dead space beside
	// each. Upload is a button; a file can still be dropped on it.
	$bn_sf_drive_param = ( $bn_sf_is_space ? 'space' : 'user' ) . ':' . (int) $bn_sf_space_id;
	$bn_sf_has_link    = $bn_sf_is_space && $bn_sf_can_write && ! $bn_sf_is_search;
	?>
	<div class="bn-files__toolbar">
		<form class="bn-files__search" method="get" action="<?php echo esc_url( $bn_sf_base_url ); ?>" role="search">
			<label class="screen-reader-text" for="bn-files-q"><?php echo esc_html( $bn_sf_is_space ? __( 'Search files in this space', 'buddynext' ) : __( 'Search your files', 'buddynext' ) ); ?></label>
			<?php
			// A GET form drops its action's query string, so carry the host page's
			// own args (e.g. /circle/?id=5) as hidden fields. The drive's own view
			// args are left out: a new search starts at page 1 of the whole drive.
			wp_parse_str( (string) wp_parse_url( $bn_sf_base_url, PHP_URL_QUERY ), $bn_sf_host_args );
			foreach ( array_diff_key( $bn_sf_host_args, array_flip( array( 'bn_q', 'bn_folder', 'bn_files_page', 'bn_folder_page', 'bn_doc' ) ) ) as $bn_sf_arg => $bn_sf_val ) :
				if ( is_scalar( $bn_sf_val ) ) :
					?>
				<input type="hidden" name="<?php echo esc_attr( $bn_sf_arg ); ?>" value="<?php echo esc_attr( (string) $bn_sf_val ); ?>">
					<?php
				endif;
			endforeach;
			?>
			<input type="search" id="bn-files-q" name="bn_q" class="bn-files__search-input" value="<?php echo esc_attr( $bn_sf_search_q ); ?>" placeholder="<?php esc_attr_e( 'Search files…', 'buddynext' ); ?>" autocomplete="off">
			<button type="submit" class="bn-files__search-btn"><?php esc_html_e( 'Search', 'buddynext' ); ?></button>
		</form>

		<?php if ( $bn_sf_can_upload || $bn_sf_has_link || ( $bn_sf_can_manage_folders && ! $bn_sf_is_search ) ) : ?>
		<div class="bn-files__tools">

			<?php if ( $bn_sf_can_manage_folders && ! $bn_sf_is_search ) : ?>
				<button type="button" class="bn-files__tool-btn" data-bn-folder-new>
					<span class="bn-files__tool-icon" aria-hidden="true"><?php buddynext_icon( 'folder-plus' ); ?></span>
					<?php esc_html_e( 'New folder', 'buddynext' ); ?>
				</button>
				<a class="bn-files__tool-btn" href="<?php echo esc_url( add_query_arg( 'bn_trash', 1, $bn_sf_base_url ) ); ?>">
					<span class="bn-files__tool-icon" aria-hidden="true"><?php buddynext_icon( 'trash' ); ?></span>
					<?php esc_html_e( 'Trash', 'buddynext' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $bn_sf_can_upload ) : ?>
			<div class="bn-files-upload"
				data-bn-file-upload
				data-bn-url="<?php echo esc_url( rest_url( 'mvs-pro/v1/documents/upload' ) ); ?>"
				data-bn-drive="<?php echo esc_attr( $bn_sf_drive_param ); ?>"
				data-bn-folder="<?php echo esc_attr( (string) $bn_sf_folder ); ?>"
				data-bn-privacy="<?php echo esc_attr( $bn_sf_is_space ? 'space' : 'private' ); ?>"
				data-bn-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-bn-max="<?php echo esc_attr( (string) ( (int) ( $bn_sf_doc_config['max_size'] ?? 0 ) ) ); ?>"
				data-bn-strings="<?php echo esc_attr( $bn_sf_up_i18n ); ?>">
				<button type="button" class="bn-files__tool-btn" data-bn-file-upload-trigger
					title="<?php echo esc_attr( $bn_sf_is_space ? __( 'Upload a file to this space (or drop one here)', 'buddynext' ) : __( 'Upload a file (or drop one here)', 'buddynext' ) ); ?>">
					<span class="bn-files__tool-icon" aria-hidden="true"><?php buddynext_icon( 'upload' ); ?></span>
					<span><?php esc_html_e( 'Upload', 'buddynext' ); ?></span>
				</button>
				<input type="file" class="bn-files-upload__input" data-bn-file-upload-input
					accept="<?php echo esc_attr( (string) ( $bn_sf_doc_config['accept'] ?? '' ) ); ?>" hidden
					aria-label="<?php esc_attr_e( 'Choose a file to upload', 'buddynext' ); ?>">
				<p class="bn-files-upload__status" data-bn-file-upload-status role="status" aria-live="polite" hidden></p>
			</div>
		<?php endif; ?>

			<?php if ( $bn_sf_has_link ) : ?>
				<?php
				// Link an EXISTING file into this space (secondary to upload). The
				// member pastes a file's link; the server resolves it, checks they own
				// it and may write here, and adds it to this space's Files without a
				// copy. Any member who may contribute here may link; removing others'
				// links stays a moderator action (checked per row).
				$bn_sf_link_i18n = (string) wp_json_encode(
					array(
						'linking' => __( 'Linking…', 'buddynext' ),
						'done'    => __( 'File linked to this space.', 'buddynext' ),
						'empty'   => __( 'Paste a file link first.', 'buddynext' ),
						'fail'    => __( 'That file could not be linked.', 'buddynext' ),
					)
				);
				?>
			<details class="bn-files-link">
				<summary class="bn-files__tool-btn bn-files-link__toggle">
					<span class="bn-files__tool-icon" aria-hidden="true"><?php buddynext_icon( 'link' ); ?></span>
					<?php esc_html_e( 'Link a file', 'buddynext' ); ?>
				</summary>
				<div class="bn-files-link__pop"
					data-bn-file-link
					data-bn-url="<?php echo esc_url( rest_url( 'mvs-pro/v1/documents/link' ) ); ?>"
					data-bn-space="<?php echo esc_attr( (string) (int) $bn_sf_space_id ); ?>"
					data-bn-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
					data-bn-strings="<?php echo esc_attr( $bn_sf_link_i18n ); ?>">
					<label class="bn-files-link__label" for="bn-files-link-input">
						<?php esc_html_e( 'Paste the link to a file you own', 'buddynext' ); ?>
					</label>
					<div class="bn-files-link__row">
						<input type="url" id="bn-files-link-input" class="bn-files-link__input"
							data-bn-file-link-input inputmode="url" autocomplete="off"
							placeholder="<?php esc_attr_e( 'https://…/media/your-file/', 'buddynext' ); ?>">
						<button type="button" class="bn-files-link__submit" data-bn-file-link-submit>
							<?php esc_html_e( 'Link', 'buddynext' ); ?>
						</button>
					</div>
					<p class="bn-files-link__status" data-bn-file-link-status role="status" aria-live="polite" hidden></p>
				</div>
			</details>
		<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>

	<?php if ( ! $bn_sf_can_upload && $bn_sf_show_ro_notice ) : ?>
		<p class="bn-files__notice" data-bn-files-readonly>
			<?php
			echo esc_html(
				$bn_sf_is_space
					? __( 'You can add files to this space, but uploads are turned off on this site right now. Ask an administrator to activate the media add-on.', 'buddynext' )
					: __( 'Uploads are turned off on this site right now. Ask an administrator to activate the media add-on.', 'buddynext' )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $bn_sf_is_search ) : ?>
		<div class="bn-files__search-head">
			<p class="bn-files__count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of results, 2: search term. */
						_n( '%1$s result for “%2$s”', '%1$s results for “%2$s”', $bn_sf_total, 'buddynext' ),
						number_format_i18n( $bn_sf_total ),
						$bn_sf_search_q
					)
				);
				?>
			</p>
			<a class="bn-files__search-clear" href="<?php echo esc_url( $bn_sf_base_url ); ?>"><?php esc_html_e( 'Clear search', 'buddynext' ); ?></a>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $bn_sf_breadcrumbs ) ) : ?>
		<nav class="bn-files__crumbs" aria-label="<?php esc_attr_e( 'Folder path', 'buddynext' ); ?>">
			<a class="bn-files__crumb" href="<?php echo esc_url( $bn_sf_folder_url( 0 ) ); ?>"><?php esc_html_e( 'Files', 'buddynext' ); ?></a>
			<?php
			$bn_sf_last = count( $bn_sf_breadcrumbs ) - 1;
			foreach ( $bn_sf_breadcrumbs as $bn_sf_i => $bn_sf_crumb ) :
				$bn_sf_cname = (string) ( $bn_sf_crumb['name'] ?? '' );
				$bn_sf_cid   = (int) ( $bn_sf_crumb['id'] ?? 0 );
				?>
				<span class="bn-files__crumb-sep" aria-hidden="true"><?php echo buddynext_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?></span>
				<?php if ( $bn_sf_i === $bn_sf_last ) : ?>
					<span class="bn-files__crumb bn-files__crumb--current" aria-current="page"><?php echo esc_html( $bn_sf_cname ); ?></span>
				<?php else : ?>
					<a class="bn-files__crumb" href="<?php echo esc_url( $bn_sf_folder_url( $bn_sf_cid ) ); ?>"><?php echo esc_html( $bn_sf_cname ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $bn_sf_is_search && ! $bn_sf_search_rdy ) : ?>
		<?php
		// The index is still building — never a false "no results".
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'file-text',
				'title' => __( 'Search is getting ready', 'buddynext' ),
				'body'  => __( 'Files are still being indexed for search. Try again in a moment.', 'buddynext' ),
			)
		);
		?>
	<?php elseif ( $bn_sf_empty ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			$bn_sf_is_search
				? array(
					'icon'  => 'file-text',
					'title' => __( 'No files match your search', 'buddynext' ),
					'body'  => __( 'Try a different word, or clear the search to browse everything.', 'buddynext' ),
				)
				: array(
					'icon'  => 'folder',
					'title' => $bn_sf_folder > 0
						? __( 'This folder is empty', 'buddynext' )
						: ( $bn_sf_is_space ? __( 'No files shared yet', 'buddynext' ) : __( 'No files yet', 'buddynext' ) ),
					'body'  => $bn_sf_is_space
						? ( $bn_sf_can_write
							? __( 'Files shared with this space appear here. Attach one to a post to add it.', 'buddynext' )
							: __( 'Files shared with this space appear here to browse and download.', 'buddynext' ) )
						: __( 'Documents you share appear here. Attach one to a post to add it.', 'buddynext' ),
				)
		);
		?>
	<?php else : ?>

		<?php if ( ! $bn_sf_is_search ) : ?>
			<p class="bn-files__count">
				<?php
				$bn_sf_parts = array();
				if ( $bn_sf_total > 0 ) {
					/* translators: %s: number of files. */
					$bn_sf_parts[] = sprintf( _n( '%s file', '%s files', $bn_sf_total, 'buddynext' ), number_format_i18n( $bn_sf_total ) );
				}
				if ( $bn_sf_ftotal > 0 ) {
					/* translators: %s: number of folders. */
					$bn_sf_parts[] = sprintf( _n( '%s folder', '%s folders', $bn_sf_ftotal, 'buddynext' ), number_format_i18n( $bn_sf_ftotal ) );
				}
				echo esc_html( implode( ' · ', $bn_sf_parts ) );
				?>
			</p>
		<?php endif; ?>

		<ul class="bn-files__list" role="list">

			<?php foreach ( $bn_sf_folders as $bn_sf_f ) : ?>
				<?php
				$bn_sf_fid   = isset( $bn_sf_f['id'] ) ? (int) $bn_sf_f['id'] : 0;
				$bn_sf_fname = isset( $bn_sf_f['name'] ) ? (string) $bn_sf_f['name'] : '';
				$bn_sf_fdate = isset( $bn_sf_f['created_at'] ) ? (string) $bn_sf_f['created_at'] : '';
				if ( $bn_sf_fid <= 0 ) {
					continue;
				}
				?>
				<li class="bn-files__row bn-files__row--folder">
					<span class="bn-files__chip bn-files__chip--dir" aria-hidden="true"><?php esc_html_e( 'DIR', 'buddynext' ); ?></span>
					<a class="bn-files__name" href="<?php echo esc_url( $bn_sf_folder_url( $bn_sf_fid ) ); ?>"><?php echo esc_html( $bn_sf_fname ); ?></a>
					<span class="bn-files__meta">
						<span class="bn-files__size"><?php esc_html_e( 'Folder', 'buddynext' ); ?></span>
						<span class="bn-files__date"><?php echo esc_html( '' !== $bn_sf_fdate ? mysql2date( $bn_sf_date_fmt, $bn_sf_fdate ) : '' ); ?></span>
					</span>
					<?php if ( $bn_sf_can_manage_folders ) : ?>
						<span class="bn-files__actions">
							<button type="button" class="bn-files__icon-btn" data-bn-folder-rename data-bn-id="<?php echo esc_attr( (string) $bn_sf_fid ); ?>" data-bn-name="<?php echo esc_attr( $bn_sf_fname ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: folder name. */ __( 'Rename %s', 'buddynext' ), $bn_sf_fname ) ); ?>"><?php buddynext_icon( 'edit' ); ?></button>
							<button type="button" class="bn-files__remove" data-bn-folder-delete data-bn-id="<?php echo esc_attr( (string) $bn_sf_fid ); ?>" data-bn-name="<?php echo esc_attr( $bn_sf_fname ); ?>"
								data-bn-files="<?php echo esc_attr( (string) (int) ( $bn_sf_f['file_count'] ?? 0 ) ); ?>" data-bn-folders="<?php echo esc_attr( (string) (int) ( $bn_sf_f['folder_count'] ?? 0 ) ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: folder name. */ __( 'Move %s to trash', 'buddynext' ), $bn_sf_fname ) ); ?>"><?php buddynext_icon( 'trash' ); ?></button>
						</span>
					<?php else : ?>
						<span class="bn-files__actions" aria-hidden="true"><?php echo buddynext_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>

			<?php foreach ( $bn_sf_documents as $bn_sf_d ) : ?>
				<?php
				$bn_sf_did   = isset( $bn_sf_d['id'] ) ? (int) $bn_sf_d['id'] : 0;
				$bn_sf_title = isset( $bn_sf_d['title'] ) && '' !== (string) $bn_sf_d['title'] ? (string) $bn_sf_d['title'] : ( isset( $bn_sf_d['slug'] ) ? (string) $bn_sf_d['slug'] : __( 'Untitled', 'buddynext' ) );
				$bn_sf_dtype = isset( $bn_sf_d['doc_type'] ) ? $bn_sf_d['doc_type'] : '';
				$bn_sf_dsize = isset( $bn_sf_d['file_size'] ) ? (int) $bn_sf_d['file_size'] : 0;
				$bn_sf_ddate = isset( $bn_sf_d['created_at'] ) ? (string) $bn_sf_d['created_at'] : '';
				$bn_sf_daid  = isset( $bn_sf_d['author'] ) ? (int) $bn_sf_d['author'] : 0;
				$bn_sf_durl  = $bn_sf_dl_url( $bn_sf_d );
				// A file LINKED into this space (home drive is elsewhere) removes by
				// dropping the link, not by re-homing — the bridge flags which is
				// which. Only meaningful on a space drive.
				$bn_sf_dlinked = $bn_sf_is_space && ! empty( $bn_sf_d['is_linked'] );
				if ( $bn_sf_did <= 0 ) {
					continue;
				}
				// Who may remove THIS row. On a space it is the file's owner or a space
				// moderator (they unlink it); on a personal drive its author or a
				// documents admin (they delete it). The owner compare is the common case.
				$bn_sf_is_owner   = $bn_sf_viewer > 0 && $bn_sf_daid === $bn_sf_viewer;
				$bn_sf_can_remove = $bn_sf_is_owner || ( $bn_sf_viewer > 0 && ( $bn_sf_is_space ? $bn_sf_can_moderate : $bn_sf_can_manage ) );
				if ( $bn_sf_daid === $bn_sf_viewer && $bn_sf_viewer > 0 ) {
					$bn_sf_owner = __( 'You', 'buddynext' );
				} else {
					$bn_sf_u     = $bn_sf_daid > 0 ? get_userdata( $bn_sf_daid ) : false;
					$bn_sf_owner = $bn_sf_u ? $bn_sf_u->display_name : '';
				}
				?>
				<li class="bn-files__row">
					<span class="bn-files__chip bn-files__chip--<?php echo esc_attr( '' !== (string) $bn_sf_dtype ? (string) $bn_sf_dtype : 'file' ); ?>" aria-hidden="true"><?php echo esc_html( $bn_sf_chip( $bn_sf_dtype ) ); ?></span>
					<a class="bn-files__name" href="<?php echo esc_url( $bn_sf_doc_url( $bn_sf_did ) ); ?>"><?php echo esc_html( $bn_sf_title ); ?></a>
					<span class="bn-files__meta">
						<span class="bn-files__size"><?php echo esc_html( $bn_sf_dsize > 0 ? size_format( $bn_sf_dsize ) : '' ); ?></span>
						<span class="bn-files__date"><?php echo esc_html( '' !== $bn_sf_ddate ? mysql2date( $bn_sf_date_fmt, $bn_sf_ddate ) : '' ); ?></span>
						<?php if ( '' !== $bn_sf_owner ) : ?>
							<span class="bn-files__owner"><?php echo esc_html( $bn_sf_owner ); ?></span>
						<?php endif; ?>
						<?php if ( $bn_sf_dlinked ) : ?>
							<span class="bn-files__badge" title="<?php esc_attr_e( 'Linked from another location', 'buddynext' ); ?>"><?php esc_html_e( 'Linked', 'buddynext' ); ?></span>
						<?php endif; ?>
					</span>
					<span class="bn-files__actions">
						<?php if ( '' !== $bn_sf_durl ) : ?>
							<a class="bn-files__download" href="<?php echo esc_url( $bn_sf_durl ); ?>">
								<?php echo buddynext_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
								<span class="screen-reader-text">
									<?php
									/* translators: %s: document title. */
									echo esc_html( sprintf( __( 'Download %s', 'buddynext' ), $bn_sf_title ) );
									?>
								</span>
							</a>
						<?php endif; ?>
						<?php if ( $bn_sf_can_remove ) : ?>
							<button type="button" class="bn-files__remove" data-bn-file-remove data-bn-id="<?php echo esc_attr( (string) $bn_sf_did ); ?>"
								<?php if ( $bn_sf_dlinked ) : ?>
								data-bn-action="unlink-space"
								data-bn-detach="<?php echo esc_url( rest_url( 'mvs-pro/v1/documents/' . $bn_sf_did . '/spaces/' . (int) $bn_sf_space_id ) ); ?>"
								data-bn-strings="<?php echo esc_attr( $bn_sf_rm_linked_i18n ); ?>"
								<?php endif; ?>
							>
								<?php echo buddynext_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
								<span class="screen-reader-text">
									<?php
									if ( $bn_sf_is_space ) {
										/* translators: %s: document title. */
										echo esc_html( sprintf( __( 'Remove %s from this space', 'buddynext' ), $bn_sf_title ) );
									} else {
										/* translators: %s: document title. */
										echo esc_html( sprintf( __( 'Remove %s', 'buddynext' ), $bn_sf_title ) );
									}
									?>
								</span>
							</button>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>

		</ul>

		<?php if ( $bn_sf_fpages > 1 ) : ?>
			<nav class="bn-files__pager" aria-label="<?php esc_attr_e( 'Folder pages', 'buddynext' ); ?>">
				<?php if ( $bn_sf_fpage > 1 ) : ?>
					<a class="bn-files__pager-link" href="<?php echo esc_url( $bn_sf_fpage_url( $bn_sf_fpage - 1 ) ); ?>" rel="prev">
						<?php echo buddynext_icon( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
						<span><?php esc_html_e( 'Previous', 'buddynext' ); ?></span>
					</a>
				<?php endif; ?>
				<span class="bn-files__pager-status">
					<?php
					/* translators: 1: current folder page, 2: total folder pages. */
					echo esc_html( sprintf( __( 'Folders: page %1$s of %2$s', 'buddynext' ), number_format_i18n( $bn_sf_fpage ), number_format_i18n( $bn_sf_fpages ) ) );
					?>
				</span>
				<?php if ( $bn_sf_fpage < $bn_sf_fpages ) : ?>
					<a class="bn-files__pager-link" href="<?php echo esc_url( $bn_sf_fpage_url( $bn_sf_fpage + 1 ) ); ?>" rel="next">
						<span><?php esc_html_e( 'Next', 'buddynext' ); ?></span>
						<?php echo buddynext_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
					</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>

		<?php if ( $bn_sf_pages > 1 ) : ?>
			<nav class="bn-files__pager" aria-label="<?php esc_attr_e( 'Files pages', 'buddynext' ); ?>">
				<?php if ( $bn_sf_page > 1 ) : ?>
					<a class="bn-files__pager-link" href="<?php echo esc_url( $bn_sf_page_url( $bn_sf_page - 1 ) ); ?>" rel="prev">
						<?php echo buddynext_icon( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
						<span><?php esc_html_e( 'Previous', 'buddynext' ); ?></span>
					</a>
				<?php endif; ?>
				<span class="bn-files__pager-status">
					<?php
					/* translators: 1: current page, 2: total pages. */
					echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'buddynext' ), number_format_i18n( $bn_sf_page ), number_format_i18n( $bn_sf_pages ) ) );
					?>
				</span>
				<?php if ( $bn_sf_page < $bn_sf_pages ) : ?>
					<a class="bn-files__pager-link" href="<?php echo esc_url( $bn_sf_page_url( $bn_sf_page + 1 ) ); ?>" rel="next">
						<span><?php esc_html_e( 'Next', 'buddynext' ); ?></span>
						<?php echo buddynext_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
					</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>

	<?php endif; ?>

</div>
