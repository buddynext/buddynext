<?php
/**
 * REST reachability audit for the `buddynext/v1` namespace.
 *
 * Walks the LIVE route registry and asserts that every BuddyNext route is
 * well-formed: it declares at least one HTTP method and a permission callback.
 * A route registered without a permission callback defaults to public in WP -
 * that is almost always a mistake and this audit fails on it so it is caught
 * before release rather than by a security report.
 *
 * It also reconciles the registry against docs/api/openapi.json (when present):
 * every generated path must map to a live route, so a stale generated file is
 * flagged.
 *
 * Run (via `require`, not `eval-file`, because of this file's strict_types line):
 *     wp eval "require '/abs/path/wp-content/plugins/buddynext/tests/audit/rest-reachability.php';"
 *
 * Exit 0 = clean; 1 = one or more problems listed.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI audit tool: reads the local generated openapi.json and writes STDERR/STDOUT. WP_Filesystem / wp_remote_get do not apply to a wp-cli audit script running on the local disk.
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'rest_get_server' ) ) {
	fwrite( STDERR, "rest-reachability: must run inside WordPress with the REST API available.\n" );
	exit( 1 );
}

$bn_namespace = 'buddynext/v1';
$bn_prefix    = '/' . $bn_namespace;
$bn_server    = rest_get_server();
$bn_routes    = $bn_server->get_routes();

$bn_http_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
$bn_problems     = array();
$bn_route_seen   = array();
$bn_op_total     = 0;

foreach ( $bn_routes as $bn_route => $bn_endpoints ) {
	if ( $bn_route !== $bn_prefix && ! str_starts_with( $bn_route, $bn_prefix . '/' ) ) {
		continue;
	}
	if ( $bn_route === $bn_prefix ) {
		continue; // Namespace index route.
	}

	$bn_route_seen[ $bn_route ] = true;
	$bn_has_http                = false;

	foreach ( (array) $bn_endpoints as $bn_i => $bn_ep ) {
		if ( ! is_array( $bn_ep ) || empty( $bn_ep['methods'] ) ) {
			continue;
		}
		$bn_methods = is_array( $bn_ep['methods'] ) ? array_keys( array_filter( $bn_ep['methods'] ) ) : array();
		$bn_real    = array_intersect( array_map( 'strtoupper', $bn_methods ), $bn_http_methods );
		if ( empty( $bn_real ) ) {
			continue;
		}
		$bn_has_http  = true;
		$bn_op_total += count( $bn_real );

		// A permission callback must be present. WP treats a missing/empty
		// callback as public, which is a foot-gun on a write route.
		if ( ! array_key_exists( 'permission_callback', $bn_ep ) || empty( $bn_ep['permission_callback'] ) ) {
			$bn_problems[] = sprintf(
				'MISSING PERMISSION  %s  [%s] (endpoint #%s) - no permission_callback; route is public by default.',
				$bn_route,
				implode( ',', $bn_real ),
				(string) $bn_i
			);
		}

		// The callback must be callable.
		if ( isset( $bn_ep['callback'] ) && ! is_callable( $bn_ep['callback'] ) ) {
			$bn_problems[] = sprintf( 'UNCALLABLE HANDLER  %s  [%s]', $bn_route, implode( ',', $bn_real ) );
		}
	}

	if ( ! $bn_has_http ) {
		$bn_problems[] = sprintf( 'NO HTTP METHOD      %s - route declares no GET/POST/PUT/PATCH/DELETE.', $bn_route );
	}
}

if ( empty( $bn_route_seen ) ) {
	$bn_problems[] = "NO ROUTES - the {$bn_namespace} namespace registered zero routes. Is BuddyNext active?";
}

// Reconcile the generated OpenAPI file (if present) against the live registry.
$bn_openapi_path = dirname( __DIR__, 2 ) . '/docs/api/openapi.json';
if ( is_readable( $bn_openapi_path ) ) {
	$bn_doc = json_decode( (string) file_get_contents( $bn_openapi_path ), true );
	if ( is_array( $bn_doc ) && isset( $bn_doc['paths'] ) && is_array( $bn_doc['paths'] ) ) {
		// Build the set of live template paths (namespace-relative, {id} form).
		$bn_live = array();
		foreach ( array_keys( $bn_route_seen ) as $bn_route ) {
			$bn_rel                      = substr( $bn_route, strlen( $bn_prefix ) );
			$bn_rel                      = preg_replace( '/\(\?P<(\w+)>[^)]*\)/', '{$1}', $bn_rel );
			$bn_live[ (string) $bn_rel ] = true;
		}
		foreach ( array_keys( $bn_doc['paths'] ) as $bn_doc_path ) {
			if ( ! isset( $bn_live[ (string) $bn_doc_path ] ) ) {
				$bn_problems[] = sprintf( 'STALE OPENAPI PATH  %s - in openapi.json but not in the live registry. Regenerate with bin/sync-api-docs.sh.', $bn_doc_path );
			}
		}
	}
}

if ( empty( $bn_problems ) ) {
	fwrite(
		STDOUT,
		sprintf(
			"rest-reachability: OK - %d routes, %d operations under %s, every route gated and reachable.\n",
			count( $bn_route_seen ),
			$bn_op_total,
			$bn_namespace
		)
	);
	exit( 0 );
}

fwrite( STDERR, 'rest-reachability: ' . count( $bn_problems ) . " problem(s):\n" );
foreach ( $bn_problems as $bn_p ) {
	fwrite( STDERR, '  ' . $bn_p . "\n" );
}
exit( 1 );
