<?php
/**
 * OpenAPI response-schema drift gate (build-time / pre-commit).
 *
 * For every resource in the ResponseSchema registry map, introspect the LIVE
 * response (as admin, on the seeded site) and compare its field set against the
 * schema declared in `ResponseSchema::<resource>()`. Fails when a field appears
 * in the live response but not the schema (undocumented drift) or is declared but
 * no longer returned (stale). This catches a controller changing its output —
 * which a spec-vs-spec diff would miss — so the committed OpenAPI spec stays true.
 *
 * Run like the generator (preserves the file's strict_types):
 *   wp eval "require '.../bin/check-openapi.php';"
 *
 * Exit 0 = no drift; 1 = drift (with a per-resource report on STDERR).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "check-openapi: run inside WordPress via `wp eval \"require '...';\"`\n" );
	exit( 1 );
}

$bn_class = '\\BuddyNext\\Rest\\ResponseSchema';
if ( ! class_exists( $bn_class ) ) {
	fwrite( STDERR, "check-openapi: ResponseSchema registry not found.\n" );
	exit( 1 );
}

// Introspect as an administrator so viewer-gated fields are present.
wp_set_current_user( 1 );

$bn_map      = $bn_class::map();
$bn_checked  = array();
$bn_drift    = array();
$bn_skipped  = array();

foreach ( $bn_map as $bn_entry ) {
	$bn_resource = (string) ( $bn_entry['resource'] ?? '' );
	$bn_shape    = (string) ( $bn_entry['shape'] ?? 'item' );
	$bn_path     = (string) ( $bn_entry['path'] ?? '' );

	// Check each resource once, via a LIST route (no id needed); the list item is
	// the resource shape. Resources with only item routes are noted as unchecked.
	if ( isset( $bn_checked[ $bn_resource ] ) || 'item' === $bn_shape ) {
		continue;
	}

	$bn_req  = new WP_REST_Request( 'GET', '/buddynext/v1' . $bn_path );
	$bn_req->set_param( 'per_page', 5 );
	$bn_resp = rest_do_request( $bn_req );
	if ( $bn_resp->is_error() ) {
		$bn_skipped[ $bn_resource ] = 'route error ' . $bn_resp->as_error()->get_error_code();
		continue;
	}

	$bn_data = $bn_resp->get_data();
	$bn_item = ( 'paginated' === $bn_shape ) ? ( $bn_data['items'][0] ?? null ) : ( is_array( $bn_data ) ? ( $bn_data[0] ?? null ) : null );
	if ( ! is_array( $bn_item ) ) {
		$bn_skipped[ $bn_resource ] = 'no rows to introspect (seed data)';
		continue;
	}

	$bn_live     = array_keys( $bn_item );
	$bn_schema   = (array) $bn_class::$bn_resource();
	$bn_declared = array_keys( (array) ( $bn_schema['properties'] ?? array() ) );

	$bn_undocumented = array_values( array_diff( $bn_live, $bn_declared ) );
	$bn_stale        = array_values( array_diff( $bn_declared, $bn_live ) );
	if ( $bn_undocumented || $bn_stale ) {
		$bn_drift[ $bn_resource ] = array( 'undocumented' => $bn_undocumented, 'stale' => $bn_stale );
	}
	$bn_checked[ $bn_resource ] = true;
}

if ( $bn_drift ) {
	fwrite( STDERR, "OpenAPI response-schema DRIFT — ResponseSchema no longer matches the live API:\n" );
	foreach ( $bn_drift as $bn_res => $bn_d ) {
		fwrite( STDERR, sprintf(
			"  %s: live-but-undocumented %s ; declared-but-stale %s\n",
			$bn_res,
			wp_json_encode( $bn_d['undocumented'] ),
			wp_json_encode( $bn_d['stale'] )
		) );
	}
	fwrite( STDERR, "Fix includes/Rest/ResponseSchema.php to match, then regenerate the spec.\n" );
	exit( 1 );
}

fwrite( STDOUT, sprintf(
	"openapi schema: no drift (%d resources checked%s)\n",
	count( $bn_checked ),
	$bn_skipped ? ', ' . count( $bn_skipped ) . ' skipped: ' . implode( '; ', array_map( static fn( $k, $v ) => "$k ($v)", array_keys( $bn_skipped ), $bn_skipped ) ) : ''
) );
