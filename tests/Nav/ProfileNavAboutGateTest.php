<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The About tab shows only when the About panel would render something.
 *
 * The gate used to count any non-empty stored string, while the panel renders a
 * field only when FieldType::render_display() returns output. An unticked
 * boolean is stored as '0': the gate said "content", the panel printed nothing,
 * and the member's About tab opened blank.
 *
 * @package BuddyNext\Tests\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Nav;

use BuddyNext\Core\Installer;
use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;
use BuddyNext\Nav\Providers\ProfileNav;
use WP_UnitTestCase;

/**
 * About-tab gate parity with the About panel.
 */
class ProfileNavAboutGateTest extends WP_UnitTestCase {

	/**
	 * Member under test.
	 *
	 * @var int
	 */
	private int $member = 0;

	/**
	 * Schema, a boolean field outside the hero keys, and a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		NavRegistry::instance()->reset();
		remove_all_actions( 'buddynext_register_nav' );
		( new ProfileNav() )->register();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$group_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_groups ORDER BY id LIMIT 1" );

		$field_id = buddynext_service( 'profiles' )->create_field(
			array(
				'group_id'   => $group_id,
				'field_key'  => 'qa_about_flag',
				'label'      => 'Open to mentoring',
				'type'       => 'boolean',
				'visibility' => 'public',
			)
		);
		$this->assertIsInt( $field_id, 'Could not create the boolean fixture field.' );

		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Primary tab ids for the member's profile, seen by the member.
	 *
	 * @return string[]
	 */
	private function primary_ids(): array {
		$out = NavRegistry::instance()->resolve( new NavContext( 'profile', $this->member, $this->member ) );
		return array_map( static fn( $n ) => $n->id, $out->layer( 'primary' ) );
	}

	/**
	 * Only an unticked boolean stored ('0'): the panel renders nothing, so no tab.
	 * Fails on 1.2.9, where '0' counted as content.
	 *
	 * @return void
	 */
	public function test_unticked_boolean_alone_does_not_open_an_empty_about_tab(): void {
		buddynext_service( 'profiles' )->save_profile( $this->member, array( 'qa_about_flag' => '0' ) );

		$this->assertNotContains( 'about', $this->primary_ids() );
	}

	/**
	 * Positive control: a ticked boolean renders, so the tab shows.
	 *
	 * @return void
	 */
	public function test_ticked_boolean_shows_the_about_tab(): void {
		buddynext_service( 'profiles' )->save_profile( $this->member, array( 'qa_about_flag' => '1' ) );

		$this->assertContains( 'about', $this->primary_ids() );
	}

	/**
	 * A site filter that drops every About group empties the panel, so the gate
	 * must hide the tab too. Fails on 1.2.9, which read the unfiltered groups.
	 *
	 * @return void
	 */
	public function test_about_groups_filter_is_honoured_by_the_gate(): void {
		buddynext_service( 'profiles' )->save_profile( $this->member, array( 'qa_about_flag' => '1' ) );
		add_filter( 'buddynext_profile_about_groups', '__return_empty_array' );

		$this->assertNotContains( 'about', $this->primary_ids() );
	}
}
