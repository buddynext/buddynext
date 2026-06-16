<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext companion installer.
 *
 * Installs a companion plugin by reusing the EDD delivery channel the companions
 * already speak: POST the store with `edd_action=get_version` + item_id + key,
 * take the signed package URL it returns, and hand it to WP core's
 * Plugin_Upgrader. Free companions install with the baked-in free distribution
 * key (the same key in the companion's own main file); the store only authorizes
 * the download once the license is activated for this domain.
 *
 * Security: install_plugins capability required; only catalog slugs are
 * installable; the download URL is resolved through EDD, never client input; the
 * store host is locked to wbcomdesigns.com. BuddyNext's job ends at activation —
 * the companion's own bundled SDK then manages its updates.
 *
 * @package BuddyNext\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Integrations;

use WP_Error;

/**
 * One-click free install + activate for catalog companions.
 */
final class CompanionInstaller {

	/**
	 * The only host the installer will ever talk to or download from.
	 */
	private const STORE_URL = 'https://wbcomdesigns.com';

	/**
	 * HTTP timeout (seconds) for store calls.
	 */
	private const TIMEOUT = 20;

	/**
	 * Install (and activate) a companion.
	 *
	 * @param string $slug Companion slug (must be in the registry).
	 * @return true|WP_Error True on success (installed + active), WP_Error otherwise.
	 */
	public static function install( string $slug ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'buddynext_cap', __( 'You do not have permission to install plugins.', 'buddynext' ), array( 'status' => 403 ) );
		}

		$entry = CompanionRegistry::get( $slug );
		if ( null === $entry ) {
			return new WP_Error( 'buddynext_unknown_companion', __( 'Unknown integration.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// Already live — nothing to do.
		if ( CompanionRegistry::is_active( $slug ) ) {
			return true;
		}

		$config  = is_array( $entry['free'] ?? null ) ? $entry['free'] : array();
		$item_id = (int) ( $config['item_id'] ?? 0 );
		$key     = (string) ( $config['key'] ?? '' );
		if ( $item_id <= 0 || '' === $key ) {
			return new WP_Error( 'buddynext_no_item', __( 'This integration cannot be installed automatically. Visit the store.', 'buddynext' ) );
		}

		// Installed but inactive → just activate it (idempotent, never re-download).
		$basename = (string) ( $config['basename'] ?? '' );
		if ( '' !== $basename && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $basename ) ) {
			return self::activate( $basename );
		}

		// EDD Software Licensing only authorizes the download once the license is
		// activated for this domain. Activate first, then surface the store's real
		// reason if it refuses, so a failure is diagnosable.
		$activation = self::activate_license( $item_id, $key );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}

		$package = self::resolve_package_url( $item_id, $key );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$installed = self::install_package( $package );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		return self::activate( '' !== $basename ? $basename : (string) $installed );
	}

	/**
	 * Activate the free license for this domain (EDD authorizes the download only
	 * after this). Returns true on valid/active; a WP_Error carrying the store's
	 * own reason otherwise.
	 *
	 * @param int    $item_id Store product id.
	 * @param string $key     Free distribution key.
	 * @return true|WP_Error
	 */
	private static function activate_license( int $item_id, string $key ) {
		$response = wp_remote_post(
			self::STORE_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'edd_action'  => 'activate_license',
					'item_id'     => $item_id,
					'license'     => $key,
					'url'         => home_url(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'buddynext_store_unreachable', __( 'Could not reach the store to activate the license. Please try again.', 'buddynext' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'buddynext_store_bad_response', __( 'The store returned an unexpected response while activating the license.', 'buddynext' ) );
		}

		$status = (string) ( $body['license'] ?? '' );
		if ( in_array( $status, array( 'valid', 'active' ), true ) ) {
			return true;
		}
		if ( 'invalid' === $status && ! empty( $body['success'] ) ) {
			return true;
		}

		$reason = (string) ( $body['error'] ?? ( '' !== $status ? $status : 'unknown' ) );
		return new WP_Error(
			'buddynext_license_activation_failed',
			sprintf(
				/* translators: %s: the store's activation error reason. */
				__( 'The store would not activate this free license for your site (reason: %s). This is a store-side license configuration issue, not a site error.', 'buddynext' ),
				$reason
			)
		);
	}

	/**
	 * Ask the store for the signed package URL for an item.
	 *
	 * @param int    $item_id Store product id.
	 * @param string $key     Free distribution key.
	 * @return string|WP_Error Package URL, or WP_Error.
	 */
	private static function resolve_package_url( int $item_id, string $key ) {
		$response = wp_remote_post(
			self::STORE_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'edd_action' => 'get_version',
					'item_id'    => $item_id,
					'license'    => $key,
					'url'        => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'buddynext_store_unreachable', __( 'Could not reach the store. Please try again.', 'buddynext' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'buddynext_store_bad_response', __( 'The store returned an unexpected response.', 'buddynext' ) );
		}

		$package = (string) ( $body['download_link'] ?? ( $body['package'] ?? '' ) );
		if ( '' === $package ) {
			return new WP_Error( 'buddynext_no_package', __( 'The store did not return a download for this plugin.', 'buddynext' ) );
		}

		// Lock the download to the store host — never follow a redirect off-domain.
		$host = (string) wp_parse_url( $package, PHP_URL_HOST );
		if ( '' === $host || ! ( 'wbcomdesigns.com' === $host || str_ends_with( $host, '.wbcomdesigns.com' ) ) ) {
			return new WP_Error( 'buddynext_bad_package_host', __( 'The download URL was not on the trusted store host.', 'buddynext' ) );
		}

		return $package;
	}

	/**
	 * Download + unpack a plugin zip via WP core's Plugin_Upgrader.
	 *
	 * @param string $package Signed package URL.
	 * @return string|WP_Error Installed plugin basename/destination, or WP_Error.
	 */
	private static function install_package( string $package ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$creds = request_filesystem_credentials( '', '', false, false, null );
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			return new WP_Error( 'buddynext_fs', __( 'WordPress needs filesystem access to install plugins. Configure direct file access or install from the Plugins screen.', 'buddynext' ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			// WP's generic "download_failed" hides WHY. Probe the package URL once so
			// the message carries the store's real reason (e.g. a 401 "Invalid
			// license supplied"), which is what makes this diagnosable.
			if ( 'download_failed' === $result->get_error_code() ) {
				$probe  = wp_remote_get( $package, array( 'timeout' => self::TIMEOUT ) );
				$code   = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );
				$reason = is_wp_error( $probe ) ? $probe->get_error_message() : trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $probe ) ) );
				if ( $code >= 400 ) {
					return new WP_Error(
						'buddynext_download_rejected',
						sprintf(
							/* translators: 1: HTTP status, 2: store reason text. */
							__( 'The store rejected the download (HTTP %1$d: %2$s). This is a store-side license/entitlement issue.', 'buddynext' ),
							$code,
							'' !== $reason ? mb_substr( $reason, 0, 120 ) : __( 'no reason given', 'buddynext' )
						)
					);
				}
			}
			return $result;
		}
		if ( true !== $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
			return new WP_Error( 'buddynext_install_failed', __( 'The plugin could not be installed.', 'buddynext' ) );
		}

		return (string) $upgrader->plugin_info();
	}

	/**
	 * Activate an installed plugin by basename.
	 *
	 * @param string $basename e.g. "jetonomy/jetonomy.php".
	 * @return true|WP_Error
	 */
	private static function activate( string $basename ) {
		if ( '' === $basename ) {
			return new WP_Error( 'buddynext_activate', __( 'Installed, but the plugin could not be activated automatically. Activate it from the Plugins screen.', 'buddynext' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$activated = activate_plugin( $basename );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}
		return true;
	}
}
