<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The profile Articles tab lists only what everyone may read.
 *
 * Owner decision: blogs are public by default, and an author's narrower choice
 * (Private, or a password) never leaks the title, cover or excerpt to anyone on
 * any BuddyNext surface. Drafts, pending and scheduled posts are not articles yet.
 * Matrix: public / private / password / draft / pending / scheduled x guest /
 * member / author / admin, across the panel HTML, the REST list and both counts.
 *
 * Runs in separate processes: the tab is live only while Member Blog's version
 * constant is defined, and a constant cannot be undefined again.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\MemberBlogBridge;
use BuddyNext\Bridges\MemberBlogRestController;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\MemberBlogBridge
 * @covers \BuddyNext\Bridges\MemberBlogRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberBlogArticlesLeakTest extends \WP_UnitTestCase {

	private int $author;

	/**
	 * Visibility label => [ title, excerpt, cover url ].
	 *
	 * @var array<string,array{0:string,1:string,2:string}>
	 */
	private array $posts = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_VERSION', 'test' );
		}
		Installer::run();

		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		$shapes       = array(
			'public'    => array( 'post_status' => 'publish' ),
			'private'   => array( 'post_status' => 'private' ),
			'password'  => array(
				'post_status'   => 'publish',
				'post_password' => 'letmein',
			),
			'draft'     => array( 'post_status' => 'draft' ),
			'pending'   => array( 'post_status' => 'pending' ),
			'scheduled' => array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			),
		);
		foreach ( $shapes as $label => $shape ) {
			$post_id    = self::factory()->post->create(
				array_merge(
					array(
						'post_author'  => $this->author,
						'post_title'   => 'Title' . ucfirst( $label ) . 'Zq',
						'post_excerpt' => 'Excerpt' . ucfirst( $label ) . 'Zq',
						'post_content' => 'Body.',
					),
					$shape
				)
			);
			$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
			set_post_thumbnail( $post_id, $attachment );

			$this->posts[ $label ] = array(
				'Title' . ucfirst( $label ) . 'Zq',
				'Excerpt' . ucfirst( $label ) . 'Zq',
				(string) get_the_post_thumbnail_url( $post_id, 'medium' ),
			);
		}
	}

	/**
	 * The four viewers of the author's tab.
	 *
	 * @return array<string,int>
	 */
	private function viewers(): array {
		return array(
			'guest'  => 0,
			'member' => self::factory()->user->create(),
			'author' => $this->author,
			'admin'  => self::factory()->user->create( array( 'role' => 'administrator' ) ),
		);
	}

	private function panel_html( int $viewer ): string {
		wp_set_current_user( $viewer );
		ob_start();
		( new MemberBlogBridge() )->render_profile_articles_panel( $this->author, $viewer );
		return (string) ob_get_clean();
	}

	private function rest_json( int $viewer ): string {
		wp_set_current_user( $viewer );
		$request = new \WP_REST_Request( 'GET', '/buddynext/v1/members/' . $this->author . '/blog' );
		$request->set_param( 'id', $this->author );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 50 );
		$response = ( new MemberBlogRestController() )->get_articles( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return (string) wp_json_encode( $response->get_data(), JSON_UNESCAPED_SLASHES );
	}

	private function assert_only_public( string $output, string $where ): void {
		foreach ( $this->posts as $label => $fields ) {
			foreach ( $fields as $field ) {
				if ( 'public' === $label ) {
					$this->assertStringContainsString( $field, $output, "$where: the public article is listed in full." );
				} else {
					$this->assertStringNotContainsString( $field, $output, "$where: the $label post leaked." );
				}
			}
		}
	}

	public function test_the_panel_shows_every_viewer_the_public_article_only(): void {
		foreach ( $this->viewers() as $who => $viewer ) {
			$this->assert_only_public( $this->panel_html( $viewer ), "Panel, $who" );
		}
	}

	public function test_the_rest_list_shows_every_viewer_the_public_article_only(): void {
		foreach ( $this->viewers() as $who => $viewer ) {
			$json = $this->rest_json( $viewer );
			$this->assert_only_public( $json, "REST, $who" );
			$this->assertSame( 1, json_decode( $json, true )['total'], "REST total, $who" );
		}
	}

	public function test_the_counts_match_the_list(): void {
		$bridge = new MemberBlogBridge();
		$this->assertSame( 1, $bridge->article_count( $this->author ), 'Tab badge.' );
		$this->assertSame( 1, $bridge->article_total( $this->author ), 'Past-the-end check.' );
	}

	public function test_a_partner_filter_cannot_widen_the_list(): void {
		add_filter(
			'bp_member_blog_dashboard_query_args',
			static function ( array $args ): array {
				unset( $args['has_password'] );
				$args['post_status'] = array( 'publish', 'private', 'draft' );
				return $args;
			}
		);
		$this->assert_only_public( $this->rest_json( $this->author ), 'REST, widened by a filter' );
	}
}
