<?php
/**
 * BuddyNext template part: dm-message.
 *
 * Renders a single message bubble inside the thread body: optional
 * avatar, optional quoted-reply preview, the body, time + read-receipt,
 * and any emoji reactions.
 *
 * Used by: templates/parts/dm-thread-messages.php.
 *
 * @package BuddyNext
 *
 * @var array  $message         Required. MVS message row.
 * @var int    $current_user_id Required. Viewing user ID.
 * @var int    $thread_tone        Optional. Avatar tone slot 1..8 for the other user. Default 1.
 * @var string $thread_initials    Optional. Two-character initials for the other user. Default ''.
 * @var string $thread_avatar_html Optional. The other user's get_avatar() markup; rendered in the
 *                                 bubble avatar when it carries a real image (matching the thread
 *                                 header), else falls back to initials. Default ''.
 * @var array  $classes            Optional. Extra CSS classes on the message wrapper.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_message_before', $args )
 *   - do_action( 'buddynext_part_dm_message_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_message_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_message_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'message'            => isset( $message ) ? (array) $message : array(),
	'current_user_id'    => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'thread_tone'        => isset( $thread_tone ) ? (int) $thread_tone : 1,
	'thread_initials'    => isset( $thread_initials ) ? (string) $thread_initials : '',
	'thread_avatar_html' => isset( $thread_avatar_html ) ? (string) $thread_avatar_html : '',
	'classes'            => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_message_args', $args );

if ( empty( $args['message'] ) ) {
	return;
}

$msg         = (array) $args['message'];
$viewer      = (int) $args['current_user_id'];
$th_tone     = (int) $args['thread_tone'];
$th_initials = (string) $args['thread_initials'];
$th_avatar   = (string) $args['thread_avatar_html'];

$msg_id      = absint( $msg['id'] ?? 0 );
$msg_body    = wp_kses(
	$msg['body'] ?? '',
	array(
		'br'     => array(),
		'em'     => array(),
		'strong' => array(),
	)
);
$msg_at      = $msg['created_at'] ?? '';
$msg_uid     = absint( $msg['sender_id'] ?? 0 );
$is_mine     = $msg_uid === $viewer;
$reactions   = (array) ( $msg['reactions'] ?? array() );
$reply_to    = $msg['reply_to'] ?? null;
$media       = is_array( $msg['media'] ?? null ) ? $msg['media'] : null;
$read_by_all = ! empty( $msg['read_by_recipient'] );

$msg_ts = is_numeric( $msg_at ) ? (int) $msg_at : strtotime( (string) $msg_at );
$clock  = '' === (string) $msg_at ? '' : wp_date( get_option( 'time_format', 'g:i A' ), $msg_ts );
$iso    = '' === (string) $msg_at ? '' : gmdate( 'c', $msg_ts );

$bn_classes = array_merge( array( 'bn-dm-msg' ), array_filter( (array) $args['classes'], 'is_string' ) );
if ( $is_mine ) {
	$bn_classes[] = 'is-mine';
}
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_message_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_dm_message_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" data-msg-id="<?php echo esc_attr( (string) $msg_id ); ?>">
	<?php if ( ! $is_mine ) : ?>
		<span class="bn-avatar bn-dm-avatar bn-dm-tone-<?php echo (int) $th_tone; ?>" data-size="sm" aria-hidden="true">
			<?php if ( false !== strpos( $th_avatar, 'src=' ) ) : ?>
				<?php
				// Same real-image branch the thread header uses, so the recipient
				// shows one consistent avatar across header, list, and every bubble.
				echo wp_kses(
					$th_avatar,
					array(
						'img' => array(
							'src'      => true,
							'class'    => true,
							'alt'      => true,
							'width'    => true,
							'height'   => true,
							'loading'  => true,
							'decoding' => true,
						),
					)
				);
				?>
			<?php else : ?>
				<span class="bn-avatar__initials"><?php echo esc_html( $th_initials ); ?></span>
			<?php endif; ?>
		</span>
	<?php endif; ?>

	<div class="bn-dm-msg__content">
		<div class="bn-dm-bubble<?php echo $is_mine ? ' is-mine' : ''; ?><?php echo $media ? ' bn-dm-bubble--has-media' : ''; ?>">
			<?php if ( is_array( $reply_to ) && ! empty( $reply_to['body'] ) ) : ?>
				<div class="bn-dm-bubble__quoted">
					<?php echo esc_html( wp_trim_words( sanitize_text_field( $reply_to['body'] ), 15 ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $media ) : ?>
				<?php
				$bn_m_id    = (int) ( $media['id'] ?? 0 );
				$bn_m_type  = (string) ( $media['type'] ?? 'file' );
				$bn_m_thumb = (string) ( $media['thumbnail'] ?? '' );
				$bn_m_url   = (string) ( $media['url'] ?? '' );
				$bn_m_title = (string) ( $media['title'] ?? '' );
				?>
				<div class="bn-dm-bubble__media" data-type="<?php echo esc_attr( $bn_m_type ); ?>">
					<?php if ( ( 'image' === $bn_m_type || 'video' === $bn_m_type ) && $bn_m_id > 0 && ( '' !== $bn_m_thumb || '' !== $bn_m_url ) ) : ?>
						<?php // Canonical BN media tile: the shared lightbox (assets/js/media/lightbox.js) binds to .bn-media-tile[data-bn-media-id] and opens it in-page — the same uniform lightbox as feed/media tab, not a new browser tab. ?>
						<button type="button" class="bn-media-tile bn-media-tile--<?php echo esc_attr( $bn_m_type ); ?>" data-bn-media-id="<?php echo esc_attr( (string) $bn_m_id ); ?>" data-media-type="<?php echo esc_attr( $bn_m_type ); ?>" data-media-src="<?php echo esc_url( '' !== $bn_m_url ? $bn_m_url : $bn_m_thumb ); ?>">
							<img class="bn-media-tile__img" src="<?php echo esc_url( '' !== $bn_m_thumb ? $bn_m_thumb : $bn_m_url ); ?>" alt="<?php echo esc_attr( $bn_m_title ); ?>" loading="lazy" decoding="async">
						</button>
					<?php else : ?>
						<a class="bn-dm-bubble__file" href="<?php echo esc_url( '' !== ( (string) ( $media['download'] ?? '' ) ) ? (string) $media['download'] : $bn_m_url ); ?>" target="_blank" rel="noopener">
							<?php buddynext_icon( 'paperclip' ); ?>
							<span><?php echo esc_html( '' !== $bn_m_title ? $bn_m_title : __( 'Attachment', 'buddynext' ) ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php echo $msg_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already filtered via wp_kses above. ?>
		</div>

		<?php buddynext_get_template( 'parts/dm-msg-actions.php' ); ?>

		<div class="bn-dm-msg__meta">
			<time class="bn-dm-msg__time" datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $clock ); ?></time>
			<?php if ( $is_mine && $read_by_all ) : ?>
				<span class="bn-dm-msg__receipt" aria-label="<?php esc_attr_e( 'Read', 'buddynext' ); ?>">
					<?php buddynext_icon( 'check-double' ); ?>
					<span><?php esc_html_e( 'Read', 'buddynext' ); ?></span>
				</span>
			<?php endif; ?>
		</div>

		<?php // Reaction chips. Container always present so the store can append the first chip client-side. ?>
		<div class="bn-dm-msg__reactions"<?php echo empty( $reactions ) ? ' hidden' : ''; ?>>
			<?php foreach ( $reactions as $bn_re ) : ?>
				<?php
				$bn_re_slug  = (string) ( $bn_re['slug'] ?? '' );
				$bn_re_count = (int) ( $bn_re['count'] ?? 0 );
				$bn_re_mine  = ! empty( $bn_re['mine'] );
				if ( '' === $bn_re_slug ) {
					continue;
				}
				?>
				<button
					type="button"
					class="bn-dm-msg__reaction<?php echo $bn_re_mine ? ' is-mine' : ''; ?>"
					data-bn-action="react-toggle"
					data-slug="<?php echo esc_attr( $bn_re_slug ); ?>"
					aria-pressed="<?php echo $bn_re_mine ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( ucfirst( $bn_re_slug ) . ' (' . $bn_re_count . ')' ); ?>"
				>
					<?php echo \BuddyNext\Core\IconService::render_emoji( $bn_re_slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-sanitized SVG. ?>
					<span class="bn-dm-msg__reaction-count"><?php echo (int) $bn_re_count; ?></span>
				</button>
			<?php endforeach; ?>
		</div>
	</div>
</div>
<?php
do_action( 'buddynext_part_dm_message_after', $args );
