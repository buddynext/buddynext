<?php
/**
 * Register every REST route the plugins can expose, for the build-time API tools.
 *
 * Routes behind a feature toggle (webhooks, realtime, AI, push) register only when
 * the feature is on, so anything built from the live route registry used to depend
 * on the build site's settings. The spec generator, the OpenAPI gate, the schema
 * authoring aid and the reachability audit all require this first, so they agree
 * on one route set. Partner-plugin routes still need the partner active.
 *
 * Build-time only: nothing in the shipped plugin loads this file.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( function_exists( 'buddynext_service' ) ) {
	$bn_features = buddynext_service( 'features' );
	if ( is_object( $bn_features ) && method_exists( $bn_features, 'catalog' ) ) {
		foreach ( array_keys( (array) $bn_features->catalog() ) as $bn_feature_slug ) {
			add_filter( 'buddynext_feature_' . $bn_feature_slug, '__return_true', PHP_INT_MAX );
		}
		// WP-CLI may already have built the server with the site's own toggles.
		$GLOBALS['wp_rest_server'] = null;
	}
}
