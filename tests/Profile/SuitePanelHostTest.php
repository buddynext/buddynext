<?php
/**
 * Tests for the shared Portfolio profile surface (one tab, many integrations).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\SuitePanelHost;

/**
 * @covers \BuddyNext\Profile\SuitePanelHost
 */
class SuitePanelHostTest extends \WP_UnitTestCase {

	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		$this->member_id = self::factory()->user->create();
	}

	private function register_panels(): void {
		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels, int $member_id ): array {
				// Lower priority sorts first.
				$panels[] = array(
					'key'      => 'courses',
					'label'    => 'Courses',
					'priority' => 30,
					'items'    => array( array( 'title' => 'Intro to WP', 'url' => 'https://x/c1' ) ),
				);
				$panels[] = array(
					'key'      => 'jobs',
					'label'    => 'Jobs',
					'priority' => 10,
					'count'    => 2,
					'items'    => array(
						array( 'title' => 'Senior PHP Dev', 'url' => 'https://x/j1', 'meta' => 'Acme' ),
						array( 'title' => 'Designer', 'url' => 'https://x/j2' ),
					),
				);
				// Empty panel — must be dropped.
				$panels[] = array( 'key' => 'events', 'label' => 'Events', 'items' => array() );
				return $panels;
			},
			10,
			2
		);
	}

	public function test_panels_filter_sort_and_drop_empty(): void {
		$this->register_panels();
		$panels = ( new SuitePanelHost() )->panels( $this->member_id );

		$this->assertCount( 2, $panels, 'empty panel dropped' );
		$this->assertSame( 'jobs', $panels[0]['key'], 'sorted by priority' );
		$this->assertSame( 'courses', $panels[1]['key'] );
	}

	public function test_missing_icon_falls_back_to_default(): void {
		// A future integration that picks a non-existent icon slug must not render
		// blank — it falls back to the default and trips _doing_it_wrong.
		$this->setExpectedIncorrectUsage( 'buddynext_member_suite_panels' );

		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels ): array {
				$panels[] = array(
					'key'      => 'bogus',
					'label'    => 'Bogus',
					'icon'     => 'no-such-icon-zzz',
					'priority' => 15,
					'items'    => array( array( 'title' => 'Thing', 'url' => 'https://x/1' ) ),
				);
				$panels[] = array(
					'key'      => 'good',
					'label'    => 'Good',
					'icon'     => 'briefcase',
					'priority' => 16,
					'items'    => array( array( 'title' => 'Job', 'url' => 'https://x/2' ) ),
				);
				return $panels;
			}
		);

		$panels = ( new SuitePanelHost() )->panels( $this->member_id );
		$byKey  = array_column( $panels, 'icon', 'key' );

		$this->assertSame( 'folder', $byKey['bogus'], 'missing icon → default' );
		$this->assertSame( 'briefcase', $byKey['good'], 'valid icon kept' );
	}

	public function test_tab_added_only_when_panels_exist(): void {
		$ctx = new \BuddyNext\Nav\NavContext( 'profile', $this->member_id, $this->member_id );

		// No providers → the Portfolio nav item's condition gates it out.
		$portfolio = $this->register_portfolio_nav_item( new SuitePanelHost() );
		$this->assertNotNull( $portfolio, 'Portfolio nav item is always registered' );
		$this->assertFalse( ( $portfolio['condition'] )( $ctx ), 'no panels → tab hidden' );

		// A provider now has data for this member. panels() is cached per member for 5
		// minutes, so the "no panels" answer above is currently the cached one - exactly
		// as it would be in production. What clears it there is the data change itself
		// (save_post / deleted_post, or a partner firing buddynext_suite_panels_changed),
		// so fire that same signal here rather than reaching past the cache.
		$this->register_panels();
		do_action( 'buddynext_suite_panels_changed', $this->member_id );

		// With providers → condition passes and count = sum of panel counts (2 + 1).
		$portfolio2 = $this->register_portfolio_nav_item( new SuitePanelHost() );
		$this->assertNotNull( $portfolio2 );
		$this->assertTrue( ( $portfolio2['condition'] )( $ctx ), 'panels present → tab shown' );
		$this->assertSame( 3, ( $portfolio2['count'] )( $ctx ) );
	}

	/**
	 * Run SuitePanelHost::register_nav() against a fresh registry and return the
	 * raw 'portfolio' registration array (with its condition + count closures).
	 *
	 * @param SuitePanelHost $profile Instance under test.
	 * @return array<string,mixed>|null
	 */
	private function register_portfolio_nav_item( SuitePanelHost $profile ): ?array {
		$registry = new \BuddyNext\Nav\NavRegistry();
		$profile->register_nav( $registry );

		$prop = new \ReflectionProperty( \BuddyNext\Nav\NavRegistry::class, 'items' );
		foreach ( (array) $prop->getValue( $registry ) as $item ) {
			if ( is_array( $item ) && 'portfolio' === ( $item['id'] ?? '' ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * The owner's management link must never reach a visitor.
	 *
	 * Portfolio is no longer server-rendered by SuitePanelHost::render_panel() - the panels
	 * are delivered over REST and rendered client-side. So the enforcement point moved to
	 * PortfolioController, and that is where this has to be tested: a template that hides
	 * the link would not have protected the REST payload, and the REST payload is what a
	 * visitor can actually fetch.
	 *
	 * @return void
	 */
	public function test_owner_cta_shown_only_to_owner(): void {
		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels, int $member_id ): array {
				$panels[] = array(
					'key'       => 'jobs',
					'label'     => 'Jobs',
					'items'     => array(
						array(
							'title' => 'Role',
							'url'   => 'https://x/j1',
						),
					),
					'owner_cta' => array(
						'label' => 'Manage on Career Board',
						'url'   => 'https://x/dashboard',
					),
				);
				return $panels;
			},
			10,
			2
		);

		// A visitor gets the panel, but stripped of the owner's management link.
		wp_set_current_user( self::factory()->user->create() );
		$visitor = $this->fetch_portfolio();

		$this->assertFalse( $visitor['is_owner'] );
		$this->assertSame( array(), $visitor['panels'][0]['owner_cta'], "a visitor never receives the owner's dashboard link" );

		// The owner gets it.
		wp_set_current_user( $this->member_id );
		$owner = $this->fetch_portfolio();

		$this->assertTrue( $owner['is_owner'] );
		$this->assertSame( 'https://x/dashboard', $owner['panels'][0]['owner_cta']['url'] );
		$this->assertSame( 'Manage on Career Board', $owner['panels'][0]['owner_cta']['label'] );
	}

	/**
	 * GET the member's portfolio over REST as the current user.
	 *
	 * @return array<string,mixed>
	 */
	private function fetch_portfolio(): array {
		$request  = new \WP_REST_Request( 'GET', "/buddynext/v1/members/{$this->member_id}/portfolio" );
		$response = rest_do_request( $request );

		$this->assertFalse( $response->is_error(), 'the portfolio route must resolve' );

		return (array) $response->get_data();
	}

	/**
	 * The portfolio payload carries one section per integration, in priority order,
	 * with each item's title and outbound link - and drops a provider that has nothing
	 * to show (an empty section is worse than none).
	 *
	 * @return void
	 */
	public function test_portfolio_payload_carries_sections_and_links(): void {
		$this->register_panels();
		wp_set_current_user( $this->member_id );

		$data = $this->fetch_portfolio();
		$keys = array_column( $data['panels'], 'key' );

		$this->assertSame( array( 'jobs', 'courses' ), $keys, 'lower priority sorts first; the empty Events panel is dropped' );

		$jobs = $data['panels'][0];
		$this->assertSame( 'Senior PHP Dev', $jobs['items'][0]['title'] );
		$this->assertSame( 'https://x/j1', $jobs['items'][0]['url'] );
		$this->assertSame( 'Acme', $jobs['items'][0]['meta'] );
	}

	/**
	 * Register an employer's empty Jobs panel: owner-only, an empty-state line and
	 * a "Post a job" action, no items.
	 *
	 * @param bool $owner_only Whether the provider marks it owner-only.
	 * @return void
	 */
	private function register_empty_owner_panel( bool $owner_only = true ): void {
		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels ) use ( $owner_only ): array {
				$panels[] = array(
					'key'           => 'jobs',
					'label'         => 'Jobs',
					'icon'          => 'briefcase',
					'owner_only'    => $owner_only,
					'owner_empty'   => 'You have not posted a job yet.',
					'owner_primary' => array(
						'label' => 'Post a job',
						'url'   => 'https://x/post-a-job/',
					),
					'items'         => array(),
				);
				return $panels;
			}
		);
	}

	/**
	 * Render the Jobs sub-tab the way the profile surface does, for a viewer.
	 *
	 * @param int $viewer_id Viewer.
	 * @return string HTML, or '' when the viewer gets no Jobs sub-tab.
	 */
	private function render_jobs_subtab( int $viewer_id ): string {
		$ctx = new \BuddyNext\Nav\NavContext( 'profile', $this->member_id, $viewer_id );
		foreach ( ( new SuitePanelHost() )->add_subnav( array(), $ctx ) as $item ) {
			if ( 'jobs' === $item['id'] ) {
				ob_start();
				( $item['render'] )( $ctx );
				return (string) ob_get_clean();
			}
		}
		return '';
	}

	public function test_owner_only_empty_state_survives_and_renders_the_action(): void {
		$this->register_empty_owner_panel();
		wp_set_current_user( $this->member_id );

		$panels = ( new SuitePanelHost() )->panels( $this->member_id );
		$this->assertCount( 1, $panels, 'an owner-only empty state is kept although it has no items' );

		$html = $this->render_jobs_subtab( $this->member_id );
		$this->assertStringContainsString( 'You have not posted a job yet.', $html );
		$this->assertStringContainsString( 'https://x/post-a-job/', $html );
		$this->assertStringContainsString( 'data-variant="primary"', $html );
	}

	public function test_owner_empty_state_never_reaches_a_visitor(): void {
		$this->register_empty_owner_panel();
		$visitor = self::factory()->user->create();
		wp_set_current_user( $visitor );

		$this->assertSame( '', $this->render_jobs_subtab( $visitor ), 'no Jobs sub-tab for anyone but the owner' );
	}

	public function test_empty_state_requires_owner_only(): void {
		// A public panel cannot keep itself alive with an empty-state line: every
		// visitor would see an empty card.
		$this->register_empty_owner_panel( false );

		$this->assertSame( array(), ( new SuitePanelHost() )->panels( $this->member_id ) );
	}

	public function test_logo_image_is_shown_whole_and_a_photo_keeps_the_crop(): void {
		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels ): array {
				$panels[] = array(
					'key'   => 'jobs',
					'label' => 'Jobs',
					'items' => array(
						array(
							'title' => 'Engineer',
							'image' => 'https://x/acme-logo.png',
							'logo'  => true,
						),
						array(
							'title' => 'Designer',
							'image' => 'https://x/photo.jpg',
						),
					),
				);
				return $panels;
			}
		);
		wp_set_current_user( $this->member_id );

		$html = $this->render_jobs_subtab( $this->member_id );

		$this->assertStringContainsString( '<img class="bn-suite-panel__logo" src="https://x/acme-logo.png" alt="" loading="lazy" decoding="async" width="48" height="48" />', $html );
		$this->assertStringContainsString( '<img src="https://x/photo.jpg" alt=""', $html, 'a photo or cover keeps the square crop' );
	}

	/**
	 * Register a Jobs panel with a grouped view of $count companies.
	 *
	 * @param int $count Companies.
	 * @return void
	 */
	private function register_grouped_panel( int $count ): void {
		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels ) use ( $count ): array {
				$groups = array();
				for ( $i = 1; $i <= $count; $i++ ) {
					$groups[] = array(
						'name'    => 'Company ' . $i,
						'url'     => 1 === $i ? 'https://x/companies/one/' : '',
						'logo'    => 1 === $i ? 'https://x/one-logo.png' : '',
						'summary' => '2 open jobs',
						'items'   => array(
							array(
								'title'      => 'Role ' . $i,
								'url'        => 'https://x/job/role-' . $i . '/',
								'meta'       => 'Lisbon · Posted 2 days ago',
								'meta_short' => 'Lisbon · 2 days ago',
								'badge'      => 'Full time',
							),
						),
					);
				}
				$panels[] = array(
					'key'           => 'jobs',
					'label'         => 'Jobs',
					'icon'          => 'briefcase',
					'count'         => $count,
					'items'         => array( array( 'title' => 'Role 1' ) ),
					'owner_primary' => array(
						'label' => 'Post a job',
						'url'   => 'https://x/post-a-job/',
					),
					'sections'      => array(
						'groups'           => $groups,
						'shown'            => 5,
						'more_label'       => 'Show 2 more companies',
						'link_label'       => 'View all jobs',
						'link_label_short' => 'View all',
						'empty'            => 'No open jobs right now.',
					),
				);
				return $panels;
			}
		);
	}

	public function test_grouped_view_renders_labelled_sections_with_links_and_rows(): void {
		$this->register_grouped_panel( 2 );
		$visitor = self::factory()->user->create();
		wp_set_current_user( $visitor );

		$html = $this->render_jobs_subtab( $visitor );

		$this->assertSame( 2, substr_count( $html, '<section class="bn-suite-group"' ), 'one section per company' );
		$this->assertStringContainsString( '<section class="bn-suite-group" aria-labelledby="bn-suite-group-jobs-0">', $html );
		$this->assertStringContainsString( '<h4 class="bn-suite-group__name" id="bn-suite-group-jobs-0">Company 1</h4>', $html );
		$this->assertStringContainsString( 'href="https://x/companies/one/" aria-describedby="bn-suite-group-jobs-0"', $html );
		$this->assertStringContainsString( '>View all jobs</span>', $html );
		$this->assertStringContainsString( '>View all</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'bn-suite-group__all' ), 'no link for a company without a page' );
		$this->assertStringContainsString( 'aria-hidden="true"><img class="bn-suite-panel__logo" src="https://x/one-logo.png" alt=""', $html, 'decorative logo' );
		$this->assertStringContainsString( 'is-mono" data-tone=', $html, 'initials tile without a logo' );
		$this->assertStringContainsString( '<a class="bn-suite-group__job" href="https://x/job/role-1/">', $html );
		$this->assertStringContainsString( 'Lisbon · Posted 2 days ago', $html );
		$this->assertStringContainsString( 'Lisbon · 2 days ago', $html );
		$this->assertStringContainsString( '<span class="bn-suite-group__badge">Full time</span>', $html );
		$this->assertStringNotContainsString( '<details', $html, 'nothing hidden with two companies' );
		$this->assertStringNotContainsString( 'bn-suite-panel__items', $html, 'sections replace the flat list' );
		$this->assertStringNotContainsString( 'Post a job', $html, 'owner action stays owner-only' );
	}

	public function test_grouped_view_hides_extra_companies_behind_a_disclosure(): void {
		$this->register_grouped_panel( 7 );
		wp_set_current_user( $this->member_id );

		$html = $this->render_jobs_subtab( $this->member_id );
		$more = strpos( $html, '<details class="bn-suite-groups__more">' );

		$this->assertNotFalse( $more );
		$this->assertSame( 7, substr_count( $html, '<section class="bn-suite-group"' ), 'every company is in the HTML' );
		$this->assertSame( 2, substr_count( substr( $html, $more ), '<section class="bn-suite-group"' ), 'two behind the reveal' );
		$this->assertStringContainsString( '<summary class="bn-suite-groups__toggle"><span class="bn-suite-groups__toggle-more">Show 2 more companies</span>', $html );
		$this->assertStringContainsString( 'aria-labelledby="bn-suite-group-jobs-6"', $html, 'unique heading ids past the fold' );
		$this->assertStringContainsString( 'https://x/post-a-job/', $html, 'the owner keeps Post a job' );
	}

	public function test_grouped_view_with_no_group_says_so(): void {
		$this->register_grouped_panel( 0 );
		wp_set_current_user( $this->member_id );

		$html = $this->render_jobs_subtab( $this->member_id );

		$this->assertStringContainsString( '<p class="bn-suite-groups__empty">No open jobs right now.</p>', $html );
		$this->assertStringNotContainsString( 'bn-suite-group"', $html );
	}

	public function test_owner_empty_state_still_wins_over_an_empty_grouped_view(): void {
		add_filter(
			'buddynext_member_suite_panels',
			static function ( array $panels ): array {
				$panels[] = array(
					'key'           => 'jobs',
					'label'         => 'Jobs',
					'owner_only'    => true,
					'owner_empty'   => 'You have not posted a job yet.',
					'owner_primary' => array(
						'label' => 'Post a job',
						'url'   => 'https://x/post-a-job/',
					),
					'items'         => array(),
					'sections'      => array(
						'groups' => array(),
						'empty'  => 'No open jobs right now.',
					),
				);
				return $panels;
			}
		);
		wp_set_current_user( $this->member_id );

		$html = $this->render_jobs_subtab( $this->member_id );

		$this->assertStringContainsString( 'You have not posted a job yet.', $html );
		$this->assertStringContainsString( 'https://x/post-a-job/', $html );
		$this->assertStringNotContainsString( 'No open jobs right now.', $html, 'one empty state, not two' );
	}
}
