<?php
/**
 * BuddyNext explore discovery card.
 *
 * Renders ONE compact, click-through card for the Explore "what's new" grid.
 * Unlike partials/post-card.php (the heavy interactive feed card with a
 * reaction bar, comment box and options menu), this is a lightweight discovery
 * teaser sized for a narrow masonry column: the whole card links through to its
 * destination and the byline gets room to breathe — no Follow button jammed
 * beside the name.
 *
 * The grid mixes entity types, so this partial switches on $card['kind']:
 *   - post-poll  : a poll post                    → poll card (Vote CTA)
 *   - post-media : a post carrying an attachment  → image card
 *   - post-quote : short, high-engagement text    → pull-quote card (span-2)
 *   - post-forum : a Jetonomy forum/discussion    → tinted thread card (span-tall)
 *   - post-text  : a plain text/link post         → text card
 *   - member     : a (new) community member        → avatar + Follow/Message footer
 *   - space      : a (new/popular) space           → cover + Join footer
 *
 * Kinds are assigned by buddynext_explore_card_kind() so the SSR template and
 * the REST pagination endpoint classify identically.
 *
 * Overridable: copy to {theme}/buddynext/partials/explore-card.php
 *
 * Expected variables (set by the caller):
 *   array $card            The normalized card payload (see ExploreService).
 *   int   $current_user_id Viewing user ID (0 for guests).
 *
 * @package BuddyNext
 * @since   1.6.0
 *
 * @var array $card
 * @var int   $current_user_id
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Media\MediaUrlResolver;

$bn_card    = isset( $card ) && is_array( $card ) ? $card : array();
$bn_kind    = (string) ( $bn_card['kind'] ?? '' );
$bn_viewer  = isset( $current_user_id ) ? (int) $current_user_id : get_current_user_id();
$bn_palette = array( 'violet', 'amber', 'emerald', 'rose', 'sky' );

if ( '' === $bn_kind ) {
	return;
}

/**
 * Resolve a deterministic decorative tone for a card by numeric seed.
 *
 * @param int   $seed    Stable identifier (post / space / user ID).
 * @param array $palette Tone slugs.
 * @return string One palette slug.
 */
if ( ! function_exists( 'bn_explore_tone' ) ) {
	/**
	 * Resolve a deterministic decorative tone for a card by numeric seed.
	 *
	 * @param int      $seed    Stable identifier (post / space / user ID).
	 * @param string[] $palette Tone slugs.
	 * @return string One palette slug.
	 */
	function bn_explore_tone( int $seed, array $palette ): string {
		if ( empty( $palette ) ) {
			return 'violet';
		}
		return $palette[ abs( $seed ) % count( $palette ) ];
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// MEMBER CARD — user info gets the whole top; Follow + Message in their own row.
// ─────────────────────────────────────────────────────────────────────────────
if ( 'member' === $bn_kind ) {
	$bn_user_id = (int) ( $bn_card['user_id'] ?? 0 );
	$bn_user    = $bn_user_id > 0 ? get_userdata( $bn_user_id ) : null;
	if ( ! $bn_user ) {
		return;
	}

	$bn_name   = (string) $bn_user->display_name;
	$bn_login  = (string) $bn_user->user_nicename;
	$bn_url    = PageRouter::profile_url( $bn_user_id );
	$bn_av     = (string) get_avatar_url( $bn_user_id, array( 'size' => 96 ) );
	$bn_tone   = bn_explore_tone( $bn_user_id, $bn_palette );
	$bn_joined = (string) ( $bn_card['registered'] ?? $bn_user->user_registered );
	$bn_tag    = (string) ( $bn_card['tagline'] ?? '' );
	?>
	<article class="ec-card ec-card--member" data-kind="member">
		<a class="ec-member-link" href="<?php echo esc_url( $bn_url ); ?>">
			<span class="bn-avatar" data-size="xl" data-tone="<?php echo esc_attr( $bn_tone ); ?>">
				<?php if ( '' !== $bn_av ) : ?>
					<img src="<?php echo esc_url( $bn_av ); ?>" alt="" width="64" height="64" loading="lazy" decoding="async">
				<?php else : ?>
					<?php echo esc_html( mb_strtoupper( mb_substr( $bn_name, 0, 1 ) ) ); ?>
				<?php endif; ?>
			</span>
			<span class="ec-member-name"><?php echo esc_html( $bn_name ); ?></span>
			<span class="ec-member-handle">@<?php echo esc_html( $bn_login ); ?></span>
			<span class="ec-member-meta">
				<?php
				if ( '' !== $bn_tag ) {
					echo esc_html( $bn_tag );
				} elseif ( '' !== $bn_joined ) {
					/* translators: %s: human-readable time since the member joined. */
					echo esc_html( sprintf( __( 'Joined %s', 'buddynext' ), buddynext_time_ago( $bn_joined ) ) );
				} else {
					esc_html_e( 'New member', 'buddynext' );
				}
				?>
			</span>
		</a>
		<?php if ( $bn_viewer > 0 && $bn_viewer !== $bn_user_id ) : ?>
			<div class="ec-foot ec-foot--actions">
				<?php
				$user_id = $bn_user_id;
				buddynext_get_template( 'partials/follow-button.php', array( 'user_id' => $bn_user_id ) );
				?>
				<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $bn_url ); ?>">
					<?php esc_html_e( 'View', 'buddynext' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</article>
	<?php
	return;
}

// ─────────────────────────────────────────────────────────────────────────────
// SPACE CARD — cover tone + name + blurb; Join in its own footer row.
// ─────────────────────────────────────────────────────────────────────────────
if ( 'space' === $bn_kind ) {
	$bn_space = isset( $bn_card['space'] ) && is_array( $bn_card['space'] ) ? $bn_card['space'] : array();
	$bn_sid   = (int) ( $bn_space['id'] ?? 0 );
	if ( $bn_sid <= 0 ) {
		return;
	}

	$bn_sname  = (string) ( $bn_space['name'] ?? __( 'Space', 'buddynext' ) );
	$bn_sdesc  = trim( wp_strip_all_tags( (string) ( $bn_space['description'] ?? '' ) ) );
	$bn_scount = (int) ( $bn_space['member_count'] ?? 0 );
	$bn_stype  = (string) ( $bn_space['type'] ?? 'open' );
	$bn_scover = (string) ( $bn_space['avatar_url'] ?? '' );
	$bn_surl   = PageRouter::space_url( $bn_sid );
	$bn_stone  = bn_explore_tone( $bn_sid, $bn_palette );
	// Route non-public types through the registry's translated label map so the
	// badge never renders a raw, untranslated slug ("private"/"secret").
	$bn_slabel = 'open' === $bn_stype
		? __( 'Public', 'buddynext' )
		: \BuddyNext\Spaces\SpaceTypeRegistry::instance()->label( $bn_stype );
	?>
	<article class="ec-card ec-card--space" data-kind="space">
		<a class="ec-img" href="<?php echo esc_url( $bn_surl ); ?>" data-tone="<?php echo esc_attr( $bn_stone ); ?>" aria-hidden="true" tabindex="-1">
			<?php if ( '' !== $bn_scover ) : ?>
				<img src="<?php echo esc_url( $bn_scover ); ?>" alt="" loading="lazy" decoding="async">
			<?php else : ?>
				<span class="ec-img-glyph" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
			<?php endif; ?>
		</a>
		<div class="ec-body">
			<div class="ec-kicker"><?php echo esc_html( sprintf( /* translators: %s: space privacy label. */ __( 'Space · %s', 'buddynext' ), $bn_slabel ) ); ?></div>
			<a class="ec-title" href="<?php echo esc_url( $bn_surl ); ?>"><?php echo esc_html( $bn_sname ); ?></a>
			<?php if ( '' !== $bn_sdesc ) : ?>
				<div class="ec-text"><?php echo esc_html( wp_trim_words( $bn_sdesc, 18, '…' ) ); ?></div>
			<?php endif; ?>
			<div class="ec-foot ec-foot--actions">
				<span class="ec-foot-meta">
					<?php
					/* translators: %s: formatted member count. */
					echo esc_html( sprintf( _n( '%s member', '%s members', $bn_scount, 'buddynext' ), number_format_i18n( $bn_scount ) ) );
					?>
				</span>
				<a class="bn-btn" data-variant="primary" data-size="sm" href="<?php echo esc_url( $bn_surl ); ?>">
					<?php esc_html_e( 'View space', 'buddynext' ); ?>
				</a>
			</div>
		</div>
	</article>
	<?php
	return;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST CARDS (post-media / post-quote / post-forum / post-text)
// ─────────────────────────────────────────────────────────────────────────────
$bn_post = isset( $bn_card['post'] ) && is_array( $bn_card['post'] ) ? $bn_card['post'] : array();
$bn_pid  = (int) ( $bn_post['id'] ?? 0 );
if ( $bn_pid <= 0 ) {
	return;
}

$bn_author_id = (int) ( $bn_post['user_id'] ?? 0 );
$bn_author    = $bn_author_id > 0 ? get_userdata( $bn_author_id ) : null;
$bn_aname     = $bn_author ? (string) $bn_author->display_name : __( 'Community member', 'buddynext' );
$bn_aurl      = $bn_author_id > 0 ? PageRouter::profile_url( $bn_author_id ) : PageRouter::activity_url();
$bn_purl      = PageRouter::post_url( $bn_pid );
$bn_av        = $bn_author_id > 0 ? (string) get_avatar_url( $bn_author_id, array( 'size' => 40 ) ) : '';
$bn_atone     = bn_explore_tone( $bn_author_id, $bn_palette );

$bn_reactions = (int) ( $bn_post['reaction_count'] ?? 0 );
$bn_comments  = (int) ( $bn_post['comment_count'] ?? 0 );
$bn_shares    = (int) ( $bn_post['share_count'] ?? 0 );
$bn_created   = (string) ( $bn_post['created_at'] ?? '' );
$bn_plain     = trim( wp_strip_all_tags( (string) ( $bn_post['content'] ?? '' ) ) );

// Link/discussion posts (e.g. a synced Jetonomy topic) carry the real headline
// in link_meta['title'] while content is just the activity verb ("started a
// discussion"). Prefer that title for the card headline; fall back to content.
$bn_link_meta = $bn_post['link_meta'] ?? null;
if ( is_string( $bn_link_meta ) && '' !== $bn_link_meta ) {
	$bn_link_meta = json_decode( $bn_link_meta, true );
}
$bn_link_title = is_array( $bn_link_meta ) ? trim( (string) ( $bn_link_meta['title'] ?? '' ) ) : '';
$bn_link_desc  = is_array( $bn_link_meta ) ? trim( (string) ( $bn_link_meta['description'] ?? '' ) ) : '';
$bn_headline   = '' !== $bn_link_title ? $bn_link_title : $bn_plain;
// Body excerpt: prefer a real link/discussion excerpt, else the post content
// (but never the bare activity verb that link posts store as content).
$bn_excerpt = '' !== $bn_link_desc ? $bn_link_desc : ( '' !== $bn_link_title ? '' : $bn_plain );

// Kicker: first hashtag if present, else generic.
$bn_kicker = '';
if ( ! empty( $bn_card['hashtag'] ) ) {
	$bn_kicker = '#' . ltrim( (string) $bn_card['hashtag'], '#' );
}

/**
 * Render the shared byline foot (avatar · name · stats). The name gets the row;
 * no Follow button here — discovery cards link through instead.
 *
 * @param string $av    Avatar URL.
 * @param string $tone  Tone slug.
 * @param string $name  Author display name.
 * @param string $url   Author profile URL.
 * @param int    $likes Reaction count.
 * @param int    $cmts  Comment count.
 * @return void
 */
$bn_render_foot = static function ( string $av, string $tone, string $name, string $url, int $likes, int $cmts ): void {
	?>
	<div class="ec-foot">
		<a class="ec-foot-author" href="<?php echo esc_url( $url ); ?>">
			<span class="bn-avatar" data-size="xs" data-tone="<?php echo esc_attr( $tone ); ?>">
				<?php if ( '' !== $av ) : ?>
					<img src="<?php echo esc_url( $av ); ?>" alt="" width="22" height="22" loading="lazy" decoding="async">
				<?php else : ?>
					<?php echo esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ); ?>
				<?php endif; ?>
			</span>
			<span class="name"><?php echo esc_html( $name ); ?></span>
		</a>
		<span class="stats">
			<?php if ( $likes > 0 ) : ?>
				<span title="<?php esc_attr_e( 'Reactions', 'buddynext' ); ?>"><?php buddynext_icon( 'heart' ); ?><?php echo esc_html( number_format_i18n( $likes ) ); ?></span>
			<?php endif; ?>
			<?php if ( $cmts > 0 ) : ?>
				<span title="<?php esc_attr_e( 'Comments', 'buddynext' ); ?>"><?php buddynext_icon( 'message-circle' ); ?><?php echo esc_html( number_format_i18n( $cmts ) ); ?></span>
			<?php endif; ?>
		</span>
	</div>
	<?php
};

// ── post-poll: recognizable poll card (question + Vote call-to-action) ───────
// Polls previously fell through to the plain post-text card, so a member could
// not tell a poll from any other text post on Explore. The poll kicker + glyph
// make it identifiable; the whole card and the footer CTA click through to the
// post where the member can vote.
if ( 'post-poll' === $bn_kind ) :
	$bn_poll_options = $bn_post['poll_options'] ?? array();
	$bn_poll_count   = is_array( $bn_poll_options ) ? count( $bn_poll_options ) : 0;
	$bn_poll_q       = '' !== $bn_headline ? $bn_headline : __( 'Community poll', 'buddynext' );
	?>
	<article class="ec-card is-poll" data-kind="post-poll">
		<a class="ec-body" href="<?php echo esc_url( $bn_purl ); ?>">
			<div class="ec-kicker ec-kicker--poll">
				<span class="ec-poll-glyph" aria-hidden="true"><?php buddynext_icon( 'bar-chart-2' ); ?></span>
				<?php
				echo '' !== $bn_kicker
					/* translators: %s: poll category/context label */
					? esc_html( sprintf( __( 'Poll · %s', 'buddynext' ), $bn_kicker ) )
					: esc_html__( 'Poll', 'buddynext' );
				?>
			</div>
			<div class="ec-title"><?php echo esc_html( wp_trim_words( $bn_poll_q, 18, '…' ) ); ?></div>
			<?php if ( $bn_poll_count > 0 ) : ?>
				<div class="ec-poll-meta">
					<?php
					/* translators: %s: formatted number of poll options. */
					echo esc_html( sprintf( _n( '%s option', '%s options', $bn_poll_count, 'buddynext' ), number_format_i18n( $bn_poll_count ) ) );
					?>
				</div>
			<?php endif; ?>
		</a>
		<div class="ec-foot ec-foot--poll">
			<a class="ec-poll-vote" href="<?php echo esc_url( $bn_purl ); ?>">
				<?php buddynext_icon( 'bar-chart-2' ); ?>
				<?php esc_html_e( 'Vote', 'buddynext' ); ?>
			</a>
			<span class="stats">
				<?php if ( $bn_reactions > 0 ) : ?>
					<span title="<?php esc_attr_e( 'Reactions', 'buddynext' ); ?>"><?php buddynext_icon( 'heart' ); ?><?php echo esc_html( number_format_i18n( $bn_reactions ) ); ?></span>
				<?php endif; ?>
				<?php if ( $bn_comments > 0 ) : ?>
					<span title="<?php esc_attr_e( 'Comments', 'buddynext' ); ?>"><?php buddynext_icon( 'message-circle' ); ?><?php echo esc_html( number_format_i18n( $bn_comments ) ); ?></span>
				<?php endif; ?>
			</span>
		</div>
	</article>
	<?php
	return;
endif;

// ── post-forum: tinted, taller discussion/thread card ───────────────────────
if ( 'post-forum' === $bn_kind ) :
	?>
	<article class="ec-card is-forum span-tall" data-kind="post-forum">
		<a class="ec-body" href="<?php echo esc_url( $bn_purl ); ?>">
			<div class="ec-kicker">
				<?php
				echo '' !== $bn_kicker
					/* translators: %s: discussion category/context label */
					? esc_html( sprintf( __( 'Discussion · %s', 'buddynext' ), $bn_kicker ) )
					: esc_html__( 'Discussion', 'buddynext' );
				?>
			</div>
			<div class="ec-title"><?php echo esc_html( wp_trim_words( $bn_headline, 16, '…' ) ); ?></div>
			<?php if ( '' !== $bn_excerpt ) : ?>
				<div class="ec-text"><?php echo esc_html( wp_trim_words( $bn_excerpt, 28, '…' ) ); ?></div>
			<?php endif; ?>
			<div class="ec-thread-meta">
				<span><?php buddynext_icon( 'user' ); ?><?php echo esc_html( $bn_aname ); ?></span>
				<?php if ( $bn_comments > 0 ) : ?>
					<span>
						<?php buddynext_icon( 'message-circle' ); ?>
						<?php
						/* translators: %s: formatted reply count. */
						echo esc_html( sprintf( _n( '%s reply', '%s replies', $bn_comments, 'buddynext' ), number_format_i18n( $bn_comments ) ) );
						?>
					</span>
				<?php endif; ?>
				<?php if ( '' !== $bn_created ) : ?>
					<span><?php buddynext_icon( 'clock' ); ?><?php echo esc_html( buddynext_time_ago( $bn_created ) ); ?></span>
				<?php endif; ?>
			</div>
		</a>
		<div class="ec-foot ec-foot--forum">
			<a class="ec-open-thread" href="<?php echo esc_url( $bn_purl ); ?>">
				<?php esc_html_e( 'Open thread', 'buddynext' ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
		</div>
	</article>
	<?php
	return;
endif;

// ── post-media: image card (cover thumbnail + caption) ──────────────────────
if ( 'post-media' === $bn_kind ) :
	$bn_cover = '';
	$bn_alt   = '';
	$bn_mids  = $bn_post['media_ids'] ?? array();
	if ( is_string( $bn_mids ) ) {
		$bn_mids = json_decode( $bn_mids, true );
	}
	if ( is_array( $bn_mids ) && ! empty( $bn_mids ) && class_exists( MediaUrlResolver::class ) ) {
		$bn_desc = MediaUrlResolver::descriptor( (int) $bn_mids[0] );
		if ( $bn_desc ) {
			$bn_cover = (string) ( '' !== $bn_desc['thumb'] ? $bn_desc['thumb'] : $bn_desc['url'] );
			$bn_alt   = (string) $bn_desc['title'];
		}
	}
	$bn_mtone = bn_explore_tone( $bn_pid, $bn_palette );
	?>
	<article class="ec-card is-media" data-kind="post-media">
		<a class="ec-img" href="<?php echo esc_url( $bn_purl ); ?>" data-tone="<?php echo esc_attr( $bn_mtone ); ?>">
			<?php if ( '' !== $bn_cover ) : ?>
				<img src="<?php echo esc_url( $bn_cover ); ?>" alt="<?php echo esc_attr( $bn_alt ); ?>" loading="lazy" decoding="async">
			<?php else : ?>
				<span class="ec-img-glyph" aria-hidden="true"><?php buddynext_icon( 'image' ); ?></span>
			<?php endif; ?>
		</a>
		<div class="ec-body">
			<div class="ec-kicker">
				<?php
				echo '' !== $bn_kicker
					/* translators: %s: hashtag. */
					? esc_html( sprintf( __( 'Photo · %s', 'buddynext' ), $bn_kicker ) )
					: esc_html__( 'Photo', 'buddynext' );
				?>
			</div>
			<?php if ( '' !== $bn_headline ) : ?>
				<a class="ec-title" href="<?php echo esc_url( $bn_purl ); ?>"><?php echo esc_html( wp_trim_words( $bn_headline, 16, '…' ) ); ?></a>
			<?php endif; ?>
			<?php $bn_render_foot( $bn_av, $bn_atone, $bn_aname, $bn_aurl, $bn_reactions, $bn_comments ); ?>
		</div>
	</article>
	<?php
	return;
endif;

// ── post-text: plain text/link card (default) ───────────────────────────────
?>
<article class="ec-card is-text" data-kind="post-text">
	<a class="ec-body" href="<?php echo esc_url( $bn_purl ); ?>">
		<div class="ec-kicker">
			<?php
			echo '' !== $bn_kicker
				/* translators: %s: hashtag. */
				? esc_html( sprintf( __( 'Post · %s', 'buddynext' ), $bn_kicker ) )
				: esc_html__( 'Post', 'buddynext' );
			?>
		</div>
		<div class="ec-title"><?php echo esc_html( wp_trim_words( $bn_headline, 24, '…' ) ); ?></div>
	</a>
	<?php $bn_render_foot( $bn_av, $bn_atone, $bn_aname, $bn_aurl, $bn_reactions, $bn_comments ); ?>
</article>
<?php
// End of explore-card.
