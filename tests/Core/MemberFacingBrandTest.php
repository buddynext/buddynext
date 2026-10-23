<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Member-facing text names the community, never the plugin (card 10435198073).
 *
 * A white-labelled site's members saw "BuddyNext" in their own settings, a
 * toast, a field note, their app-password list and their data export. Owner and
 * developer surfaces (Site Health, the setup wizard, admin notices, widget names
 * in wp-admin, webhook API errors) name the plugin on purpose: the owner needs
 * to know which plugin is speaking. Those files are listed here; any other
 * translatable string that names the plugin fails.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * @coversNothing
 */
class MemberFacingBrandTest extends \WP_UnitTestCase {

	/**
	 * Files whose plugin-naming strings only owners or developers read.
	 */
	private const OWNER_FACING = array(
		'includes/Blocks/BlockRegistrar.php',       // Block inserter category.
		'includes/Core/Abilities.php',              // Abilities API labels (admin).
		'includes/Core/Plugin.php',                 // Admin notice, menu locations.
		'includes/Core/PresetActivation.php',       // Licence notices.
		'includes/Core/Installer.php',              // Site Health.
		'includes/Auth/CoreRegistration.php',       // Admin registration notices.
		'includes/Bridges/JetonomyBridge.php',      // Integrations toggle label.
		'includes/Integrations/CompanionRegistry.php', // Wizard companion list.
		'includes/Outbound/AccessWebhookController.php', // API errors for integrators.
		'includes/Moderation/ModerationListener.php',  // Admin alert email.
		'includes/Onboarding/SetupWizard.php',      // Owner setup wizard.
		'includes/Widgets/RecentActivityWidget.php',   // Widget names in wp-admin.
		'includes/Widgets/OnlineMembersWidget.php',
		'includes/Widgets/TrendingHashtagsWidget.php',
		'templates/messages/native.php',            // Administrator-only notice.
	);

	/**
	 * Exact strings allowed in otherwise member-facing files.
	 */
	private const OWNER_STRINGS = array(
		'includes/Privacy/PrivacyTools.php' => array(
			'BuddyNext', // Policy guide, exporter and eraser names in wp-admin Tools.
			'This community runs on BuddyNext.', // Suggested policy text discloses the software.
		),
	);

	public function test_member_facing_strings_do_not_name_the_plugin(): void {
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
				preg_match_all( "/\\b(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'([^']*BuddyNext[^']*)'/", (string) file_get_contents( (string) $file ), $m );
				foreach ( $m[1] as $text ) {
					$allowed = false;
					foreach ( self::OWNER_STRINGS[ $rel ] ?? array() as $prefix ) {
						$allowed = $allowed || $text === $prefix || str_starts_with( $text, $prefix . ' ' );
					}
					if ( ! $allowed ) {
						$found[] = $rel . ': ' . $text;
					}
				}
			}
		}

		$this->assertSame( array(), $found, 'Member-facing text should use the community name (buddynext_site_name()) or neutral wording.' );
	}
}
