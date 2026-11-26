<?php
/**
 * The framing of a cover picture: where it is panned to and how far it is zoomed.
 *
 * One place for the rule, used by the member cover and the Space cover so the two can never
 * drift apart. The picture is stored uncropped; this is only the pan (x, y as a percent of the
 * frame, 0 to 100) and the zoom (1 to 3) the owner chose in the reposition window.
 *
 * @package BuddyNext
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Validate and read a cover's focal point.
 */
final class CoverFocal {

	/**
	 * User meta and Space meta key the focal point is stored under.
	 *
	 * @var string
	 */
	public const META_KEY = 'buddynext_cover_focal';

	/**
	 * Clean a raw pan and zoom, or say there is nothing usable.
	 *
	 * Pan outside 0 to 100, or not a number, is refused (null) rather than clamped, so a broken
	 * request cannot move a cover. Zoom is clamped to 1 to 3; a missing zoom is 1.
	 *
	 * @param mixed $x    Raw pan x (percent).
	 * @param mixed $y    Raw pan y (percent).
	 * @param mixed $zoom Raw zoom (1 = no zoom).
	 * @return array{x:float,y:float,zoom:float}|null The clean focal point, or null when x or y is unusable.
	 */
	public static function clean( $x, $y, $zoom = 1.0 ): ?array {
		if ( ! is_numeric( $x ) || ! is_numeric( $y ) ) {
			return null;
		}
		$x = (float) $x;
		$y = (float) $y;
		if ( $x < 0.0 || $x > 100.0 || $y < 0.0 || $y > 100.0 ) {
			return null;
		}
		$zoom = is_numeric( $zoom ) ? (float) $zoom : 1.0;

		return array(
			'x'    => round( $x, 2 ),
			'y'    => round( $y, 2 ),
			'zoom' => round( max( 1.0, min( 3.0, $zoom ) ), 3 ),
		);
	}

	/**
	 * Read the focal point out of a request's parameters (focal_x, focal_y, focal_zoom).
	 *
	 * @param array<string,mixed> $params Request parameters (REST or form).
	 * @return array{x:float,y:float,zoom:float}|null
	 */
	public static function from_params( array $params ): ?array {
		return self::clean( $params['focal_x'] ?? null, $params['focal_y'] ?? null, $params['focal_zoom'] ?? 1.0 );
	}
}
