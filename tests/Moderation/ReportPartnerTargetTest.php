<?php
/**
 * Reports on an object another plugin owns (a message, a media item).
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * Partner-owned report target tests.
 *
 * @covers \BuddyNext\Core\ObjectLabels::exists
 * @covers \BuddyNext\Moderation\ModerationService::report
 */
class ReportPartnerTargetTest extends \WP_UnitTestCase {

	/**
	 * The owner of a type says an id does not exist: the report is refused and
	 * no row is written. It says the id exists: the report is taken. Nobody
	 * answers (the partner is not active): the report is taken, as before.
	 *
	 * @return void
	 */
	public function test_report_follows_the_owning_plugins_answer(): void {
		global $wpdb;

		Installer::run();
		// The media bridge answers this filter on a real site; here the test does.
		remove_all_filters( 'buddynext_object_exists' );
		$service  = new ModerationService();
		$reporter = self::factory()->user->create();
		$count    = static function () use ( $wpdb, $reporter ): int {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE reporter_id = %d", $reporter ) );
		};

		$this->assertNotWPError( $service->report( $reporter, 'media', 70001, 'spam' ), 'Nobody can tell: accepted.' );
		$this->assertSame( 1, $count() );

		add_filter(
			'buddynext_object_exists',
			static fn( $exists, string $type, int $id ) => in_array( $type, array( 'message', 'media' ), true ) ? 500 === $id : $exists,
			10,
			3
		);

		foreach ( array( 'message', 'media' ) as $type ) {
			$missing = $service->report( $reporter, $type, 999999, 'spam' );
			$this->assertWPError( $missing, $type );
			$this->assertSame( 'object_not_found', $missing->get_error_code(), $type );
		}
		$this->assertSame( 1, $count(), 'A report about nothing writes no row.' );

		$this->assertNotWPError( $service->report( $reporter, 'message', 500, 'spam' ), 'A real message can be reported.' );
		$this->assertSame( 2, $count() );
	}
}
