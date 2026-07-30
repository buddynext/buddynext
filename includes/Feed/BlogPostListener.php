<?php
/**
 * Site tracking: publish a feed card when a WordPress post is published.
 *
 * WordPress is a blogging platform first, and a community running on it expects
 * a member's published article to show up in the feed - BuddyPress has shipped
 * this as a core component (Site Tracking) for years. BuddyNext reacted to no
 * post transition at all, so publishing an article put nothing in the feed.
 *
 * It also mattered for migration: the importer brings historical BuddyPress
 * `new_blog_post` activity across, so a migrated community had blog cards in
 * its feed and then silently stopped producing new ones.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Publishes and retracts activity cards for published site content.
 */
class BlogPostListener implements ListenerInterface {

	/**
	 * The feed card type these activities are stored as.
	 *
	 * Its OWN type, not the generic 'link'. The bridge contract requires a real
	 * PostService type per integration so Explore and the feed can classify and
	 * filter it, and so it gets its own renderer seam - which is what lets a
	 * blog post render as an article card rather than a bare URL row.
	 */
	public const TYPE = 'article';

	/**
	 * Meta key carrying the source post id on the activity card.
	 *
	 * Keyed on the ID rather than the URL because a permalink can change (slug
	 * edits, a permalink-structure change), and a card that can no longer be
	 * found is a card that can never be retracted.
	 */
	public const META_POST_ID = 'source_post_id';

	/**
	 * The feed card for a post, if one exists.
	 *
	 * The authoritative "is this article already in the feed?" answer, keyed on
	 * the post id held in the card's link_meta rather than on the permalink -
	 * a permalink can change, and a card that cannot be found is a card that
	 * can neither be de-duplicated nor retracted.
	 *
	 * Public and static because the comment sync resolves the same pairing and
	 * must not hold a second opinion about it.
	 *
	 * @param int $post_id WordPress post id.
	 * @return int Feed card id, or 0.
	 */
	public static function card_id_for_post( int $post_id ): int {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_posts
				  WHERE type = %s
				    AND JSON_EXTRACT( link_meta, %s ) = %d
				  ORDER BY id DESC LIMIT 1",
				self::TYPE,
				'$."' . self::META_POST_ID . '"',
				$post_id
			)
		);
	}

	/**
	 * Hook into the post lifecycle and the renderer seam.
	 *
	 * @return void
	 */
	public function register(): void {
		// wp_after_insert_post, not transition_post_status, and the difference is
		// visible on the card: transition fires while the post row is being
		// written, BEFORE its meta and terms are saved, so the featured image is
		// not attached yet and every card came out with an empty cover. This
		// hook fires once everything belonging to the post is on disk, and it
		// hands over the previous post object so the publish transition is still
		// detectable.
		add_action( 'wp_after_insert_post', array( $this, 'on_after_insert' ), 10, 4 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		add_filter( 'buddynext_render_post_body_' . self::TYPE, array( $this, 'render_card' ), 10, 2 );
		add_filter( 'buddynext_integrations', array( $this, 'register_integration' ) );
	}

	/**
	 * Declare the owner-facing toggle.
	 *
	 * Site tracking is the one "integration" whose partner is WordPress itself,
	 * so unlike a bridge there is no plugin to detect - it is always available.
	 * It still declares an entry because an owner whose members publish
	 * constantly needs one switch to stop the feed filling with articles, and
	 * the Integrations screen is where they will look for it.
	 *
	 * @param array<string, array<string, mixed>> $items Registered integrations.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_integration( array $items ): array {
		$items['blog'] = array(
			'label'      => __( 'Blog posts', 'buddynext' ),
			'version'    => null,
			'has_nav'    => false,
			'has_feed'   => true,
			'has_search' => false,
		);

		return $items;
	}

	/**
	 * Which post types produce a feed card.
	 *
	 * Only `post` by default. A site with forty custom post types must not have
	 * every one of them flood the community feed the moment this ships, so
	 * adding a type is an explicit opt-in.
	 *
	 * @return string[]
	 */
	private function tracked_types(): array {
		/**
		 * Filter the post types whose publication posts a feed card.
		 *
		 * @param string[] $types Post type slugs. Default: array( 'post' ).
		 */
		return array_map( 'strval', (array) apply_filters( 'buddynext_site_tracking_post_types', array( 'post' ) ) );
	}

	/**
	 * Publish a card when a post becomes public; retract it when it stops being.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public function on_after_insert( $post_id, $post, $update, $post_before ): void {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->tracked_types(), true ) ) {
			return;
		}

		$was_published = $post_before instanceof \WP_Post && 'publish' === $post_before->post_status;
		$is_published  = 'publish' === $post->post_status;

		// Unpublished, trashed, or made private: the card must go with it.
		if ( $was_published && ! $is_published ) {
			$this->retract( $post );
			return;
		}

		// Only the moment it becomes public. Editing a published post must not
		// post a second card - IntegrationActivity::publish() is idempotent per
		// link, but bailing here means an edit does not even reach it.
		if ( ! $is_published || $was_published ) {
			return;
		}

		// ...and never a SECOND card for the same article, whatever route the
		// post takes. Keyed on the post id rather than the link, because
		// IntegrationActivity::publish() de-dupes on the URL and an author who
		// edits the slug and re-publishes would otherwise get a second card for
		// the same piece under its new permalink.
		//
		// This is a "never two" rule, not a "never again" rule, and the
		// difference is a real workflow: publish, spot a typo, unpublish, fix,
		// publish. The card was retracted with the unpublish, so restoring it is
		// right - what must not happen is ending up with two.
		if ( self::card_id_for_post( (int) $post->ID ) > 0 ) {
			return;
		}

		if ( ! $this->is_shareable( $post ) ) {
			return;
		}

		if ( ! buddynext_integration_enabled( 'blog', 'feed' ) ) {
			return;
		}

		$author = (int) $post->post_author;
		if ( $author <= 0 ) {
			return;
		}

		/**
		 * Filter whether a published post posts an activity card.
		 *
		 * The escape hatch for a site that wants site tracking off, or off for
		 * SOME posts, without touching the owner-facing toggle - return false
		 * from a snippet and nothing is published. Runs last, after every other
		 * check, so a snippet always has the final say.
		 *
		 * Example - skip posts in a category:
		 *
		 *     add_filter( 'buddynext_site_tracking_publish', function ( $publish, $post ) {
		 *         return has_category( 'internal', $post ) ? false : $publish;
		 *     }, 10, 2 );
		 *
		 * @param bool     $publish Whether to publish the card. Default true.
		 * @param \WP_Post $post    The post that was just published.
		 * @param int      $author  The post author's user id.
		 */
		if ( ! (bool) apply_filters( 'buddynext_site_tracking_publish', true, $post, $author ) ) {
			return;
		}

		IntegrationActivity::publish(
			$author,
			'',
			IntegrationActivity::published_permalink( $post ),
			$this->title_for( $post ),
			self::TYPE,
			$this->excerpt_for( $post ),
			0,
			array(
				'image'            => $this->image_for( $post ),
				self::META_POST_ID => (int) $post->ID,
			)
		);
	}

	/**
	 * Retract the card when the source post is deleted outright.
	 *
	 * transition_post_status does not fire for a permanent delete from the
	 * trash, so without this a card would outlive the article it points at and
	 * link members to a 404.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public function on_delete( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && in_array( $post->post_type, $this->tracked_types(), true ) ) {
			$this->retract( $post );
		}
	}

	/**
	 * Remove the activity card for a post.
	 *
	 * @param \WP_Post $post Source post.
	 * @return void
	 */
	private function retract( \WP_Post $post ): void {
		IntegrationActivity::remove_by_meta( self::TYPE, self::META_POST_ID, (int) $post->ID );
	}

	/**
	 * May this post be surfaced to the whole community?
	 *
	 * A private post is visible to its author and editors, and a
	 * password-protected one is deliberately gated - putting either in a public
	 * feed would leak content the owner chose to restrict. Sticky/format state
	 * is irrelevant; only visibility is.
	 *
	 * @param \WP_Post $post Source post.
	 * @return bool
	 */
	private function is_shareable( \WP_Post $post ): bool {
		if ( '' !== (string) $post->post_password ) {
			return false;
		}

		return 'publish' === get_post_status( $post );
	}

	/**
	 * The card's title.
	 *
	 * @param \WP_Post $post Source post.
	 * @return string
	 */
	private function title_for( \WP_Post $post ): string {
		return (string) get_the_title( $post );
	}

	/**
	 * A short excerpt, falling back to trimmed content.
	 *
	 * Built from the post itself rather than fetched. PostService::create()
	 * fetches Open Graph metadata over HTTP whenever link_url is set and
	 * link_meta is empty - a blocking wp_remote_get on the editor's save path,
	 * which would stall publishing whenever the site could not reach itself.
	 * The post is LOCAL: everything the card needs is already in memory, so the
	 * meta is always supplied and that fetch never runs.
	 *
	 * @param \WP_Post $post Source post.
	 * @return string
	 */
	private function excerpt_for( \WP_Post $post ): string {
		$excerpt = (string) $post->post_excerpt;

		if ( '' === trim( $excerpt ) ) {
			$excerpt = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		}

		return wp_trim_words( $excerpt, 32, '…' );
	}

	/**
	 * The featured image, at a size wide enough to head a feed card.
	 *
	 * @param \WP_Post $post Source post.
	 * @return string Image URL, or '' when the post has no featured image.
	 */
	private function image_for( \WP_Post $post ): string {
		$url = get_the_post_thumbnail_url( $post, 'medium_large' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Render the article card.
	 *
	 * A dedicated card rather than the shared bridge row: WordPress is a
	 * blogging platform, a member publishing an article is a first-class piece
	 * of community content, and it deserves to look like one - a wide cover
	 * image, the headline, and a readable excerpt. The whole card is one link,
	 * so the entire surface is the target rather than a small title anchor.
	 *
	 * @param string               $html Existing HTML (empty unless another filter ran).
	 * @param array<string, mixed> $args Renderer args from templates/parts/post-body.php.
	 * @return string
	 */
	public function render_card( $html, $args ): string {
		if ( '' !== (string) $html ) {
			return (string) $html;
		}

		$link  = isset( $args['link_preview'] ) && is_array( $args['link_preview'] ) ? $args['link_preview'] : array();
		$url   = isset( $link['url'] ) ? (string) $link['url'] : '';
		$title = isset( $link['title'] ) ? (string) $link['title'] : '';
		$desc  = isset( $link['desc'] ) ? (string) $link['desc'] : '';
		$thumb = isset( $link['thumb'] ) ? (string) $link['thumb'] : '';

		if ( '' === $url ) {
			// Nothing to point at — let the default branch render the plain body.
			return '';
		}

		$out  = '<a class="bn-post-card__article" href="' . esc_url( $url ) . '">';

		if ( '' !== $thumb ) {
			$out .= '<span class="bn-post-card__article-cover">';
			$out .= '<img src="' . esc_url( $thumb ) . '" alt="" loading="lazy" decoding="async">';
			$out .= '</span>';
		}

		$out .= '<span class="bn-post-card__article-body">';
		$out .= '<span class="bn-post-card__article-kicker">';
		$out .= buddynext_get_icon( 'file-text' );
		$out .= esc_html__( 'Article', 'buddynext' );
		$out .= '</span>';

		if ( '' !== $title ) {
			$out .= '<span class="bn-post-card__article-title">' . esc_html( $title ) . '</span>';
		}
		if ( '' !== $desc ) {
			$out .= '<span class="bn-post-card__article-excerpt">' . esc_html( $desc ) . '</span>';
		}

		$domain = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' !== $domain ) {
			$out .= '<span class="bn-post-card__article-meta">' . esc_html( $domain ) . '</span>';
		}

		$out .= '</span></a>';

		return $out;
	}
}
