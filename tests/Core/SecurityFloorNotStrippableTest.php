<?php
/**
 * Security and consent plugins are a hard never-strip floor: an owner cannot
 * strip a firewall / 2FA / paywall / cookie banner from the isolation screen
 * (card 10317870342).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PluginIsolation;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PluginIsolation::owner_strip_list
 * @covers \BuddyNext\Core\PluginIsolation::essentials
 */
class SecurityFloorNotStrippableTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( PluginIsolation::OPTION_STRIP );
		parent::tear_down();
	}

	public function test_a_security_plugin_ticked_for_strip_is_never_stripped(): void {
		$firewall = 'wordfence/wordfence.php';
		update_option( PluginIsolation::OPTION_STRIP, array( $firewall ) );

		$this->assertNotContains(
			$firewall,
			PluginIsolation::owner_strip_list(),
			'a firewall must never reach the strip list, whatever the option says'
		);
		$this->assertContains( $firewall, PluginIsolation::essentials(), 'the security floor must be in essentials()' );
	}

	public function test_a_consent_plugin_ticked_for_strip_is_never_stripped(): void {
		$cmp = 'complianz-gdpr/complianz-gpdr.php';
		update_option( PluginIsolation::OPTION_STRIP, array( $cmp ) );

		$this->assertNotContains(
			$cmp,
			PluginIsolation::owner_strip_list(),
			'a consent manager must never reach the strip list (GDPR)'
		);
		$this->assertContains( $cmp, PluginIsolation::essentials() );
	}

	public function test_a_membership_plugin_is_a_floor(): void {
		$this->assertContains( 'paid-memberships-pro/paid-memberships-pro.php', PluginIsolation::essentials() );
		$this->assertContains( 'memberpress/memberpress.php', PluginIsolation::essentials() );
	}
}
