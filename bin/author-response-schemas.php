<?php
/**
 * Authoring aid: draft ResponseSchema entries for GET routes that have none.
 *
 * The response-schema registry is authored from LIVE responses (see
 * includes/REST/ResponseSchema.php). With ~100 routes to cover, reading each
 * response by hand is slow and error-prone, so this script does the reading:
 *
 *   1. Loads a freshly generated spec (BN_OPENAPI_SPEC) and lists every GET
 *      operation whose 200 has no body schema.
 *   2. Calls each one as an administrator with sample path/query values
 *      (BN_SAMPLES, a JSON map of placeholder or "path:placeholder" => value).
 *   3. Infers a WP item schema from the response and the shape (item / array /
 *      paginated), reusing an existing registry resource when the field set
 *      matches one exactly.
 *   4. Prints PHP for the new resource methods and map() entries, and lists the
 *      routes it could not call so they can be authored by hand.
 *
 * The output is a DRAFT to review and paste, not something the build runs. The
 * drift gate (bin/check-openapi.php) then keeps the committed schemas honest.
 *
 *   BN_OPENAPI_SPEC=/tmp/spec.json BN_SAMPLES=/tmp/samples.json \
 *     wp eval "require '.../bin/author-response-schemas.php';"
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "author-response-schemas: run inside WordPress via wp eval.\n" );
	exit( 1 );
}

$bn_spec_path    = (string) getenv( 'BN_OPENAPI_SPEC' );
$bn_samples_path = (string) getenv( 'BN_SAMPLES' );
$bn_spec         = is_readable( $bn_spec_path ) ? json_decode( (string) file_get_contents( $bn_spec_path ), true ) : null;
$bn_samples      = is_readable( $bn_samples_path ) ? (array) json_decode( (string) file_get_contents( $bn_samples_path ), true ) : array();
if ( ! is_array( $bn_spec ) ) {
	fwrite( STDERR, "author-response-schemas: set BN_OPENAPI_SPEC to a generated spec.\n" );
	exit( 1 );
}

wp_set_current_user( 1 );

require __DIR__ . '/openapi-all-routes.php';

// Existing resources: top-level field set => resource name, per registry class.
$bn_registries = array_filter(
	array(
		'buddynext/v1'     => '\\BuddyNext\\REST\\ResponseSchema',
		'buddynext-pro/v1' => '\\BuddyNextPro\\REST\\ResponseSchema',
	),
	'class_exists'
);
// Existing resources are reused only on an exact field-set match of a real
// resource (5+ fields); small wrappers like {count} or {items,total} match far
// too easily and would name a route after an unrelated resource.
$bn_known = array();
$bn_taken = array();
foreach ( $bn_registries as $bn_class ) {
	foreach ( get_class_methods( $bn_class ) as $bn_method ) {
		$bn_taken[ $bn_method ] = true;
		if ( in_array( $bn_method, array( 'map', 'action_result' ), true ) ) {
			continue;
		}
		$bn_schema = $bn_class::$bn_method();
		$bn_keys   = array_keys( (array) ( $bn_schema['properties'] ?? array() ) );
		if ( count( $bn_keys ) < 5 ) {
			continue;
		}
		sort( $bn_keys );
		$bn_known[ 'keys:' . implode( ',', $bn_keys ) ] = $bn_method;
	}
}

/**
 * Infer a WP schema node from a decoded JSON value.
 *
 * @param mixed $value Value.
 * @param int   $depth Nesting depth.
 * @return array<string,mixed>
 */
$bn_infer = static function ( $value, int $depth = 0 ) use ( &$bn_infer ): array {
	if ( is_bool( $value ) ) {
		return array( 'type' => 'boolean' );
	}
	if ( is_int( $value ) ) {
		return array( 'type' => 'integer' );
	}
	if ( is_float( $value ) ) {
		return array( 'type' => 'number' );
	}
	if ( is_string( $value ) ) {
		if ( preg_match( '#^https?://#', $value ) ) {
			return array(
				'type'   => 'string',
				'format' => 'uri',
			);
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value ) ) {
			return array(
				'type'   => 'string',
				'format' => 'date-time',
			);
		}
		return array( 'type' => 'string' );
	}
	if ( null === $value ) {
		return array(); // Unknown from a null sample: any type.
	}
	if ( is_array( $value ) ) {
		if ( array() === $value ) {
			return array( 'type' => array( 'array', 'object' ) );
		}
		if ( array_is_list( $value ) ) {
			$node = array( 'type' => 'array' );
			if ( $depth < 3 ) {
				// Merge up to five elements so optional fields are not missed.
				$merged = null;
				foreach ( array_slice( $value, 0, 5 ) as $element ) {
					$child = $bn_infer( $element, $depth + 1 );
					if ( null === $merged ) {
						$merged = $child;
					} elseif ( isset( $merged['properties'], $child['properties'] ) ) {
						$merged['properties'] += $child['properties'];
					}
				}
				$node['items'] = (array) $merged;
			}
			return $node;
		}
		$node = array( 'type' => 'object' );
		if ( $depth < 3 ) {
			$node['properties'] = array();
			foreach ( $value as $key => $child ) {
				$node['properties'][ (string) $key ] = $bn_infer( $child, $depth + 1 );
			}
		}
		return $node;
	}
	return array();
};

/**
 * Export a schema node as WPCS-style PHP array source.
 *
 * @param mixed $value  Value.
 * @param int   $indent Tabs.
 * @return string
 */
$bn_export = static function ( $value, int $indent ) use ( &$bn_export ): string {
	if ( ! is_array( $value ) ) {
		return var_export( $value, true );
	}
	if ( array() === $value ) {
		return 'array()';
	}
	$tabs  = str_repeat( "\t", $indent );
	$list  = array_is_list( $value );
	$lines = array();
	foreach ( $value as $key => $child ) {
		$lines[] = $tabs . "\t" . ( $list ? '' : var_export( (string) $key, true ) . ' => ' ) . $bn_export( $child, $indent + 1 ) . ',';
	}
	return "array(\n" . implode( "\n", $lines ) . "\n" . $tabs . ')';
};

$bn_methods = array();
$bn_map     = array();
$bn_failed  = array();

foreach ( (array) ( $bn_spec['paths'] ?? array() ) as $bn_full => $bn_ops ) {
	$bn_op = $bn_ops['get'] ?? null;
	if ( ! is_array( $bn_op ) || isset( $bn_op['responses']['200']['content'] ) ) {
		continue;
	}
	$bn_ns  = str_starts_with( $bn_full, '/buddynext-pro/v1' ) ? 'buddynext-pro/v1' : 'buddynext/v1';
	$bn_rel = substr( $bn_full, strlen( '/' . $bn_ns ) );

	// Fill {placeholders}: "path:name" beats "name".
	$bn_concrete = preg_replace_callback(
		'/\{([a-z_]+)\}/',
		static function ( array $m ) use ( $bn_samples, $bn_rel ): string {
			return (string) ( $bn_samples[ $bn_rel . ':' . $m[1] ] ?? $bn_samples[ $m[1] ] ?? '0' );
		},
		$bn_rel
	);

	$bn_req = new WP_REST_Request( 'GET', '/' . $bn_ns . $bn_concrete );
	foreach ( (array) ( $bn_op['parameters'] ?? array() ) as $bn_param ) {
		if ( 'query' === ( $bn_param['in'] ?? '' ) && ! empty( $bn_param['required'] ) ) {
			$bn_name = (string) $bn_param['name'];
			$bn_req->set_query_params( array_merge( $bn_req->get_query_params(), array( $bn_name => $bn_samples[ $bn_rel . ':' . $bn_name ] ?? $bn_samples[ $bn_name ] ?? 'a' ) ) );
		}
	}

	// "path:@user" runs a route as another member (e.g. one who has appeals).
	wp_set_current_user( (int) ( $bn_samples[ $bn_rel . ':@user' ] ?? 1 ) );
	ob_start();
	$bn_resp = rest_do_request( $bn_req );
	ob_end_clean();
	wp_set_current_user( 1 );

	if ( $bn_resp->is_error() || $bn_resp->get_status() >= 300 ) {
		$bn_failed[] = sprintf( '%s %s -> %d %s', $bn_ns, $bn_rel, $bn_resp->get_status(), $bn_resp->is_error() ? $bn_resp->as_error()->get_error_code() : '' );
		continue;
	}

	$bn_data  = rest_get_server()->response_to_data( $bn_resp, false );
	$bn_shape = 'item';
	$bn_item  = $bn_data;
	if ( is_array( $bn_data ) && array_is_list( $bn_data ) ) {
		$bn_shape = 'array';
		$bn_item  = $bn_data[0] ?? null;
	} elseif ( is_array( $bn_data ) && isset( $bn_data['items'] ) && is_array( $bn_data['items'] ) && array_is_list( $bn_data['items'] )
		&& array() === array_diff( array_keys( $bn_data ), array( 'items', 'next_cursor', 'total' ) ) ) {
		$bn_shape = 'paginated';
		$bn_item  = $bn_data['items'][0] ?? null;
	}

	if ( ! is_array( $bn_item ) || array_is_list( $bn_item ) ) {
		$bn_failed[] = sprintf( '%s %s -> %s with no object sample to read', $bn_ns, $bn_rel, $bn_shape );
		continue;
	}

	$bn_keys = array_keys( $bn_item );
	sort( $bn_keys );
	$bn_schema    = $bn_infer( $bn_item );
	$bn_key_sig   = 'keys:' . implode( ',', $bn_keys );
	$bn_exact_sig = 'schema:' . md5( (string) wp_json_encode( $bn_schema ) );

	if ( count( $bn_keys ) >= 5 && isset( $bn_known[ $bn_key_sig ] ) ) {
		$bn_resource = $bn_known[ $bn_key_sig ];
	} elseif ( isset( $bn_known[ $bn_exact_sig ] ) ) {
		$bn_resource = $bn_known[ $bn_exact_sig ];
	} else {
		$bn_resource = trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( preg_replace( '/\{[^}]+\}/', '', $bn_rel ) ) ), '_' );
		if ( 'buddynext-pro/v1' === $bn_ns ) {
			$bn_resource = 'pro_' . $bn_resource;
		}
		while ( isset( $bn_taken[ $bn_resource ] ) ) {
			$bn_resource .= '_response';
		}
		$bn_taken[ $bn_resource ]             = true;
		$bn_title                             = str_replace( '_', '-', $bn_resource );
		$bn_methods[ $bn_ns ][ $bn_resource ] = sprintf(
			"\t/**\n\t * Response of GET %s (authored from the live response).\n\t *\n\t * @return array<string,mixed>\n\t */\n\tpublic static function %s(): array {\n\t\treturn %s;\n\t}\n",
			$bn_rel,
			$bn_resource,
			$bn_export(
				array(
					'$schema'    => 'http://json-schema.org/draft-04/schema#',
					'title'      => $bn_title,
					'type'       => 'object',
					'properties' => $bn_schema['properties'] ?? array(),
				),
				2
			)
		);
		$bn_known[ $bn_exact_sig ]            = $bn_resource;
	}

	$bn_map[ $bn_ns ][] = sprintf(
		"\t\t\tarray( 'method' => 'GET', 'path' => '%s', 'resource' => '%s', 'shape' => '%s' ),",
		$bn_rel,
		$bn_resource,
		$bn_shape
	);
}

foreach ( $bn_map as $bn_ns => $bn_lines ) {
	fwrite( STDOUT, "\n// ===== {$bn_ns} map() entries =====\n" . implode( "\n", $bn_lines ) . "\n" );
	fwrite( STDOUT, "\n// ===== {$bn_ns} resource methods =====\n" . implode( "\n", (array) ( $bn_methods[ $bn_ns ] ?? array() ) ) . "\n" );
}
if ( $bn_failed ) {
	fwrite( STDERR, "\nCould not introspect (author by hand or add samples):\n  " . implode( "\n  ", $bn_failed ) . "\n" );
}
