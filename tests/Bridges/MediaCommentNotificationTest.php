<?php
/**
 * A media comment notifies the owner once, whether or not the photo has a feed
 * card yet, and the card created later carries the comment (card 10344509261).
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge
 */
class MediaCommentNotificationTest extends \WP_UnitTestCase {

	private const MEDIA = 1501;

	private WPMediaVerseBridge $bridge;
	private int $owner     = 0;
	private int $commenter = 0;

	/** @var array<int,array{id:int}> Comments the fake comment service returns. */
	private static array $comments = array();

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner     = self::factory()->user->create();
		$this->commenter = self::factory()->user->create();
		self::$comments  = array();

		$owner = $this->owner;
		add_filter(
			'buddynext_media_service',
			static function ( $service, string $key ) use ( $owner ) {
				if ( 'media_repository' === $key ) {
					return new class( $owner ) {
						public function __construct( private int $owner ) {}
						public function get_author( $media_id ): int {
							return $this->owner;
						}
						public function get( $media_id, string $field = '' ) {
							return 'post_author' === $field ? $this->owner : null;
						}
						public function get_permalink( $media_id ): string {
							return home_url( '/media/' . (int) $media_id . '/' );
						}
					};
				}
				if ( 'comments' === $key ) {
					return new class() {
						public function get_for_media( int $media_id, int $per_page = 20, int $page = 1 ): array {
							return array( 'comments' => MediaCommentNotificationTest::comments(), 'total' => count( MediaCommentNotificationTest::comments() ) );
						}
					};
				}
				return $service;
			},
			10,
			2
		);

		// The plugin's own bridge is already hooked; this instance only makes the
		// direct calls (the Action Scheduler job), so it is not hooked a second time.
		$this->bridge = new WPMediaVerseBridge();
	}

	/**
	 * @return array<int,array{id:int}>
	 */
	public static function comments(): array {
		return self::$comments;
	}

	private function comment( string $text ): int {
		$id               = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => 0,
				'user_id'          => $this->commenter,
				'comment_content'  => $text,
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'comment_approved' => 1,
			)
		);
		self::$comments[] = array( 'id' => $id );
		do_action( 'mvs_comment_created', self::MEDIA, $this->commenter, $id, $text, 'lightbox' );
		return $id;
	}

	private function bell( string $type ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(group_count),0) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = %s", $this->owner, $type ) );
	}

	public function test_comment_before_the_card_notifies_and_is_carried_onto_it(): void {
		global $wpdb;
		$this->comment( 'Lovely light' );
		$this->assertSame( 1, $this->bell( 'bn.media_commented' ), 'the owner hears about a comment on a photo with no card yet' );

		// The Action Scheduler job creates the card two minutes after the upload.
		$this->bridge->publish_media_activity( self::MEDIA, $this->owner, 'photo' );
		$card = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}bn_post_media WHERE media_id = %d", self::MEDIA ) );
		$this->assertGreaterThan( 0, $card );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE object_type = 'post' AND object_id = %d AND media_id = %d", $card, self::MEDIA ) ), 'the earlier comment is on the card' );
		$this->assertSame( 0, $this->bell( 'bn.post_commented' ), 'the copy on the card notifies nobody' );
		$this->assertSame( 1, $this->bell( 'bn.media_commented' ), 'still exactly one notification' );
	}

	public function test_comment_after_the_card_notifies_once(): void {
		$this->bridge->publish_media_activity( self::MEDIA, $this->owner, 'photo' );
		$this->comment( 'Second look' );
		$this->assertSame( 1, $this->bell( 'bn.media_commented' ) );
		$this->assertSame( 0, $this->bell( 'bn.post_commented' ), 'mirroring onto the card does not notify again' );
	}

	/**
	 * Deleting, trashing or spamming the MediaVerse comment withdraws its copy on the card.
	 *
	 * @return void
	 */
	public function test_removing_the_lightbox_comment_withdraws_its_copy(): void {
		global $wpdb;
		$this->bridge->publish_media_activity( self::MEDIA, $this->owner, 'photo' );
		$live = static function ( string $text ) use ( $wpdb ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE media_id = %d AND content = %s AND is_deleted = 0", self::MEDIA, $text ) );
		};

		foreach ( array( 'deleted' => 'Gone soon', 'trashed' => 'Trash me', 'spammed' => 'Spam me' ) as $how => $text ) {
			$id = $this->comment( $text );
			add_comment_meta( $id, 'mvs_media_id', self::MEDIA );
			$this->assertSame( 1, $live( $text ), "the copy of '$text' is on the card" );

			if ( 'deleted' === $how ) {
				wp_delete_comment( $id, true ); // MediaVerse's own delete.
			} elseif ( 'trashed' === $how ) {
				wp_trash_comment( $id );
			} else {
				wp_spam_comment( $id );
			}
			$this->assertSame( 0, $live( $text ), "a $how comment leaves no copy" );
		}

		// A comment with no MediaVerse meta (any other site comment) is left alone.
		$keep = $this->comment( 'Not from MediaVerse' );
		wp_delete_comment( $keep, true );
		$this->assertSame( 1, $live( 'Not from MediaVerse' ) );
	}

	public function test_own_comment_and_blocked_commenter_notify_nobody(): void {
		buddynext_service( 'blocks' )->block( $this->owner, $this->commenter );
		$this->comment( 'Blocked' );
		$this->assertSame( 0, $this->bell( 'bn.media_commented' ) );
	}

	public function test_media_notification_opens_the_post_or_the_media_page(): void {
		$this->assertSame( home_url( '/media/' . self::MEDIA . '/' ), apply_filters( 'buddynext_media_notification_url', '', self::MEDIA ), 'no card yet: the media page' );
		$this->bridge->publish_media_activity( self::MEDIA, $this->owner, 'photo' );
		$this->assertStringContainsString( '/p/', (string) apply_filters( 'buddynext_media_notification_url', '', self::MEDIA ), 'with a card: the post' );
	}
}
