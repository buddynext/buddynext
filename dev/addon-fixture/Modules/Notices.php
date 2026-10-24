<?php
/**
 * Fixture notifications.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture\Modules;

use BuddyNext\Addons\NotificationSource;

/**
 * One bell type.
 */
final class Notices implements NotificationSource {

	/**
	 * Hook.
	 *
	 * @return string
	 */
	public function notification_hook(): string {
		return 'fixture_notification_created';
	}

	/**
	 * Prefix.
	 *
	 * @return string
	 */
	public function notification_prefix(): string {
		return 'fixture';
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function notification_icon(): string {
		return 'bell';
	}

	/**
	 * Types.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function notification_types(): array {
		return array(
			'ping' => array(
				'label'       => 'Ping',
				'description' => 'A fixture ping.',
				'default_on'  => true,
			),
		);
	}

	/**
	 * Visibility: item 7 is gone.
	 *
	 * @param array<int|string,bool>         $visible   Row key => true.
	 * @param int                            $viewer_id Viewer.
	 * @param array<int|string,array<mixed>> $targets   Targets.
	 * @return array<int|string,bool>
	 */
	public function notification_visible( array $visible, int $viewer_id, array $targets ): array {
		foreach ( $targets as $key => $target ) {
			if ( 7 === (int) ( $target['object_id'] ?? 0 ) ) {
				$visible[ $key ] = false;
			}
		}
		return $visible;
	}
}
