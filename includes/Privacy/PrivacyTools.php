<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WordPress Privacy Tools integration (export + erase).
 *
 * WHY THIS EXISTS
 * ───────────────
 * BuddyNext stores all of its social-graph, content, and per-user settings in
 * custom tables ({prefix}bn_*) and in bn_* user meta. WordPress core's
 * Tools → Export/Erase Personal Data screens know nothing about these, so an
 * administrator could not fulfil a GDPR/CCPA export or erasure request for any
 * BuddyNext data. This class plugs that gap by registering one personal-data
 * exporter and one eraser through the standard core filters
 * (`wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers`),
 * so BuddyNext data is covered by the same admin workflow as everything else.
 *
 * WHAT IT COVERS
 * ──────────────
 * Tables keyed by the user (verified against Core\Installer schema):
 *   - bn_posts            (user_id)                     authored feed posts
 *   - bn_comments         (user_id)                     authored comments
 *   - bn_follows          (follower_id / following_id)  follow graph (both ways)
 *   - bn_connections      (requester_id / recipient_id) connection requests
 *   - bn_blocks           (blocker_id / blocked_id)     blocks/mutes (both ways)
 *   - bn_space_members    (user_id)                     space memberships
 *   - bn_notifications    (recipient_id / sender_id)    notifications (both ways)
 *   - bn_notification_prefs (user_id)                   per-type notify prefs
 *   - bn_hashtag_follows  (user_id)                     followed hashtags
 *   - bn_profile_values   (user_id)                     extended profile fields
 * Plus every bn_* row in {prefix}usermeta for the user (discovered dynamically
 * so the key list never drifts as features add meta).
 *
 * ERASURE STRATEGY (the SAME canonical hard-delete the account-delete path uses)
 * ─────────────────────────────────────────────────────────────────────
 * Full GDPR erasure - everything tied to the member is HARD-DELETED via
 * MemberCleanupService::purge_user_relations(): their posts (cascading each post's child
 * rows), their comments, every relational row, and all bn_* user meta. There is no
 * anonymise / keep-the-thread path - deleting a member removes the person AND their
 * content, the same uniform policy on every delete path.
 *
 * DEFERRED (not owned by BuddyNext)
 * ─────────────────────────────────
 * Direct messages and uploaded media live in WPMediaVerse tables (wp_mvs_*),
 * consumed by BuddyNext only via WPMediaVerseBridge. Those must be exported /
 * erased by WPMediaVerse's own privacy integration and are intentionally NOT
 * touched here.
 *
 * @package BuddyNext\Privacy
 */

declare( strict_types=1 );

namespace BuddyNext\Privacy;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers and implements the BuddyNext personal-data exporter and eraser.
 */
class PrivacyTools implements ListenerInterface {

	/**
	 * Stable identifier used for both the exporter and eraser registration.
	 */
	private const ID = 'buddynext';

	/**
	 * Rows processed per page for the paginated export/erase contract.
	 */
	private const PER_PAGE = 100;

	/**
	 * Register the WordPress privacy hooks.
	 *
	 * Registered unconditionally: the admin Tools → Export/Erase Personal Data
	 * workflow is a compliance obligation that must work regardless of whether
	 * the optional front-end self-service toggles are enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Contribute suggested privacy-policy text to WordPress' Privacy Policy Guide
	 * (Settings → Privacy → Policies), so a site owner writing their policy sees
	 * what BuddyNext stores. Community platforms process substantial personal
	 * data, so appearing here (like WordPress core and other plugins) is expected.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'This community runs on BuddyNext. When you register and take part, BuddyNext stores the profile details you provide, the posts, comments, reactions, and polls you create, your follows and connections, the spaces you join, direct messages you send, and community notifications. It also records limited technical data such as IP addresses for spam protection and moderation.', 'buddynext' ) . '</p>'
			. '<p>' . esc_html__( 'You can download a copy of your data or request deletion of your account at any time from your account settings, subject to the tools your site owner has enabled. Financial records tied to purchases may be retained where the law requires it.', 'buddynext' ) . '</p>';

		wp_add_privacy_policy_content( __( 'BuddyNext', 'buddynext' ), $content );
	}

	/**
	 * Add the BuddyNext exporter to the core exporters list.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'BuddyNext', 'buddynext' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the BuddyNext eraser to the core erasers list.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'BuddyNext', 'buddynext' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Exporter
	 * ────────────────────────────────────────────────────────────────────
	 */

	/**
	 * Export a user's BuddyNext data, paginated by the core contract.
	 *
	 * Page 1 carries the genuinely bounded sets — the ones whose size is fixed by the site's
	 * CONFIGURATION, not by the member's activity: their profile, their profile-field values, their
	 * notification preferences. Everything that grows with a member is a STREAMED SECTION, and the
	 * pages after that walk those sections in order.
	 *
	 * That distinction is the whole fix. Page 1 used to call itself "all the bounded sets in one
	 * shot" and then load the member's entire social graph into it — every follow in both
	 * directions, every connection, every block. None of those are bounded by anything: you do not
	 * choose who follows you. A member with 100k followers OOM'd the export request.
	 *
	 * "Realistically small" is not bounded. It is the assumption that produced this bug, and the
	 * line here is drawn on the only durable criterion: does this set grow when the member is
	 * popular or busy? Then it streams.
	 *
	 * @param string $email_address Email of the user being exported.
	 * @param int    $page          1-based page number.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$page = max( 1, (int) $page );
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id = (int) $user->ID;
		$items   = array();

		// The config-bounded sets ride along on page 1: a member has as many profile-field values
		// as the site has profile fields, and as many notification preferences as there are
		// notification types. Neither grows with how much they use the community.
		if ( 1 === $page ) {
			$items = array_merge(
				$this->export_profile_meta( $user_id ),
				$this->export_profile_values( $user_id ),
				$this->export_notification_prefs( $user_id )
			);
		}

		$stream = $this->stream_page( $user_id, $page );

		return array(
			'data' => array_merge( $items, $stream['items'] ),
			'done' => $stream['done'],
		);
	}

	/**
	 * THE EXPORT SECTION REGISTRY — everything that grows with the member.
	 *
	 * Each section knows how to count itself and how to fetch ONE page of itself. The exporter
	 * concatenates them into a single stream and walks it, so no section can be "the one nobody
	 * remembered to page". Adding a section here is what makes it exportable, countable, and
	 * finishable, in one place.
	 *
	 * Order is stable and deliberate: the graph first (small pages, quick to produce), the member's
	 * content last (the biggest sets, and the ones a member is most likely to abandon the download
	 * over). It only matters in that it must not change mid-export.
	 *
	 * @return array<string, array{count: callable, fetch: callable}>
	 */
	private function sections(): array {
		return array(
			'following'       => array(
				'count' => fn( int $u ): int => $this->count_rows( 'bn_follows', 'follower_id = %d', $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_following( $u, $l, $o ),
			),
			'followers'       => array(
				'count' => fn( int $u ): int => $this->count_rows( 'bn_follows', 'following_id = %d', $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_followers( $u, $l, $o ),
			),
			'connections'     => array(
				'count' => fn( int $u ): int => $this->count_rows( 'bn_connections', 'requester_id = %d OR recipient_id = %d', $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_connections( $u, $l, $o ),
			),
			'blocks'          => array(
				'count' => fn( int $u ): int => $this->count_rows( 'bn_blocks', 'blocker_id = %d', $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_blocks( $u, $l, $o ),
			),
			'spaces'          => array(
				'count' => fn( int $u ): int => $this->count_rows( 'bn_space_members', 'user_id = %d', $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_space_members( $u, $l, $o ),
			),
			'hashtag_follows' => array(
				'count' => fn( int $u ): int => $this->count_rows( 'bn_hashtag_follows', 'user_id = %d', $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_hashtag_follows( $u, $l, $o ),
			),
			'notifications'   => array(
				'count' => fn( int $u ): int => $this->count_rows( 'bn_notifications', 'recipient_id = %d', $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_notifications( $u, $l, $o ),
			),
			'posts'           => array(
				'count' => fn( int $u ): int => $this->count_posts( $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_posts( $u, $l, $o ),
			),
			'comments'        => array(
				'count' => fn( int $u ): int => $this->count_comments( $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_comments( $u, $l, $o ),
			),
		);
	}

	/**
	 * Serve page N of the concatenated section stream, and say whether that was the last one.
	 *
	 * `done` is computed from the counts, never assumed — the same rule the eraser had to learn.
	 * Core takes it at its word: when we say done it zips the file and emails the member to tell
	 * them this is everything we hold on them. Saying it early is how a partial export goes out
	 * wearing the label of a complete one.
	 *
	 * @param int $user_id Member.
	 * @param int $page    1-based page across the whole stream.
	 * @return array{items: array<int,array<string,mixed>>, done: bool}
	 */
	/**
	 * Tables we erase but deliberately do NOT hand back, each with its reason.
	 *
	 * A table on this list is a judgement that was MADE. A table on neither this list nor the
	 * export stream is a judgement that was never made — which is the entire condition the
	 * erasure gate exists to fail the build on, and it now checks export too.
	 *
	 * Keep this list short and keep the reasons real. "It felt like a lot of work" is not a
	 * reason; Article 15 does not have a convenience exemption.
	 *
	 * @return array<string,string> Unprefixed table => why it is not exported.
	 */
	public static function export_exclusions(): array {
		$exclusions = array(
			// Live credentials. A valid token sitting in a ZIP the member emails to themselves
			// is an account-takeover vector, and it tells them nothing about themselves that
			// the account itself does not. Excluding a secret is not withholding personal data.
			'bn_verify_tokens' => 'Live security tokens. Exporting a valid credential into a downloadable archive is a takeover vector, and it carries no information about the member.',

			// A derived mirror of content that IS exported (posts, comments, profile values).
			// Handing back the index as well would duplicate every row under a second heading
			// and tell the member nothing new.
			'bn_search_index'  => 'Derived search mirror of content already exported in full (posts, comments, profile values). Exporting it would duplicate every row, not add one.',
		);

		/**
		 * Filter the tables that are erased but deliberately not exported.
		 *
		 * Pro adds its own. Each entry MUST carry a human reason: the export gate fails the
		 * build on a table that is on neither the export stream nor this list, and a blank
		 * reason is how "nobody decided" gets dressed up as "we decided not to".
		 *
		 * @since 1.0.8
		 *
		 * @param array<string,string> $exclusions Unprefixed table => reason.
		 */
		return (array) apply_filters( 'buddynext_privacy_export_exclusions', $exclusions );
	}

	/**
	 * Columns that are never emitted, even from a table we do export.
	 *
	 * The row is personal data; the secret inside it is not something to hand back. A push
	 * token says "this member registered an Android device on this date", which they have a
	 * right to know — the token VALUE is a credential for pushing to that device, which they
	 * do not need and nobody else should have.
	 *
	 * @return array<string,string[]> Unprefixed table => column names to omit.
	 */
	public static function export_redactions(): array {
		$redactions = array(
			'bn_push_tokens' => array( 'token' ),
		);

		/**
		 * Filter the columns omitted from an otherwise-exported table.
		 *
		 * @since 1.0.8
		 *
		 * @param array<string,string[]> $redactions Unprefixed table => columns to omit.
		 */
		return (array) apply_filters( 'buddynext_privacy_export_redactions', $redactions );
	}

	/**
	 * Sections generated from the erase registry — everything not exported by hand.
	 *
	 * THE POINT: export coverage is now DERIVED from the same registry that drives erasure,
	 * instead of being a second hand-written list that has to be remembered. It was a second
	 * hand-written list, and it was 25 tables behind — every one of them a table we happily
	 * DELETE on request while being unable to SHOW it on request. Erasure (Art. 17) was
	 * complete and access (Art. 15) was not, which is a strange pair of failures to have: we
	 * knew exactly where the member's data was, and used that knowledge only to destroy it.
	 *
	 * Adding a table to erase_map() now makes it exportable automatically. Nothing to
	 * remember, so nothing to forget.
	 *
	 * @return array<string, array{count: callable, fetch: callable}>
	 */
	private function derived_sections(): array {
		$handled    = $this->tables_exported_by_hand();
		$exclusions = self::export_exclusions();
		$sections   = array();

		foreach ( \BuddyNext\Profile\MemberCleanupService::erase_map() as $table => $spec ) {
			if ( isset( $handled[ $table ] ) || isset( $exclusions[ $table ] ) ) {
				continue;
			}

			$where = (string) ( $spec['where'] ?? '' );
			if ( '' === $where ) {
				continue;
			}

			$sections[ $table ] = array(
				'count' => fn( int $u ): int => $this->count_rows( $table, $where, $u ),
				'fetch' => fn( int $u, int $l, int $o ): array => $this->export_table( $table, $where, $u, $l, $o ),
			);
		}

		return $sections;
	}

	/**
	 * Tables covered by the hand-written sections (and the page-1 items).
	 *
	 * These predate the derived stream and produce nicer, human-labelled output, so they are
	 * kept. This list is what stops a table being exported twice.
	 *
	 * @return array<string,true>
	 */
	private function tables_exported_by_hand(): array {
		return array(
			'bn_follows'            => true,
			'bn_connections'        => true,
			'bn_blocks'             => true,
			'bn_space_members'      => true,
			'bn_hashtag_follows'    => true,
			'bn_notifications'      => true,
			'bn_posts'              => true,
			'bn_comments'           => true,
			'bn_profile_values'     => true,
			'bn_notification_prefs' => true,
		);
	}

	/**
	 * Fetch one page of a table generically, as export items.
	 *
	 * @param string $table   Unprefixed table (registry-owned, never input).
	 * @param string $where   Predicate; each %d is bound to the user id.
	 * @param int    $user_id Member.
	 * @param int    $limit   Page size.
	 * @param int    $offset  Page offset.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_table( string $table, string $where, int $user_id, int $limit, int $offset ): array {
		global $wpdb;

		$args = array_fill( 0, max( 1, substr_count( $where, '%d' ) ), $user_id );
		$args = array_merge( $args, array( $limit, $offset ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table and $where are registry-owned constants, never input; every value IS bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}{$table} WHERE " . $where . ' LIMIT %d OFFSET %d',
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$redact = (array) ( self::export_redactions()[ $table ] ?? array() );
		$items  = array();

		foreach ( (array) $rows as $row ) {
			$data = array();

			foreach ( (array) $row as $column => $value ) {
				if ( in_array( $column, $redact, true ) ) {
					continue;
				}

				$data[] = array(
					'name'  => ucwords( str_replace( '_', ' ', (string) $column ) ),
					'value' => is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value ),
				);
			}

			$items[] = array(
				'group_id'    => $table,
				'group_label' => $this->table_label( $table ),
				'item_id'     => $table . '-' . ( isset( $row['id'] ) ? (int) $row['id'] : md5( (string) wp_json_encode( $row ) ) ),
				'data'        => $data,
			);
		}

		return $items;
	}

	/**
	 * A human label for a derived table's export group.
	 *
	 * @param string $table Unprefixed table.
	 * @return string
	 */
	private function table_label( string $table ): string {
		$label = ucwords( str_replace( '_', ' ', preg_replace( '/^bn_/', '', $table ) ?? $table ) );

		/**
		 * Filter the export group label for a derived table.
		 *
		 * Pro's tables get their names through this, so Free never has to know them.
		 *
		 * @since 1.0.8
		 *
		 * @param string $label Human label.
		 * @param string $table Unprefixed table name.
		 */
		return (string) apply_filters( 'buddynext_privacy_export_table_label', $label, $table );
	}

	/**
	 * Walk one page across the whole concatenated section stream.
	 *
	 * @param int $user_id Member.
	 * @param int $page    1-based page across the whole stream.
	 * @return array{items: array<int,array<string,mixed>>, done: bool}
	 */
	private function stream_page( int $user_id, int $page ): array {
		$per   = $this->per_page();
		$plan  = array();
		$total = 0;

		// The hand-written sections first, then everything derived from the erase registry.
		// A table added to erase_map() joins the stream automatically — it cannot be
		// forgotten, because nobody has to remember it.
		$all_sections = array_merge( $this->sections(), $this->derived_sections() );

		foreach ( $all_sections as $section ) {
			$pages = (int) ceil( ( (int) ( $section['count'] )( $user_id ) ) / $per );
			if ( $pages > 0 ) {
				$plan[] = array(
					'fetch' => $section['fetch'],
					'pages' => $pages,
				);
				$total += $pages;
			}
		}

		if ( 0 === $total || $page > $total ) {
			return array(
				'items' => array(),
				'done'  => true,
			);
		}

		$cursor = $page;
		foreach ( $plan as $entry ) {
			if ( $cursor <= $entry['pages'] ) {
				return array(
					'items' => (array) ( $entry['fetch'] )( $user_id, $per, ( $cursor - 1 ) * $per ),
					'done'  => ( $page >= $total ),
				);
			}
			$cursor -= $entry['pages'];
		}

		return array(
			'items' => array(),
			'done'  => true,
		);
	}

	/**
	 * Rows per export page.
	 *
	 * @return int
	 */
	private function per_page(): int {
		/**
		 * How many rows of one section to put in a single export page.
		 *
		 * @param int $per_page Default 100.
		 */
		return max( 1, (int) apply_filters( 'buddynext_export_per_page', self::PER_PAGE ) );
	}

	/**
	 * COUNT(*) one section — never count( fetch_everything() ).
	 *
	 * @param string $table Unprefixed table.
	 * @param string $where Predicate; each %d is bound to the user id.
	 * @param int    $user_id Member.
	 * @return int
	 */
	private function count_rows( string $table, string $where, int $user_id ): int {
		global $wpdb;

		$args = array_fill( 0, max( 1, substr_count( $where, '%d' ) ), $user_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table and $where are registry-owned constants, never input; the id IS bound via prepare().
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE " . $where,
				...$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $count;
	}

	/**
	 * Export the user's bn_* user meta as a single grouped item.
	 *
	 * Keys are discovered dynamically (LIKE 'bn\_%') so the export never falls
	 * out of sync with features that add new meta. Internal volatile keys
	 * (rate-limit counters, transient-style state) are skipped.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_profile_meta( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s ORDER BY meta_key",
				$user_id,
				$wpdb->esc_like( 'bn_' ) . '%'
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];

			if ( $this->is_skippable_meta_key( $key ) ) {
				continue;
			}

			// Do NOT use maybe_unserialize(): usermeta values can be
			// attacker-influenced, and unserializing a crafted object payload
			// risks PHP object injection. allowed_classes => false deserializes
			// arrays (so they still export as structured JSON) but never
			// instantiates an object gadget.
			$value = $row['meta_value'];
			if ( is_serialized( $value ) ) {
				$value = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes => false blocks object injection; deliberately not maybe_unserialize() on attacker-influenced usermeta.
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$data[] = array(
				'name'  => $key,
				'value' => (string) $value,
			);
		}

		if ( empty( $data ) ) {
			return array();
		}

		return array(
			array(
				'group_id'    => 'buddynext_profile',
				'group_label' => __( 'BuddyNext Profile & Settings', 'buddynext' ),
				'item_id'     => 'buddynext-profile-' . $user_id,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export the user's extended profile field values (bn_profile_values).
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_profile_values( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.field_id, v.value, f.label
				 FROM {$p}bn_profile_values v
				 LEFT JOIN {$p}bn_profile_fields f ON f.id = v.field_id
				 WHERE v.user_id = %d
				 ORDER BY v.field_id, v.entry_index",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$label  = '' !== (string) $row['label'] ? (string) $row['label'] : ( 'field #' . (int) $row['field_id'] );
			$data[] = array(
				'name'  => $label,
				'value' => (string) $row['value'],
			);
		}

		return array(
			array(
				'group_id'    => 'buddynext_profile_fields',
				'group_label' => __( 'BuddyNext Profile Fields', 'buddynext' ),
				'item_id'     => 'buddynext-profile-fields-' . $user_id,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export one page of the members this user follows.
	 *
	 * Ordered by the primary key so the pages tile the set exactly once — an unordered LIMIT/OFFSET
	 * is free to return the same row on two pages and skip another entirely, which on a
	 * subject-access request means quietly handing the member someone else's absence.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_following( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT following_id, status, created_at FROM {$p}bn_follows WHERE follower_id = %d ORDER BY following_id ASC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( (array) $rows as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_following',
				'group_label' => __( 'BuddyNext Following', 'buddynext' ),
				// Keyed off the OFFSET, not the position within this page: $i restarts at 0 on
				// every page, so page 2 would reissue page 1's item_ids.
				'item_id'     => 'buddynext-following-' . $user_id . '-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => __( 'Following user', 'buddynext' ),
						'value' => $this->user_label( (int) $row['following_id'] ),
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Since', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export one page of the members who follow this user.
	 *
	 * Followers are the relation with no ceiling — you do not choose who follows you — so this is
	 * the section most likely to be enormous, and the one that used to be fetched whole.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_followers( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT follower_id, status, created_at FROM {$p}bn_follows WHERE following_id = %d ORDER BY follower_id ASC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( (array) $rows as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_followers',
				'group_label' => __( 'BuddyNext Followers', 'buddynext' ),
				'item_id'     => 'buddynext-follower-' . $user_id . '-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => __( 'Follower', 'buddynext' ),
						'value' => $this->user_label( (int) $row['follower_id'] ),
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Since', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the user's connection requests (both directions).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_connections( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT requester_id, recipient_id, status, note, created_at FROM {$p}bn_connections WHERE requester_id = %d OR recipient_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( $rows as $i => $row ) {
			$other     = (int) $row['requester_id'] === $user_id ? (int) $row['recipient_id'] : (int) $row['requester_id'];
			$direction = (int) $row['requester_id'] === $user_id ? __( 'Sent', 'buddynext' ) : __( 'Received', 'buddynext' );

			$items[] = array(
				'group_id'    => 'buddynext_connections',
				'group_label' => __( 'BuddyNext Connections', 'buddynext' ),
				'item_id'     => 'buddynext-connection-' . $user_id . '-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => __( 'Member', 'buddynext' ),
						'value' => $this->user_label( $other ),
					),
					array(
						'name'  => __( 'Direction', 'buddynext' ),
						'value' => $direction,
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Note', 'buddynext' ),
						'value' => (string) $row['note'],
					),
					array(
						'name'  => __( 'Requested', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the user's blocks/mutes (the ones they created).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_blocks( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blocked_id, type, created_at FROM {$p}bn_blocks WHERE blocker_id = %d ORDER BY blocked_id ASC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( $rows as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_blocks',
				'group_label' => __( 'BuddyNext Blocked Members', 'buddynext' ),
				'item_id'     => 'buddynext-block-' . $user_id . '-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => __( 'Member', 'buddynext' ),
						'value' => $this->user_label( (int) $row['blocked_id'] ),
					),
					array(
						'name'  => __( 'Type', 'buddynext' ),
						'value' => (string) $row['type'],
					),
					array(
						'name'  => __( 'Since', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the user's space memberships.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_space_members( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.space_id, m.role, m.status, m.joined_at, s.name
				 FROM {$p}bn_space_members m
				 LEFT JOIN {$p}bn_spaces s ON s.id = m.space_id
				 WHERE m.user_id = %d
				 ORDER BY m.space_id ASC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( $rows as $i => $row ) {
			$name    = '' !== (string) $row['name'] ? (string) $row['name'] : ( '#' . (int) $row['space_id'] );
			$items[] = array(
				'group_id'    => 'buddynext_spaces',
				'group_label' => __( 'BuddyNext Space Memberships', 'buddynext' ),
				'item_id'     => 'buddynext-space-' . $user_id . '-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => __( 'Space', 'buddynext' ),
						'value' => $name,
					),
					array(
						'name'  => __( 'Role', 'buddynext' ),
						'value' => (string) $row['role'],
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Joined', 'buddynext' ),
						'value' => (string) $row['joined_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export the hashtags the user follows.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_hashtag_follows( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.name, hf.created_at
				 FROM {$p}bn_hashtag_follows hf
				 LEFT JOIN {$p}bn_hashtags h ON h.id = hf.hashtag_id
				 WHERE hf.user_id = %d
				 ORDER BY hf.hashtag_id ASC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'name'  => '#' . (string) $row['name'],
				'value' => (string) $row['created_at'],
			);
		}

		return array(
			array(
				'group_id'    => 'buddynext_hashtag_follows',
				'group_label' => __( 'BuddyNext Followed Hashtags', 'buddynext' ),
				'item_id'     => 'buddynext-hashtag-follows-' . $user_id . '-' . $offset,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export the user's per-type notification preferences.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_notification_prefs( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, on_site, email_freq FROM {$p}bn_notification_prefs WHERE user_id = %d ORDER BY type",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'name'  => (string) $row['type'],
				'value' => sprintf(
					/* translators: 1: on-site flag, 2: email frequency. */
					__( 'on-site: %1$s, email: %2$s', 'buddynext' ),
					$row['on_site'] ? __( 'yes', 'buddynext' ) : __( 'no', 'buddynext' ),
					(string) $row['email_freq']
				),
			);
		}

		return array(
			array(
				'group_id'    => 'buddynext_notification_prefs',
				'group_label' => __( 'BuddyNext Notification Preferences', 'buddynext' ),
				'item_id'     => 'buddynext-notification-prefs-' . $user_id,
				'data'        => $data,
			),
		);
	}

	/**
	 * Export the notifications addressed to the user (bounded summary).
	 *
	 * Only notifications the user received are exported as personal data; the
	 * outgoing (sender_id) rows are someone else's bell items and are excluded
	 * from the export to avoid leaking third-party recipients.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_notifications( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// This used to end in `ORDER BY created_at DESC LIMIT 500` — a hard cap, with no paging
		// behind it and no disclosure in front of it. A member with 5,000 notifications was handed
		// the most recent 500 and told the export was complete.
		//
		// A cap is the right instinct for a SCREEN. On a subject-access request it is the wrong
		// one: the answer to "too much data to send at once" is to send it in PAGES, never to send
		// less of it. Silently returning a subset of someone's data while calling it their data is
		// the export-side twin of the eraser hard-coding `done => true`.
		//
		// Ordered by id, not created_at: two notifications can share a timestamp to the second, and
		// an unstable sort makes LIMIT/OFFSET pages overlap and skip.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, object_type, object_id, is_read, created_at FROM {$p}bn_notifications WHERE recipient_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( $rows as $i => $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_notifications',
				'group_label' => __( 'BuddyNext Notifications', 'buddynext' ),
				'item_id'     => 'buddynext-notification-' . $user_id . '-' . ( $offset + (int) $i ),
				'data'        => array(
					array(
						'name'  => __( 'Type', 'buddynext' ),
						'value' => (string) $row['type'],
					),
					array(
						'name'  => __( 'Object', 'buddynext' ),
						'value' => trim( (string) $row['object_type'] . ' ' . (string) $row['object_id'] ),
					),
					array(
						'name'  => __( 'Read', 'buddynext' ),
						'value' => $row['is_read'] ? __( 'yes', 'buddynext' ) : __( 'no', 'buddynext' ),
					),
					array(
						'name'  => __( 'Received', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export one page of the user's authored posts.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_posts( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, content, link_url, privacy, status, created_at FROM {$p}bn_posts WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_posts',
				'group_label' => __( 'BuddyNext Posts', 'buddynext' ),
				'item_id'     => 'buddynext-post-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Type', 'buddynext' ),
						'value' => (string) $row['type'],
					),
					array(
						'name'  => __( 'Content', 'buddynext' ),
						'value' => (string) $row['content'],
					),
					array(
						'name'  => __( 'Link', 'buddynext' ),
						'value' => (string) $row['link_url'],
					),
					array(
						'name'  => __( 'Privacy', 'buddynext' ),
						'value' => (string) $row['privacy'],
					),
					array(
						'name'  => __( 'Status', 'buddynext' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Posted', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export one page of the user's authored comments.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per page.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	private function export_comments( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $p is $wpdb->prefix; all user input bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_type, object_id, content, is_deleted, created_at FROM {$p}bn_comments WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'buddynext_comments',
				'group_label' => __( 'BuddyNext Comments', 'buddynext' ),
				'item_id'     => 'buddynext-comment-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'On', 'buddynext' ),
						'value' => trim( (string) $row['object_type'] . ' ' . (string) $row['object_id'] ),
					),
					array(
						'name'  => __( 'Comment', 'buddynext' ),
						'value' => (string) $row['content'],
					),
					array(
						'name'  => __( 'Deleted', 'buddynext' ),
						'value' => $row['is_deleted'] ? __( 'yes', 'buddynext' ) : __( 'no', 'buddynext' ),
					),
					array(
						'name'  => __( 'Posted', 'buddynext' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return $items;
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Eraser
	 * ────────────────────────────────────────────────────────────────────
	 */

	/**
	 * Erase a user's BuddyNext data, paginated by the core contract.
	 *
	 * Page 1 removes all the bounded, per-user sets (meta, profile values,
	 * relational graph, memberships, notifications) and erases the first page
	 * of posts/comments. Subsequent pages continue erasing posts/comments until
	 * none remain, so a prolific member's content is removed across requests.
	 *
	 * @param string $email_address Email of the user being erased.
	 * @param int    $page          1-based page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		unset( $page ); // Progress is derived from what is left in the tables, not from a page number.
		$user = get_user_by( 'email', $email_address );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// Full GDPR erasure: the canonical purge HARD-DELETES every user-keyed table INCLUDING the
		// member's posts (cascading each post's child rows) and their comments. There is no
		// anonymise/keep-the-thread path — deleting a member removes the person AND their content,
		// the same policy account-delete uses.
		//
		// CORE'S PAGING LOOP IS THE CHUNKING DRIVER, AND IT IS FREE.
		//
		// WP calls this method over and over — each call its own HTTP request, with a fresh time
		// and memory budget — until we report done. Only then does it zip the export, or tell the
		// member their data has been erased.
		//
		// This used to `unset( $page )` and hard-code `'done' => true`, attempting the entire
		// erasure in one pass. On a large member that request dies; and if it dies, core has still
		// been told nothing is left to do. Worse, when it did NOT die but ran out of time, core
		// reported a completed erasure over data that was still in the database. For a compliance
		// path that is the one lie you cannot tell.
		//
		// So `done` is now derived from the tables themselves — the purge's own verifier — and not
		// asserted. If anything of the member remains, we say so and core calls us again.
		$cleanup = new \BuddyNext\Profile\MemberCleanupService();
		$removed = $cleanup->purge_user_relations( (int) $user->ID, 'gdpr-erase' );
		$residue = array_filter( $cleanup->residue( (int) $user->ID ) );

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => array() === $residue,
		);
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Counting / pagination helpers
	 * ────────────────────────────────────────────────────────────────────
	 */

	/**
	 * Count the user's authored posts.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function count_posts( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d", $user_id ) );
	}

	/**
	 * Count the user's authored comments.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function count_comments( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE user_id = %d", $user_id ) );
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Misc helpers
	 * ────────────────────────────────────────────────────────────────────
	 */

	/**
	 * Human-readable label for a related member (display name + id fallback).
	 *
	 * @param int $user_id Related user id.
	 * @return string
	 */
	private function user_label( int $user_id ): string {
		$u = get_userdata( $user_id );
		if ( $u instanceof \WP_User ) {
			return $u->display_name . ' (#' . $user_id . ')';
		}

		/* translators: %d: user id of an account that no longer exists. */
		return sprintf( __( 'Deleted member (#%d)', 'buddynext' ), $user_id );
	}

	/**
	 * Whether a bn_* user-meta key holds volatile/internal state we skip on
	 * export (rate-limit counters, OAuth nonces, transient-style flags).
	 *
	 * These are not meaningful personal data in an export; they are still
	 * deleted by the eraser via the broad LIKE 'bn\_%' sweep.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function is_skippable_meta_key( string $key ): bool {
		$prefixes = array(
			'bn_oauth_rl_',
			'bn_reg_rl_',
			'bn_social_state_',
			'bn_session_',
			'bn_presence_',
			'bn_2fa_secret',
			'bn_2fa_pending_secret',
		);

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
