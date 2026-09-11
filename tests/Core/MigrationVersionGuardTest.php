<?php
/**
 * A migration is worthless unless SCHEMA_VERSION is bumped to run it.
 *
 * maybe_upgrade() early-returns the moment the stored `buddynext_schema_version`
 * equals Installer::SCHEMA_VERSION (and the schema is intact). So every
 * `maybe_migrate_*()` it calls runs on an already-upgraded site ONLY when the
 * constant has moved past the value that site last stored. Add a migration
 * without bumping SCHEMA_VERSION and it never runs anywhere the plugin is already
 * current — exactly the systemic defect behind card 10285715373, where the seeded
 * Education year fields kept rendering as plain number inputs because the
 * conversion migration shipped without a version bump to gate it.
 *
 * This is the durable guard (card 10285715373, D-8): it fails the moment the set
 * of `maybe_migrate_*` methods drifts from the pinned snapshot, forcing the author
 * to consciously (a) bump Installer::SCHEMA_VERSION so the new migration actually
 * runs, and (b) re-pin the snapshot below — the two are edited together, in the
 * same commit, on purpose. It also fails if the migration set was re-pinned while
 * MIGRATIONS_LOCKED_AT_VERSION was left disagreeing with the live constant, so a
 * silent "updated the list, forgot the version" cannot pass unnoticed.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * Guards the SCHEMA_VERSION ⇄ maybe_migrate_* coupling.
 *
 * @covers \BuddyNext\Core\Installer::maybe_upgrade
 */
class MigrationVersionGuardTest extends \WP_UnitTestCase {

	/**
	 * The complete set of version-gated migration methods, sorted, as of the
	 * schema version below. RE-PIN THIS TOGETHER WITH A SCHEMA_VERSION BUMP when
	 * you add or remove a maybe_migrate_* method — never one without the other.
	 *
	 * @var string[]
	 */
	private const EXPECTED_MIGRATIONS = array(
		'maybe_migrate_checkbox_fields',
		'maybe_migrate_email_subjects',
		'maybe_migrate_file_fields',
		'maybe_migrate_jetonomy_feed_sync',
		'maybe_migrate_nav_default_labels',
		'maybe_migrate_skills_field_key',
		'maybe_migrate_space_options',
		'maybe_migrate_wizard_preset_types',
		'maybe_migrate_year_fields',
	);

	/**
	 * The SCHEMA_VERSION the snapshot above was locked at. Must equal the live
	 * constant; bump both together.
	 *
	 * @var int
	 */
	private const MIGRATIONS_LOCKED_AT_VERSION = 56;

	/**
	 * Read every `private static function maybe_migrate_*` name defined in
	 * Installer.php, sorted.
	 *
	 * @return string[]
	 */
	private function source_migrations(): array {
		$ref    = new \ReflectionClass( Installer::class );
		$source = (string) file_get_contents( (string) $ref->getFileName() );
		preg_match_all(
			'/private\s+static\s+function\s+(maybe_migrate_[a-z0-9_]+)\s*\(/',
			$source,
			$matches
		);
		$names = array_values( array_unique( $matches[1] ) );
		sort( $names );
		return $names;
	}

	public function test_migration_set_matches_the_pinned_snapshot(): void {
		$this->assertSame(
			self::EXPECTED_MIGRATIONS,
			$this->source_migrations(),
			"The set of Installer::maybe_migrate_* methods changed.\n"
			. "A migration only runs on an already-current site when maybe_upgrade() "
			. "does NOT early-return, which requires Installer::SCHEMA_VERSION to be "
			. "bumped past the value deployed sites last stored.\n"
			. "=> Bump Installer::SCHEMA_VERSION, then update EXPECTED_MIGRATIONS *and* "
			. "MIGRATIONS_LOCKED_AT_VERSION in this test to the new version, together."
		);
	}

	public function test_snapshot_version_tracks_the_live_constant(): void {
		$live = (int) ( new \ReflectionClass( Installer::class ) )->getConstant( 'SCHEMA_VERSION' );
		$this->assertSame(
			$live,
			self::MIGRATIONS_LOCKED_AT_VERSION,
			'MIGRATIONS_LOCKED_AT_VERSION must equal Installer::SCHEMA_VERSION. If you '
			. 'bumped the schema version, re-pin the migration snapshot (this is the '
			. 'prompt to confirm the new migration is gated by the bump).'
		);
	}

	public function test_every_migration_is_actually_invoked(): void {
		// A maybe_migrate_* that is defined but never called is dead — it would never
		// run regardless of the version gate. Assert each is wired via self::name().
		$ref    = new \ReflectionClass( Installer::class );
		$source = (string) file_get_contents( (string) $ref->getFileName() );
		foreach ( $this->source_migrations() as $name ) {
			$this->assertStringContainsString(
				'self::' . $name . '(',
				$source,
				"Migration {$name}() is defined but never invoked — it can never run."
			);
		}
	}

	public function test_year_field_migration_is_present_and_wired(): void {
		// Ties this guard to the card that created it: the Education year conversion
		// must stay in the gated set, or existing sites regress to number inputs.
		$this->assertContains( 'maybe_migrate_year_fields', $this->source_migrations() );
	}
}
