<?php
/**
 * Tests for CertRunner's verdict.
 *
 * @package BuddyNext\Tests\Cert
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Cert;

use BuddyNext\Cert\CertRunner;

/**
 * @covers \BuddyNext\Cert\CertRunner
 */
class CertRunnerTest extends \WP_UnitTestCase {

	private string $tmp_dir = '';

	public function tear_down(): void {
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			array_map( 'unlink', (array) glob( $this->tmp_dir . 'audit/*' ) );
			@rmdir( $this->tmp_dir . 'audit' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $this->tmp_dir );           // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		parent::tear_down();
	}

	/**
	 * A run that asserted nothing must not report ok.
	 *
	 * Both of the runner's inputs live under audit/, which is gitignored by
	 * design, so a fresh clone or CI runner has neither. The runner then produces
	 * holes only, and "0 failures" used to read as a pass - `Success: Functional
	 * certification passed - 0 passed, 0 failed`, exit 0, having proved nothing.
	 * bin/build-release.sh gates on that exit code, so a release could be cut on
	 * the strength of a gate that never ran.
	 */
	public function test_run_is_not_ok_when_no_assertions_ran(): void {
		$this->tmp_dir = trailingslashit( get_temp_dir() ) . 'bn-cert-' . wp_generate_password( 8, false ) . '/';
		wp_mkdir_p( $this->tmp_dir . 'audit' );

		$result = ( new CertRunner( $this->tmp_dir ) )->run();

		$this->assertSame( 0, $result['summary']['pass'], 'expected no assertions to run without a manifest or oracles' );
		$this->assertSame( 0, $result['summary']['fail'] );
		$this->assertFalse(
			$result['ok'],
			'a certification run that asserted nothing must never report ok - that is the shape that gets trusted rather than fixed'
		);
	}
}
