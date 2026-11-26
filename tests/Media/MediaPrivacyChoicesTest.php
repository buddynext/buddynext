<?php
/**
 * The privacy levels a member is offered for their media.
 *
 * MediaVerse lists its friends level only where BuddyPress has a friends component, and
 * calls it Friends. On a BuddyNext site that level is the member's connections, which the
 * upload tray already offered; the photo Edit panel never did.
 *
 * @package BuddyNext\Tests\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Media;

use BuddyNext\Bridges\WPMediaVerseBridge;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge::offer_connections_level
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge::name_connections_level
 */
class MediaPrivacyChoicesTest extends \WP_UnitTestCase {

	/**
	 * MediaVerse's own list on a site without BuddyPress friends.
	 *
	 * @return array<string,string>
	 */
	private function mediaverse_list(): array {
		return array(
			'public'    => 'Public: anyone can see',
			'members'   => 'Members: logged-in users only',
			'followers' => 'Followers: people who follow you',
			'private'   => 'Only me: hidden from everyone else',
		);
	}

	/**
	 * Connections is offered, between Followers and Only me.
	 */
	public function test_connections_level_is_offered_in_the_tray_order(): void {
		$choices = ( new WPMediaVerseBridge() )->offer_connections_level( $this->mediaverse_list() );

		$this->assertSame( array( 'public', 'members', 'followers', 'friends', 'private' ), array_keys( $choices ) );
		$this->assertStringStartsWith( 'Connections', $choices['friends'] );
	}

	/**
	 * A site that already lists the level (BuddyPress friends) keeps its own entry.
	 */
	public function test_an_existing_friends_entry_is_left_alone(): void {
		$list            = $this->mediaverse_list();
		$list['friends'] = 'Friends: your friends only';

		$choices = ( new WPMediaVerseBridge() )->offer_connections_level( $list );

		$this->assertSame( $list, $choices );
	}

	/**
	 * Every label MediaVerse prints for the level reads Connections.
	 */
	public function test_friends_label_reads_connections(): void {
		$labels = ( new WPMediaVerseBridge() )->name_connections_level( array( 'friends' => 'Friends: your friends only' ) );

		$this->assertStringStartsWith( 'Connections', $labels['friends'] );
	}
}
