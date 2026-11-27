<?php
/**
 * Every default email template is translated in every shipped language, with its
 * placeholders intact.
 *
 * An empty msgstr falls back to English, so a member on a translated site got
 * English emails. A wrong placeholder is worse: {{user_name}} or a link dropped from
 * a translation sends a broken email. The strings are read from the template defaults
 * the plugin seeds, so a new template is covered without editing this file.
 *
 * @package BuddyNext\Tests\I18n
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\I18n;

use BuddyNext\Admin\EmailEditor;
use BuddyNext\Core\Installer;
use WP_UnitTestCase;

/**
 * Checks the shipped .po catalogues against the seeded email templates.
 */
class EmailTemplateTranslationsTest extends WP_UnitTestCase {

	/**
	 * Each shipped catalogue, keyed by locale.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function locale_provider(): array {
		$out = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/languages/buddynext-*.po' ) as $file ) {
			$out[ basename( (string) $file, '.po' ) ] = array( (string) $file );
		}
		return $out;
	}

	/**
	 * Every seeded email subject, preview and body has a translation that keeps its placeholders.
	 *
	 * @dataProvider locale_provider
	 *
	 * @param string $po_file Path of the .po file.
	 */
	public function test_email_templates_are_translated_with_placeholders_intact( string $po_file ): void {
		$catalogue = $this->parse_po( $po_file );
		$checked   = 0;
		$problems  = array();

		// Types the seeder takes from the Email Templates catalogue store the translated
		// plain-text body after wpautop(), so the catalogue's own string is the msgid.
		$catalogue_bodies = array();
		foreach ( ( new EmailEditor() )->get_catalogue() as $group ) {
			foreach ( (array) $group as $type => $tpl ) {
				if ( isset( $tpl['body'] ) ) {
					$catalogue_bodies[ (string) $type ] = (string) $tpl['body'];
				}
			}
		}

		foreach ( Installer::email_template_seeds() as $row ) {
			foreach ( array( 'subject', 'preview_text', 'body_html' ) as $field ) {
				$msgid = (string) $row[ $field ];
				if ( 'body_html' === $field && isset( $catalogue_bodies[ $row['type'] ] ) ) {
					$msgid = $catalogue_bodies[ $row['type'] ];
				}

				if ( ! array_key_exists( $msgid, $catalogue ) ) {
					$problems[] = $row['type'] . ' ' . $field . ': not in the catalogue';
					continue;
				}

				++$checked;
				$msgstr = $catalogue[ $msgid ];

				if ( '' === $msgstr ) {
					$problems[] = $row['type'] . ' ' . $field . ': empty translation';
				} elseif ( $this->signature( $msgid ) !== $this->signature( $msgstr ) ) {
					$problems[] = $row['type'] . ' ' . $field . ': placeholders or HTML tags differ';
				}
			}
		}

		$this->assertGreaterThan( 100, $checked, 'The seeded email templates were not found in the catalogue.' );
		$this->assertSame( array(), $problems, basename( $po_file ) . ' has untranslated or broken email templates.' );
	}

	/**
	 * Sorted list of every token that must survive translation.
	 *
	 * @param string $text Source or translated text.
	 * @return string[]
	 */
	private function signature( string $text ): array {
		preg_match_all( '/\{\{[^}]*\}\}|%(?:\d+\$)?[sd]|<\/?[a-zA-Z0-9]+|&[a-zA-Z0-9#]+;|href="[^"]*"/', $text, $m );
		$tokens = $m[0];
		sort( $tokens );
		return $tokens;
	}

	/**
	 * Read a .po file into msgid => msgstr (singular entries only).
	 *
	 * @param string $file Path of the .po file.
	 * @return array<string, string>
	 */
	private function parse_po( string $file ): array {
		$entries = array();

		foreach ( explode( "\n\n", (string) file_get_contents( $file ) ) as $block ) {
			if ( ! preg_match( '/^msgid ((?:"[^\n]*"\n)+)msgstr ((?:"[^\n]*"\n?)+)/m', $block . "\n", $m ) ) {
				continue;
			}
			$msgid = $this->unquote_all( $m[1] );
			if ( '' !== $msgid ) {
				$entries[ $msgid ] = $this->unquote_all( $m[2] );
			}
		}

		return $entries;
	}

	/**
	 * Decode a run of quoted .po lines into one string.
	 *
	 * @param string $lines Quoted lines.
	 * @return string
	 */
	private function unquote_all( string $lines ): string {
		$out = '';
		foreach ( explode( "\n", trim( $lines ) ) as $line ) {
			$out .= $this->unquote( $line );
		}
		return $out;
	}

	/**
	 * Decode one quoted .po string.
	 *
	 * @param string $quoted The quoted string.
	 * @return string
	 */
	private function unquote( string $quoted ): string {
		return stripcslashes( substr( trim( $quoted ), 1, -1 ) );
	}
}
