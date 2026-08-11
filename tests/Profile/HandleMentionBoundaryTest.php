<?php
/**
 * An email address is not a mention.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\Handle;
use WP_UnitTestCase;

/**
 * Guards the look-behind in Handle::mention_regex().
 *
 * The pattern has four consumers — the content renderer plus the post, comment
 * and Jetonomy mention-notification parsers — so a boundary bug here breaks
 * rendering AND notifies people who were never mentioned. One pattern, one
 * test.
 */
final class HandleMentionBoundaryTest extends WP_UnitTestCase {

	/**
	 * Handles matched in a string, in order.
	 *
	 * @param string $content Text to scan.
	 * @return string[]
	 */
	private function mentions( string $content ): array {
		preg_match_all( Handle::mention_regex(), $content, $matches );

		return $matches[1];
	}

	/**
	 * An address's local part must not be read as a mention.
	 *
	 * @dataProvider email_provider
	 *
	 * @param string $content Post body containing an email address.
	 * @return void
	 */
	public function test_email_addresses_are_not_mentions( string $content ): void {
		$this->assertSame(
			array(),
			$this->mentions( $content ),
			'An email address must not produce a mention: it renders as a broken 404 link and notifies an uninvolved member.'
		);
	}

	/**
	 * Email shapes seen in real community copy.
	 *
	 * @return array<string, string[]>
	 */
	public function email_provider(): array {
		return array(
			'plain'          => array( 'email us at support@example.com' ),
			'capitalised'    => array( 'contact Support@weidelonwinning.com today' ),
			'dotted local'   => array( 'first.last@example.com' ),
			'address only'   => array( 'billing@example.org' ),
			'inside a lists' => array( 'a@example.com, b@example.com' ),
		);
	}

	/**
	 * Every legitimate way a mention opens a token must still match.
	 *
	 * A look-behind that is too greedy would silently stop mentions working
	 * after a bracket or a quote, which is exactly how people write them.
	 *
	 * @dataProvider mention_provider
	 *
	 * @param string   $content  Post body.
	 * @param string[] $expected Handles that must be found.
	 * @return void
	 */
	public function test_real_mentions_still_match( string $content, array $expected ): void {
		$this->assertSame( $expected, $this->mentions( $content ) );
	}

	/**
	 * Mention shapes that must keep working.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function mention_provider(): array {
		return array(
			'after a space'   => array( 'hey @alice welcome', array( 'alice' ) ),
			'start of string' => array( '@frank posted', array( 'frank' ) ),
			'after a newline' => array( "line one\n@eve replied", array( 'eve' ) ),
			'in brackets'     => array( '(@bob)', array( 'bob' ) ),
			'in quotes'       => array( '"@carol"', array( 'carol' ) ),
			'after a dash'    => array( '-@dave', array( 'dave' ) ),
			'several'         => array( 'cc @alice and @bob', array( 'alice', 'bob' ) ),
		);
	}
}
