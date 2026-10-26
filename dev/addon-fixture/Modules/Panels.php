<?php
/**
 * Fixture profile panels.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture\Modules;

use BuddyNext\Addons\ProfilePanelModule;

/**
 * One public panel and one owner-only panel.
 */
final class Panels implements ProfilePanelModule {

	/**
	 * Panels for a member.
	 *
	 * @param int $member_id Profile owner.
	 * @return array<int,array<string,mixed>>
	 */
	public function panels( int $member_id ): array {
		return array(
			array(
				'key'   => 'fixture_public',
				'label' => 'Fixture items',
				'icon'  => 'link',
				'items' => array(
					array(
						'title' => 'Item for ' . $member_id,
						'url'   => 'https://example.test/' . $member_id,
					),
				),
			),
			array(
				'key'        => 'fixture_private',
				'label'      => 'Fixture drafts',
				'icon'       => 'link',
				'owner_only' => true,
				'items'      => array(
					array(
						'title' => 'Draft',
						'url'   => 'https://example.test/draft',
					),
				),
			),
		);
	}
}
