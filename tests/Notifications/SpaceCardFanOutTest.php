<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * A card a bridge writes for a member does not notify the whole Space; a human post still does.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

class SpaceCardFanOutTest extends WP_UnitTestCase {

	private int $owner;
	private int $learner;
	private int $other;
	private int $space;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner   = self::factory()->user->create();
		$this->learner = self::factory()->user->create();
		$this->other   = self::factory()->user->create();

		$this->space = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Fan out ' . wp_rand(),
				'slug' => 'fan-out-' . wp_rand( 10000, 99999 ),
				'type' => 'open',
			)
		);
		( new SpaceMemberService() )->join( $this->space, $this->learner );
		( new SpaceMemberService() )->join( $this->space, $this->other );
	}

	private function notices_for( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.space_new_post'", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function card( array $meta, string $suffix ): void {
		IntegrationActivity::publish( $this->learner, 'completed a course', 'https://example.test/' . $suffix . '/', 'Card ' . $suffix, 'link', '', $this->space, $meta );
	}

	public function test_a_card_marked_notify_space_false_notifies_nobody(): void {
		$this->card( array( 'notify_space' => false ), 'quiet' );
		$this->assertSame( 0, $this->notices_for( $this->other ), 'The Space is not told about a system-written card.' );
		$this->assertSame( 0, $this->notices_for( $this->owner ) );
	}

	public function test_a_card_without_the_flag_still_notifies_the_other_members(): void {
		$this->card( array(), 'loud' );
		$this->assertSame( 1, $this->notices_for( $this->other ), 'Default is unchanged: members are told.' );
		$this->assertSame( 0, $this->notices_for( $this->learner ), 'Never the author.' );
	}

	public function test_a_developer_filter_has_the_last_word(): void {
		add_filter( 'buddynext_notify_space_of_post', '__return_true' );
		$this->card( array( 'notify_space' => false ), 'forced' );
		remove_filter( 'buddynext_notify_space_of_post', '__return_true' );
		$this->assertSame( 1, $this->notices_for( $this->other ), 'The filter can turn a quiet card loud.' );

		add_filter( 'buddynext_notify_space_of_post', '__return_false' );
		$this->card( array(), 'silenced' );
		remove_filter( 'buddynext_notify_space_of_post', '__return_false' );
		$this->assertSame( 1, $this->notices_for( $this->other ), 'And a loud card quiet: no new notice.' );
	}
}
