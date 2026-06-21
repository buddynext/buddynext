# Gamification Engine Seam

This is the contract a gamification engine implements to plug into BuddyNext. BuddyNext fires raw write-side actions, exposes recipient-perspective engagement signals and session/streak pulses, offers sidebar/profile data seams, and renders a leaderboard from the engine's public read API. BuddyNext ships **zero** gamification logic - no points, badge, level, or streak computation, and no own `wbg_*` tables. The reference engine is wb-gamification (`wb_gam_*` public API); any plugin that implements the same shape works. This page is for developers building or replacing that engine.

> **Status (1.0.1).** The write-side submission described below is now owned by the engine's own BuddyNext manifest (in wb-gamification, that is `integrations/buddynext.php`), which hooks BuddyNext's raw `buddynext_*` actions and calls `wb_gam_submit_event()`. The BuddyNext-side `GamificationBridge` no longer submits events; its only producer role is posting a credential-badge feed activity on `wb_gam_badge_awarded`. The action catalogue and `fire()` signatures below remain the contract shape, now implemented on the engine side.

![The admin dashboard whose sidebar and leaderboard data the gamification-engine seam documented here feeds](../images/admin-overview.webp)

## Overview / Contract

The seam has four parts:

1. **Write-side events** - BuddyNext fires raw `buddynext_*` actions for every social action; the engine's own BuddyNext manifest hooks them and submits award events. A pre-1.0.0 BuddyNext-side bridge did this submitting; that producer wiring has been retired.
2. **Session / streak / daily-login pulses** - idempotent per-window signals that drive streak counters.
3. **Recipient-perspective engagement events** - mirrors that fire for the *recipient* of engagement (the person whose work was liked/commented/followed), which is who gamification usually awards.
4. **Read-side rendering** - the leaderboard template and the sidebar/profile data filters consume the engine's public read API only; BuddyNext never reads engine tables.

As of 1.0.1 the BuddyNext-side `GamificationBridge` (`includes/Bridges/GamificationBridge.php`) is consume-only: it posts a credential-badge feed activity on `wb_gam_badge_awarded`. The write-side submissions are owned by the engine's own BuddyNext manifest. The inbound listener is `GamificationBridgeListener`; the profile surface is `BuddyNext\Profile\GamificationAchievements`. These self-guard on the `wb_gam_*` API and are wired on `buddynext_load_bridges` behind the `gamification` feature toggle.

### Engine API BuddyNext calls

The bridge and templates call exactly these engine functions, all guarded with `function_exists`:

| Function | Used by | Purpose |
|---|---|---|
| `wb_gam_submit_event( int $user_id, string $action_id, array $context )` | `GamificationBridge::fire()` | Submit one award event through the full pipeline (points, badges, streaks, webhooks). |
| `wb_gam_register_action( array $args )` | `GamificationBridge::register_actions()` | Register a BuddyNext action so admins can configure its point value. |
| `wb_gam_get_actions()` | `GamificationBridge::register_actions()` | Dedup guard - skip already-registered slugs. |
| `wb_gam_get_leaderboard( string $period, int $limit )` | leaderboard template | Ranked rows (`rank`, `user_id`, `display_name`, `avatar_url`, `points`). |
| `wb_gam_get_user_points( int $user_id )` | leaderboard + Achievements tab | Points balance. |
| `wb_gam_get_user_badges( int $user_id )` | leaderboard + Achievements tab | Earned badges. |
| `wb_gam_get_user_streak( int $user_id )` | leaderboard | Streak data. |

An engine replacing wb-gamification must provide functions of these names and shapes.

## Write-side events (the bridge submission path)

`GamificationBridge::register_actions()` registers a catalogue of `bn_*` action slugs with default point values, so the engine recognizes the slug and admins get a configurable point row per action:

| Action slug | Label | Default points | Recipient awarded |
|---|---|---|---|
| `bn_followed` | Followed by a member | 5 | the followed user |
| `bn_connected` | Connection accepted | 10 | BOTH connected peers |
| `bn_post_created` | Post created | 5 | the author |
| `bn_space_joined` | Joined a space | 5 | the joining user |
| `bn_strike_issued` | Moderation strike issued | 0 | the struck user (for deductions) |
| `bn_profile_updated` | Profile updated | 2 | the member |
| `bn_profile_completed` | Profile completed | 25 | the member (one-time at 100%) |
| `bn_reaction_received` | Reaction received on your content | 2 | the content owner |
| `bn_comment_created` | Comment created | 3 | the comment author |

Each catalogue entry is registered against an inert hook (`buddynext_gamification_noop`, never fired) with `user_callback => '__return_zero'`. This is deliberate: the engine's registration API mandates a real hook + callable and auto-hooks it, but BuddyNext wants to resolve the correct recipient(s) itself and submit manually - so it binds to a never-fired hook (no auto-award) and emits each event exactly once from `fire()`.

The bridge listens on these BuddyNext producer hooks and translates each into a submission:

| BuddyNext hook (args) | Bridge handler | Submits |
|---|---|---|
| `buddynext_user_followed` (2) | `on_user_followed` | `bn_followed` to the followed user |
| `buddynext_connection_accepted` (3) | `on_connection_accepted` | `bn_connected` to each peer |
| `buddynext_post_created` (3) | `on_post_created` | `bn_post_created` to the author |
| `buddynext_space_member_joined` (3) | `on_space_joined` | `bn_space_joined` to the joiner |
| `buddynext_strike_issued` (3) | `on_strike_issued` | `bn_strike_issued` to the struck user |
| `buddynext_profile_completion_changed` (2) | `on_profile_completion_changed` | `bn_profile_updated` always; `bn_profile_completed` at 100% |
| `buddynext_post_reaction_received` (4) | `on_reaction_received` | `bn_reaction_received` to the post author (self-reactions excluded upstream) |
| `buddynext_comment_created` (variadic) | `on_comment_created` | `bn_comment_created` to the commenter (commenter is the last arg under both producer shapes) |

`fire()` is the single submission point:

```php
private function fire( string $action_id, int $user_id, array $context = array() ): void {
    if ( $user_id <= 0 || ! function_exists( 'wb_gam_submit_event' ) ) {
        return;
    }
    wb_gam_submit_event( $user_id, $action_id, $context );
}
```

> **Note:** An engine can also hook the raw `buddynext_*` producer actions directly (see `docs/specs/HOOKS.md`) instead of relying on the bridge submission path - for example `buddynext_post_created`, `buddynext_user_followed`, `buddynext_reaction_added`, `buddynext_space_member_joined`. The bridge path exists so admins get a configurable point catalogue out of the box.

## Session / streak / daily-login pulses

`BuddyNext\Engagement\SessionTracker` (registered on `wp_loaded:5`) fires two idempotent pulses. Both bail for guests and for AJAX, REST, cron, and WP-CLI contexts.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_user_session_started` | action | Sliding 30-minute window; re-fires after 30 min of inactivity | `int $user_id` |
| `buddynext_user_daily_login` | action | Once per UTC calendar day | `int $user_id`, `string $date_ymd` |

`buddynext_user_daily_login` is **the canonical streak driver** - increment streak counters here, not from activity. The idempotency transients are `bn_session_{user_id}` (30-min TTL) and `bn_daily_login_{user_id}_{Y-m-d}` (25-hour TTL).

## Recipient-perspective engagement events

Gamification usually awards the *recipient* of engagement, not the actor. BuddyNext always fires the actor-perspective events (`buddynext_user_followed`, `buddynext_reaction_added`, `buddynext_comment_created`); the recipient mirrors below fire alongside them only when the recipient differs from the actor.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_follower_gained` | action | A member gains a follower | `int $followee_id`, `int $follower_id` |
| `buddynext_post_reaction_received` | action | A post is reacted to (reactor != author) | `int $post_id`, `int $author_id`, `int $reactor_id`, `string $emoji` |
| `buddynext_post_comment_received` | action | A post is commented on (commenter != author) | `int $comment_id`, `int $post_id`, `int $author_id`, `int $commenter_id` |
| `buddynext_hashtag_used` | action | A native post uses a hashtag (once per tag) | `string $tag`, `int $post_id`, `int $user_id` |
| `buddynext_dm_sent` | action | A DM goes out (once per send) | `int $sender_id`, `int $message_id`, `int $conversation_id`, `int[] $recipient_ids` |
| `buddynext_dm_received` | action | A DM arrives (once per recipient) | `int $recipient_id`, `int $sender_id`, `int $message_id`, `int $conversation_id` |

`buddynext_dm_sent` / `buddynext_dm_received` are BN-domain adapters fired by `WPMediaVerseBridge` on top of WPMediaVerse's `mvs_message_sent`, so an engine can hook the BuddyNext namespace without depending on the messaging engine being present.

## Sidebar widget data seams

BuddyNext's right-sidebar widgets fall back to inline `COUNT(*)` queries from `bn_*` tables when no engine owns the data. An engine overrides each value by returning a non-null integer (or a date array) from the matching filter; return `null` to fall through to BuddyNext's own query. Hook with `add_filter( 'hook', 'fn', 10, 2 )` to receive `( $default, int $user_id )`.

```php
// Greeting + streak widget (parts/sidebar-greeting-streak.php).
apply_filters( 'buddynext_user_active_dates',               array|null $dates, int $user_id, int $window_days = 30 )
apply_filters( 'buddynext_user_activity_streak',            int $streak, int $user_id )
apply_filters( 'buddynext_user_activity_best_month_streak', int $best,   int $user_id )

// "This week" stats widget (parts/sidebar-this-week-stats.php).
apply_filters( 'buddynext_user_weekly_notifications_count',      int|null $count, int $user_id )
apply_filters( 'buddynext_user_weekly_notifications_prev_count', int|null $count, int $user_id )
apply_filters( 'buddynext_user_weekly_notifications_read_count', int|null $count, int $user_id )
apply_filters( 'buddynext_user_weekly_followers_gained',         int|null $count, int $user_id )
apply_filters( 'buddynext_user_weekly_engagement_received',      int|null $count, int $user_id )
```

To surface a gamification tile on the profile stat strip, append a row to `$args['stats']` via `buddynext_part_profile_stats_strip_args`:

```php
add_filter( 'buddynext_part_profile_stats_strip_args', function ( array $args ): array {
    $args['stats'][] = array(
        'slug'  => 'streak',
        'label' => __( 'Streak', 'buddynext' ),
        'value' => '14d',
        'delta' => '+3',
        'trend' => 'up',
    );
    return $args;
} );
```

There are also six per-surface user-overlay filters (`buddynext_member_card_meta_html`, `buddynext_post_byline_meta_html`, `buddynext_profile_hero_badges_html`, `buddynext_avatar_overlay_html`, `buddynext_search_member_meta_html`, `buddynext_comment_author_meta_html`) that let an engine inject **escaped** HTML (level frames, badge rows) beside member names and avatars. BuddyNext echoes the returned HTML raw at the call site, so the handler must escape.

## Inbound: engine events -> BuddyNext notifications + feed

`GamificationBridgeListener` consumes the engine's outbound signals (inbound to BuddyNext) and never submits an award, so it can never double-count alongside the bridge:

| Engine hook (args) | Listener handler | Result |
|---|---|---|
| `wb_gam_badge_awarded` (3: `int $user_id`, `array $def`, `string $badge_id`) | `on_badge_awarded` | `bn.badge_awarded` notification (reads `$def['name']`) |
| `wb_gam_level_changed` (3: `int $user_id`, `array $new_level`, `array|null $old_level`) | `on_level_changed` | `bn.level_up` notification (reads `id` / `name` / `min_points`) |

Separately, `GamificationBridge::on_badge_awarded_activity` also hooks `wb_gam_badge_awarded` and publishes a **feed activity** (social proof) - but only when `$def['is_credential']` is truthy, so small participation badges never spam the feed. It links to the engine's public badge share page (`gamification/badge/{id}/{uid}/share/`) and is idempotent per share URL.

## Read side: leaderboard template + endpoint

The leaderboard renders at `PageRouter::leaderboard_url()` (the activity base + `leaderboard/`, e.g. `/activity/leaderboard/`). The template is `templates/gamification/leaderboard.php`, dispatched by `PageRouter` when `bn_activity_action === 'leaderboard'`, which also enqueues the `gamification` asset bundle.

The template consumes **only** the engine read API - there is no BuddyNext-side SQL:

- `wb_gam_get_leaderboard( $api_period, 10 )` where `$api_period` maps the UI period (`week` / `month` / `alltime`) to the engine's `week` / `month` / `all`.
- `wb_gam_get_user_points( $current_user_id )` for the hero strip.
- `wb_gam_get_user_badges()` / `wb_gam_get_user_streak()` for the sidebar widgets.

When the engine is absent (`! function_exists( 'wb_gam_get_leaderboard' )`), the template renders a friendly "requires the gamification plugin" notice instead of an empty page. The current user's rank is resolved from the returned rows; outside the top 10 it shows "Unranked".

The member-facing profile surface is the **Achievements** tab (`GamificationAchievements`), registered on `buddynext_register_nav`. It renders the member's badge grid (credential badges first, capped at 24) plus a points/level/streak standing strip and a "View leaderboard" CTA, all read from `wb_gam_*`. It is data-gated - it appears only once the member has a badge or any points, so new members never see an empty tab. Achievements/badge-share/leaderboard URLs render outside BuddyNext's client-nav router region, so the tab adds them to `buddynext_client_nav_deny` to force a full page load.

## Examples

### Award points on a BuddyNext engagement event

An engine (or a site's custom code) can award the recipient of a reaction directly off the recipient-perspective event:

```php
// Award the post AUTHOR 2 points each time their post receives a reaction
// from someone else. buddynext_post_reaction_received fires only when the
// reactor differs from the author, so no self-award guard is needed.
add_action(
    'buddynext_post_reaction_received',
    function ( int $post_id, int $author_id, int $reactor_id, string $emoji ): void {
        if ( ! function_exists( 'wb_gam_submit_event' ) ) {
            return;
        }
        wb_gam_submit_event(
            $author_id,
            'bn_reaction_received',
            array(
                'post_id'    => $post_id,
                'reactor_id' => $reactor_id,
                'emoji'      => $emoji,
            )
        );
    },
    10,
    4
);
```

### Drive a streak counter from the daily-login pulse

```php
add_action(
    'buddynext_user_daily_login',
    function ( int $user_id, string $date_ymd ): void {
        // The pulse already fires at most once per UTC day per user, so this
        // is the correct place to advance a consecutive-day streak.
        my_engine_increment_streak( $user_id, $date_ymd );
    },
    10,
    2
);
```

## Notes / gotchas

- **No double-awarding.** The bridge owns all submission; the listener is inbound-only. Never submit an award from a `wb_gam_*` outbound handler.
- **Recipient vs actor.** Award off the recipient-perspective events (`buddynext_*_received`, `buddynext_follower_gained`) when you want to reward whose work was engaged with; the actor-perspective events reward the doer.
- **Idempotency is upstream.** The session/daily-login pulses and the badge-activity publisher are already deduped; do not add your own per-request guards that would suppress legitimate repeat awards on `repeatable` actions.
- **Escape overlay HTML.** The six `*_meta_html` / `*_badges_html` overlay filters echo your return value raw - return escaped markup.
- **Free/Pro.** The entire gamification seam (bridge, listener, Achievements tab, leaderboard) is in Free. It runs whenever the `gamification` feature toggle is on and the engine is active.
- **Source over manifest/conformance docs.** The conformance record references a `buddynext_profile_extra_data` profile injection; the live profile surface is the Achievements tab instead. When docs and code disagree, the code is authoritative.
