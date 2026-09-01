<?php
/**
 * WP-CLI: bring already-stored media privacy back in line with its post.
 *
 * BuddyNext derives each attached file's privacy from the post it belongs to, on
 * create and on edit (WPMediaVerseBridge::on_post_privacy_changed). That is live
 * and verified for new posts. It does nothing for media ALREADY stored under a
 * post whose audience it never matched, on a site that ran an earlier version —
 * those keep serving at whatever level they were uploaded with until someone
 * re-saves each post by hand.
 *
 * Two generations of that problem, and this repairs both:
 *
 *   1. The "Only me" leak (card 10244775795). Nothing ever sent a privacy at
 *      upload, so the file fell to the default `public` — a private post whose
 *      photo was listed on Explore and served to anonymous visitors.
 *   2. The space leak (card 10251770663). `space_members` collapsed onto
 *      `members`, so a photo posted into a SECRET space was readable by any
 *      signed-in member of the site. That one needs more than a privacy write:
 *      `space` privacy only means anything when the file is ON that space's
 *      drive, so this moves it there too.
 *
 * Only ever tightens. A file already at the level its post implies is skipped, so
 * the command is idempotent and safe to run repeatedly.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use WP_CLI;

/**
 * Reconciles stored media privacy with the posts the media hangs off.
 */
class MediaPrivacyRepairCommand {

	/**
	 * Rows read per batch, so a large site does not load every post at once.
	 */
	private const CHUNK = 200;

	/**
	 * Report or repair media whose privacy does not match its post.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change and write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext reconcile-media-privacy --dry-run
	 *     wp buddynext reconcile-media-privacy
	 *
	 * @param array $args       Positional args (unused — WP-CLI signature).
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		unset( $args );

		// Both partner classes are guarded HERE, in the same body that names them,
		// and the resolved dependencies are passed down. `reconcile()` used to name
		// them itself while relying on this guard one frame up, which reads as a
		// bare cross-plugin call in every audit and would become a real fatal the
		// moment anything else called it.
		if ( ! class_exists( '\\WPMediaVerse\\Core\\Plugin' )
			|| ! class_exists( '\\WPMediaVerse\\Services\\PrivacyService' ) ) {
			WP_CLI::error( 'WPMediaVerse is not active, so there is no media store to reconcile.' );
			// WP_CLI::error() exits, but static analysis does not know that, so the
			// guard would not narrow the class for the calls below without this.
			return;
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$result  = $this->reconcile(
			$dry_run,
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' ),
			array( '\\WPMediaVerse\\Services\\PrivacyService', 'more_restrictive' )
		);

		foreach ( $result['changes'] as $line ) {
			WP_CLI::log( '  ' . $line );
		}

		foreach ( $result['skips'] as $line ) {
			WP_CLI::warning( $line );
		}

		$summary = sprintf(
			'%s %d media across %d posts; %d already correct, %d skipped.',
			$dry_run ? 'Would tighten' : 'Tightened',
			$result['changed'],
			$result['posts'],
			$result['correct'],
			$result['skipped']
		);

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::success( $summary . ' Re-run without --dry-run to apply.' );
			return;
		}

		WP_CLI::success( $summary );
	}

	/**
	 * Walk every non-public post carrying media and reconcile its files.
	 *
	 * Only `privacy <> 'public'` is examined. A public post's media is meant to be
	 * public, so there is nothing to tighten and touching it could only loosen
	 * something a member deliberately set narrower by hand.
	 *
	 * @param bool     $dry_run          Report without writing.
	 * @param object   $repo             MediaVerse media repository (resolved by the caller,
	 *                                   which owns the class_exists guard).
	 * @param callable $more_restrictive MediaVerse's privacy ordering.
	 * @return array{posts:int,changed:int,correct:int,skipped:int,changes:string[],skips:string[]}
	 */
	private function reconcile( bool $dry_run, $repo, callable $more_restrictive ): array {
		global $wpdb;

		$posts   = 0;
		$changed = 0;
		$correct = 0;
		$skipped = 0;
		$changes = array();
		$skips   = array();
		$plan    = array();
		$offset  = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$batch = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, privacy, space_id, media_ids
					   FROM {$wpdb->prefix}bn_posts
					  WHERE privacy <> 'public'
					    AND media_ids IS NOT NULL AND media_ids <> '' AND media_ids <> '[]'
					  ORDER BY id ASC
					  LIMIT %d OFFSET %d",
					self::CHUNK,
					$offset
				),
				ARRAY_A
			);

			$batch      = (array) $batch;
			$batch_size = count( $batch );

			foreach ( $batch as $post ) {
				++$posts;
				$media_ids = json_decode( (string) ( $post['media_ids'] ?? '' ), true );
				if ( ! is_array( $media_ids ) ) {
					continue;
				}

				$target   = WPMediaVerseBridge::media_privacy_for_post( (string) ( $post['privacy'] ?? '' ) );
				$space_id = (int) ( $post['space_id'] ?? 0 );

				foreach ( $media_ids as $media_id ) {
					$media_id = (int) $media_id;
					if ( $media_id <= 0 ) {
						continue;
					}

					// One file can hang off several posts, and on this install one
					// already did. Deciding it per post would let the last post walked
					// win, so two runs could disagree and each would look like drift to
					// the next. Collect every post's answer and settle it once, on the
					// most restrictive - which is also the only direction this command
					// is allowed to move.
					if ( ! isset( $plan[ $media_id ] ) ) {
						$plan[ $media_id ] = array(
							'target'   => $target,
							'space_id' => $space_id,
							'author'   => (int) ( $post['user_id'] ?? 0 ),
						);
						continue;
					}

					$winner = (string) call_user_func( $more_restrictive, $plan[ $media_id ]['target'], $target );
					if ( $winner !== $plan[ $media_id ]['target'] ) {
						$plan[ $media_id ] = array(
							'target'   => $target,
							'space_id' => $space_id,
							'author'   => (int) ( $post['user_id'] ?? 0 ),
						);
					}
				}
			}

			$offset += self::CHUNK;
		} while ( self::CHUNK === $batch_size );

		foreach ( $plan as $media_id => $decision ) {
			$outcome = $this->reconcile_one(
				$repo,
				(int) $media_id,
				(string) $decision['target'],
				(int) $decision['space_id'],
				(int) $decision['author'],
				$dry_run
			);

			if ( 'correct' === $outcome['state'] ) {
				++$correct;
				continue;
			}
			if ( 'skip' === $outcome['state'] ) {
				++$skipped;
				$skips[] = $outcome['message'];
				continue;
			}

			++$changed;
			if ( count( $changes ) < 25 ) {
				$changes[] = $outcome['message'];
			}
		}

		return array(
			'posts'   => $posts,
			'changed' => $changed,
			'correct' => $correct,
			'skipped' => $skipped,
			'changes' => $changes,
			'skips'   => $skips,
		);
	}

	/**
	 * Reconcile a single media row.
	 *
	 * `space` is the case that needs more than a privacy write. The engine honours
	 * it only when the file sits on that space's drive; on a personal drive it
	 * resolves to private, which would tighten correctly but hide the photo from
	 * the very space it was posted in. So the file is moved to the space drive
	 * first — and only when its AUTHOR may still contribute there, asked through
	 * the same access filter MediaVerse itself uses, so this cannot file a member's
	 * media into a space they have since left.
	 *
	 * When the move is not possible the privacy still tightens to `private`, which
	 * fails closed: the author keeps their file, nobody else can read it, and the
	 * leak is shut either way.
	 *
	 * @param object $repo     MediaVerse media repository.
	 * @param int    $media_id Media row.
	 * @param string $target   Privacy the post implies.
	 * @param int    $space_id Space the post belongs to (0 = none).
	 * @param int    $author   Post author.
	 * @param bool   $dry_run  Report without writing.
	 * @return array{state:string,message:string}
	 */
	private function reconcile_one( $repo, int $media_id, string $target, int $space_id, int $author, bool $dry_run ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// The media index keys on `media_id`, not `id`. Getting that wrong does
				// not error - the row simply is not found, every item reports "no row
				// in the media index", and the command cheerfully reports zero drift on
				// a site that is full of it. It did exactly that on the first run here.
				"SELECT privacy, drive_type, drive_id FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$media_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array(
				'state'   => 'skip',
				'message' => sprintf( 'media #%d: no row in the media index (deleted?).', $media_id ),
			);
		}

		$current    = (string) ( $row['privacy'] ?? '' );
		$drive_type = (string) ( $row['drive_type'] ?? 'user' );
		$drive_id   = (int) ( $row['drive_id'] ?? 0 );

		$needs_move = 'space' === $target && $space_id > 0
			&& ( 'space' !== $drive_type || $drive_id !== $space_id );

		if ( $current === $target && ! $needs_move ) {
			return array(
				'state'   => 'correct',
				'message' => '',
			);
		}

		$move_to_space = false;
		if ( $needs_move ) {
			$level = (string) apply_filters( 'mvs_document_drive_access', 'none', 'space', $space_id, $author );
			if ( in_array( $level, array( 'write', 'own' ), true ) ) {
				$move_to_space = true;
			} else {
				// The author cannot contribute to that space any more, so the file
				// cannot live on its drive. Fail closed rather than leave it readable.
				$target = 'private';
			}
		}

		if ( ! $dry_run ) {
			$update = array( 'privacy' => $target );
			if ( $move_to_space ) {
				$update['drive_type'] = 'space';
				$update['drive_id']   = $space_id;
			}
			$repo->set_many( $media_id, $update );
		}

		return array(
			'state'   => 'changed',
			'message' => sprintf(
				'media #%d: %s -> %s%s',
				$media_id,
				'' !== $current ? $current : '(none)',
				$target,
				$move_to_space ? sprintf( ', moved to the space %d drive', $space_id ) : ''
			),
		);
	}
}
