<?php
/**
 * WP-CLI: find and repair member handles that cannot be mentioned.
 *
 * A `user_nicename` outside {@see Handle::CHARSET} cannot be produced by
 * WordPress or by BuddyNext — `sanitize_title()` strips the offending characters,
 * and BuddyNext's signup derives a login from the local part of the email. It
 * only arrives by a direct database write, which is exactly what a migration from
 * another platform does.
 *
 * The member is then silently unmentionable: the parsers stop at the first
 * foreign character, so `@name@example-com` reads as `name` followed by
 * `example-com` and neither resolves. Their profile still works, which is why the
 * fault goes unnoticed until someone reports that a member "does not come up".
 *
 * The repair normalises `user_nicename` back to what WordPress itself would have
 * written, rather than layering a BuddyNext-only override on top. That is what
 * makes it uniform: partner plugins read `user_nicename` and become correct
 * without knowing BuddyNext exists.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use WP_CLI;

/**
 * Audits and repairs unmentionable member handles.
 */
class HandleCommand {

	/**
	 * List members whose handle cannot be mentioned.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext handles check
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args (unused).
	 * @return void
	 */
	public function check( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$rows = ( new HandleRepair() )->find_unsafe();

		if ( empty( $rows ) ) {
			WP_CLI::success( 'Every member handle can be mentioned.' );
			return;
		}

		WP_CLI::warning(
			sprintf(
				/* translators: %d: number of members. */
				_n(
					'%d member has a handle that cannot be mentioned:',
					'%d members have handles that cannot be mentioned:',
					count( $rows ),
					'buddynext'
				),
				count( $rows )
			)
		);

		foreach ( $rows as $row ) {
			$safe = Handle::make_safe( $row['user_nicename'] );

			WP_CLI::log(
				sprintf(
					'  #%-6d %-32s -> %s',
					$row['ID'],
					$row['user_nicename'],
					'' !== $safe ? $safe : '(cannot derive - set one by hand)'
				)
			);
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Run `wp buddynext handles repair --yes` to apply. Profile URLs change for these members.' );
	}

	/**
	 * Normalise unmentionable handles to WordPress's own nicename rules.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Apply the changes. Without this the command only reports what it would do,
	 * because the repair rewrites profile URLs.
	 *
	 * [--dry-run]
	 * : Report only. Implied when --yes is absent; accepted so an explicit
	 * dry run reads clearly in a script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext handles repair
	 *     wp buddynext handles repair --yes
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function repair( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		// Dry-run unless the operator opts IN. This rewrites profile URLs, and an
		// interactive prompt is not available in every context this runs in (cron,
		// CI, a deploy hook), so the safe direction is the default one.
		$dry_run = ! isset( $assoc_args['yes'] ) || isset( $assoc_args['dry-run'] );

		$repair = new HandleRepair();

		if ( empty( $repair->find_unsafe( 1 ) ) ) {
			WP_CLI::success( 'Every member handle can be mentioned. Nothing to repair.' );
			return;
		}

		$result = $repair->repair_all( $dry_run );

		foreach ( $result['changes'] as $change ) {
			WP_CLI::log(
				sprintf(
					'  %s %d: %s -> %s',
					$dry_run ? 'would repair' : 'repaired',
					$change['id'],
					$change['from'],
					$change['to']
				)
			);
		}

		foreach ( $result['skips'] as $skip ) {
			WP_CLI::warning( sprintf( 'User %d (%s): cannot derive a handle - set one by hand.', $skip['id'], $skip['from'] ) );
		}

		$summary = sprintf(
			'%s %d handle(s); %d skipped.',
			$dry_run ? 'Would repair' : 'Repaired',
			$result['repaired'],
			$result['skipped']
		);

		if ( $result['skipped'] > 0 ) {
			WP_CLI::warning( $summary );
			return;
		}

		WP_CLI::success( $summary );
	}

	/**
	 * Collapse members who carry two public identities onto one.
	 *
	 * Before 1.1.6 renaming yourself wrote BuddyNext's `bn_profile_slug` and left
	 * WordPress's `user_nicename` alone, so the member's profile and mentions said
	 * one thing while the author archive, `/wp/v2/users` and every partner plugin
	 * said another. New renames write both; this repairs the rows already on disk.
	 *
	 * ## OPTIONS
	 *
	 * [--prefer=<field>]
	 * : Which identity survives.
	 * ---
	 * default: handle
	 * options:
	 *   - handle
	 *   - nicename
	 * ---
	 *
	 * [--yes]
	 * : Apply the changes. Without this the command only reports, because either
	 * direction rewrites a public URL.
	 *
	 * [--dry-run]
	 * : Report only. Implied when --yes is absent; accepted so an explicit dry run
	 * reads clearly in a script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext handles reconcile
	 *     wp buddynext handles reconcile --yes
	 *     wp buddynext handles reconcile --prefer=nicename --yes
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function reconcile( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		// Dry-run unless the operator opts IN, for the same reason `repair` does:
		// this rewrites public URLs and runs in contexts with no prompt available.
		$dry_run = ! isset( $assoc_args['yes'] ) || isset( $assoc_args['dry-run'] );
		$prefer  = 'nicename' === ( $assoc_args['prefer'] ?? 'handle' ) ? 'nicename' : 'handle';

		$repair = new HandleRepair();

		if ( empty( $repair->find_divergent( 1 ) ) ) {
			WP_CLI::success( 'Every member has one identity. Nothing to reconcile.' );
			return;
		}

		WP_CLI::log(
			'handle' === $prefer
				? 'Keeping each member\'s BuddyNext handle; their old nicename stays reserved and keeps resolving.'
				: 'Keeping each member\'s WordPress nicename; the BuddyNext handle they chose stays reserved and keeps resolving.'
		);

		$result = $repair->reconcile_all( $dry_run, $prefer );

		foreach ( $result['changes'] as $change ) {
			WP_CLI::log(
				sprintf(
					'  %s %d: %s -> %s',
					$dry_run ? 'would reconcile' : 'reconciled',
					$change['id'],
					$change['from'],
					$change['to']
				)
			);
		}

		foreach ( $result['skips'] as $skip ) {
			WP_CLI::warning( sprintf( 'User %d (%s): %s', $skip['id'], $skip['from'], $skip['reason'] ) );
		}

		$summary = sprintf(
			'%s %d member(s); %d skipped.',
			$dry_run ? 'Would reconcile' : 'Reconciled',
			$result['reconciled'],
			$result['skipped']
		);

		if ( $result['skipped'] > 0 ) {
			WP_CLI::warning( $summary );
			return;
		}

		WP_CLI::success( $summary );
	}
}
