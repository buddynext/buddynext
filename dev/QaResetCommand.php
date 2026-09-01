<?php
/**
 * QA reset — remove what the e2e harnesses left behind on a shared site.
 *
 * DEV-ONLY, and guarded the same way as QaFixturesCommand: this directory is NOT
 * on bin/build-release.sh's RUNTIME allowlist, so the file is absent from a
 * packaged install and Plugin.php's is_readable() check means the command simply
 * never registers on a customer site.
 *
 * WHY THIS IS NOT `qa-fixtures cleanup`
 * -------------------------------------
 * `qa-fixtures cleanup` is the right shape: it writes a manifest of every row it
 * creates and removes exactly those ids. Nothing it made can be missed and
 * nothing else can be hit.
 *
 * The Playwright specs have no such manifest. They create data the way a member
 * does — through the UI and the REST API — and then end. There is no record of
 * what they made, so the only handle left is what the content LOOKS like. That
 * makes this command strictly more dangerous than cleanup(), and the design
 * follows from that:
 *
 *   - It reports and changes nothing unless the operator passes --yes.
 *   - Every pattern is anchored (`^`), so a member writing "my e2e notes" mid-post
 *     is never matched — only content that STARTS with a harness prefix.
 *   - An account that can administer the site is never deleted, whatever it is
 *     named, and is reported as needing a person. On the site this was written
 *     against that surfaced `rft_admin` - a harness had minted a full
 *     administrator and left it behind, which is a security finding rather than
 *     residue to sweep up.
 *   - User 1 is never deleted under any pattern.
 *   - Space member counts are recomputed afterwards. A raw DELETE does not fire
 *     adjust_member_count(), which is how a 2026-07-13 QA pass left every space
 *     reporting a membership it no longer had.
 *
 * THE REAL FIX IS UPSTREAM
 * ------------------------
 * This command exists so a shared dev/QA site can be brought back to a baseline
 * that is honest to review against. It does not excuse a spec that leaves rows
 * behind: a spec should clean up after itself, or run against a database that is
 * thrown away. Card 10251999569 carries both.
 *
 * @package BuddyNext\Dev
 */

declare( strict_types=1 );

namespace BuddyNext\Dev;

use WP_CLI;

/**
 * Purges e2e/test-harness residue from a shared development site.
 */
class QaResetCommand {

	/**
	 * Content prefixes that identify a harness-authored post.
	 *
	 * Anchored at the start of the content on purpose — see the class docblock.
	 * Each was read off the rows actually present on the shared dev site rather
	 * than guessed, so the list describes real harnesses, not imagined ones.
	 *
	 * @var string[]
	 */
	private const POST_PREFIXES = array(
		'e2e ',
		'link-audit ',
		'probe ',
		'UI Path Probe',
		'Probe Space',
	);

	/**
	 * Anchored regexps for harness content a plain prefix cannot express.
	 *
	 * The journey suite writes `j550 site announcement 883006`, `j19 my take …`,
	 * `j512 scheduled …` - the journey number varies, so there is no fixed prefix
	 * to LIKE against. `^j[0-9]+ ` is precise about that shape and matches nothing
	 * a member would plausibly write: a letter j, digits, a space, at position one.
	 *
	 * REGEXP cannot use an index, which is the reason the rest of this command uses
	 * LIKE. It is acceptable here and only here: this is a dev-only command run by
	 * hand on a development database, not a request path.
	 *
	 * @var string[]
	 */
	private const POST_PATTERNS = array( '^j[0-9]+ ' );

	/**
	 * Login prefixes that identify a harness-created account.
	 *
	 * @var string[]
	 */
	private const USER_PREFIXES = array(
		'repro_',
		'probe_',
		'bugfix_',
		'e2e',
		'qa_',
		'rft_',
		'bn_e2e',
		'jt_',
	);

	/**
	 * Profile-field key prefixes a harness may have created.
	 *
	 * Fields are the residue that hurts most: a leftover field shows on EVERY
	 * member's edit form forever, and it is the one kind of pollution that reads
	 * as a product bug rather than as obvious test data.
	 *
	 * @var string[]
	 */
	private const FIELD_PREFIXES = array( 'qa_', 'e2e_', 'probe_', 'test_' );

	/**
	 * Report what a reset would remove, or remove it.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Apply. Without this the command only reports, because it deletes member
	 * content and accounts on a site other people are also using.
	 *
	 * [--dry-run]
	 * : Report only. Implied when --yes is absent; accepted so an explicit dry run
	 * reads clearly in a script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext qa-reset
	 *     wp buddynext qa-reset --yes
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		$dry_run = ! isset( $assoc_args['yes'] ) || isset( $assoc_args['dry-run'] );

		$posts   = $this->harness_posts();
		$users   = $this->harness_users();
		$fields  = $this->harness_fields();
		$primed  = $this->primary_account_residue();
		$orphans = $this->orphan_counts();

		if ( empty( $posts ) && empty( $users ) && empty( $fields ) && empty( $primed ) && 0 === array_sum( $orphans ) ) {
			WP_CLI::success( 'No harness residue found. The site is at baseline.' );
			return;
		}

		// An account that can administer the site is never deleted here, whatever it
		// is named. It is split out and reported instead, because the two ways this
		// happens need a person either way: the pattern is too broad and matched a
		// real admin, or a harness genuinely minted an administrator and left it on a
		// shared site. The second is a security finding, not residue - deleting it
		// quietly would erase the evidence that it happened.
		$admins = array();
		foreach ( $users as $i => $user ) {
			if ( user_can( (int) $user['ID'], 'manage_options' ) ) {
				$admins[] = $user;
				unset( $users[ $i ] );
			}
		}
		$users = array_values( $users );

		WP_CLI::log( sprintf( '%s posts:          %d', $dry_run ? 'Would remove' : 'Removed', count( $posts ) ) );
		WP_CLI::log( sprintf( '%s accounts:       %d', $dry_run ? 'Would remove' : 'Removed', count( $users ) ) );
		WP_CLI::log( sprintf( '%s profile fields: %d', $dry_run ? 'Would remove' : 'Removed', count( $fields ) ) );

		foreach ( $orphans as $table => $count ) {
			if ( $count > 0 ) {
				WP_CLI::log(
					sprintf(
						'%s orphaned %s rows: %d',
						$dry_run ? 'Would remove' : 'Removed',
						$table,
						$count
					)
				);
			}
		}

		foreach ( $fields as $field ) {
			WP_CLI::log( sprintf( '    field #%d %s (%s)', $field['id'], $field['label'], $field['field_key'] ) );
		}

		foreach ( $primed as $meta_key => $value ) {
			WP_CLI::log(
				sprintf(
					'%s test value on the primary account: %s = %s',
					$dry_run ? 'Would clear' : 'Cleared',
					$meta_key,
					$value
				)
			);
		}

		foreach ( $admins as $admin ) {
			WP_CLI::warning(
				sprintf(
					'NOT touching %s (#%d): it matches a harness prefix but can administer this site. Either the pattern is too broad, or a harness created an administrator and left it here - both need a person.',
					$admin['user_login'],
					$admin['ID']
				)
			);
		}

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::success( 'Dry run. Re-run with --yes to apply.' );
			return;
		}

		$this->remove_posts( wp_list_pluck( $posts, 'id' ) );
		$this->remove_users( wp_list_pluck( $users, 'ID' ) );
		$this->remove_fields( wp_list_pluck( $fields, 'id' ) );

		foreach ( array_keys( $primed ) as $meta_key ) {
			delete_user_meta( 1, $meta_key );
		}

		$this->remove_orphans();

		// Counters last, once every membership row is actually gone.
		QaFixturesCommand::recount_spaces();

		WP_CLI::success( 'Harness residue removed. Space counters recomputed.' );
	}

	/**
	 * A SQL fragment matching any of a set of prefixes on a column.
	 *
	 * `LIKE 'prefix%'` per term rather than one REGEXP: LIKE uses an index where a
	 * REGEXP cannot, and the point here is an anchored prefix, which is exactly
	 * what LIKE expresses.
	 *
	 * @param string   $column   Column name (never from input).
	 * @param string[] $prefixes Prefixes to match.
	 * @return string SQL fragment, already escaped.
	 */
	private function prefix_clause( string $column, array $prefixes ): string {
		global $wpdb;

		$parts = array();
		foreach ( $prefixes as $prefix ) {
			$parts[] = $wpdb->prepare( "{$column} LIKE %s", $wpdb->esc_like( $prefix ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Posts whose content begins with a harness prefix.
	 *
	 * @return array<int,array{id:int}>
	 */
	private function harness_posts(): array {
		global $wpdb;

		$clauses = array( $this->prefix_clause( 'content', self::POST_PREFIXES ) );

		foreach ( self::POST_PATTERNS as $pattern ) {
			$clauses[] = $wpdb->prepare( 'content REGEXP %s', $pattern );
		}

		$where = '(' . implode( ' OR ', $clauses ) . ')';
		$sql   = "SELECT id FROM {$wpdb->prefix}bn_posts WHERE {$where}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Accounts whose login begins with a harness prefix. Never user 1.
	 *
	 * @return array<int,array{ID:int,user_login:string}>
	 */
	private function harness_users(): array {
		global $wpdb;

		$where = $this->prefix_clause( 'user_login', self::USER_PREFIXES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT ID, user_login FROM {$wpdb->users} WHERE ID <> 1 AND {$where}", ARRAY_A );
	}

	/**
	 * Non-system profile fields whose key begins with a harness prefix.
	 *
	 * `is_system = 0` is a second guard, not decoration: a seeded field carries
	 * is_system, so even a pattern that went wrong cannot take one out.
	 *
	 * @return array<int,array{id:int,label:string,field_key:string}>
	 */
	private function harness_fields(): array {
		global $wpdb;

		$where = $this->prefix_clause( 'field_key', self::FIELD_PREFIXES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT id, label, field_key FROM {$wpdb->prefix}bn_profile_fields WHERE is_system = 0 AND {$where}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Known harness values written onto the primary account.
	 *
	 * Deleted, never pattern-matched: user 1 is a real account, and guessing which
	 * of its values are test data is not a guess worth making. Only the exact
	 * strings a spec is known to write are listed.
	 *
	 * @return array<string,string> meta key => current value.
	 */
	private function primary_account_residue(): array {
		$known = array(
			'bn_headline' => 'quota headline',
			'bn_bio'      => 'quota bio',
			'bn_location' => 'quota loc',
			'bn_website'  => 'quota.example.com',
		);

		$found = array();
		foreach ( $known as $meta_key => $value ) {
			if ( (string) get_user_meta( 1, $meta_key, true ) === $value ) {
				$found[ $meta_key ] = $value;
			}
		}

		return $found;
	}

	/**
	 * Engagement rows whose post no longer exists.
	 *
	 * These are not this command's doing - they were already here, left by earlier
	 * ad-hoc cleanups that deleted a post with a raw DELETE and never touched what
	 * hung off it. That is the same mistake `qa-fixtures cleanup` was written to
	 * avoid, and the rows are unambiguously dead: a comment on a post that does not
	 * exist can never be shown, edited or recovered.
	 *
	 * Swept here because the point of the command is a baseline you can trust, and
	 * a hundred undeletable comments is not that.
	 *
	 * @return array<string,int> table (unprefixed) => orphan count.
	 */
	private function orphan_counts(): array {
		global $wpdb;

		$counts = array();

		foreach ( $this->orphan_queries() as $table => $where ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} t WHERE {$where}" );
		}

		return $counts;
	}

	/**
	 * Delete the orphaned engagement rows.
	 *
	 * @return void
	 */
	private function remove_orphans(): void {
		global $wpdb;

		foreach ( $this->orphan_queries() as $table => $where ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE t FROM {$wpdb->prefix}{$table} t WHERE {$where}" );
		}
	}

	/**
	 * The orphan predicate per table.
	 *
	 * One definition so the count and the delete can never disagree - reporting a
	 * number the delete then does not match is its own bug.
	 *
	 * Scoped to object_type = 'post' where the table is polymorphic: a reaction on
	 * a COMMENT is not orphaned by a missing post, and sweeping it would delete
	 * live data.
	 *
	 * @return array<string,string> table (unprefixed) => WHERE fragment.
	 */
	private function orphan_queries(): array {
		global $wpdb;

		$post_gone = "NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}bn_posts p WHERE p.id = t.object_id )";

		$comment_gone = "NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}bn_comments c WHERE c.id = t.object_id )";

		return array(
			'bn_comments'  => "t.object_type = 'post' AND {$post_gone}",
			'bn_reactions' => "t.object_type = 'post' AND {$post_gone}",
			'bn_bookmarks' => "NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}bn_posts p WHERE p.id = t.post_id )",
			// A report whose target no longer exists cannot be acted on: the queue
			// renders it as "Deleted comment (#id)" with no View link and no way to
			// judge it, and it sits there forever pushing live reports off page one.
			// Sweeping the comments above without this leaves exactly that behind -
			// found by doing it and then trying to use the Reports screen.
			'bn_reports'   => "( ( t.object_type = 'post' AND {$post_gone} ) OR ( t.object_type = 'comment' AND {$comment_gone} ) )",
		);
	}

	/**
	 * Delete posts and the engagement hanging off them.
	 *
	 * @param int[] $ids Post ids.
	 * @return void
	 */
	private function remove_posts( array $ids ): void {
		global $wpdb;

		$ids = array_map( 'intval', $ids );
		if ( empty( $ids ) ) {
			return;
		}

		// Chunked so a site with thousands of harness rows does not build one
		// enormous IN() list.
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_comments  WHERE object_type = 'post' AND object_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_reactions WHERE object_type = 'post' AND object_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_bookmarks WHERE post_id IN ({$in})" );
			// A reshare of a harness post is harness residue too, and leaving it
			// behind produces exactly the dead "original post is no longer
			// available" card the feed is already criticised for.
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_posts     WHERE shared_post_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_posts     WHERE id IN ({$in})" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Delete accounts through core, so every reassign/cleanup hook fires.
	 *
	 * @param int[] $ids User ids.
	 * @return void
	 */
	private function remove_users( array $ids ): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 1 ) {
				wp_delete_user( $id );
			}
		}
	}

	/**
	 * Delete profile fields and every stored value for them.
	 *
	 * @param int[] $ids Field ids.
	 * @return void
	 */
	private function remove_fields( array $ids ): void {
		global $wpdb;

		$ids = array_map( 'intval', $ids );
		if ( empty( $ids ) ) {
			return;
		}

		$in = implode( ',', $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_profile_values WHERE field_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_profile_fields WHERE id IN ({$in})" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
