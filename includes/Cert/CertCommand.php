<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WP-CLI surface for the functional-certification engine.
 *
 * The single trustworthy gate: it asserts the plugin BEHAVES (toggles enforce,
 * routes don't fatal), not that the code is well-formed. Registered in
 * Plugin::init() under `wp buddynext cert` and invoked by bin/check.sh + CI.
 *
 *   wp buddynext cert              Run all functional checks; exit 1 on any fail.
 *   wp buddynext cert contract     Only the dead-toggle (behaviour-flip) check.
 *   wp buddynext cert boot         Only the REST boot-smoke (no route 500s).
 *   wp buddynext cert --json       Machine-readable ledger (for the MCP / CI).
 *
 * @package BuddyNext\Cert
 */

declare( strict_types=1 );

namespace BuddyNext\Cert;

/**
 * `wp buddynext cert` command handler.
 */
class CertCommand {

	/**
	 * Run the functional certification gate.
	 *
	 * ## OPTIONS
	 *
	 * [<check>]
	 * : Which check to run. One of: contract, boot. Omit to run all.
	 *
	 * [--json]
	 * : Emit the ledger as JSON instead of a human summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext cert
	 *     wp buddynext cert contract
	 *     wp buddynext cert --json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$checks = array();
		if ( ! empty( $args[0] ) ) {
			$checks = array( (string) $args[0] );
		}

		$result = ( new CertRunner() )->run( $checks );

		if ( isset( $assoc_args['json'] ) ) {
			\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			if ( ! $result['ok'] ) {
				\WP_CLI::halt( 1 );
			}
			return;
		}

		foreach ( $result['rows'] as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			$label  = sprintf( '%-9s %-22s %s', (string) ( $row['check'] ?? '' ), (string) ( $row['entity'] ?? '' ), (string) ( $row['detail'] ?? '' ) );
			if ( 'pass' === $status ) {
				\WP_CLI::log( '  ' . \WP_CLI::colorize( '%gPASS%n ' ) . $label );
			} elseif ( 'fail' === $status ) {
				\WP_CLI::log( '  ' . \WP_CLI::colorize( '%rFAIL%n ' ) . $label );
			} else {
				\WP_CLI::log( '  ' . \WP_CLI::colorize( '%yHOLE%n ' ) . $label );
			}
		}

		$s = $result['summary'];
		\WP_CLI::log( '' );
		$line = sprintf( '%d passed, %d failed, %d holes (uncovered)', (int) $s['pass'], (int) $s['fail'], (int) $s['hole'] );

		if ( $result['ok'] ) {
			\WP_CLI::success( 'Functional certification passed — ' . $line );
		} else {
			\WP_CLI::error( 'Functional certification FAILED — ' . $line ); // error() exits 1.
		}
	}
}
