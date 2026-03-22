<?php
/**
 * BuddyNext shortcode registration service.
 *
 * Registers four core shortcodes that embed BuddyNext UI components into any
 * WordPress page or post:
 *
 *   [buddynext_feed]     Activity feed.
 *   [buddynext_members]  Member directory.
 *   [buddynext_spaces]   Spaces directory.
 *   [buddynext_profile]  Profile card for the current or a specific user.
 *
 * Each shortcode renders the corresponding PHP template via TemplateLoader.
 * When a template is absent (e.g. before the first deploy), the shortcode
 * falls back to a minimal loading wrapper hydrated by the Interactivity API.
 *
 * @package BuddyNext\Shortcodes
 */

declare( strict_types=1 );

namespace BuddyNext\Shortcodes;

use BuddyNext\Core\Container;

/**
 * Registers and handles BuddyNext shortcodes.
 */
class ShortcodeService {

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register all shortcodes.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'buddynext_feed', array( $this, 'render_feed' ) );
		add_shortcode( 'buddynext_members', array( $this, 'render_members' ) );
		add_shortcode( 'buddynext_spaces', array( $this, 'render_spaces' ) );
		add_shortcode( 'buddynext_profile', array( $this, 'render_profile' ) );
		add_shortcode( 'buddynext_edit_profile', array( $this, 'render_edit_profile' ) );
	}

	// ── Shortcode handlers ────────────────────────────────────────────────────

	/**
	 * Render the activity feed shortcode.
	 *
	 * Supported attributes:
	 *   limit   int    Maximum posts to display.  Default 10.
	 *   type    string Feed type: 'all' | 'following'.  Default 'all'.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_feed( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit' => 10,
				'type'  => 'all',
			),
			$atts,
			'buddynext_feed'
		);

		$limit = absint( $atts['limit'] );
		$type  = sanitize_key( (string) $atts['type'] );

		return $this->capture(
			'feed/home.php',
			array(
				'args' => array(
					'limit' => $limit,
					'type'  => $type,
				),
			),
			sprintf(
				'<div class="buddynext-feed" data-limit="%d" data-type="%s" data-wp-interactive="buddynext/feed"></div>',
				$limit,
				esc_attr( $type )
			)
		);
	}

	/**
	 * Render the member directory shortcode.
	 *
	 * Supported attributes:
	 *   limit     int    Members per page.  Default 12.
	 *   orderby   string 'joined' | 'active' | 'alpha'.  Default 'joined'.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_members( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'   => 12,
				'orderby' => 'joined',
			),
			$atts,
			'buddynext_members'
		);

		$limit   = absint( $atts['limit'] );
		$orderby = sanitize_key( (string) $atts['orderby'] );

		return $this->capture(
			'directory/members.php',
			array(
				'args' => array(
					'limit'   => $limit,
					'orderby' => $orderby,
				),
			),
			sprintf(
				'<div class="buddynext-members" data-limit="%d" data-orderby="%s" data-wp-interactive="buddynext/members"></div>',
				$limit,
				esc_attr( $orderby )
			)
		);
	}

	/**
	 * Render the spaces directory shortcode.
	 *
	 * Supported attributes:
	 *   limit   int    Spaces per page.  Default 12.
	 *   type    string 'all' | 'public' | 'private'.  Default 'all'.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_spaces( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit' => 12,
				'type'  => 'all',
			),
			$atts,
			'buddynext_spaces'
		);

		$limit = absint( $atts['limit'] );
		$type  = sanitize_key( (string) $atts['type'] );

		return $this->capture(
			'spaces/directory.php',
			array(
				'args' => array(
					'limit' => $limit,
					'type'  => $type,
				),
			),
			sprintf(
				'<div class="buddynext-spaces" data-limit="%d" data-type="%s" data-wp-interactive="buddynext/spaces"></div>',
				$limit,
				esc_attr( $type )
			)
		);
	}

	/**
	 * Render the user profile shortcode.
	 *
	 * Supported attributes:
	 *   user_id  int    User ID to display.  Default: current logged-in user.
	 *   view     string 'card' | 'full'.  Default 'card'.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_profile( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id' => 0,
				'view'    => 'card',
			),
			$atts,
			'buddynext_profile'
		);

		$user_id = absint( $atts['user_id'] );

		// Pretty URL: /profile/{slug}/ — PageRouter resolves the slug and stores the ID.
		if ( 0 === $user_id ) {
			$user_id = (int) get_query_var( 'bn_resolved_user_id', 0 );
		}
		// Legacy query-param fallback for any existing links: /profile/?user_id=123
		if ( 0 === $user_id ) {
			$user_id = absint( sanitize_text_field( wp_unslash( $_GET['user_id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		$view = sanitize_key( (string) $atts['view'] );

		return $this->capture(
			'profile/view.php',
			array(
				'user_id' => $user_id,
				'view'    => $view,
			),
			sprintf(
				'<div class="buddynext-profile" data-user-id="%d" data-view="%s" data-wp-interactive="buddynext/profile"></div>',
				$user_id,
				esc_attr( $view )
			)
		);
	}

	/**
	 * Render the edit profile shortcode.
	 *
	 * Always edits the current user's own profile. Redirects guests to login.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_edit_profile( $atts ): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return sprintf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'You must be logged in to edit your profile.', 'buddynext' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log in', 'buddynext' )
			);
		}

		return $this->capture(
			'profile/edit.php',
			array( 'user_id' => $user_id ),
			sprintf(
				'<div class="buddynext-edit-profile" data-user-id="%d" data-wp-interactive="buddynext/profile"></div>',
				$user_id
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Render a template to a string, with a fallback wrapper when absent.
	 *
	 * @param string               $relative  Relative template path.
	 * @param array<string, mixed> $variables Variables for the template.
	 * @param string               $fallback  HTML to return when template is missing.
	 * @return string Rendered HTML.
	 */
	private function capture( string $relative, array $variables, string $fallback ): string {
		$loader = Container::instance()->get( 'template_loader' );
		$html   = $loader->capture( $relative, $variables );

		if ( '' === trim( preg_replace( '/<!--.*?-->/s', '', $html ) ?? '' ) ) {
			return $fallback;
		}

		return $html;
	}
}
