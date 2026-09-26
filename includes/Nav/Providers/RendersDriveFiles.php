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
	 * @param array  $args       Embed options (buddynext_render_drive_files()): `can_write`
	 *                           false hides Upload / Link; `doc_query_links` true links
	 *                           single files as ?bn_doc={id} (any page) instead of
	 *                           the Files tab's clean {base}/{id}/ path.
	 * @return void
	 */
	protected function render_drive_files( string $drive_type, int $drive_id, string $base_url, int $doc_id = 0, array $args = array() ): void {
		$doc_query_links = ! empty( $args['doc_query_links'] );

		// The drive UI needs its reader island AND its uploader/folder module on
		// every surface it renders on. Loading them here, where every caller
		// routes through, means an embed can never ship with dead controls.
		// Idempotent: the tabs also enqueue early so the CSS lands in <head>.
		$assets = buddynext_service( 'assets' );
		$assets->enqueue( 'space-files' );
		$assets->enqueue( 'file-upload' );

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
					'bn_sf_doc_query'    => $doc_query_links,
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

		// The host page (an embed) may narrow writing but never widen it.
		$can_write = $view['can_write'] && ( ! isset( $args['can_write'] ) || (bool) $args['can_write'] );

		// Folder rules belong to MediaVerse (card 10343837359): it answers "may you
		// create a folder here" and, per row, "may you manage this folder" (owners
		// and moderators manage all; a member manages folders they created that
		// hold only their own files). BuddyNext only renders those answers. An
		// older MediaVerse sends neither, so fall back to the rule it used to
		// enforce: the space's owner/moderators, or the member on their own drive,
		// within MediaVerse's licence.
		$legacy_manage = $can_write
			&& ( 'space' === $drive_type ? $can_moderate : get_current_user_id() === $drive_id )
			&& class_exists( '\\WPMediaVersePro\\Documents\\DocumentLicense' )
			&& \WPMediaVersePro\Documents\DocumentLicense::can_write( get_current_user_id() );

		$can_create_folder = $can_write && ( null !== ( $view['can_create_folder'] ?? null ) ? (bool) $view['can_create_folder'] : $legacy_manage );
		$any_row_managed   = false;
		foreach ( $view['folders'] as $i => $folder_row ) {
			$row_manage                          = $can_write && ( isset( $folder_row['can_manage'] ) ? (bool) $folder_row['can_manage'] : $legacy_manage );
			$view['folders'][ $i ]['can_manage'] = $row_manage;
			$any_row_managed                     = $any_row_managed || $row_manage;
		}
		// Folder tools at all (the Trash view and the script hook-up): anyone who
		// may create a folder or manage one. MediaVerse scopes the trash list to
		// what the viewer may restore.
		$can_manage_folders = $can_create_folder || $any_row_managed;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( $can_manage_folders && ! empty( $_GET['bn_trash'] ) ) {
			$trash = WPMediaVerseBridge::drive_trash( $drive_type, $drive_id, $page );
			buddynext_get_template(
				'partials/space-files-trash.php',
				array(
					'bn_sft_drive'    => $drive_type . ':' . $drive_id,
					'bn_sft_base_url' => $base_url,
					'bn_sft_items'    => null === $trash ? array() : $trash['items'],
					'bn_sft_page'     => null === $trash ? 1 : $trash['page'],
					'bn_sft_pages'    => null === $trash ? 1 : $trash['pages'],
				)
			);
			return;
		}

		// Document upload config (enabled/accept/max_size). `enabled` folds in the
		// per-viewer write capability: it is false when documents are read-only
		// for this viewer (unlicensed MVS Pro, where writes 403). Capture it once
		// so the template can tell "this viewer may contribute but the site is
		// read-only" (documents present, composer off) from "documents disabled".
		$doc_config     = WPMediaVerseBridge::document_composer_config();
		$docs_read_only = WPMediaVerseBridge::documents_available() && empty( $doc_config['enabled'] );

		buddynext_get_template(
			'partials/space-files-tab.php',
			array(
				'bn_sf_space_id'           => $drive_id,
				'bn_sf_drive_type'         => $drive_type,
				'bn_sf_base_url'           => $base_url,
				'bn_sf_folders'            => $view['folders'],
				'bn_sf_documents'          => $view['documents'],
				'bn_sf_breadcrumbs'        => $view['breadcrumbs'],
				'bn_sf_folder'             => $view['folder'],
				'bn_sf_page'               => $view['page'],
				'bn_sf_pages'              => $view['pages'],
				'bn_sf_total'              => $view['total'],
				'bn_sf_folder_page'        => $view['folder_page'],
				'bn_sf_folder_pages'       => $view['folder_pages'],
				'bn_sf_folder_total'       => $view['folder_total'],
				// An embed may hide the write controls; it can never grant them.
				'bn_sf_can_write'          => $can_write,
				'bn_sf_can_manage_folders' => $can_manage_folders,
				'bn_sf_can_create_folder'  => $can_create_folder,
				'bn_sf_doc_query'          => $doc_query_links,
				'bn_sf_can_moderate'       => $can_moderate,
				// Drives the Files-tab uploader the same way the activity composer's
				// attach control is configured, so a contributor can add a file from
				// the Files tab itself (into the current drive + folder).
				'bn_sf_doc_config'         => $doc_config,
				// A member the drive grants write to, on a read-only (unlicensed)
				// site, would otherwise meet a silent read-only Files tab. The
				// template shows an explanatory notice instead (card 10256943808).
				'bn_sf_docs_read_only'     => $docs_read_only,
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
