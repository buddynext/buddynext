<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Avatar / cover image storage.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Member and space avatars/covers are NOT WordPress attachments (no
 * wp_posts / wp_postmeta rows) — at 100k members that would bloat core tables.
 * Each image is a plain file whose URL is stored in usermeta
 * (`bn_avatar` / `buddynext_cover_url`) or the bn_spaces columns.
 *
 * LAYOUT
 * ──────
 * Every owner gets its own folder, never mixed with the WP media library:
 *
 *   uploads/bn-avatars/{user_id}/{variation}.webp        (member avatar)
 *   uploads/bn-covers/{user_id}/{variation}.webp         (member cover)
 *   uploads/bn-space-avatars/{space_id}/{variation}.webp (space avatar)
 *   uploads/bn-space-covers/{space_id}/{variation}.webp  (space cover)
 *
 * Each folder holds the WebP size variations for that one image (e.g. a
 * full-size and a thumbnail). On replace the owner's folder is wiped first,
 * so old variations are never orphaned and there's no cleanup cron. The
 * returned URL carries a `?v=` cache-buster since the filenames are stable.
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

use WP_Error;

/**
 * Stores avatar/cover images in per-owner uploads folders as WebP variations.
 */
class ImageStorageService {

	/**
	 * Size variations generated per kind: variation name => longest edge (px).
	 * The first entry is the primary one whose URL is returned + stored.
	 * Avatars are centre-cropped square; covers are width-capped.
	 *
	 * @var array<string,array<string,int>>
	 */
	private const VARIATIONS = array(
		'avatar' => array(
			'full'  => 512,
			'thumb' => 128,
		),
		'cover'  => array(
			'full'  => 1600,
			'small' => 640,
		),
	);

	/**
	 * Store an uploaded image for an owner and return the primary variation URL.
	 *
	 * Wipes the owner's image folder first, so a replacement leaves no orphan.
	 *
	 * @param string $tmp_path Absolute path to the uploaded temp file.
	 * @param string $kind     'avatar' | 'cover'.
	 * @param string $owner    'user' | 'space'.
	 * @param int    $id       Owner id.
	 * @return string|WP_Error Primary variation URL (with cache-buster) or error.
	 */
	public function store( string $tmp_path, string $kind, string $owner, int $id ): string|WP_Error {
		if ( ! isset( self::VARIATIONS[ $kind ] ) || ! in_array( $owner, array( 'user', 'space' ), true ) || $id <= 0 ) {
			return new WP_Error( 'bn_image_args', __( 'Invalid image parameters.', 'buddynext' ) );
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$dir = $this->dir_for( $kind, $owner, $id );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'bn_image_mkdir', __( 'Could not create the image directory.', 'buddynext' ) );
		}

		// Clean slate — remove any previous variations for this owner so a
		// re-upload (or a format change) never leaves an orphaned file.
		$this->purge_dir( $dir );

		$webp_ok     = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$ext         = $webp_ok ? 'webp' : 'jpg';
		$mime        = $webp_ok ? 'image/webp' : 'image/jpeg';
		$is_avatar   = ( 'avatar' === $kind );
		$primary_url = '';
		$written     = array(); // variation => absolute path, for the sync hook.

		// Generate largest → smallest so each step can downscale the previous.
		foreach ( self::VARIATIONS[ $kind ] as $variation => $size ) {
			$editor = wp_get_image_editor( $tmp_path );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			if ( $is_avatar ) {
				$editor->resize( $size, $size, true ); // centre-crop square.
			} else {
				$editor->resize( $size, null, false ); // cap width.
			}

			$dest  = trailingslashit( $dir ) . $variation . '.' . $ext;
			$saved = $editor->save( $dest, $mime );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$path                  = isset( $saved['path'] ) ? (string) $saved['path'] : $dest;
			$written[ $variation ] = $path;
			if ( '' === $primary_url ) {
				$primary_url = $this->url_for_path( $path );
			}
		}

		/**
		 * Fires after an avatar/cover and all its variations are written to
		 * disk. A CDN / offload integration (Bunny.net, S3, a MediaVerse-style
		 * media sync, etc.) hooks this to PUSH the files to remote storage —
		 * then rewrites the public URL via `buddynext_stored_image_url` and may
		 * remove the local copies. Mirrors how WPMediaVerse syncs its media.
		 *
		 * @param string                $kind     'avatar' | 'cover'.
		 * @param string                $owner    'user' | 'space'.
		 * @param int                   $id       Owner id.
		 * @param array<string,string>  $written  variation => absolute file path.
		 */
		do_action( 'buddynext_image_stored', $kind, $owner, $id, $written );

		/**
		 * Filter the stored avatar/cover URL (e.g. to rewrite onto a CDN host).
		 *
		 * @param string $url   Public URL of the primary variation.
		 * @param string $kind  'avatar' | 'cover'.
		 * @param string $owner 'user' | 'space'.
		 * @param int    $id    Owner id.
		 */
		$primary_url = (string) apply_filters( 'buddynext_stored_image_url', $primary_url, $kind, $owner, $id );

		return add_query_arg( 'v', (string) time(), $primary_url );
	}

	/**
	 * Public URL for a specific variation of an owner's image — so each surface
	 * can request the right size (e.g. the rail uses the avatar `thumb`, the
	 * profile hero uses `full`) instead of one image scaled everywhere.
	 *
	 * Routes through `buddynext_stored_image_url` so a CDN host applies here too.
	 *
	 * @param string $kind      'avatar' | 'cover'.
	 * @param string $owner     'user' | 'space'.
	 * @param int    $id        Owner id.
	 * @param string $variation Variation name (see self::VARIATIONS). Default 'full'.
	 * @return string Public URL, or '' when the variation file is absent.
	 */
	public function variation_url( string $kind, string $owner, int $id, string $variation = 'full' ): string {
		if ( ! isset( self::VARIATIONS[ $kind ][ $variation ] ) || $id <= 0 ) {
			return '';
		}
		$dir = $this->dir_for( $kind, $owner, $id );
		foreach ( array( 'webp', 'jpg', 'jpeg', 'png' ) as $ext ) {
			$path = trailingslashit( $dir ) . $variation . '.' . $ext;
			if ( file_exists( $path ) ) {
				$url = (string) apply_filters( 'buddynext_stored_image_url', $this->url_for_path( $path ), $kind, $owner, $id );
				return $url;
			}
		}
		return '';
	}

	/**
	 * Delete an owner's entire image folder (all variations).
	 *
	 * @param string $kind  'avatar' | 'cover'.
	 * @param string $owner 'user' | 'space'.
	 * @param int    $id    Owner id.
	 */
	public function delete( string $kind, string $owner, int $id ): void {
		if ( ! isset( self::VARIATIONS[ $kind ] ) || $id <= 0 ) {
			return;
		}
		$dir = $this->dir_for( $kind, $owner, $id );

		/**
		 * Fires before an owner's image folder is removed, so a CDN / offload
		 * integration can PURGE the remote copies it pushed on store.
		 *
		 * @param string $kind  'avatar' | 'cover'.
		 * @param string $owner 'user' | 'space'.
		 * @param int    $id    Owner id.
		 * @param string $dir   Absolute folder being removed.
		 */
		do_action( 'buddynext_image_deleted', $kind, $owner, $id, $dir );

		$this->purge_dir( $dir );
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup.
		}
	}

	/**
	 * The uploads sub-directory name for an owner+kind, e.g. `bn-avatars`.
	 *
	 * @param string $kind  'avatar' | 'cover'.
	 * @param string $owner 'user' | 'space'.
	 * @return string
	 */
	private function folder( string $kind, string $owner ): string {
		$prefix = ( 'space' === $owner ) ? 'bn-space-' : 'bn-';
		return $prefix . ( 'avatar' === $kind ? 'avatars' : 'covers' );
	}

	/**
	 * Absolute filesystem directory holding an owner's image variations.
	 *
	 * @param string $kind  'avatar' | 'cover'.
	 * @param string $owner 'user' | 'space'.
	 * @param int    $id    Owner id.
	 * @return string
	 */
	private function dir_for( string $kind, string $owner, int $id ): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . $this->folder( $kind, $owner ) . '/' . $id;
	}

	/**
	 * Remove every file directly inside a directory (non-recursive — our image
	 * folders are flat). Leaves the directory itself in place for re-use.
	 *
	 * @param string $dir Absolute directory path.
	 */
	private function purge_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( trailingslashit( $dir ) . '*' );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Convert an absolute uploads path to its public URL.
	 *
	 * @param string $abs Absolute filesystem path inside the uploads dir.
	 * @return string
	 */
	private function url_for_path( string $abs ): string {
		$uploads = wp_upload_dir();
		return str_replace(
			trailingslashit( $uploads['basedir'] ),
			trailingslashit( $uploads['baseurl'] ),
			$abs
		);
	}
}
