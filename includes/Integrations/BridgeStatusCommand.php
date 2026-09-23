<?php
/**
 * WP-CLI: report the freshness of every integration bridge against its partner.
 *
 * The recurring staleness gate. Each bridge declares, in its
 * `buddynext_integrations` entry, the partner floor it needs (`min_version`)
 * and the partner release it was last built against (`tested_version`). This
 * command walks the registry and compares those against the partner version
 * actually installed, so a bridge cannot silently rot as its partner ships new
 * releases: run it in CI (or by hand) and it fails when a partner has moved
 * below a bridge's floor or ahead of what the bridge was verified against.
 *
 * @package BuddyNext\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Integrations;

use WP_CLI;

/**
 * Reports each integration's version state: ok / below-floor / partner-ahead.
 */
class BridgeStatusCommand {

	/**
	 * Report the version freshness of every registered integration bridge.
	 *
	 * A bridge is BELOW FLOOR when the installed partner is older than the
	 * bridge's `min_version` (wired seams silently no-op) — always a failure.
	 * A bridge is BEHIND when the installed partner is newer than the bridge's
	 * `tested_version` (the partner shipped past what the bridge was built for,
	 * so its newest capabilities may not be wired) — a warning, or a failure
	 * under `--strict`.
	 *
	 * ## OPTIONS
	 *
	 * [--strict]
	 * : Treat a behind (partner-ahead) bridge as a failure too, not just a warning.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext bridge-status
	 *     wp buddynext bridge-status --strict
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args: `strict`.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		$strict  = ! empty( $assoc_args['strict'] );
		$entries = IntegrationRegistry::instance()->all();

		if ( empty( $entries ) ) {
			WP_CLI::warning( 'No integration bridges are registered (no partner plugins active).' );
			return;
		}

		$rows        = array();
		$below_floor = 0;
		$behind      = 0;
		$unknown     = 0;

		foreach ( $entries as $key => $entry ) {
			$version = (string) ( $entry['version'] ?? '' );
			$floor   = (string) ( $entry['min_version'] ?? '' );
			$tested  = (string) ( $entry['tested_version'] ?? '' );

			if ( '' === $version ) {
				$state = 'unknown';
				++$unknown;
			} elseif ( '' !== $floor && version_compare( $version, $floor, '<' ) ) {
				$state = 'below-floor';
				++$below_floor;
			} elseif ( '' !== $tested && version_compare( $version, $tested, '>' ) ) {
				$state = 'behind';
				++$behind;
			} else {
				$state = 'ok';
			}

			$rows[] = array(
				'integration' => (string) $key,
				'installed'   => '' !== $version ? $version : '(unknown)',
				'floor'       => '' !== $floor ? $floor : '-',
				'tested'      => '' !== $tested ? $tested : '-',
				'state'       => $state,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'integration', 'installed', 'floor', 'tested', 'state' ) );

		if ( $below_floor > 0 ) {
			WP_CLI::error(
				sprintf(
					/* translators: %d: count of integrations below their floor. */
					_n( '%d integration is below its partner floor, so a wired feature is silently off. Update the partner.', '%d integrations are below their partner floor, so wired features are silently off. Update the partners.', $below_floor, 'buddynext' ),
					$below_floor
				)
			);
			return; // WP_CLI::error exits, but keep control flow explicit.
		}

		if ( $behind > 0 ) {
			$message = sprintf(
				/* translators: %d: count of integrations whose partner is newer than tested. */
				_n( '%d integration has a partner newer than the version its bridge was built for. The bridge is due a refresh.', '%d integrations have a partner newer than the version their bridge was built for. Those bridges are due a refresh.', $behind, 'buddynext' ),
				$behind
			);
			if ( $strict ) {
				WP_CLI::error( $message );
				return;
			}
			WP_CLI::warning( $message );
		}

		if ( $unknown > 0 ) {
			WP_CLI::log(
				sprintf(
					/* translators: %d: count of integrations that declared no version. */
					_n( '%d integration declared no partner version, so it cannot be version-gated.', '%d integrations declared no partner version, so they cannot be version-gated.', $unknown, 'buddynext' ),
					$unknown
				)
			);
		}

		// $below_floor is 0 here - a non-zero count returned via WP_CLI::error above.
		if ( 0 === $behind ) {
			WP_CLI::success( 'Every integration bridge is current with its partner.' );
		}
	}
}
