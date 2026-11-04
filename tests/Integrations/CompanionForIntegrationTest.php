<?php
/**
 * Only an active companion names an Integration Settings box.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Integrations\CompanionRegistry;
use WP_UnitTestCase;

/**
 * Core owns the `blog` toggle, which the Member Blog companion also maps to.
 */
class CompanionForIntegrationTest extends WP_UnitTestCase {

	/**
	 * An inactive companion lends no name to a key it maps to.
	 *
	 * @return void
	 */
	public function test_an_inactive_companion_is_not_returned(): void {
		add_filter(
			'buddynext_companions',
			static fn( $all ) => array(
				'qa-off' => array(
					'label'       => 'QA Off',
					'integration' => 'qa_key',
					'detect'      => static fn(): bool => false,
				),
			) + $all
		);

		$this->assertNull( CompanionRegistry::for_integration( 'qa_key' ) );
	}

	/**
	 * An active companion still names its box.
	 *
	 * @return void
	 */
	public function test_an_active_companion_is_returned(): void {
		add_filter(
			'buddynext_companions',
			static fn( $all ) => array(
				'qa-on' => array(
					'label'       => 'QA On',
					'integration' => 'qa_key',
					'detect'      => static fn(): bool => true,
				),
			) + $all
		);

		$this->assertSame( 'QA On', CompanionRegistry::for_integration( 'qa_key' )['label'] );
	}
}
