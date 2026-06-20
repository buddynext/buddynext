# Journey: Sidebar Widgets

**Free feature**: `includes/Sidebar/` (WidgetService, WidgetCache, WidgetListener)
**Feature tier**: `default_on` — bound by `FeatureRegistry` (slug `sidebar`); owner can disable in Settings → Features
**Actions / filters consumed (cache-bust hooks)**: `buddynext_post_created`, `buddynext_post_deleted`, `buddynext_user_followed`, `buddynext_user_unfollowed`, `buddynext_block`, `buddynext_unblock`, `buddynext_space_member_joined`, `buddynext_space_member_left`, `buddynext_space_member_removed`
**Render hook**: `buddynext_right_sidebar` action (shell slot) → `templates/partials/sidebar.php`
**DB tables read**: `bn_hashtags`, `bn_follows`, `bn_blocks`, `bn_connections`, `bn_spaces`, `bn_space_members`, `users`
**Option toggled**: `buddynext_features['sidebar']`
**Estimated time**: 10 min manual

> This is a UI / widget feature. Per the runbook contract (README), no frontend assertions are made. Verification happens at the **service-method**, **feature-flag option**, and **template-existence** layers — not by scraping rendered HTML. The sidebar exposes **no REST endpoints**; its only admin surface is the Settings → Features toggle.

## Site-owner expectation

Out of the box (feature `default_on`, no configuration), a community owner expects the right-hand rail on every BN hub page to show three discovery cards, populated automatically from existing community data:

1. **Trending Topics** — top 5 hashtags by post count (site-wide, refreshes ~60 s).
2. **People to Follow** — up to 3 suggested members for the logged-in viewer (excludes self, already-followed, and blocked-either-direction users; guests see nothing here).
3. **Your Spaces** / **Discover Spaces** — up to 4 spaces. Logged-in: the viewer's joined spaces by member count. Guest: top open spaces.

Logged-in viewers also get a **greeting + streak** card and a **This Week (upcoming events)** card above the three discovery cards (events card renders for guests too). The member-directory hub additionally shows a **By role** member-count card (registered from `templates/directory/members.php`, not this partial).

What the owner can toggle / configure:

- **Toggle the whole feature** at Settings → BuddyNext → Features ("Sidebar widgets"). Disabling it stops the service from binding; the rail's three discovery cards fall back to empty arrays and render nothing.
- **No per-widget settings exist** — limits (5 / 3 / 4) are template-fixed. The only configuration is the on/off toggle plus the documented extension filters/hooks (`buddynext_feature_sidebar`, `buddynext_part_sidebar_card_*`).

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`
- Some seed data for non-empty widgets: at least one hashtagged post (`bn_hashtags` populated), one space, and one space membership. The journey works on empty data too (cards show empty-states), but seed data makes the widget output meaningful. Per MEMORY: seed before calling a gap.

All `wp` commands run in the LocalWP site shell (right-click site → "Open Site Shell"). No `wp-env` prefix.

## Happy-path steps

### Part 1: Confirm the feature is enabled and the service is bound

1. Confirm the `sidebar` feature resolves enabled (default_on tier, no option override):

   ```bash
   wp eval "echo buddynext_service('features')->is_enabled('sidebar') ? 'enabled' : 'disabled';"
   ```

   - Expected: `enabled`.

2. Confirm the service is bound in the container and resolves:

   ```bash
   wp eval "var_dump( \BuddyNext\Core\Container::instance()->has('sidebar_widgets') );"
   wp eval "var_dump( get_class( buddynext_service('sidebar_widgets') ) );"
   ```

   - Expected: `bool(true)` and `string(...) "BuddyNext\Sidebar\WidgetService"`.

3. Confirm the option is unset (clean default — tier default applies, no stored override):

   ```bash
   wp option get buddynext_features --format=json
   ```

   - Expected: either the option does not exist, or a JSON object that does **not** contain a `sidebar` key (clean default = `default_on` resolves true).

### Part 2: Exercise the three widget service methods

4. Trending hashtags (site-wide, no viewer):

   ```bash
   wp eval "print_r( buddynext_service('sidebar_widgets')->trending_hashtags(5) );"
   ```

   - Expected: array of ≤ 5 objects, each with `slug` and `post_count`, ordered by `post_count` DESC. Empty array if `bn_hashtags` is empty.

5. Suggested follows for `member1` (pass member1's numeric ID):

   ```bash
   MEMBER1_ID=$(wp user get member1 --field=ID)
   wp eval "print_r( buddynext_service('sidebar_widgets')->suggested_follows( ${MEMBER1_ID}, 3 ) );"
   ```

   - Expected: array of ≤ 3 user objects, each with `ID`, `display_name`, `user_login`, and a hydrated `follow_status` of `unfollowed` / `requested` / `following`. Excludes member1, anyone member1 already follows, and any block pair.

6. Suggested follows for a guest (user_id 0) returns empty by contract:

   ```bash
   wp eval "var_dump( buddynext_service('sidebar_widgets')->suggested_follows( 0, 3 ) );"
   ```

   - Expected: `array(0) {}` — `suggested_follows` short-circuits on user_id 0.

7. Joined spaces for `member1` (logged-in path — joined spaces by member count):

   ```bash
   wp eval "print_r( buddynext_service('sidebar_widgets')->joined_spaces( ${MEMBER1_ID}, 4 ) );"
   ```

   - Expected: array of ≤ 4 space objects (`id`, `name`, `slug`, `member_count`, `avatar_url`, plus `unread_count` if the optional column exists) for spaces member1 is an `active` member of.

8. Joined spaces for a guest (user_id 0 — guest path returns top open spaces):

   ```bash
   wp eval "print_r( buddynext_service('sidebar_widgets')->joined_spaces( 0, 4 ) );"
   ```

   - Expected: array of ≤ 4 **open** spaces ordered by `member_count` DESC (guests never see private/secret spaces here).

### Part 3: Confirm the cache layer round-trips

9. Call a method twice and confirm the second call is served from object cache (the bust hooks rely on this):

   ```bash
   wp eval "
   \$w = buddynext_service('sidebar_widgets');
   \$a = \$w->trending_hashtags(5);
   \$b = \$w->trending_hashtags(5);
   var_dump( \$a == \$b );
   echo wp_cache_get('trending:5', 'buddynext_widgets') !== false ? 'cached' : 'not-cached';
   "
   ```

   - Expected: `bool(true)` then `cached`. The `trending:5` key lives in the `buddynext_widgets` group with a 60 s TTL.

10. Trigger a cache-bust hook and confirm the key clears (listener path):

    ```bash
    wp eval "
    buddynext_service('sidebar_widgets')->trending_hashtags(5);
    do_action('buddynext_post_created', 0, 0);
    var_dump( wp_cache_get('trending:5', 'buddynext_widgets') );
    "
    ```

    - Expected: `bool(false)` — `WidgetListener::bust_trending()` → `WidgetCache::invalidate_trending()` deleted `trending:5`.
    - Note: on a non-persistent (per-request) object cache, the cache does not survive between separate `wp eval` invocations; this assertion must run inside a **single** `wp eval` block as shown.

### Part 4: Confirm the render path and templates exist

11. Confirm the render partial and all sidebar template parts are present:

    ```bash
    wp eval "
    \$base = WP_PLUGIN_DIR . '/buddynext/templates/';
    foreach ( array(
      'partials/sidebar.php',
      'parts/sidebar-card.php',
      'parts/sidebar-greeting-streak.php',
      'parts/sidebar-this-week-stats.php',
      'parts/sidebar-by-role.php',
    ) as \$t ) {
      echo \$t . ': ' . ( file_exists( \$base . \$t ) ? 'OK' : 'MISSING' ) . PHP_EOL;
    }
    "
    ```

    - Expected: all six print `OK`. (Adjust the plugin path if your install dir differs.)

12. Confirm the render hook is wired (the shell only renders the rail when something is hooked to `buddynext_right_sidebar`):

    ```bash
    wp eval "var_dump( has_action('buddynext_right_sidebar') !== false );"
    ```

    - Expected: `bool(true)` — hub templates (spaces directory, members directory, gamification leaderboard, etc.) register the partial on this action.

### Part 5: Toggle the feature OFF in Settings → Features and confirm the rail disappears

13. As `admin`, open Settings → Features:

    ```
    http://buddynext-dev.local/wp-admin/admin.php?page=buddynext&tab=features&autologin=1
    ```

    - Expected: a "Features" tab with a "Sidebar widgets" toggle (description: "Right-column widgets on hub pages — trending topics, suggested people, your spaces."), shown enabled.

14. Untick "Sidebar widgets", Save. (Equivalent CLI to simulate the saved option state:)

    ```bash
    wp eval "buddynext_service('features')->persist( array_merge( (array) get_option('buddynext_features', array()), array('sidebar' => false) ) );"
    wp option get buddynext_features --format=json
    ```

    - Expected: JSON now contains `"sidebar":false`.

15. Confirm the feature now resolves disabled and the service no longer binds **on a fresh request** (the container is built once per request; re-check in a new `wp eval`):

    ```bash
    wp eval "echo buddynext_service('features')->is_enabled('sidebar') ? 'enabled' : 'disabled';"
    ```

    - Expected: `disabled`. On the next page load the container's `register_services()` skips binding `sidebar_cache` / `sidebar_widgets`, so `templates/partials/sidebar.php` takes its fallback branch and the three discovery cards render empty (no trending / suggested / spaces rows).

### Part 6: Toggle the feature back ON and confirm it returns

16. Re-enable via Settings → Features (tick "Sidebar widgets", Save), or via CLI:

    ```bash
    wp eval "buddynext_service('features')->persist( array_merge( (array) get_option('buddynext_features', array()), array('sidebar' => true) ) );"
    wp eval "echo buddynext_service('features')->is_enabled('sidebar') ? 'enabled' : 'disabled';"
    ```

    - Expected: `enabled`. The service binds again; the three discovery cards repopulate on the next render.

## Edge cases to also verify

- **Filter override beats the option**: `buddynext_feature_sidebar` runs last in `is_enabled()` and wraps the option result. With the option set to `true`, force-disable at runtime:

  ```bash
  wp eval "add_filter('buddynext_feature_sidebar', '__return_false'); echo buddynext_service('features')->is_enabled('sidebar') ? 'enabled' : 'disabled';"
  ```

  - Expected: `disabled` — the per-feature filter wins over the stored option (resolution: mandatory → dependency check → option/tier default → `buddynext_feature_{slug}` filter).

- **Suggested-follows guest short-circuit**: `suggested_follows(0, 3)` returns `array()` without touching the DB (already covered in step 6). Confirms guests never get personalised follow suggestions even when other widgets render.

- **Limit clamping**: limits are clamped server-side (`max(1, min($limit, 20))`). Request an out-of-range limit:

  ```bash
  wp eval "echo count( buddynext_service('sidebar_widgets')->trending_hashtags(999) );"
  ```

  - Expected: ≤ 20 (clamped), never 999.

- **Spaces guest vs member divergence**: `joined_spaces(0, 4)` returns top **open** spaces; `joined_spaces(MEMBER_ID, 4)` returns that member's **active** memberships. Run both (steps 7 & 8) against a member who has joined a private space — the private space appears in the member call but never in the guest call.

## What this validates

- `FeatureRegistry::is_enabled('sidebar')` resolves `default_on` correctly (true with no option, respects stored option, respects `buddynext_feature_sidebar` filter).
- `Plugin::register_services()` binds `sidebar_cache` + `sidebar_widgets` only when the feature is enabled, and `WidgetListener::register()` is only wired when `sidebar_widgets` is bound.
- `WidgetService::trending_hashtags()` returns clamped, count-ordered hashtag rows.
- `WidgetService::suggested_follows()` excludes self / followed / blocked pairs, hydrates `follow_status`, and short-circuits for guests.
- `WidgetService::joined_spaces()` diverges by viewer (joined-active for members, top-open for guests) and tolerates the optional `unread_count` column.
- `WidgetCache::get()` round-trips through the object cache with the documented keys / groups / TTLs.
- `WidgetListener` cache-bust hooks fire `invalidate_trending()` / `invalidate_user()` on the relevant domain actions.
- `templates/partials/sidebar.php` and the `parts/sidebar-*.php` template parts exist and are wired to `buddynext_right_sidebar`.

## Verification queries

The widget data comes from existing tables — verify the rows the widgets read against the service output:

```sql
-- Trending source (what the Trending Topics card reads):
SELECT slug, post_count
FROM wp_bn_hashtags
ORDER BY post_count DESC
LIMIT 5;

-- A member's active space memberships (what Your Spaces reads for that viewer):
SELECT s.id, s.name, s.slug, s.member_count
FROM wp_bn_spaces s
INNER JOIN wp_bn_space_members sm
  ON sm.space_id = s.id AND sm.user_id = MEMBER1_ID AND sm.status = 'active'
ORDER BY s.member_count DESC
LIMIT 4;

-- Top open spaces (what Discover Spaces reads for a guest):
SELECT id, name, slug, member_count
FROM wp_bn_spaces
WHERE type = 'open'
ORDER BY member_count DESC
LIMIT 4;
```

Option / feature-state checks (run in the site shell):

```bash
# Feature toggle state (clean default = key absent or sidebar:true):
wp option get buddynext_features --format=json

# Resolved enabled state:
wp eval "var_dump( buddynext_service('features')->is_enabled('sidebar') );"

# Service binding present:
wp eval "var_dump( \BuddyNext\Core\Container::instance()->has('sidebar_widgets') );"
```

## REST surface walked

**None.** The sidebar feature exposes no REST endpoints. There are no routes under `buddynext/v1/sidebar*` or `buddynext/v1/widgets*`. All data is rendered server-side through `WidgetService` inside `templates/partials/sidebar.php`. Verification is service-method + option-state + template-existence only.

Admin surface (the only owner-facing control):

```
GET  /wp-admin/admin.php?page=buddynext&tab=features   -- Settings → Features toggle ("Sidebar widgets")
                                                           persists buddynext_features['sidebar']
```

## Frontend action wiring

*(Item 11. The sidebar has no JS actions — but the shell that hosts it has two admin-gated render decisions that MUST be verified against rendered HTML, not grep. A grep-only audit wrongly called both "dead" on 2026-06-20; they work.)*

| Render decision | Template (file) | Read via | Verify (HTML) |
|---|---|---|---|
| Desktop rail shown? | `templates/shell/hub-shell.php:89` | `buddynext_community_rail_enabled()` | option off → `grep -c 'bn-app__rail'` is 0; on → 1 |
| Mobile bottom nav shown? | `templates/shell/hub-shell.php:140` | `buddynext_community_mobile_nav_enabled()` | option off → `grep -c 'bn-mobile-nav'` is 0; on → >0 |
| Right sidebar slot shown? | `templates/shell/right-sidebar.php:35` | `has_action('buddynext_right_sidebar')` | renders only when a widget is hooked |

## Admin-config → member-effect

*(Item 12. The canonical verified example — do NOT trust grep for these; flip the option and fetch the HTML.)*

```bash
BASE=http://buddynext.local
curl -s -c /tmp/bn.txt -o /dev/null -L "$BASE/?autologin=1"
# Desktop rail toggle
wp option update buddynext_enable_community_rail 0
curl -s -b /tmp/bn.txt -L "$BASE/activity/" | grep -c 'bn-app__rail'      # expect 0
wp option delete buddynext_enable_community_rail                          # restore (default on)
# Mobile bottom nav toggle
wp option update buddynext_enable_community_mobile_nav 0
curl -s -b /tmp/bn.txt -L "$BASE/activity/" | grep -c 'bn-mobile-nav'     # expect 0
wp option delete buddynext_enable_community_mobile_nav                    # restore
# Sidebar widgets feature toggle: off -> the three discovery cards render nothing
```

- **Sidebar widgets feature** (`buddynext_features['sidebar']`): OFF → `WidgetService` stops binding, the three cards fall back to empty and render nothing; the rail container may still show (it is gated separately by `buddynext_enable_community_rail`). Confirm both independently.

## Cleanup

The journey writes only to the `buddynext_features` option (and transient object-cache entries). Reset to clean default:

```bash
# Restore the clean default — remove any sidebar override key.
# Simplest: re-persist with sidebar enabled, or delete the whole option if it
# only held this journey's change.
wp eval "buddynext_service('features')->persist( array('sidebar' => true) );"

# Or, if the option existed only for this test, remove it entirely:
# wp option delete buddynext_features

# Flush object cache so stale widget entries don't linger:
wp cache flush
```

```sql
-- No journey-specific rows are created in any bn_* table by this journey;
-- the widgets only READ existing hashtag / follow / space data. Nothing to
-- delete here unless you also seeded test posts/spaces for non-empty output.
```

## Known limitations

- **Cache-bust key mismatch (suggested follows)**: `WidgetService::suggested_follows()` writes its cache under the key `suggested-v2:{user}:{limit}` (key was bumped to `-v2` after a `follow_status` payload change), but `WidgetCache::invalidate_user()` still deletes `suggested:{user}:3` (the pre-v2 key). As a result, the follow / unfollow / block bust hooks do **not** clear the suggested-follows cache; that widget self-heals only when its 300 s `TTL_USER` expires. The joined-spaces key (`spaces:{user}:4`) is busted correctly. File: `includes/Sidebar/WidgetCache.php` line ~90.
- **No per-widget configuration**: widget limits (5 trending, 3 suggested, 4 spaces) are hard-coded in `templates/partials/sidebar.php`. The only owner control is the whole-feature on/off toggle.
- **Disabling the feature renders empty cards, not a hidden rail**: when `sidebar` is disabled, `templates/partials/sidebar.php` falls back to empty arrays and still emits the three `.bn-sidebar-card` shells (empty-state copy), rather than removing them. The rail itself only hides when *nothing* is hooked to `buddynext_right_sidebar`, but hub templates still register the partial regardless of the feature flag.
- **`suggested_follows` uses `ORDER BY RAND()`**: acknowledged in-code as expensive at scale; the cache absorbs it. A precomputed affinity pool is deferred to the AI Feed (P2.1) work and is not present in Free.
- **`unread_count` column is optional**: `joined_spaces()` probes for `bn_space_members.unread_count` via `SHOW COLUMNS` (cached 1 h). On schemas without the column the unread dot never renders — expected, not a bug.

## Automation notes

- Every assertion in this journey is a `wp eval` / `wp option` call — fully scriptable in the LocalWP site shell with no HTTP layer. There is nothing to curl (no REST surface).
- The cache round-trip and bust assertions (steps 9–10) **must** run inside a single `wp eval` block each, because a non-persistent object cache does not survive between separate CLI invocations.
- The feature-toggle steps (Part 5 / Part 6) are best verified across two requests: persist the option in one `wp eval`, then re-check `is_enabled()` / container binding in a fresh `wp eval` so the container rebuilds.
- A Playwright variant would log in as admin, toggle "Sidebar widgets" at `admin.php?page=buddynext&tab=features`, then load a hub page (e.g. `/members/` or `/spaces/`) and assert presence/absence of `.bn-sidebar-card` blocks — but per the README contract, frontend assertions are out of scope until the design is final; keep automation at the service + option layer.
