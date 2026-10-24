<?php
/**
 * Shared "Portfolio" profile surface for ALL integrations and addons.
 *
 * Prevents profile-tab explosion: integrations never add their own tab. They
 * register a panel via the `buddynext_member_suite_panels` filter (or declare a
 * ProfilePanelModule on an addon), and this class renders every panel inside ONE
 * "Portfolio" tab. Ten integrations → one tab. Built once; every integration rides it.
 *
 * Lives in Free so an addon needs Free only. The cache keys on "own vs others",
 * not on the viewer, so a panel that is not owner-only may use only data that is
 * the same for every viewer; anything viewer-dependent must set `owner_only`.
 *
 * The Portfolio tab is registered on the unified Nav API (`buddynext_register_nav`);
 * each integration's panel becomes a sub-tab via `buddynext_nav_items` (add_subnav),
 * and each generated sub-tab carries its OWN `render` callable so the surface SSRs
 * exactly the active integration section through the shared PanelRenderer seam (the
 * same path core uses). The tab only appears when at least one panel has content.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use BuddyNext\Support\Monogram;

/**
 * Aggregates per-integration profile panels into one Portfolio tab.
 */
class SuitePanelHost {

	private const TAB_SLUG = 'portfolio';

	/**
	 * Fallback icon when an integration registers a slug with no SVG on disk.
	 */
	private const DEFAULT_ICON = 'folder';

	/**
	 * Object-cache group for a member's computed panel set (`buddynext_<domain>`).
	 * Two entries per member, `:own` and `:others`; the `buddynext_suite_panels_*`
	 * hooks below are the public contract that integrations wire against.
	 */
	private const CACHE_GROUP = 'buddynext_suite_panels';

	/**
	 * Default TTL (seconds) for the cached panel set. On a site with a persistent
	 * object cache this stops every profile view from re-running each integration's
	 * queries; it bounds staleness even if no explicit invalidation fires.
	 * Filterable via `buddynext_suite_panels_ttl`.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Per-request memo of computed panels, keyed by member id.
	 *
	 * @var array<int, array<int, array<string,mixed>>>
	 */
	private array $memo = array();

	/**
	 * Wire the tab + panel hooks. Called from Core\Plugin::init().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_nav' ) );
		// Each integration panel becomes a sub-tab under Portfolio — appended
		// per-request (the panel set is per-member) via the registry's public
		// mutation filter, so Portfolio renders as a parent with one sub-tab per
		// integration (Jobs / Resume / Courses / Certificates / Listings / …).
		add_filter( 'buddynext_nav_items', array( $this, 'add_subnav' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );

		// Invalidate a member's cached panel set when their content changes.
		// CPT-backed panels (Jobs, Resume, …) are covered generically by the author
		// of the saved/deleted post. For custom-table panels (badges, courses,
		// certificates) the bundled bridges call SuitePanelHost::flush() directly;
		// the `buddynext_suite_panels_changed` action is the equivalent seam for
		// THIRD-PARTY integrations that cannot reference this class.
		add_action( 'save_post', array( $this, 'invalidate_on_post_change' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'invalidate_on_post_delete' ), 10, 2 );
		add_action( 'buddynext_suite_panels_changed', array( $this, 'invalidate_from_action' ) );
	}

	/**
	 * Load the Portfolio panel styles on profile views only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( is_admin() || 'people' !== (string) get_query_var( 'bn_hub', '' ) ) {
			return;
		}
		wp_enqueue_style(
			'bn-suite',
			BUDDYNEXT_URL . 'assets/css/suite.css',
			array( 'bn-base', 'bn-shell' ),
			BUDDYNEXT_VERSION
		);
	}

	/**
	 * Resolve, normalise, filter and sort the panels for a member.
	 *
	 * @param int $member_id Profile owner.
	 * @return array<int, array<string,mixed>> Non-empty panels, sorted by priority.
	 */
	public function panels( int $member_id ): array {
		// Two answers per member: what the member sees on their own profile, and
		// what everyone else sees. A panel can show its owner more (their own
		// learning list), and one shared entry meant whoever opened the profile
		// first decided what the other got until the cache expired.
		$cache_key = self::cache_key( $member_id, get_current_user_id() === $member_id );

		if ( isset( $this->memo[ $cache_key ] ) ) {
			return $this->memo[ $cache_key ];
		}

		// Cross-request cache: skip the whole provider filter (every integration's
		// queries) when a prior request already computed this member's panels.
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$this->memo[ $cache_key ] = $cached;
			return $cached;
		}

		/**
		 * Register a Portfolio panel for a member.
		 *
		 * @param array<int,array<string,mixed>> $panels Accumulated panels.
		 * @param int                            $member_id Profile owner.
		 */
		$raw = (array) apply_filters( 'buddynext_member_suite_panels', array(), $member_id );

		$panels = array();
		foreach ( $raw as $panel ) {
			if ( ! is_array( $panel ) || empty( $panel['key'] ) ) {
				continue;
			}
			$items = isset( $panel['items'] ) && is_array( $panel['items'] ) ? $panel['items'] : array();
			$count = isset( $panel['count'] ) ? (int) $panel['count'] : count( $items );
			// A panel with neither items nor a positive count contributes nothing.
			if ( empty( $items ) && $count <= 0 ) {
				continue;
			}
			$panels[] = array(
				'key'        => (string) $panel['key'],
				'label'      => (string) ( $panel['label'] ?? '' ),
				'icon'       => $this->safe_icon( (string) ( $panel['icon'] ?? '' ), (string) $panel['key'] ),
				'count'      => $count,
				'priority'   => (int) ( $panel['priority'] ?? 50 ),
				'cta'        => isset( $panel['cta'] ) && is_array( $panel['cta'] ) ? $panel['cta'] : array(),
				// Owner-only action (e.g. "Manage on Career Board" → their dashboard).
				// Rendered only when the viewer is the profile owner.
				'owner_cta'  => isset( $panel['owner_cta'] ) && is_array( $panel['owner_cta'] ) ? $panel['owner_cta'] : array(),
				// An owner-only panel (e.g. a personal "continue learning" shelf) is
				// withheld whole from other viewers by the sub-nav + render + REST guards.
				'owner_only' => ! empty( $panel['owner_only'] ),
				'items'      => $items,
			);
		}

		// Sort by priority, then key — a stable, deterministic order even if two
		// integrations pick the same priority (so panels never shuffle per request).
		usort(
			$panels,
			static fn( array $a, array $b ): int => array( $a['priority'], $a['key'] ) <=> array( $b['priority'], $b['key'] )
		);

		$this->memo[ $cache_key ] = $panels;

		/** This filter is documented in this method (see CACHE_TTL). */
		$ttl = (int) apply_filters( 'buddynext_suite_panels_ttl', self::CACHE_TTL, $member_id );
		wp_cache_set( $cache_key, $panels, self::CACHE_GROUP, max( 0, $ttl ) );

		return $panels;
	}

	/**
	 * Drop a member's cached panel set — rebuilt lazily on the next profile view.
	 *
	 * Integrations that mutate panel data held outside a CPT (custom-table badges,
	 * courses, certificates) must call this after a write:
	 * `do_action( 'buddynext_suite_panels_changed', $member_id )`.
	 *
	 * @param int $member_id Profile owner whose panels changed.
	 * @return void
	 */
	public static function flush( int $member_id ): void {
		if ( $member_id > 0 ) {
			wp_cache_delete( self::cache_key( $member_id, true ), self::CACHE_GROUP );
			wp_cache_delete( self::cache_key( $member_id, false ), self::CACHE_GROUP );
		}
	}

	/**
	 * Cache key for a member's panels as seen by the member or by anyone else.
	 *
	 * @param int  $member_id Profile owner.
	 * @param bool $own       True for the member's own view.
	 * @return string
	 */
	private static function cache_key( int $member_id, bool $own ): string {
		return $member_id . ( $own ? ':own' : ':others' );
	}

	/**
	 * Invalidate the cache for the author of a saved post — covers every CPT-backed
	 * panel generically (Jobs, Resume, …) without coupling to any one integration.
	 *
	 * @param int      $post_id Saved post id.
	 * @param \WP_Post $post    Saved post object.
	 * @return void
	 */
	public function invalidate_on_post_change( $post_id, $post ): void {
		// Switched to another site of a network: the community tables are not there.
		if ( ! buddynext_is_community_site() ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$author = is_object( $post ) ? (int) $post->post_author : 0;
		self::flush( $author );
	}

	/**
	 * Invalidate the cache for the author of a deleted post.
	 *
	 * @param int           $post_id Deleted post id.
	 * @param \WP_Post|null $post    Deleted post object (WP 5.5+); may be null on older cores.
	 * @return void
	 */
	public function invalidate_on_post_delete( $post_id, $post = null ): void {
		// Switched to another site of a network: the community tables are not there.
		if ( ! buddynext_is_community_site() ) {
			return;
		}
		$author = is_object( $post ) ? (int) $post->post_author : (int) get_post_field( 'post_author', (int) $post_id );
		self::flush( $author );
	}

	/**
	 * Invalidate from the canonical `buddynext_suite_panels_changed` action, tolerant
	 * of a non-int hook argument.
	 *
	 * @param mixed $member_id Profile owner whose panels changed.
	 * @return void
	 */
	public function invalidate_from_action( $member_id ): void {
		self::flush( (int) $member_id );
	}

	/**
	 * Validate a panel's icon slug, falling back to a known-good default when the
	 * SVG is missing — so a new integration that picks a non-existent slug shows a
	 * sensible icon instead of a blank, and the mistake surfaces loudly in debug.
	 *
	 * @param string $icon Requested icon slug.
	 * @param string $key  Panel key, for the debug notice.
	 * @return string A slug guaranteed to resolve to an SVG.
	 */
	private function safe_icon( string $icon, string $key ): string {
		if ( '' !== $icon && \BuddyNext\Core\IconService::has( $icon ) ) {
			return $icon;
		}
		if ( '' !== $icon ) {
			_doing_it_wrong(
				'buddynext_member_suite_panels',
				sprintf(
					/* translators: 1: icon slug, 2: panel key. */
					esc_html__( 'Portfolio panel "%2$s" requested icon "%1$s", which has no SVG in buddynext/assets/icons. Falling back to the default. Add the icon file or pick an existing slug.', 'buddynext' ),
					esc_html( $icon ),
					esc_html( $key )
				),
				'0.4.0'
			);
		}
		return self::DEFAULT_ICON;
	}

	/**
	 * A member's panels as visible to the current viewer: owner-only panels (e.g.
	 * the personal "continue learning" shelf) are dropped for everyone but the
	 * profile owner. The single visibility gate the Portfolio tab, its badge, and
	 * its sub-tabs all read, so they never disagree.
	 *
	 * @param \BuddyNext\Nav\NavContext $c Nav context (carries subject + viewer).
	 * @return array<int,array<string,mixed>>
	 */
	private function visible_panels( \BuddyNext\Nav\NavContext $c ): array {
		$is_self = $c->is_self();
		return array_values(
			array_filter(
				$this->panels( $c->subject_id ),
				static fn( array $panel ): bool => $is_self || empty( $panel['owner_only'] )
			)
		);
	}

	/**
	 * Register the Portfolio tab on the member-profile nav via the unified Nav API.
	 *
	 * Hooked on `buddynext_register_nav`. Gated to members with at least one suite
	 * panel (lazy condition), with a lazy total-count badge.
	 *
	 * @param \BuddyNext\Nav\NavRegistry $registry The shared nav registry.
	 * @return void
	 */
	public function register_nav( \BuddyNext\Nav\NavRegistry $registry ): void {
		$registry->register(
			array(
				'id'        => self::TAB_SLUG,
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Portfolio', 'buddynext' ),
				// No icon: profile primary tabs are text-only for consistency.
				'priority'  => 85,
				'condition' => fn( \BuddyNext\Nav\NavContext $c ): bool => ! empty( $this->visible_panels( $c ) ),
				// Portfolio owns no panel of its own — PanelRenderer falls through to
				// its first sub-tab. A clean /portfolio/ URL deep-links there.
				'url'       => static fn( \BuddyNext\Nav\NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . self::TAB_SLUG . '/',
				'count'     => fn( \BuddyNext\Nav\NavContext $c ): int => (int) array_sum( array_column( $this->visible_panels( $c ), 'count' ) ),
			)
		);
	}

	/**
	 * Append one sub-tab per integration panel under the Portfolio parent.
	 *
	 * Hooked on `buddynext_nav_items` (runs per-context with the live subject, so
	 * the per-member panel set becomes the sub-nav). Each suite panel is a sub-nav
	 * child of 'portfolio'; the parent is repointed at its first child so clicking
	 * Portfolio opens the first integration. A panel `key` is the member-facing
	 * sub-tab slug (it appears in the clean deep-link URL, e.g. /members/x/jobs/),
	 * so keep it short, human, and unique among profile tab ids — never the partner
	 * post-type or plugin name.
	 *
	 * @param mixed                     $items Raw registration arrays for the surface.
	 * @param \BuddyNext\Nav\NavContext $ctx   Resolution context.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_subnav( $items, $ctx = null ): array {
		$items = is_array( $items ) ? $items : array();
		if ( ! ( $ctx instanceof \BuddyNext\Nav\NavContext ) || 'profile' !== $ctx->surface ) {
			return $items;
		}
		// Visible-to-viewer panels only: an owner-only shelf gets no sub-tab for
		// other visitors — it stays off their profile entirely.
		$panels = $this->visible_panels( $ctx );
		if ( empty( $panels ) ) {
			return $items;
		}

		// Portfolio owns no panel itself — PanelRenderer falls through to its first
		// sub-tab, so the parent needs no tab/render and no repoint here.

		$profile_url = \BuddyNext\Core\PageRouter::profile_url( $ctx->subject_id );
		foreach ( $panels as $panel ) {
			$key     = (string) $panel['key'];
			$items[] = array(
				'id'       => $key,
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => self::TAB_SLUG,
				'label'    => (string) $panel['label'],
				// Clean deep-link + the content-seam render. Each generated sub-tab
				// carries its OWN render closure capturing that panel's data, so the
				// surface SSRs exactly the active integration section (not all of them).
				'url'      => '' !== $profile_url ? $profile_url . $key . '/' : '',
				'render'   => function ( \BuddyNext\Nav\NavContext $c ) use ( $panel ): void {
					$this->render_section( $panel, $c->is_self() );
				},
				'icon'     => (string) $panel['icon'],
				'count'    => (int) $panel['count'],
				'priority' => (int) $panel['priority'],
			);
		}

		return $items;
	}

	/**
	 * Render one integration's panel section.
	 *
	 * @param array<string,mixed> $panel    Normalised panel.
	 * @param bool                $is_owner Whether the viewer owns this profile.
	 * @return void
	 */
	private function render_section( array $panel, bool $is_owner ): void {
		// An owner-only panel renders for the profile owner alone (the sub-tab is
		// already withheld from other visitors; this is the belt-and-braces guard).
		if ( ! empty( $panel['owner_only'] ) && ! $is_owner ) {
			return;
		}
		echo '<section class="bn-card bn-suite-panel">';

		echo '<header class="bn-suite-panel__head">';
		echo '<h3 class="bn-suite-panel__title">';
		if ( '' !== $panel['icon'] && function_exists( 'buddynext_icon' ) ) {
			buddynext_icon( (string) $panel['icon'] );
		}
		echo ' ' . esc_html( (string) $panel['label'] );
		if ( $panel['count'] > 0 ) {
			echo ' <span class="bn-suite-panel__count">' . esc_html( number_format_i18n( (int) $panel['count'] ) ) . '</span>';
		}
		echo '</h3>';

		echo '<span class="bn-suite-panel__actions">';
		// Owner-only: jump to the partner's own dashboard to manage (we only
		// summarise here — the full dashboard stays on the partner).
		if ( $is_owner && ! empty( $panel['owner_cta']['url'] ) && ! empty( $panel['owner_cta']['label'] ) ) {
			echo '<a class="bn-link bn-suite-panel__manage" href="' . esc_url( (string) $panel['owner_cta']['url'] ) . '">'
				. esc_html( (string) $panel['owner_cta']['label'] ) . '</a>';
		}
		if ( ! empty( $panel['cta']['url'] ) && ! empty( $panel['cta']['label'] ) ) {
			echo '<a class="bn-link bn-suite-panel__cta" href="' . esc_url( (string) $panel['cta']['url'] ) . '">'
				. esc_html( (string) $panel['cta']['label'] ) . '</a>';
		}
		echo '</span>';
		echo '</header>';

		if ( ! empty( $panel['items'] ) ) {
			echo '<ul class="bn-suite-panel__items" role="list">';
			foreach ( $panel['items'] as $item ) {
				if ( is_array( $item ) && ! empty( $item['title'] ) ) {
					$this->render_item( $item );
				}
			}
			echo '</ul>';
		}

		echo '</section>';
	}

	/**
	 * Render one panel item as a media-card row: a per-row medal (image, else a
	 * deterministic initials monogram — never the section icon, which would just
	 * repeat down the list) + title + optional meta + a chevron when it links out.
	 *
	 * Supported item keys: `title` (required), `url`, `image` (thumbnail URL),
	 * `meta` (one-line subtitle; also seeds the monogram), `facts` (string[] of
	 * short structured facts appended to the meta line as a middot row — e.g.
	 * location, type, posted), and `highlight` (a compact emphasis pinned to the
	 * end of the title line — e.g. a salary range). `facts` and `highlight` are
	 * optional; panels that set neither render exactly as before.
	 *
	 * @param array<string,mixed> $item Panel item.
	 * @return void
	 */
	private function render_item( array $item ): void {
		$url            = (string) ( $item['url'] ?? '' );
		$image          = (string) ( $item['image'] ?? '' );
		$meta           = (string) ( $item['meta'] ?? '' );
		$title          = (string) ( $item['title'] ?? '' );
		$highlight      = (string) ( $item['highlight'] ?? '' );
		$highlight_icon = (string) ( $item['highlight_icon'] ?? '' );
		$facts          = isset( $item['facts'] ) && is_array( $item['facts'] ) ? $item['facts'] : array();

		// The meta line is the primary subtitle (e.g. company) followed by any
		// structured facts. Each is a discrete span (separators come from CSS) so
		// the row can truncate to one line on desktop yet wrap to show every fact
		// on mobile, without a middot ever dangling at a line break.
		$meta_parts = array();
		if ( '' !== $meta ) {
			$meta_parts[] = $meta;
		}
		foreach ( $facts as $fact ) {
			$fact = trim( (string) $fact );
			if ( '' !== $fact ) {
				$meta_parts[] = $fact;
			}
		}

		echo '<li class="bn-suite-panel__item">';

		if ( '' !== $url ) {
			echo '<a class="bn-suite-panel__item-link" href="' . esc_url( $url ) . '">';
		} else {
			echo '<div class="bn-suite-panel__item-link is-static">';
		}

		// Per-row identity: a real image (logo/photo) when present, else a
		// monogram seeded from the subtitle (e.g. company) or the title — so each
		// row is distinct and the header icon is never repeated in the list.
		if ( '' !== $image ) {
			echo '<span class="bn-suite-panel__thumb"><img src="' . esc_url( $image ) . '" alt="" loading="lazy" /></span>';
		} else {
			$seed = '' !== $meta ? $meta : $title;
			echo '<span class="bn-suite-panel__thumb is-mono" data-tone="' . esc_attr( (string) Monogram::tone( $seed ) ) . '" aria-hidden="true">'
				. esc_html( Monogram::initials( $seed ) ) . '</span>';
		}

		echo '<span class="bn-suite-panel__item-body">';
		echo '<span class="bn-suite-panel__item-line">';
		echo '<span class="bn-suite-panel__item-title">' . esc_html( $title ) . '</span>';
		if ( '' !== $highlight ) {
			echo '<span class="bn-suite-panel__item-highlight">';
			if ( '' !== $highlight_icon && function_exists( 'buddynext_icon' ) ) {
				buddynext_icon( $highlight_icon );
			}
			echo esc_html( $highlight ) . '</span>';
		}
		echo '</span>';
		if ( ! empty( $meta_parts ) ) {
			echo '<span class="bn-suite-panel__item-meta">';
			$first = true;
			foreach ( $meta_parts as $part ) {
				// A spaced middot between facts is a real wrap opportunity, so the
				// line can break BETWEEN facts on mobile while each fact stays whole.
				if ( ! $first ) {
					echo '<span class="bn-suite-panel__fact-sep" aria-hidden="true"> · </span>';
				}
				echo '<span class="bn-suite-panel__fact">' . esc_html( $part ) . '</span>';
				$first = false;
			}
			echo '</span>';
		}
		echo '</span>';

		if ( '' !== $url && function_exists( 'buddynext_icon' ) ) {
			echo '<span class="bn-suite-panel__item-go" aria-hidden="true">';
			buddynext_icon( 'chevron-right' );
			echo '</span>';
		}

		echo '' !== $url ? '</a>' : '</div>';
		echo '</li>';
	}
}
