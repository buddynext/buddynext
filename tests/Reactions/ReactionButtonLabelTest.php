<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A post's reaction button reads "Like" until the member reacts, before and after the script loads.
 *
 * @package BuddyNext\Tests\Reactions
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Reactions;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Reactions\ReactionService;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The button printed "Like" while the card's script state said "React", so every
 * server-rendered card renamed its button to "React" as soon as the page loaded.
 *
 * @covers \BuddyNext\Reactions\ReactionService::button_label
 */
class ReactionButtonLabelTest extends \WP_UnitTestCase {

	/**
	 * A post's id.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * The viewer.
	 *
	 * @var int
	 */
	private int $viewer;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new Router() )->register();
		do_action( 'rest_api_init' );

		$this->viewer  = self::factory()->user->create();
		$this->post_id = (int) ( new PostService() )->create(
			$this->viewer,
			array(
				'type'    => 'text',
				'content' => 'Label check',
				'privacy' => 'public',
			)
		);
		wp_set_current_user( $this->viewer );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * The first page of the home feed, as the cards print it.
	 *
	 * @return string
	 */
	private function card_html(): string {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home/page' );
		return (string) ( rest_get_server()->dispatch( $request )->get_data()['html'] ?? '' );
	}

	/**
	 * The label the card's script state starts from.
	 *
	 * @param string $html Card markup.
	 * @return string
	 */
	private static function state_label( string $html ): string {
		return preg_match( '/&quot;reactionLabel&quot;:&quot;([^&]*)&quot;/', $html, $m ) ? $m[1] : '';
	}

	public function test_an_unreacted_card_says_like_in_markup_and_in_script_state(): void {
		$html = $this->card_html();

		$this->assertStringContainsString( 'data-wp-text="state.reactionLabel">Like<', $html );
		$this->assertSame( 'Like', self::state_label( $html ), 'The script state must agree with the printed label.' );
	}

	public function test_a_reacted_card_names_the_reaction_in_both(): void {
		( new ReactionService() )->toggle( $this->viewer, 'post', $this->post_id, 'love' );
		$html = $this->card_html();

		$this->assertStringContainsString( 'data-wp-text="state.reactionLabel">Love<', $html );
		$this->assertSame( 'Love', self::state_label( $html ) );
	}

	public function test_the_card_script_never_falls_back_to_react_for_the_post_button(): void {
		$js = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/js/feed/post-card.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( "reactDefaultLabel || 'React'", $js );
		$this->assertStringNotContainsString( "t( 'react', 'Like' )", $js, "The 'react' string is \"React\", so it is the wrong fallback for this button." );
	}
}
