# Integration #3 — WB Listora (Directory / Business Listings)

**Status:** 🟢 BUILT (activity + profile panel; notifications blocked on hook card).
**Tier:** Pro (application-layer, Tier-2 community layer).
**Plugin:** WB Listora free + pro (live). Standalone — keeps its own directory pages,
listing pages, owner dashboard, and notifications. BN adds a community layer on top:
profile + activity + notifications + search. Never takes over Listora's screens.

> Mirrors Career Board (`01-career-board.md`) — same shape, same rules.

## Data model (manifest-first)

- CPT `listora_listing` (`has_archive` — its own directory), owner = `post_author`.
- REST `listora/v1/…` incl. `dashboard/listings`, `dashboard/notifications`,
  `dashboard/profile`, `dashboard/reviews`, `dashboard/stats`, `claims`.
- Lifecycle: `wb_listora_after_create_listing( $post_id, $request )` (on submit, 2nd is a
  `WP_REST_Request`); `wb_listora_listing_trashed` / `wb_listora_after_delete_listing`.
  There is **no** `*_approve_listing` hook — "became public" is the core
  `transition_post_status` → `publish` for `listora_listing` (covers admin approval,
  frontend publish, and email-verified publish in one seam).
- Own **notifications**: notifier classes + `listora/v1/dashboard/notifications[/read]` +
  `notifications/log`. **No `*_notification_created` action** (only `wb_listora_notification_skipped`).
- Owner dashboard: `listora_dashboard` page (resolve URL via Listora settings).

## Touchpoints (community layer)

| Touchpoint | What | How |
|---|---|---|
| **Activity** | A listing **going public** → BN feed activity ("added a new listing", links to the listing) | `transition_post_status` → publish → `Feed\IntegrationActivity::publish` (publish only, no pending broadcast). Removed when it leaves publish / is deleted. |
| **Profile — Listings** | Portfolio panel: the member's published listings → each links to its Listora page | `buddynext_member_suite_panels` → `listora_listings` panel. Owner-only "Manage on WB Listora" → `wb_listora_get_dashboard_url()`. |
| **Search** | listings findable in BN search | `transition_post_status` → publish → `SearchService::index('listing', …)` |
| **Notifications** | Listora's own notifications into BN's central center | **Card needed** — Listora has no creation hook. Add `wb_listora_notification_created` (message+link), then `SuiteNotifications` mirrors it (per `02-notification-aggregation.md`). Coexists with Listora's dashboard. |
| **App coverage** | listings panel + activity | Portfolio REST (`GET buddynext-pro/v1/members/{id}/portfolio`, generic) + feed REST. ✅ no extra endpoint. |

## Organisation (consistent with Career Board)

- `includes/Integrations/Listora/ListoraSocial.php` (Pro) — profile listings panel (+ owner
  dashboard CTA) via `buddynext_member_suite_panels`. Mirrors `CareerBoardSocial`.
- `includes/Bridges/ListoraBridge.php` (Pro) — event wiring: `transition_post_status` →
  publish → activity + search; leaving publish / `before_delete_post` → remove. Guards on
  `WB_LISTORA_VERSION`.
- Notifications → the shared `SuiteNotifications` listener once the hook ships.

## Cards filed
- **WB Listora** → add `wb_listora_notification_created` (free) carrying recipient + message
  + link, so BN mirrors its notifications into the central center (same contract as Career
  Board / Jetonomy / WPMediaVerse). Card **9994191304** (project 47045113, Triage).

## Build status (2026-06-14)

> **Hook reality check:** there is **no** `wb_listora_after_approve_listing` — listings
> move through post statuses (pending / pending_verification / publish). The bridge keys
> off the WP-native `transition_post_status` seam instead, which covers every path a
> listing goes public (admin approval, frontend publish, email-verified publish) without
> depending on a partner hook that does not exist.

1. ✅ `BuddyNextPro\Bridges\ListoraBridge` — `transition_post_status`: → publish indexes
   (`SearchService` type `listing`) + posts a feed activity ("added a new listing") linking
   OUT to the listing; publish → non-publish removes it; `before_delete_post` covers hard
   delete (reconstructs the published permalink so the removal key matches). Guard
   `WB_LISTORA_VERSION`. Revisions ignored.
2. ✅ `BuddyNextPro\Integrations\Listora\ListoraSocial` — one `listora_listings` Portfolio
   panel (published listings, each links to its Listora page) + owner-only "Manage on WB
   Listora" CTA via `wb_listora_get_dashboard_url()`. Wired in `Core\Plugin::wire_extensions`.
3. ⛔ Notifications: `SuiteNotifications` listener — **blocked on card 9994191304**.
4. ✅ Verified: 10 unit tests (`ListoraBridgeTest` + `ListoraSocialTest`) green; full
   suite 26/26. Live on `buddynext-dev.local` — seeded 3 listings for Alex Rivera (ID 35);
   Portfolio tab shows the Listings panel with real `/listing/{slug}/` links; feed
   activity + search index populated; unpublish removes + republish re-adds. Browser-verified
   desktop + 390px (no console errors). App: panels flow through the generic
   `GET buddynext-pro/v1/members/{id}/portfolio` (PortfolioController) — no extra endpoint.

## Locked decisions (2026-06-14)
1. **Activity trigger: on approve only.** `wb_listora_after_approve_listing` → one feed
   activity ("listed {title}"). Pending/draft listings never broadcast (private until
   public). Mirrors Career Board posting activity on publish, not on draft.
2. **Profile: listings only (one panel).** A single `listora_listings` Portfolio panel of
   the member's published listings; reviews/claims are NOT separate panels (avoids
   tab/panel explosion — the anti-explosion rule). Revisit only if a clear member need
   appears.
3. **Notifications:** blocked on card 9994191304 until the hook ships; coexists with
   Listora's own dashboard center.

Same shape as Career Board (`01-career-board.md`) and the locked Jetonomy plan
(`03-jetonomy.md`): activity-on-publish + one shared Portfolio panel + central
notifications via a creation hook.
