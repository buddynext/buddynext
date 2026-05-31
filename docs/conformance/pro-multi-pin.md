# Conformance: Multi-pin Posts (Pro)

**Feature:** Multiple pinned posts (up to 10 per space or profile) — Pro tier.
**Repo:** buddynext-pro (filter-only extension of Free's pin path).
**Spec ref:** `buddynext/docs/specs/features/02-activity-feed.md` (Post Features → Pro: "Multiple pinned posts (up to 10 per space or profile)").
**Contract ref:** `buddynext/docs/specs/features/FREE-PRO-CONTRACT.md` §4 filter #3 (`buddynext_post_pin_limit`).
**Journey doc:** `buddynext-pro/docs/journeys/multi-pin-posts.md`.
**Live-walk URL:** http://buddynext-dev.local/activity

---

## Verdict

**usable-leave-as-is.**

The Pro feature is a single filter callback that raises Free's per-scope pin cap from 1 to 10. The entire enforcement and UX path lives in Free and is wired end-to-end: a real Pin/Unpin control in the post options menu binds to an Interactivity store action that calls the Free REST pin/unpin routes, which call `PostService::pin()`, which reads the `buddynext_post_pin_limit` filter that Pro raises. The `pin_limit_reached` error is surfaced to the user as a toast. No new Pro UI, REST routes, or DB columns are required by spec, and none are missing.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Pro registers pin-limit filter at boot | service | wired | `buddynext-pro/includes/Core/Plugin.php:191` → `ProPinService::register()` |
| Filter callback raises cap to 10 (`max($limit,10)`, never lowers) | service | wired | `buddynext-pro/includes/Feed/ProPinService.php:43-68` |
| Free reads the filter inside pin() | service | wired | `buddynext/includes/Feed/PostService.php:377` |
| Free enforces cap per scope (space_id null = profile) and returns `pin_limit_reached` WP_Error at cap | service+db | wired | `buddynext/includes/Feed/PostService.php:381-399` |
| Pin write / unpin write to `bn_posts.is_pinned` | db | wired | `buddynext/includes/Feed/PostService.php:402-413` (pin), `:432-443` (unpin) |
| REST POST/DELETE `/posts/{id}/pin` (owner-only) | rest | wired | `buddynext/includes/Feed/PostController.php:72-84, 279-309` |
| Pin/Unpin button in post options menu, bound to store | ui | wired | `buddynext/templates/parts/post-options-menu.php:98-104` (`data-wp-on--click="actions.pinPost"`) |
| Store action calls REST, optimistic flip, surfaces server error message | store | wired | `buddynext/assets/js/feed/store.js:902-931` |
| Post-card seeds Interactivity context (`can_pin`, `is_pinned`, `restUrl`, `reactNonce`) and renders menu | ui | wired | `buddynext/templates/partials/post-card.php:331-347, 442-445` |

---

## First break

none — journey complete. The web journey and the REST/app journey both reach `PostService::pin()` with the Pro-raised cap in effect.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| `pin_limit_reached` error text is generic ("You have reached the maximum number of pinned posts.") — it does not state the actual cap (10), so a Pro user who hits the wall gets no hint about their slot count. Copy nitpick only; does not stop the journey. | low | confirmed-in-code | `buddynext/includes/Feed/PostService.php:394-397`; surfaced via `buddynext/assets/js/feed/store.js:918-920` |
| Journey doc preconditions carried TODOs (column vs table, exact REST path) — both resolved against Free: storage is `bn_posts.is_pinned` (column, no separate table) and route is `POST/DELETE /buddynext/v1/posts/{id}/pin`. No product gap; doc TODOs are now answered. | low | confirmed-in-code | `buddynext/includes/Feed/PostService.php:402-408`; `buddynext/includes/Feed/PostController.php:72-84` |

No critical/high gaps. There is intentionally no Pro-specific UI: Free's single Pin control serves both tiers; Pro only changes the cap value via the filter, exactly as the contract specifies.

---

## Minimal refactor plan

EMPTY — usable as-is. Do not add Pro UI or Pro REST routes; the filter-only design is per spec and fully wired through Free.

---

## Notes for the live walk

- Walk at http://buddynext-dev.local/activity as a Pro-licensed user: pin posts in one scope and confirm pinning continues past 1 up to 10. Pinning an 11th in the same scope should toast the `pin_limit_reached` message and leave `is_pinned = 0` for the 11th.
- Verify both scopes: a profile-scope pin (`space_id = null`) and a space-scope pin each independently allow up to 10 (the count query is scoped by `space_id` at `PostService.php:382`).
- Sanity check: deactivating Pro should drop the cap back to 1 (filter no longer hooked) — confirms the extension is not persistent.
