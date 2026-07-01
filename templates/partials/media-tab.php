<?php
/**
 * Profile Media tab — unified BuddyNext island (`buddynext/media-albums`).
 *
 * Provides the Media | Albums sub-nav and the full albums surface (cards, create
 * modal, detail view, add-media picker). The upload composer keeps its own
 * self-contained island (`buddynext/media`) nested inside the Media view; the
 * gallery region is refreshed by that composer. Engine access is via the
 * BuddyNext album endpoints only — no WPMediaVerse markup/CSS/JS.
 *
 * @var int   $bn_mt_owner_id  Profile owner user id.
 * @var bool  $bn_mt_is_owner  Whether the viewer owns this profile.
 * @var int[] $bn_mt_media_ids Initial media ids for the gallery (server-rendered).
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$bn_mt_owner_id  = isset( $bn_mt_owner_id ) ? (int) $bn_mt_owner_id : 0;
$bn_mt_is_owner  = isset( $bn_mt_is_owner ) ? (bool) $bn_mt_is_owner : false;
$bn_mt_media_ids = isset( $bn_mt_media_ids ) ? (array) $bn_mt_media_ids : array();
// Owner control: the Albums sub-view can be hidden via BuddyNext -> Integrations
// (Media -> Albums sub-tab). Default on. When off, only the flat Media gallery shows.
$bn_mt_albums_enabled = ! isset( $bn_mt_albums_enabled ) || (bool) $bn_mt_albums_enabled;

$bn_mt_ctx = array(
	'restNonce'          => wp_create_nonce( 'wp_rest' ),
	'ownerId'            => $bn_mt_owner_id,
	'isOwner'            => $bn_mt_is_owner,
	'view'               => 'media',
	'albums'             => array(),
	'hasAlbums'          => false,
	'albumsLoaded'       => false,
	'albumOpen'          => false,
	'activeAlbumId'      => 0,
	'activeAlbumTitle'   => '',
	'activeAlbumCount'   => '',
	'activeAlbumDesc'    => '',
	'activeAlbumPrivacy' => 'public',
	'createOpen'         => false,
	'editingAlbumId'     => 0,
	'creating'           => false,
	'createTitle'        => '',
	'createDesc'         => '',
	'createPrivacy'      => 'public',
	'pickerOpen'         => false,
	't'                  => array(
		'oneItem'            => __( '1 item', 'buddynext' ),
		/* translators: %d: number of items in an album. */
		'nItems'             => __( '%d items', 'buddynext' ),
		'albumCreated'       => __( 'Album created.', 'buddynext' ),
		'albumSaved'         => __( 'Album updated.', 'buddynext' ),
		'createFailed'       => __( 'Could not save the album.', 'buddynext' ),
		'confirmDeleteAlbum' => __( 'Delete this album? The photos stay in your media.', 'buddynext' ),
		'delete'             => __( 'Delete', 'buddynext' ),
		'albumDeleted'       => __( 'Album deleted.', 'buddynext' ),
		'deleteFailed'       => __( 'Could not delete the album.', 'buddynext' ),
		'setCover'           => __( 'Set as cover', 'buddynext' ),
		'coverSet'           => __( 'Cover updated.', 'buddynext' ),
		'coverFailed'        => __( 'Could not set the cover.', 'buddynext' ),
		'added'              => __( 'Added to album.', 'buddynext' ),
		'addFailed'          => __( 'Could not add media.', 'buddynext' ),
		'emptyAlbum'         => __( 'This album is empty.', 'buddynext' ),
		'removeFromAlbum'    => __( 'Remove from album', 'buddynext' ),
		'confirmRemove'      => __( 'Remove this from the album?', 'buddynext' ),
		'removedFromAlbum'   => __( 'Removed from album.', 'buddynext' ),
		'removeFailed'       => __( 'Could not remove.', 'buddynext' ),
	),
);
?>
<div class="bn-media-shell"
	data-wp-interactive="buddynext/media-albums"
	<?php
	echo wp_interactivity_data_wp_context( $bn_mt_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns an escaped attribute.
	// Skip the albums init (no REST load) when the owner has hidden the Albums sub-tab.
	if ( $bn_mt_albums_enabled ) {
		echo ' data-wp-init="callbacks.initAlbums"';
	}
	?>
	>

	<?php if ( $bn_mt_albums_enabled ) : ?>
	<div class="bn-media-subnav" role="tablist" aria-label="<?php esc_attr_e( 'Media views', 'buddynext' ); ?>">
		<button type="button" class="bn-media-subnav__tab" role="tab"
			data-wp-bind--aria-selected="state.viewIsMedia"
			data-wp-class--bn-media-subnav__tab--active="state.viewIsMedia"
			data-wp-on--click="actions.showMedia"><?php esc_html_e( 'Media', 'buddynext' ); ?></button>
		<button type="button" class="bn-media-subnav__tab" role="tab"
			data-wp-bind--aria-selected="state.viewIsAlbums"
			data-wp-class--bn-media-subnav__tab--active="state.viewIsAlbums"
			data-wp-on--click="actions.showAlbums"><?php esc_html_e( 'Albums', 'buddynext' ); ?></button>
	</div>
	<?php endif; ?>

	<?php // ── MEDIA VIEW ─────────────────────────────────────────────────────── ?>
	<div class="bn-media-view" data-wp-bind--hidden="!state.viewIsMedia">
		<?php
		if ( $bn_mt_is_owner ) {
			buddynext_get_template( 'partials/media-upload-composer.php', array( 'bn_mu_owner_id' => $bn_mt_owner_id ) );
		}
		echo '<div class="bn-media-grid-region" data-bn-media-region data-bn-owner="' . esc_attr( $bn_mt_is_owner ? '1' : '0' ) . '">';
		if ( ! empty( $bn_mt_media_ids ) ) {
			echo '<div class="bn-card bn-profile-media-card">';
			echo \BuddyNext\Media\MediaRenderer::gallery( array_map( 'absint', $bn_mt_media_ids ), array( 'user_id' => $bn_mt_owner_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer emits pre-sanitized markup.
			echo '</div>';
		} else {
			?>
			<div class="bn-empty-state">
				<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'image' ); ?></div>
				<div class="bn-empty-title"><?php esc_html_e( 'No media uploaded yet.', 'buddynext' ); ?></div>
			</div>
			<?php
		}
		echo '</div>';
		?>
	</div>

	<?php // ── ALBUMS VIEW ────────────────────────────────────────────────────── ?>
	<div class="bn-media-view" data-wp-bind--hidden="!state.viewIsAlbums">

		<div class="bn-album-list" data-wp-bind--hidden="state.albumOpen">
			<?php if ( $bn_mt_is_owner ) : ?>
			<div class="bn-album-list__head">
				<button type="button" class="bn-btn" data-variant="primary" data-wp-on--click="actions.openCreateAlbum">
					<span class="bn-btn__icon" aria-hidden="true"><?php buddynext_icon( 'folder-plus' ); ?></span>
					<?php esc_html_e( 'New album', 'buddynext' ); ?>
				</button>
			</div>
			<?php endif; ?>

			<div class="bn-album-grid">
				<template data-wp-each="context.albums" data-wp-each-key="context.item.id">
					<button type="button" class="bn-album-card"
						data-wp-bind--data-album-id="context.item.id"
						data-wp-on--click="actions.openAlbum">
						<span class="bn-album-card__cover">
							<img class="bn-album-card__img" alt="" decoding="async"
								data-wp-bind--hidden="!context.item.hasCover"
								data-wp-bind--src="context.item.cover" />
							<span class="bn-album-card__ph" aria-hidden="true"
								data-wp-bind--hidden="context.item.hasCover"><?php buddynext_icon( 'image' ); ?></span>
						</span>
						<span class="bn-album-card__title" data-wp-text="context.item.title"></span>
						<span class="bn-album-card__count" data-wp-text="context.item.countLabel"></span>
					</button>
				</template>
			</div>

			<div class="bn-empty-state" data-wp-bind--hidden="state.hasAlbums">
				<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'folder' ); ?></div>
				<div class="bn-empty-title"><?php esc_html_e( 'No albums yet.', 'buddynext' ); ?></div>
			</div>
		</div>

		<div class="bn-album-detail" data-wp-bind--hidden="!state.albumOpen">
			<div class="bn-album-detail__head">
				<button type="button" class="bn-btn" data-variant="ghost" data-wp-on--click="actions.closeAlbum">
					<span class="bn-btn__icon" aria-hidden="true"><?php buddynext_icon( 'arrow-left' ); ?></span>
					<?php esc_html_e( 'Albums', 'buddynext' ); ?>
				</button>
				<span class="bn-album-detail__title" data-wp-text="context.activeAlbumTitle"></span>
				<span class="bn-album-detail__count" data-wp-text="context.activeAlbumCount"></span>
				<?php if ( $bn_mt_is_owner ) : ?>
				<div class="bn-album-detail__actions">
					<button type="button" class="bn-btn" data-variant="secondary" data-wp-on--click="actions.openAddMedia">
						<?php esc_html_e( 'Add media', 'buddynext' ); ?>
					</button>
					<button type="button" class="bn-btn" data-variant="ghost" data-wp-on--click="actions.openEditAlbum">
						<?php esc_html_e( 'Edit', 'buddynext' ); ?>
					</button>
					<button type="button" class="bn-btn" data-variant="ghost" data-wp-on--click="actions.deleteAlbum">
						<?php esc_html_e( 'Delete', 'buddynext' ); ?>
					</button>
				</div>
				<?php endif; ?>
			</div>
			<div class="bn-album-detail__grid" data-bn-album-grid></div>
		</div>
	</div>

	<?php if ( $bn_mt_is_owner ) : ?>
		<?php // ── CREATE ALBUM MODAL ─────────────────────────────────────────────── ?>
	<div class="bn-modal-backdrop bn-album-modal is-hidden" data-wp-class--is-hidden="!context.createOpen">
		<div class="bn-modal__panel" data-size="sm" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'New album', 'buddynext' ); ?>">
			<div class="bn-modal__head">
				<h3 class="bn-modal__title">
					<span data-wp-bind--hidden="state.isEditing"><?php esc_html_e( 'New album', 'buddynext' ); ?></span>
					<span data-wp-bind--hidden="!state.isEditing"><?php esc_html_e( 'Edit album', 'buddynext' ); ?></span>
				</h3>
				<button type="button" class="bn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-wp-on--click="actions.closeCreateAlbum">&times;</button>
			</div>
			<div class="bn-modal__body">
				<label class="bn-field">
					<span class="bn-field__label"><?php esc_html_e( 'Name', 'buddynext' ); ?></span>
					<input class="bn-input" type="text" maxlength="120"
						placeholder="<?php esc_attr_e( 'e.g. Summer trip', 'buddynext' ); ?>"
						data-wp-bind--value="context.createTitle"
						data-wp-on--input="actions.setCreateTitle" />
				</label>
				<label class="bn-field">
					<span class="bn-field__label"><?php esc_html_e( 'Description', 'buddynext' ); ?></span>
					<textarea class="bn-input" rows="2" data-wp-bind--value="context.createDesc" data-wp-on--input="actions.setCreateDesc"></textarea>
				</label>
				<label class="bn-field">
					<span class="bn-field__label"><?php esc_html_e( 'Who can see this', 'buddynext' ); ?></span>
					<select class="bn-input bn-album-privacy-select" data-wp-on--change="actions.setCreatePrivacy">
						<option value="public"><?php esc_html_e( 'Public', 'buddynext' ); ?></option>
						<option value="members"><?php esc_html_e( 'Members', 'buddynext' ); ?></option>
						<option value="private"><?php esc_html_e( 'Only me', 'buddynext' ); ?></option>
					</select>
				</label>
			</div>
			<div class="bn-modal__foot">
				<button type="button" class="bn-btn" data-variant="ghost" data-wp-on--click="actions.closeCreateAlbum"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
				<button type="button" class="bn-btn" data-variant="primary"
					data-wp-bind--disabled="!state.createValid"
					data-wp-on--click="actions.submitCreateAlbum">
					<span data-wp-bind--hidden="state.isEditing"><?php esc_html_e( 'Create', 'buddynext' ); ?></span>
					<span data-wp-bind--hidden="!state.isEditing"><?php esc_html_e( 'Save', 'buddynext' ); ?></span>
				</button>
			</div>
		</div>
	</div>

		<?php // ── ADD-MEDIA PICKER MODAL ─────────────────────────────────────────── ?>
	<div class="bn-modal-backdrop bn-album-picker is-hidden" data-wp-class--is-hidden="!context.pickerOpen">
		<div class="bn-modal__panel" data-size="lg" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Add media', 'buddynext' ); ?>">
			<div class="bn-modal__head">
				<h3 class="bn-modal__title"><?php esc_html_e( 'Add media to album', 'buddynext' ); ?></h3>
				<button type="button" class="bn-modal__close" aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-wp-on--click="actions.closeAddMedia">&times;</button>
			</div>
			<div class="bn-modal__body bn-album-picker__body">
				<p class="bn-album-picker__hint"><?php esc_html_e( 'Tap media to select.', 'buddynext' ); ?></p>
				<div class="bn-album-picker__grid" data-bn-picker-grid></div>
			</div>
			<div class="bn-modal__foot">
				<button type="button" class="bn-btn" data-variant="ghost" data-wp-on--click="actions.closeAddMedia"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
				<button type="button" class="bn-btn" data-variant="primary" data-bn-picker-confirm disabled data-wp-on--click="actions.confirmAddMedia">
					<?php esc_html_e( 'Add', 'buddynext' ); ?><span data-bn-picker-count></span>
				</button>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>
