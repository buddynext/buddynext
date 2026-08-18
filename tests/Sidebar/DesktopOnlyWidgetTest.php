<?php
/**
 * A sidebar widget can declare that it has its own mobile surface.
 *
 * Below 1025px the right column no longer hides — it reflows under the content
 * (bn-shell.css), which is what makes registry widgets reachable on a phone at
 * all. That reflow created one collision: the profile hero already renders
 * `.bn-pf-completeness`, a compact completion chip shown ONLY below 1025px as
 * the Profile Strength card's deliberate mobile counterpart. With the column
 * now visible there, both would land on one screen.
 *
 * The viewport is unknowable server-side, so the descriptor expresses the
 * choice as markup the stylesheet can act on: `mobile => false` wraps the
 * widget in `.bn-sidebar-desktop-only`, which the media query hides. These
 * tests pin the wrapper's presence, its absence for ordinary widgets, and the
 * one rule that keeps it from producing empty chrome.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Sidebar\SidebarRegistry;
use BuddyNext\Sidebar\Surface;

/**
 * The `mobile => false` descriptor opt-out.
 *
 * @covers \BuddyNext\Sidebar\SidebarRegistry::render
 */
class DesktopOnlyWidgetTest extends \WP_UnitTestCase {

	/**
	 * Descriptors served to the registry for the current test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $descriptors = array();

	/**
	 * Registers the filter that feeds this test's descriptors to the registry.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Surface::set( 'test-surface' );
		add_filter( 'buddynext_sidebar_widgets', array( $this, 'serve_descriptors' ) );
	}

	/**
	 * Clears the surface so it cannot leak into an unrelated test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'buddynext_sidebar_widgets', array( $this, 'serve_descriptors' ) );
		Surface::reset();
		parent::tear_down();
	}

	/**
	 * Filter callback returning the descriptors under test.
	 *
	 * @return array<int,array<string,mixed>> Descriptors.
	 */
	public function serve_descriptors(): array {
		return $this->descriptors;
	}

	/**
	 * Renders the registry for the test surface and returns its markup.
	 *
	 * @param array<int,array<string,mixed>> $descriptors Descriptors to render.
	 * @return string Rendered sidebar markup.
	 */
	private function render( array $descriptors ): string {
		$this->descriptors = $descriptors;
		ob_start();
		( new SidebarRegistry() )->render( 'test-surface' );
		return (string) ob_get_clean();
	}

	/**
	 * A self-chromed widget descriptor that emits a fixed marker.
	 *
	 * @param string $id     Widget id.
	 * @param string $marker Text the render callback emits.
	 * @return array<string,mixed> Descriptor.
	 */
	private function widget( string $id, string $marker ): array {
		return array(
			'id'       => $id,
			'surfaces' => array( 'test-surface' ),
			'chrome'   => false,
			'render'   => static function () use ( $marker ): void {
				echo '<div class="bn-sidebar-card">' . esc_html( $marker ) . '</div>';
			},
		);
	}

	/**
	 * `mobile => false` wraps the widget so CSS can hide it below 1025px.
	 *
	 * Without the wrapper the stylesheet has no hook, and the profile page shows
	 * the Strength card and its hero chip together on a phone.
	 *
	 * @return void
	 */
	public function test_a_mobile_false_widget_is_wrapped(): void {
		$widget           = $this->widget( 'strengthy', 'STRENGTH_BODY' );
		$widget['mobile'] = false;

		$html = $this->render( array( $widget ) );

		$this->assertStringContainsString( 'STRENGTH_BODY', $html, 'precondition: the widget must render at all' );
		$this->assertStringContainsString(
			'<div class="bn-sidebar-desktop-only">',
			$html,
			'a widget declaring mobile => false must be wrapped, or the media query has nothing to hide'
		);
	}

	/**
	 * An ordinary widget is NOT wrapped.
	 *
	 * This is the half that matters most: if the wrapper leaked onto every
	 * widget, the mobile column would render but be entirely empty — the exact
	 * bug the reflow was written to fix, reintroduced invisibly.
	 *
	 * @return void
	 */
	public function test_an_ordinary_widget_is_not_wrapped(): void {
		$html = $this->render( array( $this->widget( 'plain', 'PLAIN_BODY' ) ) );

		$this->assertStringContainsString( 'PLAIN_BODY', $html );
		$this->assertStringNotContainsString(
			'bn-sidebar-desktop-only',
			$html,
			'a widget that never declared `mobile` must render unwrapped and stay visible on mobile'
		);
	}

	/**
	 * `mobile => true` is explicit, and equally unwrapped.
	 *
	 * Guards the comparison: only a literal false opts out, so a truthy value
	 * cannot accidentally hide a widget.
	 *
	 * @return void
	 */
	public function test_mobile_true_is_not_wrapped(): void {
		$widget           = $this->widget( 'shown', 'SHOWN_BODY' );
		$widget['mobile'] = true;

		$this->assertStringNotContainsString( 'bn-sidebar-desktop-only', $this->render( array( $widget ) ) );
	}

	/**
	 * A self-hiding widget produces no wrapper at all.
	 *
	 * The wrapper is emitted after the empty-body check. If it were emitted
	 * before, a widget that renders nothing would leave an empty div behind —
	 * harmless-looking, but it would take the sidebar's gap and show as a stray
	 * band of blank space on the surfaces where widgets commonly self-hide.
	 *
	 * @return void
	 */
	public function test_a_self_hiding_widget_emits_no_empty_wrapper(): void {
		$html = $this->render(
			array(
				array(
					'id'       => 'silent',
					'surfaces' => array( 'test-surface' ),
					'chrome'   => false,
					'mobile'   => false,
					'render'   => static function (): void {
						// Renders nothing — e.g. no tasks left, no suggestions.
					},
				),
			)
		);

		$this->assertStringNotContainsString(
			'bn-sidebar-desktop-only',
			$html,
			'an opted-out widget that renders nothing must not leave an empty wrapper behind'
		);
	}

	/**
	 * The real profile-strength descriptor carries the opt-out.
	 *
	 * The wrapper mechanism working is not the same as it being USED. This is
	 * the assertion that actually holds the profile page to one completion
	 * indicator per screen; the ones above only prove the plumbing.
	 *
	 * @return void
	 */
	public function test_the_profile_strength_descriptor_opts_out(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		// The two gates ProfileSidebarProvider reads off Surface::context(): the
		// card is own-profile only, and self-hides without a completion figure.
		Surface::set(
			'profile',
			array(
				'is_own_profile' => true,
				'completion'     => 40,
			)
		);

		// This provider is not the one under test, so drive it directly rather
		// than depending on Plugin::init() having wired it in this process.
		remove_filter( 'buddynext_sidebar_widgets', array( $this, 'serve_descriptors' ) );
		( new \BuddyNext\Sidebar\Providers\ProfileSidebarProvider() )->register();

		$descriptors = apply_filters( 'buddynext_sidebar_widgets', array(), 'profile' );

		$strength = null;
		foreach ( (array) $descriptors as $descriptor ) {
			if ( is_array( $descriptor ) && isset( $descriptor['id'] ) && 'profile-strength' === $descriptor['id'] ) {
				$strength = $descriptor;
				break;
			}
		}

		$this->assertNotNull(
			$strength,
			'precondition: profile-strength must be registered for an own profile with a completion figure'
		);
		$this->assertArrayHasKey( 'mobile', $strength, 'profile-strength must declare the opt-out' );
		$this->assertFalse( $strength['mobile'], 'profile-strength must opt out of the mobile column' );
	}
}
