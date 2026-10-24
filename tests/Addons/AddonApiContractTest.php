<?php
/**
 * The addon API contract: nothing public may be removed or change shape.
 *
 * @package BuddyNext\Tests\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Addons;

use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * A signature in this list is a promise to every addon author. Adding a method or
 * a new interface is fine (extend the list). Removing or changing one is a breaking
 * change: bump BUDDYNEXT_ADDON_API, keep the old shape working for two minor
 * releases, and only then edit this list.
 */
class AddonApiContractTest extends WP_UnitTestCase {

	/**
	 * Every public signature promised so far.
	 *
	 * @return string[]
	 */
	private function promised(): array {
		return array(
			'AbstractAddon::slug(): string abstract',
			'AbstractAddon::label(): string abstract',
			'AbstractAddon::partner_active(): bool abstract',
			'AbstractAddon::modules(): array abstract',
			'AbstractAddon::api_version(): int',
			'AbstractAddon::partner_version(): string',
			'AbstractAddon::min_version(): string',
			'AbstractAddon::tested_version(): string',
			'AbstractAddon::feature_versions(): array',
			'AbstractAddon::nav_label(): string',
			'AbstractAddon::feed_enabled(): bool final',
			'AbstractAddon::search_enabled(): bool final',
			'AbstractAddon::resolved_modules(): array final',
			'AbstractAddon::below_floor(): bool final',
			'AbstractAddon::boot(): void final',
			'AbstractAddon::wire(): void final',
			'AbstractAddon::add_integration(array $items): array final',
			'AddonRegistry::instance(): BuddyNext\\Addons\\AddonRegistry static',
			'AddonRegistry::reset(): void static',
			'AddonRegistry::boot(): void',
			'AddonRegistry::register(BuddyNext\\Addons\\AbstractAddon $addon): bool',
			'AddonRegistry::all(): array',
			'AddonRegistry::get(string $slug): ?BuddyNext\\Addons\\AbstractAddon',
			'AddonRegistry::refused(): array',
			'AddonRegistry::settings_fields(string $slug): array',
			'AddonRegistry::render_notices(): void',
			'FeedCardModule::card_types(): array abstract',
			'NotificationSource::notification_hook(): string abstract',
			'NotificationSource::notification_prefix(): string abstract',
			'NotificationSource::notification_icon(): string abstract',
			'NotificationSource::notification_types(): array abstract',
			'NotificationSource::notification_visible(array $visible, int $viewer_id, array $targets): array abstract',
			'SearchSource::search_types(): array abstract',
			'SearchSource::search_url(string $type, int $object_id): string abstract',
			'SearchSource::reindex_ids(int $after_id, int $limit): array abstract',
			'SearchSource::reindex_item(int $id): void abstract',
			'SettingsModule::settings_fields(): array abstract',
			'DiscoveryModule::space_banner(int $space_id, int $viewer_id): string abstract',
			'DiscoveryModule::nav_deny_paths(): array abstract',
			'ProfilePanelModule::panels(int $member_id): array abstract',
		);
	}

	/**
	 * Public signatures as they are now.
	 *
	 * @return string[]
	 */
	private function current(): array {
		$out = array();
		foreach ( array( 'AbstractAddon', 'AddonRegistry', 'FeedCardModule', 'NotificationSource', 'SearchSource', 'SettingsModule', 'DiscoveryModule', 'ProfilePanelModule' ) as $name ) {
			$class = new ReflectionClass( 'BuddyNext\\Addons\\' . $name );
			foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
				$params = array();
				foreach ( $method->getParameters() as $param ) {
					$params[] = ( $param->getType() ? $param->getType() : 'mixed' ) . ' $' . $param->getName() . ( $param->isOptional() ? '=' : '' );
				}
				$out[] = $name . '::' . $method->getName() . '(' . implode( ', ', $params ) . '): ' . ( $method->getReturnType() ? $method->getReturnType() : 'mixed' )
					. ( $method->isStatic() ? ' static' : '' ) . ( $method->isFinal() ? ' final' : '' ) . ( $method->isAbstract() ? ' abstract' : '' );
			}
		}
		return $out;
	}

	public function test_no_promised_signature_was_removed_or_changed(): void {
		$missing = array_values( array_diff( $this->promised(), $this->current() ) );

		$this->assertSame( array(), $missing, 'Breaking the addon API: bump BUDDYNEXT_ADDON_API and keep the old shape for two minor releases.' );
	}

	public function test_the_api_value_is_an_integer_the_registry_supports(): void {
		$this->assertIsInt( BUDDYNEXT_ADDON_API );
		$this->assertGreaterThanOrEqual( \BuddyNext\Addons\AddonRegistry::OLDEST_SUPPORTED_API, BUDDYNEXT_ADDON_API );
	}
}
