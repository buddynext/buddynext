<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Member-facing surfaces name the community through buddynext_site_name(), never
 * the raw WordPress Site Title (card 10436265906).
 *
 * The Community name setting exists so an owner can name the community apart from
 * the Site Title, which SEO plugins often manage or leave blank. Emails, the app
 * install prompt, the app config and signup read the Site Title directly, so a
 * white-labelled site's members saw the wrong name and a blank title broke install.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * @coversNothing
 */
class CommunityNameTest extends \WP_UnitTestCase {

	/**
	 * The only places allowed to read the Site Title, with why.
	 */
	private const SITE_TITLE_READERS = array(
		'includes/Core/PageRouter.php'              => 2, // Document title: the community name replaces the site part when it differs.
		'includes/Admin/Settings.php'               => 1, // Community name field default.
		'includes/Onboarding/SetupWizard.php'       => 1, // Community name field default.
		'includes/Outbound/OutboundWebhookService.php' => 1, // Alert to the site admin, not a member.
	);

	public function test_member_facing_code_reads_the_community_name(): void {
		$root  = dirname( __DIR__, 2 ) . '/';
		$found = array();
		foreach ( array( 'includes', 'templates', 'blocks' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$rel = substr( (string) $file, strlen( $root ) );
				if ( ! str_ends_with( $rel, '.php' ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
				$hits = preg_match_all( "/get_bloginfo\\(\\s*'name'|'blogname'/", (string) file_get_contents( (string) $file ) );
				if ( $hits > ( self::SITE_TITLE_READERS[ $rel ] ?? 0 ) ) {
					$found[] = $rel . ' (' . $hits . ')';
				}
			}
		}

		$this->assertSame( array(), $found, 'Read the community name with buddynext_site_name(), or document why this surface must show the Site Title.' );
	}
}
