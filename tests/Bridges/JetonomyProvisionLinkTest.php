<?php
/**
 * A failed "Start the first discussion" link says why.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;

/**
 * Provision failure redirects and notice.
 */
class JetonomyProvisionLinkTest extends \WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner = self::factory()->user->create();
	}

	private function space( string $type ): object {
		$id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Link ' . $type,
				'slug' => 'link-' . $type . '-' . wp_rand( 1000, 9999 ),
				'type' => $type,
			)
		);
		return ( new SpaceService() )->get_object( (int) $id );
	}

	/**
	 * Run the handler and return where it redirected, '' when it stayed.
	 */
	private function provision_redirect( int $space_id, string $nonce ): string {
		$_GET['bn_provision_forum'] = (string) $space_id;
		$_GET['_bnpf']              = $nonce;
		$_SERVER['REQUEST_URI']     = '/spaces/cta/?bn_provision_forum=' . $space_id . '&_bnpf=' . $nonce;
		$catch                      = static function ( $location ): void {
			throw new \RuntimeException( (string) $location );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			( new JetonomyBridge() )->maybe_provision_and_redirect();
			return '';
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
			unset( $_GET['bn_provision_forum'], $_GET['_bnpf'] );
		}
	}

	public function test_every_failed_provision_link_returns_with_its_reason(): void {
		$space = $this->space( 'open' );
		$id    = (int) $space->id;

		wp_set_current_user( $this->owner );
		$expired = $this->provision_redirect( $id, 'stale' );
		$this->assertStringContainsString( 'bn_provision_error=expired', $expired );
		$this->assertStringNotContainsString( 'bn_provision_forum', $expired );

		// Valid nonce, nothing to create it with: no forum table here, so no forum URL.
		global $wpdb;
		$quiet  = $wpdb->suppress_errors( true );
		$failed = $this->provision_redirect( $id, wp_create_nonce( 'bn_provision_forum_' . $id ) );
		$wpdb->suppress_errors( $quiet );
		$this->assertStringContainsString( 'bn_provision_error=failed', $failed );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringContainsString( 'bn_provision_error=forbidden', $this->provision_redirect( $id, 'stale' ) );
	}

	public function test_a_hidden_spaces_slug_is_not_revealed_by_the_failure_redirect(): void {
		$secret = $this->space( 'secret' );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringNotContainsString( (string) $secret->slug, $this->provision_redirect( (int) $secret->id, 'stale' ) );
	}

	public function test_notice_offers_retry_only_to_someone_who_can_use_it(): void {
		$space = $this->space( 'open' );
		$args  = array( 'space_id' => (int) $space->id );
		$html  = function () use ( $args ): string {
			ob_start();
			( new JetonomyBridge() )->render_provision_failure_notice( $args );
			return (string) ob_get_clean();
		};

		$this->assertSame( '', $html() );

		$_GET['bn_provision_error'] = 'expired';
		wp_set_current_user( $this->owner );
		$this->assertStringContainsString( 'Try again', $html() );

		$_GET['bn_provision_error'] = 'forbidden';
		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringContainsString( 'owner or a moderator', $html() );
		$this->assertStringNotContainsString( 'Try again', $html() );

		$_GET['bn_provision_error'] = '<script>';
		$this->assertSame( '', $html() );
		unset( $_GET['bn_provision_error'] );
	}
}
