# BuddyNext — Free/Pro Extension Contract

**Status:** Locked
**Last updated:** 2026-05-20
**Owner:** BuddyNext core team
**Companion specs:**
- [`FREE-VS-PRO.md`](FREE-VS-PRO.md) — feature classification (locked 2026-03-20)
- [`../HOOKS.md`](../HOOKS.md) — canonical hook reference for all Free hooks

---

## 1. The Upscale Rule

Pro always requires Free. Pro extends Free via class inheritance, container rebind, or filter callbacks. There are no parallel class implementations across the two plugins. If Free has a service, Pro inherits or reuses it. If Pro needs to change behavior, it overrides through one of the documented patterns in §4 — never by shipping a second class with the same responsibility.

This rule exists because of a real failure mode observed in WPMediaVerse Pro: when that plugin shipped duplicated versions of `BlockRegistrar`, `Migrator`, `Sanitizers`, and `Shortcodes`, every bug fix had to land in two places, and asset enqueuing fought itself in production. BuddyNext Pro avoids this from day one. The contract is simple: Pro extends, Pro filters, Pro listens — Pro never re-implements.

---

## 2. Bootstrap Chain

| Hook timing | Plugin | What happens |
|---|---|---|
| `plugins_loaded:10` | WPMediaVerse, Jetonomy, etc. | Addons boot first, establish their service containers |
| `plugins_loaded:15` | BuddyNext (Free) | `BuddyNext\Core\Plugin::init()` runs, fires `buddynext_loaded` |
| `plugins_loaded:20` | BuddyNext Pro | `BuddyNextPro\Core\Plugin::init()` runs, fires `buddynext_pro_loaded` |
| `plugins_loaded:25` | Bridges | Cross-plugin glue (`JetonomyBridgeListener`, `WPMediaVerseBridgeListener`, etc.) |

### Defensive boot guard (required in Pro)

Pro's `Plugin::init()` must begin with this check:

```php
if ( ! class_exists( 'BuddyNext\\Core\\Plugin' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'BuddyNext Pro requires BuddyNext (Free) to be installed and active.', 'buddynext-pro' )
            . '</p></div>';
    } );
    return;
}
```

Pro's plugin header must also declare `Requires Plugins: buddynext` so WordPress enforces the dependency on activation.

---

## 3. The Four Extension Patterns

### Pattern A — Container rebind (whole-service replacement)

Pro fully replaces a Free service by rebinding the container key before any consumer resolves it.

```php
// In BuddyNextPro\Core\Plugin::bind_services():
buddynext_service_container()->bind(
    'feed',
    fn( $c ) => new \BuddyNextPro\Feed\ProFeedService(
        $c->get( 'follows' ),
        $c->get( 'post_service' )
    )
);
```

**When to use:** Pro fully replaces a service's logic (AI feed ranking, broadcast email queue, WebSocket real-time layer).

**Free side cost:** Zero. `Core/Container.php:66` already supports rebind. Any subsequent `buddynext_service('feed')` call resolves the Pro class transparently.

**Pro requirement:** `ProFeedService` must extend Free's `FeedService`. Override only the methods that differ; inherit the rest.

### Pattern B — Class inheritance + rebind (incremental override)

Pro subclasses a Free service to override specific methods, then rebinds via Pattern A.

```php
// BuddyNextPro\Notifications\RealtimeNotificationService:
class RealtimeNotificationService extends \BuddyNext\Notifications\NotificationService {

    public function create( array $payload ): int {
        $id = parent::create( $payload );
        $this->push_via_websocket( $id, $payload );
        return $id;
    }

    // delete(), mark_read(), get_for_user() — all inherited unchanged
}
```

Then bound via Pattern A at `plugins_loaded:20`.

**Constraint:** Free service classes must not be declared `final`. Methods Pro overrides must be `protected` or `public` — never `private`. Any Free service method intended as an extension point should be documented as such in its docblock.

### Pattern C — Filter callback (granular mutation)

Pro registers a `add_filter()` callback against a filter Free fires at the right seam. No container rebind needed.

```php
// Registered in BuddyNextPro\Core\Plugin::init():
add_filter( 'buddynext_feed_items', [ ProAiRanker::class, 'rerank' ], 10, 4 );
```

**When to use:** List reorder, score injection, value substitution, limit change, flag toggle. Appropriate when Pro modifies output but does not replace the whole computation.

**Free side cost:** Free must fire `apply_filters()` at the seam (see §4 for all 15 defined seams). Pro never modifies Free source to add filters after the contract is locked.

### Pattern D — Action listener (analytics, signals)

Pro listens to Free's existing `do_action()` calls to record signals, dispatch webhooks, or update Pro tables. No change to Free ever required.

```php
// Registered in BuddyNextPro\Core\Plugin::init():
add_action( 'buddynext_post_impression', [ ProAnalyticsCollector::class, 'record' ], 10, 3 );
add_action( 'buddynext_profile_viewed',  [ ProProfileStats::class, 'record' ],       10, 2 );
```

**When to use:** Analytics collection, AI signal recording, webhook dispatch, audit log enrichment, drip email triggers.

Pro consumes all 72+ existing `buddynext_*` actions documented in [`../HOOKS.md`](../HOOKS.md). The three additional analytics-focused actions added by Track A are listed in §6.

---

## 4. The 15 Filters Free Exposes for Pro

Track A (Free-side work) is adding these filters to Free's source. This table is the specification they implement against. Pro must not add usage of an undocumented filter — if a seam is missing, add it to Free via a tracked change and bump this document.

| # | Filter | Fired in | Signature | Pro use |
|---|---|---|---|---|
| 1 | `buddynext_feed_items` | `Feed/FeedService` (home, profile, space, explore methods) | `(array $items, string $scope, int $viewer_id, array $args)` | AI rerank — Pro's AI engine reorders the item array by predicted engagement before the list is returned to the controller. Without this filter, AI ranking would require replacing the entire FeedService. |
| 2 | `buddynext_feed_query_args` | `Feed/FeedService` | `(array $args, string $scope, int $viewer_id)` | Tier filtering — Pro can inject `ability_required` conditions so gated-space content is excluded from feeds for members who lack the required ability. |
| 3 | `buddynext_post_pin_limit` | `Feed/PostService` | `(int $limit, ?int $space_id, int $user_id)` | Pro allows up to 10 pinned posts per space or profile. Free returns `1`; Pro returns `10` for licensed sites. |
| 4 | `buddynext_reaction_types` | `Reactions/ReactionService` | `(array $types)` | Custom emoji set — admin-defined up to 20 reactions (Pro feature per FREE-VS-PRO.md). Free ships 6 standard types; Pro merges custom reactions stored in `get_option('buddynextpro_custom_reactions')`. |
| 5 | `buddynext_can_join_space` | `Spaces/SpaceMemberService` | `(bool $can, array $space, int $user_id, string $action)` | Gated spaces — Pro checks `buddynext_can($user_id, $space['required_ability'])` when `$space['required_ability']` is set. Returns `false` for users lacking the ability, which Free's join flow respects without any other code change. |
| 6 | `buddynext_notification_should_send` | `Notifications/NotificationService` | `(bool $should, array $payload)` | AI fatigue suppression — Pro's smart notification engine suppresses low-signal notifications (e.g. 50th reaction this hour) to reduce notification fatigue, based on signals in `bn_ai_signals`. |
| 7 | `buddynext_notification_send_at` | `Notifications/NotificationService` | `(?string $send_at, array $payload)` | AI optimal send time — Pro returns a future UTC datetime string when its model predicts better open rates; Free defaults to `null` (send immediately). Requires the `bn_notifications` table to support a `send_at` column (Track A). |
| 8 | `buddynext_email_payload` | `Notifications/EmailSender` | `(array $payload, string $template_slug, array $context)` | Broadcast queue intercept — Pro's email campaign system can intercept transactional emails destined for campaign-enrolled members and route them through the broadcast queue for throttling and analytics tracking. |
| 9 | `buddynext_safeguard_check` | `Moderation/SafeguardService` | `($result, int $user_id, string $content, string $link_url)` | Keyword blocklist and ML scoring — Pro's advanced moderation engine appends its own result (flagged, score, matched_pattern) to Free's basic safeguard result, enabling auto-action rules defined in `bn_mod_rules`. |
| 10 | `buddynext_moderation_auto_actions` | `Moderation/ModerationService` | `(array $actions, array $report)` | Auto-action rules — Pro injects rule-driven actions (auto-remove, auto-warn) by evaluating `bn_mod_rules` against the report data. Free executes whatever `$actions` the filter returns. |
| 11 | `buddynext_profile_field_types` | `Profile/ProfileFieldsManager` | `(array $types)` | Advanced field types — Pro adds `date`, `location`, `file_upload`, `multi_select`, `number`, and `conditional` field types to the admin field builder UI and the profile field renderer. |
| 12 | `buddynext_search_query_args` | `Search/SearchService` | `(array $args, string $query, int $viewer_id)` | Saved searches and advanced filters — Pro merges saved-search criteria (filter by profile field value, activity level, space membership, member tier, member label) into the query args before Free executes the search. |
| 13 | `buddynext_outbound_webhook_limit` | `Outbound/OutboundWebhookService` | `(int $limit)` | Unlimited webhooks — Free returns `1`; Pro returns `PHP_INT_MAX` for licensed sites, enabling the full catalog of unlimited outbound endpoints listed in FREE-VS-PRO.md. |
| 14 | `buddynext_brand_name` | `Core/Plugin` (static helper) | `(string $name)` | White-label — Pro returns the site-configured brand name (stored in `buddynextpro_brand_name` option) instead of "BuddyNext". Used in admin headings, email footers, and Gutenberg block editor labels. Agency (Unlimited) license only. |
| 15 | `buddynext_brand_logo_url` | `Core/Plugin` (static helper) | `(?string $url)` | White-label — Pro returns a custom logo URL for admin panel headers, email templates, and block editor chrome. `null` means "use BuddyNext default". Agency (Unlimited) license only. |
| 16 | `buddynext_realtime_transport` | `Realtime/TransportFactory` (static factory) | `(RealtimeTransport $transport)` | WebSocket real-time layer — Pro returns a `BuddyNextPro\Realtime\WebSocketTransport` instance (Soketi or Ratchet) in place of Free's no-op `PollingTransport`. Free's polling surfaces continue to work when the filter is not hooked. The returned value must implement `BuddyNext\Realtime\RealtimeTransport`; a non-conforming return falls back to `PollingTransport` silently. |

> Note: Every filter in this table fires in Free regardless of whether Pro is active. Free's behavior is always the unfiltered default. Pro registration of callbacks is conditional on license validation inside `BuddyNextPro\Core\Plugin::init()`.

---

## 5. The 3 Analytics Actions Free Fires for Pro

These three actions are added by Track A to Free's source. Pro uses them exclusively via Pattern D (listener) — no Free change is needed after initial addition.

| Action | Fired in | Signature | Pro use |
|---|---|---|---|
| `buddynext_profile_viewed` | `Profile/ProfileController` (GET /profiles/{id}) | `(int $profile_user_id, int $viewer_id)` | Powers the "Who viewed your profile" Pro feature. Pro writes to `bn_analytics_events` and surfaces the list via `buddynext-pro/v1/me/profile-viewers`. |
| `buddynext_post_impression` | `Feed/FeedService` (when a post enters the returned list) | `(int $post_id, int $viewer_id, string $surface)` | Powers per-post reach stats visible to post authors (Pro feature). `$surface` is one of `home`, `profile`, `space`, `explore`, `hashtag`. Pro writes to `bn_analytics_events`. |
| `buddynext_search_performed` | `Search/SearchService` (after results are assembled) | `(string $query, int $viewer_id, array $args, array $results)` | Two Pro uses: (1) saved-search recording for the Advanced Search Pro feature; (2) AI relevance signal collection for `bn_ai_signals`. |

Pro also listens to all 72+ existing `buddynext_*` actions for gamification signals, webhook dispatch, drip enrollment triggers, and audit log enrichment. See [`../HOOKS.md`](../HOOKS.md) for the full list.

---

## 6. Schema Additions (Track A)

These three columns are added by Track A to existing Free tables. Pro features depend on them; Free's own code ignores them unless the filter or action that surfaces them is also filtered by Pro.

| Table | Column | Type | Default | Pro feature |
|---|---|---|---|---|
| `bn_spaces` | `required_ability` | `VARCHAR(64)` | `NULL` | Gated spaces — stores the ability slug checked by `buddynext_can_join_space` filter |
| `bn_spaces` | `accent_color` | `VARCHAR(16)` | `NULL` | Per-space custom branding (Pro: Access/Spaces, FREE-VS-PRO.md §Access & Spaces) |
| `bn_spaces` | `description_layout` | `VARCHAR(32)` | `'standard'` | Layout variants for space description panels (Pro: Access/Spaces) |

These columns are added by Free's `Installer` via `dbDelta()` in the same pass that creates `bn_spaces`. They are nullable or have safe defaults so Free's queries are unaffected.

---

## 7. Table Ownership

### Free Installer creates — and owns — these tables

`bn_follows`, `bn_connections`, `bn_blocks`, `bn_posts`, `bn_poll_options`, `bn_poll_votes`, `bn_bookmarks`, `bn_shares`, `bn_spaces`, `bn_space_members`, `bn_space_categories`, `bn_notifications`, `bn_notification_prefs`, `bn_email_templates`, `bn_email_log`, `bn_verify_tokens`, `bn_reactions`, `bn_comments`, `bn_hashtags`, `bn_post_hashtags`, `bn_hashtag_follows`, `bn_search_index`, `bn_profile_groups`, `bn_profile_fields`, `bn_profile_values`, `bn_reports`, `bn_mod_log`, `bn_user_strikes`, `bn_user_abilities`, `bn_user_suspensions`, `bn_appeals`, `bn_space_bans`, `bn_outbound_webhooks`, `bn_outbound_webhook_log`, `bn_activity_log`, `bn_feed_items`, `bn_member_types`, `bn_user_member_types` (approximately 43 tables total, exact list is authoritative in `Core/Installer.php`).

### Pro Installer creates — and owns — these tables

`bn_membership_tiers`, `bn_subscriptions`, `bn_ai_signals`, `bn_analytics_events`, `bn_email_campaigns`, `bn_campaign_recipients`, `bn_drip_sequences`, `bn_drip_enrollments`, `bn_mod_rules`, `bn_member_labels`, `bn_member_label_assignments` (11 Pro-owned tables). Pro reuses Free's `bn_appeals` table for the moderation appeal workflow — it does not create a duplicate.

### The ownership rule

The plugin that owns a table is the only plugin permitted to call `dbDelta()` for it. Pro's `Installer` must never create a Free table. Free's `Installer` must never create a Pro table. Cross-plugin writes go through the owning plugin's service class — Pro calls `buddynext_service('moderation')->get_appeals()`, not raw `$wpdb->get_results('SELECT * FROM bn_appeals')`.

---

## 8. Forbidden Patterns (The Mediaverse Anti-pattern)

The following patterns are prohibited. Any PR that introduces one of these must be rejected.

- **No duplicate-named classes.** Do not ship `BuddyNextPro\Feed\FeedService`, `BuddyNextPro\Core\BlockRegistrar`, `BuddyNextPro\Core\Migrator`, `BuddyNextPro\Core\Sanitizers`, or any other class whose name matches a Free class. Extend (Pattern B) or consume via container (Pattern A) instead.
- **No parallel hook registration for the same purpose.** If Free's `NotificationListener` already registers `add_action('buddynext_post_created', ...)` to send an in-app notification, Pro must not register its own `add_action('buddynext_post_created', ...)` for the same notification. Pro either subclasses `NotificationListener` or filters Free's output.
- **No parallel asset bundles.** Pro asset handles must list Free's handle in `$deps[]`. Never register a Pro stylesheet that duplicates Free CSS. Example: `buddynextpro-spaces` depends on `buddynext-spaces` in its `wp_enqueue_style()` call.
- **No direct WordPress primitive calls when Free wraps them.** If Free has `Settings_Helper::get('key')`, Pro uses that helper — not raw `get_option('buddynext_key')`. If Free has `buddynext_can($user_id, 'ability')`, Pro uses that — not raw capability checks against the Abilities API.
- **No container bypass.** Pro never calls `new \BuddyNext\Feed\FeedService()` directly. Always go through `buddynext_service('feed')`. This ensures a future rebind (including Pro's own rebind) wins transparently.
- **No source modification of Free.** Pro never monkey-patches Free classes via `runkit`, `Patchwork`, or runtime class replacement. All extension goes through the documented patterns.
- **No undocumented filter usage.** If Pro needs a seam that doesn't exist yet, the correct path is: add the filter to Free via a tracked change, document it in this contract, then use it in Pro.

---

## 9. Pair-Plugin Invariants

These invariants are derived from `CLAUDE.md` and `FREE-VS-PRO.md`. Every Pro PR is evaluated against this list.

- **Free has no runtime dependency on Pro.** Free runs identically whether Pro is active or not. (Violation maps to: §2 bootstrap, §3 patterns A-D.)
- **Pro's bootstrap waits for Free deterministically.** `Requires Plugins:` header + `class_exists` guard + `plugins_loaded@20`. (Maps to: §2.)
- **REST namespaces are disjoint.** Free = `buddynext/v1`. Pro = `buddynext-pro/v1`. Route paths must not collide even across namespaces. (Maps to: §9 invariants.)
- **Each plugin owns its tables; cross-plugin writes go through the owner's service.** (Maps to: §7.)
- **AJAX action namespaces don't collide.** Free uses `bn_*` action names. Pro uses `bnpro_*`. Documented in plugin bootstrap; enforced on every new AJAX endpoint.
- **CPT ownership is exclusive.** If Free registers a CPT, Pro does not re-register it. Pro adds post meta or taxonomies via `register_post_meta()` / `register_taxonomy_for_object_type()`.
- **Custom capabilities have safe fallbacks.** Pro-only capabilities default to `manage_options` when unset, never to `false`, so sites upgrading from Free don't lock admins out.
- **Asset handle namespace separation.** Free: `buddynext-*`. Pro: `buddynextpro-*`. No exceptions.
- **Hook arg-signature compatibility.** The `args_signature` declared in Free's manifest must match the `accepted_args` used in Pro's listeners. When a signature changes in Free, Pro's `Requires BuddyNext:` minimum version bumps accordingly. (Maps to: §11 versioning.)
- **No duplicate class concerns across Free and Pro.** Pro extends, consumes, or filters — never re-implements in parallel. (Maps to: §1 Upscale Rule, §8 forbidden patterns.)
- **Pro asset handles depend on Free asset handles.** `buddynextpro-feed` lists `buddynext-feed` in `$deps[]`. (Maps to: §8.)
- **Pro extends Free's services, never WordPress directly.** Pro goes through Free's container and helpers. (Maps to: §8.)
- **Pro extension via documented hooks only.** No source modification of Free, no monkey-patching. (Maps to: §8.)
- **Breaking changes to a documented filter/action signature require a Pro major bump.** See §11.

---

## 10. Quick-Start: Building the First Pro Feature

**Goal:** Add a custom reaction emoji set (the simplest possible Pro feature — uses Pattern C, no new service needed).

**Step 1 — Identify the seam.** The `buddynext_reaction_types` filter (row 4 in §4) is already defined. Free calls it in `ReactionService::get_types()`. No Free change needed.

**Step 2 — Register the callback in Pro's init.**

```php
// BuddyNextPro\Core\Plugin::init():
add_filter( 'buddynext_reaction_types', [ ProReactions::class, 'extend' ] );
```

**Step 3 — Write the callback.**

```php
// BuddyNextPro\Reactions\ProReactions.php:
class ProReactions {

    public static function extend( array $types ): array {
        $custom = get_option( 'buddynextpro_custom_reactions', [] );
        if ( empty( $custom ) || ! is_array( $custom ) ) {
            return $types;
        }
        return array_merge( $types, $custom );
    }
}
```

**Step 4 — Build the admin UI.** `BuddyNextPro\Admin\CustomReactions` provides the settings screen where admins configure up to 20 custom reactions. It writes to `buddynextpro_custom_reactions` via `update_option()`. This class lives entirely in Pro — Free never touches it.

**Step 5 — Verify.** Free's `ReactionService` already calls `apply_filters('buddynext_reaction_types', $this->default_types())`. With Pro active, the filter fires and the custom set merges in. With Pro inactive, the filter still fires but returns Free's default 6 types unchanged. No conditional logic required in either plugin.

---

## 11. Manifest Discipline

Both Free and Pro maintain machine-readable manifests generated by the `wp-plugin-onboard` skill.

- **Free manifest:** `audit/manifest.json` in the `buddynext` repo. Tracks all services, hooks fired, REST routes, capabilities, tables, and asset handles.
- **Pro manifest:** `audit/manifest.json` in the `buddynext-pro` repo. Same structure.
- **Cross-plugin coupling file:** `audit/derived/cross-plugin-coupling.json` in the Pro repo. Tracks:
  - Which Free filters Pro listens to (from §4)
  - Which Free actions Pro listens to (from §5 + HOOKS.md)
  - The `Requires BuddyNext:` minimum version pinned to each hook used
  - Any Free tables Pro reads (read-only, via Free's service layer)

This file is regenerated on every Pro manifest refresh. A CI check compares it against this contract document — any Pro listener referencing a filter not in §4 fails the build with a "undocumented extension seam" error.

---

## 12. Versioning

Free and Pro version independently. Pro `0.5` can ship while Free is at `1.2`. The coupling is captured by the minimum Free version, not synchronized version numbers.

### Minimum version pinning

Pro's plugin header must declare:

```
Requires BuddyNext: 1.0.0
```

When Free ships a change that affects a contract-affecting filter (new arg added, arg type changed, arg removed), the minimum required version bumps in Pro's next release.

### Contract changes log

Append to this section when a filter or action signature changes. Format:

```
## Contract Changes

### 2026-MM-DD — buddynext_feed_items
Old signature: (array $items, string $scope, int $viewer_id)
New signature:  (array $items, string $scope, int $viewer_id, array $args)
Requires Free:  bumped to 1.1.0
Pro minimum:    buddynext-pro 0.3.0+ required
```

Breaking changes to a documented seam require a Free minor or major bump (depending on severity) and a corresponding Pro `Requires BuddyNext:` bump. Additive changes (new optional arg at the end) require a minor bump. Removals or reordering require a major bump.
