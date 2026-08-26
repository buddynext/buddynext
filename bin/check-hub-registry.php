<?php
/**
 * Hub-registry convergence gate.
 *
 * The hub list used to live in seven parallel places that drifted out of sync
 * (see free-internal/docs/plans/hub-routing-consolidation.md). Phases 1-3
 * collapsed routing onto HubRegistry: CoreHubs.php is the ONLY hub list, and
 * every hub — core or addon — registers its rewrite rules and resolves its
 * template through its descriptor's register_rules / resolve_template callback.
 *
 * This gate fails the build when that convergence starts to erode again:
 *
 *   A. A parallel hub list re-forms — an array literal OUTSIDE CoreHubs.php that
 *      maps 3+ buddynext_page_* or buddynext_slug_* option keys (the exact shape
 *      of the deleted NavManager::PAGE_OPTIONS / SLUG_OPTIONS).
 *   B. A rewrite rule routes to a bn_hub=<key> that is neither a registered core
 *      descriptor nor one of the known non-hub routes (post, settings,
 *      moderation) — i.e. a hub added to routing but not to the registry.
 *   C. register_rewrites() calls a core-hub register_*_rules() method directly
 *      again, instead of letting the registry loop drive it.
 *
 *   php bin/check-hub-registry.php        # exit 1 on any erosion
 *
 * Conservative: it flags a candidate; a human confirms. Deliberate exceptions
 * carry a `bn-hub-registry-ok:` marker comment on the offending line.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput -- CLI gate: reads plugin source from local disk, prints a report.

$bn_dir = dirname( __DIR__ );

/** Core hub keys that ARE registered as descriptors in CoreHubs.php. */
$bn_core_hub_keys = array( 'feed', 'people', 'spaces', 'messages', 'notifications', 'auth', 'onboarding', 'community_admin' );

/**
 * Non-hub bn_hub values routed without a descriptor. These stay explicit until
 * they gain descriptors (plan Phase 4: post folds into feed; settings +
 * moderation get descriptors). Shrinking this list is progress; growing it needs
 * a deliberate decision, so a new entry here is the signal to review.
 */
$bn_non_hub_routes = array( 'post', 'settings', 'moderation' );

$bn_allowed_hub_values = array_merge( $bn_core_hub_keys, $bn_non_hub_routes );

$bn_violations = array();

/**
 * Recursively collect *.php files under a directory.
 *
 * @param string $dir Directory to walk.
 * @return string[] Absolute file paths.
 */
$bn_php_files = static function ( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$out[] = $file->getPathname();
		}
	}
	return $out;
};

$bn_includes = $bn_php_files( $bn_dir . '/includes' );

// ── Check A: a parallel hub list re-forming ────────────────────────────────
// An array literal with 3+ buddynext_(page|slug)_* option keys anywhere but
// CoreHubs.php is a PAGE_OPTIONS/SLUG_OPTIONS-style parallel list coming back.
foreach ( $bn_includes as $bn_file ) {
	if ( 'CoreHubs.php' === basename( $bn_file ) ) {
		continue;
	}
	$bn_src = (string) file_get_contents( $bn_file );
	// Count distinct option keys per array-literal-ish window is overkill; the
	// drift shape is several buddynext_(page|slug)_* keys clustered in one file.
	// A file that names 3+ DISTINCT such options AND uses => (a map) is the flag.
	if ( ! preg_match_all( "/'(buddynext_(?:page|slug)_[a-z_]+)'\\s*=>/", $bn_src, $m ) ) {
		continue;
	}
	$distinct = array_unique( $m[1] );
	if ( count( $distinct ) >= 3 && false === strpos( $bn_src, 'bn-hub-registry-ok:' ) ) {
		$bn_violations[] = sprintf(
			"A. Parallel hub list re-forming in %s — maps %d hub options (%s). The hub list lives ONLY in CoreHubs.php; derive from HubRegistry instead.",
			str_replace( $bn_dir . '/', '', $bn_file ),
			count( $distinct ),
			implode( ', ', array_slice( $distinct, 0, 4 ) )
		);
	}
}

// ── Check B: a bn_hub route with no descriptor ─────────────────────────────
// Every index.php?bn_hub=<key> target in a rewrite rule must be a registered
// descriptor key or a known non-hub route.
foreach ( $bn_includes as $bn_file ) {
	$bn_src = (string) file_get_contents( $bn_file );
	if ( ! preg_match_all( '/bn_hub=([a-z_]+)/', $bn_src, $m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $m[1] as $hit ) {
		$val = $hit[0];
		if ( in_array( $val, $bn_allowed_hub_values, true ) ) {
			continue;
		}
		$line = 1 + substr_count( substr( $bn_src, 0, (int) $hit[1] ), "\n" );
		$bn_violations[] = sprintf(
			"B. %s:%d routes bn_hub=%s, which is neither a registered hub descriptor nor a known non-hub route. Register it in CoreHubs.php (or via buddynext_register_hubs).",
			str_replace( $bn_dir . '/', '', $bn_file ),
			$line,
			$val
		);
	}
}

// ── Check C: register_rewrites() calling a core-hub rules method directly ───
// After Phase 3 the registry loop drives core-hub rules; register_rewrites()
// may only call the three non-hub rule methods explicitly.
$bn_router = $bn_dir . '/includes/Core/PageRouter.php';
if ( is_file( $bn_router ) ) {
	$bn_src = (string) file_get_contents( $bn_router );
	if ( preg_match( '/function register_rewrites\(\).*?\n\t\}/s', $bn_src, $rm ) ) {
		$body = $rm[0];
		if ( preg_match_all( '/(?:self::|\$this->)register_([a-z_]+)_rules\(\)/', $body, $cm ) ) {
			$allowed_explicit = array( 'post', 'settings', 'moderation' );
			foreach ( array_unique( $cm[1] ) as $which ) {
				if ( ! in_array( $which, $allowed_explicit, true ) ) {
					$bn_violations[] = sprintf(
						"C. register_rewrites() calls register_%s_rules() directly. Core-hub rules must be driven by the registry loop via the descriptor's register_rules callback, not an explicit call.",
						$which
					);
				}
			}
		}
	}
}

if ( empty( $bn_violations ) ) {
	echo "hub-registry gate: clean — one hub list (CoreHubs.php), every route has a descriptor or a known non-hub home.\n";
	exit( 0 );
}

echo "hub-registry gate: " . count( $bn_violations ) . " issue(s):\n";
foreach ( $bn_violations as $v ) {
	echo "  - {$v}\n";
}
exit( 1 );
