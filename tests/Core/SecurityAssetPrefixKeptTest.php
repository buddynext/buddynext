<?php
/**
 * An active security plugin's front-end assets must NOT be dequeued on hub routes
 * (card 10317869252).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PluginIsolation;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PluginIsolation::frontend_asset_prefixes
 */
class SecurityAssetPrefixKeptTest extends WP_UnitTestCase {

	/** @var array<int,string> */
	private array $orig_active;
	/** @var mixed */
	private $orig_detected;

	public function set_up(): void {
		parent::set_up();
		$this->orig_active   = (array) get_option( 'active_plugins', array() );
		$this->orig_detected = get_option( PluginIsolation::DETECTED_OPTION, array() );
	}

	public function tear_down(): void {
		update_option( 'active_plugins', $this->orig_active );
		update_option( PluginIsolation::DETECTED_OPTION, $this->orig_detected );
		parent::tear_down();
	}

	public function test_active_security_plugin_asset_prefix_is_kept(): void {
		$security = PluginIsolation::security_plugins();
		$this->assertNotEmpty( $security, 'the security floor list must not be empty' );
		$probe = $security[0];

		// It is active and discovered to render on the front end - so the asset pass
		// evaluates it and must KEEP it, not dequeue it. The detected-plugins cache is
		// fingerprinted against the active set, so seed both together.
		$active = array_values( array_unique( array_merge( $this->orig_active, array( $probe ) ) ) );
		update_option( 'active_plugins', $active );
		update_option(
			PluginIsolation::DETECTED_OPTION,
			array(
				'fingerprint' => md5( (string) wp_json_encode( $active ) ),
				'plugins'     => array( $probe ),
			)
		);

		$prefixes = PluginIsolation::frontend_asset_prefixes();
		$expected = trailingslashit( plugins_url( '', $probe ) );

		$this->assertContains(
			$expected,
			$prefixes,
			"an active security plugin ({$probe}) must keep its asset prefix on hub routes - "
				. 'if it falls into the $pro_family delta its CSS/JS is dequeued while it stays loaded'
		);
	}
}
