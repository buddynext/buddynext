<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.Files.OneObjectStructurePerFile.MultipleFound -- concise test methods; Jetonomy stubs share the file.
/**
 * Forum reports land in the BuddyNext moderation queue and close on both sides.
 *
 * Jetonomy is not loaded in BuddyNext's suite, so its flag model and moderation
 * service are stubbed below with the behaviour the bridge relies on (Jetonomy
 * 2.0.7): a trash resolves the item's pending flags and fires
 * jetonomy_content_moderated synchronously, and refuses a restricted actor.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace Jetonomy\Models {
	if ( ! class_exists( Flag::class ) ) {
		/**
		 * Flag model stub over the shadow jt_flags table.
		 */
		class Flag {
			/** @var int Calls to resolve_siblings_for(). */
			public static int $sibling_calls = 0;

			public static function find( int $id ): ?object {
				global $wpdb;
				return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jt_flags WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}

			public static function reason_labels(): array {
				return array( 'off_topic' => 'Off-topic' );
			}

			public static function resolve_siblings_for( string $object_type, int $object_id, int $resolved_by, string $status, int $exclude_flag_id ): int {
				global $wpdb;
				++self::$sibling_calls;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}jt_flags SET status = %s, resolved_by = %d WHERE object_type = %s AND object_id = %d AND status = 'pending' AND id != %d", $status, $resolved_by, $object_type, $object_id, $exclude_flag_id ) );
			}
		}
	}
}

namespace Jetonomy\Moderation {
	if ( ! class_exists( Moderation_Service::class ) ) {
		/**
		 * Moderation service stub: the system status entry the bridge calls.
		 */
		class Moderation_Service {
			/** @var int[] Actors the forum refuses (banned or silenced). */
			public static array $restricted = array();

			public static function system_set_object_status( string $type, int $id, string $action, int $actor_id = 0 ) {
				global $wpdb;
				if ( in_array( $actor_id, self::$restricted, true ) ) {
					return new \WP_Error( 'jetonomy_banned', 'Banned.', array( 'status' => 403 ) );
				}
				$table  = $wpdb->prefix . ( 'post' === $type ? 'jt_posts' : 'jt_replies' );
				$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
				if ( null === $status ) {
					return new \WP_Error( 'jetonomy_not_found', 'Not found.', array( 'status' => 404 ) );
				}
				$new = array(
					'trash'   => 'trash',
					'spam'    => 'spam',
					'approve' => 'publish',
				)[ $action ];
				if ( $new === $status ) {
					return new \WP_Error( 'jetonomy_already_handled', 'Handled.', array( 'status' => 409 ) );
				}
				$wpdb->update( $table, array( 'status' => $new ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
				if ( 'publish' === $status ) {
					do_action( 'post' === $type ? 'jetonomy_post_publish_transition' : 'jetonomy_reply_publish_transition', $id, -1, '' );
				}
				\Jetonomy\Models\Flag::resolve_siblings_for( $type, $id, $actor_id, 'approve' === $action ? 'dismissed' : 'valid', 0 );
				do_action( 'jetonomy_content_moderated', $action, $type, $id, $actor_id );
				return true;
			}
		}
	}
}

namespace BuddyNext\Tests\Bridges {

	use BuddyNext\Bridges\JetonomyBridge;
	use BuddyNext\Core\Installer;
	use BuddyNext\Moderation\ModerationService;

	/**
	 * @covers \BuddyNext\Bridges\JetonomyBridge
	 * @covers \BuddyNext\Moderation\ModerationService::close_mirrored_reports
	 */
	class JetonomyForumReportsTest extends \WP_UnitTestCase {

		private ModerationService $mod;
		private int $moderator;
		private int $author;
		private int $reporter;

		public function set_up(): void {
			parent::set_up();
			Installer::run();
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_posts ( id BIGINT UNSIGNED NOT NULL, space_id BIGINT UNSIGNED NOT NULL DEFAULT 0, author_id BIGINT UNSIGNED NOT NULL DEFAULT 0, slug VARCHAR(200) NOT NULL DEFAULT '', title TEXT NULL, content_plain LONGTEXT NULL, is_private TINYINT(1) NOT NULL DEFAULT 0, is_anonymous TINYINT(1) NOT NULL DEFAULT 0, reply_count INT NOT NULL DEFAULT 0, vote_score INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, status VARCHAR(20) NOT NULL DEFAULT 'publish', PRIMARY KEY (id) ) DEFAULT CHARSET=utf8mb4" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_spaces ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(200) NOT NULL DEFAULT '', title VARCHAR(200) NOT NULL DEFAULT '', visibility VARCHAR(20) NOT NULL DEFAULT 'public', author_id BIGINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'active', PRIMARY KEY (id) ) DEFAULT CHARSET=utf8mb4" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_replies ( id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL DEFAULT 0, author_id BIGINT UNSIGNED NOT NULL DEFAULT 0, content LONGTEXT NULL, content_plain LONGTEXT NULL, is_private TINYINT(1) NOT NULL DEFAULT 0, is_anonymous TINYINT(1) NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'publish', PRIMARY KEY (id) ) DEFAULT CHARSET=utf8mb4" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_flags ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, reporter_id BIGINT UNSIGNED NOT NULL DEFAULT 0, object_type VARCHAR(10) NOT NULL DEFAULT 'post', object_id BIGINT UNSIGNED NOT NULL DEFAULT 0, reason VARCHAR(20) NOT NULL DEFAULT 'other', description TEXT NULL, status VARCHAR(10) NOT NULL DEFAULT 'pending', resolved_by BIGINT UNSIGNED NULL, PRIMARY KEY (id) ) DEFAULT CHARSET=utf8mb4" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! has_action( 'jetonomy_after_create_flag' ) ) {
				( new JetonomyBridge() )->init();
			}
			// The bridge remembers forum items for one request; each test is a new one.
			global $wp_filter;
			foreach ( $wp_filter['buddynext_removed_content_author']->callbacks ?? array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof JetonomyBridge ) {
						( new \ReflectionProperty( JetonomyBridge::class, 'forum_items' ) )->setValue( $callback['function'][0], array() );
					}
				}
			}
			\Jetonomy\Moderation\Moderation_Service::$restricted = array();
			\Jetonomy\Models\Flag::$sibling_calls                = 0;
			update_option( 'buddynext_report_rate_limit', 0 );

			$this->mod       = new ModerationService();
			$this->author    = self::factory()->user->create();
			$this->reporter  = self::factory()->user->create();
			$this->moderator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			buddynext_service( 'roles' )->set_role( $this->moderator, 'moderator' );

			$wpdb->insert(
				$wpdb->prefix . 'jt_spaces',
				array(
					'id'         => 31,
					'slug'       => 'mod-forum',
					'title'      => 'Help Desk',
					'visibility' => 'public',
				)
			); // phpcs:ignore WordPress.DB
			$wpdb->insert(
				$wpdb->prefix . 'jt_spaces',
				array(
					'id'         => 32,
					'slug'       => 'secret-forum',
					'title'      => 'Secret Room',
					'visibility' => 'hidden',
				)
			); // phpcs:ignore WordPress.DB
			$wpdb->insert(
				$wpdb->prefix . 'jt_posts',
				array(
					'id'            => 310,
					'space_id'      => 31,
					'author_id'     => $this->author,
					'slug'          => 'bad-topic',
					'title'         => 'Bad topic',
					'content_plain' => 'Body',
				)
			); // phpcs:ignore WordPress.DB
			$wpdb->insert(
				$wpdb->prefix . 'jt_posts',
				array(
					'id'            => 320,
					'space_id'      => 32,
					'author_id'     => $this->author,
					'slug'          => 'hidden-topic',
					'title'         => 'Secret title',
					'content_plain' => 'Body',
				)
			); // phpcs:ignore WordPress.DB
			$wpdb->insert(
				$wpdb->prefix . 'jt_replies',
				array(
					'id'            => 3100,
					'post_id'       => 310,
					'author_id'     => $this->author,
					'content_plain' => 'Rude reply',
				)
			); // phpcs:ignore WordPress.DB
		}

		public function tear_down(): void {
			global $wpdb;
			foreach ( array( 'jt_posts', 'jt_spaces', 'jt_replies', 'jt_flags' ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB
			}
			parent::tear_down();
		}

		/** File a forum flag the way Jetonomy does: row, then jetonomy_after_create_flag. */
		private function flag( string $type, int $id, int $reporter, string $reason = 'spam' ): int {
			global $wpdb;
			$wpdb->insert(
				$wpdb->prefix . 'jt_flags',
				array(
					'reporter_id' => $reporter,
					'object_type' => $type,
					'object_id'   => $id,
					'reason'      => $reason,
					'description' => 'Look at this',
				)
			); // phpcs:ignore WordPress.DB
			$flag_id = (int) $wpdb->insert_id;
			if ( $reporter > 0 ) {
				do_action( 'jetonomy_after_create_flag', \Jetonomy\Models\Flag::find( $flag_id ), array( 'user_id' => $reporter ) );
			}
			return $flag_id;
		}

		private function bn_status( string $type, int $id ): ?string {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_reports WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1", $type, $id ) ); // phpcs:ignore WordPress.DB
		}

		public function test_member_flags_are_copied_and_auto_flags_are_not(): void {
			global $wpdb;
			$this->flag( 'post', 310, $this->reporter, 'off_topic' );
			$this->flag( 'reply', 3100, $this->reporter, 'offensive' );
			$this->flag( 'post', 320, 0 );
			$this->flag( 'user', $this->author, $this->reporter );

			$topic = $wpdb->get_row( $wpdb->prepare( "SELECT reason, notes, status FROM {$wpdb->prefix}bn_reports WHERE object_type = 'jetonomy_topic' AND object_id = %d", 310 ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$this->assertSame( 'other', $topic['reason'] );
			$this->assertStringContainsString( 'Off-topic', $topic['notes'] );
			$this->assertStringContainsString( 'Look at this', $topic['notes'] );
			$this->assertSame( 'pending', $this->bn_status( 'jetonomy_reply', 3100 ) );
			$this->assertSame( 'inappropriate', $wpdb->get_var( "SELECT reason FROM {$wpdb->prefix}bn_reports WHERE object_type = 'jetonomy_reply'" ) ); // phpcs:ignore WordPress.DB
			$this->assertNull( $this->bn_status( 'jetonomy_topic', 320 ), 'an automatic flag is not copied' );
			$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports" ), 'a member flag is not copied' ); // phpcs:ignore WordPress.DB

			$this->flag( 'post', 310, $this->reporter );
			$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports" ), 'a second flag by the same member makes no second row' ); // phpcs:ignore WordPress.DB
		}

		public function test_a_member_cannot_report_a_forum_item_directly(): void {
			$result = $this->mod->report( $this->reporter, 'jetonomy_topic', 320, 'spam' );
			$this->assertWPError( $result );
			$this->assertSame( 'invalid_object_type', $result->get_error_code() );
		}

		public function test_moderator_without_forum_rights_removes_a_topic(): void {
			global $wpdb;
			$this->flag( 'post', 310, $this->reporter );
			$report = $this->mod->open_report_id_for( 'jetonomy_topic', 310 );

			$this->assertTrue( $this->mod->remove_content( $report, $this->moderator ) );
			$this->assertSame( 'trash', $this->jt_status( 'jt_posts', 310 ), 'trashed, not deleted' );
			$this->assertSame( 'valid', $this->jt_flag_status( 'post', 310 ) );
			$this->assertSame( 'resolved', $this->bn_status( 'jetonomy_topic', 310 ) );
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE object_type = 'report' AND object_id = %d", $report ) ), 'exactly one log row' ); // phpcs:ignore WordPress.DB
			$this->assertSame( 'remove_content', $wpdb->get_var( $wpdb->prepare( "SELECT action FROM {$wpdb->prefix}bn_mod_log WHERE object_type = 'report' AND object_id = %d", $report ) ) ); // phpcs:ignore WordPress.DB
			$this->assertSame( 'Your discussion was removed by a moderator.', $this->takedown_message() );
		}

		public function test_a_restricted_moderator_is_refused_and_nothing_changes(): void {
			$this->flag( 'reply', 3100, $this->reporter );
			\Jetonomy\Moderation\Moderation_Service::$restricted = array( $this->moderator );

			$this->assertWPError( $this->mod->remove_content( $this->mod->open_report_id_for( 'jetonomy_reply', 3100 ), $this->moderator ) );
			$this->assertSame( 'publish', $this->jt_status( 'jt_replies', 3100 ) );
			$this->assertSame( 'pending', $this->bn_status( 'jetonomy_reply', 3100 ) );
			$this->assertSame( 'pending', $this->jt_flag_status( 'reply', 3100 ) );
		}

		public function test_removing_an_already_trashed_reply_still_closes_the_report(): void {
			global $wpdb;
			$this->flag( 'reply', 3100, $this->reporter );
			$wpdb->update( $wpdb->prefix . 'jt_replies', array( 'status' => 'trash' ), array( 'id' => 3100 ) ); // phpcs:ignore WordPress.DB

			$this->assertTrue( $this->mod->remove_content( $this->mod->open_report_id_for( 'jetonomy_reply', 3100 ), $this->moderator ) );
			$this->assertSame( 'resolved', $this->bn_status( 'jetonomy_reply', 3100 ) );
		}

		public function test_dismissing_in_buddynext_dismisses_the_forum_flags(): void {
			$this->flag( 'post', 310, $this->reporter );
			$this->assertTrue( $this->mod->dismiss( $this->mod->open_report_id_for( 'jetonomy_topic', 310 ), $this->moderator ) );
			$this->assertSame( 'dismissed', $this->jt_flag_status( 'post', 310 ) );
			$this->assertSame( 'publish', $this->jt_status( 'jt_posts', 310 ) );
		}

		public function test_closing_in_jetonomy_closes_the_buddynext_report_without_echo(): void {
			global $wpdb;
			$flag_id   = $this->flag( 'post', 310, $this->reporter );
			$forum_mod = self::factory()->user->create(); // Not a BuddyNext moderator.

			$wpdb->update( $wpdb->prefix . 'jt_flags', array( 'status' => 'dismissed' ), array( 'id' => $flag_id ) ); // phpcs:ignore WordPress.DB
			do_action( 'jetonomy_flag_resolved', $flag_id, 'dismissed', $forum_mod );

			$this->assertSame( 'dismissed', $this->bn_status( 'jetonomy_topic', 310 ) );
			$this->assertSame( 0, \Jetonomy\Models\Flag::$sibling_calls, 'the BuddyNext close does not bounce back to Jetonomy' );

			// A forum trash closes a fresh report as resolved.
			$this->flag( 'reply', 3100, $this->reporter );
			\Jetonomy\Moderation\Moderation_Service::system_set_object_status( 'reply', 3100, 'trash', $forum_mod );
			$this->assertSame( 'resolved', $this->bn_status( 'jetonomy_reply', 3100 ) );

			// A hold leaves the report open.
			$this->flag( 'post', 310, self::factory()->user->create() );
			do_action( 'jetonomy_content_moderated', 'hold', 'post', 310, $forum_mod );
			$this->assertSame( 'pending', $this->bn_status( 'jetonomy_topic', 310 ) );
		}

		public function test_a_card_report_and_a_topic_flag_close_together(): void {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'status' => 'draft' ), array( 'id' => 310 ) ); // phpcs:ignore WordPress.DB
			$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'status' => 'publish' ), array( 'id' => 310 ) ); // phpcs:ignore WordPress.DB
			do_action( 'jetonomy_post_publish_transition', 310, 1, '' );
			$card = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'discussion'", $this->author ) ); // phpcs:ignore WordPress.DB
			$this->assertGreaterThan( 0, $card, 'the topic has a feed card' );

			$this->assertIsInt( $this->mod->report( self::factory()->user->create(), 'post', $card, 'spam' ) );
			$this->flag( 'post', 310, $this->reporter );

			$this->assertTrue( $this->mod->remove_content( $this->mod->open_report_id_for( 'jetonomy_topic', 310 ), $this->moderator ) );
			$this->assertSame( 'resolved', $this->bn_status( 'post', $card ), 'the card report closes with the topic report' );
			$this->assertSame( 0, $this->mod->open_report_id_for( 'post', $card ) );
			$this->assertNotSame( 'published', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_posts WHERE id = %d", $card ) ), 'the card of a trashed topic stays down' ); // phpcs:ignore WordPress.DB

			// And the other way round: removing a reported card trashes the topic and
			// closes its forum report.
			$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'status' => 'publish' ), array( 'id' => 310 ) ); // phpcs:ignore WordPress.DB
			do_action( 'jetonomy_post_publish_transition', 310, 1, '' );
			$this->assertIsInt( $this->mod->report( self::factory()->user->create(), 'post', $card, 'spam' ) );
			$this->flag( 'post', 310, self::factory()->user->create() );
			$this->assertTrue( $this->mod->remove_content( $this->mod->open_report_id_for( 'post', $card ), $this->moderator ) );
			$this->assertSame( 'trash', $this->jt_status( 'jt_posts', 310 ) );
			$this->assertSame( 'resolved', $this->bn_status( 'jetonomy_topic', 310 ) );
		}

		public function test_mirror_close_refuses_buddynext_content(): void {
			$this->assertWPError( $this->mod->close_mirrored_reports( 'post', 1, 'resolved' ) );
			$this->assertWPError( $this->mod->close_mirrored_reports( 'jetonomy_topic', 310, 'escalated' ) );
			$this->assertFalse( $this->mod->close_mirrored_reports( 'jetonomy_topic', 310, 'resolved' ), 'nothing open' );
		}

		public function test_offender_and_label(): void {
			$this->assertSame( $this->author, (int) apply_filters( 'buddynext_removed_content_author', 0, 'jetonomy_topic', 310 ) );
			$this->assertSame( $this->author, (int) apply_filters( 'buddynext_removed_content_author', 0, 'jetonomy_reply', 3100 ) );

			$this->assertSame( 'Discussion #310 in Help Desk', buddynext_object_label( 'jetonomy_topic', 310 ) );
			$hidden = buddynext_object_label( 'jetonomy_topic', 320 );
			$this->assertSame( 'Discussion #320', $hidden );
			$this->assertStringNotContainsString( 'Secret', $hidden, 'a hidden forum and its topic title are never named' );
		}

		private function jt_status( string $table, int $id ): ?string {
			return $this->jt_select( "SELECT status FROM {p}{$table} WHERE id = {$id}" );
		}

		private function jt_flag_status( string $type, int $id ): ?string {
			return $this->jt_select( "SELECT status FROM {p}jt_flags WHERE object_type = '{$type}' AND object_id = {$id} ORDER BY id DESC LIMIT 1" );
		}

		private function jt_select( string $sql ): ?string {
			global $wpdb;
			return $wpdb->get_var( str_replace( '{p}', $wpdb->prefix, $sql ) ); // phpcs:ignore WordPress.DB
		}

		private function takedown_message(): string {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.content_removed' ORDER BY id DESC LIMIT 1", $this->author ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$this->assertIsArray( $row, 'the author is told' );
			return (string) ( ( new \BuddyNext\Notifications\NotificationMessageService() )->compose( $row )['message'] ?? '' );
		}
	}
}
