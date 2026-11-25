<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * A card in a Space tells its members, but only the ones who may open it, and says an event is an event.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Notifications\NotificationMessageService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

class SpaceEventNoticeTest extends WP_UnitTestCase {

	private int $author;
	private int $member;
	private int $lapsed;
	private int $space;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->author = self::factory()->user->create();
		$this->member = self::factory()->user->create();
		$this->lapsed = self::factory()->user->create();
		$this->space  = (int) ( new SpaceService() )->create(
			$this->author,
			array(
				'name' => 'Notice ' . wp_rand(),
				'slug' => 'notice-' . wp_rand( 10000, 99999 ),
				'type' => 'open',
			)
		);
		( new SpaceMemberService() )->join( $this->space, $this->member );
		( new SpaceMemberService() )->join( $this->space, $this->lapsed );
	}

	private function notices_for( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.space_new_post'", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function card( string $type, string $suffix ): int {
		return (int) IntegrationActivity::publish( $this->author, 'scheduled an event', 'https://example.test/' . $suffix . '/', 'Card ' . $suffix, $type, '', $this->space, array() );
	}

	public function test_a_member_an_add_on_leaves_out_is_not_told(): void {
		$lapsed = $this->lapsed;
		add_filter( 'buddynext_space_post_recipients', static fn( $ids ) => array_values( array_diff( (array) $ids, array( $lapsed ) ) ) );

		$this->card( 'link', 'one' );

		$this->assertSame( 1, $this->notices_for( $this->member ), 'Someone who may open it is told.' );
		$this->assertSame( 0, $this->notices_for( $this->lapsed ), 'Someone who may not is not.' );
	}

	public function test_without_that_filter_the_whole_roster_is_told_as_before(): void {
		$this->card( 'link', 'two' );

		$this->assertSame( 1, $this->notices_for( $this->member ) );
		$this->assertSame( 1, $this->notices_for( $this->lapsed ) );
	}

	public function test_the_bell_says_an_event_was_scheduled_and_a_plain_post_was_posted(): void {
		$event_card = $this->card( 'event', 'ev' );
		$text_card  = $this->card( 'link', 'tx' );
		global $wpdb;
		$rows = array();
		foreach ( array( $event_card, $text_card ) as $i => $post_id ) {
			$rows[ $i ] = array(
				'id'          => $i + 1,
				'type'        => 'bn.space_new_post',
				'object_type' => 'post',
				'object_id'   => $post_id,
				'sender_id'   => $this->author,
				'group_count' => 1,
				'data'        => null,
			);
		}
		wp_cache_flush();

		$out = ( new NotificationMessageService() )->compose_batch( $rows );

		$this->assertStringContainsString( 'scheduled an event in', $out[0]['message'] );
		$this->assertStringContainsString( 'posted in', $out[1]['message'] );
	}
}
