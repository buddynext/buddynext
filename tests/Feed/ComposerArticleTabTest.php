<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The What's New composer's Post / Article tabs (partials/composer.php +
 * partials/composer-article.php) and the counts behind them
 * (ArticleProvider::composer_summary()).
 *
 * Runs on the WordPress-editor fallback provider and on a filtered provider;
 * the Member Blog provider is covered in tests/Bridges/MemberBlogWriteHubTest.php.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\ArticleProvider;

/**
 * @covers \BuddyNext\Feed\ArticleProvider::composer_summary
 */
class ComposerArticleTabTest extends \WP_UnitTestCase {

	private function render( int $user_id, array $extra = array() ): string {
		wp_set_current_user( $user_id );
		ob_start();
		buddynext_get_template(
			'partials/composer.php',
			array_merge(
				array(
					'space_id'        => null,
					'current_user_id' => $user_id,
				),
				$extra
			)
		);
		return (string) ob_get_clean();
	}

	private function feed( int $user_id ): string {
		return $this->render( $user_id, array( 'article_tab' => true ) );
	}

	private function writer(): int {
		return self::factory()->user->create( array( 'role' => 'author' ) );
	}

	public function test_a_writer_gets_both_tabs_on_the_main_feed_only(): void {
		$html = $this->feed( $this->writer() );

		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'aria-label="What to share"', $html );
		$this->assertMatchesRegularExpression( '/role="tab"[^>]*aria-selected="true"[^>]*tabindex="0"[^>]*data-mode="post"/s', $html );
		$this->assertMatchesRegularExpression( '/role="tab"[^>]*aria-selected="false"[^>]*tabindex="-1"[^>]*data-mode="article"/s', $html );
		$this->assertSame( 2, substr_count( $html, 'role="tabpanel"' ) );
		$this->assertStringContainsString( 'Write something longer, with a cover image and headings.', $html );
		$this->assertStringContainsString( 'Start writing', $html );
		$this->assertStringContainsString( 'class="bn-composer__prompt"', $html, 'The post box is still there, unchanged.' );
	}

	public function test_no_tabs_for_non_writers_guests_or_other_composers(): void {
		$this->assertStringNotContainsString( 'role="tablist"', $this->feed( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ), 'Non-writer.' );
		$this->assertSame( '', trim( $this->render( 0, array( 'article_tab' => true ) ) ), 'Guest: no composer at all.' );

		$writer = $this->writer();
		$this->assertStringNotContainsString( 'role="tablist"', $this->render( $writer ), 'Profile composer (no flag).' );
		$space_html = $this->render( $writer, array( 'space_id' => 987654 ) );
		$this->assertStringNotContainsString( 'bn-composer-switch', $space_html, 'Space composer.' );
	}

	public function test_the_filter_can_offer_it_on_a_profile_or_take_it_away(): void {
		$writer = $this->writer();
		$seen   = array();
		add_filter(
			'buddynext_composer_article_tab',
			static function ( bool $show, array $ctx ) use ( &$seen ): bool {
				$seen[] = $ctx['surface'];
				return 'profile' === $ctx['surface'] ? true : $show;
			},
			10,
			2
		);
		$this->assertStringContainsString( 'role="tablist"', $this->render( $writer ) );
		$this->assertSame( array( 'profile' ), $seen );

		add_filter( 'buddynext_composer_article_tab', '__return_false', 20 );
		$this->assertStringNotContainsString( 'role="tablist"', $this->feed( $writer ) );
	}

	public function test_counts_are_one_grouped_query_and_match_the_lists(): void {
		$uid   = $this->writer();
		$other = $this->writer();
		self::factory()->post->create_many(
			2,
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create_many(
			3,
			array(
				'post_author' => $uid,
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'private',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'pending',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
				'post_type'   => 'page',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
			)
		);

		$summary = ArticleProvider::composer_summary( $uid );
		$this->assertSame( 2, $summary['drafts'], 'My drafts = draft posts only.' );
		$this->assertSame( 4, $summary['articles'], 'My articles = publish + private.' );
		$this->assertSame( 1, $summary['pending'], 'Pending = posts held for review only.' );

		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertSame( $summary, ArticleProvider::composer_summary( $uid ) );
		$this->assertSame( $before, $wpdb->num_queries, 'Resolved once per request.' );

		$this->assertSame(
			array(
				'drafts'   => 0,
				'articles' => 0,
				'pending'  => 0,
			),
			ArticleProvider::composer_summary( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ),
			'Nothing for a member who cannot write.'
		);
	}

	public function test_the_article_panel_is_one_row_and_never_offers_a_draft_card(): void {
		// The Article panel must not make the feed jump when the tabs switch: explainer + ONE row, and
		// My drafts / My articles live in the tab row, not in the panel.
		$uid = self::factory()->post->create(
			array(
				'post_author' => $this->writer(),
				'post_status' => 'draft',
				'post_title'  => 'An unfinished piece',
			)
		);
		$this->assertGreaterThan( 0, $uid );
		$author = (int) get_post_field( 'post_author', $uid );
		$html   = $this->feed( $author );

		$this->assertStringNotContainsString( 'Continue writing', $html );
		$this->assertStringNotContainsString( 'An unfinished piece', $html );
		$this->assertStringContainsString( 'placeholder="Title (optional)"', $html );
		$this->assertMatchesRegularExpression( '/bn-composer-article__row.*bn-composer-article__title.*bn-composer-article__start/s', $html );

		// The counts sit beside the tabs, in the head row, before the panels.
		$head_pos  = strpos( $html, 'bn-composer-switch__head' );
		$links_pos = strpos( $html, 'bn-composer-switch__links' );
		$panel_pos = strpos( $html, 'bn-composer-article"' );
		$this->assertNotFalse( $head_pos );
		$this->assertGreaterThan( $head_pos, $links_pos );
		$this->assertLessThan( $panel_pos, $links_pos, 'My drafts / My articles are in the tab row, not in the Article panel.' );
		$this->assertStringContainsString( 'My drafts', $html );
		$this->assertStringContainsString( 'My articles', $html );
	}

	public function test_the_fallback_form_posts_the_title_to_the_wordpress_editor(): void {
		$uid  = $this->writer();
		$html = $this->feed( $uid );

		$this->assertStringContainsString( 'method="get" action="' . esc_url( admin_url( 'post-new.php' ) ) . '"', $html );
		$this->assertMatchesRegularExpression( '/class="bn-input bn-composer-article__title"[^>]*name="post_title"/s', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'edit.php?post_status=draft&author=' . $uid ) ) . '"', $html );
		$this->assertStringContainsString( 'My articles', $html );
	}

	public function test_an_editor_without_a_title_arg_gets_bn_title_and_keeps_its_own_args(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_filter(
			'buddynext_article_provider',
			static fn(): array => array(
				'key'             => 'my-editor',
				'can_write'       => true,
				'new_url'         => home_url( '/?page_id=42' ),
				'drafts_url'      => home_url( '/drafts/' ),
				'my_articles_url' => '',
				'title_arg'       => '',
			)
		);

		$html = $this->feed( $uid );
		$this->assertMatchesRegularExpression( '/class="bn-input bn-composer-article__title"[^>]*name="bn_title"/s', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page_id" value="42">', $html, 'A GET form drops the action\'s query string, so it travels as a field.' );
		$this->assertStringContainsString( 'My drafts', $html );
		$this->assertStringNotContainsString( 'My articles', $html, 'No link without a URL.' );
	}

	private function pending_link( int $uid, int $count ): string {
		return '/class="bn-composer-switch__link"[^>]*href="' . preg_quote( esc_url( admin_url( 'edit.php?post_status=pending&author=' . $uid ) ), '/' )
			. '">\s*Pending\s*<span class="bn-composer-switch__count">' . $count . '<\/span>/';
	}

	public function test_pending_counts_posts_held_for_review_only(): void {
		$uid = $this->writer();
		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'pending',
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);
		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'pending',
				'post_type'   => 'page',
			)
		);

		$summary = ArticleProvider::composer_summary( $uid );
		$this->assertSame( 1, $summary['pending'], 'Only the post held for review: a scheduled post is not in Member Blog\'s Pending list, and pages never count.' );
		$this->assertSame( 1, $summary['drafts'], 'My drafts is unaffected.' );
		$this->assertSame( 0, $summary['articles'] );
	}

	public function test_the_pending_link_shows_only_while_something_is_waiting(): void {
		$uid  = $this->writer();
		$html = $this->feed( $uid );
		$this->assertDoesNotMatchRegularExpression( '/>\s*Pending\s*<span class="bn-composer-switch__count">/', $html, 'Nothing waiting: no link.' );
		$this->assertStringNotContainsString( 'post_status=pending', $html );

		self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'pending',
			)
		);
		$this->assertMatchesRegularExpression( $this->pending_link( $uid, 1 ), $this->feed( $uid ), 'Pending (1) after a post goes to review.' );

		$other = $this->writer();
		self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$html = $this->feed( $other );
		$this->assertStringNotContainsString( 'post_status=pending', $html, 'A member whose only waiting post is scheduled gets no Pending link (it would open an empty list).' );
		$this->assertLessThan( strpos( $html, 'bn-composer-article"' ), strpos( $html, 'post_status=pending' ), 'In the tab row, beside My drafts / My articles.' );
	}

	public function test_counts_refresh_when_a_post_changes_status_author_or_is_deleted(): void {
		$uid   = $this->writer();
		$other = $this->writer();
		$post  = self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'pending',
			)
		);
		$this->assertSame( 1, ArticleProvider::composer_summary( $uid )['pending'] );

		wp_publish_post( $post );
		$this->assertSame( 0, ArticleProvider::composer_summary( $uid )['pending'], 'Publish drops the cache.' );
		$this->assertSame( 1, ArticleProvider::composer_summary( $uid )['articles'] );
		$this->assertSame( 0, ArticleProvider::composer_summary( $other )['articles'] );

		wp_update_post(
			array(
				'ID'          => $post,
				'post_author' => $other,
			)
		);
		$this->assertSame( 0, ArticleProvider::composer_summary( $uid )['articles'], 'Old author.' );
		$this->assertSame( 1, ArticleProvider::composer_summary( $other )['articles'], 'New author.' );

		wp_delete_post( $post, true );
		$this->assertSame( 0, ArticleProvider::composer_summary( $other )['articles'], 'Deleted.' );
	}
}
