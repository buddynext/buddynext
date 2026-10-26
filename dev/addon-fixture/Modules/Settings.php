<?php
/**
 * Fixture settings.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture\Modules;

use BuddyNext\Addons\SettingsModule;
use BuddyNext\Admin\Settings\Field;

/**
 * A toggle and a text field.
 */
final class Settings implements SettingsModule {

	/**
	 * Fields.
	 *
	 * @return Field[]
	 */
	public function settings_fields(): array {
		return array(
			new Field(
				array(
					'key'     => 'buddynext_fixture_enabled',
					'type'    => 'toggle',
					'label'   => 'Fixture on',
					'default' => true,
				)
			),
			new Field(
				array(
					'key'     => 'buddynext_fixture_greeting',
					'type'    => 'text',
					'label'   => 'Greeting',
					'default' => 'Hello',
				)
			),
		);
	}
}
