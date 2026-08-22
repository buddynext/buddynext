<?php
/**
 * Every static property is either reset between tests or declared persistent.
 *
 * `StateReset` clears the memos that would otherwise outlive a test while the
 * database rolls back underneath them. A list like that rots the moment someone
 * adds a memo and does not know it exists — and the symptom is not a failing
 * test, it is a suite that starts disagreeing with itself on alternate Tuesdays.
 * That is exactly what this suite did before Free ea38d182 and this file.
 *
 * So the list is enforced rather than trusted: every `static` property under
 * `includes/` must appear either in {@see StateReset::MEMOS} (reset before each
 * test) or in {@see StateReset::PERSISTENT} (deliberately kept, with a stated
 * reason). A new one is a failing test naming the file, not a new flake.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

use WP_UnitTestCase;

/**
 * The static-state inventory.
 *
 * @covers \BuddyNext\Tests\Support\StateReset
 */
class StaticStateInventoryTest extends WP_UnitTestCase {

	/**
	 * Find every static property declared under includes/.
	 *
	 * Read from source rather than by reflecting loaded classes: a class nobody
	 * has autoloaded yet still holds statics the moment something touches it, and
	 * those are precisely the ones that get missed.
	 *
	 * @return array<int, array{file:string, class:string, property:string}>
	 */
	private function declared_statics(): array {
		$root = dirname( __DIR__, 2 ) . '/includes';
		$out  = array();

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			if ( ! preg_match( '/^namespace\s+([^;]+);/m', $source, $ns ) ) {
				continue;
			}

			if ( ! preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $cls ) ) {
				continue;
			}

			// `private static ?array $foo` / `public static $bar` — declarations only,
			// never `static function` or a `static::` call.
			preg_match_all(
				'/^\s*(?:private|protected|public)\s+static\s+(?:readonly\s+)?(?:[\?\\\\\w\|]+\s+)?\$(\w+)/m',
				$source,
				$matches
			);

			foreach ( $matches[1] as $property ) {
				$out[] = array(
					'file'     => str_replace( $root . '/', '', $file->getPathname() ),
					'class'    => trim( $ns[1] ) . '\\' . $cls[1],
					'property' => $property,
				);
			}
		}

		return $out;
	}

	/**
	 * Nothing may hold state between tests without a decision having been made.
	 *
	 * @return void
	 */
	public function test_every_static_is_reset_or_declared_persistent(): void {
		$statics = $this->declared_statics();

		$this->assertNotEmpty( $statics, 'Found no static properties at all - the scanner is broken, not the code.' );

		$unaccounted = array();

		foreach ( $statics as $static ) {
			$reset = isset( StateReset::MEMOS[ $static['class'] ] )
				&& array_key_exists( $static['property'], StateReset::MEMOS[ $static['class'] ] );

			if ( $reset || isset( StateReset::PERSISTENT[ $static['class'] . '::' . $static['property'] ] ) ) {
				continue;
			}

			$unaccounted[] = sprintf( '%s::$%s  (%s)', $static['class'], $static['property'], $static['file'] );
		}

		sort( $unaccounted );

		$this->assertSame(
			array(),
			$unaccounted,
			"These statics survive a test while the database rolls back underneath them.\n"
				. "Add each to StateReset::MEMOS (cleared before every test) or to\n"
				. "StateReset::PERSISTENT with the reason it is safe to keep:\n  "
				. implode( "\n  ", $unaccounted )
		);
	}

	/**
	 * The reset list may not name something that no longer exists.
	 *
	 * The other direction. A renamed property leaves a silently-dead entry, and
	 * the memo it used to clear starts leaking again.
	 *
	 * @return void
	 */
	public function test_the_reset_list_names_only_real_properties(): void {
		$dead = array();

		foreach ( StateReset::MEMOS as $class => $properties ) {
			foreach ( array_keys( $properties ) as $property ) {
				if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
					$dead[] = $class . '::$' . $property;
				}
			}
		}

		foreach ( array_keys( StateReset::PERSISTENT ) as $entry ) {
			list( $class, $property ) = explode( '::', $entry, 2 );

			if ( ! class_exists( $class ) || ! property_exists( $class, $property ) ) {
				$dead[] = $entry;
			}
		}

		sort( $dead );

		$this->assertSame( array(), $dead, 'StateReset names properties that do not exist: ' . implode( ', ', $dead ) );
	}

	/**
	 * The hook is actually registered, or none of the above matters.
	 *
	 * Guards the guard: the list could be perfect and the extension absent from
	 * phpunit.xml.dist, in which case nothing is ever reset and this file would
	 * still be green.
	 *
	 * @return void
	 */
	public function test_the_reset_hook_is_registered_with_phpunit(): void {
		$config = (string) file_get_contents( dirname( __DIR__, 2 ) . '/phpunit.xml.dist' );

		$this->assertStringContainsString(
			StateReset::class,
			str_replace( '\\\\', '\\', $config ),
			'StateReset is not registered as a PHPUnit extension, so nothing is being reset between tests.'
		);
	}
}
