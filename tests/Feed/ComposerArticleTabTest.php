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

		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertSame( $summary, ArticleProvider::composer_summary( $uid ) );
		$this->assertSame( $before, $wpdb->num_queries, 'Resolved once per request.' );

		$this->assertSame(
			array(
				'drafts'   => 0,
				'articles' => 0,
				'draft'    => null,
			),
			ArticleProvider::composer_summary( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ),
			'Nothing for a member who cannot write.'
		);
	}

	public function test_the_latest_draft_is_offered_to_continue(): void {
		$uid    = $this->writer();
		$older  = self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
				'post_title'  => 'Older',
			)
		);
		$latest = self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
				'post_title'  => 'Notes <script>alert(1)</script> & more',
			)
		);
		global $wpdb;
		$two_hours = gmdate( 'Y-m-d H:i:s', time() - 7200 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2026-01-01 10:00:00',
				'post_modified_gmt' => '2026-01-01 10:00:00',
			),
			array( 'ID' => $older )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $two_hours,
				'post_modified_gmt' => $two_hours,
				'post_title'        => 'Notes <script>alert(1)</script> & more',
			),
			array( 'ID' => $latest )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $older );
		clean_post_cache( $latest );

		wp_set_current_user( $uid );
		$draft = ArticleProvider::composer_summary( $uid )['draft'];
		$this->assertSame( $latest, $draft['id'] );
		$this->assertSame( get_edit_post_link( $latest, 'raw' ), $draft['edit_url'], 'The fallback continues in the WordPress editor.' );

		$html = $this->feed( $uid );
		$this->assertStringContainsString( 'Continue writing', $html );
		$this->assertStringContainsString( 'Draft, edited 2h ago', $html );
		$this->assertStringContainsString( 'Or start a new article: title (optional)', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html, 'The draft title is escaped.' );
		$this->assertStringContainsString( 'Notes &lt;script&gt;alert(1)&lt;/script&gt; &amp; more', $html );
	}

	public function test_a_draft_with_no_gmt_time_still_shows_when_it_was_edited(): void {
		// Some save paths leave post_modified_gmt as 0000-00-00: the card must not read "edited 29 November -0001".
		$uid   = $this->writer();
		$draft = self::factory()->post->create(
			array(
				'post_author' => $uid,
				'post_status' => 'draft',
				'post_title'  => 'No gmt time',
			)
		);
		global $wpdb;
		$local = current_time( 'mysql' );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7200 ),
				'post_modified_gmt' => '0000-00-00 00:00:00',
			),
			array( 'ID' => $draft )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $draft );

		wp_set_current_user( $uid );
		$html = $this->feed( $uid );
		$this->assertStringContainsString( 'Draft, edited 2h ago', $html );
		$this->assertStringNotContainsString( '-0001', $html );
		unset( $local );
	}

	public function test_no_draft_card_without_a_draft(): void {
		$html = $this->feed( $this->writer() );
		$this->assertStringNotContainsString( 'Continue writing', $html );
		$this->assertStringContainsString( 'Title (optional)', $html );
		$this->assertStringContainsString( 'placeholder="Give it a title"', $html );
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
}
