# Gamification Engine Seam

This is the contract a gamification engine implements to plug into BuddyNext. BuddyNext fires raw write-side actions, exposes recipient-perspective engagement signals and session/streak pulses, offers sidebar/profile data seams, and renders a leaderboard from the engine's public read API. BuddyNext ships **zero** gamification logic - no points, badge, level, or streak computation, and no own `wbg_*` tables. The reference engine is wb-gamification (`wb_gam_*` public API); any plugin that implements the same shape works. This page is for developers building or replacing that engine.

> **Status.** The write-side submission is owned by the engine's own BuddyNext manifest (in wb-gamification, that is `integrations/buddynext.php`), which binds BuddyNext's raw `buddynext_*` actions and awards points. The BuddyNext-side `GamificationBridge` does not submit events; its only producer role is posting a credential-badge feed activity on the member's explicit `wb_gam_badge_shared` (withdrawn on `wb_gam_badge_unshared`), never on award.

![The admin dashboard whose sidebar and leaderboard data the gamification-engine seam documented here feeds](../images/admin-overview.webp)

## Overview / Contract

The seam has four parts:

1. **Write-side events** - BuddyNext fires raw `buddynext_*` actions for every social action; the engine's own BuddyNext manifest binds them and awards points.
2. **Session / streak / daily-login pulses** - idempotent per-window signals that drive streak counters.
3. **Recipient-perspective engagement events** - mirrors that fire for the *recipient* of engagement (the person whose work was liked/commented/followed), which is who gamification usually awards.
4. **Read-side rendering** - the leaderboard template and the sidebar/profile data filters consume the engine's public read API only; BuddyNext never reads engine tables.

The BuddyNext-side `GamificationBridge` (`includes/Bridges/GamificationBridge.php`) is consume-only: it posts a credential-badge feed activity on the member's explicit `wb_gam_badge_shared` (never on award - see Inbound below) and withdraws it on `wb_gam_badge_unshared`. The write-side submissions are owned by the engine's own BuddyNext manifest. Its notifications come through the plugin's own contract (see Inbound below); the profile surface is `BuddyNext\Profile\GamificationAchievements`. These self-guard on the `wb_gam_*` API and are wired on `buddynext_load_bridges`; the surfaces they add follow the owner's Integration Settings toggles for `gamification`. The profile surface also includes the Points and Kudos tabs (`GamificationPoints`, `GamificationKudos`).

### Engine API BuddyNext calls

The engine functions below are all guarded with `function_exists`. The write-side calls live in the engine's own BuddyNext manifest (`wb-gamification/integrations/buddynext.php`); the read-side calls are made by BuddyNext's leaderboard template and Achievements tab. `GamificationBridge` does not call `wb_gam_submit_event`.

| Function | Used by | Purpose |
|---|---|---|
| `wb_gam_submit_event( int $user_id, string $action_id, array $context )` | engine manifest (`integrations/buddynext.php`) | Submit one award event through the full pipeline (points, badges, streaks, webhooks). |
| `wb_gam_register_action( array $args )` | engine manifest (`integrations/buddynext.php`) | Register a BuddyNext action so admins can configure its point value. |
| `wb_gam_get_actions()` | engine manifest (`integrations/buddynext.php`) | Dedup guard - skip already-registered slugs. |
| `wb_gam_get_leaderboard( string $period, int $limit )` | leaderboard template | Ranked rows (`rank`, `user_id`, `display_name`, `avatar_url`, `points`). |
| `wb_gam_get_leaderboard_page( string $period, int $limit, string $cursor )` | leaderboard template (signed-in viewers, 1.6.5+) | One keyset page: `rows` (absolute `rank`), `has_more`, `next_cursor`, `offset`, `total`, `invalid_cursor`. Forward-only; the template passes `?cursor=` through untouched and falls back to the first page on an invalid cursor. |
| `wb_gam_get_user_points( int $user_id )` | leaderboard + Achievements tab | Points balance. |
| `wb_gam_get_user_badges( int $user_id )` | leaderboard + Achievements tab | Earned badges. |
| `wb_gam_get_user_streak( int $user_id )` | leaderboard | Streak data. |

An engine replacing wb-gamification must provide functions of these names and shapes.

## Write-side events (the engine manifest)

The engine's own BuddyNext manifest (`wb-gamification/integrations/buddynext.php`) is declarative. Each entry in its `triggers` array names an action id, the BuddyNext hook to bind, a `user_callback` that resolves who is awarded, and a default point value; the engine auto-binds every hook and awards through its normal pipeline, so BuddyNext emits the action and the engine awards exactly once. BuddyNext contains no bridge code for this. The action ids are stable, so badge, challenge and rule configuration keyed on them survives engine upgrades.

| Action id | BuddyNext hook (args) | Default points | Recipient awarded |
|---|---|---|---|
| `bn_post_created` | `buddynext_post_created` (3) | 5 | the author |
| `bn_post_shared` | `buddynext_post_shared` (3) | 5 | the sharer |
| `bn_comment_created` | `buddynext_comment_created` (4) | 3 | the comment author |
| `bn_reaction_received` | `buddynext_post_reaction_received` (4) | 2 | the content owner |
| `bn_poll_voted` | `buddynext_poll_voted` (3) | 1 | the voter |
| `bn_post_bookmarked` | `buddynext_post_bookmarked` (2) | 1 | the bookmarking member |
| `bn_followed` | `buddynext_follower_gained` (2) | 5 | the followed user |
| `bn_first_follow` | `buddynext_user_followed_first_time` (2) | 5 | the follower (one-time) |
| `bn_connected` | `buddynext_connection_accepted` (3) | 10 | both connected peers |
| `bn_connection_requested` | `buddynext_connection_requested` (4) | 1 | the requester |
| `bn_dm_sent` | `buddynext_dm_sent` (4) | 1 | the sender |
| `bn_space_joined` | `buddynext_space_member_joined` (3) | 5 | the joining user |
| `bn_space_created` | `buddynext_space_created` (2) | 10 | the space owner |
| `bn_profile_updated` | `buddynext_profile_completion_changed` (2) | 2 | the member |
| `bn_profile_completed` | `buddynext_profile_strength_changed` (2) | 25 | the member (one-time at 100%) |
| `bn_onboarding_completed` | `buddynext_onboarding_completed` (1) | 20 | the member (one-time) |

An engine other than wb-gamification can hook the same raw `buddynext_*` producer actions directly and award however it likes. For a one-off award from your own code, submit through the engine's public function:

```php
// Contract shape of a single submission.
if ( $user_id > 0 && function_exists( 'wb_gam_submit_event' ) ) {
    wb_gam_submit_event( $user_id, $action_id, $context );
}
```

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

To surface a gamification tile on the profile stat strip, append a row to `$args['stats']` via `buddynext_part_stat_strip_args`:

```php
add_filter( 'buddynext_part_stat_strip_args', function ( array $args ): array {
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

**Wrap multi-chip returns in `.bn-badge-row`.** When one of these filters returns more than one `.bn-badge` chip (Moderator + Verified + Expert, a level frame plus a badge), wrap the chips in a single `.bn-badge-row` element - a shared, token-driven, RTL-safe flex-row primitive defined in `bn-base.css` (`gap: var(--bn-s1)`) - so adjacent chips are evenly spaced and wrap cleanly on narrow viewports:

```php
return '<span class="bn-badge-row">'
    . '<span class="bn-badge" data-tone="accent">' . esc_html( $label_a ) . '</span>'
    . '<span class="bn-badge" data-tone="info">'   . esc_html( $label_b ) . '</span>'
    . '</span>';
```

As a safety net, Free also backstops the known overlay containers that hold only injected chips (`.bn-post-card__author`, `.bn-md-card__meta-overlay`, `.bn-md-card__labels`, `.bn-comment__author-meta`) with an adjacent-chip `margin-inline-start`, so chips never collide even if an engine forgets the wrapper. The two surfaces whose containers already gap their own static badges (`.bn-pf-name-row`, `.bn-search-row__title`) are intentionally left off that backstop to avoid double-spacing - so the `.bn-badge-row` wrapper is the only path that spaces chips consistently on **every** surface, and is the recommended one.

## Inbound: engine events -> BuddyNext notifications + feed

WB Gamification sends its member notifications through the community notification contract (payload as the last argument of `wb_gam_notification_created`, types declared on `wb_gam_community_notification_types`), so BuddyNext has no listener that turns engine events into notifications. Eight types reach the bell as `wb_gamification.<type>`: `badge_awarded`, `level_up`, `kudos_received`, `challenge_completed`, `reward_fulfilled`, `credential_expired`, `personal_record` and `streak_milestone`. The plugin writes each sentence, decides visibility, and removes a row when its kudos is revoked or its badge is deleted. A personal record is one quiet row per period that refreshes its number without alerting again (`renotify => false`).

Every one of these types is collect-only (`can_email => false`): WB Gamification sends its own emails, so BuddyNext only shows them in the inbox, and the digest leaves them out. Members can switch each off under notification preferences.

The plugin links each row to the member's profile front page. BuddyNext owns the profile tabs, so `GamificationBridge::filter_notification_url()` (on `buddynext_notification_url`) opens Achievements for badges, level-ups, challenges, expired credentials and streaks, Kudos for kudos, and Points for personal records; a reward keeps the plugin's own hub link.

Points amounts BuddyNext prints go through `GamificationBridge::format_points()` (WB Gamification's `wb_gam_format_points()`: "1 Point", "250 Karma", "+10 Points"); a tile that prints the number and the name apart uses `GamificationBridge::points_unit( $amount )`. Level progress on the leaderboard follows points earned (`wb_gam_get_earned_points()`), not the spendable balance, so redeeming a reward never moves the bar backwards.

Separately, `GamificationBridge` publishes the **feed activity** (social proof) off a different pair of hooks: `on_badge_shared_activity` on `wb_gam_badge_shared( int $user_id, string $badge_id )`, and `on_badge_unshared_activity` on `wb_gam_badge_unshared( int $user_id, string $badge_id )`. The card is gated to `$def['is_credential']` truthy (so small participation badges never spam the feed) and fires only on the member's explicit Share press, never on award - wb-gamification 1.6.4 made badges private until shared, and broadcasting on award would publish a credential before the member consented. It links to the engine's public badge share page (`gamification/badge/{id}/{uid}/share/`) and is idempotent per share URL: a re-share after an unshare RESTORES the same card (id, date, reactions, comments) via `IntegrationActivity::restore()` rather than minting a new one; an unshare WITHDRAWS it to `draft` via `IntegrationActivity::withdraw()` rather than deleting it. When a badge definition is deleted, `wb_gam_badge_deleted( string $badge_id, int[] $user_ids, array $def )` removes every holder's shared-badge card (`GamificationBridge::on_badge_deleted`) and their "badge earned" / "credential expired" inbox rows (`GamificationBridgeListener::on_badge_deleted`, through `NotificationService::delete_for_data()`).

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

- **No double-awarding.** The engine manifest owns award submission for the actions in the table above; do not also submit the same action from your own handler.
- **Recipient vs actor.** Award off the recipient-perspective events (`buddynext_*_received`, `buddynext_follower_gained`) when you want to reward whose work was engaged with; the actor-perspective events reward the doer.
- **Idempotency is upstream.** The session/daily-login pulses and the badge-activity publisher are already deduped; do not add your own per-request guards that would suppress legitimate repeat awards on `repeatable` actions.
- **Escape overlay HTML.** The six `*_meta_html` / `*_badges_html` overlay filters echo your return value raw - return escaped markup.
- **Wrap chip rows in `.bn-badge-row`.** When an overlay filter returns more than one `.bn-badge`, wrap them in `.bn-badge-row` (shared primitive in `bn-base.css`) so they space and wrap correctly on every surface. Free backstops the known single-purpose overlay containers, but the wrapper is the recommended and universally-consistent path.
- **Free/Pro.** The entire gamification seam (bridge, listener, Achievements tab, leaderboard) is in Free. It runs whenever the engine is active, and each surface follows the owner's Integration Settings toggle for gamification.
- **Profile surface.** Profile gamification is rendered by the Achievements, Points and Kudos tabs, not by a `buddynext_profile_extra_data` injection.
