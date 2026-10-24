<?php
/**
 * The addon framework, driven through the fixture addon.
 *
 * @package BuddyNext\Tests\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Addons;

use BuddyNext\Addons\AbstractAddon;
use BuddyNext\Addons\AddonRegistry;
use BuddyNext\Addons\AddonSettingsForm;
use BuddyNext\Addons\FeedCardModule;
use BuddyNext\Addons\ModuleWiring;
use BuddyNext\Admin\Settings\SettingsRegistry;
use BuddyNext\Dev\AddonFixture\FixtureAddon;
use BuddyNext\Dev\AddonFixture\Modules\Search;
use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Feed\PostService;
use BuddyNext\Integrations\IntegrationRegistry;
use BuddyNext\Notifications\IntegrationNotificationListener;
use BuddyNext\Search\SearchService;
use WP_UnitTestCase;

/**
 * Each module's wiring is proved here by name, so removing one fails its own test.
 */
class FixtureAddonTest extends WP_UnitTestCase {

	/**
	 * Fresh registry and a healthy pretend partner for every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		AddonRegistry::reset();
		ModuleWiring::reset();
		IntegrationRegistry::instance()->reset();
		FixtureAddon::$active  = true;
		FixtureAddon::$version = '2.0.0';
		FixtureAddon::$api     = 1;
		Search::$reindexed     = array();
	}

	/**
	 * Register and wire the fixture, as the init hook would.
	 *
	 * @return FixtureAddon
	 */
	private function wired(): FixtureAddon {
		$addon = new FixtureAddon();
		$this->assertTrue( AddonRegistry::instance()->register( $addon ) );
		$addon->wire();
		IntegrationRegistry::instance()->reset();
		return $addon;
	}

	public function test_the_integrations_entry_is_built_from_the_declaration(): void {
		$this->wired();

		$entry = IntegrationRegistry::instance()->get( 'fixture' );

		$this->assertNotNull( $entry );
		$this->assertSame( 'Fixture', $entry['label'] );
		$this->assertSame( '2.0.0', $entry['version'] );
		$this->assertSame( '1.0.0', $entry['min_version'] );
		$this->assertTrue( $entry['has_feed'] );
		$this->assertTrue( $entry['has_search'] );
	}

	public function test_an_inactive_partner_lists_nothing(): void {
		FixtureAddon::$active = false;
		$this->wired();

		$this->assertNull( IntegrationRegistry::instance()->get( 'fixture' ) );
	}

	public function test_an_addon_for_a_newer_api_is_refused_with_a_notice_and_never_wired(): void {
		FixtureAddon::$api = BUDDYNEXT_ADDON_API + 1;
		$addon             = new FixtureAddon();

		$this->assertFalse( AddonRegistry::instance()->register( $addon ) );
		$this->assertSame( 'api_too_new', AddonRegistry::instance()->refused()['fixture']['reason'] );
		$this->assertNull( IntegrationRegistry::instance()->get( 'fixture' ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin ); // Only a network admin holds activate_plugins on a network.
		}
		wp_set_current_user( $admin );
		ob_start();
		AddonRegistry::instance()->render_notices();
		$this->assertStringContainsString( 'needs a newer version of the community plugin', (string) ob_get_clean() );
	}

	public function test_a_partner_below_the_floor_is_listed_for_the_badge_but_its_modules_stay_off(): void {
		FixtureAddon::$version = '0.9.0';
		$addon                 = new FixtureAddon();
		AddonRegistry::instance()->register( $addon );
		// Booting adds only the listing; the init wiring is skipped below the floor.
		$this->assertFalse( has_action( 'init', array( $addon, 'wire' ) ) );
		$this->assertSame( '0.9.0', IntegrationRegistry::instance()->get( 'fixture' )['version'] );
		$this->assertSame( 'partner_too_old', AddonRegistry::instance()->refused()['fixture']['reason'] );
		$this->assertNotContains( 'fixture_item', PostService::allowed_types() );
	}

	public function test_a_bad_or_duplicate_slug_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'BuddyNext\Addons\AddonRegistry::register' );
		$this->assertTrue( AddonRegistry::instance()->register( new FixtureAddon() ) );
		$this->assertFalse( AddonRegistry::instance()->register( new FixtureAddon() ) );
	}

	public function test_feed_cards_register_the_type_label_renderer_and_preview(): void {
		$this->wired();

		$this->assertContains( 'fixture_item', PostService::allowed_types() );
		$this->assertSame( 'Fixture', IntegrationActivity::card_types()['fixture_item']['label'] );

		$html = (string) apply_filters(
			'buddynext_render_post_body_fixture_item',
			'',
			array(
				'bn_post_type' => 'fixture_item',
				'link_preview' => array(
					'url'   => 'https://example.test/x',
					'title' => 'A fixture',
				),
			)
		);
		$this->assertStringContainsString( 'bn-post-card__bridge-card--fixture_item', $html );
		$this->assertStringContainsString( 'A fixture', $html );
	}

	public function test_a_card_type_that_is_already_taken_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'BuddyNext\Addons\ModuleWiring::feed_cards' );
		$module = new class() implements FeedCardModule {
			/**
			 * Built-in type.
			 *
			 * @return array<string,array{label:string,icon:string}>
			 */
			public function card_types(): array {
				return array(
					'photo' => array(
						'label' => 'Mine',
						'icon'  => 'x',
					),
				);
			}
		};

		ModuleWiring::wire( new FixtureAddon(), $module );

		$this->assertArrayNotHasKey( 'photo', IntegrationActivity::card_types() );
	}

	public function test_the_notification_source_adopts_the_contract_and_answers_visibility(): void {
		$this->wired();

		$this->assertArrayHasKey( 'fixture', IntegrationNotificationListener::sources() );
		$this->assertSame( 'fixture', IntegrationNotificationListener::sources()['fixture']['prefix'] );
		$this->assertTrue( IntegrationNotificationListener::adopted( 'fixture' ) );
		$this->assertArrayHasKey( 'ping', (array) apply_filters( 'fixture_community_notification_types', array() ) );

		$visible = (array) apply_filters(
			'fixture_community_notification_visible',
			array(
				10 => true,
				11 => true,
			),
			1,
			array(
				10 => array( 'object_id' => 7 ),
				11 => array( 'object_id' => 8 ),
			)
		);
		$this->assertFalse( $visible[10] );
		$this->assertTrue( $visible[11] );
	}

	public function test_search_answers_only_for_its_own_key_and_types(): void {
		$this->wired();

		$this->assertSame( array( 1, 2, 3 ), apply_filters( 'buddynext_integration_reindex_ids', array(), 'fixture', 0, 50 ) );
		$this->assertSame( array( 3 ), apply_filters( 'buddynext_integration_reindex_ids', array(), 'fixture', 2, 50 ) );
		$this->assertSame( array(), apply_filters( 'buddynext_integration_reindex_ids', array(), 'someone_else', 0, 50 ) );

		do_action( 'buddynext_integration_reindex_item', 'fixture', 2 );
		do_action( 'buddynext_integration_reindex_item', 'someone_else', 9 );
		$this->assertSame( array( 2 ), Search::$reindexed );

		$this->assertSame( 'https://example.test/fixture/5', apply_filters( 'buddynext_search_item_url', '', 'fixture_item', 5 ) );
		$this->assertSame( '', apply_filters( 'buddynext_search_item_url', '', 'other_type', 5 ) );
		$this->assertSame( 'https://kept.test', apply_filters( 'buddynext_search_item_url', 'https://kept.test', 'fixture_item', 5 ) );
	}

	public function test_switching_search_off_removes_what_was_indexed(): void {
		global $wpdb;
		$this->wired();
		$service = new SearchService();
		$service->index( 'fixture_item', 41, 'Alpha', 'body', 1 );
		$table = $wpdb->prefix . 'bn_search_index';
		$count = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'fixture_item'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count() );

		do_action( 'buddynext_integration_search_disabled', 'someone_else' );
		$this->assertSame( 1, $count() );

		do_action( 'buddynext_integration_search_disabled', 'fixture' );
		$this->assertSame( 0, $count() );
	}

	public function test_settings_are_registered_for_search_defaults_and_sanitising(): void {
		$addon = $this->wired();

		$keys = array_map( static fn( $f ) => $f->key, AddonRegistry::instance()->settings_fields( 'fixture' ) );

		$this->assertSame( array( 'buddynext_fixture_enabled', 'buddynext_fixture_greeting' ), $keys );
		$this->assertContains( 'buddynext_fixture_greeting', SettingsRegistry::all_keys() );
		$this->assertInstanceOf( AbstractAddon::class, $addon );
	}

	public function test_a_setting_outside_the_addons_namespace_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'BuddyNext\Addons\ModuleWiring::valid_settings_fields' );
		$module = new class() implements \BuddyNext\Addons\SettingsModule {
			/**
			 * One in-namespace key and one core option.
			 *
			 * @return \BuddyNext\Admin\Settings\Field[]
			 */
			public function settings_fields(): array {
				return array(
					new \BuddyNext\Admin\Settings\Field( array( 'key' => 'buddynext_fixture_ok', 'type' => 'text' ) ),
					new \BuddyNext\Admin\Settings\Field( array( 'key' => 'blogname', 'type' => 'text' ) ),
				);
			}
		};

		$fields = ModuleWiring::valid_settings_fields( new FixtureAddon(), $module );

		$this->assertSame( array( 'buddynext_fixture_ok' ), array_map( static fn( $f ) => $f->key, $fields ) );
	}

	public function test_settings_render_in_the_card_and_save_through_their_own_sanitiser(): void {
		$this->wired();
		update_option( 'buddynext_fixture_greeting', 'Hello' );

		ob_start();
		( new AddonSettingsForm() )->render( 'fixture' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="buddynext_fixture_greeting"', $html );
		$this->assertStringContainsString( 'value="Hello"', $html );

		$_POST['buddynext_fixture_greeting'] = '  <b>Hi</b> there ';
		$_POST['buddynext_fixture_enabled']  = '0';
		$_POST['blogname']                   = 'hijacked';
		$this->assertTrue( ( new AddonSettingsForm() )->save( 'fixture' ) );

		$this->assertSame( 'Hi there', get_option( 'buddynext_fixture_greeting' ) );
		$this->assertFalse( (bool) get_option( 'buddynext_fixture_enabled' ) );
		$this->assertNotSame( 'hijacked', get_option( 'blogname' ) );
		unset( $_POST['buddynext_fixture_greeting'], $_POST['buddynext_fixture_enabled'], $_POST['blogname'] );
	}

	public function test_discovery_shows_a_banner_to_a_signed_in_viewer_only_and_declares_its_paths(): void {
		$this->wired();

		ob_start();
		do_action(
			'buddynext_part_space_hero_after',
			array(
				'space_id'        => 12,
				'current_user_id' => 5,
			)
		);
		$this->assertStringContainsString( 'Linked: space 12', (string) ob_get_clean() );

		ob_start();
		do_action(
			'buddynext_part_space_hero_after',
			array(
				'space_id'        => 12,
				'current_user_id' => 0,
			)
		);
		$this->assertSame( '', (string) ob_get_clean() );

		$deny = (array) apply_filters( 'buddynext_client_nav_deny', array() );
		$this->assertSame( array( '/fixture/' ), $deny['addon_fixture'] );
	}

	public function test_a_module_that_throws_is_switched_off_and_the_site_keeps_working(): void {
		$this->wired();
		$failed = array();
		add_action(
			'buddynext_addon_module_failed',
			static function ( $slug, $module ) use ( &$failed ): void {
				$failed[] = array( $slug, $module );
			},
			10,
			2
		);
		// A discovery banner that throws.
		$boom = new class() implements \BuddyNext\Addons\DiscoveryModule {
			/**
			 * Always throws.
			 *
			 * @param int $space_id  Space.
			 * @param int $viewer_id Viewer.
			 * @return string
			 */
			public function space_banner( int $space_id, int $viewer_id ): string {
				throw new \RuntimeException( 'partner changed' );
			}

			/**
			 * No paths.
			 *
			 * @return string[]
			 */
			public function nav_deny_paths(): array {
				return array();
			}
		};
		$addon = new FixtureAddon();
		ModuleWiring::wire( $addon, $boom );

		ob_start();
		do_action(
			'buddynext_part_space_hero_after',
			array(
				'space_id'        => 1,
				'current_user_id' => 1,
			)
		);
		ob_end_clean();

		$this->assertTrue( ModuleWiring::is_disabled( $addon, $boom ) );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'DiscoveryModule@anonymous', $failed[0][1] );
	}

	public function test_deactivating_the_partner_deletes_nothing(): void {
		$this->wired();
		update_option( 'buddynext_fixture_greeting', 'Kept' );
		update_option( 'buddynext_integration_fixture_feed', '1' );

		FixtureAddon::$active = false;
		AddonRegistry::reset();
		IntegrationRegistry::instance()->reset();
		AddonRegistry::instance()->register( new FixtureAddon() );

		$this->assertSame( 'Kept', get_option( 'buddynext_fixture_greeting' ) );
		$this->assertSame( '1', get_option( 'buddynext_integration_fixture_feed' ) );
	}

	public function test_profile_panels_reach_the_portfolio_and_the_owner_only_one_stays_off_other_views(): void {
		$this->wired();
		$entry = IntegrationRegistry::instance()->get( 'fixture' );
		$this->assertTrue( $entry['has_nav'] );

		$member = self::factory()->user->create();
		$other  = self::factory()->user->create();
		$host   = new \BuddyNext\Profile\SuitePanelHost();
		\BuddyNext\Profile\SuitePanelHost::flush( $member );

		wp_set_current_user( $member );
		$own = wp_list_pluck( $host->panels( $member ), 'key' );
		$this->assertContains( 'fixture_public', $own );
		$this->assertContains( 'fixture_private', $own );

		wp_set_current_user( $other );
		$request  = new \WP_REST_Request( 'GET', "/buddynext/v1/members/{$member}/portfolio" );
		$response = rest_do_request( $request );
		$keys     = wp_list_pluck( $response->get_data()['panels'], 'key' );
		$this->assertSame( array( 'fixture_public' ), $keys );
	}

	public function test_profile_panels_go_quiet_when_the_navigation_switch_is_off(): void {
		$this->wired();
		update_option( 'buddynext_integration_fixture_nav', 0 );
		$member = self::factory()->user->create();
		\BuddyNext\Profile\SuitePanelHost::flush( $member );

		$this->assertSame( array(), ( new \BuddyNext\Profile\SuitePanelHost() )->panels( $member ) );
	}
}
