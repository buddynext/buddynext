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
 * Each shortcode renders a loading wrapper div that is hydrated via the
 * WordPress Interactivity API (no build step required).
 *
 * @package BuddyNext\Shortcodes
 */

declare( strict_types=1 );

namespace BuddyNext\Shortcodes;

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

		return sprintf(
			'<div class="buddynext-feed" data-limit="%d" data-type="%s" data-wp-interactive="buddynext/feed"></div>',
			$limit,
			esc_attr( $type )
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

		return sprintf(
			'<div class="buddynext-members" data-limit="%d" data-orderby="%s" data-wp-interactive="buddynext/members"></div>',
			$limit,
			esc_attr( $orderby )
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

		return sprintf(
			'<div class="buddynext-spaces" data-limit="%d" data-type="%s" data-wp-interactive="buddynext/spaces"></div>',
			$limit,
			esc_attr( $type )
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
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		$view = sanitize_key( (string) $atts['view'] );

		return sprintf(
			'<div class="buddynext-profile" data-user-id="%d" data-view="%s" data-wp-interactive="buddynext/profile"></div>',
			$user_id,
			esc_attr( $view )
		);
	}
}
