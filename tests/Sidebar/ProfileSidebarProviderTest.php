<?php
/**
 * Tests for ProfileSidebarProvider.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Sidebar\Providers\ProfileSidebarProvider;
use BuddyNext\Sidebar\Surface;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Sidebar\Providers\ProfileSidebarProvider
 */
class ProfileSidebarProviderTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Surface::reset();
		parent::tear_down();
	}

	public function test_unrelated_surface_returns_list_unchanged(): void {
		$this->assertSame( array(), ( new ProfileSidebarProvider() )->widgets( array(), 'feed' ) );
	}

	public function test_profile_surface_without_context_returns_list_unchanged(): void {
		// No Surface::set() called at all — context() defaults to [].
		$this->assertSame( array(), ( new ProfileSidebarProvider() )->widgets( array(), 'profile' ) );
	}

	/**
	 * A full, all-populated context: every one of the seven cards must appear,
	 * every descriptor is self-chromed, and priorities preserve the original
	 * partial's render order.
	 */
	public function test_profile_surface_with_full_context_returns_all_seven_self_chromed_descriptors(): void {
		Surface::set(
			'profile',
			array(
				'is_own_profile' => true,
				'completion'     => array( 'percent' => 40 ),
				'social_links'   => array(
					array(
						'label' => 'Website',
						'value' => 'https://example.com',
					),
				),
				'work_entries'   => array(
					array(
						array(
							'field_key' => 'work_company',
							'value'     => 'Acme',
						),
					),
				),
				'edu_entries'    => array(
					array(
						array(
							'field_key' => 'edu_institution',
							'value'     => 'State U',
						),
					),
				),
				'skills'         => array( 'PHP', 'JS' ),
				'interest_chips' => array(
					array(
						'name' => 'Design',
						'url'  => 'https://example.com/design',
					),
				),
				'member_spaces'  => array(
					(object) array(
						'id'   => 1,
						'name' => 'Test Space',
						'slug' => 'test-space',
						'role' => 'member',
					),
				),
				'get_fv'         => static fn( string $g, string $f ): string => '', // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixture stub matches the real get_fv() signature.
				'entry_fv'       => static function ( array $entry, string $key ): string {
					foreach ( $entry as $f ) {
						if ( ( $f['field_key'] ?? '' ) === $key ) {
							return (string) $f['value'];
						}
					}
					return '';
				},
				'strength_tasks' => array(
					array(
						'label' => 'Add a bio',
						'done'  => false,
					),
				),
			)
		);

		$widgets = ( new ProfileSidebarProvider() )->widgets( array(), 'profile' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		$this->assertSame(
			array( 'profile-strength', 'profile-connect', 'profile-work', 'profile-education', 'profile-interests', 'profile-skills', 'profile-member-of' ),
			$ids
		);

		foreach ( $widgets as $widget ) {
			$this->assertArrayHasKey( 'chrome', $widget );
			$this->assertFalse( $widget['chrome'], "Widget '{$widget['id']}' must be self-chromed (chrome => false)." );
			$this->assertSame( array( 'profile' ), $widget['surfaces'] );
		}
	}

	/**
	 * Sparse context: only work_entries is non-empty (plus the own-profile
	 * strength gate). Cards backed by empty data must NOT be appended at all.
	 */
	public function test_profile_surface_with_sparse_context_includes_only_populated_cards(): void {
		Surface::set(
			'profile',
			array(
				'is_own_profile' => true,
				'completion'     => array( 'percent' => 10 ),
				'social_links'   => array(),
				'work_entries'   => array(
					array(
						array(
							'field_key' => 'work_company',
							'value'     => 'Acme',
						),
					),
				),
				'edu_entries'    => array(),
				'skills'         => array(),
				'interest_chips' => array(),
				'member_spaces'  => array(),
				'get_fv'         => static fn( string $g, string $f ): string => '', // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixture stub matches the real get_fv() signature.
				'entry_fv'       => static function ( array $entry, string $key ): string {
					foreach ( $entry as $f ) {
						if ( ( $f['field_key'] ?? '' ) === $key ) {
							return (string) $f['value'];
						}
					}
					return '';
				},
				'strength_tasks' => array(
					array(
						'label' => 'Add a bio',
						'done'  => false,
					),
				),
			)
		);

		$widgets = ( new ProfileSidebarProvider() )->widgets( array(), 'profile' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		$this->assertContains( 'profile-strength', $ids );
		$this->assertContains( 'profile-work', $ids );
		$this->assertNotContains( 'profile-connect', $ids );
		$this->assertNotContains( 'profile-education', $ids );
		$this->assertNotContains( 'profile-interests', $ids );
		$this->assertNotContains( 'profile-skills', $ids );
		$this->assertNotContains( 'profile-member-of', $ids );
		$this->assertCount( 2, $widgets );

		foreach ( $widgets as $widget ) {
			$this->assertArrayHasKey( 'chrome', $widget );
			$this->assertFalse( $widget['chrome'] );
		}
	}

	/**
	 * The hard gate: a VISITOR (is_own_profile = false) must never see the
	 * Profile Strength card, even when completion/strength_tasks are present.
	 */
	public function test_profile_strength_absent_when_not_own_profile(): void {
		Surface::set(
			'profile',
			array(
				'is_own_profile' => false,
				'completion'     => array( 'percent' => 100 ),
				'social_links'   => array(
					array(
						'label' => 'Website',
						'value' => 'https://example.com',
					),
				),
				'work_entries'   => array(),
				'edu_entries'    => array(),
				'skills'         => array(),
				'interest_chips' => array(),
				'member_spaces'  => array(),
				'get_fv'         => static fn( string $g, string $f ): string => '', // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixture stub matches the real get_fv() signature.
				'entry_fv'       => static fn( array $entry, string $f ): string => '', // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixture stub matches the real entry_fv() signature.
				'strength_tasks' => array(
					array(
						'label' => 'Add a bio',
						'done'  => true,
					),
				),
			)
		);

		$widgets = ( new ProfileSidebarProvider() )->widgets( array(), 'profile' );
		$ids     = wp_list_pluck( $widgets, 'id' );

		$this->assertNotContains( 'profile-strength', $ids );
		$this->assertContains( 'profile-connect', $ids );
	}

	/**
	 * A null `completion` (own_profile true but ProfileService returned no
	 * score) is also a hard gate — mirrors the original partial's
	 * `null !== $completion` condition.
	 */
	public function test_profile_strength_absent_when_completion_is_null(): void {
		Surface::set(
			'profile',
			array(
				'is_own_profile' => true,
				'completion'     => null,
				'social_links'   => array(),
				'work_entries'   => array(),
				'edu_entries'    => array(),
				'skills'         => array(),
				'interest_chips' => array(),
				'member_spaces'  => array(),
				'get_fv'         => static fn( string $g, string $f ): string => '', // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixture stub matches the real get_fv() signature.
				'entry_fv'       => static fn( array $entry, string $f ): string => '', // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixture stub matches the real entry_fv() signature.
				'strength_tasks' => array(
					array(
						'label' => 'Add a bio',
						'done'  => false,
					),
				),
			)
		);

		$widgets = ( new ProfileSidebarProvider() )->widgets( array(), 'profile' );
		$this->assertNotContains( 'profile-strength', wp_list_pluck( $widgets, 'id' ) );
	}

	public function test_max_widgets_raises_cap_to_seven_on_profile_surface_only(): void {
		$provider = new ProfileSidebarProvider();
		$this->assertSame( 7, $provider->max_widgets( 6, 'profile' ) );
		$this->assertSame( 6, $provider->max_widgets( 6, 'feed' ), 'Other surfaces must keep the registry default untouched.' );
		$this->assertSame( 10, $provider->max_widgets( 10, 'profile' ), 'A higher externally-set max must never be lowered.' );
	}
}
