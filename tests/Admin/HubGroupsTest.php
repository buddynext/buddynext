<?php
/**
 * The admin hub shows its sections under owner-facing groups without changing any URL.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;

/**
 * Groups are display only: every section lands in exactly one group, and a section
 * added through the `bn_admin_hub_sections` filter with no group is its own top-bar
 * entry instead of vanishing.
 *
 * @covers \BuddyNext\Admin\AdminHub
 */
class HubGroupsTest extends \WP_UnitTestCase {

	/**
	 * Tab registry before the test, restored after.
	 *
	 * @var array<string, mixed>
	 */
	private array $tabs_before = array();

	public function set_up(): void {
		parent::set_up();
		$this->tabs_before = ( new \ReflectionProperty( AdminHub::class, 'tabs' ) )->getValue();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->reset_sections_cache();
	}

	public function tear_down(): void {
		remove_all_filters( 'bn_admin_hub_sections' );
		( new \ReflectionProperty( AdminHub::class, 'tabs' ) )->setValue( null, $this->tabs_before );
		$this->reset_sections_cache();
		parent::tear_down();
	}

	private function reset_sections_cache(): void {
		( new \ReflectionProperty( AdminHub::class, 'sections_cache' ) )->setValue( null, null );
	}

	/**
	 * Call a private static hub method.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function hub( string $name, array $args = array() ) {
		return ( new \ReflectionMethod( AdminHub::class, $name ) )->invokeArgs( null, $args );
	}

	public function test_every_default_section_resolves_to_a_group_the_bar_knows(): void {
		$groups = $this->hub( 'groups' );
		foreach ( AdminHub::sections() as $key => $section ) {
			$group = $this->hub( 'section_group', array( (string) $key ) );
			$this->assertTrue(
				isset( $groups[ $group ] ) || $group === (string) $key,
				"Section {$key} must sit in a known group or be its own group (got {$group})."
			);
		}
	}

	public function test_moderation_and_automation_share_a_group_but_keep_their_own_pages(): void {
		$this->assertSame( 'moderation', $this->hub( 'section_group', array( 'moderation' ) ) );
		$this->assertSame( 'moderation', $this->hub( 'section_group', array( 'automation' ) ) );
		$this->assertSame( 'buddynext-moderation', AdminHub::section_slug( 'moderation' ) );
		$this->assertSame( 'buddynext-automation', AdminHub::section_slug( 'automation' ) );
	}

	public function test_a_filter_added_section_with_no_group_is_its_own_bar_entry(): void {
		add_filter(
			'bn_admin_hub_sections',
			static function ( array $sections ): array {
				$sections['zz-extra'] = array(
					'slug'  => 'buddynext-zz-extra',
					'label' => 'ZZ Extra',
				);
				return $sections;
			}
		);
		$this->reset_sections_cache();
		AdminHub::register_tab( 'zz-extra', 'main', 'Main', static function (): void {} );

		$groups = $this->hub( 'visible_groups' );
		$this->assertArrayHasKey( 'zz-extra', $groups, 'An ungrouped section must not vanish from the bar.' );
		$this->assertSame( 'ZZ Extra', $groups['zz-extra']['label'] );
	}
}
