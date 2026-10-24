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
			'SpaceLinkSource::link_types(): array abstract',
			'SpaceLinkSource::link_label(string $type, int $object_id): array abstract',
			'SpaceLinkSource::link_roster(string $type, int $object_id, int $after, int $limit): array abstract',
			'SpaceLinkSource::link_grants_access(string $type, int $object_id, int $user_id): bool abstract',
			'IntegrationRegistry::instance(): BuddyNext\\Integrations\\IntegrationRegistry static',
			'IntegrationRegistry::all(): array',
			'IntegrationRegistry::get(string $key): ?array',
			'IntegrationRegistry::reset(): void',
			'IntegrationRegistry::features_needing_update(array $entry): array static',
			'SpaceLinkService::register(): void',
			'SpaceLinkService::is_system_join(): bool static',
			'SpaceLinkService::sources(): array',
			'SpaceLinkService::source(string $slug): ?BuddyNext\\Addons\\SpaceLinkSource',
			'SpaceLinkService::links(int $space_id): array',
			'SpaceLinkService::links_for_source(int $space_id, string $source): array',
			'SpaceLinkService::spaces_for_object(string $source, string $type, int $object_id): array',
			'SpaceLinkService::spaces_for_user(int $user_id, string $source): array',
			'SpaceLinkService::linked_elsewhere(string $source, string $type, int $object_id, int $except_space_id=): bool',
			'SpaceLinkService::is_managed(int $space_id): bool',
			'SpaceLinkService::link(int $space_id, string $source, string $type, int $object_id): bool',
			'SpaceLinkService::unlink(int $space_id, string $source, string $type, int $object_id): void',
			'SpaceLinkService::release_object(string $source, string $type, int $object_id): array',
			'SpaceLinkService::create_and_link(string $source, string $type, int $object_id, int $owner_id): int',
			'SpaceLinkService::join_from_source(int $space_id, int $user_id): bool',
			'SpaceLinkService::leave_from_source(int $space_id, int $user_id, string $source, string $type, int $object_id): bool',
			'SpaceLinkService::backfill(int $space_id, string $source, string $type, int $object_id, int $after=): int',
			'SpaceLinkService::run_backfill_chunk(mixed $space_id=, mixed $source=, mixed $type=, mixed $object_id=, mixed $after=): void',
			'IntegrationActivity::publish(int $member_id, string $content, string $link_url, string $link_title=, string $type=, string $excerpt=, int $space_id=, array $meta=): mixed static',
			'IntegrationActivity::remove(string $link_url, string $type=): int static',
			'IntegrationActivity::withdraw(string $link_url, string $type=): bool static',
			'IntegrationActivity::restore(string $link_url, string $type=): bool static',
			'IntegrationActivity::refresh(string $link_url, string $type, array $meta): bool static',
			'IntegrationActivity::refresh_by_meta(string $type, string $meta_key, int $value, array $meta): int static',
			'IntegrationActivity::remove_by_meta(string $type, string $meta_key, int $value): int static',
			'IntegrationActivity::withdraw_by_meta(string $type, string $meta_key, int $value): int static',
			'IntegrationActivity::restore_by_meta(string $type, string $meta_key, int $value): int static',
			'IntegrationActivity::rewrite_by_meta(string $type, string $meta_key, int $value, string $content, string $link_url, string $link_title=, string $excerpt=, array $meta=): int static',
			'IntegrationActivity::rewrite(string $old_link_url, string $type, string $content, string $link_url, string $link_title=, string $excerpt=, array $meta=): int static',
			'IntegrationActivity::set_space_by_meta(string $type, string $meta_key, int $value, int $space_id): int static',
			'IntegrationActivity::card_types(): array static',
			'IntegrationActivity::render_bridge_card(array $args, string $icon=, string $label=): string static',
		);
	}

	/**
	 * Public signatures as they are now.
	 *
	 * @return string[]
	 */
	private function current(): array {
		$out = array();
		// Whole classes: every public method is promised.
		foreach ( array( 'Addons\\AbstractAddon', 'Addons\\AddonRegistry', 'Addons\\FeedCardModule', 'Addons\\NotificationSource', 'Addons\\SearchSource', 'Addons\\SettingsModule', 'Addons\\DiscoveryModule', 'Addons\\ProfilePanelModule', 'Addons\\SpaceLinkSource', 'Integrations\\IntegrationRegistry', 'Spaces\\SpaceLinkService' ) as $name ) {
			$out = array_merge( $out, $this->signatures( new ReflectionClass( 'BuddyNext\\' . $name ), false ) );
		}
		// Classes that are mostly plumbing: only the methods marked @api are promised.
		foreach ( array( 'Feed\\IntegrationActivity' ) as $name ) {
			$out = array_merge( $out, $this->signatures( new ReflectionClass( 'BuddyNext\\' . $name ), true ) );
		}
		return $out;
	}

	/**
	 * Public signatures of one class.
	 *
	 * @param ReflectionClass $class    Class.
	 * @param bool            $api_only Only methods whose docblock carries @api.
	 * @return string[]
	 */
	private function signatures( ReflectionClass $class, bool $api_only ): array {
		$out = array();
		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $api_only && false === strpos( (string) $method->getDocComment(), '@api' ) ) {
				continue;
			}
			$params = array();
			foreach ( $method->getParameters() as $param ) {
				$params[] = ( $param->getType() ? $param->getType() : 'mixed' ) . ' $' . $param->getName() . ( $param->isOptional() ? '=' : '' );
			}
			$out[] = $class->getShortName() . '::' . $method->getName() . '(' . implode( ', ', $params ) . '): ' . ( $method->getReturnType() ? $method->getReturnType() : 'mixed' )
				. ( $method->isStatic() ? ' static' : '' ) . ( $method->isFinal() ? ' final' : '' ) . ( $method->isAbstract() ? ' abstract' : '' );
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
