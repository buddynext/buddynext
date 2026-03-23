# BuddyNext — Performance Foundation & Routing Architecture

**Status:** Active
**Last updated:** 2026-03-23

---

## Why This Spec Exists

BuddyNext must perform like a Laravel application on WordPress infrastructure. The routing mechanism, memory footprint, and data layer must be built once, correctly, so they can carry the plugin for the next 5 years and 100K+ member communities without requiring a rewrite.

Two audiences must be served equally:

**Site owners** — Both new sites (fresh WordPress install, zero existing content) and existing sites (client pages at `/members/`, `/activity/`, e-commerce running alongside community). Activating BuddyNext must never silently break existing pages. URL slugs must be fully configurable with clear conflict warnings.

**Developers** — Clean, predictable extension points. Adding a custom hub, a sub-endpoint, or a cache override must follow a documented pattern with no surprises.

---

## Core Design Decisions

### 1. Query-var routing — never `pagename=`

**Problem with `pagename=activity`:**
WordPress matches the rewrite rule → runs `SELECT ID FROM wp_posts WHERE post_name='activity'` → if the site already has a page with that slug, BuddyNext hijacks it silently. Changing the slug renames both the public URL AND the WP page slug, which breaks nav menus, breadcrumbs, and SEO plugins.

**Solution: query-var routing**
```
^{public-slug}/?$  →  index.php?bn_hub=feed
^{public-slug}/explore/?$  →  index.php?bn_hub=feed&bn_activity_action=explore
^{public-slug}/{user-slug}/?$  →  index.php?bn_hub=people&bn_user_slug=$matches[1]
```

Rewrite rules map public URL paths to `bn_hub` + sub-vars. No `pagename=` anywhere. WordPress pages are never involved in routing. A `template_redirect` hook intercepts when `bn_hub` is set and loads the BuddyNext template.

**Benefits:**
- Existing site pages are completely unaffected
- Public slug and WP page are independent
- Sub-endpoints (`/members/{user}/edit/`) work naturally under the same `bn_hub`
- Developer can add a custom hub without touching WP pages

### 2. `request` filter — earliest safe interception point

```
Request → WP::parse_request() → request filter ← intercept here
       → WP::query_posts() → WP_Query instantiated
       → wp action
       → template_redirect ← too late (WP_Query already ran)
```

The `request` filter fires inside `WP::parse_request()`, before `WP_Query` is instantiated. Setting `post_type=none` and `p=-1` here means WP_Query runs zero DB queries. No WP page object is loaded. No post meta. No thumbnails. Saves 50–200KB and 1–2 DB calls per BuddyNext request.

### 3. Plugin isolation on BuddyNext routes

When a visitor loads the activity feed, every active plugin fires its hooks: WooCommerce, Yoast, contact forms, gallery plugins. None of these contribute anything to the BuddyNext page. All of their `plugins_loaded`, `init`, and `wp_enqueue_scripts` callbacks run and waste memory.

Solution: `option_active_plugins` filter in an mu-plugin, applied only when the request is detected as a BuddyNext route (set via `$_SERVER` var from the `request` filter run, or pre-detected by checking the request URI against known slug patterns before WP loads).

Only BuddyNext itself + a whitelist of explicitly declared dependencies are loaded on BuddyNext routes.

**Memory impact:** Skipping WooCommerce alone saves 20–40MB per request.

### 4. Internal pages with `bn-` prefix — zero conflict risk

Installer creates WP pages with internal slugs: `bn-feed`, `bn-members`, `bn-spaces`, `bn-messages`, `bn-notifications`, `bn-auth`. These pages are never visited directly — they exist only so WP nav menus, breadcrumbs, and SEO plugins have a real page to reference. The `get_the_ID()` and `get_post()` calls from third-party plugins work correctly because there is a real page in context.

The public URL (`/members/`, `/activity/`) is set independently by the `buddynext_slug_*` options. Rewrite rules map public slug → `bn_hub` query var. The WP page (with its `bn-` internal slug) is loaded by setting `p={page_id}` on WP_Query after `template_redirect` intercepts — so the page is in context for third-party plugins without driving the routing.

### 5. Redis as first-class dependency

WP object cache is in-process only — it dies with the PHP request. On the next request the cache is cold. For a community platform with 100K members this is catastrophic: every feed load hits MySQL.

BuddyNext ships a `CacheService` that wraps a Redis connection (via the WordPress Redis Object Cache plugin's `$wp_object_cache` backend, or direct `Predis/PhpRedis` if available) with a graceful fallback to in-process WP object cache for development environments without Redis.

The abstraction is thin and consistent:
```php
$cache->get( string $key ): mixed
$cache->set( string $key, mixed $value, int $ttl = 300 ): void
$cache->delete( string $key ): void
$cache->remember( string $key, int $ttl, callable $callback ): mixed
$cache->forget_group( string $group ): void
```

Hot data that lives in Redis (TTL guidelines):
| Data | Key pattern | TTL |
|------|-------------|-----|
| Pre-computed feed | `feed:{user_id}:{cursor}` | 60s |
| Follower count | `count:followers:{user_id}` | 300s |
| Following count | `count:following:{user_id}` | 300s |
| Unread notifications | `notif:unread:{user_id}` | 30s |
| Space membership | `space:member:{space_id}:{user_id}` | 600s |
| User profile fields | `profile:{user_id}` | 600s |
| Trending hashtags | `hashtags:trending` | 300s |

### 6. Async everything with Action Scheduler

Synchronous fan-out operations block the request and do not scale. Every operation that touches more than one row in response to a single user action must be async.

```
User creates post (50ms request)
  ↓
PostService::create() dispatches Action Scheduler jobs:
  ├── fan_out_feed      → write to bn_feed_items for each follower (batched 500/job)
  ├── dispatch_notifs   → create notifications (batched 500/job)
  ├── index_search      → update bn_search_index
  └── index_hashtags    → update bn_hashtags post counts
```

Action Scheduler jobs run in background workers, not on page load. WP-Cron is explicitly disabled for BuddyNext jobs.

### 7. Controller → View pattern (zero DB in templates)

Templates receive resolved data arrays. No service calls, no DB queries inside template files. The "controller" (the `template_redirect` handler) fetches all data, passes it to the template as variables. This is identical to a Laravel controller returning view data.

---

## Routing Map

### Hub registration

Each hub is registered via a data structure:

```php
array(
    'slug'          => 'feed',           // NavManager tab slug
    'slug_option'   => 'buddynext_slug_activity',
    'default_slug'  => 'activity',
    'page_option'   => 'buddynext_page_activity',
    'bn_hub'        => 'feed',           // query var value
    'template'      => 'feed/home',      // maps to templates/feed/home.php
    'endpoints'     => array(
        'explore'                    => array( 'bn_activity_action' => 'explore',   'template' => 'feed/explore' ),
        'hashtag/([^/]+)'            => array( 'bn_activity_action' => 'hashtag',   'template' => 'hashtags/feed' ),
        'search'                     => array( 'bn_activity_action' => 'search',    'template' => 'search/results' ),
        'leaderboard'                => array( 'bn_activity_action' => 'leaderboard','template' => 'gamification/leaderboard' ),
    ),
)
```

### Developer extension — custom hubs

```php
add_filter( 'buddynext_register_hubs', function( array $hubs ): array {
    $hubs['marketplace'] = array(
        'slug'         => 'marketplace',
        'slug_option'  => 'buddynext_slug_marketplace',
        'default_slug' => 'marketplace',
        'page_option'  => 'buddynext_page_marketplace',
        'bn_hub'       => 'marketplace',
        'template'     => 'marketplace/index',
        'endpoints'    => array(),
    );
    return $hubs;
} );
```

### Available query vars in all BuddyNext templates

| Query var | Set when | Contains |
|-----------|----------|----------|
| `bn_hub` | Always on BN route | `feed`, `people`, `spaces`, `messages`, `notifications`, `auth` |
| `bn_activity_action` | Activity sub-endpoints | `explore`, `hashtag`, `search`, `leaderboard` |
| `bn_hashtag` | Hashtag feed | hashtag slug string |
| `bn_user_slug` | Profile routes | URL slug of the member |
| `bn_resolved_user_id` | Profile routes | Resolved WP user ID (int) |
| `bn_profile_action` | Profile sub-routes | `edit`, `connections`, `media`, `badges` |
| `bn_space_slug` | Space routes | URL slug of the space |
| `bn_resolved_space_id` | Space routes | Resolved space ID (int) |
| `bn_space_action` | Space sub-routes | `members`, `settings`, `moderation`, `admin` |
| `bn_conv_id` | Message thread | Conversation ID (int) |
| `bn_msg_action` | Messages sub-routes | `requests` |
| `bn_member_type` | Member type filter | Member type slug |

---

## NavManager — Slug Conflict Detection

Before saving a slug via NavManager, the system checks for conflicts:

```php
// Check 1: another BN hub already uses this slug
// Check 2: an existing WP post/page has this slug
// Check 3: slug is a reserved WP keyword (feed, wp-admin, etc.)
```

UI shows:
- ✅ Green: slug is free
- ⚠️ Yellow: slug matches an existing page (but BN rewrite has priority — warn only)
- ❌ Red: slug matches another BN hub or is a reserved word (block save)

---

## Performance Targets

| Metric | Target | How |
|--------|--------|-----|
| Time to first byte (logged-in, Redis warm) | < 100ms | Plugin isolation + request filter + Redis |
| Feed query time | < 5ms | Pre-computed bn_feed_items + cursor pagination |
| Memory per BuddyNext request | < 32MB | Plugin isolation + lazy container |
| Notification fan-out (50K followers) | < 2s total | Action Scheduler async batches |
| Follower count read | < 1ms | Denormalized column + Redis |
| Search query (100K members) | < 10ms | FULLTEXT index on bn_search_index |

---

## Implementation Phases

| Phase | Scope | Impact |
|-------|-------|--------|
| 1 | Query-var routing + `request` filter | Correctness — fixes slug conflicts, enables clean URLs |
| 2 | Plugin isolation mu-plugin | Memory — 20–40MB saved per request |
| 3 | CacheService (Redis layer) | Query performance — 10–50x fewer DB hits |
| 4 | Async job wiring (Action Scheduler) | Scale — fan-out decoupled from request |

Each phase is independently deployable. Phase 1 is the foundation — Phases 2–4 build on top.
