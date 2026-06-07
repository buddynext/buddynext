# Conformance: AI Engine (Pro) — AI-Ranked Feed journey

**Feature:** AI Engine (repo: buddynext-pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P2-ai-engine.md`
**Code traced:** `/Users/vapvarun/dev/repos/buddynext-pro/includes/AI/`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Scope

The spec lists several AI surfaces (feed ranking, moderation, smart notifications,
discovery, semantic search, smart replies). The locked spec's first/primary
integration point and the supplied LIVE ENTRY URL (`/activity`) both point at the
**core happy-path journey: AI-ranked home feed replacing chronological**. That is
the journey verified here, end-to-end. Other AI surfaces (moderation, replies,
semantic search) are present in the directory and wired through the same container
(`includes/Core/Plugin.php` binds `pro_ai_reply`, semantic search, moderation
controller) but are out of scope for this feed-journey walk.

The feed-ranking journey is deliberately **server-side transparent**: the spec says
"AI ranking replaces chronological" on the existing Activity Feed. There is no new
member-facing UI — the same `/activity` feed UI and the same `GET /feed` REST
endpoint are reused; Pro only reorders the result set. So the only net-new UI is the
**admin enable toggle**, which exists.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin enables "AI feed ranking" + decay window | ui | wired | `buddynext-pro/includes/Admin/AIAdmin.php:46-53,74-103,250-265` (Settings-API page, AdminHub tab "growth/ai-feed", `manage_options`) |
| Admin page registered at boot | service | wired | `buddynext-pro/includes/Core/Plugin.php:211` (`( new AIAdmin() )->register()`) |
| Toggle persists as option | db | wired | option `buddynextpro_ai_feed_enabled` via `register_settings()` `AIAdmin.php:110+`; read in `AiRankedFeedService::is_enabled()` `AI/AiRankedFeedService.php:235-237` |
| `bn_ai_signals` table created | db | wired | `buddynext-pro/includes/Core/Installer.php:340` (`CREATE TABLE {$p}bn_ai_signals ...`) |
| Engagement events captured into signals | service/db | wired | `AI/SignalsCollector.php:57-62` hooks Free actions (`buddynext_reaction_added`, `buddynext_comment_created`, `buddynext_user_followed`, `buddynext_post_bookmarked`); inserts rows `SignalsCollector.php:137-165`; registered unconditionally `Plugin.php:97` |
| Container rebinds `feed` → AI service when enabled | service | wired | `Plugin.php:463-471` rebinds `'feed'` to `AiRankedFeedService(follows, post_service)` only if `is_enabled()` |
| AI service re-ranks parent feed by affinity | service | wired | `AI/AiRankedFeedService.php:58-100` (`home_feed()` calls `parent::home_feed()`, computes affinity from signals, re-sorts), `compute_scores()` 112-159, `fetch_signals()` 173-228 |
| REST `GET /feed` resolves rebound service per request | rest | wired | `buddynext/includes/Feed/FeedController.php:164-173,467-476` (`feed_service()` resolves `buddynext_service('feed')` every request; SSR + infinite scroll both honour the rebind) |
| `/activity` web UI renders the (now AI-ordered) feed | ui | wired | Same Free Activity feed UI + `FeedController::home_feed()` / `home_feed_page()` `FeedController.php:43,124,345`; payload shape unchanged, items merely reordered |

---

## First break

**none — journey complete.** Every link in the AI-ranked-feed chain is wired in
code: admin toggle → option → container rebind → REST resolution → re-rank →
existing UI. No member-facing UI change is required because the ranking is a
server-side reorder of the existing feed contract.

### Constructor-arity check (cleared, not a break)
Free binds `'feed'` with three args (`follows`, `post_service`, `feed_cache`) at
`buddynext/includes/Core/Plugin.php:636`; Pro rebinds with two
(`Plugin.php:466-469`). This is safe: `FeedService::__construct()` declares
`?FeedCache $cache = null` (`buddynext/includes/Feed/FeedService.php:75`), and
`AiRankedFeedService` inherits that constructor. Dropping the cache is intentional —
AI ranking must run per-request rather than serve a cached chronological page-1.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Feature ships **off by default** (`buddynextpro_ai_feed_enabled` default `false`). On a fresh site, or before an admin flips the toggle, `/activity` is plain chronological — correct/expected, but a reviewer walking `/activity` cold sees no AI behaviour. | low | confirmed-in-code | `AI/AiRankedFeedService.php:236`, `AIAdmin.php:47` |
| Re-ranking is a no-op until `bn_ai_signals` has rows for the viewer; on a seeded-but-low-engagement test account the reorder is invisible. Affinity also short-circuits with fewer than 2 feed items. | low | needs-live-verification | `AI/AiRankedFeedService.php:63-69`; live signal-row count not verifiable this session (Local DB connection was down) |

Neither gap stops the journey; both are expected behaviour of a signal-driven,
admin-gated ranking layer.

---

## Minimal refactor plan

_(empty — usable-leave-as-is)_

---

## Live-walk notes (for the human)

1. Admin → BuddyNext → Growth → **AI Feed**: confirm the toggle + decay-days field
   render and save (`AIAdmin.php`).
2. Flip **Enable AI feed ranking** on.
3. As a member with engagement history (reactions/comments/follows/bookmarks against
   specific authors), load http://buddynext-dev.local/activity and confirm posts from
   high-affinity authors float up vs a chronological control account.
4. If order looks unchanged: check `wp_bn_ai_signals` has rows for that viewer and
   that `buddynextpro_ai_feed_enabled = 1` — both runtime/config state, not code gaps.
   (DB could not be queried this session; verify live.)
