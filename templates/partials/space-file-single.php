<?php
/**
 * BuddyNext template partial: the space Files single-document view.
 *
 * A real, deep-linkable page (reached at ?bn_doc=N on the Files tab), not a
 * modal: the document's details, an inline preview where the type allows one
 * (PDF), and always a download. Everything the row could not show without
 * leaving the list.
 *
 * @package BuddyNext
 *
 * @var array<string,mixed> $bn_fs_doc         MVS document object.
 * @var string              $bn_fs_base_url    /spaces/{slug}/files/ .
 * @var int                 $bn_fs_folder      The document's folder (0 = drive root), for the back link.
 * @var string              $bn_fs_viewer_html MediaVerse DocumentViewer markup for the preview body ('' = none).
 */

defined( 'ABSPATH' ) || exit;

$bn_fs_doc      = isset( $bn_fs_doc ) && is_array( $bn_fs_doc ) ? $bn_fs_doc : array();
$bn_fs_base_url = isset( $bn_fs_base_url ) ? (string) $bn_fs_base_url : '';
$bn_fs_folder   = isset( $bn_fs_folder ) ? (int) $bn_fs_folder : 0;

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

// Cookie-auth GET needs the nonce, same as the list's download links.
$bn_fs_nonce  = wp_create_nonce( 'wp_rest' );
$bn_fs_dl_url = isset( $bn_fs_doc['links']['download'] ) ? add_query_arg( '_wpnonce', $bn_fs_nonce, (string) $bn_fs_doc['links']['download'] ) : '';
// The preview body is MediaVerse's own document viewer (pdf.js for PDF, rendered
// HTML for office/text, a card for the rest) — resolved by the panel.
$bn_fs_viewer = isset( $bn_fs_viewer_html ) ? (string) $bn_fs_viewer_html : '';

$bn_fs_back_url = $bn_fs_folder > 0 ? add_query_arg( 'bn_folder', $bn_fs_folder, $bn_fs_base_url ) : $bn_fs_base_url;
$bn_fs_date_out = '' !== $bn_fs_date ? mysql2date( (string) get_option( 'date_format' ), $bn_fs_date ) : '';
?>
<div class="bn-space-files bn-file-single">

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
		<?php if ( '' !== $bn_fs_dl_url ) : ?>
			<a class="bn-file-single__download" href="<?php echo esc_url( $bn_fs_dl_url ); ?>">
				<?php echo buddynext_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
				<span><?php esc_html_e( 'Download', 'buddynext' ); ?></span>
			</a>
		<?php endif; ?>
	</header>

	<?php if ( '' !== $bn_fs_viewer ) : ?>
		<div class="bn-file-single__preview bn-file-single__preview--mvs">
			<?php echo $bn_fs_viewer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaVerse's DocumentViewer returns its own sanitised, access-gated markup (pdf.js container / rendered HTML / card). ?>
		</div>
	<?php else : ?>
		<div class="bn-file-single__no-preview">
			<?php echo buddynext_icon( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IconService returns kses-safe SVG. ?>
			<p class="bn-file-single__no-preview-title"><?php esc_html_e( 'No preview for this file type', 'buddynext' ); ?></p>
			<p class="bn-file-single__no-preview-body"><?php esc_html_e( 'Download the file to open it in the right app.', 'buddynext' ); ?></p>
		</div>
	<?php endif; ?>

</div>
