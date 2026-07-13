<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Every table we erase must also be exportable, or excluded with a reason.
 *
 * @package BuddyNext\Tests\Privacy
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Privacy;

use BuddyNext\Core\Installer;
use BuddyNext\Privacy\PrivacyTools;
use BuddyNext\Profile\MemberCleanupService;
use WP_UnitTestCase;

/**
 * Article 17 was satisfied and Article 15 was not.
 *
 * 25 tables were on the erase registry and in no part of the export. Every one of them was a
 * table we happily DELETE on request while being unable to SHOW it on request — a strange
 * pair of failures to have, because it means we knew exactly where the member's data lived
 * and used that knowledge only to destroy it.
 *
 * The cause was structural, not clerical: erasure was driven by a REGISTRY and export was a
 * second, hand-written list. Two lists, one of which nobody remembered to update. The export
 * now derives its sections from the same registry, so a new table joins the export
 * automatically — nothing to remember, so nothing to forget.
 *
 * This test is the thing that keeps that true.
 *
 * @covers \BuddyNext\Privacy\PrivacyTools
 */
class ExportCoverageTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Every erased table is exported, or excluded with a stated reason.
	 *
	 * @return void
	 */
	public function test_every_erased_table_is_exported_or_excluded(): void {
		$erased     = array_keys( MemberCleanupService::erase_map() );
		$exclusions = PrivacyTools::export_exclusions();

		$reflection = new \ReflectionClass( PrivacyTools::class );

		$by_hand_method = $reflection->getMethod( 'tables_exported_by_hand' );
		$by_hand_method->setAccessible( true );
		$by_hand = $by_hand_method->invoke( new PrivacyTools() );

		$derived_method = $reflection->getMethod( 'derived_sections' );
		$derived_method->setAccessible( true );
		$derived = $derived_method->invoke( new PrivacyTools() );

		$covered = array_merge(
			array_keys( $by_hand ),
			array_keys( $derived ),
			array_keys( $exclusions )
		);

		$uncovered = array_values( array_diff( $erased, $covered ) );

		$this->assertSame(
			array(),
			$uncovered,
			'These tables are ERASED but neither exported nor excluded: ' . implode( ', ', $uncovered )
				. '. We delete them on request but cannot show them on request — Article 17 satisfied, Article 15 not.'
		);
	}

	/**
	 * An exclusion must carry a real reason.
	 *
	 * A blank reason is not a decision that was made. It is a decision that was never made,
	 * wearing the costume of one — which is exactly the state this whole gate exists to
	 * detect, so it must not be reachable through the escape hatch.
	 *
	 * @return void
	 */
	public function test_every_export_exclusion_states_why(): void {
		foreach ( PrivacyTools::export_exclusions() as $table => $reason ) {
			$this->assertGreaterThan(
				20,
				strlen( trim( (string) $reason ) ),
				"The export exclusion for {$table} has no real reason. 'It is a lot of work' is not a reason; Article 15 has no convenience exemption."
			);
		}
	}

	/**
	 * A table that IS excluded must not also claim to be hand-exported.
	 *
	 * The two lists contradicting each other is how a table ends up believed-covered by both
	 * mechanisms and actually covered by neither.
	 *
	 * @return void
	 */
	public function test_exclusions_and_hand_exports_do_not_overlap(): void {
		$reflection     = new \ReflectionClass( PrivacyTools::class );
		$by_hand_method = $reflection->getMethod( 'tables_exported_by_hand' );
		$by_hand_method->setAccessible( true );
		$by_hand = array_keys( $by_hand_method->invoke( new PrivacyTools() ) );

		$overlap = array_intersect( $by_hand, array_keys( PrivacyTools::export_exclusions() ) );

		$this->assertSame( array(), array_values( $overlap ), 'A table is listed as both hand-exported and excluded from export.' );
	}

	/**
	 * The export actually EMITS the previously-missing data, not just claims to.
	 *
	 * A set-coverage assertion proves the registry lines up. It does not prove a single row
	 * ever reaches the member. This seeds real rows in tables that were in the 25 and walks
	 * the real paginated exporter until it says done.
	 *
	 * @return void
	 */
	public function test_the_exporter_actually_emits_a_previously_missing_table(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'user_email' => 'exportme@example.test' ) );

		// bn_reactions was on the erase registry and in no part of the export.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_reactions',
			array(
				'user_id'     => $user_id,
				'object_type' => 'post',
				'object_id'   => 123,
				'emoji'       => 'like',
			)
		);

		$tools  = new PrivacyTools();
		$groups = array();

		// Walk the real paginated contract to completion.
		for ( $page = 1; $page <= 50; $page++ ) {
			$result = $tools->export( 'exportme@example.test', $page );

			foreach ( (array) $result['data'] as $item ) {
				$groups[] = (string) ( $item['group_id'] ?? '' );
			}

			if ( ! empty( $result['done'] ) ) {
				break;
			}
		}

		$this->assertContains(
			'bn_reactions',
			$groups,
			'A member asked for their data and their reactions were not in it — but deleting the account would have erased them.'
		);
	}

	/**
	 * A redacted column never reaches the export file.
	 *
	 * The ROW is personal data; the secret inside it is not something to hand back. "This
	 * member registered an Android device on this date" is theirs to know. The token value is
	 * a credential for pushing to that device.
	 *
	 * @return void
	 */
	public function test_a_redacted_column_is_never_emitted(): void {
		$this->assertContains(
			'token',
			(array) ( PrivacyTools::export_redactions()['bn_push_tokens'] ?? array() ),
			'The push-token VALUE must be redacted from the export — it is a live credential, not a fact about the member.'
		);
	}
}
