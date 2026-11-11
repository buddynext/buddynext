<?php
/**
 * Integration Settings renders one table row per integration.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\IntegrationControlsAdmin;

/**
 * @covers \BuddyNext\Admin\IntegrationControlsAdmin
 */
class IntegrationControlsAdminTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'buddynext_integrations',
			static function ( array $items ): array {
				$items['qaint'] = array(
					'label'    => 'QA Integration',
					'has_nav'  => true,
					'has_feed' => true,
					'subtabs'  => array( 'one' => 'One' ),
				);
				return $items;
			}
		);
		\BuddyNext\Integrations\IntegrationRegistry::instance()->reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'buddynext_integrations' );
		\BuddyNext\Integrations\IntegrationRegistry::instance()->reset();
		parent::tear_down();
	}

	/**
	 * Each surface is a switch in its column, a missing surface is a dash, and
	 * the field names the save handler reads are unchanged.
	 */
	public function test_each_integration_is_one_row_with_a_switch_per_surface(): void {
		ob_start();
		( new IntegrationControlsAdmin() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="bn-table bn-int-matrix"', $html );
		$this->assertStringContainsString( 'name="nav[qaint]"', $html );
		$this->assertStringContainsString( 'name="feed[qaint]"', $html );
		$this->assertStringNotContainsString( 'name="search[qaint]"', $html, 'No search surface, so no search switch.' );
		$this->assertStringContainsString( 'bn-int-matrix__none', $html, 'The missing surface shows a dash.' );
		$this->assertStringContainsString( 'name="subtab[qaint][one]"', $html );
		$this->assertSame( 1, substr_count( $html, 'bn-int-matrix__title">QA Integration' ), 'One row per integration.' );
	}
}
