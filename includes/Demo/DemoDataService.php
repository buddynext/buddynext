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
use BuddyNext\Feed\ShareService;
use BuddyNext\Hashtags\HashtagListener;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\Media\Galleries;
use BuddyNext\Media\MediaClient;
use BuddyNext\Media\ImageStorageService;
use BuddyNext\Profile\ProfileService;
use BuddyNext\Reactions\ReactionService;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceCategoryService;
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
	 * `topics` is a list of space-category slugs (see seed_member_interests),
	 * `profile` is a field_key => value map merged into save_profile(); every
	 * other field is a plain string.
	 *
	 * @var array<int,array<string,string|array<int,string>|array<string,string>>>
	 */
	private const MEMBERS = array(
		array(
			'login'    => 'alex_rivera',
			'name'     => 'Alex Rivera',
			'headline' => 'Product designer · prototyping in the open',
			'location' => 'Lisbon, PT',
			'job'      => 'Product Designer',
			'site'     => 'https://alexrivera.example',
			'note'     => 'Design systems, empty states, good coffee',
			'topics'   => array( 'design', 'startups' ),
			'profile'  => array(
				'work_company'     => 'Nomad Studio',
				'work_title'       => 'Product Designer',
				'work_location'    => 'Lisbon, PT',
				'work_start_date'  => '2021-03-01',
				'work_current'     => '1',
				'work_description' => 'Design systems and the checkout flow for a payments product.',
				'edu_institution'  => 'Universidade de Lisboa',
				'edu_degree'       => 'BA',
				'edu_field'        => 'Communication Design',
				'edu_start_year'   => '2013',
				'edu_end_year'     => '2017',
				'edu_current'      => '0',
				'skills'           => 'Design systems, prototyping, user research',
				'birth_date'       => '1994-06-12',
				'social_linkedin'  => 'https://linkedin.com/in/alexrivera',
				'social_instagram' => 'https://instagram.com/alexrivera',
			),
		),
		array(
			'login'    => 'priya_nair',
			'name'     => 'Priya Nair',
			'headline' => 'Frontend engineer · accessibility nerd',
			'location' => 'Bengaluru, IN',
			'job'      => 'Frontend Engineer',
			'site'     => 'https://priyanair.example',
			'note'     => 'CSS, accessibility, the web platform',
			'topics'   => array( 'web-development', 'design' ),
			'profile'  => array(
				'work_company'     => 'Hyperlane',
				'work_title'       => 'Senior Frontend Engineer',
				'work_location'    => 'Bengaluru, IN',
				'work_start_date'  => '2022-01-10',
				'work_current'     => '1',
				'work_description' => 'Accessibility and the design-system component library.',
				'edu_institution'  => 'BITS Pilani',
				'edu_degree'       => 'BE',
				'edu_field'        => 'Computer Science',
				'edu_start_year'   => '2014',
				'edu_end_year'     => '2018',
				'edu_current'      => '0',
				'skills'           => 'CSS architecture, ARIA, performance budgets',
				'birth_date'       => '1996-02-03',
				'social_linkedin'  => 'https://linkedin.com/in/priyanair',
				'social_github'    => 'https://github.com/priyanair',
			),
		),
		array(
			'login'    => 'marcus_obrien',
			'name'     => "Marcus O'Brien",
			'headline' => 'Community lead · runs three book clubs',
			'location' => 'Dublin, IE',
			'job'      => 'Community Lead',
			'site'     => '',
			'note'     => 'Books, community building, board games',
			'topics'   => array( 'books', 'startups' ),
			'profile'  => array(
				'work_company'     => 'Openhouse',
				'work_title'       => 'Community Lead',
				'work_location'    => 'Dublin, IE',
				'work_start_date'  => '2020-09-01',
				'work_current'     => '1',
				'work_description' => 'Runs the ambassador programme and the monthly community call.',
				'edu_institution'  => 'Trinity College Dublin',
				'edu_degree'       => 'BA',
				'edu_field'        => 'Sociology',
				'edu_start_year'   => '2010',
				'edu_end_year'     => '2014',
				'edu_current'      => '0',
				'skills'           => 'Community strategy, moderation, events',
				'birth_date'       => '1990-11-22',
				'social_linkedin'  => 'https://linkedin.com/in/marcusobrien',
			),
		),
		array(
			'login'    => 'yuki_tanaka',
			'name'     => 'Yuki Tanaka',
			'headline' => 'Illustrator & type designer',
			'location' => 'Kyoto, JP',
			'job'      => 'Illustrator',
			'site'     => 'https://yuki.example',
			'note'     => 'Type design, printmaking, slow mornings',
			'topics'   => array( 'design', 'photography' ),
			'profile'  => array(
				'work_company'     => 'Independent',
				'work_title'       => 'Illustrator',
				'work_location'    => 'Kyoto, JP',
				'work_start_date'  => '2018-04-01',
				'work_current'     => '1',
				'work_description' => 'Editorial illustration and type design for print and screen.',
				'edu_institution'  => 'Kyoto City University of Arts',
				'edu_degree'       => 'BFA',
				'edu_field'        => 'Visual Design',
				'edu_start_year'   => '2012',
				'edu_end_year'     => '2016',
				'edu_current'      => '0',
				'skills'           => 'Editorial illustration, printmaking, lettering',
				'birth_date'       => '1993-08-30',
				'social_instagram' => 'https://instagram.com/yukitanaka',
			),
		),
		array(
			'login'    => 'sara_lindqvist',
			'name'     => 'Sara Lindqvist',
			'headline' => 'Trail runner, data scientist, plant collector',
			'location' => 'Gothenburg, SE',
			'job'      => 'Data Scientist',
			'site'     => '',
			'note'     => 'Trail running, data viz, houseplants',
			'topics'   => array( 'running', 'web-development' ),
			'profile'  => array(
				'work_company'     => 'Nordic Grid',
				'work_title'       => 'Data Scientist',
				'work_location'    => 'Gothenburg, SE',
				'work_start_date'  => '2019-06-01',
				'work_current'     => '1',
				'work_description' => 'Forecasting models for renewable-energy load.',
				'edu_institution'  => 'Chalmers',
				'edu_degree'       => 'MSc',
				'edu_field'        => 'Applied Mathematics',
				'edu_start_year'   => '2013',
				'edu_end_year'     => '2018',
				'edu_current'      => '0',
				'skills'           => 'Python, forecasting, data visualisation',
				'birth_date'       => '1992-04-17',
				'social_linkedin'  => 'https://linkedin.com/in/saralindqvist',
				'social_github'    => 'https://github.com/saralindqvist',
			),
		),
		array(
			'login'    => 'diego_morales',
			'name'     => 'Diego Morales',
			'headline' => 'Indie game dev · pixel art on weekends',
			'location' => 'Mexico City, MX',
			'job'      => 'Game Developer',
			'site'     => 'https://diego.example',
			'note'     => 'Pixel art, game jams, synthwave',
			'topics'   => array( 'design', 'photography' ),
			'profile'  => array(
				'work_company'     => 'Pixel Cantina',
				'work_title'       => 'Gameplay Programmer',
				'work_location'    => 'Mexico City, MX',
				'work_start_date'  => '2021-08-01',
				'work_current'     => '1',
				'work_description' => 'Gameplay and tools for a pixel-art platformer.',
				'edu_institution'  => 'UNAM',
				'edu_degree'       => 'BSc',
				'edu_field'        => 'Computer Engineering',
				'edu_start_year'   => '2014',
				'edu_end_year'     => '2019',
				'edu_current'      => '0',
				'skills'           => 'Godot, C#, shaders',
				'birth_date'       => '1997-01-09',
				'social_github'    => 'https://github.com/diegomorales',
				'social_youtube'   => 'https://youtube.com/@/diegomorales',
			),
		),
		array(
			'login'    => 'amina_diallo',
			'name'     => 'Amina Diallo',
			'headline' => 'Climate researcher · ocean systems',
			'location' => 'Dakar, SN',
			'job'      => 'Researcher',
			'site'     => '',
			'note'     => 'Ocean systems, climate models, sailing',
			'topics'   => array( 'running', 'books' ),
			'profile'  => array(
				'work_company'     => 'Institut Ocean',
				'work_title'       => 'Research Scientist',
				'work_location'    => 'Dakar, SN',
				'work_start_date'  => '2017-02-01',
				'work_current'     => '1',
				'work_description' => 'Coastal climate models and ocean-temperature datasets.',
				'edu_institution'  => 'Universite Cheikh Anta Diop',
				'edu_degree'       => 'PhD',
				'edu_field'        => 'Oceanography',
				'edu_start_year'   => '2011',
				'edu_end_year'     => '2016',
				'edu_current'      => '0',
				'skills'           => 'Climate modelling, R, remote sensing',
				'birth_date'       => '1988-05-28',
				'social_linkedin'  => 'https://linkedin.com/in/aminadiallo',
			),
		),
		array(
			'login'    => 'tom_becker',
			'name'     => 'Tom Becker',
			'headline' => 'Coffee roaster turned backend engineer',
			'location' => 'Berlin, DE',
			'job'      => 'Backend Engineer',
			'site'     => 'https://becker.example',
			'note'     => 'Coffee roasting, Go, mechanical keyboards',
			'topics'   => array( 'web-development', 'books' ),
			'profile'  => array(
				'work_company'     => 'Rostwerk',
				'work_title'       => 'Backend Engineer',
				'work_location'    => 'Berlin, DE',
				'work_start_date'  => '2020-03-16',
				'work_current'     => '1',
				'work_description' => 'Go services and the subscription billing pipeline.',
				'edu_institution'  => 'TU Berlin',
				'edu_degree'       => 'BSc',
				'edu_field'        => 'Informatik',
				'edu_start_year'   => '2012',
				'edu_end_year'     => '2016',
				'edu_current'      => '0',
				'skills'           => 'Go, PostgreSQL, distributed systems',
				'birth_date'       => '1991-09-05',
				'social_linkedin'  => 'https://linkedin.com/in/tombecker',
				'social_github'    => 'https://github.com/tombecker',
			),
		),
		array(
			'login'    => 'lucia_ferrari',
			'name'     => 'Lucia Ferrari',
			'headline' => 'UX writer · turning jargon into plain words',
			'location' => 'Milan, IT',
			'job'      => 'UX Writer',
			'site'     => '',
			'note'     => 'Plain language, UX writing, espresso',
			'topics'   => array( 'design', 'books' ),
			'profile'  => array(
				'work_company'     => 'Chiaro',
				'work_title'       => 'UX Writer',
				'work_location'    => 'Milan, IT',
				'work_start_date'  => '2022-05-02',
				'work_current'     => '1',
				'work_description' => 'Plain-language content for onboarding and error states.',
				'edu_institution'  => 'Universita di Bologna',
				'edu_degree'       => 'MA',
				'edu_field'        => 'Linguistics',
				'edu_start_year'   => '2015',
				'edu_end_year'     => '2020',
				'edu_current'      => '0',
				'skills'           => 'UX writing, plain language, content design',
				'birth_date'       => '1995-12-01',
				'social_linkedin'  => 'https://linkedin.com/in/luciaferrari',
			),
		),
		array(
			'login'    => 'noah_kim',
			'name'     => 'Noah Kim',
			'headline' => 'Photographer & DevRel',
			'location' => 'Seoul, KR',
			'job'      => 'Developer Advocate',
			'site'     => 'https://noahkim.example',
			'note'     => 'Photography, DevRel, street food',
			'topics'   => array( 'photography', 'web-development' ),
			'profile'  => array(
				'work_company'     => 'Tideway',
				'work_title'       => 'Developer Advocate',
				'work_location'    => 'Seoul, KR',
				'work_start_date'  => '2023-02-01',
				'work_current'     => '1',
				'work_description' => 'Docs, demos, and the getting-started experience.',
				'edu_institution'  => 'KAIST',
				'edu_degree'       => 'BSc',
				'edu_field'        => 'Computer Science',
				'edu_start_year'   => '2013',
				'edu_end_year'     => '2017',
				'edu_current'      => '0',
				'skills'           => 'Developer relations, docs, public speaking',
				'birth_date'       => '1994-03-14',
				'social_linkedin'  => 'https://linkedin.com/in/noahkim',
				'social_github'    => 'https://github.com/noahkim',
				'social_youtube'   => 'https://youtube.com/@/noahkim',
			),
		),
		array(
			'login'    => 'fatima_zahra',
			'name'     => 'Fatima Zahra',
			'headline' => 'Open-source maintainer · docs first',
			'location' => 'Casablanca, MA',
			'job'      => 'OSS Maintainer',
			'site'     => '',
			'note'     => 'Open source, docs, mentoring',
			'topics'   => array( 'web-development', 'startups' ),
			'profile'  => array(
				'work_company'     => 'Atlas Cloud',
				'work_title'       => 'Staff Engineer',
				'work_location'    => 'Casablanca, MA',
				'work_start_date'  => '2018-01-08',
				'work_end_date'    => '2024-06-28',
				'work_current'     => '0',
				'work_description' => 'Platform team. Maintaining open-source tooling full time since.',
				'edu_institution'  => 'ENSIAS',
				'edu_degree'       => 'MEng',
				'edu_field'        => 'Software Engineering',
				'edu_start_year'   => '2011',
				'edu_end_year'     => '2016',
				'edu_current'      => '0',
				'skills'           => 'Open source, documentation, mentoring',
				'birth_date'       => '1989-07-19',
				'social_linkedin'  => 'https://linkedin.com/in/fatimazahra',
				'social_github'    => 'https://github.com/fatimazahra',
			),
		),
		array(
			'login'    => 'liam_walsh',
			'name'     => 'Liam Walsh',
			'headline' => 'Synth builder, weekend cyclist',
			'location' => 'Melbourne, AU',
			'job'      => 'Hardware Engineer',
			'site'     => '',
			'note'     => 'Synth DIY, cycling, vinyl',
			'topics'   => array( 'running', 'photography' ),
			'profile'  => array(
				'work_company'     => 'Bellwether Instruments',
				'work_title'       => 'Hardware Engineer',
				'work_location'    => 'Melbourne, AU',
				'work_start_date'  => '2019-11-04',
				'work_current'     => '1',
				'work_description' => 'Analogue synth design and small-batch manufacturing.',
				'edu_institution'  => 'RMIT',
				'edu_degree'       => 'BEng',
				'edu_field'        => 'Electrical Engineering',
				'edu_start_year'   => '2009',
				'edu_end_year'     => '2013',
				'edu_current'      => '0',
				'skills'           => 'PCB design, analogue circuits, firmware',
				'birth_date'       => '1990-02-26',
				'social_instagram' => 'https://instagram.com/liamwalsh',
				'social_youtube'   => 'https://youtube.com/@/liamwalsh',
			),
		),
	);

	/**
	 * Pronouns per member (roster order), surfaced as a profile-field value so
	 * the About section reads as a complete profile rather than a stub.
	 *
	 * @var string[]
	 */
	private const PRONOUNS = array(
		'she/her',
		'she/her',
		'he/him',
		'they/them',
		'she/her',
		'he/him',
		'she/her',
		'he/him',
		'she/her',
		'he/him',
		'she/her',
		'he/him',
	);

	/**
	 * Interests per member (roster order), shown as a profile-field value.
	 *
	 * @var string[]
	 */
	/**
	 * Topic interests and the human bio line used to live in two arrays kept
	 * parallel to MEMBERS by index. They are fields on the member record now:
	 * a parallel array is a drift point, and PHPStan proved the defensive
	 * `?? ''` guards around them were already dead code.
	 *
	 * `interests` is a category_multiselect whose options come from the SPACE
	 * CATEGORIES, so its values are category IDs, not prose. The seeder used to
	 * post free-text blurbs into it, which stored NOTHING - on a fresh install
	 * not one demo member had an interest, so suggestions had no signal and
	 * `buddynext_member_interests_updated` could never fire. The blurbs predate
	 * the field: a migration renamed the old free-text `interests` field to
	 * `skills` and gave the key to this one. They are good copy, so they enrich
	 * the bio instead of being discarded.
	 */

	/**
	 * Spaces to seed — one of every type. avatar/cover index into assets/demo/.
	 *
	 * `members` is how many of the roster join, and the spread across spaces is
	 * deliberate: the directory's default sort is member_count DESC, so equal
	 * counts would make it rank nothing.
	 *
	 * @var array<int,array<string,string|int>>
	 */
	private const SPACES = array(
		array(
			'name'     => 'Design Critique',
			'slug'     => 'design-critique',
			'members'  => 11,
			'type'     => 'open',
			'desc'     => 'Share work in progress and get honest, kind feedback.',
			'category' => 'design',
		),
		array(
			'name'     => 'Frontend Guild',
			'slug'     => 'frontend-guild',
			'members'  => 9,
			'type'     => 'open',
			'desc'     => 'Everything CSS, a11y, and the modern web platform.',
			'category' => 'web-development',
		),
		array(
			'name'     => 'Book Club',
			'slug'     => 'book-club',
			'members'  => 5,
			'type'     => 'private',
			'desc'     => 'One book a month. Request to join and pick up the current read.',
			'category' => 'books',
		),
		array(
			'name'     => 'Trail Runners',
			'slug'     => 'trail-runners',
			'members'  => 7,
			'type'     => 'open',
			'desc'     => 'Routes, gear talk, and weekend meetups.',
			'category' => 'running',
		),
		array(
			'name'     => 'Founders Lounge',
			'slug'     => 'founders-lounge',
			'members'  => 3,
			'type'     => 'secret',
			'desc'     => 'Invite-only room for the core team to talk shop.',
			'category' => 'startups',
		),
		array(
			'name'     => 'Photo Walks',
			'slug'     => 'photo-walks',
			'members'  => 4,
			'type'     => 'private',
			'desc'     => 'Monthly city photo walks. Members share their best frame.',
			'category' => 'photography',
		),
		// The three below exist to fill the categories the INSTALLER seeds, so no
		// filter chip in the directory leads to an empty page. The topic spaces
		// above deliberately no longer use those generic slugs - "Trail Runners"
		// filed under "General" tells an owner nothing - but leaving them barren
		// just moves the problem: the 1.0.4 QA found exactly that, a chip that
		// filters to nothing. These are also the three spaces almost every real
		// community actually has, so they earn their place rather than padding.
		array(
			'name'     => 'Say Hello',
			'slug'     => 'say-hello',
			'members'  => 8,
			'type'     => 'open',
			'desc'     => 'New here? Introduce yourself and tell us what you are working on.',
			'category' => 'introductions',
		),
		array(
			'name'     => 'Community News',
			'slug'     => 'community-news',
			'members'  => 10,
			'type'     => 'open',
			'desc'     => 'Product updates and community announcements from the team.',
			'category' => 'announcements',
		),
		array(
			'name'     => 'The Lounge',
			'slug'     => 'the-lounge',
			'members'  => 6,
			'type'     => 'open',
			'desc'     => 'Off-topic chatter, small wins, and weekend plans.',
			'category' => 'general',
		),
	);

	/**
	 * Sub-spaces to seed under the roots above, keyed by parent slug. Proves the
	 * parent <-> child link end to end on a seeded community — the breadcrumb up
	 * from a child and the "Sub-spaces" rail down from a parent. A private child
	 * under an open parent also exercises the visibility-scoped get_subspaces().
	 *
	 * @var array<int,array<string,string>>
	 */
	private const SUBSPACES = array(
		array(
			'parent' => 'design-critique',
			'name'   => 'UI Patterns',
			'slug'   => 'ui-patterns',
			'type'   => 'open',
			'desc'   => 'A shared library of interface patterns and when to reach for each.',
		),
		array(
			'parent' => 'design-critique',
			'name'   => 'Accessibility Lab',
			'slug'   => 'accessibility-lab',
			'type'   => 'open',
			'desc'   => 'Audits, fixes, and WCAG questions pulled from real project work.',
		),
		array(
			'parent' => 'design-critique',
			'name'   => 'Design Systems',
			'slug'   => 'design-systems',
			'type'   => 'private',
			'desc'   => 'Tokens, components, and governance for the system team.',
		),
		array(
			'parent' => 'frontend-guild',
			'name'   => 'CSS Architecture',
			'slug'   => 'css-architecture',
			'type'   => 'open',
			'desc'   => 'Cascade layers, container queries, and CSS that scales.',
		),
		array(
			'parent' => 'frontend-guild',
			'name'   => 'Web Performance',
			'slug'   => 'web-performance',
			'type'   => 'open',
			'desc'   => 'Core Web Vitals, bundle budgets, and real-device profiling.',
		),
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
	 * Link / oEmbed posts (rich media). Seeded as 'link' posts so the feed
	 * exercises the oEmbed + link-preview render path — including a YouTube video
	 * that renders as a capped 16/9 player. `author`/`space` are roster / SPACES
	 * indices; space -1 means the global feed. In-space authors are the space
	 * owner so the post is authorised. These are plain bn_posts rows (no
	 * WPMediaVerse media), so the one-click cleanup removes them completely.
	 *
	 * @var array<int,array{author:int,space:int,content:string,url:string}>
	 */
	private const LINK_POSTS = array(
		array(
			'author'  => 0,
			'space'   => -1,
			'content' => 'This walkthrough nails what we are building. Worth ten minutes. #community',
			'url'     => 'https://www.youtube.com/watch?v=kSoXOIcnO_E',
		),
		array(
			'author'  => 1,
			'space'   => 1,
			'content' => 'Great primer on modern CSS layout — sharing it in the guild. #css #frontend',
			'url'     => 'https://web.dev/learn/css/',
		),
		array(
			'author'  => 3,
			'space'   => 3,
			'content' => 'Solid overview of trail running for anyone just starting out. #running #outdoors',
			'url'     => 'https://en.wikipedia.org/wiki/Trail_running',
		),
	);

	/**
	 * Uploaded-photo posts. Seeded ONLY when the media engine is active: each
	 * bundled image is ingested through the same WPMediaVerse upload path the
	 * composer uses (UploadService::handle), then attached as media_ids. The
	 * media_id is recorded so cleanup delete_cascades the row - no orphan.
	 *
	 * @var array<int,array{author:int,content:string,img:string}>
	 */
	private const MEDIA_POSTS = array(
		array(
			'author'  => 9,
			'content' => 'Golden hour from last night\'s walk. The light did all the work. #photography',
			'img'     => 'covers/cover-02.png',
		),
		array(
			'author'  => 3,
			'content' => 'New gradient study — soft, rounded, calm. #illustration #design',
			'img'     => 'covers/cover-05.png',
		),
		array(
			'author'  => 5,
			'content' => 'Trail snapshot from the river loop this morning. #running #outdoors',
			'img'     => 'covers/cover-07.png',
		),
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
			'media'      => array(),
		);

		// ── Profiles ───────────────────────────────────────────────────────
		// Members populate BN's REAL starter profile fields (shipped by the
		// installer: basic_info / social_links / skills). The demo creates NO
		// fields of its own — doing so left the real fields empty and made every
		// demo profile look incomplete. Values are written per member below and
		// cleared on cleanup by the platform `deleted_user` listener (which now
		// purges bn_profile_values for any removed account).
		$profiles = new ProfileService();

		// ── Members ─────────────────────────────────────────────────────────
		$storage = new ImageStorageService();
		$say( 'Creating members…' );
		$user_ids = array();
		foreach ( self::MEMBERS as $i => $member ) {
			$user_id = $this->create_member( $member );
			if ( $user_id <= 0 ) {
				continue;
			}
			$user_ids[]          = $user_id;
			$manifest['users'][] = $user_id;

			// Avatar + cover from bundled offline art.
			$this->store_bundled( $storage, 'avatar', 'user', $user_id, 'avatars/avatar-' . sprintf( '%02d', ( $i % 12 ) + 1 ) . '.png' );
			$this->store_bundled( $storage, 'cover', 'user', $user_id, 'covers/cover-' . sprintf( '%02d', ( $i % 8 ) + 1 ) . '.png' );

			// Fill BN's real starter fields so the About section reads complete.
			// Compose the bio from job/location so it COMPLEMENTS the headline
			// shown above it rather than repeating it (prepending the headline
			// made every card render the tagline twice). Empty when we have
			// nothing to add - the card then simply shows the headline alone.
			$bn_handle = str_replace( '_', '', $member['login'] );

			// Two sentences: what they do, then who they are. "Product Designer
			// based in Lisbon, PT." on its own is a database row; adding "Into
			// design systems, empty states, good coffee." makes it a person, and
			// an owner judges the demo on whether it reads like a community they
			// would want to run. Empty parts drop out, so a member with neither
			// simply has no bio.
			$bn_bio = $member['job'] . ' based in ' . $member['location'] . '. Into ' . lcfirst( $member['note'] ) . '.';
			// The starter fields, plus the member's work / education / skills.
			//
			// A fresh install ships 26 profile fields in six groups and the seed
			// used to fill six of them, all in Basics - so a demo profile showed
			// a headline and one link with the Work, Education and Skills
			// sections empty, and the date / boolean / number field types never
			// rendered at all. An owner cannot judge a profile layout that has
			// nothing in it.
			//
			// The `profile` block per member is deliberately uneven on socials:
			// the UX writer has no GitHub, the illustrator has Instagram and no
			// LinkedIn. Real rosters look like that, and it shows the renderer
			// handling a missing field rather than a uniformly full one.
			$profiles->save_profile(
				$user_id,
				array_merge(
					array(
						'headline'       => $member['headline'],
						'bio'            => $bn_bio,
						'location'       => $member['location'],
						'website'        => $member['site'],
						'pronouns'       => self::PRONOUNS[ $i ] ?? '',
						'social_twitter' => 'https://twitter.com/' . $bn_handle,
					),
					$member['profile']
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
		$space_by_slug = array();

		// Map category slugs to ids so the seeded spaces are filterable by category
		// in the directory (the category chips are dead weight on a fresh, empty
		// dataset otherwise).
		$bn_cat_service = new SpaceCategoryService();
		$cat_by_slug    = array();
		foreach ( $bn_cat_service->get_all() as $bn_cat ) {
			if ( isset( $bn_cat['slug'], $bn_cat['id'] ) ) {
				$cat_by_slug[ (string) $bn_cat['slug'] ] = (int) $bn_cat['id'];
			}
		}

		// Create any category this dataset references that the site lacks -
		// otherwise those spaces seed uncategorized and the directory's category
		// chips filter to an empty page (found on the 1.0.4 dist-zip QA: the
		// starter kit ships only 3 of the 5 slugs the demo spaces use).
		foreach ( self::SPACES as $bn_demo_space ) {
			$bn_cat_slug = (string) $bn_demo_space['category'];
			if ( isset( $cat_by_slug[ $bn_cat_slug ] ) ) {
				continue;
			}
			$bn_new_cat = $bn_cat_service->create(
				array(
					'name' => ucwords( str_replace( '-', ' ', $bn_cat_slug ) ),
					'slug' => $bn_cat_slug,
				)
			);
			if ( ! is_wp_error( $bn_new_cat ) ) {
				$cat_by_slug[ $bn_cat_slug ]    = (int) $bn_new_cat;
				$manifest['space_categories'][] = (int) $bn_new_cat;
			}
		}

		// Interests, now that the topic categories exist. This runs here rather
		// than in the member loop above for a plain ordering reason: the field
		// stores CATEGORY IDS, and the categories are not created until the
		// block directly above this one.
		$this->seed_member_interests( $user_ids, $cat_by_slug, $profiles );

		foreach ( self::SPACES as $i => $space ) {
			$owner_id = $user_ids[ $i % count( $user_ids ) ];
			$space_id = $space_service->create(
				$owner_id,
				array(
					'name'        => $space['name'],
					'slug'        => $space['slug'],
					'type'        => $space['type'],
					'description' => $space['desc'],
					'category_id' => $cat_by_slug[ $space['category'] ] ?? 0,
				)
			);
			if ( is_wp_error( $space_id ) ) {
				continue;
			}
			$space_ids[]                     = $space_id;
			$space_by_slug[ $space['slug'] ] = $space_id;
			$manifest['spaces'][]            = $space_id;

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

			// Membership varies per space, and that is the point. The old rule
			// added every second member to every space, so all of them landed on
			// the SAME count - and "Sort: Popular" is member_count DESC, which
			// made the directory's default sort a total tie that ranked nothing.
			// A believable spread is what shows an owner the sort works at all.
			//
			// The roster is rotated by space index so different spaces draw
			// different members; taking the first N of the same list every time
			// would give every space an identical membership.
			if ( 'secret' !== $space['type'] ) {
				// No `?? default` here on purpose: every space declares `members`,
				// so a missing one is a mistake PHPStan should catch rather than
				// a silent fallback that quietly flattens the spread again.
				$bn_want   = (int) $space['members'];
				$bn_offset = count( $user_ids ) > 0 ? ( $i % count( $user_ids ) ) : 0;
				$bn_roster = array_merge(
					array_slice( $user_ids, $bn_offset ),
					array_slice( $user_ids, 0, $bn_offset )
				);
				$bn_joined = 0;
				foreach ( $bn_roster as $member_id ) {
					if ( $bn_joined >= $bn_want ) {
						break;
					}
					if ( $member_id === $owner_id ) {
						continue;
					}
					$space_members->join( $space_id, $member_id );
					++$bn_joined;
				}
			}
		}
		$say( sprintf( 'Created %d spaces.', count( $space_ids ) ) );

		// ── Sub-spaces (children of the roots above) ────────────────────────
		// Created through SpaceService::create() with parent_id so depth/cap are
		// validated exactly as the live UI does, then a slice of members joins so
		// the rail counts read realistically.
		$say( 'Creating sub-spaces…' );
		$sub_count = 0;
		foreach ( self::SUBSPACES as $i => $sub ) {
			$parent_id = $space_by_slug[ $sub['parent'] ] ?? 0;
			if ( ! $parent_id ) {
				continue;
			}
			$owner_id = $user_ids[ ( $i + 1 ) % count( $user_ids ) ];
			$sub_id   = $space_service->create(
				$owner_id,
				array(
					'name'        => $sub['name'],
					'slug'        => $sub['slug'],
					'type'        => $sub['type'],
					'description' => $sub['desc'],
					'parent_id'   => $parent_id,
				)
			);
			if ( is_wp_error( $sub_id ) ) {
				continue;
			}
			$space_ids[]          = $sub_id;
			$manifest['spaces'][] = $sub_id;
			++$sub_count;

			$this->store_bundled( $storage, 'avatar', 'space', $sub_id, 'avatars/avatar-' . sprintf( '%02d', ( ( $i + 7 ) % 12 ) + 1 ) . '.png' );

			// A third of the roster joins each sub-space (fewer than a root space,
			// which reads right for a focused child). SUBSPACES are open/private
			// only — discovery surfaces — so every child takes auto-joins.
			foreach ( $user_ids as $j => $member_id ) {
				if ( $member_id === $owner_id || 0 !== ( ( $i + $j ) % 3 ) ) {
					continue;
				}
				$space_members->join( $sub_id, $member_id );
			}
		}
		$say( sprintf( 'Created %d sub-spaces.', $sub_count ) );

		// ── Posts (global + in-space) with comments + reactions ─────────────
		$say( 'Creating posts, comments, reactions…' );
		$posts     = new PostService();
		$comments  = new CommentService();
		$reactions = new ReactionService();
		$post_ids  = array();

		foreach ( self::POSTS as $i => $body ) {
			$author_id = $user_ids[ $i % count( $user_ids ) ];
			// Put every third post inside a space the author can post to.
			$space_id = ( 0 === $i % 3 && ! empty( $space_ids ) ) ? $space_ids[ $i % count( $space_ids ) ] : 0;

			// Backdate across the last ~30 days for a realistic time spread, through
			// the API (no raw UPDATE). last_activity_at defaults to created_at and is
			// bumped to NOW() by any engagement below, so busy posts surface in the
			// "Active" feed while quiet ones stay at their post time.
			$bn_created_at = gmdate( 'Y-m-d H:i:s', time() - ( ( $i * 211 ) % 43200 ) * 60 );
			$post_id       = $posts->create(
				$author_id,
				array(
					'type'       => 'text',
					'content'    => $body,
					'created_at' => $bn_created_at,
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
			// rank - applied uniformly to every activity type via engage_post().
			$this->engage_post( $post_id, $i, $user_ids, $comments, $reactions );
		}
		$say( sprintf( 'Created %d posts.', count( $post_ids ) ) );

		// ── Link / oEmbed posts (video embeds + article cards) ──────────────
		// Seeded as 'link' posts so the feed exercises the oEmbed render path
		// (a YouTube video among them). In-space authors are the space owner so
		// the post is authorised. PostService auto-fetches link_meta when empty;
		// the YouTube post renders as a capped 16/9 player on the feed.
		$say( 'Creating link / video posts…' );
		foreach ( self::LINK_POSTS as $li => $lp ) {
			$author_id = $user_ids[ $lp['author'] % count( $user_ids ) ];
			$space_id  = ( $lp['space'] >= 0 && isset( $space_ids[ $lp['space'] ] ) ) ? $space_ids[ $lp['space'] ] : 0;

			$post_id = $posts->create(
				$author_id,
				array(
					'type'     => 'link',
					'content'  => $lp['content'],
					'link_url' => $lp['url'],
				) + ( $space_id > 0 ? array( 'space_id' => $space_id ) : array() )
			);
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			$post_ids[]          = $post_id;
			$manifest['posts'][] = array(
				'id'     => $post_id,
				'author' => $author_id,
			);
			$this->engage_post( $post_id, count( self::POSTS ) + $li, $user_ids, $comments, $reactions );
		}
		$say( sprintf( 'Total posts now %d.', count( $post_ids ) ) );

		// ── Media posts (uploaded photos) — only when the media engine is active ──
		// Ingest bundled demo images through the SAME WPMediaVerse upload path the
		// composer uses (UploadService::handle), attach the media_id, and record it
		// so cleanup delete_cascades the engine row. Skipped silently when the media
		// engine is absent, so the seed never hard-depends on it.
		if ( MediaClient::available() ) {
			$uploader = MediaClient::upload();
			if ( is_object( $uploader ) && method_exists( $uploader, 'handle' ) ) {
				if ( ! function_exists( 'wp_tempnam' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$say( 'Creating media posts…' );
				foreach ( self::MEDIA_POSTS as $mi => $mp ) {
					$src = BUDDYNEXT_DIR . 'assets/demo/' . $mp['img'];
					if ( ! is_readable( $src ) ) {
						continue;
					}
					$author_id = $user_ids[ $mp['author'] % count( $user_ids ) ];
					// Copy to a real temp file: handle() may move/unlink tmp_name, and the
					// bundled repo asset must survive for the next seed.
					$tmp = wp_tempnam( basename( $mp['img'] ) );
					// copy() can emit a warning on a transient temp-write failure; the
					// return value is the real signal and is checked here to skip the item.
					if ( ! $tmp || ! @copy( $src, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled via the checked return value.
						continue;
					}
					$file     = array(
						'tmp_name' => $tmp,
						'name'     => basename( $mp['img'] ),
						'type'     => 'image/png',
						'size'     => (int) filesize( $tmp ),
						'error'    => 0,
					);
					$media_id = $uploader->handle( $file, $author_id, array() );
					if ( file_exists( $tmp ) ) {
						wp_delete_file( $tmp );
					}
					if ( is_wp_error( $media_id ) || (int) $media_id <= 0 ) {
						continue;
					}
					$manifest['media'][] = (int) $media_id;
					// 'photo', matching what the composer stores for the same action:
					// it promotes a text post to 'photo' the moment media is attached.
					// Seeding these as 'text' made the demo content misrepresent its own
					// product — the type is what classifies a post, so a demo photo post
					// was indistinguishable from a plain status update to anything that
					// reads the type.
					$post_id = $posts->create(
						$author_id,
						array(
							'type'      => 'photo',
							'content'   => $mp['content'],
							'media_ids' => array( (int) $media_id ),
						)
					);
					if ( is_wp_error( $post_id ) ) {
						continue;
					}
					$post_ids[]          = $post_id;
					$manifest['posts'][] = array(
						'id'     => $post_id,
						'author' => $author_id,
					);
					$this->engage_post( $post_id, 200 + $mi, $user_ids, $comments, $reactions );
				}
			}
		}

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

		// Bring the site owner INTO the community they just seeded.
		$this->seed_owner_relationships( $user_ids, $follows, $connections, $say );

		// Space albums (1.1.1) — shipped after this seeder was written, so the
		// space Media tab was empty on every demo site.
		$this->seed_space_albums( $space_by_slug, $manifest, $say );

		// ── Engagement extras: a poll, bookmarks, and DM threads ────────────
		// These populate the Polls feature, the member Bookmarks screen, and the
		// Messages UI so every demo surface has live content (no empty states).
		$this->seed_extras( $user_ids, $post_ids, $manifest, $say );

		update_option( self::MANIFEST_OPTION, $manifest, false );

		// Say what the demo could NOT show, and why.
		//
		// Photo posts, space albums and direct messages all run on WPMediaVerse.
		// Without it the seed still succeeds - it never hard-depends on an
		// optional plugin - but it quietly produces a community with no images
		// and no messages, and the owner is left to conclude that BuddyNext
		// simply does not do those things. Skipping silently is the wrong
		// default when the whole purpose of the seed is to show the product.
		if ( ! MediaClient::available() ) {
			$say( 'Skipped photo posts, space albums and direct messages: WPMediaVerse is not active.' );
			$say( 'Install and activate it, then run cleanup and seed again for the full demo.' );
		}

		$say( 'Demo data installed.' );

		return $this->summary();
	}

	/**
	 * Ingest a bundled demo image through the real upload path.
	 *
	 * Shared by the media posts and the space albums. The copy-to-tempfile dance
	 * is not incidental: handle() may move or unlink tmp_name, and the bundled
	 * repo asset has to survive for the next seed.
	 *
	 * @param string $rel       Path under assets/demo/.
	 * @param int    $author_id Uploading member.
	 * @return int Media ID, or 0 when the engine is absent or the file is unusable.
	 */
	private function upload_bundled_media( string $rel, int $author_id ): int {
		$uploader = MediaClient::available() ? MediaClient::upload() : null;
		if ( ! is_object( $uploader ) || ! method_exists( $uploader, 'handle' ) ) {
			return 0;
		}

		$src = BUDDYNEXT_DIR . 'assets/demo/' . $rel;
		if ( ! is_readable( $src ) ) {
			return 0;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( basename( $rel ) );
		// copy() can emit a warning on a transient temp-write failure; the return
		// value is the real signal and is checked here.
		if ( ! $tmp || ! @copy( $src, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled via the checked return value.
			return 0;
		}

		$media_id = $uploader->handle(
			array(
				'tmp_name' => $tmp,
				'name'     => basename( $rel ),
				'type'     => 'image/png',
				'size'     => (int) filesize( $tmp ),
				'error'    => 0,
			),
			$author_id,
			array()
		);

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return is_wp_error( $media_id ) ? 0 : (int) $media_id;
	}

	/**
	 * Put a photo album inside a couple of spaces.
	 *
	 * Space albums shipped in 1.1.1, after this seeder was written, so the space
	 * Media tab was empty on every demo site - a feature we ship and never show.
	 *
	 * Albums are an mvs_album CPT owned by WPMediaVerse, so this goes through
	 * MediaClient::albums() (the BuddyNext-owned client, same pattern as the DM
	 * threads) and Galleries::assign_album_to_space() for the space binding. No
	 * direct writes to the partner's post meta: the bridge contract says the
	 * bridge owns partner-table access, and a demo seeder is not an excuse.
	 *
	 * Skipped when the media engine is absent - seed() reports that once, at the
	 * end, rather than each step failing quietly on its own.
	 *
	 * @param array<string,int>   $space_by_slug Space slug => ID.
	 * @param array<string,mixed> $manifest      Seed manifest, by reference.
	 * @param callable            $say           Progress logger.
	 */
	private function seed_space_albums( array $space_by_slug, array &$manifest, callable $say ): void {
		if ( ! MediaClient::available() ) {
			return;
		}

		$albums = MediaClient::albums();
		if ( ! is_object( $albums ) || ! method_exists( $albums, 'create' ) || ! method_exists( $albums, 'add_items' ) ) {
			return;
		}

		// Albums upload their OWN images rather than borrowing the three the
		// media posts made. Splitting those across two albums left one photo in
		// each, and a one-photo album demonstrates the feature worse than having
		// none. The bundled covers are abstract art and there are eight of them,
		// so this needs no new assets.
		$plan = array(
			array(
				'space'       => 'photo-walks',
				'title'       => 'March city walk',
				'description' => 'Everyone brought a frame back from the riverside route.',
				'images'      => array( 'covers/cover-01.png', 'covers/cover-03.png', 'covers/cover-04.png' ),
			),
			array(
				'space'       => 'design-critique',
				'title'       => 'Work in progress',
				'description' => 'Screens shared for feedback this month.',
				'images'      => array( 'covers/cover-06.png', 'covers/cover-08.png', 'covers/cover-02.png' ),
			),
		);

		$made = 0;

		foreach ( $plan as $entry ) {
			$space_id = (int) ( $space_by_slug[ $entry['space'] ] ?? 0 );
			if ( $space_id <= 0 ) {
				continue;
			}

			// Authored by the space owner, which is who could really create it.
			$owner_id = (int) ( ( new SpaceService() )->get( $space_id )['owner_id'] ?? 0 );
			if ( $owner_id <= 0 ) {
				continue;
			}

			$items = array();
			foreach ( $entry['images'] as $rel ) {
				$media_id = $this->upload_bundled_media( $rel, $owner_id );
				if ( $media_id > 0 ) {
					$items[]             = $media_id;
					$manifest['media'][] = $media_id;
				}
			}
			if ( empty( $items ) ) {
				continue;
			}

			$album_id = $albums->create(
				$owner_id,
				array(
					'title'       => $entry['title'],
					'description' => $entry['description'],
					'privacy'     => 'public',
				)
			);
			if ( is_wp_error( $album_id ) || (int) $album_id <= 0 ) {
				continue;
			}

			$album_id = (int) $album_id;
			$albums->add_items( $album_id, $items );

			// Switch the space's Media tab ON. Without it the album exists and is
			// simply unreachable: the tab is gated on the per-space
			// `mvs_media_tab` field, which defaults OFF, so the REST route answers
			// media_tab_disabled and the web nav never renders the tab. Seeding
			// content nobody can open is worse than seeding none - it looks like
			// the feature is broken rather than switched off.
			update_space_meta( $space_id, 'mvs_media_tab', '1' );
			if ( method_exists( $albums, 'set_cover' ) ) {
				$albums->set_cover( $album_id, (int) $items[0] );
			}
			Galleries::assign_album_to_space( $album_id, $space_id );

			$manifest['albums'][] = $album_id;
			++$made;
		}

		if ( $made > 0 ) {
			$say( sprintf( 'Created %d space albums.', $made ) );
		}
	}

	/**
	 * Connect the site owner to the demo community.
	 *
	 * Without this the owner is a stranger on their own site. The whole roster
	 * follows and connects in a ring among ITSELF, so after seeding the person
	 * evaluating BuddyNext has an empty notification bell, no followers, nobody
	 * followed, and a personalised home feed with nothing personal in it. The
	 * community looks alive from every angle except theirs - which is the one
	 * angle they are looking from.
	 *
	 * Everything goes through the real services, so each follow and request
	 * fires its own notification exactly as a live one would; nothing is
	 * inserted straight into bn_notifications.
	 *
	 * Two requests are left PENDING on purpose. An owner opening a fresh install
	 * to "2 people want to connect" has something to DO, and it exercises the
	 * accept/decline path that an all-accepted graph never shows.
	 *
	 * Cleanup needs no special case: wp_delete_user() on each demo member fires
	 * UserCleanupListener, which purges their follows, connections and
	 * notifications platform-wide, including the ones pointing at the owner.
	 *
	 * @param array<int,int>    $user_ids    Seeded member IDs.
	 * @param FollowService     $follows     Follow service.
	 * @param ConnectionService $connections Connection service.
	 * @param callable          $say         Progress logger.
	 */
	private function seed_owner_relationships( array $user_ids, $follows, $connections, callable $say ): void {
		$owner_id = $this->resolve_owner_id();
		if ( $owner_id <= 0 || count( $user_ids ) < 8 ) {
			return;
		}

		$say( 'Introducing the site owner to the community…' );

		// Five members follow the owner: the bell has something in it.
		foreach ( array_slice( $user_ids, 0, 5 ) as $member_id ) {
			$follows->follow( $member_id, $owner_id );
		}

		// The owner follows four back, so Following is not empty and the
		// personalised home feed actually has a reason to differ from Explore.
		foreach ( array_slice( $user_ids, 2, 4 ) as $member_id ) {
			$follows->follow( $owner_id, $member_id );
		}

		// One settled connection, so the Connections tab is not empty either.
		$settled = $user_ids[0];
		if ( true === $connections->send_request( $settled, $owner_id ) ) {
			$connections->accept_request( $owner_id, $settled );
		}

		// Two still waiting on the owner.
		foreach ( array_slice( $user_ids, 5, 2 ) as $member_id ) {
			$connections->send_request( $member_id, $owner_id );
		}
	}

	/**
	 * The person who will be looking at this demo.
	 *
	 * Seeding runs from two places: the Tools screen, where the current user IS
	 * the owner, and WP-CLI, where there is no current user at all. Falling back
	 * to the first administrator keeps `wp buddynext demo seed` useful instead of
	 * silently skipping the one member who matters.
	 */
	private function resolve_owner_id(): int {
		$current = get_current_user_id();
		if ( $current > 0 ) {
			return $current;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return empty( $admins ) ? 0 : (int) $admins[0];
	}

	/**
	 * Give each member the topic categories they are interested in.
	 *
	 * Kept separate from the member loop because the field stores category IDs
	 * and the categories do not exist until the spaces block has run.
	 *
	 * Saved through save_profile() - the same path the REST API and the profile
	 * form use - so the values land in bn_profile_values, the caches clear, and
	 * `buddynext_member_interests_updated` fires exactly as it would for a real
	 * member. A direct INSERT would produce rows the suggestion engine reads but
	 * no event any integration could observe.
	 *
	 * @param array<int,int>    $user_ids    Seeded member IDs, in MEMBERS order.
	 * @param array<string,int> $cat_by_slug Category slug => ID.
	 * @param object            $profiles    ProfileService.
	 */
	private function seed_member_interests( array $user_ids, array $cat_by_slug, $profiles ): void {
		foreach ( $user_ids as $i => $user_id ) {
			$slugs = self::MEMBERS[ $i ]['topics'] ?? array();
			if ( empty( $slugs ) ) {
				continue;
			}

			$ids = array();
			foreach ( $slugs as $slug ) {
				if ( isset( $cat_by_slug[ $slug ] ) ) {
					$ids[] = (int) $cat_by_slug[ $slug ];
				}
			}
			if ( empty( $ids ) ) {
				continue;
			}

			$profiles->save_profile( $user_id, array( 'interests' => $ids ) );
		}
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
			$post_ids[]          = $poll_id;
			$this->engage_post( (int) $poll_id, 7, $user_ids, new CommentService(), new ReactionService() );
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

		// Reshares — members amplify a few popular posts (with a note) so the
		// feed carries share activity too. Each reshare is a 'share' post recorded
		// in the manifest, and demo bn_shares rows are cleared on cleanup, so the
		// go-live reset leaves nothing behind.
		if ( count( $post_ids ) > 2 && class_exists( ShareService::class ) ) {
			$say( 'Creating reshares…' );
			$share_service   = new ShareService();
			$share_comments  = new CommentService();
			$share_reactions = new ReactionService();
			$share_specs     = array(
				array(
					'by'   => 3,
					'post' => 0,
					'note' => 'Worth a look — sharing with the group.',
				),
				array(
					'by'   => 6,
					'post' => 2,
					'note' => 'Resharing this, great thread.',
				),
				array(
					'by'   => 9,
					'post' => 5,
					'note' => '',
				),
			);
			foreach ( $share_specs as $sk => $spec ) {
				$sharer   = $user_ids[ $spec['by'] % $n ];
				$target   = $post_ids[ $spec['post'] % count( $post_ids ) ];
				$share_id = $share_service->share( $sharer, $target, $spec['note'] );
				if ( is_wp_error( $share_id ) ) {
					continue;
				}
				$manifest['posts'][] = array(
					'id'     => (int) $share_id,
					'author' => $sharer,
				);
				$this->engage_post( (int) $share_id, 100 + $sk, $user_ids, $share_comments, $share_reactions );
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
	 * Generate (or top up to) a fresh MICRO community of synthetic members with a
	 * realistic social graph — built for repeatable local/Docker testing, not for
	 * the polished customer demo.
	 *
	 * Every member it creates carries the same `bn_demo` flag seed() uses, so the
	 * existing `demo cleanup` removes scale data too. What it wires:
	 *   - N synthetic members (login `bn_demo_scale_{n}`, default avatars).
	 *   - A clustered follow graph (ring neighbours + cross-links) so
	 *     friends-of-friends "who to follow" suggestions actually surface.
	 *   - Accepted AND pending connections, so the connection/follow request
	 *     inboxes have data to test against.
	 *   - Member-type assignments on a slice, space memberships on any existing
	 *     demo spaces, and a light scatter of posts so the feed is alive.
	 * Finally it flushes the suggestion + object caches. Idempotent per login, so
	 * re-running tops up to $target rather than duplicating.
	 *
	 * @param int           $target Total scale members to ensure exist (1..5000).
	 * @param callable|null $log   Optional progress callback( string $message ).
	 * @return array<string,int> Counts created.
	 */
	public function scale( int $target, ?callable $log = null ): array {
		$say    = $log ?? static function (): void {};
		$target = max( 1, min( 5000, $target ) );

		$follows       = buddynext_service( 'follows' );
		$connections   = buddynext_service( 'connections' );
		$space_members = buddynext_service( 'space_members' );
		$member_types  = buddynext_service( 'member_types' );

		$firsts = array( 'Alex', 'Sam', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Jamie', 'Avery', 'Quinn', 'Devon', 'Rowan', 'Noor', 'Kai', 'Luca', 'Mira', 'Ivo', 'Zara', 'Theo', 'Nadia', 'Omar', 'Lena', 'Diego', 'Yara', 'Finn' );
		$lasts  = array( 'Rivera', 'Chen', 'Kobayashi', 'Okafor', 'Silva', 'Nguyen', 'Patel', 'Larsson', 'Haddad', 'Ross', 'Bauer', 'Costa', 'Mensah', 'Reyes', 'Vidal', 'Kaur', 'Osei', 'Marin', 'Blum', 'Ferrari' );
		$roles  = array( 'Community builder', 'Indie maker', 'Frontend developer', 'Product designer', 'Writer', 'Photographer', 'Runner', 'Open-source contributor', 'Data nerd', 'Musician' );
		$cities = array( 'Berlin', 'Lisbon', 'Austin', 'Toronto', 'Nairobi', 'Manila', 'Delhi', 'Osaka', 'Amsterdam', 'Bogota' );

		// 1) Members ---------------------------------------------------------
		$ids     = array();
		$created = 0;
		for ( $n = 1; $n <= $target; $n++ ) {
			$existing = get_user_by( 'login', 'bn_demo_scale_' . $n );
			if ( $existing ) {
				$ids[] = (int) $existing->ID;
				continue;
			}
			$uid = $this->create_member(
				array(
					'login'    => 'scale_' . $n,
					'name'     => $firsts[ $n % count( $firsts ) ] . ' ' . $lasts[ intdiv( $n, count( $firsts ) ) % count( $lasts ) ],
					'headline' => $roles[ $n % count( $roles ) ],
					'job'      => $roles[ $n % count( $roles ) ],
					'location' => $cities[ $n % count( $cities ) ],
				)
			);
			if ( $uid > 0 ) {
				$ids[] = $uid;
				++$created;
				if ( 0 === $created % 100 ) {
					$say( sprintf( '  … %d members created', $created ) );
				}
			}
		}
		$count = count( $ids );
		if ( $count < 2 ) {
			$say( 'Not enough members to build a graph.' );
			return array( 'members' => $count );
		}
		$say( sprintf( 'Members: %d (new: %d)', $count, $created ) );

		// 2) Clustered follow graph (ring neighbours + two cross-links) so the
		// friends-of-friends signal has second-degree overlap to work with.
		$follow_edges = 0;
		foreach ( $ids as $i => $uid ) {
			$targets = array(
				$ids[ ( $i + 1 ) % $count ],
				$ids[ ( $i + 2 ) % $count ],
				$ids[ ( $i + 3 ) % $count ],
				$ids[ ( $i * 7 + 3 ) % $count ],
				$ids[ ( $i * 13 + 5 ) % $count ],
			);
			foreach ( array_unique( $targets ) as $t ) {
				if ( (int) $t !== (int) $uid && ! is_wp_error( $follows->follow( (int) $uid, (int) $t ) ) ) {
					++$follow_edges;
				}
			}
		}
		$say( sprintf( 'Follows: %d edges', $follow_edges ) );

		// 3) Connections — accepted (for the connections list) + pending (for the
		// request inbox). send_request() returns true on a fresh pending row.
		$accepted = 0;
		$pending  = 0;
		foreach ( $ids as $i => $uid ) {
			$peer = $ids[ ( $i + 8 ) % $count ];
			if ( (int) $peer !== (int) $uid && true === $connections->send_request( (int) $uid, (int) $peer ) ) {
				if ( true === $connections->accept_request( (int) $peer, (int) $uid ) ) {
					++$accepted;
				}
			}
			$pend = $ids[ ( $i * 5 + 9 ) % $count ];
			if ( (int) $pend !== (int) $uid && ! $connections->are_connected( (int) $uid, (int) $pend )
				&& true === $connections->send_request( (int) $uid, (int) $pend ) ) {
				++$pending;
			}
		}
		$say( sprintf( 'Connections: %d accepted, %d pending', $accepted, $pending ) );

		// 4) Member types on ~1 in 6; space memberships on existing demo spaces.
		$type_rows = is_object( $member_types ) && method_exists( $member_types, 'get_all' ) ? (array) $member_types->get_all() : array();
		$type_ids  = array_values( array_filter( array_map( static fn( $t ) => (int) ( $t['id'] ?? 0 ), $type_rows ) ) );
		$typed     = 0;
		if ( $type_ids ) {
			foreach ( $ids as $i => $uid ) {
				if ( 0 === $i % 6 ) {
					$member_types->assign_type( (int) $uid, $type_ids[ $i % count( $type_ids ) ], 1 );
					++$typed;
				}
			}
		}
		$manifest    = get_option( self::MANIFEST_OPTION, array() );
		$space_ids   = is_array( $manifest ) ? array_map( 'absint', (array) ( $manifest['spaces'] ?? array() ) ) : array();
		$space_joins = 0;
		if ( $space_ids && is_object( $space_members ) && method_exists( $space_members, 'join' ) ) {
			foreach ( $ids as $i => $uid ) {
				$sid = $space_ids[ $i % count( $space_ids ) ];
				if ( ! is_wp_error( $space_members->join( (int) $sid, (int) $uid ) ) ) {
					++$space_joins;
				}
			}
		}
		$say( sprintf( 'Member types: %d assigned. Space joins: %d.', $typed, $space_joins ) );

		// 5) A light scatter of posts so the feed is not empty.
		$posts         = new PostService();
		$created_posts = array();
		$snippets      = array( 'Just joined — excited to be here!', 'Anyone else testing at scale today?', 'Sharing a quick note with the community.', 'Loving the new activity feed.', 'What is everyone working on this week?' );
		foreach ( $ids as $i => $uid ) {
			if ( 0 !== $i % 4 ) {
				continue;
			}
			$res = $posts->create(
				(int) $uid,
				array(
					'type'    => 'text',
					'content' => $snippets[ $i % count( $snippets ) ],
				)
			);
			if ( ! is_wp_error( $res ) ) {
				$created_posts[] = array(
					'id'     => (int) $res,
					'author' => (int) $uid,
				);
			}
		}
		$post_ids = count( $created_posts );
		$say( sprintf( 'Posts: %d', $post_ids ) );

		// Record what we made in the SAME manifest seed() uses, so `demo cleanup`
		// replays and removes scale data too (the bn_demo flag alone is only the
		// safety net — cleanup iterates the manifest).
		$manifest          = is_array( $manifest ) ? $manifest : array();
		$manifest['users'] = array_values( array_unique( array_merge( (array) ( $manifest['users'] ?? array() ), array_map( 'intval', $ids ) ) ) );
		$manifest['posts'] = array_merge( (array) ( $manifest['posts'] ?? array() ), $created_posts );
		update_option( self::MANIFEST_OPTION, $manifest, false );

		// 6) Bust the per-viewer suggestion caches + object cache so the fresh
		// graph is reflected immediately.
		if ( is_object( $follows ) && method_exists( $follows, 'flush_suggestions_for' ) ) {
			foreach ( $ids as $uid ) {
				$follows->flush_suggestions_for( (int) $uid );
			}
		}
		wp_cache_flush();

		return array(
			'members'              => $count,
			'members_created'      => $created,
			'follows'              => $follow_edges,
			'connections_accepted' => $accepted,
			'connections_pending'  => $pending,
			'member_types'         => $typed,
			'space_joins'          => $space_joins,
			'posts'                => $post_ids,
		);
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

		$posts         = new PostService();
		$storage       = new ImageStorageService();
		$profiles      = new ProfileService();
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

		// Media rows (uploaded photos): delete_cascade through the WPMediaVerse
		// repo — the engine owns the wp_mvs_* tables + files, so this is the
		// API-correct teardown that leaves no orphaned media on the go-live reset.
		$demo_media = array_map( 'intval', (array) ( $manifest['media'] ?? array() ) );
		if ( ! empty( $demo_media ) && MediaClient::available() ) {
			$repo = MediaClient::repo();
			if ( is_object( $repo ) && method_exists( $repo, 'delete_cascade' ) ) {
				$say( 'Removing media…' );
				foreach ( $demo_media as $mid ) {
					$repo->delete_cascade( $mid );
				}
			}
		}

		// Space albums. The media inside them is deleted separately above; this
		// removes the album post itself, which would otherwise survive as an
		// empty collection pointing at a space that is about to be deleted too.
		$demo_albums = array_values( array_filter( array_map( 'intval', (array) ( $manifest['albums'] ?? array() ) ) ) );
		if ( ! empty( $demo_albums ) ) {
			$say( 'Removing space albums…' );
			foreach ( $demo_albums as $album_id ) {
				if ( 'mvs_album' === get_post_type( $album_id ) ) {
					wp_delete_post( $album_id, true );
				}
			}
		}

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
		// Categories this seeder created (starter categories are never touched).
		$bn_demo_cats = (array) ( $manifest['space_categories'] ?? array() );
		if ( ! empty( $bn_demo_cats ) ) {
			$say( 'Removing demo space categories…' );
			$bn_cat_service = new SpaceCategoryService();
			foreach ( array_map( 'absint', $bn_demo_cats ) as $bn_cat_id ) {
				if ( $bn_cat_id > 0 ) {
					$bn_cat_service->delete( $bn_cat_id );
				}
			}
		}

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
			// wp_delete_user() below fires `deleted_user` → UserCleanupListener,
			// which now purges this member's profile values, bookmarks, follows,
			// connections, space memberships and notifications platform-wide — so
			// the demo cleanup no longer needs per-table passes for those.
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

		// Bookmarks + DM threads need no explicit pass here, all via platform
		// hooks: deleting demo posts cascades their bookmark rows
		// (PostService::delete); wp_delete_user fires BN's UserCleanupListener
		// (remaining per-user bookmarks) AND WPMediaVerse's UserDeletionService,
		// which clears the members' conversation participation + messages and
		// sweeps any now-empty conversation thread. Nothing is left orphaned.
		// Reshares (bn_shares) need no explicit pass: PostService::delete() above
		// cascades the share rows when the demo posts are removed (it deletes
		// bn_shares by post_id), so the go-live reset clears them through the API.

		delete_option( self::MANIFEST_OPTION );
		$say( 'Demo data removed.' );

		return $removed;
	}

	// ── Private helpers ────────────────────────────────────────────────────

	/**
	 * Apply varied engagement (comments + reactions) to one post so every
	 * activity type - text, link/oEmbed, poll, reshare - carries the same kind
	 * of social proof. $seq drives the busy/quiet spread so Top/Active sorts
	 * have something to rank.
	 *
	 * @param int             $post_id   Target post.
	 * @param int             $seq       Sequence index (drives variation).
	 * @param int[]           $user_ids  Demo member IDs.
	 * @param CommentService  $comments  Comment service.
	 * @param ReactionService $reactions Reaction service.
	 * @return void
	 */
	private function engage_post( int $post_id, int $seq, array $user_ids, CommentService $comments, ReactionService $reactions ): void {
		$n = count( $user_ids );
		if ( 0 === $n ) {
			return;
		}
		$busy      = ( 0 === $seq % 4 );
		$quiet     = ( 0 === $seq % 5 );
		$comment_n = $quiet ? 0 : ( $busy ? 4 : 1 + ( $seq % 2 ) );
		$react_n   = $quiet ? 0 : min( $n, $busy ? 6 : 2 + ( $seq % 3 ) );
		for ( $c = 0; $c < $comment_n; $c++ ) {
			$commenter = $user_ids[ ( $seq + $c + 1 ) % $n ];
			$comments->create( $commenter, 'post', $post_id, self::COMMENTS[ ( $seq + $c ) % count( self::COMMENTS ) ] );
		}
		for ( $r = 0; $r < $react_n; $r++ ) {
			$reactor = $user_ids[ ( $seq + $r + 2 ) % $n ];
			$reactions->react( $reactor, 'post', $post_id, self::REACTIONS[ ( $seq + $r ) % count( self::REACTIONS ) ] );
		}
	}

	/**
	 * Create a single demo member, flagged for safe cleanup.
	 *
	 * @param array<string,string|array<int,string>|array<string,string>> $member Roster entry.
	 * @return int New user ID, or 0 on failure / already-exists.
	 */
	private function create_member( array $member ): int {
		$login = 'bn_demo_' . $member['login'];
		if ( username_exists( $login ) ) {
			return 0;
		}
		$email = $member['login'] . '@buddynext-demo.invalid';

		// A bio that COMPLEMENTS the one-line headline instead of repeating it:
		// composed from the roster's job/location (no new data needed), so the
		// member card never renders the headline twice. Empty when there is
		// nothing to add - the header then shows the headline alone.
		$bio = '';
		if ( '' !== $member['job'] && '' !== $member['location'] ) {
			$bio = $member['job'] . ' based in ' . $member['location'] . '.';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true ),
				'display_name' => $member['name'],
				'description'  => $bio,
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
		// Mark email verified so demo members can post/comment even when the site
		// has the email-verification gate enabled (else POST /posts 403s by design).
		update_user_meta( $user_id, 'buddynext_email_verified', '1' );
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
			// Through the same service the profile upload flow uses, so seeded
			// media resolves identically to a real upload and the storage keys
			// stay owned in one place (no demo-only shim).
			$avatars = new \BuddyNext\Profile\AvatarService();
			if ( 'avatar' === $kind ) {
				$avatars->save_avatar_url( (int) $id, esc_url_raw( (string) $stored ) );
			} else {
				$avatars->save_cover_url( (int) $id, (string) $stored );
			}
		}
	}
}
