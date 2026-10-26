<?php
/**
 * A refused install answers a 4xx, a store fault a 502, never a 500.
 *
 * @package BuddyNext\Tests\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Integrations\CompanionInstaller;
use WP_UnitTestCase;

/**
 * A 500 reads as an outage to monitoring and the app, so each refusal carries its own status.
 */
class CompanionInstallerStatusTest extends WP_UnitTestCase {

	/**
	 * An admin, since the installer checks install_plugins first.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Register a not-installed companion.
	 *
	 * @param array<string,mixed> $free The entry's free-download config.
	 * @return void
	 */
	private function companion( array $free ): void {
		add_filter(
			'buddynext_companions',
			static function ( $all ) use ( $free ) {
				$all['qa-status'] = array(
					'label'  => 'QA',
					'detect' => static fn(): bool => false,
					'free'   => $free,
				);
				return $all;
			}
		);
	}

	public function test_a_companion_with_no_download_answers_400(): void {
		$this->companion( array() );

		$error = CompanionInstaller::install( 'qa-status' );

		$this->assertWPError( $error );
		$this->assertSame( 'buddynext_no_item', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	public function test_an_unreachable_store_answers_502(): void {
		$this->companion(
			array(
				'item_id'  => 1,
				'key'      => 'k',
				'basename' => 'qa-status/qa-status.php',
			)
		);
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'down' ) );

		$error = CompanionInstaller::install( 'qa-status' );

		$this->assertWPError( $error );
		$this->assertSame( 'buddynext_store_unreachable', $error->get_error_code() );
		$this->assertSame( 502, $error->get_error_data()['status'] );
	}
}
