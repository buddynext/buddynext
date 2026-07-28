<?php
/**
 * Removing reported content tells the author it happened.
 *
 * `buddynext_content_removed` was documented at its own call site as "the public
 * side-effect hook for notification/analytics listeners" — and had none, in Free,
 * Pro or WPMediaVerse. So a member's post or comment could be taken down from the
 * report queue and simply vanish: no notification, no reason, and no prompt to
 * appeal, while every other moderation action (warn, strike, suspend, and the
 * pre-moderation approve/reject pair) notified them correctly.
 *
 * The hook also used to fire even when nothing was removed — an object type no
 * handler claimed still broadcast "content removed". That would have made the new
 * notice lie, so the action is now gated on a confirmed takedown.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;
use WP_Error;

/**
 * The takedown notice.
 *
 * @covers \BuddyNext\Moderation\ModerationListener::on_content_removed_notify
 * @covers \BuddyNext\Moderation\ModerationService::remove_object
 */
class ContentRemovedNoticeTest extends \WP_UnitTestCase {

	/**
	 * Moderation service under test.
	 *
	 * @var ModerationService
	 */
	private $moderation;

	/**
	 * Post service used to build the content being reported.
	 *
	 * @var PostService
	 */
	private $posts;

	/**
	 * The moderator performing the takedown.
	 *
	 * @var int
	 */
	private $admin = 0;

	/**
	 * The member whose content is removed.
	 *
	 * @var int
	 */
	private $author = 0;

	/**
	 * Boot the schema and the listeners the plugin wires at runtime.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->moderation = new ModerationService();
		$this->posts      = new PostService();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Notification rows belonging to a recipient, newest first.
	 *
	 * @param int $user_id Recipient.
	 * @return array<int, array<string, mixed>>
	 */
	private function notifications_for( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, object_type, object_id, data FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d ORDER BY id DESC",
				$user_id
			),
			ARRAY_A
		);
	}

	/**
	 * Report a piece of content and remove it as the admin.
	 *
	 * @param string $object_type Content type.
	 * @param int    $object_id   Content ID.
	 * @return bool|WP_Error Whatever remove_content() returned.
	 */
	private function report_and_remove( string $object_type, int $object_id ) {
		$report_id = $this->moderation->report( $this->admin, $object_type, $object_id, 'spam' );
		$this->assertIsInt( $report_id, 'The report fixture was not created.' );

		return $this->moderation->remove_content( $report_id, $this->admin );
	}

	/**
	 * The reported bug: a removed post notifies nobody.
	 *
	 * @return void
	 */
	public function test_removing_a_post_notifies_its_author(): void {
		$post_id = $this->posts->create(
			$this->author,
			array(
				'type'    => 'text',
				'content' => 'Content that will be taken down.',
			)
		);

		$this->assertSame( array(), $this->notifications_for( $this->author ), 'Fixture started with notifications.' );

		$result = $this->report_and_remove( 'post', $post_id );
		$this->assertTrue( $result, 'The takedown itself failed.' );

		$rows = $this->notifications_for( $this->author );
		$this->assertCount( 1, $rows, 'The author was not told their post was removed.' );
		$this->assertSame( 'bn.content_removed', $rows[0]['type'] );
		$this->assertSame( 'post', $rows[0]['object_type'] );
		$this->assertSame( $post_id, (int) $rows[0]['object_id'] );
		$this->assertSame( 'post', json_decode( (string) $rows[0]['data'], true )['content_type'] );
	}

	/**
	 * Comments go through the same path and carry their own content_type, so the
	 * copy can say "your comment" rather than something generic.
	 *
	 * @return void
	 */
	public function test_removing_a_comment_notifies_its_author(): void {
		global $wpdb;

		$post_id = $this->posts->create(
			$this->admin,
			array(
				'type'    => 'text',
				'content' => 'Host post.',
			)
		);

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'user_id'     => $this->author,
				'content'     => 'A comment that will be taken down.',
			)
		);
		$this->assertSame( 1, $inserted, 'The comment fixture was not written.' );
		$comment_id = (int) $wpdb->insert_id;

		$this->report_and_remove( 'comment', $comment_id );

		$rows = $this->notifications_for( $this->author );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'bn.content_removed', $rows[0]['type'] );
		$this->assertSame( 'comment', json_decode( (string) $rows[0]['data'], true )['content_type'] );

		$this->assertSame(
			'1',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT is_deleted FROM {$wpdb->prefix}bn_comments WHERE id = %d", $comment_id ) ),
			'The comment was not actually taken down.'
		);
	}

	/**
	 * A moderator removing their own content is not notified about it.
	 *
	 * @return void
	 */
	public function test_moderator_removing_their_own_content_is_not_notified(): void {
		$post_id = $this->posts->create(
			$this->admin,
			array(
				'type'    => 'text',
				'content' => 'The moderator cleaning up after themselves.',
			)
		);

		$this->report_and_remove( 'post', $post_id );

		$this->assertSame(
			array(),
			array_filter(
				$this->notifications_for( $this->admin ),
				static fn( array $row ): bool => 'bn.content_removed' === $row['type']
			),
			'The moderator was told about their own takedown.'
		);
	}

	/**
	 * An object type no handler claims must not announce a takedown that did not
	 * happen — the report stays open with an error instead.
	 *
	 * @return void
	 */
	public function test_unhandled_object_type_announces_nothing(): void {
		$fired = 0;
		add_action(
			'buddynext_content_removed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$report_id = $this->moderation->report( $this->admin, 'widget', 4242, 'spam' );
		$result    = $this->moderation->remove_content( $report_id, $this->admin );

		$this->assertInstanceOf( WP_Error::class, $result, 'An unremovable type reported success.' );
		$this->assertSame( 'bn_removal_unsupported', $result->get_error_code() );
		$this->assertSame( 0, $fired, 'buddynext_content_removed fired for content nothing removed.' );
	}

	/**
	 * The type is in the preference catalogue, so members can actually control it
	 * and it renders with a label instead of a raw slug.
	 *
	 * @return void
	 */
	public function test_type_is_registered_in_the_preference_catalogue(): void {
		$catalogue = buddynext_service( 'notification_pref_catalogue' )->all();

		$this->assertArrayHasKey( 'bn.content_removed', $catalogue, 'bn.content_removed is missing from the preference catalogue.' );
		$entry = $catalogue['bn.content_removed'];

		$this->assertNotSame( '', (string) $entry['label'] );
		$this->assertTrue( (bool) $entry['default_on_site'] );
	}
}
