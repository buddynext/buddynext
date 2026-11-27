<?php
/**
 * Shared feed-activity helper for integrations.
 *
 * One consistent way for any integration bridge (core or Pro) to publish a
 * "member created partner content" activity to the BuddyNext feed (engagement),
 * and to remove it when the content goes away. Goes through PostService — no raw
 * SQL, one link-card style for every integration, idempotent per partner page.
 *
 * Lives in Free so both Free core bridges (e.g. Jetonomy) and Pro bridges
 * (e.g. Career Board) use the same helper without duplicating the logic.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

/**
 * Publish / remove integration engagement activities.
 */
class IntegrationActivity {

	/**
	 * Whether a bridge-driven (system) publish is currently in flight.
	 *
	 * Read by SpacePostGuard to skip the per-space `who_can_post` gate. That gate
	 * governs a MEMBER composing into a space; an integration activity is a mirror
	 * of content the partner plugin already created and already authorized, whose
	 * author frequently is not a member of the linked BuddyNext space at all.
	 *
	 * Deliberately a system flag rather than a key in the post data array: a data
	 * key could be smuggled in through a create path and would turn the skip into
	 * a who_can_post bypass. Nothing a request can supply reaches this.
	 *
	 * @var bool
	 */
	private static bool $system_publish = false;

	/**
	 * Whether the current save is a bridge-driven integration publish.
	 *
	 * @return bool
	 */
	public static function is_system_publish(): bool {
		return self::$system_publish;
	}

	/**
	 * True while a bridge is re-indexing its content for search only.
	 *
	 * @var bool
	 */
	private static bool $search_only = false;

	/**
	 * Run a bridge's own sync with new activity cards switched off.
	 *
	 * A bridge has one sync per item, and it both indexes the item for search and
	 * gives it a feed card when it has none. Re-indexing a whole integration
	 * through that sync must not publish a card for every old item it meets: the
	 * feed would fill with years of forum topics dated today. Existing cards are
	 * still refreshed and restored as usual; only the creation of a new one is
	 * skipped.
	 *
	 * @param callable $run The bridge sync to run.
	 * @return mixed Whatever $run returns.
	 */
	public static function search_only( callable $run ) {
		$before            = self::$search_only;
		self::$search_only = true;
		try {
			return $run();
		} finally {
			self::$search_only = $before;
		}
	}

	/**
	 * How many bridge mirror writes are in flight (nesting-safe).
	 *
	 * @var int
	 */
	private static int $mirror_depth = 0;

	/**
	 * Whether the current write copies an action a partner plugin already
	 * recorded (a MediaVerse follow or lightbox comment, a Jetonomy reply, or
	 * the reverse). Listeners that reward or count actions check this so one
	 * member action is never paid twice, once per plugin.
	 *
	 * @return bool
	 */
	public static function is_mirror(): bool {
		return self::$mirror_depth > 0;
	}

	/**
	 * Run a bridge mirror write with is_mirror() true for its duration.
	 *
	 * @param callable $write The cross-plugin write.
	 * @return mixed Whatever $write returns.
	 */
	public static function as_mirror( callable $write ) {
		++self::$mirror_depth;
		try {
			return $write();
		} finally {
			--self::$mirror_depth;
		}
	}

	/**
	 * Publish a link-card activity for content a member just created.
	 *
	 * Rendered as a standard feed link card pointing at the partner's own page
	 * (we link OUT — we never embed the partner UI). `link_meta` is supplied so
	 * PostService does not make an OG-fetch HTTP call to the partner.
	 *
	 * @param int         $member_id  The member who created the content.
	 * @param string      $content    Feed text, e.g. "started a discussion".
	 * @param string      $link_url   The partner page the card links to.
	 * @param string      $link_title Title shown on the card (the content's title).
	 * @param string      $type       Post type to record. Defaults to 'link'. Pass a
	 *                                specific type (e.g. 'discussion', 'job')
	 *                                so discovery surfaces can classify + filter the
	 *                                card by what it represents instead of a generic
	 *                                link. Must be a PostService::ALLOWED_TYPES value.
	 * @param string      $excerpt    Optional short excerpt of the partner content,
	 *                                stored in link_meta['description'] so the card can
	 *                                show a title + preview instead of just a verb.
	 * @param int         $space_id   The BuddyNext space the partner content belongs to,
	 *                                when it belongs to one. This is what makes the
	 *                                per-space "Share activity to the main feed" toggle
	 *                                real: FeedService excludes an opted-out space by
	 *                                `space_id`, and its clause deliberately lets a NULL
	 *                                space through, so a card published without one can
	 *                                never be suppressed — and never appears in the
	 *                                space's own feed either. Pass 0 for content that
	 *                                genuinely has no space (a badge, a resume).
	 * @param array       $meta       Optional extra fields merged into `link_meta`, so a
	 *                                typed card (event, course, …) can carry its structured
	 *                                payload (cover, start time, location, source id) for a
	 *                                `buddynext_render_post_body_{type}` renderer and the
	 *                                REST/app surfaces. Overrides the defaults it shares a
	 *                                key with (e.g. pass 'image' to set the card cover).
	 * @param string|null $created_at UTC time of the card (see Core\Backdate). Null means now.
	 * @return int|\WP_Error Post id (0 when an identical card already exists), or WP_Error.
	 *
	 * @api
	 */
	public static function publish( int $member_id, string $content, string $link_url, string $link_title = '', string $type = 'link', string $excerpt = '', int $space_id = 0, array $meta = array(), ?string $created_at = null ) {
		if ( $member_id <= 0 || '' === $link_url ) {
			return new \WP_Error( 'invalid_activity', 'member id and link url are required' );
		}

		// A search-only re-index never creates a card (see search_only()).
		if ( self::$search_only ) {
			return 0;
		}

		$type    = '' !== $type ? $type : 'link';
		$service = new PostService();

		// This member's card from before 1.1.0, stored as a plain `link`, becomes
		// this card instead of a duplicate (see PostService::adopt_legacy_link_cards()).
		$service->adopt_legacy_link_cards( $type, $link_url, $member_id );

		// Idempotent: one activity card per partner page, even if the partner
		// hook fires more than once. Match on the same type the card is stored as.
		if ( $service->exists_by_link( $type, $link_url ) ) {
			return 0;
		}

		self::$system_publish = true;

		try {
			return $service->create(
				$member_id,
				array(
					'type'       => $type,
					'content'    => $content,
					'space_id'   => $space_id > 0 ? $space_id : null,
					// A card with no space links OUT to a public partner page (a public
					// Jetonomy discussion, a job posting, etc.) and is inherently public.
					// A card scoped to a SPACE must inherit the space's privacy instead
					// of being stamped 'public' - a bare 'public' on a private/secret
					// space's card leaked it onto Explore and profiles (card 10312981296).
					// 'space_members' lets the feed's space-readability guard decide:
					// open space -> everyone, private/secret -> members only.
					'privacy'    => $space_id > 0 ? 'space_members' : 'public',
					'link_url'   => $link_url,
					'link_meta'  => self::card_meta( $link_url, $link_title, $excerpt, $meta ),
					'created_at' => $created_at,
				)
			);
		} finally {
			self::$system_publish = false;
		}
	}

	/**
	 * Reconcile the two names this payload has had for its cover image.
	 *
	 * A silent key mismatch, and it cost every bridge its cover art: this class
	 * has always written `image`, while the thing that RENDERS the card reads
	 * `thumbnail` (templates/partials/post-card.php, both the inline preview and
	 * the Explore cover) because that is the key PostService::fetch_og_meta()
	 * produces for an ordinary shared link. So an integration could set a
	 * perfectly good image and the card would render without one, with nothing
	 * anywhere reporting a problem.
	 *
	 * Both keys are kept in sync rather than one being renamed, because cards
	 * already written carry `image` and anything reading either name has to keep
	 * working. Whichever a caller supplies, both come out populated.
	 *
	 * @param array<string, mixed> $meta Link meta being written.
	 * @return array<string, mixed>
	 */
	private static function normalise_link_meta( array $meta ): array {
		$image     = isset( $meta['image'] ) ? (string) $meta['image'] : '';
		$thumbnail = isset( $meta['thumbnail'] ) ? (string) $meta['thumbnail'] : '';
		$resolved  = '' !== $thumbnail ? $thumbnail : $image;

		$meta['image']     = $resolved;
		$meta['thumbnail'] = $resolved;

		return $meta;
	}

	/**
	 * The permalink a post HAS, or HAD while it was published.
	 *
	 * Forces `publish` status on a probe copy and strips WordPress's `__trashed` slug
	 * suffix, so the URL is identical to the one stored on the activity card when the
	 * content first went public — whatever the post's current (draft / trashed) state.
	 *
	 * That identity is the whole point: remove() matches an activity card by its
	 * link_url, so a bridge tearing down the card for a trashed post MUST be able to
	 * reconstruct the URL exactly as it was written. Ask WordPress for the permalink of
	 * a trashed post and you get the `__trashed` slug, which matches nothing, and the
	 * activity card is orphaned in the feed forever.
	 *
	 * Lives here because this is the class every bridge already goes through to publish
	 * and remove a card — the URL rule belongs next to the thing it is a key for. It was
	 * previously copy-pasted, byte for byte, into two Pro bridges.
	 *
	 * @param \WP_Post $post The partner post (listing, job, …).
	 * @return string
	 */
	public static function published_permalink( \WP_Post $post ): string {
		if ( 'publish' === $post->post_status ) {
			return (string) get_permalink( $post );
		}

		$probe              = clone $post;
		$probe->post_status = 'publish';
		$probe->post_name   = (string) preg_replace( '/__trashed$/', '', (string) $probe->post_name );

		return (string) get_permalink( $probe );
	}

	/**
	 * Remove the activity card for a partner page (e.g. when the content is deleted).
	 *
	 * With a real type, also removes the same page's card from before 1.1.0, when
	 * integrations stored their cards as a plain `link` (see
	 * PostService::adopt_legacy_link_cards()). publish(), withdraw(), restore(),
	 * refresh() and rewrite() adopt that card the same way; signatures unchanged.
	 *
	 * @param string $link_url The partner page the card linked to.
	 * @param string $type     Post type the card was stored as. Defaults to 'link';
	 *                         pass the same type used at publish() time.
	 * @return int Rows removed.
	 *
	 * @api
	 */
	public static function remove( string $link_url, string $type = 'link' ): int {
		if ( '' === $link_url ) {
			return 0;
		}
		$type    = '' !== $type ? $type : 'link';
		$service = new PostService();
		$service->adopt_legacy_link_cards( $type, $link_url );
		return $service->delete_by_link( $type, $link_url );
	}

	/**
	 * Withdraw a card whose partner entity was UNPUBLISHED, not destroyed.
	 *
	 * The reversible counterpart to remove(): a discussion set back to draft, a
	 * media item trashed. Instead of deleting the row — which drops the card's id,
	 * date, reactions and every comment members left on it, then forces a brand-new
	 * card (dated now) if the entity is republished — this flips the card to 'draft',
	 * which is hidden from every feed but preserved for restore(). Only a currently
	 * 'published' card is withdrawn, so a moderator's under_review hold is never
	 * disturbed. Idempotent: a second withdraw is a no-op.
	 *
	 * @param string $link_url The partner page the card links to.
	 * @param string $type     Post type the card was stored as (same as at publish()).
	 * @return bool True when a live card was withdrawn.
	 *
	 * @api
	 */
	public static function withdraw( string $link_url, string $type = 'link' ): bool {
		if ( '' === $link_url ) {
			return false;
		}
		$type    = '' !== $type ? $type : 'link';
		$service = new PostService();
		$service->adopt_legacy_link_cards( $type, $link_url );
		return $service->transition_link_status( $type, $link_url, 'published', 'draft' ) > 0;
	}

	/**
	 * Bring back a card withdrawn by withdraw() when its partner entity is republished.
	 *
	 * Flips a 'draft' card back to 'published' IN PLACE — same id, same original date,
	 * same reactions and comments — so a public → draft → public round trip no longer
	 * resurfaces the thread as new or orphans its replies. Only a 'draft' card is
	 * restored, so a card a moderator moved to under_review stays hidden. Returns
	 * false when no withdrawn card matched (the caller then publishes a fresh one).
	 *
	 * @param string $link_url The partner page the card links to.
	 * @param string $type     Post type the card was stored as.
	 * @return bool True when a withdrawn card was restored.
	 *
	 * @api
	 */
	public static function restore( string $link_url, string $type = 'link' ): bool {
		if ( '' === $link_url ) {
			return false;
		}
		$type    = '' !== $type ? $type : 'link';
		$service = new PostService();
		$service->adopt_legacy_link_cards( $type, $link_url );
		return $service->transition_link_status( $type, $link_url, 'draft', 'published' ) > 0;
	}

	/**
	 * Re-write the stored snapshot on a card that already exists.
	 *
	 * The companion publish() needs — and the reason a bridge cannot simply "publish
	 * again" to correct a card. publish() is idempotent by link_url and returns 0 the
	 * moment a card exists, by design: a partner hook that fires twice must not post
	 * to the feed twice. But that also froze the snapshot forever, so any bridge bug
	 * that wrote a wrong or empty payload stayed wrong on every card already out
	 * there, on real member feeds, with the fix helping only cards published after it.
	 *
	 * Call it right after a publish() that returned 0 and the card self-heals the next
	 * time the partner saves the entity — the bridge already re-asserts on every
	 * update, so no new hook and no migration is needed.
	 *
	 * The payload is MERGED over what the card already carries, never substituted for
	 * it. A bridge's snapshot builder knows its own typed fields and nothing else —
	 * publish() is what stamped `title` and `description` on the card, from arguments
	 * a refresh caller does not have — so a wholesale replace would repair the date
	 * and silently strip the headline off the same card.
	 *
	 * @param string               $link_url The partner page the card links to.
	 * @param string               $type     Post type the card was stored as.
	 * @param array<string, mixed> $meta     Fields to update; others are preserved.
	 * @return bool True when a card was refreshed.
	 *
	 * @api
	 */
	public static function refresh( string $link_url, string $type, array $meta ): bool {
		if ( '' === $link_url || '' === $type || array() === $meta ) {
			return false;
		}

		$service = new PostService();
		$service->adopt_legacy_link_cards( $type, $link_url );

		$stored = $service->get_link_meta( $type, $link_url );
		// A caller that names the picture owns it: normalise_link_meta() prefers `thumbnail`, so the stored one would
		// otherwise win over a replaced picture, and an empty value (the picture was removed) could never clear it.
		if ( array_key_exists( 'image', $meta ) || array_key_exists( 'thumbnail', $meta ) ) {
			unset( $stored['image'], $stored['thumbnail'] );
		}

		return $service->update_link_meta(
			$type,
			$link_url,
			self::normalise_link_meta( array_merge( $stored, $meta ) )
		);
	}

	/**
	 * Refresh the snapshot of EVERY card of a partner id, e.g. all of an event's
	 * cards (the organiser's and each attendee's) by their shared event_id.
	 *
	 * Where refresh() reaches one card by its link, a fact the partner object owns (an
	 * event's date, cover, title) is copied onto every card about it, so when it
	 * changes all of them have to follow. The fields given are merged over what
	 * each card already carries; anything not named (each card's own `relation`,
	 * its link) is left alone.
	 *
	 * @since 1.2.5
	 *
	 * @param string               $type     Card post type (e.g. 'event').
	 * @param string               $meta_key link_meta field the id was stored under.
	 * @param int                  $value    The partner id whose cards to refresh.
	 * @param array<string, mixed> $meta     Fields to update on each card.
	 * @param array<string, mixed> $opts     Optional. `user_id` only that member's cards; `published_only` leave withdrawn
	 *                                       cards alone; `link_url` + `link_arg` point every card whose link carries
	 *                                       `?link_arg=` at the new address, keeping its marker (a per-member card whose
	 *                                       partner address changed); `batch` (+ `after_id`) handle at most that many cards
	 *                                       and set `$next` to carry on from when the batch was full.
	 * @param int|null             $next     Out: last card id of a full batch, else null.
	 * @return int Cards refreshed.
	 *
	 * @api
	 */
	public static function refresh_by_meta( string $type, string $meta_key, int $value, array $meta, array $opts = array(), ?int &$next = null ): int {
		$next = null;
		if ( '' === $type || '' === $meta_key || $value <= 0 || array() === $meta ) {
			return 0;
		}
		// Keep `image` and `thumbnail` in step, but only when the caller sent one:
		// normalising a patch without either would blank both on every card.
		if ( isset( $meta['image'] ) || isset( $meta['thumbnail'] ) ) {
			$meta = self::normalise_link_meta( $meta );
		}

		return ( new PostService() )->merge_link_meta_cards( $type, $meta_key, $value, $meta, $opts, $next );
	}

	/**
	 * Remove EVERY card of a type that a bridge stamped with a partner id in its
	 * link_meta — e.g. all of an event's cards (the organizer card + each
	 * attendee's) by their shared event_id.
	 *
	 * Use this when a partner entity is deleted and the per-card URLs can't be
	 * reconstructed (the source rows are already gone) or a URL match would be
	 * unsafe: the id the bridge stored at publish time is the stable, exact key.
	 *
	 * @param string $type     Card post type (e.g. 'event').
	 * @param string $meta_key link_meta field the id was stored under (e.g. 'event_id').
	 * @param int    $value    The partner id whose cards to remove.
	 * @return int Rows removed.
	 *
	 * @api
	 */
	public static function remove_by_meta( string $type, string $meta_key, int $value ): int {
		if ( '' === $type || '' === $meta_key || $value <= 0 ) {
			return 0;
		}
		return ( new PostService() )->delete_by_link_meta_int( $type, $meta_key, $value );
	}

	/**
	 * Delete the cards of SEVERAL partner ids in one lookup (the batch counterpart of remove_by_meta).
	 *
	 * For a bulk delete on the partner's side, such as a forum Space purge removing thousands of topics: one query finds
	 * every card of the ids, where calling remove_by_meta() once per id scans the type's cards once per id.
	 *
	 * @since 1.3.0
	 *
	 * @param string $type     Card post type (e.g. 'discussion').
	 * @param string $meta_key link_meta field the id was stored under (e.g. 'post_id').
	 * @param int[]  $values   The partner ids whose cards to remove.
	 * @return int Rows removed.
	 *
	 * @api
	 */
	public static function remove_by_meta_many( string $type, string $meta_key, array $values ): int {
		if ( '' === $type || '' === $meta_key || array() === array_filter( array_map( 'absint', $values ) ) ) {
			return 0;
		}
		return ( new PostService() )->delete_by_link_meta_int( $type, $meta_key, $values );
	}

	/**
	 * Whether a card of a type already carries this link_meta value, live or withdrawn.
	 *
	 * A bridge asks it to keep one card for a group of items, such as the dates of a repeating event.
	 *
	 * @param string $type     Card post type (e.g. 'event').
	 * @param string $meta_key link_meta field (e.g. 'series_id').
	 * @param int    $value    The value to look for.
	 * @return bool
	 *
	 * @api
	 */
	public static function has_card_by_meta( string $type, string $meta_key, int $value ): bool {
		return ( new PostService() )->has_link_meta_card( $type, $meta_key, $value );
	}

	/**
	 * Withdraw EVERY card a partner entity stamped with an id (the reversible
	 * counterpart of remove_by_meta): flip each from 'published' to 'draft', hidden
	 * from every feed but preserved, so a later restore_by_meta() brings the whole set
	 * back. For an entity whose per-item cards are keyed by a shared id — an event's
	 * organizer card plus each attendee's — withdrawn together when the event is
	 * cancelled and restored together when it is reinstated.
	 *
	 * @param string $type     Card post type (e.g. 'event').
	 * @param string $meta_key link_meta field the id was stored under (e.g. 'event_id').
	 * @param int    $value    The partner id whose cards to withdraw.
	 * @return int Rows withdrawn.
	 *
	 * @api
	 */
	public static function withdraw_by_meta( string $type, string $meta_key, int $value ): int {
		if ( '' === $type || '' === $meta_key || $value <= 0 ) {
			return 0;
		}
		return ( new PostService() )->transition_link_meta_status( $type, $meta_key, $value, 'published', 'draft' );
	}

	/**
	 * Bring back every card withdraw_by_meta() withdrew for a partner id — flip each
	 * 'draft' card back to 'published', same ids/dates/comments. Cards a moderator
	 * moved to under_review are left alone.
	 *
	 * @param string $type     Card post type (e.g. 'event').
	 * @param string $meta_key link_meta field the id was stored under (e.g. 'event_id').
	 * @param int    $value    The partner id whose cards to restore.
	 * @return int Rows restored.
	 *
	 * @api
	 */
	public static function restore_by_meta( string $type, string $meta_key, int $value ): int {
		if ( '' === $type || '' === $meta_key || $value <= 0 ) {
			return 0;
		}
		return ( new PostService() )->transition_link_meta_status( $type, $meta_key, $value, 'draft', 'published' );
	}

	/**
	 * Turn a partner id's card into a different card, in place.
	 *
	 * For a card whose meaning changes but whose moment still stands: a course
	 * certificate that expires is no longer worth advertising, but the member did
	 * finish the course. The card keeps its id, author, date, status, space,
	 * reactions and comments, so it never resurfaces in a feed; only its text,
	 * link and snapshot change, built exactly as publish() builds them.
	 *
	 * A card is left alone when another card of the type already owns the new
	 * link (publish() keeps one card per link), so the caller can fall back to
	 * withdraw_by_meta().
	 *
	 * @since 1.2.4
	 *
	 * @param string               $type       Card post type (e.g. 'course').
	 * @param string               $meta_key   link_meta field the id was stored under.
	 * @param int                  $value      The partner id whose cards to rewrite.
	 * @param string               $content    New card text.
	 * @param string               $link_url   New link.
	 * @param string               $link_title New link title.
	 * @param string               $excerpt    New preview line.
	 * @param array<string, mixed> $meta       Extra snapshot fields, as for publish().
	 * @return int 1 when the card was rewritten, else 0.
	 *
	 * @api
	 */
	public static function rewrite_by_meta( string $type, string $meta_key, int $value, string $content, string $link_url, string $link_title = '', string $excerpt = '', array $meta = array() ): int {
		if ( '' === $type || '' === $meta_key || $value <= 0 || '' === $link_url ) {
			return 0;
		}
		return ( new PostService() )->rewrite_link_meta_card( $type, $meta_key, $value, $content, $link_url, self::card_meta( $link_url, $link_title, $excerpt, $meta ) );
	}

	/**
	 * Turn the card that has a given link into a different card, in place.
	 *
	 * As rewrite_by_meta(), for a card found by its link: use it when the card's
	 * snapshot holds no id of its own (a course completion card carries only the
	 * course id, shared by every learner; its link is per learner). A later
	 * certificate upgrades that card rather than adding a second post.
	 *
	 * @since 1.2.4
	 *
	 * @param string               $old_link_url Link the card has now.
	 * @param string               $type         Card post type (e.g. 'course').
	 * @param string               $content      New card text.
	 * @param string               $link_url     New link.
	 * @param string               $link_title   New link title.
	 * @param string               $excerpt      New preview line.
	 * @param array<string, mixed> $meta         Extra snapshot fields, as for publish().
	 * @return int 1 when the card was rewritten, else 0.
	 *
	 * @api
	 */
	public static function rewrite( string $old_link_url, string $type, string $content, string $link_url, string $link_title = '', string $excerpt = '', array $meta = array() ): int {
		if ( '' === $old_link_url || '' === $type || '' === $link_url ) {
			return 0;
		}
		$service = new PostService();
		$service->adopt_legacy_link_cards( $type, $old_link_url );
		return $service->rewrite_link_card( $type, $old_link_url, $content, $link_url, self::card_meta( $link_url, $link_title, $excerpt, $meta ) );
	}

	/**
	 * The link_meta snapshot an integration card is stored with.
	 *
	 * @param string               $link_url   Link the card points at.
	 * @param string               $link_title Link title.
	 * @param string               $excerpt    Preview line.
	 * @param array<string, mixed> $meta       Extra fields (ids, badges, image).
	 * @return array<string, mixed>
	 */
	private static function card_meta( string $link_url, string $link_title, string $excerpt, array $meta ): array {
		return self::normalise_link_meta(
			array_merge(
				array(
					'title'       => $link_title,
					'description' => $excerpt,
					'image'       => '',
					'url'         => $link_url,
				),
				$meta
			)
		);
	}

	/**
	 * Move every card of a partner id into the partner's current space.
	 *
	 * For a partner object that can change home (an event moved to another
	 * space): the cards keep their ids, dates and comments, and take the new
	 * space and the privacy publish() gives a card there (a space card follows
	 * the space's audience; a card with no space is public). Call it on every
	 * partner save; cards already in that space are untouched.
	 *
	 * @param string $type     Card post type (e.g. 'event').
	 * @param string $meta_key link_meta field the id was stored under (e.g. 'event_id').
	 * @param int    $value    The partner id whose cards to move.
	 * @param int    $space_id BuddyNext space id, or 0 for none.
	 * @return int Cards moved.
	 *
	 * @api
	 */
	public static function set_space_by_meta( string $type, string $meta_key, int $value, int $space_id ): int {
		if ( '' === $type || '' === $meta_key || $value <= 0 || $space_id < 0 ) {
			return 0;
		}
		return ( new PostService() )->move_link_meta_space( $type, $meta_key, $value, $space_id );
	}

	/**
	 * Put a partner item's card(s) into another Space, found by the link the card points at.
	 *
	 * For items a partner identifies by URL only (a Listora listing); see set_space_by_meta() for the id form.
	 *
	 * @since 1.3.0
	 *
	 * @param string $type     Card post type (e.g. 'listing').
	 * @param string $link_url The card's link.
	 * @param int    $space_id Target Space id, or 0 for site-wide.
	 * @return int Cards moved.
	 */
	public static function set_space_by_link( string $type, string $link_url, int $space_id ): int {
		if ( '' === $type || '' === $link_url || $space_id < 0 ) {
			return 0;
		}
		return ( new PostService() )->move_link_url_space( $type, $link_url, $space_id );
	}

	/**
	 * Run a bridge's re-placing now for a small set of items, or in background chunks for a large one.
	 *
	 * A Space with thousands of partner items must not re-place them inside the owner's save: up to
	 * $inline_max items run at once (the normal case, so the result is immediate); more are queued on
	 * Action Scheduler in chunks, each running the bridge's own handler on the given hook. Without
	 * Action Scheduler everything runs inline.
	 *
	 * @since 1.3.0
	 *
	 * @param string                $hook       Action the bridge listens on; it receives one chunk (array).
	 * @param array<int,int|string> $items      Item ids (or links) to re-place.
	 * @param callable              $now        Runs one item inline.
	 * @param int                   $inline_max Most items run inline.
	 * @param int                   $chunk      Items per queued chunk.
	 * @param string                $group      Action Scheduler group (an add-on passes its own).
	 * @return void
	 */
	public static function run_in_chunks( string $hook, array $items, callable $now, int $inline_max = 25, int $chunk = 100, string $group = \BuddyNext\Core\CronScheduler::GROUP ): void {
		$items = array_values( array_unique( array_filter( $items ) ) );
		if ( count( $items ) <= $inline_max || ! function_exists( 'as_enqueue_async_action' ) ) {
			array_map( $now, $items );
			return;
		}
		// Action Scheduler stores a job's arguments in a 191-character column, so a chunk is cut by its encoded
		// length as well as its count: a hundred ids or card links would otherwise be refused outright. An item
		// too long to queue alone runs now.
		$part = array();
		$size = 2;
		foreach ( $items as $item ) {
			$cost = strlen( (string) wp_json_encode( $item ) ) + 1;
			if ( $cost + 4 > self::JOB_ARGS_MAX ) {
				$now( $item );
				continue;
			}
			if ( array() !== $part && ( count( $part ) >= max( 1, $chunk ) || $size + $cost + 4 > self::JOB_ARGS_MAX ) ) {
				as_enqueue_async_action( $hook, array( $part ), $group );
				$part = array();
				$size = 2;
			}
			$part[] = $item;
			$size  += $cost;
		}
		if ( array() !== $part ) {
			as_enqueue_async_action( $hook, array( $part ), $group );
		}
	}

	/**
	 * The cards of a type sitting in a Space (link and link_meta), for re-placing them.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $space_id Space id.
	 * @param string $type     Card post type.
	 * @return array<int,array{link_url:string,link_meta:array<string,mixed>}>
	 */
	public static function cards_in_space( int $space_id, string $type ): array {
		return ( new PostService() )->cards_in_space( $space_id, $type );
	}

	/**
	 * Every typed integration card, keyed by post type: the source label and icon
	 * the feed card and the Explore card both show. One list, so a bridge's card
	 * reads the same on the feed and on Explore, and Explore can never fall back
	 * to a bare "Post" teaser for an integration it was not told about.
	 *
	 * A bridge registers its type here in the same change that publishes it.
	 *
	 * @return array<string,array{label:string,icon:string}>
	 *
	 * @api
	 */
	public static function card_types(): array {
		/**
		 * Filter the typed integration cards.
		 *
		 * @since 1.2.6
		 *
		 * @param array<string,array{label:string,icon:string}> $types Post type => { label, icon }.
		 */
		$types = (array) apply_filters( 'buddynext_integration_card_types', array() );

		$out = array();
		foreach ( $types as $type => $card ) {
			if ( is_string( $type ) && '' !== $type && is_array( $card ) && ! empty( $card['label'] ) ) {
				$out[ $type ] = array(
					'label' => (string) $card['label'],
					'icon'  => (string) ( $card['icon'] ?? 'link' ),
				);
			}
		}
		return $out;
	}

	/**
	 * The stored title a card may keep once its partner is gone.
	 *
	 * Media and document titles are decided per viewer at render by the partner, so
	 * with it gone there is nobody left to ask and they stay hidden. Discussions keep
	 * the neutral line they show for a deleted topic.
	 *
	 * @since 1.2.8
	 *
	 * @param string $type  Post type.
	 * @param string $title Stored card title.
	 * @return string The title, or '' when it must not be shown.
	 *
	 * @api
	 */
	public static function orphaned_card_title( string $type, string $title ): string {
		return in_array( $type, array( 'media', 'document', 'discussion' ), true ) ? '' : $title;
	}

	/**
	 * Render the neutral card for a post whose partner plugin is not active: the
	 * title it may keep and a plain line, with no link and no system sentence.
	 *
	 * @since 1.2.8
	 *
	 * @param array<string,mixed> $args Post-body args ({ link_preview, bn_post_type }).
	 * @return string Pre-escaped card HTML.
	 *
	 * @api
	 */
	public static function render_orphaned_card( array $args ): string {
		$type  = isset( $args['bn_post_type'] ) ? (string) $args['bn_post_type'] : '';
		$link  = isset( $args['link_preview'] ) && is_array( $args['link_preview'] ) ? $args['link_preview'] : array();
		$title = self::orphaned_card_title( $type, trim( (string) ( $link['title'] ?? '' ) ) );

		$html  = '<div class="bn-post-card__bridge-card bn-post-card__bridge-card--orphaned is-unavailable">';
		$html .= '<span class="bn-post-card__bridge-icon" aria-hidden="true">' . buddynext_get_icon( 'info' ) . '</span>';
		$html .= '<div class="bn-post-card__bridge-content">';
		if ( '' !== $title ) {
			$html .= '<span class="bn-post-card__bridge-title">' . esc_html( $title ) . '</span>';
		}
		$html .= '<p class="bn-post-card__bridge-text">' . esc_html__( 'This is no longer available.', 'buddynext' ) . '</p>';
		return $html . '</div></div>';
	}

	/**
	 * The member's own words for what they did ("posted a new job", "completed a
	 * course"), for the post header: "Alice added a new listing".
	 *
	 * Every bridge stores this as the post content. The header is where a feed
	 * names who did what (Facebook, LinkedIn), so the card body shows only the
	 * item itself. One helper for the feed card, the Explore card and the
	 * Community Activity block, so a bridge never needs its own.
	 *
	 * @param string $type    Post type.
	 * @param string $content Stored post content (the verb).
	 * @param string $title   The card's own title, when it has one.
	 * @return string Plain text, '' when the post is not an integration card or the
	 *                verb is only the title said again.
	 *
	 * @api
	 */
	public static function action_line( string $type, string $content, string $title = '' ): string {
		if ( ! isset( self::card_types()[ $type ] ) ) {
			return '';
		}
		$verb = trim( wp_strip_all_tags( $content ) );
		return ( '' !== $verb && trim( $title ) !== $verb ) ? $verb : '';
	}

	/**
	 * Render the uniform integration feed card for a typed bridge post.
	 *
	 * The single card style every bridge shares: an icon + a source label + the
	 * linked content title + an optional preview line — the same
	 * `.bn-post-card__bridge-card` markup the inline discussion card uses, so a
	 * job, listing, course, or badge card looks identical across integrations.
	 * Bridges call this from their `buddynext_render_post_body_{type}` renderer so
	 * the card links OUT to the partner page (never the plain-text fallback, which
	 * would drop the link). Returns pre-escaped HTML for the seam to echo.
	 *
	 * @param array<string,mixed> $args  Post-body args ({ link_preview, post_content, bn_post_type }).
	 * @param string              $icon  Lucide icon slug; '' = the type's registered icon.
	 * @param string              $label Source label shown above the title; '' = the type's registered label.
	 * @return string Card HTML, or '' when there is no link to render (text fallback).
	 *
	 * @api
	 */
	public static function render_bridge_card( array $args, string $icon = '', string $label = '' ): string {
		$link  = isset( $args['link_preview'] ) && is_array( $args['link_preview'] ) ? $args['link_preview'] : array();
		$url   = isset( $link['url'] ) ? (string) $link['url'] : '';
		$title = isset( $link['title'] ) ? (string) $link['title'] : '';
		$desc  = isset( $link['desc'] ) ? (string) $link['desc'] : '';
		$type  = isset( $args['bn_post_type'] ) ? (string) $args['bn_post_type'] : 'link';

		$registered = self::card_types()[ $type ] ?? null;
		if ( '' === $icon ) {
			$icon = $registered['icon'] ?? 'link';
		}
		if ( '' === $label ) {
			$label = $registered['label'] ?? '';
		}

		// No link to point at → let the caller fall back to the plain-text body.
		if ( '' === $url ) {
			return '';
		}
		// The linked line is the content's own title; fall back to a trimmed verb.
		if ( '' === $title ) {
			$title = wp_trim_words( wp_strip_all_tags( (string) ( $args['post_content'] ?? '' ) ), 14 );
		}

		// Cover art, when the partner has one. Listings, courses and jobs often do
		// and just as often do not, so this is strictly optional: no image means no
		// element at all, and the card keeps the compact single-column shape it has
		// today rather than reserving an empty box.
		// `thumb` is the key post-card.php already puts in link_preview (it reads
		// link_meta['thumbnail']); `image` is accepted too because normalise_link_meta()
		// writes BOTH names and a caller building this array by hand will reach for
		// the obvious one. Reading only one of the two is how the covers went
		// missing the first time.
		$image = (string) ( $link['thumb'] ?? $link['image'] ?? '' );

		$classes = 'bn-post-card__bridge-card bn-post-card__bridge-card--grid bn-post-card__bridge-card--' . sanitize_html_class( $type );
		if ( '' !== $image ) {
			$classes .= ' has-cover';
		}

		// What a member needs to judge the card without opening it, and what they can do from it.
		$link_meta = isset( $args['link_meta'] ) && is_array( $args['link_meta'] ) ? $args['link_meta'] : array();
		$resolved  = self::resolve( $type, $link, $link_meta, (int) ( $args['bn_post_id'] ?? 0 ) );
		$facts     = $resolved['facts'];
		$actions   = $resolved['actions'];
		// A partner may know a better title (a candidate's headline) or the right destination (the course, not its
		// certificate) than what was stored when the card was made.
		if ( '' !== $resolved['title'] ) {
			$title = $resolved['title'];
		}
		if ( '' !== $resolved['url'] ) {
			$url = $resolved['url'];
		}
		if ( '' !== $resolved['text'] ) {
			$desc = $resolved['text'];
		}

		$html = '<div class="' . esc_attr( $classes ) . '">';

		if ( '' !== $image ) {
			// Decorative: the title beneath it is the accessible name of the same
			// link, so announcing the image as well would read the card twice.
			$html .= '<a class="bn-post-card__bridge-cover" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">';
			$html .= '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async" />';
			$html .= '</a>';
		}

		// The type icon sits in front of the label, so the text has the card's full width.
		$html .= '<div class="bn-post-card__bridge-content">';
		$html .= '<span class="bn-post-card__bridge-kind"><span class="bn-post-card__bridge-icon" aria-hidden="true">' . buddynext_get_icon( $icon ) . '</span>';
		if ( '' !== $label ) {
			$html .= '<span class="bn-post-card__bridge-source">' . esc_html( $label ) . '</span>';
		}
		$html .= '</span>';
		$html .= '<a class="bn-post-card__bridge-title" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
		$html .= self::render_parts( $facts, $actions );
		$html .= '</div>';
		if ( '' !== $desc ) {
			$html .= '<p class="bn-post-card__bridge-text">' . esc_html( $desc ) . '</p>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Live values for the cards on this page, by post id: array{facts, actions}.
	 *
	 * @var array<int,array{facts:array<int,mixed>,actions:array<int,mixed>}>
	 */
	private static array $live = array();

	/**
	 * Most characters a queued job's encoded arguments may take (Action Scheduler's column is 191, minus the array wrapper).
	 *
	 * @var int
	 */
	private const JOB_ARGS_MAX = 180;

	/**
	 * Post id => the member the card is about, filled with $live.
	 *
	 * @var array<int,int>
	 */
	private static array $card_member = array();

	/**
	 * Load the live facts and actions of a whole page of partner cards, one call per card type.
	 *
	 * A card's facts are written when it publishes, so a value that changes afterwards (spots left,
	 * solved, open now) would go stale, and a card made before a bridge learned a fact would never
	 * show it. A bridge answers `buddynext_card_live` for its type from its own plugin's data; an old
	 * card and a new one are answered the same way. Nothing is asked for a type with no card on the
	 * page, and a bridge whose plugin is off or too old answers nothing, so the stored facts stand.
	 *
	 * @since 1.3.0
	 *
	 * @param array $items  Hydrated feed rows about to render.
	 * @param int   $viewer Current user ID (0 = logged out).
	 * @return void
	 */
	public static function prime_live( array $items, int $viewer ): void {
		$types = array_flip( self::live_types() );

		$by_type = array();
		foreach ( $items as $item ) {
			$type = (string) ( $item['type'] ?? '' );
			$id   = (int) ( $item['id'] ?? 0 );
			if ( $id > 0 && isset( $types[ $type ] ) && ! isset( self::$live[ $id ] ) ) {
				// The member the card is about rides along, so a partner can answer for them without a lookup per card.
				$by_type[ $type ][ $id ] = self::link_meta_of( $item ) + array( 'user_id' => (int) ( $item['user_id'] ?? 0 ) );
			}
		}

		foreach ( $by_type as $type => $cards ) {
			/**
			 * Live facts and actions for the partner cards of one type on this page.
			 *
			 * Return `array( post_id => array( 'facts' => array( array( 'icon' => ..., 'text' => ... ) ), 'actions' => array( array( 'label' => ..., 'url' => ... ) ) ) )`
			 * for the ids you can answer. Load them in ONE call, not one per card. The first action is
			 * the card's main button, the second is secondary, and no more than two are shown.
			 *
			 * @since 1.3.0
			 *
			 * @param array<int,array>   $answers Post id => { facts, actions }; empty by default.
			 * @param string             $type    Card post type ('job', 'listing', ...).
			 * @param array<int,array>   $cards   Post id => the card's stored link meta, plus `user_id` (the member it is about).
			 * @param int                $viewer  Current user ID (0 = logged out).
			 */
			$answers = (array) apply_filters( 'buddynext_card_live', array(), (string) $type, $cards, $viewer );
			foreach ( $cards as $id => $card ) {
				self::$card_member[ (int) $id ] = (int) ( $card['user_id'] ?? 0 );
				self::$live[ (int) $id ]        = isset( $answers[ $id ] ) && is_array( $answers[ $id ] ) ? $answers[ $id ] : array();
			}
		}
	}

	/**
	 * Put the card's facts and actions on a REST feed item, so the app reads what the page shows.
	 *
	 * @since 1.3.0
	 *
	 * @param array $item   The enriched item.
	 * @param int   $viewer Current user ID (0 = logged out).
	 * @return array
	 */
	public static function enrich_item( array $item, int $viewer ): array {
		unset( $viewer );
		$type = (string) ( $item['type'] ?? '' );
		if ( ! in_array( $type, self::live_types(), true ) ) {
			return $item;
		}
		// The stored facts are a publish-time snapshot a client can read as-is, so they pass the same filter the rendered card does.
		$meta = self::link_meta_of( $item );
		if ( ! empty( $meta['facts'] ) && is_array( $meta['facts'] ) && isset( $item['link_meta'] ) ) {
			$meta['facts']     = array_values( (array) apply_filters( 'buddynext_card_facts', $meta['facts'], $type, $meta + array( 'user_id' => (int) ( $item['user_id'] ?? 0 ) ) ) );
			$item['link_meta'] = $meta;
		}
		$resolved     = self::resolve( $type, array(), self::link_meta_of( $item ), (int) ( $item['id'] ?? 0 ) );
		$item['card'] = $resolved;
		return $item;
	}

	/**
	 * A feed row's stored link meta as an array (the column is JSON until a hydrate decodes it).
	 *
	 * @param array $item Feed row.
	 * @return array<string,mixed>
	 */
	private static function link_meta_of( array $item ): array {
		$meta = $item['link_meta'] ?? array();
		if ( is_string( $meta ) ) {
			$meta = json_decode( $meta, true );
		}
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * The facts line and button row of a card, ready to echo (escaped).
	 *
	 * One markup for every card, so the hand-written discussion card and the shared bridge card cannot drift.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int,array{icon:string,text:string}> $facts   Cleaned facts (see facts()).
	 * @param array<int,array{label:string,url:string}> $actions Cleaned buttons (see actions()).
	 * @return string
	 */
	public static function render_parts( array $facts, array $actions ): string {
		$html = '';
		if ( array() !== $facts ) {
			$html .= '<ul class="bn-post-card__bridge-facts">';
			foreach ( $facts as $fact ) {
				$html .= '<li>' . ( '' !== $fact['icon'] ? '<span class="bn-post-card__bridge-fact-icon" aria-hidden="true">' . buddynext_get_icon( $fact['icon'] ) . '</span>' : '' ) . '<span>' . esc_html( $fact['text'] ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		if ( array() !== $actions ) {
			$html .= '<div class="bn-post-card__bridge-actions">';
			foreach ( $actions as $i => $action ) {
				$html .= '<a class="bn-btn" data-size="sm" data-variant="' . ( 0 === $i ? 'primary' : 'secondary' ) . '" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a>';
			}
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * Card types a bridge can answer live: every registered card type, and the discussion card.
	 *
	 * @return string[]
	 */
	private static function live_types(): array {
		return array_merge( array_keys( self::card_types() ), array( 'discussion' ) );
	}

	/**
	 * The facts and actions one card shows: the partner's live answer for this page when it gave
	 * one, else what was stored when the card published, then the site's filters.
	 *
	 * @param string              $type      Card post type.
	 * @param array<string,mixed> $link      The card's link preview.
	 * @param array<string,mixed> $link_meta The card's stored link meta.
	 * @param int                 $post_id   Post id (0 when unknown).
	 * @return array{facts:array<int,array{icon:string,text:string}>,actions:array<int,array{label:string,url:string}>,title:string,text:string,url:string} A live answer may also name a better title, the short text under it and the address the title opens; '' = keep the stored ones.
	 */
	public static function resolve( string $type, array $link, array $link_meta, int $post_id ): array {
		$live = $post_id > 0 ? ( self::$live[ $post_id ] ?? array() ) : array();
		// The member the card is about, so a filter can decide what this viewer may see of them.
		if ( $post_id > 0 && ! isset( $link_meta['user_id'] ) && ! empty( self::$card_member[ $post_id ] ) ) {
			$link_meta['user_id'] = self::$card_member[ $post_id ];
		}
		$facts = ! empty( $live['facts'] ) ? (array) $live['facts'] : (array) ( $link['facts'] ?? $link_meta['facts'] ?? array() );

		/**
		 * Filter the facts line of a bridge card (company, place, type, rating, length ...).
		 *
		 * Each fact is `array( 'icon' => icon slug, 'text' => string )`. The list is cut to four
		 * and empty facts are dropped after this filter, so a site may add, remove or reword.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,array{icon:string,text:string}> $facts     The partner's facts.
		 * @param string                                    $type      Card post type ('job', 'listing', ...).
		 * @param array<string,mixed>                       $link_meta The card's stored link meta.
		 */
		$facts = self::facts( (array) apply_filters( 'buddynext_card_facts', $facts, $type, $link_meta ) );

		$actions = ! empty( $live['actions'] ) ? (array) $live['actions'] : (array) ( $link['actions'] ?? $link_meta['actions'] ?? array() );

		/**
		 * Filter the buttons of a bridge card: the main one and an optional secondary.
		 *
		 * Each is `array( 'label' => string, 'url' => string )`. At most two are shown and a link to
		 * another site is allowed. The footer (React, Comment, Share, Save) and the three-dot menu are
		 * not touched by this: a card only adds what is specific to its type.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,array{label:string,url:string}> $actions   The partner's buttons.
		 * @param string                                    $type      Card post type.
		 * @param array<string,mixed>                       $link_meta The card's stored link meta.
		 * @param int                                       $post_id   Post id.
		 */
		$title   = trim( (string) ( $live['title'] ?? '' ) );
		$text    = trim( wp_strip_all_tags( (string) ( $live['text'] ?? '' ) ) );
		$url     = trim( (string) ( $live['url'] ?? '' ) );
		$actions = self::actions(
			(array) apply_filters( 'buddynext_card_actions', $actions, $type, $link_meta, $post_id ),
			'' !== $url ? $url : (string) ( $link['url'] ?? $link_meta['url'] ?? '' )
		);

		return array(
			'facts'   => $facts,
			'actions' => $actions,
			'title'   => $title,
			'text'    => $text,
			'url'     => esc_url_raw( $url, array( 'http', 'https' ) ),
		);
	}

	/**
	 * An address reduced to what decides whether two links go to the same place: no fragment, no trailing slash.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function same_address_key( string $url ): string {
		return untrailingslashit( (string) strtok( $url, '#' ) );
	}

	/**
	 * The buttons of a bridge card, cleaned: a short label, a real address, no more than two.
	 *
	 * A button that goes where the card's own title already goes adds a second tab stop and nothing else, so it is
	 * never drawn, whoever supplied it. It is left out before the list is cut to two, so an action the title cannot
	 * do (download, a different page) further down the list still shows.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int,mixed> $actions   Raw buttons.
	 * @param string           $title_url Where the card's title goes ('' = no title link to compare with).
	 * @return array<int,array{label:string,url:string}>
	 */
	public static function actions( array $actions, string $title_url = '' ): array {
		$here = '' !== $title_url ? self::same_address_key( $title_url ) : '';
		$out  = array();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) ( $action['label'] ?? '' ) ) );
			$url   = esc_url_raw( (string) ( $action['url'] ?? '' ), array( 'http', 'https' ) );
			if ( '' === $label || '' === $url || ( '' !== $here && self::same_address_key( $url ) === $here ) ) {
				continue;
			}
			$out[] = array(
				'label' => mb_substr( $label, 0, 30 ),
				'url'   => $url,
			);
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The Space a partner item's card may live in: an open Space, else none (site-wide).
	 *
	 * An event or listing is public, and its card must stay public. In an open Space the card
	 * also shows in that Space's own feed; for a private or secret Space it stays in the site
	 * feed, and the Space shows the item on its own tab. Pass the result as the card's space.
	 *
	 * @since 1.3.0
	 *
	 * An item tied to several Spaces gets one card: pass the Space ids in order of preference (the
	 * item's own Space first, then the Spaces it was linked to, earliest first) and the first OPEN
	 * one wins.
	 *
	 * @param int|int[] $space_id The Space the item was made for (0 = none), or several in order.
	 * @return int The first open Space id that is not plan-gated, else 0.
	 */
	public static function card_space( $space_id ): int {
		$card_space = 0;
		$space_ids  = array_values( array_filter( array_map( 'intval', (array) $space_id ) ) );
		if ( array() !== $space_ids && function_exists( 'buddynext_service' ) ) {
			foreach ( $space_ids as $id ) {
				$space = buddynext_service( 'spaces' )->get( $id );
				// Open, and not gated by a plan or ability: a gated Space is members-only in practice, so a
				// public item's card stays in the site feed instead of going into it.
				if ( null !== $space && \BuddyNext\Spaces\SpaceService::TYPE_OPEN === (string) ( $space['type'] ?? '' ) && '' === (string) ( $space['required_ability'] ?? '' ) ) {
					$card_space = $id;
					break;
				}
			}
		}

		/**
		 * Filter the Space a partner item's card is placed in.
		 *
		 * Defaults to the item's Space when it is open, else 0 (site-wide).
		 *
		 * @since 1.3.0
		 *
		 * @param int       $card_space The Space the card goes in (0 = site-wide).
		 * @param int|int[] $space_id   The Space(s) the item belongs to, in order of preference.
		 */
		return max( 0, (int) apply_filters( 'buddynext_partner_card_space', $card_space, $space_id ) );
	}

	/**
	 * The facts line of a bridge card, cleaned.
	 *
	 * A bridge passes `'facts' => array( array( 'icon' => 'map-pin', 'text' => 'Remote' ), ... )`
	 * in the card's meta when it publishes. An empty fact is left out, a text is kept to one short
	 * line, the icon slug is sanitised (one the icon set does not have draws no icon, the text
	 * stays), and no more than four are kept: the partner's own page always has the rest.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int,mixed> $facts Raw facts.
	 * @return array<int,array{icon:string,text:string}>
	 */
	public static function facts( array $facts ): array {
		$out = array();
		foreach ( $facts as $fact ) {
			if ( ! is_array( $fact ) ) {
				continue;
			}
			$text = trim( wp_strip_all_tags( (string) ( $fact['text'] ?? '' ) ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'icon' => sanitize_key( (string) ( $fact['icon'] ?? '' ) ),
				'text' => mb_substr( $text, 0, 80 ),
			);
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return $out;
	}
}
