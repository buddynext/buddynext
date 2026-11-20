<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The article provider contract, without Member Blog (the fallback path).
 *
 * The Member Blog provider is covered in tests/Bridges/MemberBlogWriteHubTest.php,
 * which runs with Member Blog's constant and functions stubbed.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\ArticleProvider;

/**
 * @covers \BuddyNext\Feed\ArticleProvider
 */
class ArticleProviderTest extends \WP_UnitTestCase {

	private const KEYS = array( 'key', 'label', 'icon', 'can_write', 'new_url', 'drafts_url', 'my_articles_url', 'pending_url', 'title_arg', 'supports_space' );

	public function test_a_member_who_can_use_the_editor_gets_the_wordpress_fallback(): void {
		$uid      = self::factory()->user->create( array( 'role' => 'author' ) );
		$provider = ArticleProvider::for_user( $uid );

		$this->assertSame( self::KEYS, array_keys( $provider ) );
		$this->assertSame( ArticleProvider::FALLBACK_KEY, $provider['key'] );
		$this->assertTrue( $provider['can_write'] );
		$this->assertSame( admin_url( 'post-new.php' ), $provider['new_url'] );
		$this->assertStringStartsWith( admin_url( 'edit.php' ), $provider['drafts_url'] );
		$this->assertStringContainsString( 'post_status=draft', $provider['drafts_url'] );
		$this->assertStringStartsWith( admin_url( 'edit.php' ), $provider['my_articles_url'] );
		$this->assertSame( admin_url( 'edit.php?post_status=pending&author=' . $uid ), $provider['pending_url'] );
		$this->assertSame( 'post_title', $provider['title_arg'] );
		$this->assertFalse( $provider['supports_space'] );
	}

	public function test_a_subscriber_or_guest_with_no_provider_gets_null(): void {
		$this->assertNull( ArticleProvider::for_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) );
		$this->assertNull( ArticleProvider::for_user( 0 ) );
	}

	public function test_a_filtered_provider_is_normalised_to_the_full_shape(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_filter(
			'buddynext_article_provider',
			static fn( $provider, int $user_id ): array => array(
				'key'       => 'my-editor',
				'can_write' => 1,
				'new_url'   => home_url( '/write/?u=' . $user_id ),
			),
			10,
			2
		);

		$provider = ArticleProvider::for_user( $uid );
		$this->assertSame( self::KEYS, array_keys( $provider ) );
		$this->assertSame( 'my-editor', $provider['key'] );
		$this->assertTrue( $provider['can_write'] );
		$this->assertSame( home_url( '/write/?u=' . $uid ), $provider['new_url'] );
		$this->assertSame( '', $provider['drafts_url'] );
		$this->assertSame( '', $provider['title_arg'] );
	}

	public function test_a_filter_returning_null_hands_over_to_the_fallback(): void {
		add_filter( 'buddynext_article_provider', '__return_null' );
		$this->assertSame( ArticleProvider::FALLBACK_KEY, ArticleProvider::for_user( self::factory()->user->create( array( 'role' => 'editor' ) ) )['key'] );
	}

	public function test_it_resolves_once_per_request_per_member(): void {
		$uid   = self::factory()->user->create( array( 'role' => 'author' ) );
		$calls = 0;
		add_filter(
			'buddynext_article_provider',
			static function ( $provider ) use ( &$calls ) {
				++$calls;
				return $provider;
			}
		);

		$first = ArticleProvider::for_user( $uid );
		$this->assertSame( $first, ArticleProvider::for_user( $uid ) );
		$this->assertSame( 1, $calls );

		ArticleProvider::for_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 2, $calls, 'Another member resolves on their own.' );
	}

	public function test_the_composer_tab_defaults_to_the_main_feed_for_a_writer(): void {
		$writer = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertTrue( ArticleProvider::show_composer_tab( $writer, array( 'surface' => 'feed' ) ) );
		$this->assertFalse(
			ArticleProvider::show_composer_tab(
				$writer,
				array(
					'surface'  => 'space',
					'space_id' => 3,
				)
			),
			'Space composers do not offer it by default.'
		);
		$this->assertFalse( ArticleProvider::show_composer_tab( self::factory()->user->create( array( 'role' => 'subscriber' ) ), array( 'surface' => 'feed' ) ), 'Non-writer.' );
		$this->assertFalse( ArticleProvider::show_composer_tab( 0, array( 'surface' => 'feed' ) ), 'Guest.' );

		update_option( 'buddynext_integration_blog_nav', '0' );
		$this->assertFalse( ArticleProvider::show_composer_tab( $writer, array( 'surface' => 'feed' ) ), 'The Blog posts nav switch turns it off.' );
	}

	public function test_the_composer_tab_filter_has_the_last_word(): void {
		$writer  = self::factory()->user->create( array( 'role' => 'author' ) );
		$context = array();
		add_filter(
			'buddynext_composer_article_tab',
			static function ( bool $show, array $ctx ) use ( &$context ): bool {
				$context = $ctx;
				return 'space' === $ctx['surface'] ? true : $show;
			},
			10,
			2
		);

		$this->assertTrue(
			ArticleProvider::show_composer_tab(
				$writer,
				array(
					'surface'  => 'space',
					'space_id' => 3,
				)
			)
		);
		$this->assertSame(
			array(
				'surface'  => 'space',
				'space_id' => 3,
				'user_id'  => $writer,
			),
			$context
		);
	}

	public function test_a_long_intro_ends_with_a_plain_ellipsis_not_the_wordpress_marker(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_excerpt' => '',
				'post_content' => str_repeat( 'Plenty of words fill this article. ', 30 ),
			)
		);

		$intro = \BuddyNext\Feed\BlogPostListener::excerpt_for( $post, 28 );

		$this->assertStringEndsWith( '…', $intro );
		$this->assertStringNotContainsString( '[', $intro );
	}
}
