<?php
/**
 * The article provider: where a member writes a long-form article.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * One answer, per member, to "can this member write an article, and where".
 *
 * BuddyNext owns the article surfaces (the feed card, the profile Articles tab,
 * the composer's Article tab) but no editor. The editor is a provider, resolved
 * through the `buddynext_article_provider` filter. WB Member Blog registers itself
 * from BuddyNext's side (Bridges\MemberBlogBridge); with no provider, a member who
 * can use the WordPress editor gets it, and everyone else gets null.
 *
 * Every doorway (profile Articles tab links, the write hub, the rail entry, the
 * app's /app/config) reads this one payload, so they can never disagree about who
 * may write or where the editor is.
 */
final class ArticleProvider {

	/**
	 * Resolved payloads for this request, keyed by user id.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private static array $resolved = array();

	/**
	 * Object-cache group and key prefix of a member's article counts (composer_summary()).
	 */
	private const CACHE_GROUP = 'buddynext';
	private const CACHE_KEY   = 'bn_article_counts_';

	/**
	 * The article provider for a member, or null when they have nowhere to write.
	 *
	 * Resolved once per request per member.
	 *
	 * @since 1.3.0
	 *
	 * @param int $user_id Member.
	 * @return array{key:string,label:string,icon:string,can_write:bool,new_url:string,drafts_url:string,my_articles_url:string,pending_url:string,title_arg:string,supports_space:bool}|null
	 */
	public static function for_user( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $user_id, self::$resolved ) ) {
			return self::$resolved[ $user_id ];
		}

		/**
		 * Filters the article provider for a member: the editor the profile
		 * Articles tab, the composer's Article tab and the app send them to.
		 *
		 * Return an array with these keys, or null for "nowhere to write":
		 * `key` (string slug), `label`, `icon` (a BuddyNext icon slug),
		 * `can_write` (bool), `new_url`, `drafts_url`, `my_articles_url`,
		 * `pending_url` (the member's posts waiting for review),
		 * `title_arg` (query arg the editor reads to prefill the title, '' for
		 * none) and `supports_space` (bool). Missing keys are filled with empty
		 * defaults. WB Member Blog registers at priority 10 when the incoming
		 * value is null; hook earlier to be offered first, later to override.
		 * With no provider, a member with `edit_posts` gets the WordPress editor
		 * (`can_write` false while a BuddyNext hold, suspension or pending
		 * approval, applies).
		 *
		 * @since 1.3.0
		 *
		 * @param array<string,mixed>|null $provider Provider so far. Default null.
		 * @param int                      $user_id  Member.
		 */
		$provider = apply_filters( 'buddynext_article_provider', null, $user_id );

		if ( ! is_array( $provider ) && user_can( $user_id, 'edit_posts' ) ) {
			$provider = array(
				'key'             => 'wordpress',
				'label'           => __( 'Article', 'buddynext' ),
				'icon'            => 'file-text',
				'can_write'       => '' === \BuddyNext\Bridges\MemberBlogBridge::write_hold( $user_id ),
				'new_url'         => admin_url( 'post-new.php' ),
				'drafts_url'      => admin_url( 'edit.php?post_status=draft&author=' . $user_id ),
				'my_articles_url' => admin_url( 'edit.php?author=' . $user_id ),
				'pending_url'     => admin_url( 'edit.php?post_status=pending&author=' . $user_id ),
				'title_arg'       => 'post_title',
				'supports_space'  => false,
			);
		}

		self::$resolved[ $user_id ] = is_array( $provider ) ? self::normalise( $provider ) : null;
		return self::$resolved[ $user_id ];
	}

	/**
	 * Whether the feed composer shows the Article tab.
	 *
	 * @since 1.3.0
	 *
	 * @param int                 $user_id Member.
	 * @param array<string,mixed> $context Composer context: `surface` ('feed' for the
	 *                                     main What's New composer, 'space' in a space,
	 *                                     'profile' on a profile's Posts tab),
	 *                                     plus `space_id` in a space.
	 * @return bool
	 */
	public static function show_composer_tab( int $user_id, array $context ): bool {
		$provider = self::for_user( $user_id );
		$show     = null !== $provider
			&& $provider['can_write']
			&& buddynext_integration_enabled( 'blog', 'nav' )
			&& 'feed' === ( $context['surface'] ?? '' );

		/**
		 * Filters whether the composer shows the Article tab beside Post.
		 *
		 * Default: the member has an article provider that lets them write, the
		 * Blog posts "Show in navigation" switch is on, and the composer is the
		 * main feed's (`surface` 'feed'). Return true for `surface` 'space' or
		 * 'profile' to offer it in space or profile composers too. The tab is
		 * never drawn for a member whose provider has no `new_url`.
		 *
		 * @since 1.3.0
		 *
		 * @param bool                $show    Whether to show the tab.
		 * @param array<string,mixed> $context Composer context (`surface`, `space_id`, `user_id`).
		 */
		return (bool) apply_filters( 'buddynext_composer_article_tab', $show, array_merge( $context, array( 'user_id' => $user_id ) ) );
	}

	/**
	 * What the composer's Article tab shows a writer: how many drafts, articles
	 * and posts waiting they have (the counts beside the composer tabs). The app
	 * reads the same numbers (/app/config `posting.article_provider.counts`).
	 *
	 * `drafts` counts status 'draft' and `articles` counts 'publish' + 'private':
	 * the same statuses the provider's My drafts and My articles views list (a
	 * private post is published to a smaller audience). `pending` counts posts held
	 * for review ('pending') only, the composer's Pending link: Member Blog's Pending
	 * list shows just those, so the number always matches the list it opens
	 * (scheduled posts join the count when Member Blog lists them). Counts are of `post` posts the member authored.
	 *
	 * One grouped COUNT on post_author, cached per member in the object cache
	 * (a per-request memo without a persistent one) and dropped by flush_counts()
	 * when one of their posts changes status, author or is deleted. Zeros for a
	 * member who cannot write.
	 *
	 * @since 1.3.0
	 *
	 * @param int $user_id Member.
	 * @return array{drafts:int,articles:int,pending:int}
	 */
	public static function composer_summary( int $user_id ): array {
		$summary  = array(
			'drafts'   => 0,
			'articles' => 0,
			'pending'  => 0,
		);
		$provider = self::for_user( $user_id );
		if ( null === $provider || ! $provider['can_write'] ) {
			return $summary;
		}
		$cached = wp_cache_get( self::CACHE_KEY . $user_id, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one grouped COUNT per member, object-cached below; core has no per-author status count.
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_status, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'post' AND post_status IN ( 'draft', 'publish', 'private', 'pending' ) GROUP BY post_status",
				$user_id
			),
			ARRAY_A
		);
		$counts = wp_list_pluck( $rows, 'total', 'post_status' );

		$summary['drafts']   = (int) ( $counts['draft'] ?? 0 );
		$summary['articles'] = (int) ( $counts['publish'] ?? 0 ) + (int) ( $counts['private'] ?? 0 );
		$summary['pending']  = (int) ( $counts['pending'] ?? 0 );

		// The hour is a backstop for writes that skip the post hooks (raw SQL); flush_counts() is the real refresh.
		wp_cache_set( self::CACHE_KEY . $user_id, $summary, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $summary;
	}

	/**
	 * Keep the cached article counts fresh: a post's status, author or deletion
	 * changes its author's counts. Wired from BlogPostListener::register().
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'on_status_change' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'on_post_updated' ), 10, 3 );
		add_action( 'deleted_post', array( self::class, 'on_deleted' ), 10, 2 );
	}

	/**
	 * Drop a member's cached article counts (composer_summary()).
	 *
	 * @since 1.3.0
	 *
	 * @param int $user_id Member.
	 * @return void
	 */
	public static function flush_counts( int $user_id ): void {
		if ( $user_id > 0 ) {
			wp_cache_delete( self::CACHE_KEY . $user_id, self::CACHE_GROUP );
		}
	}

	/**
	 * `transition_post_status`: a post's status changed, so its author's counts did.
	 *
	 * @since 1.3.0
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public static function on_status_change( $new_status, $old_status, $post ): void {
		if ( $post instanceof \WP_Post && 'post' === $post->post_type && $new_status !== $old_status ) {
			self::flush_counts( (int) $post->post_author );
		}
	}

	/**
	 * `post_updated`: a post moved to another author, so both members' counts changed.
	 *
	 * @since 1.3.0
	 *
	 * @param int      $post_id     Post id.
	 * @param \WP_Post $post_after  The post after the update.
	 * @param \WP_Post $post_before The post before it.
	 * @return void
	 */
	public static function on_post_updated( $post_id, $post_after, $post_before ): void {
		if ( $post_after instanceof \WP_Post && $post_before instanceof \WP_Post && 'post' === $post_after->post_type
			&& (int) $post_after->post_author !== (int) $post_before->post_author ) {
			self::flush_counts( (int) $post_after->post_author );
			self::flush_counts( (int) $post_before->post_author );
		}
	}

	/**
	 * `deleted_post`: a deleted post leaves its author's counts.
	 *
	 * @since 1.3.0
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    The deleted post.
	 * @return void
	 */
	public static function on_deleted( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && 'post' === $post->post_type ) {
			self::flush_counts( (int) $post->post_author );
		}
	}

	/**
	 * Fill and type every key of a provider payload.
	 *
	 * @param array<string,mixed> $provider Raw payload.
	 * @return array{key:string,label:string,icon:string,can_write:bool,new_url:string,drafts_url:string,my_articles_url:string,pending_url:string,title_arg:string,supports_space:bool}
	 */
	private static function normalise( array $provider ): array {
		return array(
			'key'             => sanitize_key( (string) ( $provider['key'] ?? '' ) ),
			'label'           => (string) ( $provider['label'] ?? __( 'Article', 'buddynext' ) ),
			'icon'            => sanitize_key( (string) ( $provider['icon'] ?? 'file-text' ) ),
			'can_write'       => (bool) ( $provider['can_write'] ?? false ),
			'new_url'         => esc_url_raw( (string) ( $provider['new_url'] ?? '' ) ),
			'drafts_url'      => esc_url_raw( (string) ( $provider['drafts_url'] ?? '' ) ),
			'my_articles_url' => esc_url_raw( (string) ( $provider['my_articles_url'] ?? '' ) ),
			'pending_url'     => esc_url_raw( (string) ( $provider['pending_url'] ?? '' ) ),
			'title_arg'       => sanitize_key( (string) ( $provider['title_arg'] ?? '' ) ),
			'supports_space'  => (bool) ( $provider['supports_space'] ?? false ),
		);
	}
}
