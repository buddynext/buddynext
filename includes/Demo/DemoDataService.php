<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Demo data seeder + one-click cleanup.
 *
 * WHY THIS EXISTS
 * ───────────────
 * A fresh BuddyNext install is empty, so every surface (directory, spaces,
 * feed, profiles) reads as broken on first run. This service populates a
 * realistic community — members with profile-field values + bundled avatars
 * and covers, spaces of every type with memberships, posts carrying hashtags,
 * comments, reactions, follows and connections — so the product can be
 * evaluated end-to-end with zero manual setup, fully offline.
 *
 * SINGLE ENGINE
 * ─────────────
 * The CLI command (`wp buddynext demo …`) and the admin button both call this
 * one service — there is no second code path to drift. Everything created is
 * recorded in the `bn_demo_manifest` option; cleanup replays that manifest in
 * reverse and deletes exactly what was seeded (and nothing else). Demo users
 * also carry a `bn_demo` usermeta flag as a belt-and-braces safety net.
 *
 * OFFLINE IMAGES
 * ──────────────
 * Avatars/covers come from original, license-free gradient art bundled in
 * assets/demo/ — no network fetch, no Gravatar, no third-party placeholder
 * service. Each is stored through ImageStorageService (per-owner WebP folders),
 * exactly like a real upload, so the demo exercises the production image path.
 *
 * @package BuddyNext\Demo
 */

declare( strict_types=1 );

namespace BuddyNext\Demo;

use BuddyNext\Comments\CommentService;
use BuddyNext\Feed\BookmarkService;
use BuddyNext\Feed\PostService;
use BuddyNext\Hashtags\HashtagListener;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\Media\MediaClient;
use BuddyNext\Media\ImageStorageService;
use BuddyNext\Profile\ProfileService;
use BuddyNext\Reactions\ReactionService;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * Seeds and removes a realistic demo community.
 */
class DemoDataService {

	/**
	 * Option key holding the manifest of everything this service created.
	 */
	private const MANIFEST_OPTION = 'bn_demo_manifest';

	/**
	 * Usermeta flag marking a demo-seeded user (cleanup safety net).
	 */
	private const USER_FLAG = 'bn_demo';

	/**
	 * Realistic member roster. login is prefixed `bn_demo_` so it never
	 * collides with a real account; avatar/cover index into assets/demo/.
	 *
	 * @var array<int,array<string,string>>
	 */
	private const MEMBERS = array(
		array( 'login' => 'alex_rivera',     'name' => 'Alex Rivera',     'headline' => 'Product designer · prototyping in the open',         'location' => 'Lisbon, PT',      'job' => 'Product Designer',   'site' => 'https://alexrivera.example' ),
		array( 'login' => 'priya_nair',      'name' => 'Priya Nair',      'headline' => 'Frontend engineer · accessibility nerd',             'location' => 'Bengaluru, IN',   'job' => 'Frontend Engineer',  'site' => 'https://priyanair.example' ),
		array( 'login' => 'marcus_obrien',   'name' => "Marcus O'Brien",  'headline' => 'Community lead · runs three book clubs',             'location' => 'Dublin, IE',      'job' => 'Community Lead',     'site' => '' ),
		array( 'login' => 'yuki_tanaka',     'name' => 'Yuki Tanaka',     'headline' => 'Illustrator & type designer',                        'location' => 'Kyoto, JP',       'job' => 'Illustrator',        'site' => 'https://yuki.example' ),
		array( 'login' => 'sara_lindqvist',  'name' => 'Sara Lindqvist',  'headline' => 'Trail runner, data scientist, plant collector',      'location' => 'Gothenburg, SE',  'job' => 'Data Scientist',     'site' => '' ),
		array( 'login' => 'diego_morales',   'name' => 'Diego Morales',   'headline' => 'Indie game dev · pixel art on weekends',             'location' => 'Mexico City, MX', 'job' => 'Game Developer',     'site' => 'https://diego.example' ),
		array( 'login' => 'amina_diallo',    'name' => 'Amina Diallo',    'headline' => 'Climate researcher · ocean systems',                 'location' => 'Dakar, SN',       'job' => 'Researcher',         'site' => '' ),
		array( 'login' => 'tom_becker',      'name' => 'Tom Becker',      'headline' => 'Coffee roaster turned backend engineer',             'location' => 'Berlin, DE',      'job' => 'Backend Engineer',   'site' => 'https://becker.example' ),
		array( 'login' => 'lucia_ferrari',   'name' => 'Lucia Ferrari',   'headline' => 'UX writer · turning jargon into plain words',        'location' => 'Milan, IT',       'job' => 'UX Writer',          'site' => '' ),
		array( 'login' => 'noah_kim',        'name' => 'Noah Kim',        'headline' => 'Photographer & DevRel',                              'location' => 'Seoul, KR',       'job' => 'Developer Advocate', 'site' => 'https://noahkim.example' ),
		array( 'login' => 'fatima_zahra',    'name' => 'Fatima Zahra',    'headline' => 'Open-source maintainer · docs first',                'location' => 'Casablanca, MA',  'job' => 'OSS Maintainer',     'site' => '' ),
		array( 'login' => 'liam_walsh',      'name' => 'Liam Walsh',      'headline' => 'Synth builder, weekend cyclist',                     'location' => 'Melbourne, AU',   'job' => 'Hardware Engineer',  'site' => '' ),
	);

	/**
	 * Spaces to seed — one of every type. avatar/cover index into assets/demo/.
	 *
	 * @var array<int,array<string,string>>
	 */
	private const SPACES = array(
		array( 'name' => 'Design Critique',     'slug' => 'design-critique',     'type' => 'open',    'desc' => 'Share work in progress and get honest, kind feedback.' ),
		array( 'name' => 'Frontend Guild',      'slug' => 'frontend-guild',      'type' => 'open',    'desc' => 'Everything CSS, a11y, and the modern web platform.' ),
		array( 'name' => 'Book Club',           'slug' => 'book-club',           'type' => 'private', 'desc' => 'One book a month. Request to join and pick up the current read.' ),
		array( 'name' => 'Trail Runners',       'slug' => 'trail-runners',       'type' => 'open',    'desc' => 'Routes, gear talk, and weekend meetups.' ),
		array( 'name' => 'Founders Lounge',     'slug' => 'founders-lounge',     'type' => 'secret',  'desc' => 'Invite-only room for the core team to talk shop.' ),
		array( 'name' => 'Photo Walks',         'slug' => 'photo-walks',         'type' => 'private', 'desc' => 'Monthly city photo walks. Members share their best frame.' ),
	);

	/**
	 * Sample post bodies (global feed). Hashtags are extracted automatically by
	 * the buddynext_post_created → HashtagListener pipeline.
	 *
	 * @var string[]
	 */
	private const POSTS = array(
		'Shipped a new prototype today. Spent way too long on the empty states but it was worth it. #design #ux',
		'Hot take: most dashboards would be better as a single well-chosen number. #data #design',
		'Finally got dark mode pixel-perfect across the whole app. #frontend #css #accessibility',
		'Weekend trail was brutal — 1,200m of climbing in the fog. Legs gone, soul restored. #running #outdoors',
		'Reading a wonderful book on systems thinking. Anyone else in the #bookclub want to discuss chapter 4?',
		'Roasted a new single-origin this morning. Bright, citrusy, dangerous. #coffee',
		'Spent the evening soldering a new synth voice. It bleeps! #synthDIY #music',
		'Docs are a feature. Rewrote our getting-started guide and onboarding drop-off halved. #opensource #docs',
		'Tried shooting only at golden hour for a week. Completely changed how I see light. #photography',
		'Refactored the gnarliest module in our codebase. 400 lines became 120. #engineering',
		'New illustration set is up — soft gradients and rounded everything. #illustration #design',
		'Climate model run finished after 9 hours. The ocean is telling us things. #climate #science',
	);

	/**
	 * Comment bodies reused across posts.
	 *
	 * @var string[]
	 */
	private const COMMENTS = array(
		'This is great — love the direction.',
		'Saving this. Exactly what I needed today.',
		'How did you approach the edge cases?',
		'Congrats! That is a real milestone.',
		'Adding my +1 to this.',
		'Would love a deeper write-up sometime.',
	);

	/**
	 * The six canonical reaction emojis.
	 *
	 * @var string[]
	 */
	private const REACTIONS = array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' );

	/**
	 * A single demo poll: question plus 2-5 options. Seeded as one feed post of
	 * type 'poll' so the Polls feature has live data to screenshot/test.
	 *
	 * @var array{question:string, options:string[]}
	 */
	private const POLL = array(
		'question' => 'What should we focus on at the next community call?',
		'options'  => array( 'Design critique session', 'Live coding hour', 'Career AMA', 'Show and tell' ),
	);

	/**
	 * Direct-message threads between demo members (by roster index, 0-based).
	 * Each thread alternates sender starting with member A. Seeded through the
	 * WPMediaVerse messaging engine so the Messages UI has real conversations.
	 *
	 * @var array<int, array{a:int, b:int, messages:string[]}>
	 */
	private const DM_THREADS = array(
		array(
			'a'        => 0,
			'b'        => 1,
			'messages' => array(
				'Hey Priya, loved your accessibility thread today.',
				'Thanks Alex! Want to pair on the contrast tokens this week?',
				'Yes please. Thursday afternoon work for you?',
				'Perfect, I will send an invite.',
			),
		),
		array(
			'a'        => 0,
			'b'        => 4,
			'messages' => array(
				'Sara, your data viz post was so clean. What did you use?',
				'Thank you! Mostly D3 with a custom colour scale.',
				'Would love to see the scale code sometime.',
			),
		),
		array(
			'a'        => 0,
			'b'        => 7,
			'messages' => array(
				'Tom, welcome aboard. Shout if you need anything.',
				'Appreciate it Alex, settling in well already.',
			),
		),
		array(
			'a'        => 2,
			'b'        => 4,
			'messages' => array(
				'Sara, are you joining the trail run on Saturday?',
				'Planning to! Which route are we taking?',
				'The river loop, easy pace, coffee after.',
			),
		),
		array(
			'a'        => 5,
			'b'        => 9,
			'messages' => array(
				'Noah, your photo walk shots came out incredible.',
				'Appreciate it Diego! Bringing the wide lens next time.',
			),
		),
	);

	/** How many recent posts each demo member bookmarks. */
	private const BOOKMARKS_PER_MEMBER = 3;

	/**
	 * Whether a demo dataset is currently installed.
	 */
	public function is_seeded(): bool {
		$manifest = get_option( self::MANIFEST_OPTION, array() );
		return is_array( $manifest ) && ! empty( $manifest['users'] );
	}

	/**
	 * A short summary of what is installed, for the admin UI.
	 *
	 * @return array<string,int>
	 */
	public function summary(): array {
		$m = get_option( self::MANIFEST_OPTION, array() );
		$m = is_array( $m ) ? $m : array();
		return array(
			'users'  => count( $m['users'] ?? array() ),
			'spaces' => count( $m['spaces'] ?? array() ),
			'posts'  => count( $m['posts'] ?? array() ),
			'fields' => count( $m['fields'] ?? array() ),
			'groups' => count( $m['groups'] ?? array() ),
		);
	}

	/**
	 * Seed the full demo community. Idempotent: refuses to double-seed.
	 *
	 * @param callable|null $log Optional progress callback( string $message ).
	 * @return array<string,int> Summary counts.
	 */
	public function seed( ?callable $log = null ): array {
		$say = $log ?? static function (): void {};

		if ( $this->is_seeded() ) {
			$say( 'Demo data already installed. Run cleanup first.' );
			return $this->summary();
		}

		$manifest = array(
			'created_at' => time(),
			'groups'     => array(),
			'fields'     => array(),
			'users'      => array(),
			'spaces'     => array(),
			'posts'      => array(),
		);

		// ── Profile field definitions ──────────────────────────────────────
		$profiles = new ProfileService();
		$say( 'Creating profile fields…' );
		$group_id = $profiles->create_group(
			array(
				'group_key'  => 'bn_demo_details',
				'label'      => __( 'Details', 'buddynext' ),
				'sort_order' => 20,
			)
		);
		$manifest['groups'][] = $group_id;

		$field_defs = array(
			array( 'field_key' => 'bn_demo_location', 'label' => __( 'Location', 'buddynext' ), 'type' => 'text', 'is_searchable' => 1 ),
			array( 'field_key' => 'bn_demo_job',      'label' => __( 'Job title', 'buddynext' ), 'type' => 'text', 'is_searchable' => 1 ),
			array( 'field_key' => 'bn_demo_website',  'label' => __( 'Website', 'buddynext' ),   'type' => 'url' ),
		);
		foreach ( $field_defs as $i => $def ) {
			$def['group_id']   = $group_id;
			$def['sort_order'] = $i;
			$manifest['fields'][] = $profiles->create_field( $def );
		}

		// ── Members ─────────────────────────────────────────────────────────
		$storage = new ImageStorageService();
		$say( 'Creating members…' );
		$user_ids = array();
		foreach ( self::MEMBERS as $i => $member ) {
			$user_id = $this->create_member( $member );
			if ( $user_id <= 0 ) {
				continue;
			}
			$user_ids[]            = $user_id;
			$manifest['users'][]   = $user_id;

			// Avatar + cover from bundled offline art.
			$this->store_bundled( $storage, 'avatar', 'user', $user_id, 'avatars/avatar-' . sprintf( '%02d', ( $i % 12 ) + 1 ) . '.png' );
			$this->store_bundled( $storage, 'cover', 'user', $user_id, 'covers/cover-' . sprintf( '%02d', ( $i % 8 ) + 1 ) . '.png' );

			// Profile field values.
			$profiles->save_profile(
				$user_id,
				array(
					'bn_demo_location' => $member['location'],
					'bn_demo_job'      => $member['job'],
					'bn_demo_website'  => $member['site'],
				)
			);
		}
		$say( sprintf( 'Created %d members.', count( $user_ids ) ) );

		if ( empty( $user_ids ) ) {
			// Nothing to attach content to — persist what we have and bail.
			update_option( self::MANIFEST_OPTION, $manifest, false );
			return $this->summary();
		}

		// ── Spaces + memberships ────────────────────────────────────────────
		$say( 'Creating spaces…' );
		$space_service = new SpaceService();
		$space_members = new SpaceMemberService();
		$space_ids     = array();
		foreach ( self::SPACES as $i => $space ) {
			$owner_id = $user_ids[ $i % count( $user_ids ) ];
			$space_id = $space_service->create(
				$owner_id,
				array(
					'name'        => $space['name'],
					'slug'        => $space['slug'],
					'type'        => $space['type'],
					'description' => $space['desc'],
				)
			);
			if ( is_wp_error( $space_id ) ) {
				continue;
			}
			$space_ids[]          = $space_id;
			$manifest['spaces'][] = $space_id;

			$this->store_bundled( $storage, 'avatar', 'space', $space_id, 'avatars/avatar-' . sprintf( '%02d', ( ( $i + 4 ) % 12 ) + 1 ) . '.png' );
			$this->store_bundled( $storage, 'cover', 'space', $space_id, 'covers/cover-' . sprintf( '%02d', ( ( $i + 3 ) % 8 ) + 1 ) . '.png' );
			$space_service->update(
				$space_id,
				$owner_id,
				array(
					'avatar_url'      => $storage->variation_url( 'avatar', 'space', $space_id, 'full' ),
					'cover_image_url' => $storage->variation_url( 'cover', 'space', $space_id, 'full' ),
				)
			);

			// Add roughly half the members to each space.
			foreach ( $user_ids as $j => $member_id ) {
				if ( $member_id === $owner_id || 0 !== ( ( $i + $j ) % 2 ) ) {
					continue;
				}
				if ( 'secret' === $space['type'] ) {
					continue; // Invite-only: leave to owner only for the demo.
				}
				$space_members->join( $space_id, $member_id );
			}
		}
		$say( sprintf( 'Created %d spaces.', count( $space_ids ) ) );

		// ── Posts (global + in-space) with comments + reactions ─────────────
		$say( 'Creating posts, comments, reactions…' );
		$posts     = new PostService();
		$comments  = new CommentService();
		$reactions = new ReactionService();
		$post_ids  = array();

		foreach ( self::POSTS as $i => $body ) {
			$author_id = $user_ids[ $i % count( $user_ids ) ];
			// Put every third post inside a space the author can post to.
			$space_id  = ( 0 === $i % 3 && ! empty( $space_ids ) ) ? $space_ids[ $i % count( $space_ids ) ] : 0;

			$post_id = $posts->create(
				$author_id,
				array(
					'type'    => 'text',
					'content' => $body,
				) + ( $space_id > 0 ? array( 'space_id' => $space_id ) : array() )
			);
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			$post_ids[] = $post_id;
			// Store the author with each post — bn_posts are not WP posts, so
			// cleanup cannot look the author up via get_post_field(); it needs
			// the author to satisfy PostService::delete()'s ownership check.
			$manifest['posts'][] = array(
				'id'     => $post_id,
				'author' => $author_id,
			);

			// Engagement varies per post so Top/Active sorts have something to
			// rank: most posts get a few, some are busy, a couple are quiet —
			// reactions are capped at the member count (one reaction per member).
			$bn_nu        = count( $user_ids );
			$bn_busy      = ( 0 === $i % 4 );
			$bn_quiet     = ( 0 === $i % 5 );
			$bn_comment_n = $bn_quiet ? 0 : ( $bn_busy ? 4 : 1 + ( $i % 2 ) );
			$bn_react_n   = $bn_quiet ? 0 : min( $bn_nu, $bn_busy ? 6 : 2 + ( $i % 3 ) );

			for ( $c = 0; $c < $bn_comment_n; $c++ ) {
				$commenter = $user_ids[ ( $i + $c + 1 ) % $bn_nu ];
				$comments->create( $commenter, 'post', $post_id, self::COMMENTS[ ( $i + $c ) % count( self::COMMENTS ) ] );
			}
			for ( $r = 0; $r < $bn_react_n; $r++ ) {
				$reactor = $user_ids[ ( $i + $r + 2 ) % $bn_nu ];
				$reactions->react( $reactor, 'post', $post_id, self::REACTIONS[ ( $i + $r ) % count( self::REACTIONS ) ] );
			}

			// Spread posts across the last ~30 days, and set last_activity_at so
			// "Latest" and "Active" orderings differ: busy posts read as recently
			// active; quiet ones stay at their post time. Active offset is never
			// larger than the post age, so last_activity_at >= created_at.
			global $wpdb;
			$bn_age_min      = ( $i * 211 ) % 43200;
			$bn_active_min   = $bn_busy ? min( $bn_age_min, ( $i * 37 ) % 720 ) : $bn_age_min;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_posts',
				array(
					'created_at'       => gmdate( 'Y-m-d H:i:s', time() - $bn_age_min * 60 ),
					'last_activity_at' => gmdate( 'Y-m-d H:i:s', time() - $bn_active_min * 60 ),
				),
				array( 'id' => $post_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
		$say( sprintf( 'Created %d posts.', count( $post_ids ) ) );

		// ── Hashtag indexing (synchronous) ──────────────────────────────────
		// Posts created above fire buddynext_post_created, which normally defers
		// hashtag extraction to Action Scheduler (buddynext_async_index_hashtags).
		// Those async jobs do NOT run during a CLI/admin seed — they sit in the
		// queue until wp-cron fires — so the bn_hashtags registry stays empty and
		// every freshly-seeded tag reads as "does not exist yet" when the owner
		// clicks it right after seeding. Drain the queue synchronously here so the
		// demo community is fully indexed the moment the seeder returns. The worker
		// is idempotent (delete+reinsert links, upsert the registry row), so a
		// later Action Scheduler pass over the same posts is harmless.
		if ( buddynext_feature_enabled( 'hashtags' ) ) {
			$hashtag_indexer = new HashtagListener( new HashtagService() );
			foreach ( $post_ids as $bn_pid ) {
				$hashtag_indexer->async_index_hashtags( 'post', (int) $bn_pid, '' );
			}
			$say( 'Indexed hashtags.' );
		}

		// ── Social graph: follows + connections ─────────────────────────────
		$say( 'Wiring follows and connections…' );
		$follows     = new FollowService();
		$connections = new ConnectionService();
		$n           = count( $user_ids );
		foreach ( $user_ids as $idx => $uid ) {
			// Follow the next two members in the ring.
			$follows->follow( $uid, $user_ids[ ( $idx + 1 ) % $n ] );
			$follows->follow( $uid, $user_ids[ ( $idx + 2 ) % $n ] );

			// Form a mutual connection with the member two ahead.
			$other = $user_ids[ ( $idx + 2 ) % $n ];
			if ( $other !== $uid ) {
				$req = $connections->send_request( $uid, $other );
				if ( true === $req ) {
					$connections->accept_request( $other, $uid );
				}
			}
		}

		// ── Engagement extras: a poll, bookmarks, and DM threads ────────────
		// These populate the Polls feature, the member Bookmarks screen, and the
		// Messages UI so every demo surface has live content (no empty states).
		$this->seed_extras( $user_ids, $post_ids, $manifest, $say );

		update_option( self::MANIFEST_OPTION, $manifest, false );
		$say( 'Demo data installed.' );

		return $this->summary();
	}

	/**
	 * Seed engagement extras that the core loop does not cover: one poll post,
	 * a few bookmarks per member, and direct-message threads between members.
	 *
	 * Records created IDs into $manifest so cleanup() can remove them. The DM
	 * engine (WPMediaVerse) is optional; if it is not active the DM step is
	 * skipped silently rather than failing the whole seed.
	 *
	 * @param int[]               $user_ids Demo member IDs (roster order).
	 * @param int[]               $post_ids Demo post IDs.
	 * @param array<string,mixed> $manifest Seed manifest, passed by reference.
	 * @param callable            $say      Progress logger.
	 * @return void
	 */
	private function seed_extras( array $user_ids, array $post_ids, array &$manifest, callable $say ): void {
		if ( empty( $user_ids ) ) {
			return;
		}
		$n = count( $user_ids );

		// Poll — authored by the third member so it sits among the other posts.
		$say( 'Creating a poll…' );
		$poll_author = $user_ids[ 2 % $n ];
		$poll_id     = ( new PostService() )->create(
			$poll_author,
			array(
				'type'    => 'poll',
				'content' => self::POLL['question'],
				'options' => self::POLL['options'],
			)
		);
		if ( ! is_wp_error( $poll_id ) ) {
			$manifest['posts'][] = array(
				'id'     => $poll_id,
				'author' => $poll_author,
			);
			$post_ids[] = $poll_id;
		}

		// Bookmarks — each member saves a few of the most recent posts.
		if ( ! empty( $post_ids ) ) {
			$say( 'Adding bookmarks…' );
			$bookmarks = new BookmarkService();
			foreach ( $user_ids as $idx => $uid ) {
				for ( $b = 0; $b < self::BOOKMARKS_PER_MEMBER; $b++ ) {
					$post_id = $post_ids[ ( $idx + $b ) % count( $post_ids ) ];
					$bookmarks->bookmark( $uid, $post_id );
				}
			}
		}

		// Direct messages — seeded through the WPMediaVerse engine when present.
		$messaging = class_exists( MediaClient::class ) ? MediaClient::messaging() : null;
		if ( is_object( $messaging )
			&& method_exists( $messaging, 'find_or_create_conversation' )
			&& method_exists( $messaging, 'send_message' )
		) {
			$say( 'Creating direct-message threads…' );
			foreach ( self::DM_THREADS as $thread ) {
				$a = $user_ids[ $thread['a'] % $n ] ?? 0;
				$b = $user_ids[ $thread['b'] % $n ] ?? 0;
				if ( $a <= 0 || $b <= 0 || $a === $b ) {
					continue;
				}
				$conv    = $messaging->find_or_create_conversation( $a, $b );
				$conv_id = is_array( $conv ) ? (int) ( $conv['conversation_id'] ?? 0 ) : 0;
				if ( $conv_id <= 0 ) {
					continue;
				}
				foreach ( $thread['messages'] as $i => $body ) {
					$sender = ( 0 === $i % 2 ) ? $a : $b;
					$messaging->send_message( $conv_id, $sender, array( 'content' => $body ) );
				}
			}
		}
	}

	/**
	 * Remove everything the seeder created, in reverse order, then drop the
	 * manifest. Safe to call when nothing is installed.
	 *
	 * @param callable|null $log Optional progress callback( string $message ).
	 * @return array<string,int> Counts of what was removed.
	 */
	public function cleanup( ?callable $log = null ): array {
		$say      = $log ?? static function (): void {};
		$manifest = get_option( self::MANIFEST_OPTION, array() );
		$manifest = is_array( $manifest ) ? $manifest : array();

		$removed = array(
			'posts'  => 0,
			'spaces' => 0,
			'users'  => 0,
			'fields' => 0,
			'groups' => 0,
		);

		$posts    = new PostService();
		$storage  = new ImageStorageService();
		$profiles = new ProfileService();
		$space_service = new SpaceService();

		// Posts (cascade removes their comments/reactions in PostService::delete).
		// Delete these BEFORE the authors so the ownership check still passes.
		$say( 'Removing posts…' );
		foreach ( (array) ( $manifest['posts'] ?? array() ) as $entry ) {
			$post_id = (int) ( is_array( $entry ) ? ( $entry['id'] ?? 0 ) : $entry );
			$author  = (int) ( is_array( $entry ) ? ( $entry['author'] ?? 0 ) : 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$result = $posts->delete( $post_id, $author > 0 ? $author : get_current_user_id() );
			if ( ! is_wp_error( $result ) ) {
				++$removed['posts'];
			}
		}

		// Prune hashtag registry rows orphaned by the post removals above.
		// Deleting the demo posts fires buddynext_post_deleted, which drops the
		// junction links and recomputes post_count to 0, but the bn_hashtags
		// registry row persists (tags are a durable vocabulary). After a demo
		// cleanup that leaves the trending/explore surfaces cluttered with
		// zero-post tags, so remove only registry rows that now have NO links and
		// NO followers — a tag a real member follows or that still has posts is
		// never touched.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE h FROM {$wpdb->prefix}bn_hashtags h
			 LEFT JOIN {$wpdb->prefix}bn_post_hashtags ph ON ph.hashtag_id = h.id
			 WHERE ph.hashtag_id IS NULL AND h.follower_count = 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Spaces (and their per-owner image folders).
		$say( 'Removing spaces…' );
		foreach ( (array) ( $manifest['spaces'] ?? array() ) as $space_id ) {
			$space_id = (int) $space_id;
			$space    = $space_service->get( $space_id );
			$owner_id = $space ? (int) $space['owner_id'] : get_current_user_id();
			$storage->delete( 'avatar', 'space', $space_id );
			$storage->delete( 'cover', 'space', $space_id );
			if ( ! is_wp_error( $space_service->delete( $space_id, $owner_id ) ) ) {
				++$removed['spaces'];
			}
		}

		// Users (and their image folders). Reassign authored content is moot —
		// their posts were already removed above.
		$say( 'Removing members…' );
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( (array) ( $manifest['users'] ?? array() ) as $user_id ) {
			$user_id = (int) $user_id;
			// Safety net: only delete users we flagged as demo accounts.
			if ( ! get_user_meta( $user_id, self::USER_FLAG, true ) ) {
				continue;
			}
			$storage->delete( 'avatar', 'user', $user_id );
			$storage->delete( 'cover', 'user', $user_id );
			if ( wp_delete_user( $user_id ) ) {
				++$removed['users'];
			}
		}

		// Profile field definitions + group.
		$say( 'Removing profile fields…' );
		foreach ( (array) ( $manifest['fields'] ?? array() ) as $field_id ) {
			$profiles->delete_field( (int) $field_id );
			++$removed['fields'];
		}
		foreach ( (array) ( $manifest['groups'] ?? array() ) as $gid ) {
			$profiles->delete_group( (int) $gid );
			++$removed['groups'];
		}

		// Bookmarks live in their own table; deleting the user or post does not
		// remove them, so clear any rows owned by the demo members for a clean
		// re-seed. DM threads orphan harmlessly; a re-seed makes new ones and
		// the WPMediaVerse engine owns those rows.
		$demo_user_ids = array_map( 'intval', (array) ( $manifest['users'] ?? array() ) );
		if ( ! empty( $demo_user_ids ) ) {
			global $wpdb;
			$say( 'Removing bookmarks…' );
			$placeholders = implode( ',', array_fill( 0, count( $demo_user_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bn_bookmarks WHERE user_id IN ($placeholders)", $demo_user_ids ) );
		}

		delete_option( self::MANIFEST_OPTION );
		$say( 'Demo data removed.' );

		return $removed;
	}

	// ── Private helpers ────────────────────────────────────────────────────

	/**
	 * Create a single demo member, flagged for safe cleanup.
	 *
	 * @param array<string,string> $member Roster entry.
	 * @return int New user ID, or 0 on failure / already-exists.
	 */
	private function create_member( array $member ): int {
		$login = 'bn_demo_' . $member['login'];
		if ( username_exists( $login ) ) {
			return 0;
		}
		$email   = $member['login'] . '@buddynext-demo.invalid';
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true ),
				'display_name' => $member['name'],
				'description'  => $member['headline'],
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		update_user_meta( $user_id, self::USER_FLAG, 1 );
		update_user_meta( $user_id, 'bn_headline', $member['headline'] );
		// Demo members are established community members, not first-time visitors:
		// mark onboarding complete so they land on the feed (not the wizard).
		update_user_meta( $user_id, 'bn_onboarding_complete', '1' );
		return (int) $user_id;
	}

	/**
	 * Store a bundled offline image for an owner through ImageStorageService.
	 *
	 * The bundled PNG is read directly as the source — ImageStorageService
	 * generates the WebP variations into the owner's folder and never touches
	 * the bundled file.
	 *
	 * @param ImageStorageService $storage  Storage service.
	 * @param string              $kind     'avatar' | 'cover'.
	 * @param string              $owner    'user' | 'space'.
	 * @param int                 $id       Owner ID.
	 * @param string              $rel_path Path under assets/demo/.
	 * @return void
	 */
	private function store_bundled( ImageStorageService $storage, string $kind, string $owner, int $id, string $rel_path ): void {
		$src = BUDDYNEXT_DIR . 'assets/demo/' . $rel_path;
		if ( ! is_readable( $src ) ) {
			return;
		}
		$stored = $storage->store( $src, $kind, $owner, $id );
		if ( is_wp_error( $stored ) ) {
			return;
		}
		if ( 'user' === $owner ) {
			// Write the canonical key for each kind: avatars live under `bn_avatar`,
			// covers under `buddynext_cover_url` — the same keys the profile upload
			// flow and renderers use, so seeded media resolves identically to real
			// uploads (no demo-only dual-key shim).
			$meta_key = ( 'avatar' === $kind ) ? 'bn_avatar' : 'buddynext_cover_url';
			update_user_meta( $id, $meta_key, esc_url_raw( $stored ) );
		}
	}
}
