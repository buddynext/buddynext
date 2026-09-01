<?php
/**
 * A counted noun never ships with a single hardcoded plural form.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * "1 members" is the symptom; the cause is `__( '%s members' )` where `_n()`
 * belongs, and it keeps coming back because each instance is fixed where it was
 * reported rather than as a class. It has now been found at least three times: the
 * member-directory hero, the onboarding space card, the space header ("1 Posts"),
 * and the hashtag sidebar.
 *
 * So this is a grep, expressed as a test. It scans the source for a translated
 * string that formats a count into a noun that only exists in its plural form, and
 * fails naming the file and line. A reviewer cannot approve the next one by
 * accident, and a translator never receives a string whose plural rule we already
 * decided for them - which matters most in languages with more than two forms,
 * where even the "correct" English pair is not enough.
 *
 * The check is deliberately narrow: only `__()`/`esc_html__()` (never `_n()`), and
 * only a `%s`/`%d` immediately followed by a known counted noun.
 *
 * One legitimate exception has to be understood or the check is useless. A string
 * localised FOR JAVASCRIPT cannot use `_n()` - the plural rule has to be applied in
 * the browser, where the count is - so those ship as an adjacent PAIR, a `'1 item'`
 * beside a `'%d items'`, and the store picks. Five such pairs already exist and are
 * correct. So the rule this enforces is not "always `_n()`"; it is "a plural count
 * string must have a singular form somewhere" - via `_n()`, or as a sibling within a
 * few lines. Flagging the pairs would have taught everyone to ignore the test, which
 * is how a guard stops guarding.
 */
class CountStringsArePluralisedTest extends \WP_UnitTestCase {

	/**
	 * Nouns that only make sense with a number in front of them.
	 *
	 * @var string[]
	 */
	private const COUNTED_NOUNS = array(
		'members',
		'posts',
		'comments',
		'spaces',
		'followers',
		'following',
		'replies',
		'likes',
		'reactions',
		'messages',
		'notifications',
		'results',
		'items',
		'files',
	);

	/**
	 * Every PHP file that can emit a member-facing string.
	 *
	 * @return string[]
	 */
	private function source_files(): array {
		$roots = array(
			dirname( __DIR__, 2 ) . '/includes',
			dirname( __DIR__, 2 ) . '/templates',
		);

		$files = array();
		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	public function test_no_counted_noun_ships_with_only_a_plural_form(): void {
		$nouns   = implode( '|', self::COUNTED_NOUNS );
		$pattern = '/\b(?:esc_html__|esc_attr__|__)\(\s*[\'"]%[sd] (' . $nouns . ')[\'"]/i';

		$offences = array();

		foreach ( $this->source_files() as $path ) {
			$lines = file( $path, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}

			foreach ( $lines as $i => $line ) {
				if ( ! preg_match( $pattern, $line, $m ) ) {
					continue;
				}

				if ( $this->has_singular_sibling( $lines, $i ) ) {
					continue;
				}

				$offences[] = sprintf(
					'%s:%d  \'%%s %s\' has no singular form - use _n(), or ship a \'1 %s\' sibling for JS',
					str_replace( dirname( __DIR__, 2 ) . '/', '', $path ),
					$i + 1,
					$m[1],
					rtrim( $m[1], 's' )
				);
			}
		}

		$this->assertSame(
			array(),
			$offences,
			"A counted noun must go through _n() so \"1 members\" cannot happen:\n  " . implode( "\n  ", $offences )
		);
	}

	/**
	 * Whether a singular counterpart sits beside this plural string.
	 *
	 * The JS pairs are written adjacently by convention - `'oneItem'` then
	 * `'nItems'`, `'oneReaction'` then `'manyReactions'` - usually with a
	 * translators comment between them. Four lines of slack covers every existing
	 * pair without reaching into an unrelated entry.
	 *
	 * @param string[] $lines File lines.
	 * @param int      $index Line index of the plural string.
	 * @return bool
	 */
	private function has_singular_sibling( array $lines, int $index ): bool {
		$from = max( 0, $index - 4 );

		for ( $i = $from; $i <= min( $index + 4, count( $lines ) - 1 ); $i++ ) {
			if ( $i !== $index && preg_match( '/[\'"]1 [a-z]+[\'"]/i', $lines[ $i ] ) ) {
				return true;
			}
		}

		return false;
	}
}
