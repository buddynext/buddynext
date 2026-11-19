<?php
/**
 * One inbox for the forums: the Forum email card always shows, BuddyNext's Email
 * switch governs forum email, and Jetonomy's notifications page hands over.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\PageRouter;

/**
 * @covers \BuddyNext\Bridges\JetonomyBridge::render_partner_prefs
 * @covers \BuddyNext\Bridges\JetonomyBridge::filter_forum_email
 * @covers \BuddyNext\Bridges\JetonomyBridge::redirect_forum_notifications
 */
class JetonomyForumInboxTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_community_notification_types' );
		remove_all_actions( 'jetonomy_profile_notification_fields' );
		set_query_var( 'jetonomy_route', '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Jetonomy has adopted the notification contract.
	 *
	 * @return void
	 */
	private function adopt(): void {
		add_filter(
			'jetonomy_community_notification_types',
			static function ( array $types ): array {
				$types['mention'] = array( 'label' => 'Mentions' );
				return $types;
			}
		);
	}

	/**
	 * Run the notifications redirect: the target, or '' when the request went through.
	 *
	 * @param string $route Jetonomy route query var.
	 * @return string
	 */
	private function redirect( string $route = 'notifications' ): string {
		set_query_var( 'jetonomy_route', $route );
		$throw = static function ( $location ): void {
			throw new \RuntimeException( (string) $location );
		};
		add_filter( 'wp_redirect', $throw );
		try {
			( new JetonomyBridge() )->redirect_forum_notifications();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}
		return '';
	}

	public function test_the_forum_email_card_shows_without_any_partner_field(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		ob_start();
		( new JetonomyBridge() )->render_partner_prefs( $user );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="forum-email"', $html );
		$this->assertStringContainsString( 'data-body-key="email_opt_out"', $html );
		$this->assertStringNotContainsString( 'bn-partner-prefs__fields', $html, 'No digest wrapper when Jetonomy renders nothing.' );

		add_action( 'jetonomy_profile_notification_fields', static function (): void { echo '<input name="digest_frequency" type="radio" value="weekly">'; } );
		ob_start();
		( new JetonomyBridge() )->render_partner_prefs( $user );
		$this->assertStringContainsString( 'name="digest_frequency"', (string) ob_get_clean() );

		ob_start();
		( new JetonomyBridge() )->render_partner_prefs( 0 );
		$this->assertSame( '', (string) ob_get_clean(), 'Nothing for a guest.' );
	}

	public function test_forum_email_follows_the_buddynext_email_switch(): void {
		$bridge = new JetonomyBridge();
		$user   = self::factory()->user->create();

		$this->assertTrue( $bridge->filter_forum_email( true, $user ), 'Nothing stored means on.' );
		update_user_meta( $user, 'bn_channel_prefs', array( 'email' => true ) );
		$this->assertTrue( $bridge->filter_forum_email( true, $user ) );

		update_user_meta( $user, 'bn_channel_prefs', array( 'email' => false ) );
		$this->assertFalse( $bridge->filter_forum_email( true, $user ), 'Email off in BuddyNext: no forum email.' );
		$this->assertFalse( apply_filters( 'jetonomy_send_instant_email', true, $user, 'mention', 'mention' ), 'Registered on Jetonomy\'s filter.' );

		$this->assertFalse( $bridge->filter_forum_email( false, self::factory()->user->create() ), 'Never turns an email on.' );
		$this->assertTrue( $bridge->filter_forum_email( true, 0 ), 'A guest address is never suppressed.' );
	}

	public function test_jetonomy_notifications_page_hands_over_to_buddynext(): void {
		$this->adopt();
		$this->assertSame( '', $this->redirect(), 'A guest stays on Jetonomy\'s page.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( PageRouter::notifications_url(), $this->redirect() );

		$this->assertSame( '', $this->redirect( 'home' ), 'Other forum pages are untouched.' );
	}

	public function test_no_hand_over_until_the_inbox_shows_the_forum(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', $this->redirect() );
	}
}
