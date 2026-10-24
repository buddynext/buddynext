<?php
/**
 * Fixture addon: implements every module, never shipped.
 *
 * It is the lock on the addon API. PHPStan analyses it on every commit, so a
 * changed or removed method in `BuddyNext\Addons` fails here before it can break a
 * real addon. The tests drive it to prove each module's wiring.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture;

use BuddyNext\Addons\AbstractAddon;

/**
 * A pretend partner plugin and the addon around it.
 */
final class FixtureAddon extends AbstractAddon {

	/**
	 * Whether the pretend partner is active (tests flip it).
	 *
	 * @var bool
	 */
	public static bool $active = true;

	/**
	 * The pretend partner's version (tests change it).
	 *
	 * @var string
	 */
	public static string $version = '2.0.0';

	/**
	 * The API value the addon claims to target (tests change it).
	 *
	 * @var int
	 */
	public static int $api = 1;

	/**
	 * Integration key.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'fixture';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Fixture';
	}

	/**
	 * API targeted.
	 *
	 * @return int
	 */
	public function api_version(): int {
		return self::$api;
	}

	/**
	 * Partner state.
	 *
	 * @return bool
	 */
	public function partner_active(): bool {
		return self::$active;
	}

	/**
	 * Partner version.
	 *
	 * @return string
	 */
	public function partner_version(): string {
		return self::$version;
	}

	/**
	 * Floor.
	 *
	 * @return string
	 */
	public function min_version(): string {
		return '1.0.0';
	}

	/**
	 * Last verified partner release.
	 *
	 * @return string
	 */
	public function tested_version(): string {
		return '2.0.0';
	}

	/**
	 * Modules.
	 *
	 * @return object[]
	 */
	public function modules(): array {
		return array(
			new Modules\Cards(),
			new Modules\Notices(),
			new Modules\Search(),
			new Modules\Settings(),
			new Modules\Discovery(),
			new Modules\Panels(),
		);
	}
}
