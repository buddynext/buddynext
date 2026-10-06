<?php
/**
 * Per-feature partner versions on the integration registry.
 *
 * @package BuddyNext\Tests\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Integrations\IntegrationRegistry;
use WP_UnitTestCase;

/**
 * Card 10374880583 (owner decision): a bridge keeps its floor; a feature that
 * needs a newer partner is named for the owner instead of switching the whole
 * bridge off.
 */
class FeatureVersionsTest extends WP_UnitTestCase {

	/**
	 * Only features the installed partner is too old for are listed.
	 *
	 * @return void
	 */
	public function test_features_needing_update(): void {
		$entry = array(
			'version'          => '1.6.3',
			'feature_versions' => array(
				'Kudos'   => '1.6.5',
				'Streaks' => '1.6.0',
			),
		);
		$this->assertSame( array( 'Kudos' => '1.6.5' ), IntegrationRegistry::features_needing_update( $entry ) );

		$entry['version'] = '1.6.5';
		$this->assertSame( array(), IntegrationRegistry::features_needing_update( $entry ) );

		$entry['version'] = '';
		$this->assertSame( array(), IntegrationRegistry::features_needing_update( $entry ), 'Unknown version: no claim.' );
	}

	/**
	 * The registry keeps a declared map and drops empty pairs.
	 *
	 * @return void
	 */
	public function test_registry_normalises_the_map(): void {
		$add = static function ( array $items ): array {
			$items['qa_partner'] = array(
				'label'            => 'QA Partner',
				'version'          => '1.0.0',
				'feature_versions' => array(
					'Bells' => '2.0.0',
					''      => '1.0.0',
					'Empty' => '',
				),
			);
			return $items;
		};
		add_filter( 'buddynext_integrations', $add );
		IntegrationRegistry::instance()->reset(); // The registry memoises its entries.
		$entry = IntegrationRegistry::instance()->all()['qa_partner'] ?? array();
		remove_filter( 'buddynext_integrations', $add );
		IntegrationRegistry::instance()->reset();

		$this->assertSame( array( 'Bells' => '2.0.0' ), $entry['feature_versions'] ?? null );
	}
}
