<?php
/**
 * OpenAPI gate (build-time / release): the committed spec is complete and true.
 *
 * Three checks, all against the site it runs on:
 *
 *   1. Coverage  - generate a fresh spec; FAIL if any operation's 200 response
 *                  has no body schema. A registry that only validates the schemas
 *                  that exist cannot notice the ones that are missing, which is how
 *                  364 of 400 operations once shipped untyped while this gate
 *                  passed.
 *   2. Fresh     - FAIL if the committed spec differs from that fresh generate: a
 *                  live route missing from the spec, a removed route still in it,
 *                  or any other stale output.
 *   3. Drift     - for every list resource in the ResponseSchema registries, call
 *                  the live route and FAIL if a field is returned but undocumented,
 *                  or documented but no longer returned.
 *
 * The spec documents every route, including partner-plugin routes, so it must be
 * built and checked on a site with those partners active. On a site without them
 * the comparison is meaningless, so the gate stops with exit 2 and says so.
 *
 *   wp eval "require '.../bin/check-openapi.php';"
 *
 * Exit 0 = pass; 1 = fail (report on STDERR); 2 = wrong environment.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "check-openapi: run inside WordPress via `wp eval \"require '...';\"`\n" );
	exit( 1 );
}

$bn_plugin_dir = dirname( __DIR__ );

// ── Environment: the partner plugins whose routes the spec documents ─────────
$bn_partners = array( 'wpmediaverse/wpmediaverse.php', 'jetonomy/jetonomy.php', 'eventonomy/eventonomy.php', 'learnomy/learnomy.php', 'buddynext-pro/buddynext-pro.php' );
$bn_active   = (array) get_option( 'active_plugins', array() );
$bn_missing  = array_values( array_diff( $bn_partners, $bn_active ) );
if ( $bn_missing ) {
	fwrite( STDERR, 'check-openapi: SKIPPED - activate ' . implode( ', ', $bn_missing ) . " (the spec documents their routes).\n" );
	exit( 2 );
}

$bn_failures = array();

// ── 1 + 2. Coverage and freshness, from a fresh generate ─────────────────────
$bn_committed_path = $bn_plugin_dir . '/docs/api/openapi.combined.json';
$bn_fresh_path     = wp_tempnam( 'openapi-combined' );
putenv( 'BN_OPENAPI_CONFIG=' . $bn_plugin_dir . '/docs/api/openapi.combined.config.json' );
putenv( 'BN_OPENAPI_OUT=' . $bn_fresh_path );
ob_start();
require $bn_plugin_dir . '/bin/gen-openapi.php';
ob_end_clean();
putenv( 'BN_OPENAPI_CONFIG' );
putenv( 'BN_OPENAPI_OUT' );

$bn_fresh     = json_decode( (string) file_get_contents( $bn_fresh_path ), true );
$bn_committed = is_readable( $bn_committed_path ) ? json_decode( (string) file_get_contents( $bn_committed_path ), true ) : null;
wp_delete_file( $bn_fresh_path );

$bn_untyped = array();
foreach ( (array) ( $bn_fresh['paths'] ?? array() ) as $bn_path => $bn_ops ) {
	foreach ( (array) $bn_ops as $bn_method => $bn_op ) {
		if ( in_array( $bn_method, array( 'get', 'post', 'put', 'patch', 'delete' ), true ) && empty( $bn_op['responses']['200']['content'] ) ) {
			$bn_untyped[] = strtoupper( $bn_method ) . ' ' . $bn_path;
		}
	}
}
if ( $bn_untyped ) {
	$bn_failures[] = count( $bn_untyped ) . " operation(s) have no 200 response schema - add a map() entry (and a resource method) to ResponseSchema:\n    " . implode( "\n    ", $bn_untyped );
}

if ( ! is_array( $bn_committed ) ) {
	$bn_failures[] = 'docs/api/openapi.combined.json is missing or not valid JSON - run bin/sync-api-docs.sh.';
} elseif ( $bn_committed !== $bn_fresh ) {
	$bn_added   = array_diff( array_keys( (array) $bn_fresh['paths'] ), array_keys( (array) $bn_committed['paths'] ) );
	$bn_removed = array_diff( array_keys( (array) $bn_committed['paths'] ), array_keys( (array) $bn_fresh['paths'] ) );
	$bn_changed = array();
	foreach ( array_intersect( array_keys( (array) $bn_fresh['paths'] ), array_keys( (array) $bn_committed['paths'] ) ) as $bn_path ) {
		if ( $bn_fresh['paths'][ $bn_path ] !== $bn_committed['paths'][ $bn_path ] ) {
			$bn_changed[] = $bn_path;
		}
	}
	$bn_failures[] = "the committed spec is stale - run bin/sync-api-docs.sh and commit docs/api/.\n"
		. '    routes not in the committed spec: ' . ( $bn_added ? implode( ', ', $bn_added ) : 'none' ) . "\n"
		. '    routes no longer registered: ' . ( $bn_removed ? implode( ', ', $bn_removed ) : 'none' ) . "\n"
		. '    routes whose description changed: ' . ( $bn_changed ? implode( ', ', array_slice( $bn_changed, 0, 20 ) ) : 'none' )
		. ( $bn_committed['components'] !== $bn_fresh['components'] ? "\n    components changed" : '' );
}

// ── 3. Field drift of list resources against the live responses ──────────────
$bn_registries = array_filter(
	array(
		'/buddynext/v1'     => '\\BuddyNext\\REST\\ResponseSchema',
		'/buddynext-pro/v1' => '\\BuddyNextPro\\REST\\ResponseSchema',
	),
	'class_exists'
);

// Introspect as an administrator so viewer-gated fields are present.
wp_set_current_user( 1 );

$bn_checked = array();
$bn_drift   = array();
$bn_skipped = array();

foreach ( $bn_registries as $bn_ns => $bn_class ) {
	foreach ( (array) $bn_class::map() as $bn_entry ) {
		$bn_resource = (string) ( $bn_entry['resource'] ?? '' );
		$bn_shape    = (string) ( $bn_entry['shape'] ?? 'item' );
		$bn_path     = (string) ( $bn_entry['path'] ?? '' );
		$bn_key      = $bn_ns . ':' . $bn_resource;

		// Each resource once, via a list route with no path parameters (item routes
		// need an id); the list item is the resource shape.
		if ( '' === $bn_resource || isset( $bn_checked[ $bn_key ] ) || ! in_array( $bn_shape, array( 'array', 'paginated' ), true ) || false !== strpos( $bn_path, '{' ) ) {
			continue;
		}

		$bn_req = new WP_REST_Request( 'GET', $bn_ns . $bn_path );
		$bn_req->set_param( 'per_page', 5 );
		ob_start();
		$bn_resp = rest_do_request( $bn_req );
		ob_end_clean();
		if ( $bn_resp->is_error() ) {
			$bn_skipped[ $bn_resource ] = 'route error ' . $bn_resp->as_error()->get_error_code();
			continue;
		}

		$bn_data = rest_get_server()->response_to_data( $bn_resp, false );
		$bn_item = ( 'paginated' === $bn_shape ) ? ( $bn_data['items'][0] ?? null ) : ( is_array( $bn_data ) ? ( $bn_data[0] ?? null ) : null );
		if ( ! is_array( $bn_item ) ) {
			$bn_skipped[ $bn_resource ] = 'no rows to introspect';
			continue;
		}

		$bn_live     = array_keys( $bn_item );
		$bn_schema   = (array) $bn_class::$bn_resource();
		$bn_declared = array_keys( (array) ( $bn_schema['properties'] ?? array() ) );

		// `<key>_gmt` siblings of Core\Dates timestamp keys are documented by the
		// generator itself wherever `<key>` is declared.
		$bn_ts_keys      = \BuddyNext\Core\Dates::timestamp_keys();
		$bn_undocumented = array_values(
			array_filter(
				array_diff( $bn_live, $bn_declared ),
				static fn( string $field ): bool => ! ( str_ends_with( $field, '_gmt' ) && in_array( substr( $field, 0, -4 ), $bn_ts_keys, true ) && in_array( substr( $field, 0, -4 ), $bn_declared, true ) )
			)
		);
		// A `<key>_gmt` sibling is only added when `<key>` holds a date (Core\Dates),
		// so it is legitimately absent from a row whose `<key>` is null.
		$bn_stale = array_values(
			array_filter(
				array_diff( $bn_declared, $bn_live ),
				static fn( string $field ): bool => ! ( str_ends_with( $field, '_gmt' ) && array_key_exists( substr( $field, 0, -4 ), $bn_item ) )
			)
		);
		if ( $bn_undocumented || $bn_stale ) {
			$bn_drift[] = sprintf( '%s: returned but undocumented %s; documented but not returned %s', $bn_resource, wp_json_encode( $bn_undocumented ), wp_json_encode( $bn_stale ) );
		}
		$bn_checked[ $bn_key ] = true;
	}
}
if ( $bn_drift ) {
	$bn_failures[] = "response fields drifted from ResponseSchema:\n    " . implode( "\n    ", $bn_drift );
}

if ( $bn_failures ) {
	fwrite( STDERR, "OpenAPI gate FAILED:\n  - " . implode( "\n  - ", $bn_failures ) . "\n" );
	exit( 1 );
}

fwrite(
	STDOUT,
	sprintf(
		"openapi: every operation typed, committed spec fresh, no field drift (%d list resources checked%s)\n",
		count( $bn_checked ),
		$bn_skipped ? '; skipped: ' . implode( '; ', array_map( static fn( $k, $v ) => "$k ($v)", array_keys( $bn_skipped ), $bn_skipped ) ) : ''
	)
);
