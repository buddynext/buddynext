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
			$this->render_card( $widget, $body );
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
