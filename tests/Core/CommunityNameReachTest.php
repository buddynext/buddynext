<?php
/**
 * The Community Name reaches everywhere its own field says it does.
 *
 * Settings → General describes `buddynext_site_name` as "Displayed in the site
 * header, emails, and browser title." It reached the header only. The tab title
 * was built from WordPress's Site Title, because both head emitters set
 * `$parts['title']` and nothing ever touched `$parts['site']`; email read
 * `get_bloginfo( 'name' )` in four places, including the From name.
 *
 * So an owner renaming their community got a renamed header, mail still sent as
 * WordPress, and every tab and bookmark still announcing the old name — with
 * nothing to indicate the setting had done half its job.
 *
 * The tests are organised by the three promises in that sentence, so a fourth
 * surface added later has an obvious place to be asserted.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;
use BuddyNext\Notifications\EmailSender;

/**
 * Community Name coverage across the surfaces its hint promises.
 *
 * @covers \BuddyNext\Core\PageRouter::apply_community_name_to_title
 * @covers \BuddyNext\Notifications\EmailSender::from_name
 */
class CommunityNameReachTest extends \WP_UnitTestCase {

	/**
	 * Start from a known WP Site Title and no community name.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'blogname', 'Acme WordPress Site' );
		delete_option( 'buddynext_site_name' );
		delete_option( 'buddynext_email_from_name' );
	}

	/**
	 * Drop any title filter this test added, so the next test starts clean.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'document_title_parts' );

		parent::tear_down();
	}

	/**
	 * The site half of the document title, after BuddyNext has had its say.
	 *
	 * @return string
	 */
	private function title_site_part(): string {
		PageRouter::apply_community_name_to_title();

		$parts = (array) apply_filters(
			'document_title_parts',
			array(
				'title' => 'Activity',
				'site'  => get_bloginfo( 'name' ),
			)
		);

		return (string) ( $parts['site'] ?? '' );
	}

	// ── Promise 1: browser title ─────────────────────────────────────────────────

	/**
	 * A configured Community Name is what the tab says.
	 *
	 * @return void
	 */
	public function test_the_community_name_reaches_the_tab_title(): void {
		update_option( 'buddynext_site_name', 'Acme Makers Club' );

		$this->assertSame(
			'Acme Makers Club',
			$this->title_site_part(),
			'The tab title still announces the WordPress site name after the community was renamed.'
		);
	}

	/**
	 * With no Community Name set, the WP Site Title stands.
	 *
	 * Without this the fix could be "always overwrite the site part" and every
	 * assertion above would still pass.
	 *
	 * @return void
	 */
	public function test_an_unset_community_name_leaves_the_site_title_alone(): void {
		$this->assertSame(
			'Acme WordPress Site',
			$this->title_site_part(),
			'The tab title changed on a site that never set a community name.'
		);
	}

	/**
	 * With no Community Name set, nobody else's site part is clobbered either.
	 *
	 * Restating the WP Site Title through a filter looks harmless — the value is
	 * identical, so nothing visibly changes — right up until a theme or another
	 * plugin has already customised the site part, at which point BuddyNext
	 * quietly overwrites it with WordPress's raw title on every community page,
	 * for a setting the owner never touched. Hence the early return.
	 *
	 * @return void
	 */
	public function test_another_plugins_site_part_survives_when_no_community_name_is_set(): void {
		$custom = static function ( array $parts ): array {
			$parts['site'] = 'Set By Another Plugin';
			return $parts;
		};
		add_filter( 'document_title_parts', $custom, 5 );

		$site = $this->title_site_part();

		remove_filter( 'document_title_parts', $custom, 5 );

		$this->assertSame(
			'Set By Another Plugin',
			$site,
			"BuddyNext overwrote another plugin's site title while having no community name of its own to apply."
		);
	}

	/**
	 * The title half is not touched by this.
	 *
	 * The page's own name is decided elsewhere, and a fix aimed at the site half
	 * that also rewrote the page half would be a different bug.
	 *
	 * @return void
	 */
	public function test_the_page_title_half_is_untouched(): void {
		update_option( 'buddynext_site_name', 'Acme Makers Club' );

		PageRouter::apply_community_name_to_title();

		$parts = (array) apply_filters(
			'document_title_parts',
			array(
				'title' => 'Activity',
				'site'  => get_bloginfo( 'name' ),
			)
		);

		$this->assertSame( 'Activity', (string) $parts['title'], 'The page name was overwritten by the community name.' );
	}

	/**
	 * An SEO plugin keeps the head it owns.
	 *
	 * The same deference the title half already applies (Zoho #41057): an owner
	 * who configured Yoast made an explicit choice, and renaming a community is
	 * not a request to overrule it.
	 *
	 * @return void
	 */
	public function test_an_seo_plugin_keeps_the_head(): void {
		update_option( 'buddynext_site_name', 'Acme Makers Club' );

		$claim = static fn(): bool => true;
		add_filter( 'buddynext_seo_plugin_active', $claim );

		$site = $this->title_site_part();

		remove_filter( 'buddynext_seo_plugin_active', $claim );

		$this->assertSame(
			'Acme WordPress Site',
			$site,
			'BuddyNext overwrote the site title while an SEO plugin was managing the head.'
		);
	}

	// ── Promise 2: emails ────────────────────────────────────────────────────────

	/**
	 * Mail goes out under the community's name, not WordPress's.
	 *
	 * @return void
	 */
	public function test_the_from_name_falls_back_to_the_community_name(): void {
		update_option( 'buddynext_site_name', 'Acme Makers Club' );

		$this->assertSame(
			'Acme Makers Club',
			EmailSender::from_name(),
			'Email is still sent as the WordPress site name after the community was renamed.'
		);
	}

	/**
	 * An explicit From name still wins over both.
	 *
	 * @return void
	 */
	public function test_an_explicit_from_name_still_wins(): void {
		update_option( 'buddynext_site_name', 'Acme Makers Club' );
		update_option( 'buddynext_email_from_name', 'Acme Support' );

		$this->assertSame(
			'Acme Support',
			EmailSender::from_name(),
			'The From name an owner typed was replaced by the community name.'
		);
	}

	/**
	 * The {{site_name}} token in a template resolves to the community name.
	 *
	 * @return void
	 */
	public function test_the_site_name_token_resolves_to_the_community_name(): void {
		update_option( 'buddynext_site_name', 'Acme Makers Club' );

		$this->assertSame(
			'Welcome to Acme Makers Club',
			EmailSender::apply_global_tokens( 'Welcome to {{site_name}}' ),
			'An email template naming the site still printed the WordPress site name.'
		);
	}

	/**
	 * And falls back to the WP Site Title when no community name is set.
	 *
	 * @return void
	 */
	public function test_the_site_name_token_falls_back_to_the_site_title(): void {
		$this->assertSame(
			'Welcome to Acme WordPress Site',
			EmailSender::apply_global_tokens( 'Welcome to {{site_name}}' ),
			'The site-name token broke on a site that never set a community name.'
		);
	}

	// ── Promise 3: header ────────────────────────────────────────────────────────

	/**
	 * The helper every header surface reads answers with the community name.
	 *
	 * @return void
	 */
	public function test_the_shared_helper_prefers_the_community_name(): void {
		$this->assertSame( 'Acme WordPress Site', buddynext_site_name(), 'The unset fallback is wrong.' );

		update_option( 'buddynext_site_name', 'Acme Makers Club' );

		$this->assertSame( 'Acme Makers Club', buddynext_site_name(), 'The configured community name is not being returned.' );
	}
}
