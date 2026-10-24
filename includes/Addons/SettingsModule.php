<?php
/**
 * Addon module: owner settings inside the addon's Integration Settings card.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

use BuddyNext\Admin\Settings\Field;

/**
 * An addon with owner settings.
 *
 * The fields render and save inside the addon's own card on Integration Settings
 * (no new admin tab), are sanitised by their {@see Field}, and appear in the
 * settings registry, so ⌘K search and the option-defaults gate see them.
 * Option keys must start with `buddynext_<slug>_`.
 *
 * @api
 * @since 1.2.7
 */
interface SettingsModule {

	/**
	 * The addon's fields (toggle, text, textarea, number, select, url).
	 *
	 * @return Field[]
	 */
	public function settings_fields(): array;
}
