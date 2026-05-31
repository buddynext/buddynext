# Conformance — Pro AI Engine (Feed Ranking journey)

**Feature:** AI Engine (repo: buddynext-pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P2-ai-engine.md`
**Code traced:** `/Users/vapvarun/dev/repos/buddynext-pro/includes/AI/`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Scope of this audit

The spec lists five integration surfaces (Feed Ranking, Moderation, Smart
Notifications, Discovery, Search). The locked entry URL is `/activity`, so the
core happy-path verified here is **AI feed ranking** — the spec's first and
primary integration point ("AI ranking replaces chronological"). Moderation,
smart replies, and semantic search have services + controllers + admin toggles
present (see notes) but are not the `/activity` journey and were not traced
end-to-end here.

## Happy-path journey (site owner enables AI ranking → member sees ranked feed)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin toggles "Enable AI feed ranking" + decay window | ui | wired | `includes/Admin/AIAdmin.php:249` (toggle row), `:262` (decay number row); registered `includes/Core/Plugin.php:211` |
| Toggle persisted as option | store | wired | `includes/Admin/AIAdmin.php:113` register_setting → `buddynextpro_ai_feed_enabled` |
| Engagement events written to `bn_ai_signals` (warm dataset) | service | wired | `includes/AI/SignalsCollector.php:57` hooks react/comment/follow/bookmark; always-on `includes/Core/Plugin.php:97` |
| Signals table exists / ENUM widened | db | wired | `includes/Core/Installer.php:340` CREATE TABLE bn_ai_signals; `:148` ALTER ENUM |
| Container rebinds `feed` to AI service when enabled | service | wired | `includes/Core/Plugin.php:463-471` rebind to `AiRankedFeedService` |
| REST feed endpoint resolves via container | rest | wired | `includes/Feed/FeedController.php:417-425` feed_service() → `buddynext_service('feed')`; called at `:150`, `:304` |
| SSR initial paint resolves via container | ui | wired | `templates/feed/home.php:153` `buddynext_service('feed')` → `home_feed()` at `:157` |
| Re-rank by affinity (engagement + decayed signals) | service | wired | `includes/AI/AiRankedFeedService.php:58` home_feed override; `:112` compute_scores; `:199` fetch_signals query |
| Disabled / anon / cold fallback to chronological | service | wired | `AiRankedFeedService.php:63` short-circuit; `FeedController.php:425` direct FeedService fallback |
| `/activity` route → home feed template | ui | wired | `includes/Core/PageRouter.php:7`, `:813` returns `feed/home.php` |

## First break

none — journey complete. Admin control, signal collection, schema, container
rebind, REST path, and SSR path all resolve through the same `feed` container
key, so AI ranking reaches both the initial paint and infinite-scroll
pagination consistently. Off-state and cold-table paths fall back to
chronological without fatal.

## UX gaps

None that stop the journey. The ranking is intentionally invisible (re-ordered
chronological cards, same markup) — there is no per-member "why am I seeing
this" affordance, but the spec does not require one. The feature is admin-gated
and off by default, matching the spec.

## Notes on other spec surfaces (not the /activity journey)

- Moderation: `includes/AI/ModerationAi.php`, `Controllers/AiModerationController.php`, `ContentClassifier.php` present.
- Smart replies: `ReplyGenerator.php`, `ReplyButtonRenderer.php`, `AiReplyAssets.php`, `Controllers/ReplyController.php` + admin toggles `AIAdmin.php:335-378`.
- Semantic search: `SemanticSearchService.php`, `EmbeddingIndexer.php`, `EmbeddingProvider.php` + container rebind `Plugin.php:486-493`.
- Smart Notifications (fatigue / optimal send time) and Discovery (spaces-you-
  might-like, extended PYMK, interest-cluster trending) were not located as
  dedicated AI services in this trace — flagged for a separate per-surface
  audit, not as a break of the `/activity` journey.

## Minimal refactor plan

Empty — usable, leave as is. Do not rewrite working ranking code.
