<?php
/**
 * Surface-scoped right-sidebar widget registry (Free core).
 *
 * One renderer for the whole suite: Free's own widgets and every Pro bridge
 * register a descriptor via `buddynext_sidebar_widgets`; this filters by
 * surface + condition + opt-in, sorts by priority, caps per surface, and
 * self-hides any widget whose body renders empty. Ported from the Pro
 * SuiteSidebar so bridge descriptors need no change.
 *
 * Descriptor keys: `id`, `render` (both required), plus optional `surfaces`,
 * `hubs`, `condition`, `priority` (default 50), `default` (false = opt-in),
 * `chrome` (false = self-chromed), `title`, `icon`, `classes`, `see_all_url`,
 * `see_all_label`, and `mobile` (false = suppressed below 1025px; see the
 * render loop).
 *
 * @package BuddyNext\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar;

/**
 * Surface-scoped widget renderer for the right sidebar.
 */
class SidebarRegistry {

	/**
	 * Hooks the render method to buddynext_right_sidebar.
	 */
	public function register(): void {
		add_action( 'buddynext_right_sidebar', array( $this, 'render' ), 20 );
	}

	/**
	 * Renders the sidebar widgets for the given hub.
	 *
	 * @param string $hub Coarse bn_hub passed by the shell.
	 */
	public function render( $hub ): void {
		$hub     = (string) $hub;
		$surface = Surface::current( $hub );
		$widgets = apply_filters( 'buddynext_sidebar_widgets', array(), $surface );
		if ( ! is_array( $widgets ) || empty( $widgets ) ) {
			return;
		}

		$usable = array();
		foreach ( $widgets as $widget ) {
			if ( ! is_array( $widget ) || empty( $widget['id'] ) || empty( $widget['render'] ) || ! is_callable( $widget['render'] ) ) {
				continue;
			}
			if ( array_key_exists( 'default', $widget ) && false === $widget['default'] ) {
				continue; // Opt-in: off unless a filter flipped it on.
			}
			$surfaces = isset( $widget['surfaces'] ) ? (array) $widget['surfaces'] : array();
			$hubs     = isset( $widget['hubs'] ) ? (array) $widget['hubs'] : array();
			if ( ! empty( $surfaces ) ) {
				if ( ! in_array( $surface, $surfaces, true ) ) {
					continue;
				}
			} elseif ( ! empty( $hubs ) && ! in_array( $hub, $hubs, true ) ) {
				continue;
			}
			if ( isset( $widget['condition'] ) && is_callable( $widget['condition'] ) && ! (bool) call_user_func( $widget['condition'], $surface ) ) {
				continue;
			}
			$widget['priority'] = isset( $widget['priority'] ) ? (int) $widget['priority'] : 50;
			$usable[]           = $widget;
		}
		if ( empty( $usable ) ) {
			return;
		}

		usort(
			$usable,
			static function ( array $a, array $b ): int {
				return (int) $a['priority'] <=> (int) $b['priority'];
			}
		);

		$max = (int) apply_filters( 'buddynext_sidebar_max_widgets', 6, $surface );
		if ( $max > 0 && count( $usable ) > $max ) {
			$usable = array_slice( $usable, 0, $max );
		}

		foreach ( $usable as $widget ) {
			ob_start();
			call_user_func( $widget['render'], $surface );
			$body = trim( (string) ob_get_clean() );
			if ( '' === $body ) {
				continue; // Self-hiding.
			}

			// Opt-out for a widget that ALREADY has a purpose-built mobile surface
			// elsewhere on the page. Below 1025px the column reflows under the
			// content instead of hiding (see bn-shell.css), so a widget with its own
			// mobile counterpart would render twice on one screen. The viewport is
			// unknowable server-side, so the choice is expressed as a wrapper the
			// stylesheet can act on rather than as a skipped render.
			//
			// Emitted only for opted-out widgets, and only after the self-hide check
			// above, so no empty wrapper is ever produced.
			$desktop_only = array_key_exists( 'mobile', $widget ) && false === $widget['mobile'];
			if ( $desktop_only ) {
				echo '<div class="bn-sidebar-desktop-only">';
			}

			if ( isset( $widget['chrome'] ) && false === $widget['chrome'] ) {
				// Self-chromed widget: it renders its own .bn-sidebar-card, so echo
				// the (already-escaped) body raw instead of double-wrapping it.
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render callback outputs escaped markup.
			} else {
				$this->render_card( $widget, $body );
			}

			if ( $desktop_only ) {
				echo '</div>';
			}
		}
	}

	/**
	 * Renders a single sidebar card with widget content.
	 *
	 * @param array<string,mixed> $widget Descriptor.
	 * @param string              $body   Escaped widget body.
	 */
	private function render_card( array $widget, string $body ): void {
		if ( ! function_exists( 'buddynext_get_template' ) ) {
			return;
		}
		$classes = array( 'bn-sidebar-widget' );
		if ( ! empty( $widget['classes'] ) ) {
			$classes = array_merge( $classes, array_map( 'strval', (array) $widget['classes'] ) );
		}
		buddynext_get_template(
			'parts/sidebar-card.php',
			array(
				'id'            => (string) $widget['id'],
				'title'         => (string) ( $widget['title'] ?? '' ),
				'title_icon'    => (string) ( $widget['icon'] ?? '' ),
				'classes'       => $classes,
				'body_html'     => $body,
				'body_action'   => '',
				'see_all_url'   => (string) ( $widget['see_all_url'] ?? '' ),
				'see_all_label' => (string) ( $widget['see_all_label'] ?? '' ),
			)
		);
	}
}
