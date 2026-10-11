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
 *   uploads/bn-avatars/{user_id}/{variation}-{version}.webp        (member avatar)
 *   uploads/bn-covers/{user_id}/{variation}-{version}.webp         (member cover)
 *   uploads/bn-space-avatars/{space_id}/{variation}-{version}.webp (space avatar)
 *   uploads/bn-space-covers/{space_id}/{variation}-{version}.webp  (space cover)
 *
 * Each folder holds the WebP size variations for that one image (e.g. a
 * full-size and a thumbnail).
 *
 * FILE NAMES CARRY A VERSION
 * ──────────────────────────
 * A replaced image gets a new file name, so it has a new URL for the browser
 * and for every CDN or image optimiser in between. The name used to be fixed
 * with a `?v=` query string doing the cache-busting; a CDN that drops or
 * rewrites the query string then served the old image for as long as its cache
 * lived, up to a year (card 10390900499).
 *
 * On replace the folder keeps the version it is replacing for a short grace
 * period (a day by default), so a page held in a page cache still shows the
 * previous image instead of a broken one. Then it is removed: a member who
 * replaces a photo expects the old one to be gone, not reachable at its old
 * address for as long as they keep the new one. An image stored under the old
 * fixed name keeps resolving until it is next replaced; nothing is migrated.
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
	 * Job that removes a replaced image once its grace period is over.
	 */
	public const PRUNE_HOOK = 'buddynext_prune_replaced_image';

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
	 * Keeps the files being replaced as the one previous version and removes
	 * anything older (see the file header).
	 *
	 * @param string $tmp_path Absolute path to the uploaded temp file.
	 * @param string $kind     'avatar' | 'cover'.
	 * @param string $owner    'user' | 'space'.
	 * @param int    $id       Owner id.
	 * @return string|WP_Error Primary variation URL or error.
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

		/**
		 * How long a replaced avatar or cover stays on disk, in seconds.
		 *
		 * The grace period exists for page caches: HTML cached before the change
		 * still points at the old file. Return 0 to remove the old image at once.
		 *
		 * @since 1.2.5
		 *
		 * @param int    $seconds Grace period. Default one day.
		 * @param string $kind    'avatar' | 'cover'.
		 * @param string $owner   'user' | 'space'.
		 * @param int    $id      Owner id.
		 */
		$grace = (int) apply_filters( 'buddynext_replaced_image_grace', DAY_IN_SECONDS, $kind, $owner, $id );

		// Keep what is being replaced (for the grace period), drop what that itself
		// replaced. With no grace period nothing is kept.
		$previous = $grace > 0 ? $this->current_version( $dir ) : null;
		$this->purge_dir( $dir, $previous );
		if ( $grace > 0 && glob( trailingslashit( $dir ) . '*' ) ) {
			self::schedule_prune( $grace, $kind, $owner, $id );
		}

		// Seconds in hex: sorts by name in the order it was written, and two
		// saves in the same second simply overwrite each other.
		$version = dechex( time() );

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

			$dest  = trailingslashit( $dir ) . $variation . '-' . $version . '.' . $ext;
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
		return (string) apply_filters( 'buddynext_stored_image_url', $primary_url, $kind, $owner, $id );
	}

	/**
	 * Wire the job that removes a replaced image. Called once from Plugin::init().
	 *
	 * @return void
	 */
	public static function register_async(): void {
		add_action( self::PRUNE_HOOK, array( self::class, 'prune' ), 10, 3 );
	}

	/**
	 * Queue the removal of an owner's replaced image.
	 *
	 * On a host with no Action Scheduler and no working cron the old file simply
	 * stays until the image is next replaced, which is the behaviour without a
	 * grace period at all - degraded, never broken.
	 *
	 * @param int    $delay Seconds from now.
	 * @param string $kind  'avatar' | 'cover'.
	 * @param string $owner 'user' | 'space'.
	 * @param int    $id    Owner id.
	 * @return void
	 */
	private static function schedule_prune( int $delay, string $kind, string $owner, int $id ): void {
		$args = array( $kind, $owner, $id );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::PRUNE_HOOK, $args, 'buddynext' );
			return;
		}

		wp_schedule_single_event( time() + $delay, self::PRUNE_HOOK, $args );
	}

	/**
	 * Remove every version of an owner's image except the one in use.
	 *
	 * @param string $kind  'avatar' | 'cover'.
	 * @param string $owner 'user' | 'space'.
	 * @param int    $id    Owner id.
	 * @return void
	 */
	public static function prune( string $kind, string $owner, int $id ): void {
		$service = new self();
		if ( ! isset( self::VARIATIONS[ $kind ] ) || ! in_array( $owner, array( 'user', 'space' ), true ) || $id <= 0 ) {
			return;
		}
		$dir = $service->dir_for( $kind, $owner, $id );
		$service->purge_dir( $dir, $service->current_version( $dir ) );
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

		// The newest versioned file. The version is fixed-width hex, so the last
		// name in sorted order is the latest; the URL is stable between requests
		// and changes exactly when the image does.
		$versioned = glob( trailingslashit( $dir ) . $variation . '-*.*' );
		if ( $versioned ) {
			sort( $versioned );

			return (string) apply_filters( 'buddynext_stored_image_url', $this->url_for_path( (string) end( $versioned ) ), $kind, $owner, $id );
		}

		// An image stored before file names carried a version. The name is fixed,
		// so the file's mtime in a query string is its only cache-buster.
		foreach ( array( 'webp', 'jpg', 'jpeg', 'png' ) as $ext ) {
			$path = trailingslashit( $dir ) . $variation . '.' . $ext;
			if ( file_exists( $path ) ) {
				$url   = (string) apply_filters( 'buddynext_stored_image_url', $this->url_for_path( $path ), $kind, $owner, $id );
				$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a race with delete() must not warn; false is handled below.

				return $mtime ? add_query_arg( 'v', (string) $mtime, $url ) : $url;
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
			// Files are removed via wp_delete_file() in purge_dir(); only the now-empty,
			// plugin-owned uploads subfolder remains. Core has no wp_* wrapper for
			// removing a directory and bootstrapping WP_Filesystem for a best-effort
			// cleanup is disproportionate, so rmdir() is used directly and silenced.
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of plugin-owned uploads folder.
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
	 * The version of a file name: '4f2a9c10' for `thumb-4f2a9c10.webp`, and ''
	 * for the fixed name used before names carried a version (`thumb.webp`).
	 *
	 * @param string $file File path or name.
	 * @return string
	 */
	private function version_of( string $file ): string {
		return preg_match( '/-([0-9a-f]+)\.[a-z]+$/', basename( $file ), $m ) ? $m[1] : '';
	}

	/**
	 * The version of the image a folder currently serves.
	 *
	 * @param string $dir Absolute directory path.
	 * @return string Newest version in the folder; '' for an old fixed-name image
	 *                or an empty folder.
	 */
	private function current_version( string $dir ): string {
		$versions = array_map( array( $this, 'version_of' ), glob( trailingslashit( $dir ) . '*' ) ?: array() );

		return $versions ? (string) max( $versions ) : '';
	}

	/**
	 * Remove the files directly inside a directory (non-recursive — our image
	 * folders are flat). Leaves the directory itself in place for re-use.
	 *
	 * @param string      $dir  Absolute directory path.
	 * @param string|null $keep Version whose files stay; null removes every file.
	 */
	private function purge_dir( string $dir, ?string $keep = null ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( trailingslashit( $dir ) . '*' );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			if ( is_file( $file ) && ( null === $keep || $this->version_of( $file ) !== $keep ) ) {
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
