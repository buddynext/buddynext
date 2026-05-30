# Conformance — Shares / Reposts

**Feature:** Shares / Reposts (Free)
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Post Features → "share"; Data Stored → `bn_shares`)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/activity

---

## Journey chain

A logged-in member opens the feed, clicks Share on a post, picks Repost, and the repost appears in the feed with the original embedded; the source post's share count bumps.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Share button on each post card (suppressed on share cards) | ui | wired | `templates/parts/post-actions.php:158-171` (`data-wp-on--click="actions.openShare"`, `state.shareLabel`) |
| `openShare` dispatches `bn:open-share-modal` CustomEvent | store | wired | `assets/js/feed/store.js:832-848` |
| Share modal rendered once per surface (home/bookmarks/single) | ui | wired | `templates/feed/home.php:587`; `templates/partials/share-modal.php:27-111` |
| Bridge listener populates modal context + opens it | store | wired | `assets/js/feed/store.js:1950-1966` |
| Repost CTA → `actions.repost` → POST `/posts/{id}/share` | store | wired | `templates/partials/share-modal.php:67-78`; `assets/js/feed/store.js:1874-1906` |
| REST route `POST /posts/{id}/share` (auth-gated) | rest | wired | `includes/Feed/ShareController.php:31-46,80-99,137-147` |
| ShareService writes `bn_shares`, dedupes, creates `type='share'` feed post, bumps count, fires hooks | service | wired | `includes/Feed/ShareService.php:31-117` |
| `bn_shares` + `bn_posts(type='share', shared_post_id)` persisted | db | wired | `includes/Feed/ShareService.php:53-90` |
| Home feed query selects all types incl. `share` (no type exclusion) | service | wired | `templates/feed/home.php:183-222` (selects `shared_post_id`, `type`; no `type<>'share'` filter) |
| Share card renders embedded original (+ graceful fallback) | ui | wired | `templates/partials/post-card.php:102-111,481`; `templates/parts/post-body.php:303-356` |
| Unshare `DELETE /posts/{id}/share` + count decrement | rest/service | wired | `ShareController.php:40-44,107-114`; `ShareService.php:128-145` |
| Share history `GET /me/shares` (app/REST client) | rest/service | wired | `ShareController.php:48-71,122-130`; `ShareService.php:181-227` |

## First break

none — journey complete.

## UX gaps

None that stop the journey. Observations (non-blocking):

- The optional repost note (`bn_shares.content`) is accepted by the API (`ShareController.php:83`, `ShareService.php:31`) but the web modal's Repost CTA sends no note (`store.js:1880-1883`). This is intentional: "Quote" pre-fills the composer for a commented repost (`store.js:1907-1922`). The note column is reachable only by REST clients, not the web Repost button. Matches spec ("reposts with optional note") via the API; the web UX routes commentary through Quote. confidence: confirmed-in-code.
- Privacy inheritance on the share feed-post is sound: it copies the original's privacy and the home query enforces `public/followers` for followed authors (`ShareService.php:67-75`, `home.php:201`), respecting `17-roles-permissions.md` visibility.

## Minimal refactor plan

None. Feature is wired end-to-end across ui/store/rest/service/db for the web journey and additionally exposes a clean REST surface (`/posts/{id}/share`, `/me/shares`) for the app client.

## Note on verification

All statuses above are confirmed by reading code. A live walk at the entry URL with seeded follow/post data is the final confirmation but no break was provable statically.
