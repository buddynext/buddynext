<?php
/**
 * Attach / read media on a BuddyNext object via the engine's 1.6.0 seam.
 *
 * Uses WPMediaVerse's provider-neutral object↔media linkage (`object_media`
 * service, engine 1.6.0) so a BuddyNext post (`bn_post`) or space (`bn_space`)
 * can carry an ordered set of media without the BuddyPress save path. The
 * `object_type` discriminator keeps BN ids isolated from `bp_activity`/`bp_group`
 * ids in the shared link table (dual-support + migration-safe).
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

/**
 * BuddyNext object ↔ media linkage.
 */
class ObjectMediaLink {

	public const POST  = 'bn_post';
	public const SPACE = 'bn_space';

	/**
	 * Replace the media linked to a BN object with the given ordered set.
	 *
	 * @param string $object_type self::POST | self::SPACE.
	 * @param int    $object_id   Object id (post id / space id).
	 * @param int[]  $media_ids   Ordered media ids (empty detaches all).
	 * @return bool True if the engine seam handled it.
	 */
	public static function set( string $object_type, int $object_id, array $media_ids ): bool {
		$svc = MediaClient::object_link();
		if ( ! $svc || ! method_exists( $svc, 'set_object_media' ) ) {
			return false;
		}
		$svc->set_object_media( $object_type, $object_id, array_map( 'intval', $media_ids ) );
		return true;
	}

	/**
	 * Get the ordered media ids linked to a BN object.
	 *
	 * @param string $object_type self::POST | self::SPACE.
	 * @param int    $object_id   Object id.
	 * @return int[] Ordered media ids (empty if none / engine unavailable).
	 */
	public static function get( string $object_type, int $object_id ): array {
		$svc = MediaClient::object_link();
		if ( ! $svc || ! method_exists( $svc, 'get_object_media' ) ) {
			return array();
		}
		return array_map( 'intval', (array) $svc->get_object_media( $object_type, $object_id ) );
	}
}
