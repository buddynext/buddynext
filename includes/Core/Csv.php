<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The one CSV row writer for every BuddyNext export (Free and Pro).
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Writes CSV rows that a spreadsheet cannot execute.
 *
 * Exported cells carry member-controlled text (display names, moderation notes).
 * A cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is run
 * as a formula by Excel, LibreOffice and Google Sheets when the owner opens the
 * file (OWASP "CSV Injection"). Every export writes through row() so a column
 * added later is guarded without anyone remembering to.
 *
 * Pro's analytics and billing exports call this too; Pro's Free-version floor
 * (BuddyNextPro\Core\Plugin::init) keeps it from booting against a Free that
 * predates this class.
 *
 * @since 1.3.0
 */
final class Csv {

	/**
	 * Write one row with every cell defused.
	 *
	 * The empty `$escape` is deliberate and must stay explicit: PHP's default
	 * backslash escaping is not RFC 4180, and omitting the argument is deprecated
	 * from PHP 8.4, so the notice would be written into the downloaded file.
	 *
	 * @param resource         $stream Open, writable stream.
	 * @param array<int,mixed> $cells  Cell values.
	 * @return void
	 */
	public static function row( $stream, array $cells ): void {
		fputcsv( $stream, array_map( array( self::class, 'cell' ), $cells ), ',', '"', '' );
	}

	/**
	 * Make one cell inert.
	 *
	 * A risky cell gets a leading single quote, the OWASP-recommended prefix that
	 * every spreadsheet renders as literal text. Plain numbers pass unchanged, so
	 * a refund of `-5` stays a number while `-2+3` is guarded.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string
	 */
	public static function cell( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( '' === $value || is_numeric( $value ) ) {
			return $value;
		}

		return in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}
}
