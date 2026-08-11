<?php
/**
 * BuddyNext template part: post-body.
 *
 * Renders the body region of a post-card — the text content plus the
 * type-specific payload (photo grid, file list, link preview, poll, media
 * bridge, share embed, etc.). Mirrors the markup previously inlined in
 * `templates/partials/post-card.php` between `<!-- Body -->` and the closing
 * `</div><!-- .bn-post-card__body -->` tag.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array       $bn_post              Hydrated post array.
 * @var int         $bn_post_id           Post ID.
 * @var string      $bn_post_type         Post type slug.
 * @var string      $post_content         Decoded post content (pre-format).
 * @var array       $link_preview         Pre-resolved link-preview fields:
 *                                        { url, title, desc, thumb, domain }.
 * @var array       $link_meta            Decoded link_meta JSON. Carries a typed
 *                                        card's structured payload (e.g. an event's
 *                                        cover / start / location / source id) for
 *                                        the `buddynext_render_post_body_{type}`
 *                                        renderer seam in the default branch.
 * @var array       $poll_data            Pre-resolved poll fields:
 *                                        { options, total_votes, my_voted_option_id }.
 * @var array       $media_attachments    Pre-resolved media attachment ids.
 * @var bool        $is_pinned            Whether the post is pinned (legacy hook context).
 * @var bool        $has_cw               Whether a content warning is active (controls JS bind).
 * @var array|null  $shared_post          For type=share — the original post row or null.
 * @var array       $classes              Optional extra CSS classes for the body wrap.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_body_before', $args )
 *   - do_action( 'buddynext_part_post_body_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_body_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_body_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Profile\AvatarService;

$args = array(
	'bn_post'           => isset( $bn_post ) && is_array( $bn_post ) ? $bn_post : array(),
	'bn_post_id'        => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'bn_post_type'      => isset( $bn_post_type ) ? (string) $bn_post_type : 'text',
	'post_content'      => isset( $post_content ) ? (string) $post_content : '',
	'link_preview'      => isset( $link_preview ) && is_array( $link_preview ) ? $link_preview : array(),
	'link_meta'         => isset( $link_meta ) && is_array( $link_meta ) ? $link_meta : array(),
	'poll_data'         => isset( $poll_data ) && is_array( $poll_data ) ? $poll_data : array(),
	'media_attachments' => isset( $media_attachments ) && is_array( $media_attachments ) ? $media_attachments : array(),
	'is_pinned'         => ! empty( $is_pinned ),
	'has_cw'            => ! empty( $has_cw ),
	'shared_post'       => isset( $shared_post ) && is_array( $shared_post ) ? $shared_post : null,
	'classes'           => isset( $classes ) ? (array) $classes : array(),
	// Which surface is rendering: a feed PREVIEWS, the permalink READS.
	'context'           => isset( $context ) ? (string) $context : '',
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_body_args', $args );

if ( 0 === (int) $args['bn_post_id'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-post-card__body' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_body_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

$bn_body_post_type = (string) $args['bn_post_type'];
$bn_body_content   = (string) $args['post_content'];
$bn_body_media_ids = array_values( array_map( 'absint', (array) $args['media_attachments'] ) );
$bn_body_link      = (array) $args['link_preview'];
$bn_link_url       = isset( $bn_body_link['url'] ) ? (string) $bn_body_link['url'] : '';
$bn_link_title     = isset( $bn_body_link['title'] ) ? (string) $bn_body_link['title'] : '';
$bn_link_desc      = isset( $bn_body_link['desc'] ) ? (string) $bn_body_link['desc'] : '';
$bn_link_thumb     = isset( $bn_body_link['thumb'] ) ? (string) $bn_body_link['thumb'] : '';
$bn_link_domain    = isset( $bn_body_link['domain'] ) ? (string) $bn_body_link['domain'] : '';
$bn_body_poll      = (array) $args['poll_data'];
$bn_poll_options   = isset( $bn_body_poll['options'] ) && is_array( $bn_body_poll['options'] ) ? $bn_body_poll['options'] : array();
$bn_poll_total     = isset( $bn_body_poll['total_votes'] ) ? absint( $bn_body_poll['total_votes'] ) : 0;
$bn_poll_my_vote   = isset( $bn_body_poll['my_voted_option_id'] ) ? absint( $bn_body_poll['my_voted_option_id'] ) : 0;
$bn_poll_closed    = ! empty( $bn_body_poll['closed'] );
$bn_shared_post    = is_array( $args['shared_post'] ) ? $args['shared_post'] : null;

/*
 * PREVIEW vs READ.
 *
 * The feed is a scanning surface; the permalink is a reading surface. Measured
 * on the same 1,820-character post: in the feed its card is 1,310px on desktop
 * and 1,803px at 390px -- 1.5 and 2.1 screens, with ZERO posts fully visible on
 * the first screen and 1,686px of scrolling before the next post begins. On
 * /p/{id}/ the identical text is comfortable, because that page exists to
 * display it and already carries the full measure, the breadcrumb and the
 * expanded thread.
 *
 * So a long post is previewed here and read there, rather than expanded in
 * place. Expanding inline would put the 2.1-screen card back the moment it is
 * tapped, which is the thing being fixed. Opening the post costs the member
 * nothing because the feed keeps its state in the URL (?shown=N&filter=…) --
 * verified: going to a post and back restores all 30 loaded cards and the
 * scroll position.
 *
 * THE THRESHOLD IS A CONVENTION, NOT A MEASUREMENT. ~300 characters is about
 * one Twitter post (280), i.e. what a "regular update" looks like anywhere.
 * It is deliberately not fitted to any dataset we happen to hold: the migrated
 * feed that prompted this measures how people wrote on Mighty Networks, and a
 * seeded test site measures fixtures. Neither predicts how ten thousand
 * different communities write, so both filters below exist for the owners whose
 * members genuinely write longer.
 *
 * Text only. A tall photo or a vertical video can eat a screen too, but that is
 * a media-sizing question with different answers, and clamping a caption would
 * not touch it.
 */
/*
 * Absent context means DO NOT preview.
 *
 * post-card.php is theme-overridable, so a site's copy may predate this and
 * pass no context at all. Defaulting such a render to "feed" would clamp
 * someone's permalink and point See more at the page the reader is already on
 * -- which is exactly what happened here the first time, because $args is built
 * from an explicit key list that did not yet carry context. Truncating a
 * reading surface is the worse failure, so an unknown surface shows everything.
 */
$bn_body_context  = isset( $args['context'] ) ? (string) $args['context'] : '';
$bn_is_preview    = in_array( $bn_body_context, array( 'home', 'explore', 'profile', 'space', 'bookmarks' ), true );
$bn_body_is_long  = false;

if ( $bn_is_preview && ( 'text' === $bn_body_post_type || 'activity' === $bn_body_post_type ) ) {
	/**
	 * Characters past which a post is previewed rather than shown whole.
	 *
	 * @since 1.1.3
	 *
	 * @param int    $limit   Character count. Default 300 (~one Twitter post).
	 * @param string $context Feed context the card is rendering in.
	 */
	$bn_preview_limit = (int) apply_filters( 'buddynext_post_preview_char_limit', 300, $bn_body_context );

	/**
	 * Line breaks past which a post is previewed regardless of length.
	 *
	 * A short list is tall: ten one-word lines occupy the screen a paragraph of
	 * the same character count would not, so counting characters alone misses
	 * exactly the shape that hurts most.
	 *
	 * @since 1.1.3
	 *
	 * @param int    $limit   Line-break count. Default 6.
	 * @param string $context Feed context the card is rendering in.
	 */
	$bn_preview_lines = (int) apply_filters( 'buddynext_post_preview_line_limit', 6, $bn_body_context );

	$bn_body_is_long = ( $bn_preview_limit > 0 && mb_strlen( $bn_body_content ) > $bn_preview_limit )
		|| ( $bn_preview_lines > 0 && substr_count( $bn_body_content, "\n" ) > $bn_preview_lines );
}

do_action( 'buddynext_part_post_body_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	<?php if ( ! empty( $args['has_cw'] ) ) : ?>
		data-wp-bind--class="state.bodyClass"
	<?php endif; ?>
>

	<?php if ( 'text' === $bn_body_post_type || 'activity' === $bn_body_post_type ) : ?>
		<div class="bn-post-card__content<?php echo $bn_body_is_long ? ' bn-post-card__content--preview' : ''; ?>">
			<?php
			echo wp_kses(
				nl2br( buddynext_format_content( $bn_body_content ) ),
				array(
					'br'     => array(),
					'a'      => array(
						'href'  => array(),
						'class' => array(),
					),
					'strong' => array(),
					'em'     => array(),
				)
			);
			?>
		</div>
		<?php if ( $bn_body_is_long ) : ?>
			<?php
			/*
			 * A real link, not a JS toggle.
			 *
			 * It points at the canonical permalink, so it works with JS off, it
			 * middle-clicks into a new tab, it is keyboard reachable for free, and
			 * a crawler follows it to the page that carries the full text and the
			 * Open Graph card. A button that expanded in place would have none of
			 * those properties and would restore the oversized card anyway.
			 */
			?>
			<p class="bn-post-card__more">
				<a class="bn-post-card__more-link" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::post_url( (int) $args['bn_post_id'] ) ); ?>">
					<?php esc_html_e( 'See more', 'buddynext' ); ?>
				</a>
			</p>
		<?php endif; ?>

	<?php elseif ( 'photo' === $bn_body_post_type || 'media' === $bn_body_post_type ) : ?>
		<?php if ( '' !== $bn_body_content ) : ?>
			<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?></div>
		<?php endif; ?>
			<?php
			// Media grid — BN-native markup with engine-resolved signed URLs
			// (broadcast TTL). Handles photo, video, and audio tiles by media
			// type; MediaRenderer escapes all URLs/attributes. The 'media' type
			// shares this path (mixed photo/video/audio) — BuddyNext owns the
			// UX, so there is no MediaVerse-side hydration.
			if ( ! empty( $bn_body_media_ids ) ) {
				echo \BuddyNext\Media\MediaRenderer::grid( array_map( 'absint', (array) $bn_body_media_ids ), (int) $args['bn_post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer escapes all URLs/attributes internally.
			}
			?>

	<?php elseif ( 'file' === $bn_body_post_type ) : ?>
		<?php if ( '' !== $bn_body_content ) : ?>
			<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $bn_body_media_ids ) ) : ?>
			<div class="bn-post-card__file-list">
				<?php foreach ( $bn_body_media_ids as $file_media_id ) : ?>
					<div class="bn-post-card__file-item" data-media-id="<?php echo absint( $file_media_id ); ?>">
						<span class="bn-post-card__file-icon" aria-hidden="true"><?php buddynext_icon( 'copy' ); ?></span>
						<span class="bn-post-card__file-label"><?php esc_html_e( 'Attached file', 'buddynext' ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	<?php elseif ( 'link' === $bn_body_post_type ) : ?>
		<?php if ( '' !== $bn_body_content ) : ?>
			<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?></div>
		<?php endif; ?>
		<?php
		$bn_oembed = ( '' !== $bn_link_url ) ? \BuddyNext\Feed\PostService::oembed_html( $bn_link_url ) : '';

		// Does this embed actually contain a PLAYER? Only a real provider iframe or
		// a <video> can fail silently and need the fallback layer below. Plenty of
		// providers return no player at all: WordPress' own internal-post embeds
		// (.wp-embedded-content), and the blockquote-plus-script embeds used by X,
		// Instagram and Reddit. Those carry their own visible fallback markup, so
		// painting a "video did not load" backdrop behind them is both wrong copy
		// and - because the CSS only anchors the RICH variant - an absolutely
		// positioned layer with no positioned ancestor, which escapes to .bn-app
		// and covers the entire feed. Mirrors the :has() selectors in bn-feed.css.
		$bn_oembed_has_player = ( '' !== $bn_oembed )
			&& ( false !== stripos( $bn_oembed, '<video' )
				|| 1 === preg_match( '#<iframe\b(?![^>]*\bwp-embedded-content\b)#i', $bn_oembed ) );

		if ( '' !== $bn_oembed ) :
			?>
			<div class="bn-post-card__embed bn-post-card__oembed">
				<?php
				// Fallback layer BEHIND the player. A blocked embed (ad blocker,
				// offline, blocked network request) leaves a TRANSPARENT iframe over
				// the reserved 16/9 box, so the member saw a large blank void.
				// Detection is not possible client-side - a cross-origin iframe
				// fires load, never error, even when its request was blocked - so
				// instead of detecting, the box carries a designed backdrop: a
				// working player paints over it completely; a failed one lets it
				// show through, giving the member the original link instead of
				// nothing. Deleted/region-locked videos render the provider's own
				// error UI and never reach this layer.
				?>
				<?php if ( $bn_oembed_has_player ) : ?>
				<a
					class="bn-post-card__oembed-fallback"
					href="<?php echo esc_url( $bn_link_url ); ?>"
					target="_blank"
					rel="noopener noreferrer"
					tabindex="-1"
					aria-hidden="true"
				>
					<?php echo \BuddyNext\Core\IconService::render( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'Video did not load — watch it on the original site', 'buddynext' ); ?></span>
				</a>
				<?php endif; ?>
				<?php echo $bn_oembed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress oEmbed HTML from the registered-provider allowlist. ?>
			</div>
		<?php elseif ( '' !== $bn_link_url ) : ?>
			<a
				href="<?php echo esc_url( $bn_link_url ); ?>"
				class="bn-post-card__embed bn-post-card__link-preview"
				target="_blank"
				rel="noopener noreferrer"
				aria-label="<?php echo esc_attr( '' !== $bn_link_title ? $bn_link_title : $bn_link_domain ); ?>"
			>
				<?php if ( '' !== $bn_link_thumb ) : ?>
					<div class="bn-post-card__link-thumb">
						<img
							src="<?php echo esc_url( $bn_link_thumb ); ?>"
							alt=""
							loading="lazy"
						>
					</div>
				<?php endif; ?>
				<div class="bn-post-card__link-info">
					<?php if ( '' !== $bn_link_title ) : ?>
						<p class="bn-post-card__link-title"><?php echo esc_html( $bn_link_title ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $bn_link_desc ) : ?>
						<p class="bn-post-card__link-desc"><?php echo esc_html( $bn_link_desc ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $bn_link_domain ) : ?>
						<span class="bn-post-card__link-domain"><?php echo esc_html( $bn_link_domain ); ?></span>
					<?php endif; ?>
				</div>
			</a>
		<?php endif; ?>

	<?php elseif ( 'poll' === $bn_body_post_type ) : ?>
		<?php if ( '' !== $bn_body_content ) : ?>
			<div class="bn-post-card__content bn-post-card__poll-question">
				<?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $bn_poll_options ) ) : ?>
			<div
				class="bn-post-card__poll"
				role="group"
				aria-label="<?php esc_attr_e( 'Poll options', 'buddynext' ); ?>"
			>
				<?php foreach ( $bn_poll_options as $option ) : ?>
					<?php
					$opt_id    = absint( $option['id'] );
					$opt_text  = $option['option_text'] ?? '';
					$opt_votes = absint( $option['vote_count'] );
					$opt_pct   = $bn_poll_total > 0 ? (int) round( ( $opt_votes / $bn_poll_total ) * 100 ) : 0;
					$opt_voted = ( $bn_poll_my_vote === $opt_id && $opt_id > 0 );
					?>
					<button
						type="button"
						class="bn-post-card__poll-option<?php echo $opt_voted ? ' is-voted' : ''; ?><?php echo $bn_poll_closed ? ' is-closed' : ''; ?>"
						<?php if ( ! $bn_poll_closed ) : ?>
						data-wp-context='<?php echo wp_json_encode( array( 'optionId' => $opt_id ) ); ?>'
						data-wp-bind--class="state.pollOptionBtnClass"
						data-wp-on--click="actions.votePoll"
						<?php else : ?>
						disabled
						<?php endif; ?>
						data-option-id="<?php echo absint( $opt_id ); ?>"
						aria-label="<?php echo esc_attr( sprintf( '%s — %d%%', $opt_text, $opt_pct ) ); ?>"
					>
						<div
							class="bn-post-card__poll-fill"
							style="width:<?php echo absint( $opt_pct ); ?>%"
							data-wp-bind--style="state.pollFillStyle"
							aria-hidden="true"
						></div>
						<span class="bn-post-card__poll-option-text"><?php echo esc_html( $opt_text ); ?></span>
						<span
							class="bn-post-card__poll-pct"
							data-wp-text="state.pollOptionPctText"
							aria-hidden="true"
						><?php echo absint( $opt_pct ); ?>%</span>
					</button>
				<?php endforeach; ?>
				<p
					class="bn-post-card__poll-total"
					data-wp-text="state.pollTotalVotesText"
				>
					<?php
					/* translators: %d: number of votes. */
					echo esc_html( sprintf( _n( '%d vote', '%d votes', $bn_poll_total, 'buddynext' ), $bn_poll_total ) );
					?>
				</p>
				<?php if ( $bn_poll_closed ) : ?>
					<p class="bn-post-card__poll-closed">
						<?php buddynext_icon( 'lock' ); ?>
						<span><?php esc_html_e( 'Poll closed', 'buddynext' ); ?></span>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

	<?php elseif ( 'announcement' === $bn_body_post_type ) : ?>
		<div class="bn-post-card__content bn-post-card__content--announcement">
			<?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?>
		</div>

	<?php elseif ( 'discussion' === $bn_body_post_type ) : ?>
		<?php
		// Show the discussion topic title (carried in link_meta) and link to the
		// thread — not the activity verb. The source label stays generic; the
		// underlying discussion engine is never named on the front end.
		$bn_disc_title = '' !== $bn_link_title ? $bn_link_title : wp_trim_words( wp_strip_all_tags( $bn_body_content ), 14 );
		?>
		<div class="bn-post-card__bridge-card bn-post-card__bridge-card--discussion">
			<span class="bn-post-card__bridge-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
			<div class="bn-post-card__bridge-content">
				<span class="bn-post-card__bridge-source"><?php esc_html_e( 'Discussion', 'buddynext' ); ?></span>
				<?php if ( '' !== $bn_link_url ) : ?>
					<a class="bn-post-card__bridge-title" href="<?php echo esc_url( $bn_link_url ); ?>"><?php echo esc_html( $bn_disc_title ); ?></a>
				<?php else : ?>
					<span class="bn-post-card__bridge-title"><?php echo esc_html( $bn_disc_title ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $bn_link_desc ) : ?>
					<p class="bn-post-card__bridge-text"><?php echo esc_html( $bn_link_desc ); ?></p>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( 'share' === $bn_body_post_type ) : ?>
		<?php if ( '' !== $bn_body_content ) : ?>
			<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?></div>
		<?php endif; ?>
		<?php if ( null !== $bn_shared_post ) : ?>
			<?php
			$orig_author         = get_userdata( (int) ( $bn_shared_post['user_id'] ?? 0 ) );
			$orig_name           = $orig_author ? esc_html( $orig_author->display_name ) : esc_html__( 'Community Member', 'buddynext' );
			$orig_username       = $orig_author ? esc_html( $orig_author->user_nicename ) : '';
			$orig_avatar         = get_avatar_url( (int) ( $bn_shared_post['user_id'] ?? 0 ), array( 'size' => 40 ) );
			$orig_time           = buddynext_time_ago( (string) ( $bn_shared_post['created_at'] ?? '' ) );
			$orig_content        = $bn_shared_post['content'] ?? '';
			$orig_post_url       = PageRouter::profile_url( (int) ( $bn_shared_post['user_id'] ?? 0 ) );
				$orig_single_url = PageRouter::post_url( (int) ( $bn_shared_post['id'] ?? 0 ) );
			$orig_initials       = AvatarService::initials_for( (string) $orig_name );
			?>
			<blockquote class="bn-post-card__shared bn-post-card__shared-embed" role="article" aria-label="<?php esc_attr_e( 'Shared post', 'buddynext' ); ?>">
				<div class="bn-post-card__shared-header">
					<a href="<?php echo esc_url( $orig_post_url ); ?>" class="bn-post-card__shared-avatar-link" aria-hidden="true">
						<?php if ( $orig_avatar ) : ?>
							<span class="bn-avatar" data-size="sm">
								<img src="<?php echo esc_url( $orig_avatar ); ?>" alt="<?php echo esc_attr( $orig_name ); ?>" width="32" height="32">
							</span>
						<?php else : ?>
							<span class="bn-avatar" data-size="sm"><?php echo esc_html( $orig_initials ); ?></span>
						<?php endif; ?>
					</a>
					<div class="bn-post-card__shared-meta">
						<a href="<?php echo esc_url( $orig_post_url ); ?>" class="bn-post-card__shared-name"><?php echo esc_html( $orig_name ); ?></a>
						<span class="bn-post-card__shared-sub">
							<?php if ( $orig_username ) : ?>
								<span class="bn-post-card__shared-username">@<?php echo esc_html( $orig_username ); ?></span>
								<span class="bn-post-card__sep" aria-hidden="true">&middot;</span>
							<?php endif; ?>
							<span class="bn-post-card__shared-time"><?php echo esc_html( $orig_time ); ?></span>
						</span>
					</div>
				</div>
				<?php
				// A reshare must preview the ORIGINAL beyond its text, or resharing a
				// photo or a YouTube/link post renders as an empty quote. Resolve a
				// thumbnail (first attachment, else the link/video oEmbed thumbnail)
				// and a link headline from the shared post's hydrated fields.
				$orig_type      = (string) ( $bn_shared_post['type'] ?? '' );
				$orig_media_ids = $bn_shared_post['media_ids'] ?? array();
				if ( is_string( $orig_media_ids ) ) {
					$orig_media_ids = json_decode( $orig_media_ids, true );
				}
				$orig_link_meta = $bn_shared_post['link_meta'] ?? array();
				if ( is_string( $orig_link_meta ) ) {
					$orig_link_meta = json_decode( $orig_link_meta, true );
				}
				$orig_thumb      = '';
				$orig_link_title = is_array( $orig_link_meta ) ? trim( (string) ( $orig_link_meta['title'] ?? '' ) ) : '';

				if ( is_array( $orig_media_ids ) && ! empty( $orig_media_ids ) && class_exists( '\BuddyNext\Media\MediaUrlResolver' ) ) {
					$orig_desc = \BuddyNext\Media\MediaUrlResolver::descriptor( (int) $orig_media_ids[0] );
					if ( $orig_desc ) {
						$orig_thumb = (string) ( '' !== $orig_desc['thumb'] ? $orig_desc['thumb'] : $orig_desc['url'] );
					}
				}
				if ( '' === $orig_thumb && is_array( $orig_link_meta ) && ! empty( $orig_link_meta['thumbnail'] ) ) {
					$orig_thumb = (string) $orig_link_meta['thumbnail'];
				}
				$orig_has_text = '' !== trim( (string) $orig_content );
				?>
				<a class="bn-post-card__shared-content-link" href="<?php echo esc_url( $orig_single_url ); ?>">
					<?php if ( $orig_has_text ) : ?>
						<span class="bn-post-card__shared-content"><?php echo wp_kses_post( nl2br( wp_trim_words( $orig_content, 60 ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $orig_thumb ) : ?>
						<span class="bn-post-card__shared-thumb">
							<img src="<?php echo esc_url( $orig_thumb ); ?>" alt="" loading="lazy" decoding="async">
							<?php if ( 'link' === $orig_type ) : ?>
								<span class="bn-post-card__shared-play" aria-hidden="true"><?php buddynext_icon( 'play' ); ?></span>
							<?php endif; ?>
						</span>
					<?php endif; ?>
					<?php if ( '' !== $orig_link_title ) : ?>
						<span class="bn-post-card__shared-linktitle"><?php echo esc_html( wp_trim_words( $orig_link_title, 18, '…' ) ); ?></span>
					<?php endif; ?>
					<?php if ( ! $orig_has_text && '' === $orig_thumb && '' === $orig_link_title ) : ?>
						<span class="bn-post-card__shared-empty"><?php esc_html_e( 'View original post', 'buddynext' ); ?></span>
					<?php endif; ?>
					<span class="bn-post-card__shared-viewlink"><?php esc_html_e( 'View activity', 'buddynext' ); ?></span>
				</a>
			</blockquote>
		<?php else : ?>
			<div class="bn-post-card__shared-missing">
				<span aria-hidden="true"><?php buddynext_icon( 'share' ); ?></span>
				<p><?php esc_html_e( 'Original post is no longer available.', 'buddynext' ); ?></p>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<?php
		/**
		 * Typed integration-card seam. A bridge registers
		 * `buddynext_render_post_body_{$type}` returning the card's inner HTML
		 * (already escaped) so an add-on can render a premium typed card — event,
		 * and later course / media / achievement — through the feed without forking
		 * this template. Existing types keep their own branches above; only unknown
		 * types reach here. An empty return falls through to the plain-text body, so
		 * a registered-but-declining renderer degrades gracefully.
		 *
		 * @param string $html The card HTML to output (default '').
		 * @param array  $args The full post-body args (post, id, link_meta, content).
		 */
		$bn_typed_html = (string) apply_filters( 'buddynext_render_post_body_' . $bn_body_post_type, '', $args );
		if ( '' !== $bn_typed_html ) {
			echo $bn_typed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns pre-escaped HTML.
		} else {
			?>
			<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?></div>
			<?php
		}
		?>
	<?php endif; ?>

	<?php
	/*
	 * Attached media on a post whose own branch above does not present it.
	 *
	 * Media is orthogonal to post type everywhere except here. PostService::create()
	 * accepts media_ids on ANY allowed type — media alone even satisfies its
	 * not-empty gate — authorizes them, stores them and links them in the media
	 * table. Only the render treated media as belonging to two types, so a post
	 * could be created with media, pass every check, and show the member nothing.
	 *
	 * Three reachable ways in, none of them hypothetical:
	 *   - the composer promotes photo|text to 'photo' when media is attached, but
	 *     ONLY those two, so announcement + media stays 'announcement';
	 *   - the importer types a migrated blog post as its blog type and attaches
	 *     media in the same call, so migrated post media never rendered;
	 *   - any REST client may post a valid type with media_ids.
	 *
	 * Rendered here rather than added to each branch so the rule is one rule: a new
	 * post type gets its media shown by existing, instead of silently dropping it
	 * until someone notices. Types listed below already present their own media —
	 * photo/media as the grid, file as the file list — and are the only exclusions.
	 */
	if ( ! empty( $bn_body_media_ids ) && ! in_array( $bn_body_post_type, array( 'photo', 'media', 'file' ), true ) ) {
		echo \BuddyNext\Media\MediaRenderer::grid( array_map( 'absint', (array) $bn_body_media_ids ), (int) $args['bn_post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer escapes all URLs/attributes internally.
	}
	?>

</div><!-- .bn-post-card__body -->
<?php
do_action( 'buddynext_part_post_body_after', $args );
