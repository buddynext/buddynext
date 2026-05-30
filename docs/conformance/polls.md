# Conformance — Polls (Feed)

**Feature:** Polls (post type `poll`)
**Repo:** free
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Post Types → `poll`; Data Stored → `bn_poll_options`, `bn_poll_votes`)
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/activity

---

## What the spec asks for

A member can create a poll post (2–5 options) in the composer, the poll renders inline in the feed with options + vote counts, and any logged-in member can vote inline (one vote per poll, vote switching / toggle-off). Optional end date noted in spec.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Composer "Poll" toggle reveals option inputs | ui | wired | `templates/partials/composer.php:181,214` (`data-wp-on--click="actions.togglePoll"`), inputs `:187-198` |
| Toggle action flips composerType | store | wired | `assets/js/feed/store.js:1409` (`ctx.composerType = ... 'poll'`) |
| Submit collects ≥2 options, POSTs `type=poll` + `options[]` | store | wired | `assets/js/feed/store.js:1458-1472` → POST `/posts` `:1476` |
| `POST /posts` accepts `options` param | rest | wired | `includes/Feed/PostController.php:105,115` |
| Create persists post + options atomically | service | wired | `includes/Feed/PostService.php:81-95,156-157,515-540` (`insert_poll_options`) |
| Options stored | db | wired | `bn_poll_options` insert `includes/Feed/PostService.php:530-538` |
| Feed query hydrates `poll_options` for poll rows | service | wired | `templates/feed/home.php:486-491` (`post_service->get()`), `PostService.php:578-579,591-616` |
| Poll renders inline (options, %, fill bar, total) | ui | wired | `templates/parts/post-body.php:214-264`; card ctx `templates/partials/post-card.php:236-262,338-341` |
| Member's existing vote pre-marked | service/ui | wired | `post-card.php:254` (`polls->user_vote`), `post-body.php:232` (`is-voted`) |
| Click option fires vote action | ui→store | wired | `post-body.php:239` (`data-wp-on--click="actions.votePoll"`); `store.js:1008-1036` |
| `POST /posts/{id}/vote` records vote | rest | wired | `includes/Feed/PollController.php:31-39,68-97` |
| Vote recorded, switch/toggle handled, counts updated | service/db | wired | `includes/Feed/PollService.php:37-130` (UNIQUE-key one-vote, switch, toggle-off) |
| Results returned, UI updates live | store | wired | `store.js:1023-1033` (re-maps `pollOptions`, totals, pct) |
| Routes hooked at runtime | rest | wired | `includes/REST/Router.php:69` (`PollController`), `Router.php:51` (`rest_api_init`), booted `includes/Core/Plugin.php:179`; services `Plugin.php:630,633` (`post_service`, `polls`) |

## First break

none — journey complete. Create, render, and vote are wired across ui → store → rest → service → db for both the web (Interactivity API) and REST/app clients.

## UX gaps

- **Optional poll end date not implemented (spec mentions "optional end date").** Severity low, confidence confirmed-in-code. No `ends_at`/poll-close column or expiry enforcement exists (`grep` for `ends_at|poll_end|expires` finds only `site_pin_expires_at` for announcements; composer has no date input; `PollService::vote` does not gate on a close time). Polls are effectively open-ended. This does not break the core journey — it is a missing optional sub-feature.
- **Vote network failure is silent.** Severity low, confidence confirmed-in-code. `store.js:1035` swallows the catch with an empty block and there is no error toast on a non-`ok` response (contrast with composer submit which surfaces `errorMessage`). A failed vote gives the user no feedback. Cosmetic; journey still completes on success.

## Minimal refactor plan

None required for usability. The two gaps above are optional/cosmetic and do not stop the journey; per the prime directive they are left as-is unless the spec owner promotes the end-date sub-feature to required.
