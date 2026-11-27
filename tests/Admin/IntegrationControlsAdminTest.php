<?php
/**
 * Integration Settings renders one table row per integration.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\IntegrationControlsAdmin;

/**
 * @covers \BuddyNext\Admin\IntegrationControlsAdmin
 */
class IntegrationControlsAdminTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'buddynext_integrations',
			static function ( array $items ): array {
				$items['qaint'] = array(
					'label'    => 'QA Integration',
					'has_nav'  => true,
					'has_feed' => true,
					'subtabs'  => array( 'one' => 'One', 'opt' => 'Option' ),
					'subtab_options' => array( 'opt' => 'An option card that needs Navigation.' ),
				);
				return $items;
			}
		);
		\BuddyNext\Integrations\IntegrationRegistry::instance()->reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'buddynext_integrations' );
		\BuddyNext\Integrations\IntegrationRegistry::instance()->reset();
		parent::tear_down();
	}

	/**
	 * Each surface is a switch on its card, a missing surface says so, and
	 * the field names the save handler reads are unchanged.
	 */
	public function test_each_integration_is_one_row_with_a_switch_per_surface(): void {
		ob_start();
		( new IntegrationControlsAdmin() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="bn-int-card"', $html );
		$this->assertStringContainsString( 'name="nav[qaint]"', $html );
		$this->assertStringContainsString( 'name="feed[qaint]"', $html );
		$this->assertStringNotContainsString( 'name="search[qaint]"', $html, 'No search surface, so no search switch.' );
		$this->assertStringContainsString( 'Not offered by this integration.', $html, 'The missing surface says so.' );
		$this->assertStringContainsString( 'name="subtab[qaint][one]"', $html );
		$this->assertSame( 1, substr_count( $html, 'bn-int-card__title">QA Integration' ), 'One card per integration.' );
	}

	/**
	 * A row backed by an active Wbcom companion shows its bundled mark; an
	 * integration that declares no icon keeps the letter tile.
	 */
	public function test_row_shows_product_icon_or_letter(): void {
		add_filter(
			'buddynext_integrations',
			static function ( array $items ): array {
				$items['qafam'] = array( 'label' => 'QA Family', 'has_feed' => true );
				return $items;
			}
		);
		add_filter(
			'buddynext_companions',
			static fn( array $all ): array => array(
				'jetonomy' => array(
					'label'       => 'Jetonomy',
					'integration' => 'qafam',
					'detect'      => static fn(): bool => true,
				),
			) + $all
		);
		\BuddyNext\Integrations\IntegrationRegistry::instance()->reset();

		ob_start();
		( new IntegrationControlsAdmin() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'assets/img/companions/jetonomy.svg', $html, 'First-party product shows its mark.' );
		$this->assertStringContainsString( '<span class="bn-int-tile" aria-hidden="true">Q</span>', $html, 'Unknown integration keeps the letter tile.' );
		$this->assertStringNotContainsString( 'style="background:', $html, 'No hashed colour tiles.' );
	}

	/**
	 * Post the Integration Settings form as an admin and return.
	 *
	 * @param array<string,mixed> $post Fields the browser would submit.
	 * @return void
	 */
	private function save_form( array $post ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST                = $post;
		$_POST['_wpnonce']    = wp_create_nonce( 'bn_integration_controls_save' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		$stop                 = static function (): never {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			( new IntegrationControlsAdmin() )->handle_save();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		remove_filter( 'wp_redirect', $stop );
		$_POST = array();
	}

	/**
	 * Navigation off, save, then on, save: the option card that was drawn disabled (and so not submitted) keeps its
	 * stored choice instead of being switched off.
	 *
	 * @return void
	 */
	public function test_turning_navigation_back_on_keeps_a_disabled_option_card(): void {
		update_option( 'buddynext_integration_qaint_subtab_opt', '1' );

		// Navigation turned off; the card was enabled (Navigation was on), so its box is submitted checked.
		$this->save_form( array( 'nav' => array(), 'feed' => array( 'qaint' => '1' ), 'subtab' => array( 'qaint' => array( 'one' => '1', 'opt' => '1' ) ) ) );
		$this->assertSame( '1', (string) get_option( 'buddynext_integration_qaint_subtab_opt' ), 'Switching Navigation off changed the option card.' );

		// Navigation turned back on; the card was drawn disabled, so the browser does not send it.
		$this->save_form( array( 'nav' => array( 'qaint' => '1' ), 'feed' => array( 'qaint' => '1' ), 'subtab' => array( 'qaint' => array( 'one' => '1' ) ) ) );
		$this->assertSame( '1', (string) get_option( 'buddynext_integration_qaint_subtab_opt' ), 'Turning Navigation back on silently switched the option card off.' );

		// With Navigation on the card is enabled again: unticking it is honoured.
		$this->save_form( array( 'nav' => array( 'qaint' => '1' ), 'feed' => array( 'qaint' => '1' ), 'subtab' => array( 'qaint' => array( 'one' => '1' ) ) ) );
		$this->assertSame( '0', (string) get_option( 'buddynext_integration_qaint_subtab_opt' ), 'An enabled card that is left unticked must switch off.' );
	}
}
