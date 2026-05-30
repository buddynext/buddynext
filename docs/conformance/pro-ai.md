# Conformance Dossier — AI Engine (Pro)

**Feature:** AI Engine (BuddyNext Pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P2-ai-engine.md` (Locked, 2026-03-19)
**Code traced:** `/Users/vapvarun/dev/repos/buddynext-pro/includes/AI/`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** partial-needs-wiring

---

## Scope

The spec lists five AI surfaces (Feed Ranking, Content Moderation, Smart Notifications, Discovery, Smart Reply). The locked entry URL is `/activity`, whose spec-primary integration point is row 1 of the Integration Points table: **"Activity Feed — AI ranking replaces chronological."** That is the core happy-path traced below. The secondary AI surface a member actually reaches on `/activity` (Smart Reply chips on the comment form) and the submission-time Moderation filter are also traced because they share the entry surface.

---

## Journey chain — AI-ranked feed at `/activity`

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin enables AI ranking toggle | service | wired | `AiRankedFeedService::is_enabled()` reads `buddynextpro_ai_feed_enabled` — `includes/AI/AiRankedFeedService.php:235` |
| Engagement signals collected into `bn_ai_signals` | service/db | wired | `SignalsCollector::register()` hooks `buddynext_reaction_added` / `_comment_created` / `_user_followed` / `_post_bookmarked`; registered unconditionally at `includes/Core/Plugin.php:97` |
| Pro rebinds container `feed` key to AI subclass | service | wired (but unused, see break) | `includes/Core/Plugin.php:459-467` |
| Pro `home_feed()` re-ranks by affinity | service | wired | `AiRankedFeedService::home_feed()` — `includes/AI/AiRankedFeedService.php:58-100` |
| SSR initial paint of default `/activity` (`for-you`) | ui | **broken** | `templates/feed/home.php:138-152` — the `for-you` default path runs inline chronological SQL in the template and only calls `buddynext_service('feed')` for non-default filters. Pro override never reached on the default view. |
| SSR initial paint, non-default filter (following/spaces/network) | ui→service | wired | `templates/feed/home.php:146` resolves `buddynext_service('feed')` → Pro subclass when toggle on |
| Infinite scroll / pagination (REST) | rest→service | **broken** | `FeedController::feed_service()` hardcodes `new FeedService(...)` and ignores the container — `includes/Feed/FeedController.php:407-409`; called by `home_feed`/`home_feed_page` at lines 150, 304 |

### Reply (Smart Reply) sub-journey at `/activity` — for reference

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Suggest-replies button + chip island | ui | wired | `ReplyButtonRenderer::render()` emits Interactivity island on `buddynext_part_post_comment_form_after` — `includes/AI/ReplyButtonRenderer.php:50,104-155` |
| Store module enqueued | store | wired | `AiReplyAssets::maybe_enqueue()` registers `@buddynextpro/ai-reply`; `assets/js/ai-reply/store.js` exists (5,955 bytes) — `includes/AI/AiReplyAssets.php:95-101` |
| REST `POST /buddynext-pro/v1/ai/reply-suggestions` | rest | wired | `ReplyController::register_routes()` registered at `includes/Core/Plugin.php:315-317`; quota + provider checks at `includes/AI/Controllers/ReplyController.php:107-176` |
| Provider call gated on config | service | wired | `ReplyGenerator::is_enabled()` reads `buddynextpro_ai_reply_provider` (default `disabled`) — `includes/AI/ReplyGenerator.php:496-498` |

### Moderation sub-journey — for reference

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| AI classifier hooks submission filter | service | wired | `ModerationAi::register()` adds `buddynext_safeguard_check` at priority 20; registered unconditionally at `includes/Core/Plugin.php:214`; block logic `includes/AI/ModerationAi.php:86-120` |
| Admin test endpoint | rest | wired | `AiModerationController` `POST /ai/classify` (manage_options) — `includes/Core/Plugin.php:312-314` |

---

## First break

`templates/feed/home.php:138-152` — the default `for-you` view of `/activity` (the literal happy-path) renders chronological inline SQL inside the template and never resolves `buddynext_service('feed')`. The Pro `AiRankedFeedService` container rebind (`Plugin.php:459`) is therefore bypassed on the default view. The companion break is `FeedController::feed_service()` (`includes/Feed/FeedController.php:407-409`), which hardcodes `new FeedService(...)` so every REST pagination / filter-switch page also bypasses the container. Net: AI feed ranking — the spec's #1 integration point — does not reach the page a member opens.

---

## UX gaps

1. **AI feed ranking never reaches the default `/activity` view** — severity: high — confidence: confirmed-in-code — evidence: `templates/feed/home.php:138-152` (default `for-you` uses inline chronological SQL, not the feed service); container override sits unused at `includes/Core/Plugin.php:459-467`. With the admin toggle ON, a member opening `/activity` still gets chronological order. Note: this is a parity gap for both web and app journeys (REST path also bypassed).

2. **REST feed pagination ignores the container-bound feed service** — severity: high — confidence: confirmed-in-code — evidence: `includes/Feed/FeedController.php:407-409` hardcodes `new FeedService(...)`. Even on non-default SSR filters where the first paint is AI-ranked, scrolling reverts to chronological. Affects the app/REST client identically.

3. **Free `feed` binding signature differs from Pro override** — severity: low — confidence: confirmed-in-code — evidence: Free binds 3 args `new FeedService(follows, post_service, feed_cache)` at `includes/Core/Plugin.php:632`; Pro override and `FeedController::feed_service()` pass only 2. Once the consumers are pointed at the container this mismatch must be reconciled so the AI subclass receives the cache arg (or the cache layer is intentionally omitted for ranked feeds).

(Smart Reply, Signals collection, and Moderation are fully wired — no gaps. Smart Notifications and Discovery surfaces from the spec were not traced under this `/activity` entry and are out of scope for this dossier.)

---

## Minimal refactor plan (reuse existing working code)

1. Make `FeedController::feed_service()` resolve the container instead of constructing directly: return `buddynext_service('feed')` (with a `FeedService` fallback) — `includes/Feed/FeedController.php:407-409`. This immediately wires AI ranking into all REST pagination paths.
2. Route the default `for-you` SSR path through the feed service the same way the non-default filters already do: in `templates/feed/home.php`, call `buddynext_service('feed')->home_feed(..., 'for-you')` rather than the inline chronological SQL, so the Pro subclass re-ranks the first paint. Keep the inline SQL only as the service's internal default (it already lives in `FeedService::home_feed_uncached`).
3. Reconcile the constructor arity: update Pro's `AiRankedFeedService` binding at `includes/Core/Plugin.php:459-467` to accept/pass the `feed_cache` dependency that Free's binding supplies (`Plugin.php:632`), or document that ranked feeds intentionally skip the cache. Verify no fatal from the 2-vs-3 arg mismatch once the container is the live source.
4. Live-verify on `/activity` with the toggle ON and seeded `bn_ai_signals` rows: confirm first paint and scroll both reflect affinity ordering (per MEMORY: seed data before judging — an empty signals table makes ranking == chronological and would mask the fix).

---

## Live-walk URL

http://buddynext-dev.local/activity
