<?php
/**
 * OpenAPI generator for the BuddyNext REST API.
 *
 * Introspects the LIVE WordPress route registry (so it can never drift from the
 * code) and emits an OpenAPI 3.1 document for the namespace(s) named in the config.
 * The default config (docs/api/openapi.config.json) covers `buddynext/v1` (Free)
 * only; a config listing `namespaces` can include Pro's `buddynext-pro/v1` too
 * (see docs/api/openapi.combined.config.json, used for the buddynext.com reference).
 *
 * Run it against a WordPress install with BuddyNext active. Load it with
 * `wp eval "require '…';"` rather than `wp eval-file`: eval-file wraps the file
 * in eval(), which rejects this file's `declare(strict_types=1)` first statement.
 *
 *     wp eval "require '/abs/path/wp-content/plugins/buddynext/bin/gen-openapi.php';"
 *
 * or through the wrapper, which uses the right invocation and validates the result:
 *
 *     bin/sync-api-docs.sh
 *
 * Config: openapi.config.json by default; override with the BN_OPENAPI_CONFIG env
 * var. Keys: namespace | namespaces[], tagPrefixes, tagRules, info, servers.
 * Output: docs/api/openapi.json by default; override with BN_OPENAPI_OUT.
 * Overwritten each run - do not hand-edit.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI build tool: reads local route/config files and writes the generated openapi.json. WP_Filesystem / wp_remote_get do not apply to a wp-cli generator that runs on the local disk.
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "gen-openapi: must run inside WordPress (use `wp eval-file`).\n" );
	exit( 1 );
}

if ( ! function_exists( 'rest_get_server' ) ) {
	fwrite( STDERR, "gen-openapi: REST API is not available.\n" );
	exit( 1 );
}

$bn_plugin_dir  = dirname( __DIR__ );
$bn_env_config  = getenv( 'BN_OPENAPI_CONFIG' );
$bn_config_path = ( is_string( $bn_env_config ) && '' !== $bn_env_config ) ? $bn_env_config : $bn_plugin_dir . '/docs/api/openapi.config.json';

if ( ! is_readable( $bn_config_path ) ) {
	fwrite( STDERR, "gen-openapi: config not found at {$bn_config_path}\n" );
	exit( 1 );
}

$bn_config = json_decode( (string) file_get_contents( $bn_config_path ), true );
if ( ! is_array( $bn_config ) ) {
	fwrite( STDERR, "gen-openapi: config is not valid JSON.\n" );
	exit( 1 );
}

// One or more namespaces. `namespaces` (array) takes precedence; `namespace`
// (string) is the single-namespace fallback, kept for backward compatibility.
$bn_namespaces = ( isset( $bn_config['namespaces'] ) && is_array( $bn_config['namespaces'] ) )
	? array_values( array_map( 'strval', $bn_config['namespaces'] ) )
	: array( (string) ( $bn_config['namespace'] ?? 'buddynext/v1' ) );
// Optional per-namespace tag prefix, e.g. { "buddynext-pro/v1": "Pro: " } so Pro
// operations group under their own headings in the reference.
$bn_tag_prefixes = (array) ( $bn_config['tagPrefixes'] ?? array() );
$bn_tag_rules    = (array) ( $bn_config['tagRules'] ?? array() );
$bn_fallb_tag    = (string) ( $bn_config['fallbackTag'] ?? 'Misc' );

/**
 * Map a route path to a tag from the configured prefix rules (longest match wins).
 *
 * @param string $path      Route path (namespace-relative, leading slash).
 * @param array  $rules     Tag rules from config.
 * @param string $fallback  Fallback tag.
 * @return string
 */
$bn_tag_for = static function ( string $path, array $rules, string $fallback ): string {
	$best_len = -1;
	$best_tag = $fallback;
	foreach ( $rules as $rule ) {
		$rp = (string) ( $rule['prefix'] ?? '' );
		if ( '' === $rp ) {
			continue;
		}
		if ( $path === $rp || str_starts_with( $path, $rp . '/' ) || str_starts_with( $path, $rp ) ) {
			if ( strlen( $rp ) > $best_len ) {
				$best_len = strlen( $rp );
				$best_tag = (string) ( $rule['tag'] ?? $fallback );
			}
		}
	}
	return $best_tag;
};

/**
 * Convert a WordPress route regex into an OpenAPI path template and collect params.
 *
 * `/spaces/(?P<id>[\d]+)/members` -> `/spaces/{id}/members` with an integer `id` param.
 *
 * @param string $route Namespace-relative route regex.
 * @return array{path:string, params:array<int,array<string,mixed>>}
 */
$bn_templatize = static function ( string $route ): array {
	$params = array();
	$path   = preg_replace_callback(
		'/\(\?P<(\w+)>([^)]*)\)/',
		static function ( array $m ) use ( &$params ): string {
			$name    = $m[1];
			$pattern = $m[2];
			// An integer-only pattern gets an integer schema; everything else is a string.
			$is_int   = (bool) preg_match( '/^\[?\\\\d[\]+*]*$/', $pattern ) || ( false !== strpos( $pattern, '\\d' ) && false === strpos( $pattern, 'a-f' ) );
			$params[] = array(
				'name'     => $name,
				'in'       => 'path',
				'required' => true,
				'schema'   => array( 'type' => $is_int ? 'integer' : 'string' ),
			);
			return '{' . $name . '}';
		},
		$route
	);
	return array(
		'path'   => (string) $path,
		'params' => $params,
	);
};

/**
 * Map a WordPress arg definition to an OpenAPI schema fragment.
 *
 * @param array $arg WP arg definition.
 * @return array<string,mixed>
 */
$bn_arg_schema = static function ( array $arg ): array {
	$type   = $arg['type'] ?? 'string';
	$type   = is_array( $type ) ? ( $type[0] ?? 'string' ) : (string) $type;
	$schema = array( 'type' => 'boolean' === $type || 'integer' === $type || 'number' === $type || 'array' === $type || 'object' === $type ? $type : 'string' );
	if ( 'array' === $type && isset( $arg['items'] ) && is_array( $arg['items'] ) ) {
		$item_type       = (string) ( $arg['items']['type'] ?? 'string' );
		$schema['items'] = array( 'type' => in_array( $item_type, array( 'integer', 'number', 'boolean', 'string' ), true ) ? $item_type : 'string' );
	}
	if ( isset( $arg['enum'] ) && is_array( $arg['enum'] ) ) {
		$schema['enum'] = array_values( $arg['enum'] );
	}
	if ( isset( $arg['default'] ) && ! is_array( $arg['default'] ) ) {
		$schema['default'] = $arg['default'];
	}
	return $schema;
};

$bn_server = rest_get_server();
$bn_routes = $bn_server->get_routes();

$bn_http_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
$bn_paths        = array();
$bn_tags_seen    = array();
$bn_route_count  = 0;
$bn_op_count     = 0;

foreach ( $bn_namespaces as $bn_namespace ) {
	$bn_prefix  = '/' . trim( $bn_namespace, '/' );
	$bn_tag_pre = (string) ( $bn_tag_prefixes[ $bn_namespace ] ?? '' );

	foreach ( $bn_routes as $bn_route => $bn_endpoints ) {
		if ( $bn_route !== $bn_prefix && ! str_starts_with( $bn_route, $bn_prefix . '/' ) ) {
			continue;
		}

		// Namespace-relative path, e.g. '/spaces/(?P<id>\d+)/members'.
		$bn_rel = substr( $bn_route, strlen( $bn_prefix ) );
		if ( '' === $bn_rel ) {
			continue; // The namespace index route itself.
		}

		$bn_tpl                  = $bn_templatize( $bn_rel );
		$bn_oa_path              = $bn_tpl['path'];
		$bn_path_par             = $bn_tpl['params'];
		$bn_tag                  = $bn_tag_pre . $bn_tag_for( $bn_rel, $bn_tag_rules, $bn_fallb_tag );
		$bn_tags_seen[ $bn_tag ] = true;

		if ( ! isset( $bn_paths[ $bn_oa_path ] ) ) {
			$bn_paths[ $bn_oa_path ] = array();
			++$bn_route_count;
		}

		foreach ( (array) $bn_endpoints as $bn_ep ) {
			if ( ! is_array( $bn_ep ) || empty( $bn_ep['methods'] ) ) {
				continue;
			}
			$bn_methods = is_array( $bn_ep['methods'] ) ? array_keys( array_filter( $bn_ep['methods'] ) ) : array();
			$bn_args    = isset( $bn_ep['args'] ) && is_array( $bn_ep['args'] ) ? $bn_ep['args'] : array();

			// Public when the permission callback is core's __return_true; otherwise
			// authenticated (cookie+nonce or application password).
			$bn_perm   = $bn_ep['permission_callback'] ?? null;
			$bn_public = ( '__return_true' === $bn_perm );

			foreach ( $bn_methods as $bn_method ) {
				$bn_method = strtoupper( (string) $bn_method );
				if ( ! in_array( $bn_method, $bn_http_methods, true ) ) {
					continue;
				}

				$bn_op = array(
					'tags'        => array( $bn_tag ),
					'operationId' => strtolower( $bn_method ) . preg_replace( '/[^A-Za-z0-9]+/', '_', $bn_oa_path ),
					'summary'     => $bn_method . ' ' . $bn_prefix . $bn_oa_path,
					'parameters'  => $bn_path_par,
					'responses'   => array(
						'200' => array( 'description' => 'Success.' ),
					),
				);

				if ( ! $bn_public ) {
					$bn_op['security']         = array( array( 'cookieAuth' => array() ), array( 'appPassword' => array() ) );
					$bn_op['responses']['401'] = array( 'description' => 'Not authenticated.' );
					$bn_op['responses']['403'] = array( 'description' => 'Authenticated but not permitted.' );
				}

				if ( 'GET' === $bn_method || 'DELETE' === $bn_method ) {
					// Non-path args become query parameters.
					foreach ( $bn_args as $bn_name => $bn_arg ) {
						$bn_has_path_param = (bool) array_filter( $bn_path_par, static fn( $p ) => $p['name'] === $bn_name );
						if ( $bn_has_path_param ) {
							continue;
						}
						$bn_arg                = is_array( $bn_arg ) ? $bn_arg : array();
						$bn_op['parameters'][] = array(
							'name'        => (string) $bn_name,
							'in'          => 'query',
							'required'    => (bool) ( $bn_arg['required'] ?? false ),
							'description' => (string) ( $bn_arg['description'] ?? '' ),
							'schema'      => $bn_arg_schema( $bn_arg ),
						);
					}
				} else {
					// POST/PUT/PATCH: non-path args become a JSON request body.
					$bn_props    = array();
					$bn_required = array();
					foreach ( $bn_args as $bn_name => $bn_arg ) {
						if ( array_filter( $bn_path_par, static fn( $p ) => $p['name'] === $bn_name ) ) {
							continue;
						}
						$bn_arg               = is_array( $bn_arg ) ? $bn_arg : array();
						$bn_props[ $bn_name ] = $bn_arg_schema( $bn_arg );
						if ( ! empty( $bn_arg['description'] ) ) {
							$bn_props[ $bn_name ]['description'] = (string) $bn_arg['description'];
						}
						if ( ! empty( $bn_arg['required'] ) ) {
							$bn_required[] = (string) $bn_name;
						}
					}
					if ( ! empty( $bn_props ) ) {
						$bn_schema = array(
							'type'       => 'object',
							'properties' => $bn_props,
						);
						if ( ! empty( $bn_required ) ) {
							$bn_schema['required'] = $bn_required;
						}
						$bn_op['requestBody'] = array(
							'content' => array(
								'application/json' => array( 'schema' => $bn_schema ),
							),
						);
					}
				}

				// De-dupe empty parameter arrays for cleanliness.
				if ( empty( $bn_op['parameters'] ) ) {
					unset( $bn_op['parameters'] );
				}

				$bn_paths[ $bn_oa_path ][ strtolower( $bn_method ) ] = $bn_op;
				++$bn_op_count;
			}
		}
	}
}

ksort( $bn_paths );

$bn_tag_list = array();
foreach ( array_keys( $bn_tags_seen ) as $bn_t ) {
	$bn_tag_list[] = array( 'name' => $bn_t );
}
usort( $bn_tag_list, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

$bn_doc = array(
	'openapi'    => '3.1.0',
	'info'       => (array) ( $bn_config['info'] ?? array(
		'title'   => 'BuddyNext REST API',
		'version' => '0.0.0',
	) ),
	'servers'    => (array) ( $bn_config['servers'] ?? array() ),
	'tags'       => $bn_tag_list,
	'paths'      => $bn_paths,
	'components' => array(
		'securitySchemes' => (array) ( $bn_config['securitySchemes'] ?? array() ),
	),
);

$bn_env_out  = getenv( 'BN_OPENAPI_OUT' );
$bn_out_rel  = (string) ( $bn_config['output'] ?? 'docs/api/openapi.json' );
$bn_out_path = ( is_string( $bn_env_out ) && '' !== $bn_env_out ) ? $bn_env_out : $bn_plugin_dir . '/' . ltrim( $bn_out_rel, '/' );

$bn_json = wp_json_encode( $bn_doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( false === $bn_json ) {
	fwrite( STDERR, "gen-openapi: failed to encode document.\n" );
	exit( 1 );
}

if ( false === file_put_contents( $bn_out_path, $bn_json . "\n" ) ) {
	fwrite( STDERR, "gen-openapi: could not write {$bn_out_path}\n" );
	exit( 1 );
}

fwrite(
	STDOUT,
	sprintf(
		"gen-openapi: wrote %s\n  %d paths, %d operations, %d tags, namespaces %s\n",
		$bn_out_path,
		$bn_route_count,
		$bn_op_count,
		count( $bn_tag_list ),
		implode( ', ', $bn_namespaces )
	)
);
