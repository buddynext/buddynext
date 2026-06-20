# Journey: Gamification Bridge (opt-in)

**Bridge**: `includes/Bridges/GamificationBridge.php` + `includes/Bridges/GamificationBridgeListener.php`
**Partner plugin**: [wb-gamification](https://github.com/vapvarun/wb-gamification) — points / badges / levels / streaks / leaderboards engine. **This journey requires wb-gamification active.** BuddyNext ships zero gamification logic; it only registers an action catalogue and emits events.
**BN actions consumed (producer → bridge)**: `buddynext_user_followed`, `buddynext_connection_accepted`, `buddynext_post_created`, `buddynext_space_member_joined`, `buddynext_strike_issued`, `buddynext_profile_completion_changed`, `buddynext_post_reaction_received`, `buddynext_comment_created`
**Partner functions called**: `wb_gam_register_action()`, `wb_gam_get_actions()`, `wb_gam_submit_event()`, `wb_gam_get_user_points()`, `wb_gam_get_user_level()`, `wb_gam_get_user_badges()`
**Partner actions listened to (inbound, Listener)**: `wb_gam_badge_awarded`, `wb_gam_level_changed`
**Filter used**: `buddynext_profile_extra_data` (BN exposes partner Points / Level / Badges as profile stat tiles)
**Partner DB tables observed**: `wp_wb_gam_events`, `wp_wb_gam_points`, `wp_wb_gam_user_badges`, `wp_wb_gam_user_totals` (all partner-owned — read only)
**Estimated time**: 14 min manual

## Site-owner expectation

A community owner activates **wb-gamification** next to BuddyNext and expects it to be plug-and-play: members start earning points and badges for normal community activity — following each other, posting, reacting, commenting, joining spaces, completing their profile — **without any wiring**. The owner configures point values per action in the wb-gamification admin; BuddyNext supplies the action catalogue (`bn_followed`, `bn_post_created`, …) automatically so those rows are already present to configure. The Points / Level / Badge tiles then appear on member profiles, and badge / level-up events flow back into the BuddyNext notification stream. With the partner plugin **off**, BuddyNext behaves exactly as before — no points UI, no fatals, no broken hooks.

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site).
- **wb-gamification active** (the partner plugin). Confirm:
  ```bash
  wp plugin is-active wb-gamification && echo "partner ON"
  wp eval 'echo function_exists("wb_gam_submit_event") ? "submit API present" : "MISSING";'
  ```
  Both must be true, or every step below is a no-op (see Edge case 1).
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it).
- Member users: `member1` / `password`, `member2` / `password`.
- Capture the member IDs once for the SQL checks:
  ```bash
  wp user get member1 --field=ID   # → MEMBER1_ID
  wp user get member2 --field=ID   # → MEMBER2_ID
  ```

## Happy-path steps

The bridge loads on `buddynext_load_bridges` (Plugin.php:300/306). `GamificationBridge::init()` registers the catalogue and binds the producer hooks; `GamificationBridgeListener::register()` binds the two inbound partner hooks. Both bail immediately if `wb_gam_submit_event()` is absent.

### Part 1: Confirm the action catalogue is registered

1. Verify the bridge registered the BuddyNext action catalogue with the partner engine:

   ```bash
   wp eval '$a = wb_gam_get_actions(); foreach (["bn_followed","bn_connected","bn_post_created","bn_space_joined","bn_strike_issued","bn_profile_updated","bn_profile_completed","bn_reaction_received","bn_comment_created"] as $id) { echo $id . ": " . ( isset($a[$id]) ? "registered" : "MISSING" ) . "\n"; }'
   ```

   - Expected: all 9 slugs print `registered`. These are the rows a site owner sees as configurable point actions in the wb-gamification admin. The catalogue is registered against an inert `buddynext_gamification_noop` hook (NOOP_HOOK) so the engine never auto-awards — the bridge submits each event manually exactly once.

### Part 2: Follow → bn_followed (recipient earns points)

2. As `member1`, follow `member2` (this fires `buddynext_user_followed`):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/members/MEMBER2_ID/follow \
     -u member1:password -H "Content-Type: application/json"
   ```

   - Expected: 200/201. Bridge `on_user_followed()` fires `wb_gam_submit_event( MEMBER2_ID, 'bn_followed', ['follower_id'=>MEMBER1_ID] )`. The **followed** user (member2) receives the award, not the follower.

3. Confirm the partner engine recorded the event for member2:

   ```sql
   SELECT user_id, action_id, created_at
   FROM wp_wb_gam_events
   WHERE user_id = MEMBER2_ID AND action_id = 'bn_followed'
   ORDER BY created_at DESC LIMIT 1;
   ```

   - Expected: 1 row, `action_id = bn_followed`. A matching row also lands in `wp_wb_gam_points` if the admin configured a non-zero point value (default 5).

### Part 3: Post created → bn_post_created (author earns points)

4. As `member2`, create a post (fires `buddynext_post_created`):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member2:password -H "Content-Type: application/json" \
     -d '{"content":"Gamification journey post"}'
   ```

   - Expected: 201. Note the returned post `id` (`POST_ID`). Bridge `on_post_created()` fires `bn_post_created` for member2.

5. Confirm the event:

   ```sql
   SELECT user_id, action_id, object_id
   FROM wp_wb_gam_events
   WHERE user_id = MEMBER2_ID AND action_id = 'bn_post_created'
   ORDER BY created_at DESC LIMIT 1;
   ```

   - Expected: 1 row. (`object_id` is the post id when the partner extracts it from `meta['object_id']`; the bridge passes `post_id` in context.)

### Part 4: Reaction received → bn_reaction_received (content owner earns points)

6. As `member1`, react to member2's post (fires `buddynext_post_reaction_received` only because reactor ≠ author — ReactionService.php:105):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts/POST_ID/reactions \
     -u member1:password -H "Content-Type: application/json" \
     -d '{"emoji":"like"}'
   ```

   - Expected: 200/201. Bridge `on_reaction_received()` awards the **author** (member2), not the reactor — matching "reaction received on your content" semantics. No self-award guard needed; BN already excluded self-reactions before firing.

7. Confirm the event credited member2 (the author):

   ```sql
   SELECT user_id, action_id
   FROM wp_wb_gam_events
   WHERE user_id = MEMBER2_ID AND action_id = 'bn_reaction_received'
   ORDER BY created_at DESC LIMIT 1;
   ```

   - Expected: 1 row for member2.

### Part 5: Comment created → bn_comment_created (commenter earns points)

8. As `member1`, comment on member2's post (fires `buddynext_comment_created`):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts/POST_ID/comments \
     -u member1:password -H "Content-Type: application/json" \
     -d '{"content":"Nice post"}'
   ```

   - Expected: 201. Bridge `on_comment_created()` awards the **comment author** (member1). The handler reads the commenting user as the *last* argument from `func_get_args()`, tolerating both the canonical 4-arg producer (CommentService.php:134) and the legacy 3-arg producer (WPMediaVerseBridge.php:522).

9. Confirm member1 earned the comment event:

   ```sql
   SELECT user_id, action_id
   FROM wp_wb_gam_events
   WHERE user_id = MEMBER1_ID AND action_id = 'bn_comment_created'
   ORDER BY created_at DESC LIMIT 1;
   ```

   - Expected: 1 row for member1.

### Part 6: Space join → bn_space_joined

10. Create an open space as `member1`, then have `member2` join it (fires `buddynext_space_member_joined`). Reuse the spaces journey to create the space, or:

    ```bash
    SPACE_ID=$(curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces \
      -u member1:password -H "Content-Type: application/json" \
      -d '{"name":"Gam Journey Space","slug":"gam-journey-space","type":"open"}' | wp eval 'echo json_decode(file_get_contents("php://stdin"))->id;')
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/$SPACE_ID/join \
      -u member2:password -H "Content-Type: application/json"
    ```

    - Expected: 200. Bridge `on_space_joined()` fires `bn_space_joined` for member2.

11. Confirm the event:

    ```sql
    SELECT user_id, action_id
    FROM wp_wb_gam_events
    WHERE user_id = MEMBER2_ID AND action_id = 'bn_space_joined'
    ORDER BY created_at DESC LIMIT 1;
    ```

    - Expected: 1 row.

### Part 7: Profile standing surfaces on the profile (filter seam)

12. Read member2's gamification standing through the same read API the bridge uses for the profile tiles:

    ```bash
    wp eval 'printf("points=%d level=%s badges=%d\n", wb_gam_get_user_points(MEMBER2_ID), (function(){ $l = wb_gam_get_user_level(MEMBER2_ID); return is_array($l) && !empty($l["name"]) ? $l["name"] : "none"; })(), count((array) wb_gam_get_user_badges(MEMBER2_ID)));'
    ```

    - Expected: `points` ≥ 0 (reflecting the events above, given configured point values), `level` and `badges` per partner config.

13. Visit member2's profile in the browser: `http://buddynext-dev.local/members/member2/?autologin=1`. The stats strip (`templates/parts/profile-stats-strip.php:154`) applies `buddynext_profile_extra_data`, and the bridge `inject_profile_gamification()` appends **Points**, **Level** (only if a named level matched), and **Badges** (only if the user has any) tiles.

    - Expected: a **Points** tile is present (Level/Badges appear only when the partner returns a level name / non-empty badge set). This is the only place the filter is applied — it is **template-only**, not exposed in the BuddyNext REST member payload.

### Part 8: Inbound — badge / level events become BN notifications

14. Trigger a badge or level award in wb-gamification (e.g. via its admin "Manual Award" page, or by accumulating enough events to cross a configured threshold). The partner fires `wb_gam_badge_awarded` / `wb_gam_level_changed`; the **Listener** routes each into a BuddyNext notification:

    ```sql
    -- Badge → bn.badge_awarded notification:
    SELECT recipient_id, type, data
    FROM wp_bn_notifications
    WHERE type = 'bn.badge_awarded' ORDER BY id DESC LIMIT 1;

    -- Level up → bn.level_up notification:
    SELECT recipient_id, type, data
    FROM wp_bn_notifications
    WHERE type = 'bn.level_up' ORDER BY id DESC LIMIT 1;
    ```

    - Expected: a row of the matching `type`, `sender_id` null (system notification), `data` JSON carrying the badge name / level id. The Listener only writes notifications — it never submits an award, so it cannot double-count alongside the emit-side bridge.

## Edge cases to also verify

- **Bridge inert when partner inactive (no fatals).** Deactivate the partner and re-run a BN action:
  ```bash
  wp plugin deactivate wb-gamification
  curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/members/MEMBER2_ID/follow \
    -u member1:password -H "Content-Type: application/json"
  wp eval 'echo function_exists("wb_gam_submit_event") ? "present" : "absent";'
  ```
  Expected: follow succeeds normally, prints `absent`, **no PHP fatal/notice**. `init()` returned early so no producer hooks were ever bound; `fire()` also re-guards `function_exists('wb_gam_submit_event')`. Re-activate the partner afterward (`wp plugin activate wb-gamification`).

- **Catalogue registration is idempotent.** Re-fire the bridge load and confirm no duplicate-registration warning and no duplicate catalogue rows:
  ```bash
  wp eval 'do_action("buddynext_load_bridges"); $a = wb_gam_get_actions(); echo isset($a["bn_followed"]) ? "still single registration, no notice\n" : "MISSING\n";'
  ```
  Expected: clean — `register_actions()` skips any slug already present in `wb_gam_get_actions()`.

- **Event maps to the correct recipient, not the actor.** After Part 2 (member1 follows member2), confirm member1 did **not** receive a `bn_followed` event:
  ```sql
  SELECT COUNT(*) AS should_be_zero
  FROM wp_wb_gam_events
  WHERE user_id = MEMBER1_ID AND action_id = 'bn_followed';
  ```
  Expected: `0` — only the followed user earns. Same recipient discipline applies to `bn_reaction_received` (awards the post author, not the reactor).

- **Connection accepted awards BOTH parties.** Have member1 request and member2 accept a connection (fires `buddynext_connection_accepted`). Expected: two `bn_connected` events — one for each user — because `on_connection_accepted()` calls `fire()` twice (once per side).

## What this validates

This journey validates the **BuddyNext-side bridge seam**, not the partner's points/badge math:

- `GamificationBridge::init()` binds the 8 producer actions and the `buddynext_profile_extra_data` filter **only** when `wb_gam_submit_event()` exists.
- `register_actions()` registers the 9-entry `ACTION_CATALOGUE` via `wb_gam_register_action()`, idempotently (skips already-registered slugs), against the inert `NOOP_HOOK` so the engine never auto-awards.
- Each `on_*` handler maps a BN action to the right `bn_*` slug **and the correct recipient**: followed user (`bn_followed`), both peers (`bn_connected` ×2), author (`bn_post_created`), joining user (`bn_space_joined`), struck user (`bn_strike_issued`), content owner (`bn_reaction_received`), commenter (`bn_comment_created`), and profile owner (`bn_profile_updated`, plus `bn_profile_completed` only at 100%).
- `fire()` guards `user_id > 0` and partner presence, then calls `wb_gam_submit_event()` exactly once per event.
- `inject_profile_gamification()` appends Points / Level / Badges tiles via the shared `buddynext_profile_extra_data` filter, degrading to a no-op when each read function is absent.
- `GamificationBridgeListener::register()` binds `wb_gam_badge_awarded` / `wb_gam_level_changed` and translates them into `bn.badge_awarded` / `bn.level_up` notification rows — inbound only, never submitting an award.

## Verification queries

```sql
-- All gamification events generated by this journey (partner-owned table):
SELECT user_id, action_id, object_id, created_at
FROM wp_wb_gam_events
WHERE action_id LIKE 'bn_%'
ORDER BY created_at DESC
LIMIT 25;

-- Points ledger rows for the test members (only if admin configured point values):
SELECT user_id, action_id, points, created_at
FROM wp_wb_gam_points
WHERE user_id IN (MEMBER1_ID, MEMBER2_ID) AND action_id LIKE 'bn_%'
ORDER BY created_at DESC;

-- Running totals (partner-derived):
SELECT user_id, point_type, total
FROM wp_wb_gam_user_totals
WHERE user_id IN (MEMBER1_ID, MEMBER2_ID);

-- Inbound notifications the Listener created from partner events:
SELECT recipient_id, type, data, created_at
FROM wp_bn_notifications
WHERE type IN ('bn.badge_awarded', 'bn.level_up')
ORDER BY id DESC;
```

```bash
# Observe events firing live via debug log (set WP_DEBUG_LOG=true in wp-config.php first):
wp eval 'add_action("all", function($h){ if (in_array($h, ["buddynext_user_followed","buddynext_post_created","buddynext_post_reaction_received","buddynext_comment_created","buddynext_space_member_joined"], true)) error_log("BN action fired: $h"); });'
# then watch wp-content/debug.log while performing the REST calls above.
```

## REST surface walked

The bridge exposes **no REST endpoints of its own** — it is event-driven middleware. It is exercised indirectly through the BuddyNext Free endpoints that fire the producer actions:

```
POST /buddynext/v1/users/{id}/follow          -- fires buddynext_user_followed        → bn_followed
POST /buddynext/v1/users/{id}/connect/accept  -- fires buddynext_connection_accepted   → bn_connected ×2
POST /buddynext/v1/posts                      -- fires buddynext_post_created          → bn_post_created
POST /buddynext/v1/reactions/toggle           -- fires buddynext_post_reaction_received → bn_reaction_received
POST /buddynext/v1/comments                   -- fires buddynext_comment_created       → bn_comment_created
POST /buddynext/v1/spaces/{id}/join           -- fires buddynext_space_member_joined   → bn_space_joined
```

> **Route shapes corrected against the live index 2026-06-20** (the prior list had `/members/{id}/follow`, `/posts/{id}/reactions`, `/posts/{id}/comments` — those don't exist; the real producers are `/users/{id}/follow`, `/reactions/toggle`, `/comments`). Observability is not REST-facing: standing is surfaced on the rendered profile (`buddynext_profile_extra_data`); award state lives in the partner's `wp_wb_gam_*` tables.

## Bridge contract & partner gate

*(Item 11, bridge form. A bridge has no buttons — its "wiring" is the cross-plugin hook contract. The load-bearing checks are the partner guard and exact hook arg-counts.)*

| Direction | Hook (arg count) | Handler | Guard |
|---|---|---|---|
| BN → gamification | BN domain events (`buddynext_user_followed`, `_post_created`, `_post_reaction_received`, `_comment_created`, `_space_member_joined`) | submit via `wb_gam_submit_event()` | `GamificationBridgeListener::register()` bails if `! function_exists('wb_gam_submit_event')` (`:28`) |
| gamification → BN | `wb_gam_badge_awarded(user_id, def, badge_id)` (3), `wb_gam_level_changed(user_id, new, old)` (3) | `on_badge_awarded` / `on_level_changed` create a BN activity + notification | same guard (`:42-43`) |

**Verify this run (partner active — `wb-gamification` IS active here):**
1. As `alice` react to a post → confirm a row appears in the partner's `wp_wb_gam_*` event table (points awarded to the author, not the reactor — the self-reaction guard).
2. Award a badge in the partner → confirm a BN activity + notification is created for that user.
3. **Graceful no-op:** deactivate `wb-gamification`, repeat step 1 → no fatal, no event (the `function_exists` guard means the listener never registered). Reactivate.

## Admin-config → member-effect

*(Item 12.)*

- **Gamification feature toggle** (Settings → Features → "Gamification"): OFF → BN stops submitting events even with the partner active; ON → resumes. Confirm via the partner event table.
- **Partner presence:** with `wb-gamification` deactivated, the BN profile gamification card must render nothing (not error) — the degrade path.

## Cleanup

```sql
-- Remove gamification events generated by this journey (partner table — BN-sourced rows only):
DELETE FROM wp_wb_gam_events  WHERE action_id LIKE 'bn_%' AND user_id IN (MEMBER1_ID, MEMBER2_ID);
DELETE FROM wp_wb_gam_points  WHERE action_id LIKE 'bn_%' AND user_id IN (MEMBER1_ID, MEMBER2_ID);

-- Recompute / clear the test members' totals (or let the partner recompute on next event):
DELETE FROM wp_wb_gam_user_totals WHERE user_id IN (MEMBER1_ID, MEMBER2_ID);

-- Remove the inbound test notifications:
DELETE FROM wp_bn_notifications WHERE type IN ('bn.badge_awarded', 'bn.level_up') AND recipient_id IN (MEMBER1_ID, MEMBER2_ID);
```

```bash
# Remove the test space and its membership (if created in Part 6):
wp db query "DELETE FROM wp_bn_space_members WHERE space_id IN (SELECT id FROM wp_bn_spaces WHERE slug = 'gam-journey-space');"
wp db query "DELETE FROM wp_bn_spaces WHERE slug = 'gam-journey-space';"

# Undo the follow / post / comment created during the journey via the matching DELETE endpoints,
# or remove the bn_posts / bn_comments / bn_follows rows directly (see activity-feed.md / social-graph.md cleanup).
```

> The `wp_wb_gam_*` tables are **partner-owned**. Deleting rows here only tidies test data; the canonical lifecycle (recompute, badge revocation, streak reset) belongs to wb-gamification. Prefer the plugin's own admin reset where available over manual SQL on a shared instance.

## Known limitations

- **Point values, badge rules, levels, streaks, leaderboards are entirely partner-owned.** BuddyNext registers the action catalogue with `default_points` hints only; the actual points awarded, badge thresholds, and level curve are configured in the wb-gamification admin. A `bn_*` event firing does **not** guarantee points — it guarantees the engine *received* the event. If `wp_wb_gam_points` shows no row, check the action's configured point value in the partner admin, not the bridge.
- **Profile tiles are template-only.** The Points / Level / Badges tiles are injected via `buddynext_profile_extra_data`, which is applied only in `templates/parts/profile-stats-strip.php`. They are **not** part of the BuddyNext REST member response, so REST-only consumers won't see gamification standing through BN.
- **`bn_profile_completed` requires reaching exactly 100%.** `on_profile_completion_changed()` fires `bn_profile_completed` only when `$percent === 100`; partial completion fires `bn_profile_updated` only.
- **No leaderboard/streak surface in BN.** Leaderboards and streaks live entirely in wb-gamification (blocks, REST, admin). BuddyNext does not re-expose them; surfacing them is partner-side or a future BN consumer.
- **`bn_strike_issued` has `default_points = 0`.** It exists so an admin can configure a point *deduction* for moderation strikes; it awards nothing unless configured negative.

## Automation notes

- Gate the whole script on `wp plugin is-active wb-gamification` (and `function_exists('wb_gam_submit_event')`). Skip — do not fail — when the partner is absent: the bridge is *designed* to be inert, so "partner off → no events" is a passing state, asserted by the inactive edge case.
- Collect `MEMBER1_ID` / `MEMBER2_ID` and any created `POST_ID` / `SPACE_ID` from setup; never hardcode.
- Assert at the **event** layer (`wp_wb_gam_events`), not the **points** layer (`wp_wb_gam_points`) — points depend on per-site admin config and will be flaky across instances; event rows are deterministic given the bridge fired.
- The recipient-mapping assertions (actor did **not** earn; both peers earned on connection) are the highest-value checks — they guard the most error-prone part of the bridge and are pure SQL count assertions.
- The inbound Listener path (Part 8) is hard to drive purely via BN REST; trigger it through the partner's Manual Award admin action or a `wp eval` that calls the partner's badge/level award API, then assert the `wp_bn_notifications` row.
