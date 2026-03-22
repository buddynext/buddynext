<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * BuddyNext member display helpers.
 *
 * Static utility methods for rendering member avatars, initials, role badges,
 * and time differences in the admin UI. Usable from any admin page.
 *
 * @package BuddyNext\Admin\Helpers
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Helpers;

/**
 * Static utility methods for member display in admin UI.
 */
class MemberDisplay {

	/**
	 * Get initials from a display name (up to 2 characters).
	 *
	 * @param string $display_name Display name.
	 * @return string
	 */
	public static function get_initials( string $display_name ): string {
		$parts = array_filter( explode( ' ', trim( $display_name ) ) );
		if ( empty( $parts ) ) {
			return '?';
		}
		$first = mb_substr( reset( $parts ), 0, 1 );
		$last  = count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '';
		return mb_strtoupper( $first . $last );
	}

	/**
	 * Return a deterministic CSS class for avatar background based on user ID.
	 *
	 * @param int $user_id User ID.
	 * @return string CSS class name.
	 */
	public static function get_avatar_color( int $user_id ): string {
		$colors = array( 'av-brand', 'av-green', 'av-purple', 'av-orange', 'av-pink', 'av-teal', 'av-rose', 'av-indigo' );
		return $colors[ $user_id % count( $colors ) ];
	}

	/**
	 * Render a role badge with appropriate color.
	 *
	 * @param string $role WP role slug.
	 * @return void
	 */
	public static function render_role_badge( string $role ): void {
		$labels = array(
			'administrator' => array(
				'label' => __( 'Admin', 'buddynext' ),
				'class' => 'bn-badge-role-admin',
			),
			'editor'        => array(
				'label' => __( 'Editor', 'buddynext' ),
				'class' => 'bn-badge-role-editor',
			),
			'author'        => array(
				'label' => __( 'Author', 'buddynext' ),
				'class' => 'bn-badge-role-author',
			),
			'contributor'   => array(
				'label' => __( 'Contributor', 'buddynext' ),
				'class' => 'bn-badge-role-contrib',
			),
			'subscriber'    => array(
				'label' => __( 'Member', 'buddynext' ),
				'class' => 'bn-badge-role-member',
			),
		);
		$map    = $labels[ $role ] ?? array(
			'label' => ucfirst( $role ),
			'class' => 'bn-badge-role-member',
		);
		echo '<span class="bn-badge ' . esc_attr( $map['class'] ) . '">' . esc_html( $map['label'] ) . '</span>';
	}

	/**
	 * Return a short human-readable time difference string.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string e.g. "2h ago", "3d ago", "1w ago".
	 */
	public static function human_time_diff_short( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return "\xe2\x80\x94";
		}
		$diff = max( 0, time() - $timestamp );
		if ( $diff < 60 ) {
			return __( 'Just now', 'buddynext' );
		}
		if ( $diff < 3600 ) {
			return (string) round( $diff / 60 ) . 'm ago';
		}
		if ( $diff < 86400 ) {
			return (string) round( $diff / 3600 ) . 'h ago';
		}
		if ( $diff < 604800 ) {
			return (string) round( $diff / 86400 ) . 'd ago';
		}
		if ( $diff < 2592000 ) {
			return (string) round( $diff / 604800 ) . 'w ago';
		}
		return gmdate( 'M j, Y', $timestamp );
	}
}
