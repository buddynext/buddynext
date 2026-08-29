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

$bn_sf_doc_url = static function ( int $did ) use ( $bn_sf_base_url ): string {
	// Clean URL: /spaces/{slug}/files/{id}/ (base_url already ends in files/).
	return trailingslashit( $bn_sf_base_url ) . $did . '/';
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
?>
<div class="bn-space-files">

	<form class="bn-files__search" method="get" action="<?php echo esc_url( $bn_sf_base_url ); ?>" role="search">
		<label class="screen-reader-text" for="bn-files-q"><?php echo esc_html( $bn_sf_is_space ? __( 'Search files in this space', 'buddynext' ) : __( 'Search your files', 'buddynext' ) ); ?></label>
		<input type="search" id="bn-files-q" name="bn_q" class="bn-files__search-input" value="<?php echo esc_attr( $bn_sf_search_q ); ?>" placeholder="<?php esc_attr_e( 'Search files…', 'buddynext' ); ?>" autocomplete="off">
		<button type="submit" class="bn-files__search-btn"><?php esc_html_e( 'Search', 'buddynext' ); ?></button>
	</form>

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
					<span class="bn-files__actions" aria-hidden="true"><?php echo buddynext_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?></span>
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
				if ( $bn_sf_did <= 0 ) {
					continue;
				}
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
					echo esc_html( sprintf( __( 'Folders — page %1$s of %2$s', 'buddynext' ), number_format_i18n( $bn_sf_fpage ), number_format_i18n( $bn_sf_fpages ) ) );
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
