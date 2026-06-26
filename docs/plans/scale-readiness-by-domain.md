# Scale Readiness — By-Functionality Worklist

The micro-level companion to [`scale-readiness-100k.md`](./scale-readiness-100k.md). That
doc holds the *rules + ranked risks*; this one is the **grind list** — every member-facing
functionality mapped to its service, REST controller, templates, and blocks, with one
compliance cell per standard so we can work it **functionality → template → block**, one
cell at a time.

## Legend

- ✅ verified compliant (audit + code-read)
- ⚠️ verified issue — fix tracked below / in the 100k plan
- ◻️ not yet audited at micro level (the work to do)
- — not applicable

Standards: **REST** = [REST-API-BOUNDARY](../standards/REST-API-BOUNDARY.md) ·
**Cache** = [CACHING](../standards/CACHING.md) · **Scale** = [DATA-AT-SCALE](../standards/DATA-AT-SCALE.md) ·
**BG** = [BACKGROUND-JOBS](../standards/BACKGROUND-JOBS.md).

> REST boundary is globally CI-enforced (`bin/check-rest-boundary.sh`), so the REST cell is
> ✅ for every area unless a specific gap is noted. The one global REST gap (no token/JWT
> auth for a native app) is tracked once in the 100k plan, not per-row.

---

## FREE — member-facing functionalities

| # | Functionality | Service(s) | Templates | Blocks | REST | Cache | Scale | BG |
|---|---|---|---|---|---|---|---|---|
| 1 | **Feed** (posts/polls/share/bookmark) | `Feed/FeedService`, `PostService`, `PollService`, `ShareService`, `BookmarkService` | `feed/` | `bn-activity-feed`, `bn-post-composer` | ✅ | ⚠️ `PollService`,`ShareService` uncached | ⚠️ page-1 cached✅; impression hook fires per-row (P0, Pro listener) | ✅ |
| 2 | **Social Graph** (follow/connect/block) | `SocialGraph/FollowService`, `ConnectionService`, `BlockService` | `partials/` (buttons) | `bn-follow-button`, `bn-connection-button` | ✅ | ✅ canonical pattern + key-bust | ✅ indexed (`bn_follows`,`bn_connections`,`bn_blocks`) | — |
| 3 | **Profile + Directory** | `Profile/ProfileService`, `MemberDirectoryService` | `profile/`, `directory/` | `bn-profile-header`,`bn-profile-fields`,`bn-profile-completion-bar`,`bn-member-card`,`bn-member-directory` | ✅ | ✅ profile + dir cached | ⚠️ **presence CAST scans** (P0); dir keyset✅ | — |
| 4 | **Notifications** | `Notifications/NotificationService`, `NotificationPrefService` | `notifications/` | `bn-notification-bell` | ✅ | ✅ count cached+invalidated (needs Redis) | ◻️ list page uncached (cache page-1) | ✅ digest AS |
| 5 | **Messages / DM** (UI over WPMediaVerse) | `Messages/*` (bridge) | `messages/` | — | ✅ (`mvs/v1` documented) | ◻️ | ⚠️ **DM poll flat 5s, no hidden-tab pause** | — |
| 6 | **Spaces** | `Spaces/SpaceService`, `SpaceMemberService`, `SpaceCategoryService` | `spaces/` | `bn-space-card`,`bn-space-directory`,`bn-my-spaces` | ✅ | ⚠️ `SpaceCategoryService` uncached | ⚠️ **per-space autoloaded options** (P0) | — |
| 7 | **Reactions + Comments** | `Reactions/ReactionService`, `Comments/CommentService` | `partials/` | — | ✅ | ✅ both cached + invalidated | ✅ denormalized counters | — |
| 8 | **Hashtags** | `Hashtags/HashtagService` | `hashtags/` | `bn-trending-hashtags` | ✅ | ⚠️ **dup store** (transient + CacheService) | ◻️ | ✅ lazy-on-read |
| 9 | **Search** | `Search/SearchService` | `search/` | `bn-search-bar` | ✅ | ◻️ | ⚠️ `enrich_members()` N+1 (un-primed); `LIKE` no FULLTEXT | ✅ reindex AS |
| 10 | **Moderation** | `Moderation/SafeguardService`, `PreModerationService`, `ModerationService` | `moderation/` | — | ✅ | ⚠️ `PreModeration`,`Safeguard` uncached | ◻️ admin queue lists | ✅ queue-check AS |
| 11 | **Onboarding** | `Onboarding/*`, `SetupWizard` | `onboarding/` | — | ✅ | ◻️ | ◻️ | ✅ nudge single-events |
| 12 | **Auth** (login/register/verify) | `Auth/*` | `auth/` | `bn-login-form`,`bn-registration-form` | ✅ | ✅ transients for tokens/rate | ◻️ | ✅ token cleanup AS |
| 13 | **Member Types** | `MemberTypes/MemberTypeService` | `settings/` | — | ✅ | ✅ via `CacheService` (the one real consumer) | ◻️ | — |
| 14 | **Engagement / Streaks** | `Engagement/StreakService` | `parts/` | — | ✅ | ✅ cached (`buddynext_user_meta`) | ◻️ | — |
| 15 | **Realtime / Presence** | `Realtime/PresenceService`, `RealtimeController` | `shell/` | — | ✅ | ◻️ presence id-set cache (fix for #3) | ⚠️ presence write throttled✅; read scans (ties to #3) | — |
| 16 | **Activity Log** | `ActivityLog/ActivityLogService` | — | — | ✅ | ⚠️ uncached `get_results` | ◻️ admin-only | ✅ weekly cleanup AS |
| 17 | **Header / Nav / Sidebar / Widgets** | `Header/*`, `Nav/*`, `Widgets/*` | `parts/`, `shell/`, `partials/` | `bn-header-user-menu` | ✅ | ⚠️ `RecentActivity`/`TrendingHashtags` widgets bypass `WidgetCache` | ◻️ | — |

## PRO — functionalities

| # | Functionality | Service(s) | Templates | REST | Cache | Scale | BG |
|---|---|---|---|---|---|---|---|
| P1 | **Membership / Subscriptions** | `Membership/MembershipTierService`,`SubscriptionService` | `membership/` | ✅ | ⚠️ group `'buddynextpro'` (no domain) — converge | ◻️ | ✅ expiry AS |
| P2 | **Payments / Stripe** | `Payments/*`,`Stripe/StripeGateway` | — | ✅ | ✅ gateway cached | ◻️ | ✅ webhook events |
| P3 | **AI** (ranked feed / semantic search / embeddings / moderation) | `AI/AiRankedFeedService`,`SemanticSearchService`,`EmbeddingProvider`,`EmbeddingIndexer` | — | ✅ | ⚠️ ranked-feed + semantic uncached; `EmbeddingProvider` group `buddynext_pro_embeddings` (drift) | ◻️ | ✅ indexer async + AI sweep AS |
| P4 | **Analytics** (events / cohort / funnel / profile-views) | `Analytics/AnalyticsCollector`,`AnalyticsService`,`CohortService`,`FunnelService`,`ProfileViewService` | — | ✅ | ⚠️ Analytics/ProfileView uncached; Cohort/Funnel TTL-only | ⚠️ **impression INSERT storm** (P0); unbounded table | ◻️ retention cron missing |
| P5 | **Email** (broadcast / drip / segments) | `Email/BroadcastService`,`DripEnrollmentService`,`SegmentService` | — | ✅ | ⚠️ `SegmentService`,`DripService` uncached | ⚠️ `SegmentService` `number=>-1` unbounded | ✅ broadcast AS (drip on native cron — move to AS) |
| P6 | **Realtime / Push** | `Realtime/*`,`Push/PushDispatcher`,`PushClient` | — | ✅ | ◻️ | ◻️ FCM single-vendor | ✅ event-driven |
| P7 | **Members / Labels** | `Members/LabelService`,`LabelAssignmentService` | — | ✅ | ⚠️ both uncached | ◻️ | — |
| P8 | **Moderation (Pro)** (rules / appeals / sweep) | `Moderation/*` | — | ✅ | ◻️ | ◻️ | ✅ AI sweep + cleanup AS (group hardened) |
| P9 | **White-label / Brand** | `WhiteLabel/BrandService` | — | ✅ | ✅ cached + `bn_space_meta` | ◻️ | — |
| P10 | **Search (Pro / Saved)** | `Search/SavedSearchService` | — | ✅ | ⚠️ uncached | ◻️ | — |

---

## Suggested grind order (highest value first)

Work top-down; within each row do **service → controller → each template → each block**,
running the per-standard checklist on every surface before marking the cell ✅.

1. **Feed (#1) + Analytics (P4)** together — the impression P0 spans both (Free fires, Pro
   writes). Biggest scale win.
2. **Profile/Directory (#3) + Realtime/Presence (#15)** — the presence-scan P0.
3. **Spaces (#6)** — the autoload P0.
4. **Cache cleanup sweep** — Hashtags dup store (#8), uncached hot reads (#1 Poll/Share,
   #6 SpaceCategory, #10 Moderation, P3/P4/P5/P7), Pro group convergence (P1/P3).
5. **Messages (#5)** — DM poll hot/cold + hidden-tab pause.
6. **Everything ◻️** — audit each remaining cell template-by-template, block-by-block.

## How each cell gets closed (definition of done per surface)

For a template or block, "done" = its data path satisfies the relevant checklists:
- **REST:** data comes via `restFetch` to a `buddynext/v1` route with a real
  `permission_callback`; no `admin-ajax`/`$.ajax`. (`check-rest-boundary.sh` green.)
- **Cache:** every hot read uses the canonical per-service `CACHE_GROUP`+`CACHE_TTL`
  pattern with key-based bust on its write path.
- **Scale:** queries bounded + indexed; lists keyset-paginated + cached page-1; no
  per-row N+1; no autoloaded per-entity options; high-volume writes via AS.
- **BG:** any background work follows the decision tree (lazy/reactive/armed/AS-recurring),
  one group, idempotent guard, no idle poll.
