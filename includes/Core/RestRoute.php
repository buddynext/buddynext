<?php
/**
 * Canonical form of a REST route, for gates that match on route text.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * One place to normalise a REST route so every gate compares like WordPress routes.
 */
final class RestRoute {

	/**
	 * Normalise a REST route so a gate cannot be bypassed by its case (or a
	 * trailing slash).
	 *
	 * WordPress dispatches REST routes case-INSENSITIVELY: WP_REST_Server matches
	 * the request path against each registered route with the `i` flag, so
	 * `/BuddyNext/v1/Spaces` reaches the very controller `/buddynext/v1/spaces`
	 * does. A gate that runs a case-sensitive check on the raw route text therefore
	 * reads a mixed-case path as "not my namespace" and waves it straight through to
	 * a controller whose permission_callback is __return_true — the private-community
	 * bypass (Zoho #41763). Every REST gate must match on this normalised form:
	 * lower-cased, trimmed, and without a trailing slash. Feed it the request (or a
	 * route/prefix string), and normalise BOTH the route and anything it is compared
	 * against.
	 *
	 * @param \WP_REST_Request|string $route Request, or a route/prefix string.
	 * @return string Lower-cased, untrailingslashit route (empty string stays empty).
	 */
	public static function normalize( $route ): string {
		if ( $route instanceof \WP_REST_Request ) {
			$route = (string) $route->get_route();
		}
		return untrailingslashit( strtolower( trim( (string) $route ) ) );
	}
}
