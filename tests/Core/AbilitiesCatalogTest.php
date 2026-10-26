<?php
/**
 * Every capability the Roles tab edits is registered as an ability.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Abilities;
use BuddyNext\Core\PermissionService;

/**
 * @covers \BuddyNext\Core\Abilities::get_catalog
 */
class AbilitiesCatalogTest extends \WP_UnitTestCase {

	public function test_every_owner_facing_capability_is_in_the_catalog(): void {
		$missing = array_diff( PermissionService::owner_facing_abilities(), ( new Abilities() )->get_catalog() );
		$this->assertSame( array(), array_values( $missing ) );
	}
}
