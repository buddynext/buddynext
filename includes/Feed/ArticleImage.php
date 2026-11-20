<?php
/**
 * The one image rule for article cards.
 *
 * An article image used to be drawn in the shape its member uploaded: the feed
 * card read WordPress's 768px soft size (a 2400x300 banner arrived 768x96 and was
 * stretched into a 16:9 box) and the profile row read `medium` at its own ratio (a
 * tall photo became a 96x288 strip). Every article surface now reads one of two
 * hard-cropped sizes made for the job: a 16:9 cover (1200x675) for feed and Space
 * cards, a square (192x192) for list rows.
 *
 * Neither size is made for every upload on the site. Both are left out of
 * WordPress's own generation and are cut only for the featured image of a
 * published article: when it is saved (BlogPostListener) and once for the
 * articles that already exist (BlogPostListener::regen_crops_batch()). A page
 * view never generates anything.
 *
 * @package BuddyNext\Feed
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

/**
 * Article cover and thumbnail sizes, their generation, and the image a card shows.
 */
final class ArticleImage {

	/**
	 * 16:9 cover crop for feed and Space cards.
	 */
	public const COVER = 'bn-article-cover';

	/**
	 * Square crop for list rows (192px for 2x screens of a 96px tile).
	 */
	public const THUMB = 'bn-article-thumb';

	/**
	 * Action Scheduler hook of the one-time crop pass over existing articles.
	 */
	public const REGEN_HOOK = 'buddynext_regen_article_crops';

	/**
	 * Crop dimensions (width, height).
	 */
	private const COVER_BOX = array( 1200, 675 );
	private const THUMB_BOX = array( 192, 192 );

	/**
	 * Smallest original that still makes a cover. Under 600px wide it would blur;
	 * under 225px tall the 16:9 box would scale it past about 1.5x.
	 */
	private const COVER_MIN_W = 600;
	private const COVER_MIN_H = 225;

	/**
	 * Smallest existing file used as a cover when the crop is missing: 600 wide at 16:9.
	 */
	private const COVER_FALLBACK_H = 338;

	/**
	 * Shortest side a thumbnail needs (the 192px crop).
	 */
	private const THUMB_MIN = 192;

	/**
	 * True only while BuddyNext itself cuts article crops.
	 *
	 * @var bool
	 */
	private static bool $generating = false;

	/**
	 * Register both sizes and keep them out of every other image's generation.
	 *
	 * The sizes are not added to `image_size_names_choose`, so the editor's size
	 * picker never offers them.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_image_size( self::COVER, self::COVER_BOX[0], self::COVER_BOX[1], true );
		add_image_size( self::THUMB, self::THUMB_BOX[0], self::THUMB_BOX[1], true );
		// get_intermediate_image_sizes() feeds wp_get_registered_image_subsizes(),
		// which both the upload path (wp_create_image_subsizes) and the retry path
		// (wp_update_image_subsizes) read, so one filter covers every generator.
		add_filter( 'intermediate_image_sizes', array( self::class, 'only_when_generating' ) );
	}

	/**
	 * Drop the article sizes unless BuddyNext is cutting them right now.
	 *
	 * @param mixed $sizes Registered size names.
	 * @return mixed
	 */
	public static function only_when_generating( $sizes ) {
		if ( self::$generating || ! is_array( $sizes ) ) {
			return $sizes;
		}
		return array_values( array_diff( $sizes, array( self::COVER, self::THUMB ) ) );
	}

	/**
	 * Cut the missing article crops of one attachment. Cheap when nothing is missing.
	 *
	 * Reads the attachment metadata first and returns without loading the image
	 * when both crops exist or the original is too small for them, so a re-run
	 * (another save, the regeneration pass) does no work.
	 *
	 * @param int $attachment_id Featured image id.
	 * @return bool Whether crops were generated.
	 */
	public static function ensure_crops( int $attachment_id ): bool {
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			return false;
		}
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		$sizes  = (array) ( $meta['sizes'] ?? array() );

		$wanted = array();
		if ( self::fits( $width, $height, 'cover' ) && ! isset( $sizes[ self::COVER ] ) ) {
			$wanted[] = self::COVER;
		}
		if ( self::fits( $width, $height, 'thumb' ) && ! isset( $sizes[ self::THUMB ] ) ) {
			$wanted[] = self::THUMB;
		}
		if ( array() === $wanted ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		self::$generating = true;
		try {
			// Core decides what it can actually cut (it never upscales): a crop it
			// refuses stays missing in the metadata, and trying again each save
			// would load the image every time.
			$missing = array_intersect_key( wp_get_missing_image_subsizes( $attachment_id ), array_flip( $wanted ) );
			if ( array() === $missing ) {
				return false;
			}
			// A missing original file (an offloaded library) returns a WP_Error;
			// the card then falls back to an existing size.
			return is_array( wp_update_image_subsizes( $attachment_id ) );
		} finally {
			self::$generating = false;
		}
	}

	/**
	 * The image a post's card shows, or null for a text-only card.
	 *
	 * @param \WP_Post|int $post    Article.
	 * @param string       $variant 'cover' (16:9 card) or 'thumb' (square row).
	 * @return array{url:string,width:int|null,height:int|null}|null
	 */
	public static function for_post( $post, string $variant ): ?array {
		$attachment_id = (int) get_post_thumbnail_id( $post );

		return $attachment_id > 0 ? self::for_attachment( $attachment_id, $variant ) : null;
	}

	/**
	 * The image an attachment gives a card of this variant.
	 *
	 * The crop when it exists; else the smallest existing file still big enough
	 * for the box; else the full image. Null when the original is too small, so a
	 * 120px logo is never blown up into a cover or a tile. An attachment without
	 * metadata (an external image a plugin filters in) is returned without
	 * dimensions: the card's fixed-ratio box still prevents a layout shift.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $variant       'cover' or 'thumb'.
	 * @return array{url:string,width:int|null,height:int|null}|null
	 */
	public static function for_attachment( int $attachment_id, string $variant ): ?array {
		$meta   = wp_get_attachment_metadata( $attachment_id );
		$width  = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$height = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;

		if ( $width <= 0 || $height <= 0 ) {
			$url = (string) wp_get_attachment_url( $attachment_id );
			return '' !== $url ? self::shape( $url, null, null ) : null;
		}
		if ( ! self::fits( $width, $height, $variant ) ) {
			return null;
		}

		$cover = 'cover' === $variant;
		$crop  = $cover ? self::COVER : self::THUMB;
		$box   = $cover ? self::COVER_BOX : self::THUMB_BOX;
		$sizes = (array) ( $meta['sizes'] ?? array() );
		$pick  = isset( $sizes[ $crop ] ) ? $crop : '';

		// An original no bigger than the crop is never cut (core does not
		// upscale): the full image is then the best file there is.
		if ( '' === $pick && $width <= $box[0] && $height <= $box[1] ) {
			$url = (string) wp_get_attachment_url( $attachment_id );
			return '' !== $url ? self::shape( $url, $width, $height ) : null;
		}

		if ( '' === $pick ) {
			$best = 0;
			foreach ( $sizes as $name => $size ) {
				$w  = (int) ( $size['width'] ?? 0 );
				$h  = (int) ( $size['height'] ?? 0 );
				$ok = $cover ? ( $w >= self::COVER_MIN_W && $h >= self::COVER_FALLBACK_H ) : min( $w, $h ) >= self::THUMB_MIN;
				if ( $ok && ( 0 === $best || $w * $h < $best ) ) {
					$best = $w * $h;
					$pick = (string) $name;
				}
			}
		}

		if ( '' !== $pick ) {
			$src = image_get_intermediate_size( $attachment_id, $pick );
			if ( is_array( $src ) && ! empty( $src['url'] ) ) {
				return self::shape( (string) $src['url'], (int) $src['width'], (int) $src['height'] );
			}
		}

		$url = (string) wp_get_attachment_url( $attachment_id );
		return '' !== $url ? self::shape( $url, $width, $height ) : null;
	}

	/**
	 * Whether an original of this size may be used for the variant at all.
	 *
	 * @param int    $width   Original width.
	 * @param int    $height  Original height.
	 * @param string $variant 'cover' or 'thumb'.
	 * @return bool
	 */
	private static function fits( int $width, int $height, string $variant ): bool {
		if ( 'cover' === $variant ) {
			return $width >= self::COVER_MIN_W && $height >= self::COVER_MIN_H;
		}
		return min( $width, $height ) >= self::THUMB_MIN;
	}

	/**
	 * The REST / template shape of an image.
	 *
	 * @param string   $url    Image URL.
	 * @param int|null $width  Width, null when unknown.
	 * @param int|null $height Height, null when unknown.
	 * @return array{url:string,width:int|null,height:int|null}
	 */
	private static function shape( string $url, ?int $width, ?int $height ): array {
		return array(
			'url'    => $url,
			'width'  => $width,
			'height' => $height,
		);
	}
}
