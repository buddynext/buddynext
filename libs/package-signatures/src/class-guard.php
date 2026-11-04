<?php
/**
 * Checks registered products' update packages before WordPress installs them.
 *
 * Hooks core's upgrader_pre_download, which runs for plugin and theme updates from the dashboard, auto-updates and
 * WP-CLI alike, whatever updater announced the update. Only packages of registered products from their registered
 * hosts are touched; everything else passes straight through.
 *
 * @package PackageSignatures
 */

defined( 'ABSPATH' ) || exit;

class Package_Signatures_Guard {

	const LOG_OPTION = 'package_signatures_log';
	const LOG_SIZE   = 20;

	/**
	 * Hooks in. Called once, by the newest bundled copy.
	 */
	public static function boot() {
		// Last, so a file another handler supplied earlier is checked too, not waved through.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Registered products, latest registration per plugin or theme wins.
	 *
	 * @return array
	 */
	public static function products() {
		$out = array();
		foreach ( (array) ( $GLOBALS['package_signatures_products'] ?? array() ) as $p ) {
			if ( empty( $p['product'] ) || empty( $p['keys'] ) || ( empty( $p['plugin'] ) && empty( $p['theme'] ) ) ) {
				continue;
			}
			$p['mode']  = ( 'enforce' === ( $p['mode'] ?? '' ) ) ? 'enforce' : 'warn';
			$p['hosts'] = array_map( 'strtolower', (array) ( $p['hosts'] ?? array() ) );
			$out[ ! empty( $p['plugin'] ) ? 'plugin:' . $p['plugin'] : 'theme:' . $p['theme'] ] = $p;
		}
		return $out;
	}

	/**
	 * Filter callback for upgrader_pre_download.
	 *
	 * @param false|string|WP_Error $reply      Earlier reply; anything but false means another handler decided.
	 * @param string                $package    Package URL.
	 * @param WP_Upgrader           $upgrader   Upgrader instance.
	 * @param array                 $hook_extra plugin / theme being updated.
	 * @return false|string|WP_Error
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$product = self::match( (array) $hook_extra );
		if ( ! $product ) {
			return $reply; // Not one of ours: untouched.
		}

		// From here on, this update is checked whatever the package URL says: the offer itself could be forged.
		$response = null;
		$owned    = false;
		if ( is_string( $reply ) && '' !== $reply ) {
			$file = $reply; // Another handler already supplied the file; verify it rather than pass it through.
		} else {
			$problem = self::package_problem( $package, $product );
			if ( '' !== $problem ) {
				$result = array( 'ok' => false, 'reason' => $problem, 'manifest' => array() );
				self::record( $product, $result );
				return 'enforce' === $product['mode'] ? self::refusal( $problem ) : $reply;
			}
			$file     = wp_tempnam( 'package-signatures' );
			$owned    = true;
			$response = wp_safe_remote_get( $package, array( 'timeout' => 300, 'stream' => true, 'filename' => $file ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				wp_delete_file( $file );
				return new WP_Error( 'download_failed', __( 'The update package could not be downloaded.', 'package-signatures' ) );
			}
		}

		$bundle = self::bundle_from( $response, $product );
		$result = Package_Signatures_Verifier::verify( $bundle, (array) $product['keys'], $file, self::expect( $product ) );
		self::record( $product, $result );

		if ( $result['ok'] || 'warn' === $product['mode'] ) {
			return $file; // Hand the file to the upgrader.
		}
		if ( $owned ) {
			wp_delete_file( $file );
		}
		return self::refusal( $result['reason'] );
	}

	/**
	 * Why a package URL must not be fetched for this product, or '' when it may.
	 *
	 * @param mixed $package Package URL.
	 * @param array $product Registration.
	 * @return string
	 */
	private static function package_problem( $package, array $product ) {
		if ( ! is_string( $package ) || ! preg_match( '#^https://#i', $package ) ) {
			return 'insecure_url';
		}
		$host = strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) );
		return in_array( $host, $product['hosts'], true ) ? '' : 'untrusted_host';
	}

	/**
	 * The error that stops an update in enforce mode.
	 *
	 * @param string $reason Reason code.
	 * @return WP_Error
	 */
	private static function refusal( $reason ) {
		/* translators: %s: reason code */
		return new WP_Error( 'package_signature_invalid', sprintf( __( 'Update stopped: the package signature could not be verified (%s).', 'package-signatures' ), $reason ) );
	}

	/**
	 * The registered product this update is for, matched on the plugin or theme being updated only.
	 *
	 * @param array $hook_extra Upgrader context.
	 * @return array|null
	 */
	private static function match( array $hook_extra ) {
		$products = self::products();
		$key      = ! empty( $hook_extra['plugin'] ) ? 'plugin:' . $hook_extra['plugin'] : ( ! empty( $hook_extra['theme'] ) ? 'theme:' . $hook_extra['theme'] : '' );
		return ( '' !== $key && ! empty( $products[ $key ] ) ) ? $products[ $key ] : null;
	}

	/**
	 * Signature bundle: the X-Package-Signature header of our own download, else the product's fallback URL.
	 *
	 * @param array|WP_HTTP_Response|null $res     Download response, null when another handler supplied the file.
	 * @param array                       $product Registration.
	 * @return string
	 */
	private static function bundle_from( $res, array $product ) {
		$header = $res ? (string) wp_remote_retrieve_header( $res, 'x-package-signature' ) : '';
		if ( '' !== $header ) {
			$decoded = base64_decode( $header, true );
			if ( false !== $decoded ) {
				return $decoded;
			}
		}
		$version = self::offered_version( $product );
		if ( empty( $product['fallback'] ) || '' === $version ) {
			return '';
		}
		$url = str_replace( array( '{product}', '{version}' ), array( rawurlencode( $product['product'] ), rawurlencode( $version ) ), (string) $product['fallback'] );
		$sig = wp_safe_remote_get( $url, array( 'timeout' => 15, 'limit_response_size' => Package_Signatures_Verifier::MAX_BUNDLE_BYTES ) );
		return ( ! is_wp_error( $sig ) && 200 === (int) wp_remote_retrieve_response_code( $sig ) ) ? (string) wp_remote_retrieve_body( $sig ) : '';
	}

	/**
	 * What the signed manifest must say: this product, the version WordPress was offered, newer than installed.
	 *
	 * @param array $product Registration.
	 * @return array
	 */
	private static function expect( array $product ) {
		return array(
			'product'   => $product['product'],
			'version'   => self::offered_version( $product ),
			'installed' => self::installed_version( $product ),
		);
	}

	/**
	 * Version in WordPress's update offer for this product.
	 *
	 * @param array $product Registration.
	 * @return string
	 */
	private static function offered_version( array $product ) {
		if ( ! empty( $product['plugin'] ) ) {
			$t = get_site_transient( 'update_plugins' );
			return isset( $t->response[ $product['plugin'] ]->new_version ) ? (string) $t->response[ $product['plugin'] ]->new_version : '';
		}
		$t = get_site_transient( 'update_themes' );
		return isset( $t->response[ $product['theme'] ]['new_version'] ) ? (string) $t->response[ $product['theme'] ]['new_version'] : '';
	}

	/**
	 * Installed version of the product.
	 *
	 * @param array $product Registration.
	 * @return string
	 */
	private static function installed_version( array $product ) {
		if ( ! empty( $product['plugin'] ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = get_plugins();
			return (string) ( $all[ $product['plugin'] ]['Version'] ?? '' );
		}
		return (string) wp_get_theme( $product['theme'] )->get( 'Version' );
	}

	/**
	 * Keeps the last few results (not autoloaded) and tells listeners, for reporting.
	 *
	 * @param array $product Registration.
	 * @param array $result  Verifier result.
	 */
	private static function record( array $product, array $result ) {
		$log   = (array) get_option( self::LOG_OPTION, array() );
		$log[] = array(
			'time'    => time(),
			'product' => $product['product'],
			'version' => (string) ( $result['manifest']['version'] ?? self::offered_version( $product ) ),
			'mode'    => $product['mode'],
			'result'  => $result['ok'] ? 'verified' : 'failed',
			'reason'  => $result['reason'],
		);
		update_option( self::LOG_OPTION, array_slice( $log, -self::LOG_SIZE ), false );
		/**
		 * Fires after a registered product's package was checked.
		 *
		 * @param array $product Registration (product, plugin/theme, mode).
		 * @param array $result  { ok, reason, manifest }.
		 */
		do_action( 'package_signatures_checked', $product, $result );
	}
}
