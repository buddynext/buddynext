<?php
/**
 * Front-end asset isolation for BuddyNext hub routes.
 *
 * BuddyNext owns its community surfaces end-to-end: the look and feel of every
 * BN hub page (activity, members, spaces, messages, notifications, …) must be
 * uniform and free of third-party CSS/JS that would otherwise leak in from
 * unrelated plugins and fight the design system.
 *
 * At a very late `wp_enqueue_scripts` priority — after every plugin and the
 * theme have enqueued — this pass dequeues, on BN routes only, any style,
 * script, or script-module whose source is NOT WordPress core, the active
 * theme, BuddyNext, or BuddyNext Pro. Genuinely-needed dependencies survive:
 * `wp_dequeue_*` only drops the handle from the print queue, so anything an
 * allowed asset still depends on is re-added by WordPress at print time.
 *
 * Two seams:
 *   - `buddynext_asset_isolation_enabled` (bool) — master switch, on by default.
 *   - `buddynext_allowed_assets` (string[])      — allowed URL prefixes.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Strips foreign assets from BuddyNext front-end routes for a uniform UX.
 */
class AssetIsolation {

	/**
	 * Register the late isolation pass.
	 *
	 * @return void
	 */
	public function init(): void {
		// Priority 9999: run after the theme and every plugin have enqueued.
		add_action( 'wp_enqueue_scripts', array( $this, 'isolate' ), 9999 );
	}

	/**
	 * Dequeue every non-allowlisted style, script, and script-module on BN routes.
	 *
	 * @return void
	 */
	public function isolate(): void {
		if ( ! PageRouter::is_bn_route() ) {
			return;
		}

		/**
		 * Filter whether asset isolation runs on BuddyNext routes.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! (bool) apply_filters( 'buddynext_asset_isolation_enabled', true ) ) {
			return;
		}

		$prefixes = $this->allowed_prefixes();

		$this->isolate_classic( wp_styles(), $prefixes, 'wp_dequeue_style' );
		$this->isolate_classic( wp_scripts(), $prefixes, 'wp_dequeue_script' );
		$this->isolate_modules( $prefixes );
	}

	/**
	 * The allowed asset-source URL prefixes: WP core, active theme, BN, BN Pro.
	 *
	 * @return array<int,string> Normalised, scheme-stable URL prefixes.
	 */
	private function allowed_prefixes(): array {
		$prefixes = array(
			includes_url(),                   // wp-includes/* (jQuery, Interactivity, …).
			admin_url(),                      // wp-admin/* (admin-ajax, media, …).
			get_template_directory_uri(),     // Parent theme.
			get_stylesheet_directory_uri(),   // Child theme.
			BUDDYNEXT_URL,                    // BuddyNext.
		);

		if ( defined( 'BUDDYNEXTPRO_PLUGIN_URL' ) ) {
			$prefixes[] = BUDDYNEXTPRO_PLUGIN_URL; // BuddyNext Pro.
		}

		/**
		 * Filter the allowed asset-source URL prefixes on BuddyNext routes.
		 *
		 * Any enqueued style/script/script-module whose source does not begin
		 * with one of these prefixes is dequeued. Add a prefix here to keep a
		 * specific plugin's assets (e.g. a consent banner) on BN pages.
		 *
		 * @param array<int,string> $prefixes Allowed URL prefixes.
		 */
		$prefixes = (array) apply_filters( 'buddynext_allowed_assets', $prefixes );

		return array_values(
			array_filter(
				array_map(
					static function ( $prefix ): string {
						return is_string( $prefix ) ? set_url_scheme( $prefix ) : '';
					},
					$prefixes
				),
				static function ( string $prefix ): bool {
					return '' !== $prefix;
				}
			)
		);
	}

	/**
	 * Dequeue non-allowlisted handles from a classic dependency registry.
	 *
	 * @param \WP_Dependencies  $registry  wp_styles() or wp_scripts().
	 * @param array<int,string> $prefixes Allowed URL prefixes.
	 * @param callable          $dequeue  wp_dequeue_style / wp_dequeue_script.
	 * @return void
	 */
	private function isolate_classic( \WP_Dependencies $registry, array $prefixes, callable $dequeue ): void {
		foreach ( (array) $registry->queue as $handle ) {
			$dep = $registry->registered[ $handle ] ?? null;
			$src = ( $dep && is_string( $dep->src ) ) ? $dep->src : '';

			if ( ! $this->is_allowed( $src, $prefixes ) ) {
				$dequeue( $handle );
			}
		}
	}

	/**
	 * Dequeue non-allowlisted script modules (WP 6.5+ Interactivity API et al.).
	 *
	 * The script-module registry exposes no public enumeration API, so read the
	 * registered set reflectively and bail gracefully if the internals change.
	 *
	 * @param array<int,string> $prefixes Allowed URL prefixes.
	 * @return void
	 */
	private function isolate_modules( array $prefixes ): void {
		if ( ! function_exists( 'wp_script_modules' ) || ! function_exists( 'wp_dequeue_script_module' ) ) {
			return;
		}

		$modules = wp_script_modules();

		// The enqueued set lives in a private `queue` array; sources live in a
		// private `registered` map keyed by id. Read both reflectively and bail
		// gracefully if either moves (the classic pass has already run).
		$queue      = $this->read_private( $modules, 'queue' );
		$registered = $this->read_private( $modules, 'registered' );

		if ( ! is_array( $queue ) || ! is_array( $registered ) ) {
			return;
		}

		foreach ( $queue as $id ) {
			$entry = $registered[ $id ] ?? null;
			$src   = ( is_array( $entry ) && isset( $entry['src'] ) && is_string( $entry['src'] ) ) ? $entry['src'] : '';

			if ( '' === $src ) {
				continue; // Queued but unregistered — nothing printable to strip.
			}

			if ( ! $this->is_allowed( $src, $prefixes ) ) {
				wp_dequeue_script_module( (string) $id );
			}
		}
	}

	/**
	 * Read a private/protected property off an object, or null if unavailable.
	 *
	 * @param object $obj  Target object.
	 * @param string $name Property name.
	 * @return mixed|null
	 */
	private function read_private( object $obj, string $name ) {
		try {
			// No setAccessible() call: it has been a no-op since PHP 8.1 (the
			// plugin requires 8.2+) and is deprecated as of PHP 8.5.
			$prop = new \ReflectionProperty( $obj, $name );

			return $prop->getValue( $obj );
		} catch ( \ReflectionException $e ) {
			return null;
		}
	}

	/**
	 * Whether an asset source begins with an allowed prefix.
	 *
	 * @param string            $src      Asset source URL.
	 * @param array<int,string> $prefixes Allowed URL prefixes.
	 * @return bool
	 */
	private function is_allowed( string $src, array $prefixes ): bool {
		$src = trim( $src );
		if ( '' === $src ) {
			return true; // Dependency alias / no external file — nothing to strip.
		}

		// Protocol-relative → resolve against the current scheme.
		if ( 0 === strpos( $src, '//' ) ) {
			$src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
		} elseif ( '/' === $src[0] ) {
			// Root-relative → resolve against the site host.
			$src = home_url( $src );
		}

		$src = set_url_scheme( (string) strtok( $src, '?' ) );

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $src, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
