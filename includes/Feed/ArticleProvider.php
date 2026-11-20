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
	 * Composer summaries for this request, keyed by user id.
	 *
	 * @var array<int,array{drafts:int,articles:int,draft:array{id:int,title:string,modified_gmt:string,edit_url:string}|null}>
	 */
	private static array $summaries = array();

	/**
	 * The article provider for a member, or null when they have nowhere to write.
	 *
	 * Resolved once per request per member.
	 *
	 * @since 1.3.0
	 *
	 * @param int $user_id Member.
	 * @return array{key:string,label:string,icon:string,can_write:bool,new_url:string,drafts_url:string,my_articles_url:string,title_arg:string,supports_space:bool}|null
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
		 * `title_arg` (query arg the editor reads to prefill the title, '' for
		 * none) and `supports_space` (bool). Missing keys are filled with empty
		 * defaults. WB Member Blog registers at priority 10 when the incoming
		 * value is null; hook earlier to be offered first, later to override.
		 * With no provider, a member with `edit_posts` gets the WordPress editor.
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
				'can_write'       => true,
				'new_url'         => admin_url( 'post-new.php' ),
				'drafts_url'      => admin_url( 'edit.php?post_status=draft&author=' . $user_id ),
				'my_articles_url' => admin_url( 'edit.php?author=' . $user_id ),
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
	 * What the composer's Article tab shows a writer: how many drafts and
	 * articles they have, and their most recently edited draft to continue.
	 *
	 * `drafts` counts status 'draft' and `articles` counts 'publish' + 'private':
	 * the same statuses the provider's My drafts and My articles views list (a
	 * private post is published to a smaller audience). Posts held for review
	 * ('pending') and scheduled posts ('future') are in neither number. Counts are
	 * of `post` posts the member authored.
	 *
	 * Two indexed queries at most (one grouped COUNT on post_author, plus the
	 * latest draft only when there is one), resolved once per request. Zeros and
	 * no draft for a member who cannot write.
	 *
	 * @since 1.3.0
	 *
	 * @param int $user_id Member.
	 * @return array{drafts:int,articles:int,draft:array{id:int,title:string,modified_gmt:string,edit_url:string}|null}
	 */
	public static function composer_summary( int $user_id ): array {
		$summary  = array(
			'drafts'   => 0,
			'articles' => 0,
			'draft'    => null,
		);
		$provider = self::for_user( $user_id );
		if ( null === $provider || ! $provider['can_write'] ) {
			return $summary;
		}
		if ( isset( self::$summaries[ $user_id ] ) ) {
			return self::$summaries[ $user_id ];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one grouped COUNT per member per request (memoised above); core has no per-author status count.
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_status, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'post' AND post_status IN ( 'draft', 'publish', 'private' ) GROUP BY post_status",
				$user_id
			),
			ARRAY_A
		);
		$counts = wp_list_pluck( $rows, 'total', 'post_status' );

		$summary['drafts']   = (int) ( $counts['draft'] ?? 0 );
		$summary['articles'] = (int) ( $counts['publish'] ?? 0 ) + (int) ( $counts['private'] ?? 0 );

		if ( $summary['drafts'] > 0 ) {
			$latest = get_posts(
				array(
					'author'                 => $user_id,
					'post_type'              => 'post',
					'post_status'            => 'draft',
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( array() !== $latest && $latest[0] instanceof \WP_Post ) {
				$draft            = $latest[0];
				$summary['draft'] = array(
					'id'           => (int) $draft->ID,
					'title'        => (string) $draft->post_title,
					// A draft saved by some paths carries 0000-00-00 as its GMT time: fall back to the local time.
					'modified_gmt' => '0000-00-00 00:00:00' === (string) $draft->post_modified_gmt || '' === (string) $draft->post_modified_gmt
						? get_gmt_from_date( (string) $draft->post_modified )
						: (string) $draft->post_modified_gmt,
					'edit_url'     => self::draft_edit_url( $provider, (int) $draft->ID, $user_id ),
				);
			}
		}

		self::$summaries[ $user_id ] = $summary;
		return $summary;
	}

	/**
	 * Where a member continues one of their drafts in their provider's editor.
	 *
	 * Member Blog's editor for its provider, the WordPress editor for the
	 * fallback, and the provider's drafts list for any other provider (the
	 * contract carries no per-post edit address).
	 *
	 * @param array{key:string,drafts_url:string} $provider Normalised provider.
	 * @param int                                 $post_id  Draft.
	 * @param int                                 $user_id  Its author.
	 * @return string
	 */
	private static function draft_edit_url( array $provider, int $post_id, int $user_id ): string {
		if ( 'member-blog' === $provider['key'] ) {
			return \BuddyNext\Bridges\MemberBlogBridge::edit_url( $post_id, $user_id );
		}
		if ( 'wordpress' === $provider['key'] ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the provider slug, not the brand name.
			return (string) get_edit_post_link( $post_id, 'raw' );
		}
		return $provider['drafts_url'];
	}

	/**
	 * Fill and type every key of a provider payload.
	 *
	 * @param array<string,mixed> $provider Raw payload.
	 * @return array{key:string,label:string,icon:string,can_write:bool,new_url:string,drafts_url:string,my_articles_url:string,title_arg:string,supports_space:bool}
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
			'title_arg'       => sanitize_key( (string) ( $provider['title_arg'] ?? '' ) ),
			'supports_space'  => (bool) ( $provider['supports_space'] ?? false ),
		);
	}
}
