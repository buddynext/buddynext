# Journey: Gamification Bridge (opt-in)

**Bridge**: `includes/Bridges/GamificationBridge.php` + `includes/Bridges/GamificationBridgeListener.php` + `includes/Profile/GamificationAchievements.php`
**Partner plugin**: [wb-gamification](https://github.com/vapvarun/wb-gamification) — points / badges / levels / streaks / leaderboards engine. **This journey requires wb-gamification active.** BuddyNext ships zero gamification logic and **awards nothing** — the bridge is **consumer-only**. The producer/submit side (action catalogue, `register_actions()`, `wb_gam_submit_event()` calls, the `on_user_followed` / `on_post_created` award handlers) has been **retired**; hook auto-binding and point awards are now owned entirely by the wb-gamification manifest at `integrations/buddynext.php` inside the partner plugin.
**Partner actions consumed (engine → BN)**: `wb_gam_badge_awarded` (3 args), `wb_gam_level_changed` (3 args)
**BN-owned hooks consumed (Achievements tab)**: `buddynext_register_nav`, `buddynext_part_profile_tab_panel_after`, `wp_enqueue_scripts`, filter `buddynext_client_nav_deny`
**Hooks fired by the bridge**: none. The feed card is a side effect of `IntegrationActivity::publish()` → `PostService::create()` (which fires the normal `buddynext_post_created`); the bridge itself does not `do_action()`.
**Partner read functions called**: `wb_gam_get_user_badges()`, `wb_gam_get_user_points()`, `wb_gam_get_user_level()`, `wb_gam_get_user_streak()`; classes `\WBGam\Engine\LeaderboardEngine::get_user_rank()`, `\WBGam\Engine\BadgeSharePage::get_share_url()`; option `wb_gam_hub_page_id`
**Partner functions used only as guards/detectors**: `wb_gam_submit_event()` (`function_exists`), `wb_gam_get_user_badges()` (`function_exists`)
**Partner DB tables observed**: none directly — BuddyNext reads standing through the `wb_gam_*` functions/engine, never raw SQL on partner tables
**BN tables touched**: writes `wp_bn_posts` (feed card via `IntegrationActivity`), `wp_bn_notifications` (badge/level notifications)
**Estimated time**: 10 min manual

## Site-owner expectation

A community owner activates **wb-gamification** next to BuddyNext and expects it to be plug-and-play. The partner engine owns 100% of the points/badges/levels/streaks math and decides when members earn things — BuddyNext does **not** award or submit anything. What BuddyNext adds is three **read/celebrate** surfaces so earned standing is visible inside the community:

- When a member earns a **credential badge**, a **feed card** announces it ("earned the *X* badge") so the community can celebrate it — social proof.
- Any badge award or level-up raises a **BuddyNext bell notification** for that member, delivered through BuddyNext's own notification/email preferences.
- A member's standing (points / rank / level / streak + earned badges) appears on a dedicated **Achievements profile tab**, with each badge linking to its public share page and a "View leaderboard" CTA.

With the partner plugin **off**, BuddyNext behaves exactly as before — no Achievements tab, no badge feed cards, no gamification notifications, no fatals. The bridge is inert.

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site).
- **wb-gamification active** (the partner plugin). Confirm:
  ```bash
  wp plugin is-active wb-gamification && echo "partner ON"
  wp eval 'echo function_exists("wb_gam_submit_event") ? "engine present" : "MISSING";'
  ```
  Both must be true, or every step below is a no-op (see Edge case 1). `wb_gam_submit_event` is only used here as the **detector** the BN feature gate keys on (`FeatureRegistry::presence_met( 'gamification' )`, `FeatureRegistry.php:292`); BuddyNext never calls it.
- The **Gamification** feature must be ON (Settings → Platform → Features). It is default-on and resolves on whether the partner is present.
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it).
- Member users: `member1` / `password`, `member2` / `password`.
- Capture the member IDs once for the SQL checks:
  ```bash
  wp user get member1 --field=ID   # → MEMBER1_ID
  wp user get member2 --field=ID   # → MEMBER2_ID
  ```

## Happy-path steps

The bridge loads on `buddynext_load_bridges` (`Plugin.php:442-447`), gated by `buddynext_feature_enabled( 'gamification' )`. When on, three objects wire up:

- `GamificationBridge::init()` (`GamificationBridge.php:32`) binds **one** hook: `wb_gam_badge_awarded` → `on_badge_awarded_activity` (feed card).
- `GamificationBridgeListener::register()` (`GamificationBridgeListener.php:27`) — self-guards on `function_exists( 'wb_gam_submit_event' )` — binds `wb_gam_badge_awarded` → `on_badge_awarded` and `wb_gam_level_changed` → `on_level_changed` (notifications).
- `GamificationAchievements::register()` (`GamificationAchievements.php:36`) — self-guards on `function_exists( 'wb_gam_get_user_badges' )` — registers the Achievements profile tab via the Nav API.

There is **no BN action that triggers an award** anymore. The journey is driven by triggering a real partner event (badge / level) and asserting the three BN consumer surfaces.

### Part 1: Award a badge in wb-gamification → feed card + notification

1. Trigger a **badge award** for `member2` in the partner engine. Use the partner's Manual Award admin action, or fire the real partner hook directly so the exact 1.0.3 signature is exercised (`do_action( 'wb_gam_badge_awarded', int $user_id, array $def, string $badge_id )` — verified against `wb-gamification/.../BadgeEngine.php:296`):

   ```bash
   # Credential badge — exercises BOTH the feed card AND the notification:
   wp eval 'do_action( "wb_gam_badge_awarded", MEMBER2_ID, array( "name" => "Top Contributor", "is_credential" => true ), "top-contributor" );'
   ```

2. Confirm the **feed card** the consumer bridge created (`GamificationBridge::on_badge_awarded_activity`, `GamificationBridge.php:58`). It only fires for **credential** badges (`$def['is_credential']`), and is idempotent per share URL via `IntegrationActivity`:

   ```sql
   SELECT id, user_id, type, content, link_url
   FROM wp_bn_posts
   WHERE user_id = MEMBER2_ID
     AND link_url = 'http://buddynext-dev.local/gamification/badge/top-contributor/MEMBER2_ID/share/'
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row. `content = "earned the Top Contributor badge"`, `type = link` (the `IntegrationActivity::publish()` default), `link_url` = the bridge's hand-built share URL `home_url( 'gamification/badge/{badge_id}/{user_id}/share/' )` (`GamificationBridge.php:88`). Re-running step 1 with the same badge does **not** add a second row (idempotent on `link_url`, `IntegrationActivity.php:56`).

3. Confirm the **notification** the listener created (`GamificationBridgeListener::on_badge_awarded`, `GamificationBridgeListener.php:57`) — this fires for **every** badge, credential or not:

   ```sql
   SELECT recipient_id, sender_id, type, object_type, data
   FROM wp_bn_notifications
   WHERE type = 'bn.badge_awarded' AND recipient_id = MEMBER2_ID
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row. `sender_id` null (system notification), `object_type = badge`, `data` JSON carrying `badge` / `badge_id` / `badge_name`. The `data['badge']` key is what `NotificationMessageService::resolve_message()` renders as "You earned a new badge: Top Contributor." (`NotificationMessageService.php:326`).

### Part 2: A participation (non-credential) badge → notification only, no feed card

4. Award a **non-credential** badge to `member2`:

   ```bash
   wp eval 'do_action( "wb_gam_badge_awarded", MEMBER2_ID, array( "name" => "First Steps", "is_credential" => false ), "first-steps" );'
   ```

5. Confirm a notification exists but **no** feed card was created:

   ```sql
   -- Notification present (listener fires for all badges):
   SELECT type, data FROM wp_bn_notifications
   WHERE type = 'bn.badge_awarded' AND recipient_id = MEMBER2_ID
   ORDER BY id DESC LIMIT 1;

   -- No feed card (the feed bridge gates on is_credential):
   SELECT COUNT(*) AS should_be_zero FROM wp_bn_posts
   WHERE user_id = MEMBER2_ID
     AND link_url = 'http://buddynext-dev.local/gamification/badge/first-steps/MEMBER2_ID/share/';
   ```

   - Expected: a `bn.badge_awarded` notification for "First Steps"; `should_be_zero = 0`. This is the load-bearing distinction — tiny participation badges notify privately but never spam the public feed.

### Part 3: Level up → BN notification

6. Trigger a **level change** for `member2` (`do_action( 'wb_gam_level_changed', int $user_id, array $new_level, array|null $old_level )` — verified against `wb-gamification/.../LevelEngine.php:146`):

   ```bash
   wp eval 'do_action( "wb_gam_level_changed", MEMBER2_ID, array( "id" => 3, "name" => "Gold", "min_points" => 500 ), array( "id" => 2, "name" => "Silver", "min_points" => 200 ) );'
   ```

7. Confirm the level-up notification (`GamificationBridgeListener::on_level_changed`, `GamificationBridgeListener.php:101`):

   ```sql
   SELECT recipient_id, sender_id, type, object_type, object_id, data
   FROM wp_bn_notifications
   WHERE type = 'bn.level_up' AND recipient_id = MEMBER2_ID
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row. `object_type = level`, `object_id = 3` (the new level id), `data` carrying `level` / `level_id` / `level_name` / `min_points` / `old_level_*`. `data['level']` (the numeric id) is what `NotificationMessageService::resolve_message()` renders as "You reached level 3." (`NotificationMessageService.php:337`). There is **no** feed card for level changes — level-ups are notification-only.

### Part 4: Achievements profile tab surfaces standing + badges

8. Read `member2`'s standing through the same partner functions the tab uses:

   ```bash
   wp eval 'printf("points=%d level=%s badges=%d\n", (int) wb_gam_get_user_points(MEMBER2_ID), ( function(){ $l = wb_gam_get_user_level(MEMBER2_ID); return is_array($l) && !empty($l["name"]) ? $l["name"] : "none"; } )(), count( (array) wb_gam_get_user_badges(MEMBER2_ID) ));'
   ```

   - Expected: `points` ≥ 0, `level` and `badges` per partner state. The tab condition `has_standing()` (`GamificationAchievements.php:166`) shows the tab only when the member has **any badge OR points > 0**, so a brand-new member never sees an empty Achievements tab.

9. Visit `member2`'s profile in the browser: `http://buddynext-dev.local/members/member2/?autologin=1`. Confirm:

   - An **Achievements** tab is present in the profile nav (registered on `buddynext_register_nav` via `NavRegistry`, id `achievements`, icon `award`, priority 70 — `GamificationAchievements.php:114`). Its count badge equals the number of earned badges.
   - Opening the tab reveals a **standing strip** (Points / Rank / Level / Day streak — only the tiles the partner returns a value for) and a **badge grid** (credential badges first, capped at 24), with a **View leaderboard** CTA linking to the page in option `wb_gam_hub_page_id`.
   - Each badge links to its share page via `\WBGam\Engine\BadgeSharePage::get_share_url()` (`GamificationAchievements.php:375`), falling back to the hand-built `gamification/badge/{id}/{uid}/share/` only on an older partner that predates the helper.
   - **Rank** is read from `\WBGam\Engine\LeaderboardEngine::get_user_rank( $member_id, 'all' )` (`GamificationAchievements.php:341`), guarded so it no-ops when the engine class is absent.

## Edge cases to also verify

- **Bridge inert when partner inactive (no fatals).** Deactivate the partner and load BN surfaces:
  ```bash
  wp plugin deactivate wb-gamification
  # load http://buddynext-dev.local/members/member2/?autologin=1
  wp eval 'echo function_exists("wb_gam_submit_event") ? "present" : "absent";'
  ```
  Expected: prints `absent`; the profile renders with **no Achievements tab**; no badge feed cards or gamification notifications can be produced; **no PHP fatal/notice**. With the partner gone, `presence_met( 'gamification' )` is false so `is_enabled()` forces the feature OFF (`FeatureRegistry.php:285`) and none of the three objects are constructed; each object also self-guards at hook time. Re-activate afterward (`wp plugin activate wb-gamification`).

- **Feature toggle OFF with partner present.** Turn the **Gamification** feature off in Settings → Platform → Features while wb-gamification stays active. Expected: the bridge/listener/tab are never wired (`Plugin.php:442` short-circuits), so no feed cards, no notifications, and the Achievements tab disappears — even though the partner keeps awarding internally.

- **Credential vs participation gate.** Re-confirm Part 2: a credential badge produces a feed card **and** a notification; a non-credential badge produces a notification **only**. This is the single most error-prone branch (`GamificationBridge.php:59` `empty( $def['is_credential'] )`).

- **Feed card is idempotent.** Re-fire the same credential badge twice (repeat step 1). Expected: still exactly one `wp_bn_posts` row for that share URL — `IntegrationActivity::publish()` bails via `exists_by_link()` on the second fire.

- **Empty badge name is skipped.** Fire `wb_gam_badge_awarded` with `name => ''`. Expected: no feed card (`GamificationBridge.php:64` returns on empty name). The notification listener still records the award (it tolerates an empty name).

- **Achievements tab hidden for zero-standing members.** A member with no badges and 0 points must **not** see the tab (`has_standing()` returns false). Verify on a fresh member.

## What this validates

This journey validates the **BuddyNext-side consumer seams**, not the partner's points/badge math:

- **Feed card (social proof)**: `wb_gam_badge_awarded` → `GamificationBridge::on_badge_awarded_activity` publishes one idempotent `wp_bn_posts` link card **only for credential badges**, linking to the badge share page.
- **Notifications**: `wb_gam_badge_awarded` → `on_badge_awarded` (`bn.badge_awarded`) and `wb_gam_level_changed` → `on_level_changed` (`bn.level_up`) write system notification rows for **every** badge / level change. The listener only writes notifications — it never submits an award, so it can never double-count.
- **Achievements tab**: registered on the BN Nav API (`buddynext_register_nav`), data-gated by `has_standing()`, rendering the standing strip + badge grid read purely through `wb_gam_*` functions and the WBGam engine classes (read-only).
- **Inert-when-absent**: with the partner off (or the feature toggled off) none of the three objects wire up; the feature gate (`presence_met`) and each object's own `function_exists`/`is_callable` guards make absence a clean no-op.
- **Retired producer side stays gone**: BuddyNext registers **no** action catalogue, calls **no** `wb_gam_submit_event()`, and injects **no** `buddynext_profile_extra_data` stat tiles. Awarding is 100% the partner's job via its own `integrations/buddynext.php` manifest.

## Verification queries

```sql
-- Credential-badge feed cards created by the consumer bridge:
SELECT id, user_id, type, content, link_url, created_at
FROM wp_bn_posts
WHERE link_url LIKE '%/gamification/badge/%/share/'
ORDER BY id DESC LIMIT 25;

-- Badge / level notifications the listener created from partner events:
SELECT recipient_id, type, object_type, object_id, data, created_at
FROM wp_bn_notifications
WHERE type IN ('bn.badge_awarded', 'bn.level_up')
ORDER BY id DESC LIMIT 25;
```

```bash
-- Standing as the Achievements tab reads it (partner-owned values):
wp eval 'printf("points=%d badges=%d rank=%s\n",
  (int) wb_gam_get_user_points(MEMBER2_ID),
  count( (array) wb_gam_get_user_badges(MEMBER2_ID) ),
  ( is_callable( array( "\\WBGam\\Engine\\LeaderboardEngine", "get_user_rank" ) )
    ? (string) ( \WBGam\Engine\LeaderboardEngine::get_user_rank(MEMBER2_ID, "all")["rank"] ?? "n/a" )
    : "n/a" ) );'
```

## REST surface walked

The bridge exposes **no REST endpoints of its own**, and — unlike the old producer model — it is **not** triggered by BuddyNext REST endpoints either. The two write surfaces (feed card, notifications) are driven entirely by the **partner's** `wb_gam_badge_awarded` / `wb_gam_level_changed` actions, exercised here via the partner admin or `wp eval`.

The resulting BN rows are observable through the standard BuddyNext REST surfaces:

```
GET /buddynext/v1/notifications        -- the bn.badge_awarded / bn.level_up rows surface here
GET /buddynext/v1/posts                -- the credential-badge feed card surfaces in the feed
```

> Member standing (points / level / badges) is **not** in the BuddyNext REST member payload — it lives on the rendered **Achievements profile tab** only, and in the partner's own `wb_gam_*` REST/blocks.

## Bridge contract & partner gate

*(Item 11, bridge form. A bridge has no buttons — its "wiring" is the cross-plugin hook contract. The load-bearing checks are the partner guard and exact hook arg-counts.)*

| Direction | Hook (arg count) | Handler | Effect | Guard |
|---|---|---|---|---|
| gamification → BN (feed) | `wb_gam_badge_awarded( int $user_id, array $def, string $badge_id )` (3) | `GamificationBridge::on_badge_awarded_activity` (`:58`) | feed link card for **credential** badges only | Feature gate `buddynext_feature_enabled('gamification')` → `presence_met` → `function_exists('wb_gam_submit_event')` (`Plugin.php:442`, `FeatureRegistry.php:292`) |
| gamification → BN (notify) | `wb_gam_badge_awarded` (3) · `wb_gam_level_changed( int $user_id, array $new, array\|null $old )` (3) | `GamificationBridgeListener::on_badge_awarded` (`:57`) · `on_level_changed` (`:101`) | `bn.badge_awarded` / `bn.level_up` notification rows | `register()` bails if `! function_exists('wb_gam_submit_event')` (`GamificationBridgeListener.php:28`) |
| BN-owned (Achievements tab) | `buddynext_register_nav`, `buddynext_part_profile_tab_panel_after`, filter `buddynext_client_nav_deny` | `GamificationAchievements::register_nav` / `render_panel` / `add_nav_deny` (`:40-43`) | profile tab: standing strip + badge grid | `register()` bails if `! function_exists('wb_gam_get_user_badges')` (`GamificationAchievements.php:37`); tab also data-gated by `has_standing()` |

**Verify this run (partner active — `wb-gamification` IS active here):**
1. Award a **credential** badge in the partner → confirm a BN feed card **and** a `bn.badge_awarded` notification appear.
2. Award a **non-credential** badge → confirm a notification but **no** feed card.
3. Trigger a **level change** → confirm a `bn.level_up` notification (no feed card).
4. **Graceful no-op:** deactivate `wb-gamification`, reload a profile → no Achievements tab, no fatal (the guards mean nothing registered). Reactivate.

## Admin-config → member-effect

*(Item 12.)*

- **Gamification feature toggle** (Settings → Platform → Features → "Gamification"): OFF → none of the three consumer surfaces wire up (no feed cards, no gamification notifications, no Achievements tab) even with the partner active; ON → all three resume. The toggle renders as unavailable when the partner is absent (`presence_met` false).
- **Partner presence:** with `wb-gamification` deactivated, the Achievements tab and all gamification surfaces must render nothing (not error) — the degrade path.
- **wb-gamification hub page** (partner option `wb_gam_hub_page_id`): set it to a published page → the Achievements tab's "View leaderboard" CTA appears and links there; unset/unpublished → the CTA is hidden (`GamificationAchievements.php:354`).

## Cleanup

```sql
-- Remove the feed cards this journey created (BN-owned):
DELETE FROM wp_bn_posts
WHERE link_url LIKE '%/gamification/badge/%/share/'
  AND user_id IN (MEMBER1_ID, MEMBER2_ID);

-- Remove the gamification notifications this journey created (BN-owned):
DELETE FROM wp_bn_notifications
WHERE type IN ('bn.badge_awarded', 'bn.level_up')
  AND recipient_id IN (MEMBER1_ID, MEMBER2_ID);
```

> The badge / level / points state itself is **partner-owned** (`wb_gam_*` tables). This journey never writes to those tables, so there is nothing partner-side to clean up beyond whatever the Manual Award action created — undo that through the wb-gamification admin (badge revocation / recount), not manual SQL on a shared instance.

## Known limitations

- **BuddyNext awards nothing — the bridge is consumer-only.** Points, badges, levels, streaks, leaderboards, and the rules that grant them are 100% the partner's domain. A missing badge or zero points is a wb-gamification configuration matter, not a BN bug. The retired producer wiring (action catalogue, `wb_gam_submit_event()` calls, `on_user_followed`/`on_post_created` handlers) is intentionally gone — do not reintroduce it; the partner's `integrations/buddynext.php` manifest now owns hook auto-binding and awards.
- **Feed card is credential-only; notifications are all-badge.** Only badges flagged `is_credential` produce a public feed card; every badge (and every level change) produces a private notification. This asymmetry is by design (avoid feed spam from participation badges).
- **Standing is template-only, not in BN REST.** Points / level / badges / rank appear on the Achievements profile tab; they are **not** part of the BuddyNext REST member payload. REST-only consumers read standing from the partner's own `wb_gam_*` surfaces.
- **Achievements tab is data-gated.** It only appears once a member has at least one badge or any points (`has_standing()`), so zero-standing members never see an empty tab.
- **Rank reads the engine directly.** Leaderboard rank has no `wb_gam_*` wrapper, so the tab calls `\WBGam\Engine\LeaderboardEngine::get_user_rank()` directly (guarded). If the partner renames/removes that class, the Rank tile silently disappears rather than fataling.
- **Share-URL builders differ slightly between the two surfaces.** The feed bridge always hand-builds `gamification/badge/{id}/{uid}/share/` (`GamificationBridge.php:88`); the Achievements tab prefers `\WBGam\Engine\BadgeSharePage::get_share_url()` with that hand-built path only as a legacy fallback. On a current wb-gamification both resolve to the same canonical share page.

## Automation notes

- Gate the whole script on `wp plugin is-active wb-gamification` (and `function_exists('wb_gam_submit_event')`). Skip — do not fail — when the partner is absent: the bridge is *designed* to be inert, so "partner off → no surfaces" is a passing state, asserted by the inactive edge case.
- Drive the journey by firing the **partner** hooks (`wb_gam_badge_awarded` / `wb_gam_level_changed`) via the partner admin or `wp eval` — there is no BN REST endpoint that triggers an award anymore.
- Collect `MEMBER1_ID` / `MEMBER2_ID` from setup; never hardcode. Substitute the resolved IDs into the `link_url` strings used in the SQL assertions.
- The highest-value assertions are the **credential-vs-participation** branch (feed card present only for credential badges) and the **idempotency** of the feed card — both are pure SQL count assertions and guard the most error-prone code in the bridge.
- The Achievements tab (Part 4) is best verified in the browser (tab presence + standing strip + badge grid). A headless check can still assert the read functions return the expected standing, but the Nav registration + reactive panel reveal need a rendered profile.
