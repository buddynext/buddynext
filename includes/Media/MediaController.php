<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Member-facing media REST controller (buddynext/v1).
 *
 * Powers the BuddyNext-native upload + gallery experience on a member's own
 * profile Media tab. Members upload to their OWN profile only; the engine's own
 * upload REST is NOT used (it requires the upload_mvs_media capability that most
 * members lack). Instead this controller consumes the WPMediaVerse engine purely
 * through the BuddyNext\Media\MediaClient seam, server-side, and BuddyNext's own
 * ownership gate (logged-in + acting on own media) is the authority.
 *
 *   POST   /me/media               — upload one file to own profile (auth)
 *   GET    /users/{id}/media       — paginated gallery HTML for a profile (auth)
 *   DELETE /me/media/{media_id}    — trash own media (auth + owner)
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints for member media upload + gallery on the profile Media tab.
 */
class MediaController extends BaseRestController {

	/**
	 * Map the feed/post privacy vocabulary the composer offers
	 * (public/followers/connections/private) to the engine's media-file privacy.
	 * A batch upload becomes a feed post, so the member picks the POST audience;
	 * the media file's engine privacy is derived to roughly match. The engine
	 * has no followers/connections concept, so both collapse to logged-in
	 * 'members' — the post's own privacy is what gates the feed surface.
	 *
	 * @var array<string,string>
	 */
	private const PRIVACY_MAP = array(
		'public'      => 'public',
		'followers'   => 'members',
		'connections' => 'members',
		'private'     => 'private',
	);

	/**
	 * Register routes under buddynext/v1.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/me/media',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_own_media' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/media',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_user_media' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id'       => array(
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'sanitize_callback' => 'absint',
							'default'           => 1,
						),
						'per_page' => array(
							'sanitize_callback' => 'absint',
							'default'           => 24,
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/media/(?P<media_id>[\d]+)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_own_media' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'media_id' => array(
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// ── Albums ────────────────────────────────────────────────────────────
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/albums',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_user_albums' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id'       => array( 'sanitize_callback' => 'absint' ),
						'page'     => array(
							'sanitize_callback' => 'absint',
							'default'           => 1,
						),
						'per_page' => array(
							'sanitize_callback' => 'absint',
							'default'           => 24,
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/albums',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_album' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/albums/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_album' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id'       => array( 'sanitize_callback' => 'absint' ),
						'page'     => array(
							'sanitize_callback' => 'absint',
							'default'           => 1,
						),
						'per_page' => array(
							'sanitize_callback' => 'absint',
							'default'           => 24,
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/albums/(?P<id>[\d]+)/items',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_album_items' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id' => array( 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/albums/(?P<id>[\d]+)/items/(?P<media_id>[\d]+)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_album_item' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id'       => array( 'sanitize_callback' => 'absint' ),
						'media_id' => array( 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/albums/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_album' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id' => array( 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_album' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id' => array( 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/albums/(?P<id>[\d]+)/reorder',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'reorder_album' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id' => array( 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);
	}

	/**
	 * Upload a single file to the current member's own profile media.
	 *
	 * @param WP_REST_Request $request Request (multipart/form-data).
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_own_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$upload = MediaClient::upload();
		if ( ! $upload || ! method_exists( $upload, 'handle' ) ) {
			return new WP_Error(
				'bn_media_unavailable',
				__( 'Media uploads are unavailable right now.', 'buddynext' ),
				array( 'status' => 503 )
			);
		}

		/*
		 * The REST layer already verified the X-WP-Nonce header before this
		 * callback fires, so the $_FILES read below is authenticated. WPCS cannot
		 * see the REST auth layer, hence the scoped suppressions.
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		 */
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] )
			? $_FILES['file']
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error(
				'bn_media_missing',
				__( 'No file uploaded, or the upload failed.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		$user_id = get_current_user_id();

		$file_data = array(
			'name'     => sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
			'type'     => (string) ( $file['type'] ?? '' ),
			'tmp_name' => (string) ( $file['tmp_name'] ?? '' ),
			'error'    => (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ),
			'size'     => (int) ( $file['size'] ?? 0 ),
		);

		$args = array(
			'title'       => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'privacy'     => $this->sanitize_privacy( (string) $request->get_param( 'privacy' ) ),
		);

		// The engine validates MIME, size, duplicates, strips EXIF, optimizes and
		// generates thumbnails; we hand it the file and the member as author so the
		// new media surfaces in their own gallery (Galleries::user_media_ids).
		$media_id = $upload->handle( $file_data, $user_id, $args );

		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}

		$descriptor = MediaUrlResolver::descriptor( (int) $media_id );

		$duplicate_id = method_exists( $upload, 'get_last_duplicate_warning' )
			? (int) $upload->get_last_duplicate_warning()
			: 0;

		return new WP_REST_Response(
			array(
				'media'             => $descriptor,
				'duplicate_warning' => $duplicate_id > 0,
				'existing_media_id' => $duplicate_id,
			),
			201
		);
	}

	/**
	 * Return a page of a member's gallery as rendered tile HTML.
	 *
	 * Returns the SAME markup the profile template renders (MediaRenderer), so
	 * the client can swap the grid in place after an upload/delete without a
	 * second tile renderer drifting out of sync. Privacy is enforced per-viewer
	 * inside Galleries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_user_media( WP_REST_Request $request ): WP_REST_Response {
		$owner    = (int) $request->get_param( 'id' );
		$viewer   = get_current_user_id();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 60, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$ids   = Galleries::user_media_ids( $owner, $viewer, $per_page, $offset );
		$total = Galleries::user_media_count( $owner, $viewer );

		$response = new WP_REST_Response(
			array(
				'html'        => MediaRenderer::gallery( array_map( 'absint', $ids ) ),
				'ids'         => array_map( 'absint', $ids ),
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			),
			200
		);
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Trash a media item the current member owns.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_own_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$repo = MediaClient::repo();
		if ( ! $repo || ! method_exists( $repo, 'trash' ) ) {
			return new WP_Error(
				'bn_media_unavailable',
				__( 'Media is unavailable right now.', 'buddynext' ),
				array( 'status' => 503 )
			);
		}

		$media_id = (int) $request->get_param( 'media_id' );
		$user_id  = get_current_user_id();
		$author   = method_exists( $repo, 'get_author' ) ? (int) $repo->get_author( $media_id ) : 0;

		// Owner-only: the member can delete their own media; site managers may
		// moderate any media. No other member can delete it.
		if ( $author !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'bn_media_forbidden',
				__( 'You can only remove your own media.', 'buddynext' ),
				array( 'status' => current_user_can( 'read' ) ? 403 : 401 )
			);
		}

		if ( $author <= 0 ) {
			// Already gone / never existed — report success so the UI converges.
			return new WP_REST_Response(
				array(
					'deleted' => true,
					'id'      => $media_id,
				),
				200
			);
		}

		$trashed = (bool) $repo->trash( $media_id );

		if ( ! $trashed ) {
			return new WP_Error(
				'bn_media_delete_failed',
				__( 'Could not remove that media. Please try again.', 'buddynext' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $media_id,
			),
			200
		);
	}

	/**
	 * GET /users/{id}/albums — a user's albums, privacy-filtered for the viewer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_user_albums( WP_REST_Request $request ): WP_REST_Response {
		$owner    = (int) $request->get_param( 'id' );
		$viewer   = get_current_user_id();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 60, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		return new WP_REST_Response(
			array(
				'albums'   => Galleries::user_albums( $owner, $viewer, $per_page, $offset ),
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * POST /me/albums — create an album owned by the current member.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_album( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$svc = MediaClient::albums();
		if ( ! $svc || ! method_exists( $svc, 'create' ) ) {
			return new WP_Error( 'bn_albums_unavailable', __( 'Albums are unavailable right now.', 'buddynext' ), array( 'status' => 503 ) );
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'bn_album_title_required', __( 'An album needs a name.', 'buddynext' ), array( 'status' => 422 ) );
		}

		$album_id = $svc->create(
			get_current_user_id(),
			array(
				'title'       => $title,
				'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
				'privacy'     => $this->sanitize_album_privacy( (string) $request->get_param( 'privacy' ) ),
			)
		);
		if ( is_wp_error( $album_id ) ) {
			return $album_id;
		}

		return new WP_REST_Response( Galleries::album_summary( (int) $album_id ), 201 );
	}

	/**
	 * GET /albums/{id} — album detail + a page of its media (privacy-checked).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_album( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$album_id = (int) $request->get_param( 'id' );
		$viewer   = get_current_user_id();

		if ( 'mvs_album' !== get_post_type( $album_id ) ) {
			return new WP_Error( 'bn_album_not_found', __( 'Album not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$is_owner = $this->album_owned_by_current( $album_id );
		if ( ! $is_owner && ! Galleries::can_view_album( $album_id, $viewer ) ) {
			// Do not disclose existence of a private album.
			return new WP_Error( 'bn_album_not_found', __( 'Album not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 60, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$ids     = Galleries::album_media_ids( $album_id, $per_page, $offset );
		$summary = Galleries::album_summary( $album_id );

		return new WP_REST_Response(
			array_merge(
				$summary,
				array(
					'is_owner'    => $is_owner,
					'html'        => MediaRenderer::gallery( array_map( 'absint', $ids ) ),
					'ids'         => array_map( 'absint', $ids ),
					'page'        => $page,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( max( 1, (int) $summary['media_count'] ) / $per_page ),
				)
			),
			200
		);
	}

	/**
	 * POST /me/albums/{id}/items — add media (owner only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_album_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$album_id = (int) $request->get_param( 'id' );
		$gate     = $this->require_album_owner( $album_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$media_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'media_ids' ) ) ) ) );
		if ( empty( $media_ids ) ) {
			return new WP_Error( 'bn_album_no_media', __( 'No media to add.', 'buddynext' ), array( 'status' => 422 ) );
		}

		$svc   = MediaClient::albums();
		$added = ( $svc && method_exists( $svc, 'add_items' ) ) ? (int) $svc->add_items( $album_id, $media_ids ) : 0;

		return new WP_REST_Response(
			array(
				'added'       => $added,
				'media_count' => ( $svc && method_exists( $svc, 'get_item_count' ) ) ? (int) $svc->get_item_count( $album_id ) : 0,
			),
			200
		);
	}

	/**
	 * DELETE /me/albums/{id}/items/{media_id} — remove media from album (owner only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_album_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$album_id = (int) $request->get_param( 'id' );
		$gate     = $this->require_album_owner( $album_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$media_id = (int) $request->get_param( 'media_id' );
		$svc      = MediaClient::albums();
		if ( $svc && method_exists( $svc, 'remove_item' ) ) {
			$svc->remove_item( $album_id, $media_id );
		}

		return new WP_REST_Response(
			array(
				'removed'     => true,
				'media_count' => ( $svc && method_exists( $svc, 'get_item_count' ) ) ? (int) $svc->get_item_count( $album_id ) : 0,
			),
			200
		);
	}

	/**
	 * PUT /me/albums/{id} — update title/description/privacy/cover (owner only).
	 *
	 * Mirrors the engine's own update: post fields via wp_update_post, privacy via
	 * the media repo (album privacy is keyed by album id), cover via set_cover.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_album( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$album_id = (int) $request->get_param( 'id' );
		$gate     = $this->require_album_owner( $album_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$update = array( 'ID' => $album_id );

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$title = sanitize_text_field( (string) $title );
			if ( '' === $title ) {
				return new WP_Error( 'bn_album_title_required', __( 'An album needs a name.', 'buddynext' ), array( 'status' => 422 ) );
			}
			$update['post_title'] = $title;
		}

		$desc = $request->get_param( 'description' );
		if ( null !== $desc ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $desc );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		$privacy = $request->get_param( 'privacy' );
		if ( null !== $privacy ) {
			$repo = MediaClient::repo();
			if ( $repo && method_exists( $repo, 'set' ) ) {
				$repo->set( $album_id, 'privacy', $this->sanitize_album_privacy( (string) $privacy ) );
			}
		}

		$cover = $request->get_param( 'cover_media_id' );
		if ( null !== $cover ) {
			$svc = MediaClient::albums();
			if ( $svc && method_exists( $svc, 'set_cover' ) ) {
				$svc->set_cover( $album_id, (int) $cover );
			}
		}

		return new WP_REST_Response( Galleries::album_summary( $album_id ), 200 );
	}

	/**
	 * DELETE /me/albums/{id} — delete an album + its item links (owner only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_album( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$album_id = (int) $request->get_param( 'id' );
		$gate     = $this->require_album_owner( $album_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$svc = MediaClient::albums();
		if ( $svc && method_exists( $svc, 'delete_all_items' ) ) {
			$svc->delete_all_items( $album_id );
		}
		wp_delete_post( $album_id, true );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $album_id,
			),
			200
		);
	}

	/**
	 * PUT /me/albums/{id}/reorder — set item order (owner only).
	 *
	 * @param WP_REST_Request $request Request. Body: order[] (media ids, new order).
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_album( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$album_id = (int) $request->get_param( 'id' );
		$gate     = $this->require_album_owner( $album_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		// Numeric keys become the 0-indexed positions the engine expects.
		$order = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'order' ) ) ) );

		$svc = MediaClient::albums();
		if ( $svc && method_exists( $svc, 'reorder' ) ) {
			$svc->reorder( $album_id, $order );
		}

		return new WP_REST_Response( array( 'reordered' => true ), 200 );
	}

	/**
	 * Whether the current user owns the album (or is a site manager).
	 *
	 * @param int $album_id Album id.
	 * @return bool
	 */
	private function album_owned_by_current( int $album_id ): bool {
		if ( 'mvs_album' !== get_post_type( $album_id ) ) {
			return false;
		}
		$author = (int) get_post_field( 'post_author', $album_id );
		return ( get_current_user_id() === $author ) || current_user_can( 'manage_options' );
	}

	/**
	 * Owner gate for album write operations.
	 *
	 * @param int $album_id Album id.
	 * @return true|WP_Error
	 */
	private function require_album_owner( int $album_id ): bool|WP_Error {
		if ( 'mvs_album' !== get_post_type( $album_id ) ) {
			return new WP_Error( 'bn_album_not_found', __( 'Album not found.', 'buddynext' ), array( 'status' => 404 ) );
		}
		if ( ! $this->album_owned_by_current( $album_id ) ) {
			return new WP_Error(
				'bn_album_forbidden',
				__( 'You can only manage your own albums.', 'buddynext' ),
				array( 'status' => current_user_can( 'read' ) ? 403 : 401 )
			);
		}
		return true;
	}

	/**
	 * Map a composer (post) privacy choice to the engine media-file privacy.
	 * Unknown values fall back to public.
	 *
	 * @param string $privacy Post-vocabulary privacy (public/followers/connections/private).
	 * @return string Engine media privacy (public/members/private).
	 */
	private function sanitize_privacy( string $privacy ): string {
		$privacy = sanitize_key( $privacy );

		return self::PRIVACY_MAP[ $privacy ] ?? 'public';
	}

	/**
	 * Clamp an album privacy choice to the engine's album vocabulary.
	 *
	 * @param string $privacy Raw privacy.
	 * @return string public|members|private (default public).
	 */
	private function sanitize_album_privacy( string $privacy ): string {
		$privacy = sanitize_key( $privacy );

		return in_array( $privacy, array( 'public', 'members', 'private' ), true ) ? $privacy : 'public';
	}
}
