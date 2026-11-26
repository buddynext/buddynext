<?php
/**
 * A mail's one main action is drawn as the brand button; Unsubscribe and inline links are not.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\EmailSender;
use WP_UnitTestCase;

/**
 * EmailSender::brand_wrap() main action.
 */
class EmailMainActionButtonTest extends WP_UnitTestCase {

	/**
	 * A paragraph holding only a link becomes a button.
	 *
	 * @return void
	 */
	public function test_a_link_only_paragraph_becomes_a_button(): void {
		$html = EmailSender::brand_wrap( '<p>Hi Bob,</p><p><a href="https://example.org/c/1">View comment</a></p>', 'Subject' );
		$this->assertMatchesRegularExpression( '#<a href="https://example.org/c/1" style="display:inline-block;background:[^"]+;color:\#ffffff#', $html );
	}

	/**
	 * Unsubscribe and links inside a sentence keep their plain look.
	 *
	 * @return void
	 */
	public function test_unsubscribe_and_inline_links_stay_plain(): void {
		$html = EmailSender::brand_wrap( '<p>See <a href="https://example.org/a">this</a> now.</p><p><a href="https://example.org/unsubscribe?t=1">Unsubscribe</a></p>', 'Subject' );
		$this->assertStringNotContainsString( 'href="https://example.org/a" style=', $html );
		$this->assertStringNotContainsString( 'href="https://example.org/unsubscribe?t=1" style=', $html );
	}
}
