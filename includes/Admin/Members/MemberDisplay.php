<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * BuddyNext member display helpers.
 *
 * Static utility methods for rendering member avatars, initials, role badges,
 * and time differences in the admin UI. Usable from any admin page.
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

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
			'label' => self::role_label( $role ),
			'class' => 'bn-badge-role-member',
		);
		echo '<span class="bn-badge ' . esc_attr( $map['class'] ) . '">' . esc_html( $map['label'] ) . '</span>';
	}

	/**
	 * Human label for a role BuddyNext does not name itself.
	 *
	 * The five roles above get a BuddyNext-specific label ("Member", not
	 * "Subscriber"). Everything else belongs to WordPress or another plugin, and
	 * WordPress already knows its display name — so ask, rather than guess. The
	 * previous ucfirst() on the slug printed Learnomy's roles as
	 * "Learnomy_instructor" in the Role column.
	 *
	 * @param string $role Role slug.
	 * @return string Translated role name, or a humanised slug if the role is no
	 *                longer registered (its plugin was deactivated).
	 */
	private static function role_label( string $role ): string {
		$names = wp_roles()->get_names();

		if ( isset( $names[ $role ] ) ) {
			return translate_user_role( $names[ $role ] );
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $role ) );
	}
}
