<?php
/**
 * The Members admin list and CSV export by member type.
 *
 * The filter is one indexed condition on the existing assignments table, added to that one query only.
 * Three things have to hold: only that type's members come back (with the right total), an unknown type
 * returns nobody rather than everybody, and the condition does not leak into the next query.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Members;
use BuddyNext\Admin\Members\MemberExport;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Admin\Members::list_members
 * @covers \BuddyNext\Admin\Members\MemberExport::export_members_csv
 * @covers \BuddyNext\MemberTypes\MemberTypeService::within_type
 * @covers \BuddyNext\MemberTypes\MemberTypeService::names_for_users
 */
class MembersTypeFilterTest extends \WP_UnitTestCase {

	private int $pro_user = 0;
	private int $fan_user = 0;
	private int $plain_user = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$types = buddynext_service( 'member_types' );

		$pro = $types->create( array( 'slug' => 'qa-pro', 'name' => 'QA Professional' ) );
		$fan = $types->create( array( 'slug' => 'qa-fan', 'name' => 'QA Enthusiast' ) );

		$this->pro_user   = self::factory()->user->create( array( 'display_name' => 'Pro Person' ) );
		$this->fan_user   = self::factory()->user->create( array( 'display_name' => 'Fan Person' ) );
		$this->plain_user = self::factory()->user->create( array( 'display_name' => 'Plain Person' ) );
		$types->assign_type( $this->pro_user, (int) $pro );
		$types->assign_type( $this->fan_user, (int) $fan );
	}

	public function test_the_list_returns_only_that_types_members_with_the_right_total(): void {
		$data = ( new Members() )->list_members( array( 'member_type' => 'qa-pro' ) );

		$this->assertSame( array( $this->pro_user ), array_column( $data['members'], 'id' ) );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( array( 'QA Professional' ), $data['members'][0]['member_types'] );
	}

	public function test_an_unknown_type_returns_nobody_not_everybody(): void {
		$data = ( new Members() )->list_members( array( 'member_type' => 'no-such-type' ) );

		$this->assertSame( 0, $data['total'] );
		$this->assertSame( array(), $data['members'] );
	}

	public function test_no_filter_lists_everyone_and_the_filter_does_not_leak_into_the_next_query(): void {
		( new Members() )->list_members( array( 'member_type' => 'qa-pro' ) );

		$ids = array_column( ( new Members() )->list_members( array( 'per_page' => 100 ) )['members'], 'id' );
		$this->assertContains( $this->plain_user, $ids );
		$this->assertContains( $this->fan_user, $ids );
	}

	public function test_the_export_has_a_type_column_and_follows_the_filter(): void {
		ob_start();
		( new MemberExport() )->export_members_csv( array( 'member_type' => 'qa-fan' ) );
		$csv = (string) ob_get_clean();

		$this->assertStringContainsString( 'Member type', strtok( $csv, "\n" ) );
		$this->assertStringContainsString( 'Fan Person', $csv );
		$this->assertStringContainsString( 'QA Enthusiast', $csv );
		$this->assertStringNotContainsString( 'Pro Person', $csv );
		$this->assertStringNotContainsString( 'Plain Person', $csv );
	}

	public function test_the_unfiltered_export_lists_everyone_with_their_type(): void {
		ob_start();
		( new MemberExport() )->export_members_csv();
		$csv = (string) ob_get_clean();

		$this->assertStringContainsString( 'Plain Person', $csv );
		$this->assertMatchesRegularExpression( '/Pro Person.*QA Professional/', $csv );
	}

	public function test_the_export_follows_the_active_and_suspended_tabs(): void {
		( new \BuddyNext\Moderation\ModerationService() )->suspend( $this->fan_user, 'test', 0 );

		ob_start();
		( new MemberExport() )->export_members_csv( array( 'status' => 'suspended' ) );
		$suspended = (string) ob_get_clean();
		ob_start();
		( new MemberExport() )->export_members_csv( array( 'status' => 'active' ) );
		$active = (string) ob_get_clean();

		$this->assertStringContainsString( 'Fan Person', $suspended );
		$this->assertStringNotContainsString( 'Pro Person', $suspended );
		$this->assertStringNotContainsString( 'Fan Person', $active );
		$this->assertStringContainsString( 'Pro Person', $active );
	}
}
