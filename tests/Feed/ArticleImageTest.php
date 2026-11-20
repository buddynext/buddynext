<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The one article image rule: hard-cropped sizes, cut only for published
 * articles' featured images, never stretched, never generated on a page view.
 *
 * Fixtures mirror QA's set (card 10531470411): a 1200x675 landscape, an 800x2400
 * phone photo, a 2400x300 banner, a 120x120 logo, a 5000x3000 photo WordPress
 * scales to 2560, and text-only cases (long title, no image, no intro). Images
 * are drawn with GD at those sizes so the repo carries no binary fixtures.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Bridges\MemberBlogBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\ArticleImage;
use BuddyNext\Feed\BlogPostListener;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\ArticleImage
 * @covers \BuddyNext\Feed\BlogPostListener
 */
class ArticleImageTest extends \WP_UnitTestCase {

	private int $author;

	/**
	 * Generated fixture files, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $files = array();

	public function set_up(): void {
		parent::set_up();
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	public function tear_down(): void {
		$this->clear_queue();
		$this->remove_added_uploads();
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		parent::tear_down();
	}

	/** A JPEG (or PNG) of exactly this size, uploaded as an attachment of $parent. */
	private function upload( int $width, int $height, int $parent = 0, string $ext = 'jpg' ): int {
		$file = trailingslashit( get_temp_dir() ) . 'bn-art-' . $width . 'x' . $height . '-' . wp_generate_password( 6, false ) . '.' . $ext;
		$img  = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $img, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate( $img, 40, 110, 200 ) );
		imagefilledellipse( $img, intdiv( $width, 2 ), intdiv( $height, 2 ), intdiv( $width, 2 ), intdiv( $height, 2 ), (int) imagecolorallocate( $img, 230, 120, 40 ) );
		'png' === $ext ? imagepng( $img, $file ) : imagejpeg( $img, $file, 80 );
		imagedestroy( $img );
		$this->files[] = $file;

		return (int) self::factory()->attachment->create_upload_object( $file, $parent );
	}

	private function article( array $args = array() ): int {
		return (int) wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'An article',
					'post_content' => 'Body text.',
					'post_status'  => 'publish',
					'post_author'  => $this->author,
				),
				$args
			)
		);
	}

	/** An article whose featured image is a fresh upload of this size. */
	private function article_with_image( int $width, int $height, string $ext = 'jpg' ): array {
		$post_id = $this->article();
		$att     = $this->upload( $width, $height, $post_id, $ext );
		set_post_thumbnail( $post_id, $att );
		return array( $post_id, $att );
	}

	private function sizes( int $att ): array {
		return (array) ( wp_get_attachment_metadata( $att )['sizes'] ?? array() );
	}

	/** Remove the article crops from an attachment's metadata (files stay), as on a pre-1.3.0 site. */
	private function strip_crops( int $att ): void {
		$meta = wp_get_attachment_metadata( $att );
		unset( $meta['sizes'][ ArticleImage::COVER ], $meta['sizes'][ ArticleImage::THUMB ] );
		wp_update_attachment_metadata( $att, $meta );
	}

	private function render( array $vars ): string {
		ob_start();
		buddynext_get_template( 'parts/article-card.php', $vars );
		return (string) ob_get_clean();
	}

	private function pending(): int {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			return count(
				as_get_scheduled_actions(
					array(
						'hook'     => ArticleImage::REGEN_HOOK,
						'status'   => 'pending',
						'per_page' => -1,
					),
					'ids'
				)
			);
		}
		$crons = _get_cron_array();
		$n     = 0;
		foreach ( (array) $crons as $hooks ) {
			$n += count( (array) ( $hooks[ ArticleImage::REGEN_HOOK ] ?? array() ) );
		}
		return $n;
	}

	private function clear_queue(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( ArticleImage::REGEN_HOOK );
		}
		wp_unschedule_hook( ArticleImage::REGEN_HOOK );
	}

	private function queued( array $args ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return as_has_scheduled_action( ArticleImage::REGEN_HOOK, $args, 'buddynext' );
		}
		return false !== wp_next_scheduled( ArticleImage::REGEN_HOOK, $args );
	}

	// ── Generation: only for published articles' featured images ──────────────

	public function test_an_unrelated_upload_gets_no_article_crops(): void {
		$att   = $this->upload( 1200, 675 );
		$sizes = $this->sizes( $att );

		$this->assertArrayHasKey( 'medium', $sizes, 'WordPress still makes its own sizes' );
		$this->assertArrayNotHasKey( ArticleImage::COVER, $sizes );
		$this->assertArrayNotHasKey( ArticleImage::THUMB, $sizes );
		$this->assertNotContains( ArticleImage::COVER, get_intermediate_image_sizes(), 'hidden from every generator outside BuddyNext' );
	}

	public function test_a_featured_image_set_on_a_published_article_gets_both_crops(): void {
		list( , $att ) = $this->article_with_image( 2400, 1600 );
		$sizes         = $this->sizes( $att );

		$this->assertSame( array( 1200, 675 ), array( $sizes[ ArticleImage::COVER ]['width'], $sizes[ ArticleImage::COVER ]['height'] ) );
		$this->assertSame( array( 192, 192 ), array( $sizes[ ArticleImage::THUMB ]['width'], $sizes[ ArticleImage::THUMB ]['height'] ) );
	}

	public function test_a_draft_gets_its_crops_on_publish_not_before(): void {
		$post_id = $this->article( array( 'post_status' => 'draft' ) );
		$att     = $this->upload( 2400, 1600, $post_id );
		set_post_thumbnail( $post_id, $att );
		$this->assertArrayNotHasKey( ArticleImage::COVER, $this->sizes( $att ), 'a draft is not an article yet' );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		$this->assertArrayHasKey( ArticleImage::COVER, $this->sizes( $att ), 'publishing cuts the crops' );
		$this->assertArrayHasKey( ArticleImage::THUMB, $this->sizes( $att ) );
	}

	public function test_a_private_post_gets_no_crops(): void {
		$post_id = $this->article( array( 'post_status' => 'private' ) );
		$att     = $this->upload( 2400, 1600, $post_id );
		set_post_thumbnail( $post_id, $att );

		$this->assertArrayNotHasKey( ArticleImage::COVER, $this->sizes( $att ) );
	}

	public function test_a_replaced_featured_image_gets_its_crops_and_the_card_follows(): void {
		list( $post_id, $old ) = $this->article_with_image( 2400, 1600 );
		$old_file              = get_attached_file( $old );
		$new                   = $this->upload( 1600, 1200, $post_id );
		set_post_thumbnail( $post_id, $new );

		$this->assertArrayHasKey( ArticleImage::COVER, $this->sizes( $new ), 'the new image is cropped on that save' );
		$this->assertFileExists( (string) $old_file, 'the old image is left alone (another post may share it)' );

		$meta = BlogPostListener::live_link_meta( array( BlogPostListener::META_POST_ID => $post_id ) );
		$this->assertSame( image_get_intermediate_size( $new, ArticleImage::COVER )['url'], $meta['cover_image']['url'] );
		$this->assertSame( $meta['cover_image']['url'], $meta['thumbnail'] );
	}

	public function test_a_removed_featured_image_leaves_a_text_only_card(): void {
		list( $post_id ) = $this->article_with_image( 2400, 1600 );
		delete_post_thumbnail( $post_id );

		$meta = BlogPostListener::live_link_meta( array( BlogPostListener::META_POST_ID => $post_id ) );
		$this->assertNull( $meta['cover_image'] );
		$this->assertNull( $meta['thumb_image'] );
		$this->assertSame( '', $meta['image'] );
		$this->assertSame( '', $meta['thumbnail'] );
	}

	public function test_a_huge_photo_is_cropped_from_the_scaled_original(): void {
		list( $post_id, $att ) = $this->article_with_image( 5000, 3000 );
		$meta                  = wp_get_attachment_metadata( $att );

		$this->assertSame( 2560, (int) $meta['width'], 'WordPress scaled the 5000px upload' );
		$this->assertNotEmpty( $meta['original_image'] );
		$this->assertSame( array( 1200, 675 ), array( $meta['sizes'][ ArticleImage::COVER ]['width'], $meta['sizes'][ ArticleImage::COVER ]['height'] ) );
		$this->assertSame( array( 192, 192 ), array( $meta['sizes'][ ArticleImage::THUMB ]['width'], $meta['sizes'][ ArticleImage::THUMB ]['height'] ) );
		$this->assertSame( 1200, ArticleImage::for_post( $post_id, 'cover' )['width'] );
	}

	// ── The rules ─────────────────────────────────────────────────────────────

	public function test_qa_images_follow_the_rules(): void {
		list( $land ) = $this->article_with_image( 1200, 675 );
		list( $tall ) = $this->article_with_image( 800, 2400 );
		list( $pano ) = $this->article_with_image( 2400, 300 );
		list( $tiny ) = $this->article_with_image( 120, 120, 'png' );

		$cover = ArticleImage::for_post( $land, 'cover' );
		$this->assertSame( array( 1200, 675 ), array( $cover['width'], $cover['height'] ), 'landscape: a 16:9 cover' );
		$this->assertSame( 192, ArticleImage::for_post( $land, 'thumb' )['width'] );

		// WordPress never upscales a crop: the tall photo's cover is its middle
		// 800x675, the banner's 1200x300. Both are filled into the 16:9 box by CSS.
		$tall_cover = ArticleImage::for_post( $tall, 'cover' );
		$this->assertSame( array( 800, 675 ), array( $tall_cover['width'], $tall_cover['height'] ), 'tall photo: cropped to the middle, not a strip' );
		$this->assertSame( array( 192, 192 ), array( ArticleImage::for_post( $tall, 'thumb' )['width'], ArticleImage::for_post( $tall, 'thumb' )['height'] ) );
		$this->assertStringContainsString( '800x675', $tall_cover['url'] );

		$pano_cover = ArticleImage::for_post( $pano, 'cover' );
		$this->assertSame( array( 1200, 300 ), array( $pano_cover['width'], $pano_cover['height'] ), 'banner: no 768x96 stretched' );
		$this->assertSame( 192, ArticleImage::for_post( $pano, 'thumb' )['height'] );

		$this->assertNull( ArticleImage::for_post( $tiny, 'cover' ), 'a 120px logo is not a cover' );
		$this->assertNull( ArticleImage::for_post( $tiny, 'thumb' ), 'nor a list tile' );
		$tiny_meta = BlogPostListener::live_link_meta( array( BlogPostListener::META_POST_ID => $tiny ) );
		$this->assertNull( $tiny_meta['cover_image'] );
		$this->assertSame( '', $tiny_meta['thumbnail'] );

		$this->assertNull( ArticleImage::for_post( $this->article(), 'cover' ), 'no image: no cover' );
	}

	public function test_a_missing_crop_falls_back_to_the_smallest_file_big_enough(): void {
		$att = $this->upload( 100, 100 );
		wp_update_attachment_metadata(
			$att,
			array(
				'width'  => 2000,
				'height' => 1125,
				'file'   => '2026/11/photo.jpg',
				'sizes'  => array(
					'small' => array(
						'file'   => 'photo-300x169.jpg',
						'width'  => 300,
						'height' => 169,
					),
					'big'   => array(
						'file'   => 'photo-1024x576.jpg',
						'width'  => 1024,
						'height' => 576,
					),
					'mid'   => array(
						'file'   => 'photo-768x432.jpg',
						'width'  => 768,
						'height' => 432,
					),
					'wide'  => array(
						'file'   => 'photo-900x200.jpg',
						'width'  => 900,
						'height' => 200,
					),
				),
			)
		);

		$cover = ArticleImage::for_attachment( $att, 'cover' );
		$this->assertSame( array( 768, 432 ), array( $cover['width'], $cover['height'] ), 'the smallest file at least 600x338' );
		$this->assertStringEndsWith( 'photo-768x432.jpg', $cover['url'] );
		$this->assertSame( 900, ArticleImage::for_attachment( $att, 'thumb' )['width'], 'smallest with a 192px short side (the tile crops its middle)' );
	}

	public function test_no_file_is_stretched_past_its_size(): void {
		$att  = $this->upload( 100, 100 );
		$meta = array(
			'width'  => 700,
			'height' => 230,
			'file'   => '2026/11/strip.jpg',
			'sizes'  => array(),
		);
		wp_update_attachment_metadata( $att, $meta );
		$cover = ArticleImage::for_attachment( $att, 'cover' );
		$this->assertSame( array( 700, 230 ), array( $cover['width'], $cover['height'] ), 'only the full image qualifies, at its own size' );
		$this->assertNotNull( $cover );

		$meta['width'] = 590;
		wp_update_attachment_metadata( $att, $meta );
		$this->assertNull( ArticleImage::for_attachment( $att, 'cover' ), 'under 600px wide is never a cover' );

		$meta['width']  = 700;
		$meta['height'] = 220;
		wp_update_attachment_metadata( $att, $meta );
		$this->assertNull( ArticleImage::for_attachment( $att, 'cover' ), 'too short for the 16:9 box without stretching' );
	}

	public function test_an_external_image_without_metadata_has_no_dimensions(): void {
		list( $post_id, $att ) = $this->article_with_image( 1200, 675 );
		delete_post_meta( $att, '_wp_attachment_metadata' );
		$external = static fn(): string => 'https://cdn.example.com/cover.jpg';
		add_filter( 'wp_get_attachment_url', $external );

		$cover = ArticleImage::for_post( $post_id, 'cover' );
		remove_filter( 'wp_get_attachment_url', $external );

		$this->assertSame(
			array(
				'url'    => 'https://cdn.example.com/cover.jpg',
				'width'  => null,
				'height' => null,
			),
			$cover
		);

		$html = $this->render(
			array(
				'url'   => 'https://example.com/a/',
				'title' => 'External',
				'image' => $cover,
			)
		);
		$this->assertStringContainsString( 'width="1200" height="675"', $html, 'the box size keeps the layout from shifting' );
	}

	public function test_a_page_view_never_generates(): void {
		list( $post_id, $att ) = $this->article_with_image( 2400, 1600 );
		$this->strip_crops( $att );

		$writes = 0;
		$count  = static function ( $data ) use ( &$writes ) {
			++$writes;
			return $data;
		};
		add_filter( 'wp_update_attachment_metadata', $count );
		ArticleImage::for_post( $post_id, 'cover' );
		BlogPostListener::live_link_meta( array( BlogPostListener::META_POST_ID => $post_id ) );
		remove_filter( 'wp_update_attachment_metadata', $count );

		$this->assertSame( 0, $writes );
		$this->assertArrayNotHasKey( ArticleImage::COVER, $this->sizes( $att ) );
	}

	// ── REST: additive, old keys unchanged ────────────────────────────────────

	public function test_card_link_meta_keeps_the_old_keys_and_adds_the_new(): void {
		list( $post_id, $att ) = $this->article_with_image( 2400, 1600 );
		$card                  = ( new PostService() )->get( BlogPostListener::card_id_for_post( $post_id ) );
		$meta                  = (array) $card['link_meta'];
		$crop                  = image_get_intermediate_size( $att, ArticleImage::COVER )['url'];

		$this->assertSame( $crop, $meta['image'] );
		$this->assertSame( $crop, $meta['thumbnail'] );
		$this->assertSame(
			array(
				'url'    => $crop,
				'width'  => 1200,
				'height' => 675,
			),
			$meta['cover_image']
		);
		$this->assertSame( 192, $meta['thumb_image']['width'] );
	}

	// ── The one-time crop pass ────────────────────────────────────────────────

	public function test_the_crop_pass_walks_batches_and_stops(): void {
		$shared = $this->upload( 2400, 1600 );
		$ids    = array();
		for ( $i = 0; $i < 21; $i++ ) {
			$ids[] = $this->article();
		}
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_thumbnail_id', $shared );
		}
		$this->strip_crops( $shared );
		$loose = $this->upload( 1600, 1200 );
		$draft = $this->article( array( 'post_status' => 'draft' ) );
		$other = $this->upload( 1600, 1200 );
		update_post_meta( $draft, '_thumbnail_id', $other );

		$start    = min( $ids ) - 1;
		$listener = new BlogPostListener();
		$purges   = 0;
		$on_purge = static function () use ( &$purges ): void {
			++$purges;
		};
		add_action( 'buddynext_purge_page_cache_all', $on_purge );

		$listener->regen_crops_batch( $start, 0 );
		$this->assertArrayHasKey( ArticleImage::COVER, $this->sizes( $shared ), 'the batch cut the missing crops' );
		$this->assertTrue( $this->queued( array( $ids[19], 1 ) ), 'the cursor advanced to the 20th article' );

		$listener->regen_crops_batch( $ids[19], 1 );
		$this->assertTrue( $this->queued( array( $ids[20], 1 ) ) );

		$this->clear_queue();
		$listener->regen_crops_batch( $ids[20], 1 );
		$this->assertSame( 0, $this->pending(), 'an empty batch ends the chain' );
		$this->assertSame( 1, $purges, 'one page-cache purge at the end of the chain' );

		$this->assertArrayNotHasKey( ArticleImage::COVER, $this->sizes( $loose ), 'a media-library image is untouched' );
		$this->assertArrayNotHasKey( ArticleImage::COVER, $this->sizes( $other ), 'a draft\'s image is untouched' );

		remove_action( 'buddynext_purge_page_cache_all', $on_purge );
	}

	public function test_a_rerun_does_no_work_and_purges_nothing(): void {
		list( $post_id ) = $this->article_with_image( 2400, 1600 );

		$writes   = 0;
		$count    = static function ( $data ) use ( &$writes ) {
			++$writes;
			return $data;
		};
		$purges   = 0;
		$on_purge = static function () use ( &$purges ): void {
			++$purges;
		};
		add_filter( 'wp_update_attachment_metadata', $count );
		add_action( 'buddynext_purge_page_cache_all', $on_purge );

		$listener = new BlogPostListener();
		$listener->regen_crops_batch( $post_id - 1, 0 );
		$listener->regen_crops_batch( $post_id, 0 );

		remove_filter( 'wp_update_attachment_metadata', $count );
		remove_action( 'buddynext_purge_page_cache_all', $on_purge );

		$this->assertSame( 0, $writes, 'images that already have both crops are skipped' );
		$this->assertSame( 0, $purges, 'nothing changed, nothing purged' );
	}

	public function test_the_upgrade_queues_the_first_batch_once(): void {
		$this->clear_queue();

		$step = new \ReflectionMethod( Installer::class, 'maybe_migrate_article_crops' );
		$step->setAccessible( true );
		$step->invoke( null, 77 );
		$this->assertFalse( $this->queued( array( 0, 0 ) ), 'a current site queues nothing' );

		$step->invoke( null, 76 );
		$step->invoke( null, 76 );
		$this->assertTrue( $this->queued( array( 0, 0 ) ) );
		$this->assertSame( 1, $this->pending(), 'never a second chain' );
		$this->clear_queue();
	}

	// ── The card template ─────────────────────────────────────────────────────

	public function test_the_cover_card_with_an_image(): void {
		$html = $this->render(
			array(
				'variant' => 'cover',
				'url'     => 'https://example.com/a/',
				'title'   => 'Notes from the late set',
				'excerpt' => 'A tall phone photo from the back of the room.',
				'image'   => array(
					'url'    => 'https://example.com/a-800x675.jpg',
					'width'  => 800,
					'height' => 675,
				),
			)
		);

		$this->assertStringContainsString( 'data-variant="cover"', $html );
		$this->assertStringContainsString( 'class="bn-article-card__media"', $html );
		$this->assertStringContainsString( 'width="800" height="675"', $html );
		$this->assertStringContainsString( 'class="bn-article-card__kicker"', $html );
		$this->assertStringContainsString( 'class="bn-article-card__read"', $html );
		$this->assertStringContainsString( 'Read article', $html );
		$this->assertStringNotContainsString( 'data-image="none"', $html );
	}

	public function test_a_card_without_an_image_has_no_media_node(): void {
		$long = 'A very long title that goes on and on to show how the card wraps when a member writes a headline that is far too long for one line of the layout Supercalifragilisticexpialidociousandthensomemorelettersthatneverbreak';
		foreach ( array( 'cover', 'row' ) as $variant ) {
			$html = $this->render(
				array(
					'variant' => $variant,
					'url'     => 'https://example.com/a/',
					'title'   => $long,
					'image'   => null,
				)
			);
			$this->assertStringNotContainsString( '__media', $html, "$variant: nothing drawn for a missing image" );
			$this->assertStringNotContainsString( '<img', $html );
			$this->assertStringContainsString( 'data-image="none"', $html );
			$this->assertStringContainsString( 'class="bn-article-card__title"', $html, 'the clamped title class' );
			$this->assertStringContainsString( esc_html( $long ), $html, 'the title is clamped by CSS, never cut in markup' );
			$this->assertStringNotContainsString( 'bn-article-card__excerpt', $html, 'no intro: no excerpt node' );
		}
	}

	public function test_the_row_card_keeps_meta_and_edit(): void {
		$html = $this->render(
			array(
				'variant'    => 'row',
				'url'        => 'https://example.com/a/',
				'title'      => '',
				'image'      => array(
					'url'    => 'https://example.com/a-192x192.jpg',
					'width'  => 192,
					'height' => 192,
				),
				'date_iso'   => '2026-11-20T10:00:00+00:00',
				'date_label' => 'November 20, 2026',
				'writers'    => array( 'Aisha', 'Raj' ),
				'edit_url'   => 'https://example.com/edit/1/',
			)
		);

		$this->assertStringContainsString( 'data-variant="row"', $html );
		$this->assertStringContainsString( 'width="192" height="192"', $html );
		$this->assertStringContainsString( '(untitled)', $html );
		$this->assertStringContainsString( '<time datetime="2026-11-20T10:00:00+00:00">November 20, 2026</time>', $html );
		$this->assertStringContainsString( 'Co-written with Aisha, Raj', $html );
		$this->assertStringContainsString( 'https://example.com/edit/1/', $html );
		$this->assertStringNotContainsString( 'Read article', $html, 'a row is its title link' );
		$this->assertStringNotContainsString( '__kicker', $html );
	}

	public function test_every_feed_card_img_has_width_and_height(): void {
		list( $post_id ) = $this->article_with_image( 2400, 300 );
		$card            = ( new PostService() )->get( BlogPostListener::card_id_for_post( $post_id ) );
		$meta            = (array) $card['link_meta'];
		$args            = array(
			'link_preview' => array(
				'url'   => $meta['url'],
				'title' => $meta['title'],
				'desc'  => $meta['description'],
				'thumb' => $meta['thumbnail'],
			),
			'link_meta'    => $meta,
		);
		$html            = ( new BlogPostListener() )->render_card( '', $args );
		$this->assertStringContainsString( 'width="1200" height="300"', $html );

		// A card whose source post is gone: only the stored URL, nominal box size.
		$args['link_meta'] = array( 'thumbnail' => 'https://example.com/old.jpg' );
		$html              = ( new BlogPostListener() )->render_card( '', $args );
		$this->assertStringContainsString( 'width="1200" height="675"', $html );
		$this->assertSame( 1, substr_count( $html, '<img' ) );
	}
}
