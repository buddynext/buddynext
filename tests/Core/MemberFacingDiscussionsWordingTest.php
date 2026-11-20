<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Members read "Discussions", "topic" and "reply", never "forum" or "thread"
 * (card 10526710923).
 *
 * The Jetonomy side of a community was called Discussion, Topic, Thread, Forum
 * and Community on different surfaces. The owner picked one vocabulary for
 * members: the area is "Discussions", a post in it is a "topic", an answer is a
 * "reply". wp-admin (includes/Admin/) and the files below speak to owners or
 * developers and may still say "forum"; any other translatable string that does
 * fails here.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * @coversNothing
 */
class MemberFacingDiscussionsWordingTest extends \WP_UnitTestCase {

	/**
	 * Files whose forum/thread strings only owners or developers read.
	 */
	private const OWNER_FACING = array(
		'includes/Integrations/CompanionRegistry.php', // Setup wizard companion list.
		'includes/Spaces/CoreSpaceFields.php',         // "Linked forum ID" space field, owner schema editor.
		'includes/Core/FeatureRegistry.php',           // Feature toggles in wp-admin.
	);

	/**
	 * Exact strings allowed in otherwise member-facing files.
	 */
	private const OWNER_STRINGS = array(
		// Owner-facing "works with Jetonomy" version list.
		'includes/Bridges/JetonomyBridge.php'  => array( 'Forum notifications in the BuddyNext bell' ),
		// A post's comment thread, not Jetonomy.
		'includes/Comments/CommentService.php' => array( 'This thread has reached its maximum reply depth.' ),
	);

	public function test_member_facing_strings_say_discussions_not_forum(): void {
		$root  = dirname( __DIR__, 2 ) . '/';
		$found = array();
		foreach ( array( 'includes', 'templates', 'blocks' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$rel = substr( (string) $file, strlen( $root ) );
				if ( ! str_ends_with( $rel, '.php' ) || str_starts_with( $rel, 'includes/Admin/' ) || in_array( $rel, self::OWNER_FACING, true ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
				preg_match_all( "/\\b(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\\(\\s*'([^']*\\b(?:[Ff]orums?|[Tt]hreads?|[Tt]hreaded)\\b[^']*)'/", (string) file_get_contents( (string) $file ), $m );
				foreach ( $m[1] as $text ) {
					if ( ! in_array( $text, self::OWNER_STRINGS[ $rel ] ?? array(), true ) ) {
						$found[] = $rel . ': ' . $text;
					}
				}
			}
		}

		$this->assertSame( array(), $found, 'Member-facing text says "Discussions", "topic" and "reply" (owner decision, card 10526710923).' );
	}
}
