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
	 * Publish a link-card activity for content a member just created.
	 *
	 * Rendered as a standard feed link card pointing at the partner's own page
	 * (we link OUT — we never embed the partner UI). `link_meta` is supplied so
	 * PostService does not make an OG-fetch HTTP call to the partner.
	 *
	 * @param int    $member_id  The member who created the content.
	 * @param string $content    Feed text, e.g. "started a discussion".
	 * @param string $link_url   The partner page the card links to.
	 * @param string $link_title Title shown on the card (the content's title).
	 * @param string $type       Post type to record. Defaults to 'link'. Pass a
	 *                           specific type (e.g. 'discussion', 'job')
	 *                           so discovery surfaces can classify + filter the
	 *                           card by what it represents instead of a generic
	 *                           link. Must be a PostService::ALLOWED_TYPES value.
	 * @param string $excerpt    Optional short excerpt of the partner content,
	 *                           stored in link_meta['description'] so the card can
	 *                           show a title + preview instead of just a verb.
	 * @param int    $space_id   The BuddyNext space the partner content belongs to,
	 *                           when it belongs to one. This is what makes the
	 *                           per-space "Share activity to the main feed" toggle
	 *                           real: FeedService excludes an opted-out space by
	 *                           `space_id`, and its clause deliberately lets a NULL
	 *                           space through, so a card published without one can
	 *                           never be suppressed — and never appears in the
	 *                           space's own feed either. Pass 0 for content that
	 *                           genuinely has no space (a badge, a resume).
	 * @param array  $meta       Optional extra fields merged into `link_meta`, so a
	 *                           typed card (event, course, …) can carry its structured
	 *                           payload (cover, start time, location, source id) for a
	 *                           `buddynext_render_post_body_{type}` renderer and the
	 *                           REST/app surfaces. Overrides the defaults it shares a
	 *                           key with (e.g. pass 'image' to set the card cover).
	 * @return int|\WP_Error Post id (0 when an identical card already exists), or WP_Error.
	 */
	public static function publish( int $member_id, string $content, string $link_url, string $link_title = '', string $type = 'link', string $excerpt = '', int $space_id = 0, array $meta = array() ) {
		if ( $member_id <= 0 || '' === $link_url ) {
			return new \WP_Error( 'invalid_activity', 'member id and link url are required' );
		}

		$type    = '' !== $type ? $type : 'link';
		$service = new PostService();

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
					'type'      => $type,
					'content'   => $content,
					'space_id'  => $space_id > 0 ? $space_id : null,
					// Integration activities link OUT to a public partner page (a
					// public Jetonomy discussion, a job posting, etc.), so they are
					// inherently public. Set it explicitly rather than inheriting the
					// site's default-post-privacy option, which may be blank.
					'privacy'   => 'public',
					'link_url'  => $link_url,
					'link_meta' => self::normalise_link_meta(
						array_merge(
							array(
								'title'       => $link_title,
								'description' => $excerpt,
								'image'       => '',
								'url'         => $link_url,
							),
							$meta
						)
					),
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
	 * @param string $link_url The partner page the card linked to.
	 * @param string $type     Post type the card was stored as. Defaults to 'link';
	 *                         pass the same type used at publish() time.
	 * @return int Rows removed.
	 */
	public static function remove( string $link_url, string $type = 'link' ): int {
		if ( '' === $link_url ) {
			return 0;
		}
		return ( new PostService() )->delete_by_link( '' !== $type ? $type : 'link', $link_url );
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
	 */
	public static function refresh( string $link_url, string $type, array $meta ): bool {
		if ( '' === $link_url || '' === $type || array() === $meta ) {
			return false;
		}

		$service = new PostService();

		return $service->update_link_meta(
			$type,
			$link_url,
			self::normalise_link_meta( array_merge( $service->get_link_meta( $type, $link_url ), $meta ) )
		);
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
	 */
	public static function remove_by_meta( string $type, string $meta_key, int $value ): int {
		if ( '' === $type || '' === $meta_key || $value <= 0 ) {
			return 0;
		}
		return ( new PostService() )->delete_by_link_meta_int( $type, $meta_key, $value );
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
	 * @param string              $icon  Lucide icon slug for the card (e.g. 'graduation-cap').
	 * @param string              $label Source label shown above the title (e.g. "Course").
	 * @return string Card HTML, or '' when there is no link to render (text fallback).
	 */
	public static function render_bridge_card( array $args, string $icon, string $label ): string {
		$link  = isset( $args['link_preview'] ) && is_array( $args['link_preview'] ) ? $args['link_preview'] : array();
		$url   = isset( $link['url'] ) ? (string) $link['url'] : '';
		$title = isset( $link['title'] ) ? (string) $link['title'] : '';
		$desc  = isset( $link['desc'] ) ? (string) $link['desc'] : '';
		$type  = isset( $args['bn_post_type'] ) ? (string) $args['bn_post_type'] : 'link';

		// No link to point at → let the caller fall back to the plain-text body.
		if ( '' === $url ) {
			return '';
		}
		// The linked line is the content's own title; fall back to a trimmed verb.
		if ( '' === $title ) {
			$title = wp_trim_words( wp_strip_all_tags( (string) ( $args['post_content'] ?? '' ) ), 14 );
		}

		// The member's own words for what happened ("posted a new job", "completed
		// a course"). Every bridge passes one and none of them were ever shown: it
		// was read only as a FALLBACK title, so on a card that had a title it was
		// silently dropped. The event card has always printed its verb as a lead,
		// and the result of bridge cards not doing so was that "completed a course"
		// and "earned a certificate" rendered as two identical cards.
		//
		// Skipped when it was already promoted to the title above, so a card with
		// no title of its own does not say the same thing twice.
		$verb = trim( wp_strip_all_tags( (string) ( $args['post_content'] ?? '' ) ) );
		$lead = ( '' !== $verb && $verb !== $title ) ? $verb : '';

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

		$classes = 'bn-post-card__bridge-card bn-post-card__bridge-card--' . sanitize_html_class( $type );
		if ( '' !== $image ) {
			$classes .= ' has-cover';
		}

		$html = '<div class="' . esc_attr( $classes ) . '">';

		if ( '' !== $image ) {
			// Decorative: the title beneath it is the accessible name of the same
			// link, so announcing the image as well would read the card twice.
			$html .= '<a class="bn-post-card__bridge-cover" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">';
			$html .= '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async" />';
			$html .= '</a>';
		}

		$html .= '<span class="bn-post-card__bridge-icon" aria-hidden="true">' . buddynext_get_icon( $icon ) . '</span>';
		$html .= '<div class="bn-post-card__bridge-content">';
		if ( '' !== $label ) {
			$html .= '<span class="bn-post-card__bridge-source">' . esc_html( $label ) . '</span>';
		}
		if ( '' !== $lead ) {
			$html .= '<p class="bn-post-card__bridge-lead">' . esc_html( $lead ) . '</p>';
		}
		$html .= '<a class="bn-post-card__bridge-title" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
		if ( '' !== $desc ) {
			$html .= '<p class="bn-post-card__bridge-text">' . esc_html( $desc ) . '</p>';
		}
		$html .= '</div></div>';

		return $html;
	}
}
