# Conformance — Shares / Reposts

**Feature:** Shares / Reposts (Free)
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Post Features → "share"; Data Stored → `bn_shares`)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## What the spec asks for

Free members can **share (repost)** a post, optionally with a note ("quote"). `bn_shares` stores
`id, user_id, post_id, note, created_at`. The denormalised `bn_posts.share_count` reflects the total.
A repost surfaces back into the feed as a `share`-type post. Share is a login-required action.

---

## Journey chain (logged-in member — primary actor)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Share button rendered on each post card | ui | wired | `templates/parts/post-actions.php:220-233` (suppressed on `share`-type cards); bound to `state.shareLabel` / `state.shareBtnClass` |
| Click → `openShare` dispatches `bn:open-share-modal` w/ postId, permalink, nonce, restUrl | store | wired | `assets/js/feed/store.js:832-848` |
| Share-modal partial rendered on feed (logged-in only) | ui | wired | `templates/feed/home.php:598`; guest early-return at `templates/partials/share-modal.php:20-23` |
| Bridge listener populates modal context + opens it | store | wired | `assets/js/feed/store.js:2016-2032` |
| Modal CTAs: Repost / Quote / Copy link | ui | wired | `templates/partials/share-modal.php:68-99` → `actions.repost`/`quote`/`copyLink` |
| `repost` → `POST buddynext/v1/posts/{id}/share`, bumps source card count | store | wired | `assets/js/feed/store.js:1940-1972` |
| `quote` → prefills composer with permalink, focuses it | store | wired | `assets/js/feed/store.js:1973-1988` |
| REST route registered + auth-gated | rest | wired | `includes/Feed/ShareController.php:30-72`; registered `includes/REST/Router.php:73` |
| Service: insert `bn_shares`, dedupe, create `share`-type `bn_posts` row, bump `share_count` | service | wired | `includes/Feed/ShareService.php:31-118` (dedupe L35-52; feed row L77-90; count L93) |
| Unshare decrements count | service | wired | `includes/Feed/ShareService.php:128-145` |
| Repost surfaces in feed as a `share` card | db/ui | wired | `ShareService.php:77-90`; shared block at `templates/partials/post-card.php:102-105` |

## First break

none — journey complete (for the logged-in member, the spec's intended actor).

---

## UX gaps

1. **Guest sees a non-functional Share button** — low severity. The button at
   `templates/parts/post-actions.php:220` is gated only by post type, not by `$can_share`
   (`post-card.php:224` computes `$can_share = current_user_id > 0` but never applies it to the
   Share button). For a logged-out viewer the share-modal partial returns early
   (`share-modal.php:20-23`), so clicking Share does nothing and offers no sign-in prompt. Reaction/
   comment/bookmark do not exhibit this. Cosmetic, not a journey break. Confidence: confirmed-in-code.

2. **Direct repost does not persist a note to `bn_shares.note`** — low severity / observation.
   "Quote" prefills the composer and publishes a normal post (`store.js:1973-1988`); the bare
   `repost` posts to `/posts/{id}/share` with no `content`, leaving `note` empty. The REST layer
   accepts and sanitizes `content` → `note` (`ShareController.php:83-85`), so the column is wired and
   reachable by REST/app clients — the web UI simply chooses quote = new post, repost = bare reshare.
   Consistent and usable. Confidence: confirmed-in-code.

Neither gap stops the core repost journey.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

Optional, non-blocking: bind the Share button render to `$can_share` and have `openShare` toast a
sign-in prompt when no modal is present, so guests get a nudge instead of a dead button. Not required
for conformance.
