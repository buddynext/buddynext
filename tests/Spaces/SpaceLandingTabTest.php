<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests SpaceService::landing_tab() — the one resolver the space page and REST
 * both use to decide which tab a space opens on.
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.2.1
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Covers the landing-tab resolution order and the buddynext_space_default_tab filter.
 */
class SpaceLandingTabTest extends WP_UnitTestCase {

	private SpaceService $spaces;
	private int $owner_id;
	private int $open_space;

	/**
	 * Seed an open space owned by a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->spaces     = new SpaceService();
		$this->owner_id   = self::factory()->user->create();
		$this->open_space = (int) $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Landing Open',
				'slug' => 'landing-open',
				'type' => 'open',
			)
		);
	}

	/**
	 * Build stub nav items: [ id => renderable-bool ].
	 *
	 * @param array<string,bool> $spec Tab id => whether it renders.
	 * @return array<int,object>
	 */
	private function nav( array $spec ): array {
		$items = array();
		foreach ( $spec as $id => $render ) {
			$items[] = new class( (string) $id, (bool) $render ) {
				public function __construct( public string $id, private bool $renderable ) {}
				public function has_render(): bool {
					return $this->renderable;
				}
			};
		}
		return $items;
	}

	/**
	 * The hydrated space row for an id.
	 *
	 * @param int $id Space id.
	 * @return array<string,mixed>
	 */
	private function row( int $id ): array {
		return (array) $this->spaces->get( $id );
	}

	public function test_explicit_url_tab_wins(): void {
		$nav = $this->nav( array( 'feed' => true, 'members' => true ) );
		$this->assertSame( 'members', $this->spaces->landing_tab( $this->row( $this->open_space ), $this->owner_id, $nav, 'members' ) );
	}

	public function test_empty_uses_first_nav_tab(): void {
		$nav = $this->nav( array( 'feed' => true, 'members' => true, 'about' => true ) );
		$this->assertSame( 'feed', $this->spaces->landing_tab( $this->row( $this->open_space ), $this->owner_id, $nav, '' ) );
	}

	public function test_space_default_tab_beats_site_order(): void {
		update_space_meta( $this->open_space, 'default_tab', 'about' );
		$nav = $this->nav( array( 'feed' => true, 'members' => true, 'about' => true ) );
		$this->assertSame( 'about', $this->spaces->landing_tab( $this->row( $this->open_space ), $this->owner_id, $nav, '' ) );
	}

	public function test_hidden_default_falls_back_to_first_renderable(): void {
		update_space_meta( $this->open_space, 'default_tab', 'media' ); // Not in the nav below.
		$nav = $this->nav( array( 'feed' => true, 'members' => true ) );
		$this->assertSame( 'feed', $this->spaces->landing_tab( $this->row( $this->open_space ), $this->owner_id, $nav, '' ) );
	}

	public function test_dedicated_page_tab_default_falls_back_to_first_inline(): void {
		// 'members' is a visible tab but a DEDICATED page (has_render() false), not
		// an inline landing target, so it is not honoured as a landing tab.
		update_space_meta( $this->open_space, 'default_tab', 'members' );
		$nav = $this->nav( array( 'feed' => true, 'members' => false, 'about' => true ) );
		$this->assertSame( 'feed', $this->spaces->landing_tab( $this->row( $this->open_space ), $this->owner_id, $nav, '' ) );
	}

	public function test_filter_applies_to_default_but_not_to_url(): void {
		add_filter( 'buddynext_space_default_tab', static fn(): string => 'members' );
		$nav = $this->nav( array( 'feed' => true, 'members' => true ) );
		$this->assertSame( 'members', $this->spaces->landing_tab( $this->row( $this->open_space ), $this->owner_id, $nav, '' ) );
		$this->assertSame( 'feed', $this->spaces->landing_tab( $this->row( $this->open_space ), $this->owner_id, $nav, 'feed' ) );
		remove_all_filters( 'buddynext_space_default_tab' );
	}

	public function test_non_member_of_private_space_lands_on_about(): void {
		$private  = (int) $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Landing Private',
				'slug' => 'landing-private',
				'type' => 'private',
			)
		);
		$stranger = self::factory()->user->create();
		$nav      = $this->nav( array( 'feed' => true, 'members' => true, 'about' => true ) );

		// A non-member cannot read a private space's content, so lands on About.
		$this->assertSame( 'about', $this->spaces->landing_tab( $this->row( $private ), $stranger, $nav, '' ) );
		// The owner (a member) is not forced to About.
		$this->assertSame( 'feed', $this->spaces->landing_tab( $this->row( $private ), $this->owner_id, $nav, '' ) );
	}
}
