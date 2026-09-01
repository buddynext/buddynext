<?php
/**
 * BuddyNext template partial: the space Files single-document view.
 *
 * A real, deep-linkable page (reached at ?bn_doc=N on the Files tab), not a
 * modal: the document's details, an inline preview where the type allows one,
 * and always a download. Everything the row could not show without leaving the
 * list.
 *
 * The preview is BuddyNext's OWN chrome around MediaVerse DATA: a small island
 * fetches the `/preview` REST route (which BuddyNext already carries as
 * `links.preview`) and renders whatever it answers — a PDF via PDF.js, an office
 * rendition, rendered HTML for text/csv/markdown, or a "no preview" card.
 * MediaVerse never renders into this page; it only serves the bytes and the
 * sharing REST.
 *
 * Sharing (members + link) is BuddyNext's own modal over MediaVerse's
 * `documents/{id}/permissions` REST, shown only when the viewer may grant
 * ($bn_fs_can_share — the owner of a personal drive, on a writable site).
 *
 * @package BuddyNext
 *
 * @var array<string,mixed> $bn_fs_doc       MVS document object.
 * @var string              $bn_fs_base_url  /spaces/{slug}/files/ or /members/{slug}/files/ .
 * @var int                 $bn_fs_folder    The document's folder (0 = drive root), for the back link.
 * @var bool                $bn_fs_can_share Whether to offer the Share control.
 */

defined( 'ABSPATH' ) || exit;

$bn_fs_doc       = isset( $bn_fs_doc ) && is_array( $bn_fs_doc ) ? $bn_fs_doc : array();
$bn_fs_base_url  = isset( $bn_fs_base_url ) ? (string) $bn_fs_base_url : '';
$bn_fs_folder    = isset( $bn_fs_folder ) ? (int) $bn_fs_folder : 0;
$bn_fs_id        = isset( $bn_fs_doc['id'] ) ? (int) $bn_fs_doc['id'] : 0;
$bn_fs_can_share = ! empty( $bn_fs_can_share ) && $bn_fs_id > 0;

$bn_fs_type  = isset( $bn_fs_doc['doc_type'] ) ? (string) $bn_fs_doc['doc_type'] : '';
$bn_fs_title = isset( $bn_fs_doc['title'] ) && '' !== (string) $bn_fs_doc['title']
	? (string) $bn_fs_doc['title']
	: ( isset( $bn_fs_doc['slug'] ) ? (string) $bn_fs_doc['slug'] : __( 'Untitled', 'buddynext' ) );
$bn_fs_size  = isset( $bn_fs_doc['file_size'] ) ? (int) $bn_fs_doc['file_size'] : 0;
$bn_fs_date  = isset( $bn_fs_doc['created_at'] ) ? (string) $bn_fs_doc['created_at'] : '';
$bn_fs_aid   = isset( $bn_fs_doc['author'] ) ? (int) $bn_fs_doc['author'] : 0;

// Same shorthand chip + a human type label as the list — a small display map,
// deliberately duplicated with the list partial rather than shared, since it is
// a lookup table, not logic.
$bn_fs_chips = array(
	'pdf'              => array( 'PDF', __( 'PDF document', 'buddynext' ) ),
	'word'             => array( 'DOC', __( 'Word document', 'buddynext' ) ),
	'excel'            => array( 'XLS', __( 'Excel spreadsheet', 'buddynext' ) ),
	'powerpoint'       => array( 'PPT', __( 'PowerPoint presentation', 'buddynext' ) ),
	'odf_text'         => array( 'ODT', __( 'OpenDocument text', 'buddynext' ) ),
	'odf_sheet'        => array( 'ODS', __( 'OpenDocument spreadsheet', 'buddynext' ) ),
	'odf_presentation' => array( 'ODP', __( 'OpenDocument slides', 'buddynext' ) ),
	'text'             => array( 'TXT', __( 'Text file', 'buddynext' ) ),
	'markdown'         => array( 'MD', __( 'Markdown', 'buddynext' ) ),
	'csv'              => array( 'CSV', __( 'CSV', 'buddynext' ) ),
	'rtf'              => array( 'RTF', __( 'Rich text', 'buddynext' ) ),
);
$bn_fs_chip  = isset( $bn_fs_chips[ $bn_fs_type ] ) ? $bn_fs_chips[ $bn_fs_type ][0] : 'FILE';
$bn_fs_label = isset( $bn_fs_chips[ $bn_fs_type ] ) ? $bn_fs_chips[ $bn_fs_type ][1] : __( 'File', 'buddynext' );

// Owner display name.
$bn_fs_owner = '';
if ( $bn_fs_aid > 0 ) {
	$bn_fs_u     = get_userdata( $bn_fs_aid );
	$bn_fs_owner = $bn_fs_u ? $bn_fs_u->display_name : '';
}

// Cookie-auth GET needs the nonce, same as the list's download links — one
// nonce serves the download link, the preview fetch, and the sharing calls.
$bn_fs_nonce  = wp_create_nonce( 'wp_rest' );
$bn_fs_dl_url = isset( $bn_fs_doc['links']['download'] ) ? add_query_arg( '_wpnonce', $bn_fs_nonce, (string) $bn_fs_doc['links']['download'] ) : '';
$bn_fs_pv_url = isset( $bn_fs_doc['links']['preview'] ) ? add_query_arg( '_wpnonce', $bn_fs_nonce, (string) $bn_fs_doc['links']['preview'] ) : '';

// Vendored PDF.js (assets/js/vendor/pdfjs/) renders PDFs to canvas for a clean
// single-column read — the browser's embedded PDF chrome is unreadable in a
// column this width. Dynamic-imported by the island only when a PDF is opened,
// so no other view pays its weight; mtime-versioned so an update always reaches
// the browser past the immutable module cache. Falls back to an <iframe> if it
// fails to load, so the read never breaks outright.
$bn_fs_pdf_url = BUDDYNEXT_URL . 'assets/js/vendor/pdfjs/';
$bn_fs_pdf_ver = (string) ( @filemtime( BUDDYNEXT_DIR . 'assets/js/vendor/pdfjs/pdf.min.mjs' ) ?: '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file just yields an unversioned URL.

// Human labels for the three permission levels, shared by the select options and
// the JS that labels each existing grant.
$bn_fs_levels = array(
	'view'    => __( 'Can view', 'buddynext' ),
	'comment' => __( 'Can comment', 'buddynext' ),
	'edit'    => __( 'Can edit', 'buddynext' ),
);

$bn_fs_ctx_arr = array(
	'previewUrl' => $bn_fs_pv_url,
	'title'      => $bn_fs_title,
	'isPdf'      => ( 'pdf' === $bn_fs_type ),
	'pdfLib'     => add_query_arg( 'ver', $bn_fs_pdf_ver, $bn_fs_pdf_url . 'pdf.min.mjs' ),
	'pdfWorker'  => add_query_arg( 'ver', $bn_fs_pdf_ver, $bn_fs_pdf_url . 'pdf.worker.min.mjs' ),
);
if ( $bn_fs_can_share ) {
	$bn_fs_ctx_arr += array(
		'docId'       => $bn_fs_id,
		'permsUrl'    => rest_url( 'mvs-pro/v1/documents/' . $bn_fs_id . '/permissions' ),
		'permDelUrl'  => rest_url( 'mvs-pro/v1/permissions/' ),
		'nonce'       => $bn_fs_nonce,
		'levelLabels' => $bn_fs_levels,
		'shareOpen'   => false,
		'shareBusy'   => false,
		'shareError'  => '',
		'shareLink'   => '',
		'i18n'        => array(
			'remove'   => __( 'Remove', 'buddynext' ),
			'noShares' => __( 'Not shared with anyone yet.', 'buddynext' ),
			'link'     => __( 'Anyone with the link', 'buddynext' ),
			'error'    => __( 'Something went wrong. Please try again.', 'buddynext' ),
		),
	);
}
$bn_fs_ctx = (string) wp_json_encode( $bn_fs_ctx_arr );

$bn_fs_back_url = $bn_fs_folder > 0 ? add_query_arg( 'bn_folder', $bn_fs_folder, $bn_fs_base_url ) : $bn_fs_base_url;
$bn_fs_date_out = '' !== $bn_fs_date ? mysql2date( (string) get_option( 'date_format' ), $bn_fs_date ) : '';
?>
<div
	class="bn-space-files bn-file-single"
	data-wp-interactive="buddynext/space-files"
	data-wp-context='<?php echo esc_attr( $bn_fs_ctx ); ?>'>

	<a class="bn-file-single__back" href="<?php echo esc_url( $bn_fs_back_url ); ?>">
		<?php echo buddynext_icon( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
		<span><?php esc_html_e( 'Back to Files', 'buddynext' ); ?></span>
	</a>

	<header class="bn-file-single__head">
		<span class="bn-files__chip bn-files__chip--<?php echo esc_attr( '' !== $bn_fs_type ? $bn_fs_type : 'file' ); ?>" aria-hidden="true"><?php echo esc_html( $bn_fs_chip ); ?></span>
		<div class="bn-file-single__headings">
			<h2 class="bn-file-single__title"><?php echo esc_html( $bn_fs_title ); ?></h2>
			<p class="bn-file-single__meta">
				<?php
				$bn_fs_bits = array( $bn_fs_label );
				if ( $bn_fs_size > 0 ) {
					$bn_fs_bits[] = size_format( $bn_fs_size );
				}
				if ( '' !== $bn_fs_date_out ) {
					$bn_fs_bits[] = $bn_fs_date_out;
				}
				if ( '' !== $bn_fs_owner ) {
					/* translators: %s: uploader display name. */
					$bn_fs_bits[] = sprintf( __( 'by %s', 'buddynext' ), $bn_fs_owner );
				}
				echo esc_html( implode( ' · ', $bn_fs_bits ) );
				?>
			</p>
		</div>
		<div class="bn-file-single__actions">
			<?php if ( $bn_fs_can_share ) : ?>
				<button type="button" class="bn-file-single__share" data-wp-on--click="actions.openShare">
					<?php echo buddynext_icon( 'share-2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
					<span><?php esc_html_e( 'Share', 'buddynext' ); ?></span>
				</button>
			<?php endif; ?>
			<?php if ( '' !== $bn_fs_dl_url ) : ?>
				<a class="bn-file-single__download" href="<?php echo esc_url( $bn_fs_dl_url ); ?>">
					<?php echo buddynext_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
					<span><?php esc_html_e( 'Download', 'buddynext' ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( '' !== $bn_fs_pv_url ) : ?>
		<div class="bn-file-single__previewer" data-wp-init="callbacks.loadPreview">
			<div class="bn-file-single__preview" data-bn-preview>
				<div class="bn-file-single__preview-status">
					<span class="bn-file-single__spinner" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Loading preview…', 'buddynext' ); ?></span>
				</div>
			</div>
			<div class="bn-file-single__no-preview" data-bn-no-preview hidden>
				<?php echo buddynext_icon( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
				<p class="bn-file-single__no-preview-title"><?php esc_html_e( 'No preview for this file type', 'buddynext' ); ?></p>
				<p class="bn-file-single__no-preview-body"><?php esc_html_e( 'Download the file to open it in the right app.', 'buddynext' ); ?></p>
			</div>
		</div>
	<?php else : ?>
		<div class="bn-file-single__no-preview">
			<?php echo buddynext_icon( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
			<p class="bn-file-single__no-preview-title"><?php esc_html_e( 'No preview for this file type', 'buddynext' ); ?></p>
			<p class="bn-file-single__no-preview-body"><?php esc_html_e( 'Download the file to open it in the right app.', 'buddynext' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $bn_fs_can_share ) : ?>
		<div class="bn-share" data-wp-bind--hidden="!context.shareOpen">
			<div class="bn-share__backdrop" data-wp-on--click="actions.closeShare"></div>
			<div class="bn-share__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Share document', 'buddynext' ); ?>">
				<header class="bn-share__head">
					<h3 class="bn-share__title">
						<?php
						/* translators: %s: document title. */
						echo esc_html( sprintf( __( 'Share “%s”', 'buddynext' ), $bn_fs_title ) );
						?>
					</h3>
					<button type="button" class="bn-share__close" data-wp-on--click="actions.closeShare" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>">
						<?php echo buddynext_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
					</button>
				</header>

				<p class="bn-share__error" data-wp-text="context.shareError" data-wp-bind--hidden="!context.shareError" role="alert"></p>

				<form class="bn-share__add" data-wp-on--submit="actions.addMember">
					<input type="text" class="bn-share__login" name="login" autocomplete="off" required
						placeholder="<?php esc_attr_e( 'Add a member by username or email', 'buddynext' ); ?>"
						aria-label="<?php esc_attr_e( 'Add a member by username or email', 'buddynext' ); ?>">
					<select class="bn-share__perm" name="permission" aria-label="<?php esc_attr_e( 'Permission', 'buddynext' ); ?>">
						<?php foreach ( $bn_fs_levels as $bn_fs_lk => $bn_fs_ll ) : ?>
							<option value="<?php echo esc_attr( $bn_fs_lk ); ?>"><?php echo esc_html( $bn_fs_ll ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="bn-share__add-btn" data-wp-bind--disabled="context.shareBusy"><?php esc_html_e( 'Add', 'buddynext' ); ?></button>
				</form>

				<ul class="bn-share__grants" data-bn-grants></ul>

				<div class="bn-share__link">
					<div class="bn-share__link-controls">
						<select class="bn-share__link-perm" name="link_permission" aria-label="<?php esc_attr_e( 'Link permission', 'buddynext' ); ?>">
							<?php foreach ( $bn_fs_levels as $bn_fs_lk => $bn_fs_ll ) : ?>
								<option value="<?php echo esc_attr( $bn_fs_lk ); ?>"><?php echo esc_html( $bn_fs_ll ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="bn-share__link-btn" data-wp-on--click="actions.createLink" data-wp-bind--disabled="context.shareBusy">
							<?php echo buddynext_icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
							<span><?php esc_html_e( 'Create share link', 'buddynext' ); ?></span>
						</button>
					</div>
					<div class="bn-share__link-out" data-wp-bind--hidden="!context.shareLink">
						<input type="text" class="bn-share__link-url" data-wp-bind--value="context.shareLink" readonly aria-label="<?php esc_attr_e( 'Share link', 'buddynext' ); ?>">
						<button type="button" class="bn-share__link-copy" data-wp-on--click="actions.copyLink" aria-label="<?php esc_attr_e( 'Copy link', 'buddynext' ); ?>">
							<?php echo buddynext_icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
						</button>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>

</div>
