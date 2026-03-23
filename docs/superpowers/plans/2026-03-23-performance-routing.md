# Performance Routing Architecture — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` to execute this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor BuddyNext routing from `pagename=` query (hijacks client pages) to `bn_hub` query-var routing with `request`-filter WP_Query suppression, plugin isolation mu-plugin, `CacheService::remember()` convenience method, and NavManager slug conflict detection.

**Architecture:** Four independent phases. Phase 1 is the foundation (routing correctness + slug-conflict fix). Phases 2–4 build on top and are each independently deployable. No phase requires the others to be complete first.

**Spec:** `docs/specs/features/21-performance-routing.md`

**Tech Stack:** PHP 8.1 strict_types · WordPress hooks (`request` filter, `template_redirect`) · WP_UnitTestCase · WPCS + PHPStan L5

**Plugin path:** `/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext/`

---

## File Map

### Phase 1 — PageRouter refactor

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/Core/PageRouter.php` | Modify | Replace all `pagename=` rules with `bn_hub=`; add `request` filter; add `template_redirect`; update `hub_url()` |
| `includes/Core/PageSetup.php` | Modify | Change page slugs from `spaces/members/...` to `bn-spaces/bn-members/...` (internal slugs, never public) |
| `tests/Core/PageRouterTest.php` | Create | Unit tests for rewrite rule existence, `is_bn_route()` detection, `hub_url()` builders |

### Phase 2 — Plugin isolation mu-plugin

| File | Action | Responsibility |
|------|--------|----------------|
| `wp-content/mu-plugins/buddynext-early-router.php` | Create | `option_active_plugins` filter — load only BuddyNext + whitelist on BN routes |

### Phase 3 — CacheService additions

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/Core/CacheService.php` | Modify | Add `remember()` + `forget_group()` methods per spec |
| `tests/Core/CacheServiceTest.php` | Modify | Add tests for the two new methods |

### Phase 4 — NavManager slug conflict detection

| File | Action | Responsibility |
|------|--------|----------------|
| `includes/Admin/NavManager.php` | Modify | AJAX endpoint `wp_ajax_bn_check_slug` + inline JS conflict UI |
| `tests/Admin/NavManagerTest.php` | Modify | Add test for slug conflict detection logic |

---

## Task 1: PageRouter — rewrite rules

**Spec section:** Core Design Decisions §1 + §2

**Files:**
- Modify: `includes/Core/PageRouter.php`
- Create: `tests/Core/PageRouterTest.php`

### What changes

**Before** (current problem):
```
^activity/?$  →  index.php?pagename=activity
```
WordPress resolves `pagename=activity` → runs `SELECT ID FROM wp_posts WHERE post_name='activity'` → hijacks any client page named "activity".

**After** (spec solution):
```
^{public-slug}/?$  →  index.php?bn_hub=feed
```
No `pagename=` anywhere. WordPress never looks for a page by slug.

- [ ] **Step 1: Write failing test for `bn_hub` rewrite tag**

Create `tests/Core/PageRouterTest.php`:

```php
<?php
/**
 * Tests for the PageRouter rewrite-rule and hook model.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;

/**
 * @covers \BuddyNext\Core\PageRouter
 */
class PageRouterTest extends \WP_UnitTestCase {

	private PageRouter $router;

	public function set_up(): void {
		parent::set_up();
		$this->router = new PageRouter();
		$this->router->init();
		flush_rewrite_rules();
	}

	// ── Rewrite rules contain bn_hub, never pagename ──────────────────────────

	public function test_activity_rule_uses_bn_hub_not_pagename(): void {
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		$found = false;
		foreach ( $rules as $pattern => $target ) {
			if ( str_contains( $target, 'bn_hub=feed' ) ) {
				$found = true;
				$this->assertStringNotContainsString( 'pagename', $target );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected a rewrite rule with bn_hub=feed' );
	}

	public function test_people_rule_uses_bn_hub_not_pagename(): void {
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		$found = false;
		foreach ( $rules as $pattern => $target ) {
			if ( str_contains( $target, 'bn_hub=people' ) ) {
				$found = true;
				$this->assertStringNotContainsString( 'pagename', $target );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected a rewrite rule with bn_hub=people' );
	}

	// ── is_bn_route() reflects bn_hub query var ───────────────────────────────

	public function test_is_bn_route_returns_false_without_bn_hub(): void {
		unset( $_GET['bn_hub'] );
		$GLOBALS['wp_query'] = new \WP_Query( array( 'p' => -1 ) );
		$this->assertFalse( PageRouter::is_bn_route() );
	}

	public function test_is_bn_route_returns_true_when_bn_hub_set(): void {
		set_query_var( 'bn_hub', 'feed' );
		$this->assertTrue( PageRouter::is_bn_route() );
		// Clean up.
		set_query_var( 'bn_hub', '' );
	}

	// ── hub_url() builds from slug option, not get_permalink ─────────────────

	public function test_activity_url_uses_slug_option(): void {
		update_option( 'buddynext_slug_activity', 'activity' );
		$url = PageRouter::activity_url();
		$this->assertStringContainsString( '/activity/', $url );
	}

	public function test_activity_url_uses_custom_slug_when_changed(): void {
		update_option( 'buddynext_slug_activity', 'feed' );
		$url = PageRouter::activity_url();
		$this->assertStringContainsString( '/feed/', $url );
		// Reset.
		update_option( 'buddynext_slug_activity', 'activity' );
	}

	// ── Profile URL builders still work ──────────────────────────────────────

	public function test_profile_url_uses_user_nicename(): void {
		$user_id = self::factory()->user->create( array( 'user_nicename' => 'testuser' ) );
		$url     = PageRouter::profile_url( $user_id );
		$this->assertStringContainsString( 'testuser', $url );
	}
}
```

- [ ] **Step 2: Run test — expect failure** (no `is_bn_route()` method yet)

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Core/PageRouterTest.php --testdox 2>&1 | tail -30
```

Expected: multiple FAILURES about missing `is_bn_route()` and tests for `bn_hub` in rewrite rules that find `pagename=` instead.

- [ ] **Step 3: Refactor `register_activity_rules()` to use `bn_hub=feed`**

In `includes/Core/PageRouter.php`, change `register_activity_rules()`:

```php
private function register_activity_rules(): void {
    $a = self::hub_slug( 'buddynext_slug_activity', 'activity' );

    add_rewrite_rule(
        '^' . preg_quote( $a, '/' ) . '/explore/?$',
        'index.php?bn_hub=feed&bn_activity_action=explore',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $a, '/' ) . '/hashtag/([^/]+)/?$',
        'index.php?bn_hub=feed&bn_activity_action=hashtag&bn_hashtag=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $a, '/' ) . '/search/?$',
        'index.php?bn_hub=feed&bn_activity_action=search',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $a, '/' ) . '/leaderboard/?$',
        'index.php?bn_hub=feed&bn_activity_action=leaderboard',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $a, '/' ) . '/?$',
        'index.php?bn_hub=feed',
        'top'
    );
}
```

Note the root rule moved to last so specifics match first.

- [ ] **Step 4: Refactor all remaining hub rules to use `bn_hub`**

Change `register_people_rules()`:

```php
private function register_people_rules(): void {
    $p = self::hub_slug( 'buddynext_slug_people', 'members' );

    add_rewrite_rule(
        '^' . preg_quote( $p, '/' ) . '/([^/]+)/edit/?$',
        'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=edit',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $p, '/' ) . '/([^/]+)/connections/?$',
        'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=connections',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $p, '/' ) . '/([^/]+)/media/?$',
        'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=media',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $p, '/' ) . '/([^/]+)/badges/?$',
        'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=badges',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $p, '/' ) . '/([^/]+)/?$',
        'index.php?bn_hub=people&bn_user_slug=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $p, '/' ) . '/([a-z0-9-]+)/?$',
        'index.php?bn_hub=people&bn_member_type=$matches[1]',
        'bottom'
    );
    add_rewrite_rule(
        '^' . preg_quote( $p, '/' ) . '/?$',
        'index.php?bn_hub=people',
        'top'
    );
}
```

Change `register_spaces_rules()`:

```php
private function register_spaces_rules(): void {
    $s = self::hub_slug( 'buddynext_slug_spaces', 'spaces' );

    add_rewrite_rule(
        '^' . preg_quote( $s, '/' ) . '/([^/]+)/members/?$',
        'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=members',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $s, '/' ) . '/([^/]+)/settings/?$',
        'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=settings',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $s, '/' ) . '/([^/]+)/moderation/?$',
        'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=moderation',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $s, '/' ) . '/([^/]+)/admin/?$',
        'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=admin',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $s, '/' ) . '/([^/]+)/?$',
        'index.php?bn_hub=spaces&bn_space_slug=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $s, '/' ) . '/?$',
        'index.php?bn_hub=spaces',
        'top'
    );
}
```

Change `register_messages_rules()`:

```php
private function register_messages_rules(): void {
    $m = self::hub_slug( 'buddynext_slug_messages', 'messages' );

    add_rewrite_rule(
        '^' . preg_quote( $m, '/' ) . '/requests/?$',
        'index.php?bn_hub=messages&bn_msg_action=requests',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $m, '/' ) . '/([0-9]+)/?$',
        'index.php?bn_hub=messages&bn_conv_id=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^' . preg_quote( $m, '/' ) . '/?$',
        'index.php?bn_hub=messages',
        'top'
    );
}
```

Change `register_notifications_rules()`:

```php
private function register_notifications_rules(): void {
    $n = self::hub_slug( 'buddynext_slug_notifications', 'notifications' );

    add_rewrite_rule(
        '^' . preg_quote( $n, '/' ) . '/?$',
        'index.php?bn_hub=notifications',
        'top'
    );
}
```

Change `register_auth_rules()`:

```php
private function register_auth_rules(): void {
    $a = self::hub_slug( 'buddynext_slug_auth', 'login' );

    add_rewrite_rule(
        '^' . preg_quote( $a, '/' ) . '/?$',
        'index.php?bn_hub=auth',
        'top'
    );
}
```

- [ ] **Step 5: Add `bn_hub` rewrite tag in `register_rewrites()`**

Add at the top of the rewrite tags block (after line `add_rewrite_tag( '%bn_activity_action%', '([^/]*)' );`):

```php
add_rewrite_tag( '%bn_hub%', '([a-z]+)' );
```

- [ ] **Step 6: Add `is_bn_route()` static method**

Add after the `flush_on_slug_change()` method:

```php
/**
 * Return true when the current request is a BuddyNext hub route.
 *
 * Safe to call from any hook after parse_request.
 *
 * @return bool
 */
public static function is_bn_route(): bool {
    return '' !== (string) get_query_var( 'bn_hub', '' );
}
```

- [ ] **Step 7: Run tests — expect activity + people rules pass, `is_bn_route` pass**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Core/PageRouterTest.php --testdox 2>&1 | tail -30
```

Expected: all tests PASS

- [ ] **Step 8: WPCS check**

```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/PageRouter.php", standard: "WordPress" })
```

Fix all violations before continuing.

- [ ] **Step 9: Commit Phase 1a**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && git add includes/Core/PageRouter.php tests/Core/PageRouterTest.php && git commit -m "refactor(routing): replace pagename= rules with bn_hub query-var routing

All five hub rewrite rules now target index.php?bn_hub={hub} instead of
index.php?pagename={slug}. This eliminates the silent page-hijack on
existing sites that have pages with the same slugs as BuddyNext hubs.

Adds is_bn_route() static helper for request filter + template_redirect."
```

---

## Task 2: PageRouter — `request` filter + `template_redirect`

**Spec section:** Core Design Decisions §2 + §7

**Files:**
- Modify: `includes/Core/PageRouter.php`
- Modify: `tests/Core/PageRouterTest.php`

The `request` filter fires inside `WP::parse_request()` — before `WP_Query` instantiates. Setting `p={page_id}` here loads only the internal `bn-` page by primary key (one fast PK query) instead of the full post lookup by slug. Template_redirect fires after `wp`, intercepts when `bn_hub` is set, and loads the BuddyNext template directly.

Hub-to-template map:
```
feed          → feed/home  (or sub-endpoint template)
people        → directory/members  (or profile/view, profile/edit, etc.)
spaces        → spaces/directory  (or spaces/home, spaces/members, etc.)
messages      → messages/list  (or messages/thread, messages/requests)
notifications → notifications/index
auth          → auth/login
```

- [ ] **Step 1: Write failing test for `request` filter**

Add to `tests/Core/PageRouterTest.php`:

```php
// ── request filter suppresses default page lookup ────────────────────────

public function test_suppress_query_sets_p_to_internal_page_when_bn_hub_set(): void {
    // Simulate a request with bn_hub set via a minimal query vars array.
    $query_vars = array( 'bn_hub' => 'feed' );
    $result     = $this->router->suppress_default_query( $query_vars );
    // The filter must set p to something greater than 0 (the internal page ID)
    // OR set p=-1 (when no internal page is configured yet in tests).
    $this->assertArrayHasKey( 'p', $result );
    $this->assertArrayHasKey( 'post_type', $result );
    $this->assertSame( 'page', $result['post_type'] );
}

public function test_suppress_query_does_not_modify_non_bn_requests(): void {
    $query_vars = array( 'pagename' => 'about' );
    $result     = $this->router->suppress_default_query( $query_vars );
    $this->assertArrayNotHasKey( 'bn_hub', $result );
    $this->assertSame( array( 'pagename' => 'about' ), $result );
}
```

Run to confirm failure:
```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Core/PageRouterTest.php::test_suppress_query_sets_p_to_internal_page_when_bn_hub_set --testdox 2>&1 | tail -15
```

Expected: FAIL — method does not exist.

- [ ] **Step 2: Add `request` filter registration in `init()`**

In `PageRouter::init()`, add after the slug-flush loop:

```php
add_filter( 'request', array( $this, 'suppress_default_query' ) );
add_action( 'template_redirect', array( $this, 'dispatch_hub_template' ) );
```

- [ ] **Step 3: Implement `suppress_default_query()`**

Add after `set_hub_vars()`:

```php
/**
 * Intercept the request filter to suppress WP_Query's post lookup.
 *
 * When bn_hub is present, WordPress must not run a post-by-slug DB query.
 * We set p={page_id} so WP_Query fetches just the internal bn-* page by
 * primary key — one fast PK lookup instead of a slug search + all meta.
 *
 * @param array<string, mixed> $query_vars Parsed query vars.
 * @return array<string, mixed>
 */
public function suppress_default_query( array $query_vars ): array {
    if ( empty( $query_vars['bn_hub'] ) ) {
        return $query_vars;
    }

    $hub = (string) $query_vars['bn_hub'];

    // Map hub name → page option.
    $page_options = array(
        'feed'          => 'buddynext_page_activity',
        'people'        => 'buddynext_page_people',
        'spaces'        => 'buddynext_page_spaces',
        'messages'      => 'buddynext_page_messages',
        'notifications' => 'buddynext_page_notifications',
        'auth'          => 'buddynext_page_auth',
    );

    $page_id = 0;
    if ( isset( $page_options[ $hub ] ) ) {
        $page_id = (int) get_option( $page_options[ $hub ], 0 );
    }

    // Remove slug-based lookup keys.
    unset( $query_vars['pagename'], $query_vars['name'], $query_vars['page'] );

    // Set to internal page (PK lookup) or -1 when page not configured.
    $query_vars['post_type'] = 'page';
    $query_vars['p']         = $page_id > 0 ? $page_id : -1;

    return $query_vars;
}
```

- [ ] **Step 4: Implement `dispatch_hub_template()`**

Hub-to-template resolution table lives here:

```php
/**
 * Intercept template_redirect to load the BuddyNext hub template.
 *
 * Fires after wp() completes. When bn_hub is set, BuddyNext loads its own
 * template and calls exit — WordPress never renders the WP page content.
 *
 * @return void
 */
public function dispatch_hub_template(): void {
    $hub = (string) get_query_var( 'bn_hub', '' );
    if ( '' === $hub ) {
        return;
    }

    $template = $this->resolve_hub_template( $hub );

    if ( null === $template ) {
        return;
    }

    /**
     * Fires before the BuddyNext hub template is loaded.
     *
     * @param string $hub      Hub slug (feed, people, spaces, etc.).
     * @param string $template Relative template path.
     */
    do_action( 'buddynext_before_hub', $hub, $template );

    buddynext_get_template( $template );

    exit;
}

/**
 * Resolve a relative template path from the active hub + sub-action vars.
 *
 * @param string $hub Hub slug.
 * @return string|null Relative template path, or null when hub unknown.
 */
private function resolve_hub_template( string $hub ): ?string {
    switch ( $hub ) {
        case 'feed':
            $action = (string) get_query_var( 'bn_activity_action', '' );
            $map    = array(
                'explore'     => 'feed/explore',
                'hashtag'     => 'hashtags/feed',
                'search'      => 'search/results',
                'leaderboard' => 'gamification/leaderboard',
            );
            return $map[ $action ] ?? 'feed/home';

        case 'people':
            $user_slug = (string) get_query_var( 'bn_user_slug', '' );
            if ( '' !== $user_slug ) {
                $action = (string) get_query_var( 'bn_profile_action', '' );
                $map    = array(
                    'edit'        => 'profile/edit',
                    'connections' => 'profile/connections',
                    'media'       => 'profile/media',
                    'badges'      => 'profile/badges',
                );
                return $map[ $action ] ?? 'profile/view';
            }
            return 'directory/members';

        case 'spaces':
            $space_slug = (string) get_query_var( 'bn_space_slug', '' );
            if ( '' !== $space_slug ) {
                $action = (string) get_query_var( 'bn_space_action', '' );
                $map    = array(
                    'members'    => 'spaces/members',
                    'settings'   => 'spaces/settings',
                    'moderation' => 'spaces/moderation',
                    'admin'      => 'spaces/admin',
                );
                return $map[ $action ] ?? 'spaces/home';
            }
            return 'spaces/directory';

        case 'messages':
            $conv_id = (int) get_query_var( 'bn_conv_id', 0 );
            if ( $conv_id > 0 ) {
                return 'messages/thread';
            }
            $msg_action = (string) get_query_var( 'bn_msg_action', '' );
            if ( 'requests' === $msg_action ) {
                return 'messages/requests';
            }
            return 'messages/list';

        case 'notifications':
            return 'notifications/index';

        case 'auth':
            return 'auth/login';

        default:
            return null;
    }
}
```

- [ ] **Step 5: Run all PageRouter tests**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Core/PageRouterTest.php --testdox 2>&1 | tail -30
```

Expected: all PASS.

- [ ] **Step 6: WPCS check**

```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/PageRouter.php", standard: "WordPress" })
```

- [ ] **Step 7: Flush rewrites and browser-test**

```bash
wp --path="/Users/varundubey/Local Sites/forums/app/public" rewrite flush
```

Then navigate to `http://forums.local/activity?autologin=1` — should render the feed template (not a 404 or WP page content).

- [ ] **Step 8: Commit Phase 1b**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && git add includes/Core/PageRouter.php tests/Core/PageRouterTest.php && git commit -m "feat(routing): add request filter + template_redirect hub dispatcher

suppress_default_query() intercepts the request filter before WP_Query
instantiates. Sets p={page_id} for PK-only lookup instead of slug search.

dispatch_hub_template() intercepts template_redirect, resolves the hub+
sub-action to a template path, loads it, and exits — WP never renders
the page content."
```

---

## Task 3: PageSetup — internal `bn-` page slugs

**Spec section:** Core Design Decisions §4

**Files:**
- Modify: `includes/Core/PageSetup.php`

Current problem: PageSetup creates pages with clean public slugs (`spaces`, `members`). On an existing site that already has `/members/` or `/spaces/` pages, the `ensure_pages()` call will adopt those pages instead of creating new ones — silently redirecting the site's nav menu links through BuddyNext.

Fix: internal pages get `bn-` prefixed slugs (`bn-feed`, `bn-members`, etc.). The public URL remains whatever the `buddynext_slug_*` option says. The two are now completely decoupled.

- [ ] **Step 1: Update `HUBS` constant in `PageSetup.php`**

Change slugs for all 6 hubs:

```php
private const HUBS = array(
    'buddynext_page_activity'      => array(
        'title'     => 'BuddyNext Feed',
        'slug'      => 'bn-feed',
        'shortcode' => '[buddynext_activity]',
    ),
    'buddynext_page_spaces'        => array(
        'title'     => 'BuddyNext Spaces',
        'slug'      => 'bn-spaces',
        'shortcode' => '[buddynext_spaces]',
    ),
    'buddynext_page_people'        => array(
        'title'     => 'BuddyNext Members',
        'slug'      => 'bn-members',
        'shortcode' => '[buddynext_people]',
    ),
    'buddynext_page_notifications' => array(
        'title'     => 'BuddyNext Notifications',
        'slug'      => 'bn-notifications',
        'shortcode' => '[buddynext_notifications]',
    ),
    'buddynext_page_messages'      => array(
        'title'     => 'BuddyNext Messages',
        'slug'      => 'bn-messages',
        'shortcode' => '[buddynext_messages]',
    ),
    'buddynext_page_auth'          => array(
        'title'     => 'BuddyNext Login',
        'slug'      => 'bn-login',
        'shortcode' => '[buddynext_auth]',
    ),
);
```

- [ ] **Step 2: Bump `PAGES_VERSION` to force re-run on existing installs**

Change:
```php
public const PAGES_VERSION = 2;
```

- [ ] **Step 3: WPCS check**

```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/PageSetup.php", standard: "WordPress" })
```

- [ ] **Step 4: Test on local site — verify existing slug pages are not adopted**

```bash
# Confirm existing /members/ page slug is NOT adopted as the hub page
wp --path="/Users/varundubey/Local Sites/forums/app/public" post list --post_type=page --fields=ID,post_name,post_title 2>&1 | grep -E "bn-|members|spaces"
```

Expected: see `bn-members`, `bn-spaces`, etc. The pre-existing `members` or `spaces` page (if any) is untouched.

- [ ] **Step 5: Commit Phase 1c**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && git add includes/Core/PageSetup.php && git commit -m "fix(setup): use bn- prefixed slugs for internal hub pages

Internal WP pages now use bn-feed, bn-members, bn-spaces, etc. as their
post_name. Public URLs remain configured via buddynext_slug_* options.
The two are fully decoupled — activating BuddyNext on an existing site
with /members/ or /spaces/ pages no longer hijacks those pages.

Bumped PAGES_VERSION to 2 to force re-creation on upgrade."
```

---

## Task 4: Plugin isolation mu-plugin (Phase 2)

**Spec section:** Core Design Decisions §3

**Files:**
- Create: `wp-content/mu-plugins/buddynext-early-router.php`

**What it does:** Before WordPress runs `plugins_loaded`, this mu-plugin checks the request URI against the BuddyNext slug patterns. If it's a BN route, it filters `option_active_plugins` to strip all non-essential plugins. This saves 20–40MB per request (WooCommerce alone is ~30MB).

The check must happen **before** `plugins_loaded` so plugins never load their code. The `option_active_plugins` filter fires during `WP::init()` which is before `plugins_loaded`.

- [ ] **Step 1: Create the mu-plugin**

```php
<?php
/**
 * BuddyNext early router — plugin isolation on BuddyNext routes.
 *
 * Must-use plugin. Runs before normal plugins load. Checks the request URI
 * against configured BuddyNext hub slugs and strips non-essential plugins
 * from the active-plugins list so they never consume memory on BN routes.
 *
 * @package BuddyNext
 */

// Guard: only run on front-end HTTP requests. Admin, WP-CLI, and REST
// requests all need the full plugin set.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

/**
 * Determine whether the current request is a BuddyNext hub route.
 *
 * Reads buddynext_slug_* options from the database and compares against
 * REQUEST_URI before WordPress finishes loading.
 *
 * @return bool
 */
function buddynext_mu_is_bn_request(): bool {
    static $result = null;

    if ( null !== $result ) {
        return $result;
    }

    // REQUEST_URI is always set on real HTTP requests.
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    // Strip query string and leading slash.
    $path = ltrim( (string) strtok( $uri, '?' ), '/' );

    if ( '' === $path ) {
        $result = false;
        return false;
    }

    $slug_defaults = array(
        'buddynext_slug_activity'      => 'activity',
        'buddynext_slug_people'        => 'members',
        'buddynext_slug_spaces'        => 'spaces',
        'buddynext_slug_messages'      => 'messages',
        'buddynext_slug_notifications' => 'notifications',
        'buddynext_slug_auth'          => 'login',
    );

    global $wpdb;

    foreach ( $slug_defaults as $option_name => $fallback ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $slug = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $option_name
            )
        );

        $slug = ( null !== $slug && '' !== trim( (string) $slug ) ) ? trim( (string) $slug ) : $fallback;

        // Match path starts with this slug.
        if ( 0 === strpos( $path, $slug ) ) {
            $result = true;
            return true;
        }
    }

    $result = false;
    return false;
}

if ( buddynext_mu_is_bn_request() ) {
    /**
     * Essential plugins that must remain active on BuddyNext routes.
     * Add third-party plugin slugs here if BuddyNext integrates with them.
     */
    $bn_whitelist = apply_filters(
        'buddynext_isolation_whitelist',
        array(
            'buddynext/buddynext.php',
            'redis-cache/redis-cache.php',
            'query-monitor/query-monitor.php', // dev only — harmless in prod
        )
    );

    // Strip active plugins that are not in the whitelist.
    add_filter(
        'option_active_plugins',
        static function ( array $plugins ) use ( $bn_whitelist ): array {
            return array_values(
                array_filter(
                    $plugins,
                    static function ( string $plugin ) use ( $bn_whitelist ): bool {
                        return in_array( $plugin, $bn_whitelist, true );
                    }
                )
            );
        }
    );
}
```

- [ ] **Step 2: Test on local site**

Navigate to `http://forums.local/activity?autologin=1` and verify the feed still renders. Check that WooCommerce (if active) is not loaded.

Check memory usage via Query Monitor or `memory_get_peak_usage()` added temporarily to the template.

- [ ] **Step 3: Verify admin panel unaffected**

Navigate to `http://forums.local/wp-admin?autologin=1` — all plugins must still load normally (the `is_admin()` guard at the top covers this).

- [ ] **Step 4: Commit Phase 2**

```bash
git add wp-content/mu-plugins/buddynext-early-router.php && git commit -m "feat(perf): add plugin isolation mu-plugin for BuddyNext routes

Strips non-essential plugins before plugins_loaded on BuddyNext front-end
routes. Saves 20-40MB per request (WooCommerce alone is ~30MB).

Whitelist is filterable via buddynext_isolation_whitelist so developers
can declare their own dependencies."
```

---

## Task 5: CacheService — `remember()` + `forget_group()` (Phase 3)

**Spec section:** Core Design Decisions §5

**Files:**
- Modify: `includes/Core/CacheService.php`
- Modify: `tests/Core/CacheServiceTest.php`

Current `CacheService` already wraps `wp_cache_*` with domain-specific methods. The spec also requires:
- `remember( string $key, int $ttl, callable $callback ): mixed` — returns cached value or computes and caches it
- `forget_group( string $group ): void` — bulk-evict by group (delegate to `wp_cache_flush_group()` when available)

- [ ] **Step 1: Write failing tests**

Add to `tests/Core/CacheServiceTest.php`:

```php
// ── remember() ────────────────────────────────────────────────────────────

public function test_remember_computes_and_stores_on_miss(): void {
    $called = 0;
    $result = $this->cache->remember( 'test_key', 60, static function () use ( &$called ): string {
        ++$called;
        return 'computed';
    } );

    $this->assertSame( 'computed', $result );
    $this->assertSame( 1, $called );
}

public function test_remember_returns_cached_value_without_calling_callback(): void {
    $this->cache->set( 'test_key2', 'cached', 60 );
    $called = 0;
    $result = $this->cache->remember( 'test_key2', 60, static function () use ( &$called ): string {
        ++$called;
        return 'should-not-be-called';
    } );

    $this->assertSame( 'cached', $result );
    $this->assertSame( 0, $called );
}

// ── forget_group() ─────────────────────────────────────────────────────────

public function test_forget_group_removes_entries(): void {
    $this->cache->set( 'key_a', 'value_a', 60 );
    $this->cache->set( 'key_b', 'value_b', 60 );

    $this->cache->forget_group( 'buddynext' );

    // After flush, wp_cache_get should miss.
    // Note: WP test suite uses an in-memory cache that supports flush_group.
    $this->assertNull( $this->cache->get( 'key_a' ) );
}
```

Run to confirm failure:
```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Core/CacheServiceTest.php --filter "remember|forget_group" --testdox 2>&1 | tail -20
```

Expected: FAIL — methods do not exist.

- [ ] **Step 2: Add `remember()` method to `CacheService`**

Add after the `delete()` method in the Generic helpers section:

```php
/**
 * Return cached value or compute and cache it.
 *
 * Calls $callback only on a cache miss, stores the result, and returns it.
 *
 * @param string   $key      Cache key.
 * @param int      $ttl      TTL in seconds.
 * @param callable $callback Callable that produces the value.
 * @return mixed
 */
public function remember( string $key, int $ttl, callable $callback ): mixed {
    $cached = $this->get( $key );

    if ( null !== $cached ) {
        return $cached;
    }

    $value = $callback();
    $this->set( $key, $value, $ttl );

    return $value;
}
```

- [ ] **Step 3: Add `forget_group()` method to `CacheService`**

Add after `remember()`:

```php
/**
 * Flush all cache entries in the BuddyNext cache group.
 *
 * Delegates to wp_cache_flush_group() when the persistent cache backend
 * supports it (Redis Object Cache plugin, Memcached Object Cache plugin).
 * Falls back to a no-op on hosts where the in-process cache auto-expires
 * at the end of the request anyway.
 *
 * @param string $group Cache group name (default: buddynext group).
 * @return void
 */
public function forget_group( string $group = self::GROUP ): void {
    if ( function_exists( 'wp_cache_flush_group' ) ) {
        wp_cache_flush_group( $group );
    }
}
```

- [ ] **Step 4: Run tests**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Core/CacheServiceTest.php --testdox 2>&1 | tail -20
```

Expected: all PASS (including new tests).

- [ ] **Step 5: WPCS check**

```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/CacheService.php", standard: "WordPress" })
```

- [ ] **Step 6: Commit Phase 3**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && git add includes/Core/CacheService.php tests/Core/CacheServiceTest.php && git commit -m "feat(cache): add remember() and forget_group() to CacheService

remember() implements the read-through pattern: return cached value or
compute-and-cache. Callers pass a callable; no value is computed if the
cache is warm.

forget_group() delegates to wp_cache_flush_group() on backends that
support it (Redis, Memcached). Silent no-op on in-process object cache."
```

---

## Task 6: NavManager — slug conflict detection (Phase 4)

**Spec section:** NavManager — Slug Conflict Detection

**Files:**
- Modify: `includes/Admin/NavManager.php`
- Modify: `tests/Admin/NavManagerTest.php`

When admin types a URL slug in the NavManager config panel, JavaScript calls `wp_ajax_bn_check_slug`. PHP checks for conflicts and returns a status: `free`, `warn` (existing WP page), or `block` (another BN hub or reserved word). The config panel shows ✅ / ⚠️ / ❌ feedback inline.

- [ ] **Step 1: Write failing test for `check_slug()` method**

Add to `tests/Admin/NavManagerTest.php`:

```php
// ── Slug conflict detection ───────────────────────────────────────────────

public function test_check_slug_returns_free_for_unused_slug(): void {
    $nav    = new \BuddyNext\Admin\NavManager();
    $result = $nav->check_slug_status( 'my-community', 'feed' );
    $this->assertSame( 'free', $result );
}

public function test_check_slug_returns_block_for_reserved_word(): void {
    $nav    = new \BuddyNext\Admin\NavManager();
    $result = $nav->check_slug_status( 'wp-admin', 'feed' );
    $this->assertSame( 'block', $result );
}

public function test_check_slug_returns_block_for_another_bn_hub_slug(): void {
    update_option( 'buddynext_slug_spaces', 'spaces' );
    $nav    = new \BuddyNext\Admin\NavManager();
    $result = $nav->check_slug_status( 'spaces', 'feed' ); // feed hub trying to use 'spaces'
    $this->assertSame( 'block', $result );
}

public function test_check_slug_returns_warn_for_existing_wp_page(): void {
    // Create a WP page with slug 'about-us'.
    $page_id = self::factory()->post->create( array(
        'post_type'   => 'page',
        'post_name'   => 'about-us',
        'post_status' => 'publish',
    ) );
    $nav    = new \BuddyNext\Admin\NavManager();
    $result = $nav->check_slug_status( 'about-us', 'feed' );
    $this->assertSame( 'warn', $result );
    wp_delete_post( $page_id, true );
}
```

Run to confirm failure:
```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Admin/NavManagerTest.php --filter "check_slug" --testdox 2>&1 | tail -20
```

Expected: FAIL — method does not exist.

- [ ] **Step 2: Add `check_slug_status()` to `NavManager`**

Add as a public method (after the existing class constants, or in a new `// ── Slug conflict ──` section):

```php
// ── Slug conflict detection ───────────────────────────────────────────────

/**
 * WordPress reserved slugs that BuddyNext must never use as hub slugs.
 */
private const RESERVED_SLUGS = array(
    'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json',
    'feed', 'rss', 'rss2', 'atom', 'rdf', 'comments', 'trackback',
    'embed', 'wp-cron',
);

/**
 * Check whether a proposed hub URL slug is available.
 *
 * Returns:
 *   'free'  — slug is unclaimed, safe to use
 *   'warn'  — slug matches an existing WP post/page (BN rewrite has
 *             priority but the existing page becomes unreachable)
 *   'block' — slug matches another BN hub or is a WP reserved keyword
 *
 * @param string $slug         Proposed slug (pre-sanitized).
 * @param string $current_hub  Hub slug that owns this request (excluded
 *                             from the other-hub conflict check).
 * @return string 'free' | 'warn' | 'block'
 */
public function check_slug_status( string $slug, string $current_hub ): string {
    $slug = sanitize_title( $slug );

    if ( '' === $slug ) {
        return 'block';
    }

    // 1. Reserved WordPress keywords.
    if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
        return 'block';
    }

    // 2. Another BN hub is already using this slug.
    $hub_options = array(
        'feed'          => 'buddynext_slug_activity',
        'people'        => 'buddynext_slug_people',
        'spaces'        => 'buddynext_slug_spaces',
        'messages'      => 'buddynext_slug_messages',
        'notifications' => 'buddynext_slug_notifications',
        'auth'          => 'buddynext_slug_auth',
    );

    foreach ( $hub_options as $hub => $option ) {
        if ( $hub === $current_hub ) {
            continue;
        }
        $existing = (string) get_option( $option, '' );
        if ( '' !== $existing && $existing === $slug ) {
            return 'block';
        }
    }

    // 3. Existing WP post or page has this slug.
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_name   = %s
                AND post_status = 'publish'
                AND post_type   IN ('post', 'page')",
            $slug
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    if ( $count > 0 ) {
        return 'warn';
    }

    return 'free';
}
```

- [ ] **Step 3: Add AJAX handler in `register()` and `render_page()`**

In `NavManager::register()`, add:

```php
add_action( 'wp_ajax_bn_check_slug', array( $this, 'handle_check_slug_ajax' ) );
```

Add `handle_check_slug_ajax()` method:

```php
/**
 * Handle wp_ajax_bn_check_slug — return slug conflict status as JSON.
 *
 * @return void
 */
public function handle_check_slug_ajax(): void {
    check_ajax_referer( 'bn_nav_manager', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        return;
    }

    $slug = sanitize_title( (string) ( $_POST['slug'] ?? '' ) );
    $hub  = sanitize_key( (string) ( $_POST['hub'] ?? '' ) );

    $status = $this->check_slug_status( $slug, $hub );

    wp_send_json_success( array( 'status' => $status ) );
}
```

- [ ] **Step 4: Add inline JavaScript for conflict feedback**

In `NavManager::render_page()`, before the closing `</div>`, add a `<script>` block with the conflict-check UI. The JS debounces the input (300ms), calls the AJAX endpoint, and toggles CSS classes/icons on the hint span:

```php
// Output after the form HTML:
?>
<script>
(function () {
    'use strict';
    var debounceTimer;
    var nonce = <?php echo wp_json_encode( wp_create_nonce( 'bn_nav_manager' ) ); ?>;

    document.querySelectorAll( 'input[name$="[url_slug]"]' ).forEach( function ( input ) {
        var hint = input.nextElementSibling;
        input.addEventListener( 'input', function () {
            clearTimeout( debounceTimer );
            var slug = input.value.trim();
            var hub  = input.closest( 'form' ).querySelector( '[name$="[hub]"]' );
            hub = hub ? hub.value : '';

            debounceTimer = setTimeout( function () {
                if ( '' === slug ) {
                    hint.textContent = '';
                    hint.className   = 'bn-cf-hint';
                    return;
                }
                var data = new FormData();
                data.append( 'action', 'bn_check_slug' );
                data.append( 'nonce', nonce );
                data.append( 'slug', slug );
                data.append( 'hub', hub );

                fetch( ajaxurl, { method: 'POST', body: data } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( json ) {
                        var st = json.data && json.data.status ? json.data.status : 'free';
                        var labels = {
                            free:  '✅ Slug is available',
                            warn:  '⚠️ An existing page uses this slug — it will become unreachable',
                            block: '❌ This slug is reserved or used by another hub'
                        };
                        hint.textContent = labels[ st ] || '';
                        hint.className   = 'bn-cf-hint bn-cf-hint--' + st;
                    } )
                    .catch( function () {
                        hint.textContent = '';
                    } );
            }, 300 );
        } );
    } );
}());
</script>
<?php
```

- [ ] **Step 5: Run all NavManager tests**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Admin/NavManagerTest.php --testdox 2>&1 | tail -30
```

Expected: all PASS.

- [ ] **Step 6: WPCS check**

```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Admin/NavManager.php", standard: "WordPress" })
```

- [ ] **Step 7: Browser test**

Navigate to `http://forums.local/wp-admin/admin.php?page=buddynext-nav-manager&autologin=1`.
Type `activity` in the URL Slug field of the Feed hub — should show ❌ (used by Feed hub itself or another hub).
Type `members` in the URL Slug field of the Feed hub — should show ⚠️ if `/members/` page exists, else ✅.
Type `wp-admin` — should show ❌ immediately.

- [ ] **Step 8: Commit Phase 4**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && git add includes/Admin/NavManager.php tests/Admin/NavManagerTest.php && git commit -m "feat(nav): add slug conflict detection to NavManager

check_slug_status() categorizes a proposed hub slug as:
  free  — unclaimed, safe to save
  warn  — matches existing WP page (BN wins but page becomes unreachable)
  block — reserved WP keyword or already claimed by another hub

AJAX handler + debounced JS input listener shows ✅/⚠️/❌ inline."
```

---

## Full Test Run

After all four phases:

- [ ] **Run full test suite**

```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit --testdox 2>&1 | tail -50
```

Expected: all PASS.

- [ ] **WPCS full check**

```
mcp__wpcs__wpcs_check_directory({ path: "includes/", standard: "WordPress" })
```

Expected: zero violations.

- [ ] **Flush rewrites and end-to-end browser test**

```bash
wp --path="/Users/varundubey/Local Sites/forums/app/public" rewrite flush
```

Navigate each hub URL and confirm correct template renders:
- `http://forums.local/activity?autologin=1` → feed home template
- `http://forums.local/activity/explore` → explore template
- `http://forums.local/members?autologin=1` → member directory
- `http://forums.local/spaces?autologin=1` → spaces directory
- `http://forums.local/notifications?autologin=1` → notifications
- `http://forums.local/messages?autologin=1` → messages list
- `http://forums.local/wp-admin?autologin=1` → admin panel unaffected (all plugins still load)

---

## Reference Commands

```bash
# Flush rewrites
wp --path="/Users/varundubey/Local Sites/forums/app/public" rewrite flush

# Check rewrite rules
wp --path="/Users/varundubey/Local Sites/forums/app/public" rewrite list | grep -i "bn_hub\|activity\|members\|spaces"

# Check active pages
wp --path="/Users/varundubey/Local Sites/forums/app/public" post list --post_type=page --fields=ID,post_name,post_title

# Check buddynext_page_* options
wp --path="/Users/varundubey/Local Sites/forums/app/public" option list --search="buddynext_page_*"

# Check buddynext_slug_* options
wp --path="/Users/varundubey/Local Sites/forums/app/public" option list --search="buddynext_slug_*"

# PHPUnit full suite
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit --testdox

# PHPUnit single test class
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext" && vendor/bin/phpunit tests/Core/PageRouterTest.php --testdox

# Debug log
tail -50 "/Users/varundubey/Local Sites/forums/app/public/wp-content/debug.log"
```
