<?php
/**
 * Guard the BuddyNext <-> WPMediaVerse surface-ownership rules.
 *
 * The rules themselves live in
 * docs/website/developer-guide/54-mediaverse-surface-ownership.md. This script
 * is what makes them hold. Every defect that map documents was a rule nothing
 * tested: the "no MediaVerse assets on BuddyNext pages" rule survived two years
 * because it is easy to honour, not because anything checked it, and the two
 * surfaces with no written rule at all grew a second implementation each.
 *
 * Checks:
 *   1. No MediaVerse JS/CSS enqueued from BuddyNext.
 *   2. No generated/default avatar registered at pre_get_avatar_data priority 10
 *      — a placeholder must never outrank another plugin's real upload.
 *   3. The lightbox reads the engine's per-comment permission flags rather than
 *      re-deriving who may edit or delete.
 *
 * Usage: php bin/check-mediaverse-surfaces.php
 * Exit:  0 clean, 1 on any violation.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

$root   = dirname( __DIR__ );
$errors = array();

/** Read a file, or '' when absent. */
$read = static function ( string $rel ) use ( $root ): string {
	$path = $root . '/' . $rel;
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
};

// ── 1. MediaVerse assets are never enqueued by BuddyNext ────────────────────
// BuddyNext consumes the engine at the REST level and owns its own UX. An
// wp_enqueue_* of an mvs-* handle is that boundary being crossed.
$asset_hits = array();
$iterator   = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	if ( preg_match( '/wp_enqueue_(?:script|style)\(\s*[\'"](mvs|wpmediaverse)[\w-]*[\'"]/i', $src, $m ) ) {
		$asset_hits[] = str_replace( $root . '/', '', $file->getPathname() ) . ' -> ' . $m[0];
	}
}
if ( $asset_hits ) {
	$errors[] = "BuddyNext enqueues a WPMediaVerse asset (it must consume the engine at REST level only):\n    " . implode( "\n    ", $asset_hits );
}

// ── 2. No placeholder avatar at pre_get_avatar_data priority 10 ─────────────
// Real uploads race at 10; anything generated belongs after them. Registering a
// fallback at 10 is what made BuddyNext's initials beat WPMediaVerse's photo.
$avatar = $read( 'includes/Profile/AvatarService.php' );
if ( '' !== $avatar ) {
	if ( ! preg_match( "/add_filter\(\s*'pre_get_avatar_data',\s*array\(\s*\\\$this,\s*'filter_avatar_fallback'\s*\),\s*(\d+)/", $avatar, $m ) ) {
		$errors[] = 'AvatarService no longer registers filter_avatar_fallback — the generated initials must stay on their own late hook.';
	} elseif ( (int) $m[1] <= 10 ) {
		$errors[] = sprintf(
			'AvatarService registers its generated-initials fallback at priority %d. It must run AFTER the real-avatar sources at 10, or a placeholder outranks another plugin\'s upload.',
			(int) $m[1]
		);
	}
	if ( preg_match( '/function filter_avatar_data\(.*?\n\t\}/s', $avatar, $body ) && str_contains( $body[0], 'build_svg_url' ) ) {
		$errors[] = 'AvatarService::filter_avatar_data() (priority 10) generates an initials SVG. Generated avatars belong in filter_avatar_fallback().';
	}
}

// ── 3. The lightbox trusts the engine's per-comment permission flags ────────
// can_edit / can_delete are computed by the same code that enforces the routes.
// Re-deriving them here is how a UI offers a control that 403s, or hides one the
// API allows — which is exactly what happened when it had neither.
$lightbox = $read( 'assets/js/media/lightbox.js' );
if ( '' !== $lightbox ) {
	// Strip comments first. An earlier version of this check matched anywhere in
	// the file, so the docblock EXPLAINING the flags satisfied it while the code
	// had stopped reading them — a guard that passes on its own documentation.
	$code = preg_replace( '#/\*.*?\*/#s', '', $lightbox );
	$code = preg_replace( '#^\s*//.*$#m', '', (string) $code );

	foreach ( array( 'can_edit', 'can_delete' ) as $flag ) {
		// Require a real property read off a comment object, not a mention.
		if ( ! preg_match( '/\b\w+\.' . preg_quote( $flag, '/' ) . '\b/', (string) $code ) ) {
			$errors[] = sprintf(
				'assets/js/media/lightbox.js no longer reads `%s` from the engine. Comment controls must follow the server flags, not local rules.',
				$flag
			);
		}
	}
}

if ( $errors ) {
	fwrite( STDERR, "MediaVerse surface-ownership violations:\n\n" );
	foreach ( $errors as $e ) {
		fwrite( STDERR, "  - {$e}\n" );
	}
	fwrite( STDERR, "\nRules: docs/website/developer-guide/54-mediaverse-surface-ownership.md\n" );
	exit( 1 );
}

echo "MediaVerse surface ownership: OK (assets, avatar precedence, comment flags)\n";
exit( 0 );
