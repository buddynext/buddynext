<?php
/**
 * Shared Files-tab renderer for a document drive, either kind.
 *
 * BuddyNext owns the document-drive UI end to end (WPMediaVerse renders none into
 * a BuddyNext surface — it only serves the data). The space Files tab and the
 * profile Files tab are the SAME UI pointed at two different drives — a space
 * drive (`space:N`) or the member's own drive (`user:N`) — so the browse / search
 * / single-document logic lives here once and both nav providers use it. The only
 * per-surface differences are the drive descriptor, the base URL, and how the
 * single-document id arrives in the URL (each provider resolves its own path
 * segment and passes the id in).
 *
 * @package BuddyNext
 */

namespace BuddyNext\Nav\Providers;

use BuddyNext\Bridges\WPMediaVerseBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a document-drive Files tab (list / search / single-document).
 */
trait RendersDriveFiles {

	/**
	 * Render the Files tab for one drive.
	 *
	 * Read-only view controls (folder, page, search) come off the GET query,
	 * mirroring the same params on either surface; nothing is written, so no
	 * nonce is involved. The single-document id is resolved by the caller (from
	 * its own clean-URL path segment, or the `?bn_doc=` alias) and passed in.
	 *
	 * @param string $drive_type 'space' or 'user'.
	 * @param int    $drive_id   Drive id (space id, or the profile owner id).
	 * @param string $base_url   The Files tab URL (list root + single-doc base).
	 * @param int    $doc_id     Single-document id, or 0 for the list.
	 * @return void
	 */
	protected function render_drive_files( string $drive_type, int $drive_id, string $base_url, int $doc_id = 0 ): void {
		// Single-file view — a real deep-linkable page, not a modal.
		if ( $doc_id > 0 ) {
			$this->render_drive_file_single( $drive_type, $drive_id, $doc_id, $base_url );
			return;
		}

		// On a space drive the Files tab's Remove control UNLINKS (returns the file
		// to its owner's personal drive), which a space moderator may do to anyone's
		// file — an author may always remove their own, checked per row. Compute the
		// moderator authority once here; it is meaningless on a personal drive.
		$can_moderate = false;
		if ( 'space' === $drive_type ) {
			$role         = ( new \BuddyNext\Spaces\SpaceMemberService() )->get_role( $drive_id, get_current_user_id() );
			$can_moderate = \BuddyNext\Spaces\SpaceRoles::can_moderate( $role, get_current_user_id() );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET view controls.
		$query  = isset( $_GET['bn_q'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_q'] ) ) : '';
		$folder = isset( $_GET['bn_folder'] ) ? absint( wp_unslash( $_GET['bn_folder'] ) ) : 0;
		$page   = isset( $_GET['bn_files_page'] ) ? max( 1, absint( wp_unslash( $_GET['bn_files_page'] ) ) ) : 1;
		$fpage  = isset( $_GET['bn_folder_page'] ) ? max( 1, absint( wp_unslash( $_GET['bn_folder_page'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Search mode — a flat, drive-scoped result set instead of the folder
		// listing. Empty query falls straight through to the folder listing.
		if ( '' !== $query ) {
			$search = 'user' === $drive_type
				? WPMediaVerseBridge::user_drive_search( $drive_id, $query, $page )
				: WPMediaVerseBridge::space_drive_search( $drive_id, $query, $page );
			if ( null === $search ) {
				$this->render_drive_files_empty( $drive_type );
				return;
			}
			buddynext_get_template(
				'partials/space-files-tab.php',
				array(
					'bn_sf_space_id'     => $drive_id,
					'bn_sf_drive_type'   => $drive_type,
					'bn_sf_base_url'     => $base_url,
					'bn_sf_search_q'     => $search['query'],
					'bn_sf_search_ready' => $search['ready'],
					'bn_sf_documents'    => $search['items'],
					'bn_sf_folders'      => array(),
					'bn_sf_breadcrumbs'  => array(),
					'bn_sf_folder'       => 0,
					'bn_sf_page'         => $search['page'],
					'bn_sf_pages'        => $search['pages'],
					'bn_sf_total'        => $search['total'],
					'bn_sf_can_moderate' => $can_moderate,
				)
			);
			return;
		}

		$view = 'user' === $drive_type
			? WPMediaVerseBridge::user_drive_view( $drive_id, $folder, $page, $fpage )
			: WPMediaVerseBridge::space_drive_view( $drive_id, $folder, $page, $fpage );

		if ( null === $view ) {
			$this->render_drive_files_empty( $drive_type );
			return;
		}

		buddynext_get_template(
			'partials/space-files-tab.php',
			array(
				'bn_sf_space_id'     => $drive_id,
				'bn_sf_drive_type'   => $drive_type,
				'bn_sf_base_url'     => $base_url,
				'bn_sf_folders'      => $view['folders'],
				'bn_sf_documents'    => $view['documents'],
				'bn_sf_breadcrumbs'  => $view['breadcrumbs'],
				'bn_sf_folder'       => $view['folder'],
				'bn_sf_page'         => $view['page'],
				'bn_sf_pages'        => $view['pages'],
				'bn_sf_total'        => $view['total'],
				'bn_sf_folder_page'  => $view['folder_page'],
				'bn_sf_folder_pages' => $view['folder_pages'],
				'bn_sf_folder_total' => $view['folder_total'],
				'bn_sf_can_write'    => $view['can_write'],
				'bn_sf_can_moderate' => $can_moderate,
				// Document upload config (enabled/accept/max_size). Drives the Files-tab
				// uploader the same way the activity composer's attach control is
				// configured, so a contributor can add a file from the Files tab itself
				// (into the current drive + folder) rather than only via a post.
				'bn_sf_doc_config'   => WPMediaVerseBridge::document_composer_config(),
			)
		);
	}

	/**
	 * Render the single-document view — details plus BuddyNext's own inline
	 * preview (the template ships the reader island). A cross-drive or unreadable
	 * id resolves to null and shows "file not found", never another drive's
	 * document under this tab.
	 *
	 * @param string $drive_type 'space' or 'user'.
	 * @param int    $drive_id   Drive id.
	 * @param int    $doc_id     Document id.
	 * @param string $base_url   The Files tab URL (for the back link).
	 * @return void
	 */
	protected function render_drive_file_single( string $drive_type, int $drive_id, int $doc_id, string $base_url ): void {
		$doc = 'user' === $drive_type
			? WPMediaVerseBridge::user_drive_document( $drive_id, $doc_id )
			: WPMediaVerseBridge::space_drive_document( $drive_id, $doc_id );

		if ( null === $doc ) {
			buddynext_get_template(
				'parts/empty-state.php',
				array(
					'icon'  => 'file-text',
					'title' => __( 'File not found', 'buddynext' ),
					'body'  => __( 'This file may have been moved or removed, or it is not shared with you.', 'buddynext' ),
				)
			);
			return;
		}

		// Sharing (members + link) is offered only where the viewer may actually
		// grant, and on a writable site. A personal drive's owner qualifies (the
		// profile Files tab is self-only, so the viewer IS the owner). A space
		// drive's owner or moderator qualifies too - the write authority MediaVerse
		// now enforces server-side (can_grant returns true for a contributing Space
		// member since MVS 2.4.0). A plain space member reads the drive but sees no
		// Share control, matching what the grant endpoint would refuse anyway.
		$bn_fs_viewer = get_current_user_id();
		$can_share    = WPMediaVerseBridge::documents_writable()
			&& (
				'user' === $drive_type
				|| WPMediaVerseBridge::space_drive_can_share( $drive_id, $bn_fs_viewer )
			);

		buddynext_get_template(
			'partials/space-file-single.php',
			array(
				'bn_fs_doc'       => $doc,
				'bn_fs_base_url'  => $base_url,
				'bn_fs_folder'    => isset( $doc['folder'] ) ? (int) $doc['folder'] : 0,
				'bn_fs_can_share' => $can_share,
			)
		);
	}

	/**
	 * The neutral empty state, worded for the drive kind.
	 *
	 * @param string $drive_type 'space' or 'user'.
	 * @return void
	 */
	private function render_drive_files_empty( string $drive_type ): void {
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'folder',
				'title' => __( 'No files to show', 'buddynext' ),
				'body'  => 'user' === $drive_type
					? __( 'Documents you share appear here. Attach one to a post to add it.', 'buddynext' )
					: __( 'Files shared with this space will appear here.', 'buddynext' ),
			)
		);
	}
}
