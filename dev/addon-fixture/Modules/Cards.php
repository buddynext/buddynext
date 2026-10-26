<?php
/**
 * Fixture feed cards.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture\Modules;

use BuddyNext\Addons\FeedCardModule;

/**
 * One card type.
 */
final class Cards implements FeedCardModule {

	/**
	 * Card types.
	 *
	 * @return array<string,array{label:string,icon:string}>
	 */
	public function card_types(): array {
		return array(
			'fixture_item' => array(
				'label' => 'Fixture',
				'icon'  => 'link',
			),
		);
	}
}
