<?php
/**
 * Reset BuddyNext's in-memory state between tests.
 *
 * WP_UnitTestCase wraps every test in a transaction and rolls it back, so the
 * DATABASE returns to its prior state. Nothing rolls back a PHP static. Any class
 * that memoises an answer therefore carries it into the next test, where the
 * database it was derived from no longer says the same thing.
 *
 * That is the second cause of this suite's non-determinism (the first was the
 * installer's DDL breaking the transaction outright — Free ea38d182). It shows up
 * as tests that pass alone and fail in company, with the failing set shifting run
 * to run:
 *
 *   BlockService::$blocking_pair_cache      → privacy and visibility tests
 *   PermissionService::$role_map_cache      → 401 where 200 was expected
 *   Sidebar\Surface::$current / $context    → sidebar descriptor counts
 *   ModerationService::$reasons_cache       → moderation reason lookups
 *   the registry singletons                 → whatever a prior test registered
 *
 * Registered as a PHPUnit hook rather than a base class, deliberately: 2,462 tests
 * extend WP_UnitTestCase directly, and a new one always will. A hook cannot be
 * forgotten by the next person writing a test.
 *
 * ## Why reflection rather than reset methods
 *
 * Several of these have no public reset and should not grow one just to satisfy
 * the suite — a production API that exists only for tests is worse than the
 * reflection here, which is confined to this file.
 *
 * Reflection is used uniformly, including for the few classes that DO have a
 * reset method. Restoring a declared value is the whole job, and mixing two
 * mechanisms would mean the behaviour of this hook depended on which class it was
 * clearing — the sort of difference nobody remembers when adding the next entry.
 *
 * ## The list cannot rot
 *
 * {@see \BuddyNext\Tests\Support\StaticStateInventoryTest} enumerates every static
 * property under includes/ and fails when one is neither reset here nor listed as
 * deliberately persistent. A new memo added next year is a failing test, not a new
 * flake.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

use PHPUnit\Runner\BeforeTestHook;

/**
 * Clears memoised state before each test.
 */
final class StateReset implements BeforeTestHook {

	/**
	 * Statics that memoise a DERIVED answer and must be cleared between tests.
	 *
	 * class => [ property => value to restore ]
	 *
	 * @var array<class-string, array<string, mixed>>
	 */
	public const MEMOS = array(
		\BuddyNext\SocialGraph\BlockService::class        => array( 'blocking_pair_cache' => array() ),
		\BuddyNext\Core\PermissionService::class          => array( 'role_map_cache' => null ),
		\BuddyNext\Moderation\ModerationService::class    => array( 'reasons_cache' => null ),
		\BuddyNext\Profile\MemberDirectoryService::class  => array( 'mirror_keys_memo' => null ),
		\BuddyNext\Core\Installer::class                  => array(
			'schema_check_result' => null,
			'last_schema_errors'  => array(),
		),
		\BuddyNext\Sidebar\Surface::class                 => array(
			'current' => '',
			'context' => array(),
		),
		\BuddyNext\Admin\AdminHub::class                  => array(
			'placement_cache' => null,
			'sections_cache'  => null,
		),
		\BuddyNext\Core\HeadMeta::class                   => array( 'emitted' => false ),
		\BuddyNext\Core\PageRouter::class                 => array(
			'rendering'     => null,
			'title_claimed' => false,
		),
		// Re-entrancy flags. A test that dies mid-sync leaves these true and every
		// later test silently skips the work they guard.
		\BuddyNext\Bridges\JetonomyBridge::class          => array( 'syncing' => false ),
		\BuddyNext\Feed\BlogCommentSync::class            => array( 'syncing' => false ),
		\BuddyNext\Bridges\WPMediaVerseBridge::class      => array( 'suppress_upload_activity' => false ),
		\BuddyNext\Feed\IntegrationActivity::class        => array( 'system_publish' => false ),
		\BuddyNext\Outbound\WebhookLog::class             => array( 'in_request' => false ),
	);

	/**
	 * Statics that are deliberately NOT reset, each with the reason.
	 *
	 * Read by the inventory test, so a decision to leave something alone is
	 * recorded rather than implied by absence.
	 *
	 * @var array<string, string>
	 */
	public const PERSISTENT = array(
		'BuddyNext\Core\Plugin::booted'                        => 'Resetting this would re-run the whole boot on the next test, registering every hook a second time.',
		'BuddyNext\Core\Container::instance'                   => 'The service container IS the application; rebuilding it per test would discard every binding the bootstrap made.',
		'BuddyNext\Core\IconService::icons_dir'                => 'A filesystem path resolved once. Not derived from the database.',
		'BuddyNext\Core\IconService::emoji_dir'                => 'As above.',
		'BuddyNext\Core\HubRegistry::instance'                 => 'Populated at boot from code, not from the database. Tests that need a different registry build their own.',
		'BuddyNext\Nav\NavRegistry::instance'                  => 'As above.',
		'BuddyNext\Integrations\IntegrationRegistry::instance' => 'As above.',
		'BuddyNext\Spaces\SpaceTypeRegistry::instance'         => 'As above.',
		'BuddyNext\Spaces\SpaceFieldRegistry::instance'        => 'As above.',
		'BuddyNext\Admin\AdminHub::instance'                   => 'Registrar built from code at boot; its DERIVED caches are reset above instead.',
		'BuddyNext\Admin\AdminHub::tabs'                       => 'Registered from code at boot, not per test.',
		'BuddyNext\Admin\AdminHub::legacy_pages'               => 'As above.',
		'BuddyNext\Admin\Settings\SettingsRegistry::pages'     => 'Registered from code at boot, not per test.',
	);

	/**
	 * Clear the memos before a test runs.
	 *
	 * @param string $test Fully-qualified test name (unused).
	 * @return void
	 */
	public function executeBeforeTest( string $test ): void {
		foreach ( self::MEMOS as $class => $properties ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			foreach ( $properties as $property => $value ) {
				self::reset_property( $class, $property, $value );
			}
		}
	}

	/**
	 * Restore one static property to a declared value.
	 *
	 * @param class-string $class    Class holding the property.
	 * @param string       $property Property name.
	 * @param mixed        $value    Value to restore.
	 * @return void
	 */
	private static function reset_property( string $class, string $property, $value ): void {
		try {
			$reflected = new \ReflectionProperty( $class, $property );
		} catch ( \ReflectionException $e ) {
			// The property was renamed or removed. The inventory test reports that
			// properly; failing every test in the suite over it would not.
			return;
		}

		// No setAccessible() call: it has been a no-op since PHP 8.1 and is
		// deprecated in 8.5, where it would print a notice per property per test -
		// roughly 40,000 lines across this suite.
		$reflected->setValue( null, $value );
	}
}
