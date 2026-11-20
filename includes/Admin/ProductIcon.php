<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Product icon for admin lists that name a product.
 *
 * One place draws the square icon beside a product's name on Integration
 * Settings and the Add-ons tab. An integration or companion declares `icon`
 * in its registration (an http(s) URL, or a path relative to the plugins
 * folder such as `my-addon/assets/icon.svg`); without one, the Wbcom family
 * falls back to the mark BuddyNext ships in assets/img/companions/; without
 * that, the first letter of the name on a tile.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Resolves and renders a product's icon.
 */
final class ProductIcon {

	/**
	 * Rendered edge in CSS pixels; matches .bn-int-tile.
	 */
	private const SIZE = 40;

	/**
	 * Icon for a companion registry entry: its declared `icon`, else the bundled
	 * mark named by `logo` (or the slug), else the letter tile.
	 *
	 * @param string               $slug  Companion slug.
	 * @param array<string, mixed> $entry Companion entry.
	 * @return string Escaped markup.
	 */
	public static function for_companion( string $slug, array $entry ): string {
		return self::render(
			(string) ( $entry['label'] ?? $slug ),
			(string) ( $entry['icon'] ?? '' ),
			(string) ( $entry['logo'] ?? $slug )
		);
	}

	/**
	 * Safe URL for an icon, or '' when there is none to show.
	 *
	 * @param string $icon    Declared icon: http(s) URL or plugins-folder-relative path.
	 * @param string $bundled Bundled mark name in assets/img/companions/, without .svg.
	 * @return string
	 */
	public static function url( string $icon, string $bundled = '' ): string {
		$icon = trim( $icon );
		if ( '' !== $icon ) {
			$scheme = wp_parse_url( $icon, PHP_URL_SCHEME );
			if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
				$url = esc_url_raw( $icon, array( 'http', 'https' ) );
				if ( '' !== $url ) {
					return $url;
				}
			} elseif ( null === $scheme && ! str_contains( $icon, '..' ) && 1 === preg_match( '#^[A-Za-z0-9_-][A-Za-z0-9._/-]*$#', $icon ) ) {
				return plugins_url( $icon );
			}
		}

		$file = sanitize_file_name( $bundled );
		if ( '' !== $file && is_file( BUDDYNEXT_DIR . 'assets/img/companions/' . $file . '.svg' ) ) {
			return BUDDYNEXT_URL . 'assets/img/companions/' . $file . '.svg';
		}
		return '';
	}

	/**
	 * The icon tile: an image when one resolves, the name's first letter otherwise.
	 * Decorative in both cases, because the name is printed beside it.
	 *
	 * @param string $label   Product name.
	 * @param string $icon    Declared icon (see url()).
	 * @param string $bundled Bundled mark name (see url()).
	 * @return string Escaped markup.
	 */
	public static function render( string $label, string $icon = '', string $bundled = '' ): string {
		$url = self::url( $icon, $bundled );
		if ( '' !== $url ) {
			return sprintf(
				'<span class="bn-int-tile has-icon" aria-hidden="true"><img src="%1$s" alt="" width="%2$d" height="%2$d" decoding="async"></span>',
				esc_url( $url ),
				self::SIZE
			);
		}
		$initial = function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $label, 0, 1 ) ) : strtoupper( substr( $label, 0, 1 ) );
		return '<span class="bn-int-tile" aria-hidden="true">' . esc_html( $initial ) . '</span>';
	}
}
