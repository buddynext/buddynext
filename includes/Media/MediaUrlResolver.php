<?php
/**
 * Resolve a WPMediaVerse media id into displayable, signed URLs.
 *
 * WPMediaVerse stores files behind a deny-all gate; the only valid URL is an
 * HMAC-signed `/serve` URL minted from the media id at render time. BuddyNext
 * must therefore resolve every media id through the engine read API — never a
 * raw uploads path or a persisted upload-time URL (those 403 once their short
 * TTL expires). For feed/long-lived markup we use the BROADCAST signed URLs
 * (year TTL, re-minted each render, privacy-checked at sign time).
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

/**
 * Media id → render descriptor (type + signed URLs).
 */
class MediaUrlResolver {

	/**
	 * Build a render descriptor for a media id, or null if unavailable.
	 *
	 * @param int $media_id Media id (WPMediaVerse mvs_media_index id).
	 * @return array<string,mixed>|null {
	 *     @type int    $id        Media id.
	 *     @type string $type      image|video|audio|file.
	 *     @type string $url       Signed full-file URL (broadcast TTL).
	 *     @type string $thumb     Signed thumbnail/poster URL.
	 *     @type string $title     Media title.
	 *     @type int    $width     Pixel width (0 if unknown).
	 *     @type int    $height    Pixel height (0 if unknown).
	 *     @type string $duration  Duration (audio/video; '' if n/a).
	 *     @type string $permalink Media single-page permalink.
	 * }
	 */
	public static function descriptor( int $media_id ) {
		if ( $media_id <= 0 ) {
			return null;
		}
		$repo = MediaClient::repo();
		if ( ! $repo ) {
			return null;
		}

		$type = (string) $repo->get( $media_id, 'media_type' );
		if ( '' === $type ) {
			// Unknown / deleted / not visible to this viewer.
			return null;
		}

		$thumb = method_exists( $repo, 'get_broadcast_thumbnail_url' )
			? (string) $repo->get_broadcast_thumbnail_url( $media_id, 'thumb_large' )
			: '';
		$url = method_exists( $repo, 'get_broadcast_url' )
			? (string) $repo->get_broadcast_url( $media_id )
			: (string) $repo->get( $media_id, 'file_url' );

		return array(
			'id'        => $media_id,
			'type'      => $type,
			'url'       => $url,
			// Raw poster URL — empty when the engine has no generated thumbnail
			// (e.g. a video with no extracted poster frame). The renderer decides
			// per type whether to fall back to the full file (images) or to a
			// poster-less tile (video). Never force a non-image file into an <img>.
			'thumb'     => $thumb,
			'title'     => (string) $repo->get( $media_id, 'title' ),
			'width'     => (int) $repo->get( $media_id, 'width' ),
			'height'    => (int) $repo->get( $media_id, 'height' ),
			'duration'  => (string) $repo->get( $media_id, 'duration' ),
			'permalink' => method_exists( $repo, 'get_permalink' ) ? (string) $repo->get_permalink( $media_id ) : '',
		);
	}

	/**
	 * Resolve a list of media ids to descriptors (skipping unavailable ones).
	 *
	 * @param int[] $media_ids Media ids in order.
	 * @return array<int,array<string,mixed>> Ordered descriptors.
	 */
	public static function descriptors( array $media_ids ): array {
		$out = array();
		foreach ( $media_ids as $mid ) {
			$d = self::descriptor( (int) $mid );
			if ( null !== $d ) {
				$out[] = $d;
			}
		}
		return $out;
	}
}
