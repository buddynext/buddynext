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
 * @var array       $link_meta            Decoded link_meta JSON (used by the event
 *                                        branch: { title, location, event_at }).
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

do_action( 'buddynext_part_post_body_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	<?php if ( ! empty( $args['has_cw'] ) ) : ?>
		data-wp-bind--class="state.bodyClass"
	<?php endif; ?>
>

	<?php if ( 'text' === $bn_body_post_type || 'activity' === $bn_body_post_type ) : ?>
		<div class="bn-post-card__content">
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
				echo \BuddyNext\Media\MediaRenderer::grid( array_map( 'absint', (array) $bn_body_media_ids ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer escapes all URLs/attributes internally.
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
		if ( '' !== $bn_oembed ) :
		?>
			<div class="bn-post-card__embed bn-post-card__oembed">
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
					/* translators: %d: total vote count */
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

	<?php elseif ( 'job' === $bn_body_post_type ) : ?>
		<div class="bn-post-card__bridge-card bn-post-card__bridge-card--job">
			<span class="bn-post-card__bridge-icon" aria-hidden="true"><?php buddynext_icon( 'briefcase' ); ?></span>
			<div class="bn-post-card__bridge-content">
				<span class="bn-post-card__bridge-source"><?php esc_html_e( 'Job Listing', 'buddynext' ); ?></span>
				<p class="bn-post-card__bridge-text"><?php echo wp_kses_post( wp_trim_words( $bn_body_content, 20 ) ); ?></p>
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
		<div class="bn-post-card__content"><?php echo wp_kses_post( nl2br( buddynext_format_content( $bn_body_content ) ) ); ?></div>
	<?php endif; ?>

</div><!-- .bn-post-card__body -->
<?php
do_action( 'buddynext_part_post_body_after', $args );
