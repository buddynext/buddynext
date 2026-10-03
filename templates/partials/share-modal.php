<?php
/**
 * BuddyNext — Post share modal.
 *
 * Rendered once per page in the feed shell. The post-card store opens it by
 * setting the global `buddynext/share-modal` state with the source post ID,
 * permalink, and counts. Provides three CTAs: repost, quote, copy link.
 *
 * Variables (optional):
 *   int $current_user_id  Active viewer.
 *
 * @package BuddyNext
 * @since   1.4.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$share_modal_user_id = absint( $current_user_id ?? get_current_user_id() );
// Guests get the dialog too: a public post can be shared by anyone who can see it.
$share_modal_nonce = $share_modal_user_id > 0 ? wp_create_nonce( 'wp_rest' ) : '';

/**
 * Filter the networks offered for sharing a public post outside the community.
 *
 * Each entry is an icon slug, a label and a link template; {url} and {text} are
 * replaced (URL-encoded) with the post link and a short excerpt. Return an empty
 * array to offer only Copy link.
 *
 * @since 1.2.4
 *
 * @param array<string,array{icon:string,label:string,url:string}> $networks Keyed by network.
 */
$share_modal_networks  = (array) apply_filters(
	'buddynext_share_networks',
	array(
		'x'        => array(
			'icon'  => 'brand-x',
			'label' => __( 'X', 'buddynext' ),
			'url'   => 'https://x.com/intent/post?url={url}&text={text}',
		),
		'facebook' => array(
			'icon'  => 'facebook',
			'label' => __( 'Facebook', 'buddynext' ),
			'url'   => 'https://www.facebook.com/sharer/sharer.php?u={url}',
		),
		'linkedin' => array(
			'icon'  => 'linkedin',
			'label' => __( 'LinkedIn', 'buddynext' ),
			'url'   => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
		),
		'whatsapp' => array(
			'icon'  => 'whatsapp',
			'label' => __( 'WhatsApp', 'buddynext' ),
			'url'   => 'https://wa.me/?text={text}%20{url}',
		),
		'email'    => array(
			'icon'  => 'mail',
			'label' => __( 'Email', 'buddynext' ),
			'url'   => 'mailto:?subject={text}&body={url}',
		),
	)
);
$share_modal_templates = array();
foreach ( $share_modal_networks as $share_modal_key => $share_modal_net ) {
	$share_modal_templates[ sanitize_key( (string) $share_modal_key ) ] = (string) ( $share_modal_net['url'] ?? '' );
}
?>
<div class="bn-modal-backdrop bn-share-modal"
	hidden
	data-wp-interactive="buddynext/share-modal"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'open'      => false,
				'postId'    => 0,
				'permalink' => '',
				'author'    => '',
				'excerpt'   => '',
				'note'      => '',
				'busy'      => false,
				'error'     => '',
				'restUrl'   => rest_url( 'buddynext/v1' ),
				'nonce'     => $share_modal_nonce,
				'canRepost' => false,
				'shareable' => false,
				'templates' => (object) $share_modal_templates,
				'links'     => (object) array(),
			)
		)
	);
	?>
	'
	data-wp-bind--hidden="!state.open"
	data-wp-on-document--bn-open-share-modal="actions.receiveOpen">
	<div
		class="bn-modal__panel bn-share-modal__panel"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-share-modal-title"
		data-size="sm">
		<div class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-share-modal-title">
				<?php esc_html_e( 'Share post', 'buddynext' ); ?>
			</h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.close">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body bn-share-modal__body">
			<div class="bn-share-modal__preview" hidden data-wp-bind--hidden="state.hasNoPreview">
				<span class="bn-share-modal__preview-author" data-wp-text="state.author"></span>
				<span class="bn-share-modal__preview-excerpt" data-wp-text="state.excerpt"></span>
			</div>
			<?php if ( ! empty( $share_modal_templates ) ) : ?>
			<div class="bn-share-modal__out" hidden data-wp-bind--hidden="!context.shareable">
				<span class="bn-share-modal__out-label"><?php esc_html_e( 'Share to', 'buddynext' ); ?></span>
				<div class="bn-share-modal__nets">
					<?php
					foreach ( $share_modal_networks as $share_modal_key => $share_modal_net ) :
						$share_modal_key = sanitize_key( (string) $share_modal_key );
						?>
					<a class="bn-share-modal__net"
						href="#"
						target="_blank"
						rel="noopener noreferrer"
						data-wp-bind--href="context.links.<?php echo esc_attr( $share_modal_key ); ?>">
						<?php buddynext_icon( (string) ( $share_modal_net['icon'] ?? 'share-2' ) ); ?>
						<span><?php echo esc_html( (string) ( $share_modal_net['label'] ?? $share_modal_key ) ); ?></span>
					</a>
					<?php endforeach; ?>
					<button type="button"
						class="bn-share-modal__net"
						hidden
						data-wp-bind--hidden="state.cannotNativeShare"
						data-wp-on--click="actions.nativeShare">
						<?php buddynext_icon( 'share-2' ); ?>
						<span><?php esc_html_e( 'More', 'buddynext' ); ?></span>
					</button>
				</div>
			</div>
			<?php endif; ?>
			<div class="bn-share-modal__repost-block" hidden data-wp-bind--hidden="!context.canRepost">
			<textarea
				class="bn-share-modal__note"
				rows="3"
				placeholder="<?php esc_attr_e( 'Add a comment (optional)', 'buddynext' ); ?>"
				aria-label="<?php esc_attr_e( 'Add a comment (optional)', 'buddynext' ); ?>"
				data-wp-on--input="actions.onNoteInput"
				data-wp-bind--disabled="state.busy"></textarea>
			</div>
			<div class="bn-share-modal__actions">
				<button type="button"
					hidden
					data-wp-bind--hidden="!context.canRepost"
					class="bn-btn bn-share-modal__repost"
					data-variant="primary"
					data-size="md"
					data-wp-on--click="actions.repost"
					data-wp-bind--disabled="state.busy">
					<?php buddynext_icon( 'share' ); ?>
					<span data-wp-text="state.repostLabel"><?php esc_html_e( 'Repost', 'buddynext' ); ?></span>
				</button>
				<button type="button"
					class="bn-btn bn-share-modal__copy"
					data-variant="secondary"
					data-size="md"
					data-wp-on--click="actions.copyLink"
					data-wp-bind--disabled="state.busy">
					<?php buddynext_icon( 'link' ); ?>
					<span><?php esc_html_e( 'Copy link', 'buddynext' ); ?></span>
				</button>
			</div>
			<p class="bn-share-modal__error"
				role="alert"
				hidden
				data-wp-bind--hidden="state.hasNoError">
				<span data-wp-text="state.error"></span>
			</p>
			</div>
	</div>
</div>
