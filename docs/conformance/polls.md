# Conformance — Polls (Activity Feed post type)

**Feature:** Polls (free)
**Spec ref:** `docs/specs/features/02-activity-feed.md` (post type `poll`, lines 33, 113; "2–5 options, vote inline, optional end date")
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/activity

---

## Core happy-path journey

A member opens the home feed, creates a poll via the composer, the poll renders as a card, any member taps an option to vote inline, and the bars update with live counts.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Member opens composer, toggles to Poll mode | ui | wired | `templates/partials/composer.php:213-214` (poll tool button `data-wp-on--click="actions.togglePoll"`, `aria-pressed="state.isPoll"`); panel `:174-199` |
| 2 | Toggle flips composer type; option inputs reveal | store | wired | `assets/js/feed/store.js:1421` (`togglePoll` flips composerType); `:1233-1236` (`isPoll`/`isNotPoll` getters drive `data-wp-bind--hidden`) |
| 3 | Submit collects ≥2 option values, posts to REST | store | wired | `assets/js/feed/store.js:1470-1485` (collects `.bn-composer__poll-option`, min-2 guard, `body.options`), `:1488` POST `/posts` |
| 4 | REST create accepts `type=poll` + `options` | rest | wired | `includes/Feed/PostController.php:98,105` (`type`, `options` params) → `create()` `:115` |
| 5 | Service inserts post row + option rows | service | wired | `includes/Feed/PostService.php:167-169` (`insert_poll_options`); `:553-578` writes `bn_poll_options` |
| 6 | Tables exist | db | wired | `includes/Core/Installer.php:449-468` (`bn_poll_options`, `bn_poll_votes` with `UNIQUE(post_id,user_id)`) |
| 7 | Feed hydrates poll options into card | service | wired | `templates/feed/home.php:497-502` (poll-type rows hydrate via `post_service->get`); `PostService.php:617,629` (`fetch_poll_options`) |
| 8 | Card renders options, %, my-vote, totals | ui | wired | `templates/partials/post-card.php:236-256` (builds `poll_options_ctx`, `my_voted_option_id`, context `:320-342`); `templates/parts/post-body.php:214-266` (vote buttons, fill bars, totals) |
| 9 | Member taps option → vote action | ui→store | wired | `post-body.php:239` (`data-wp-on--click="actions.votePoll"`); `store.js:1020-1047` POSTs `/posts/{id}/vote` with `pollNonce` |
| 10 | REST vote endpoint + auth | rest | wired | `includes/Feed/PollController.php:31-39,68-97` (`POST /posts/{id}/vote`, `require_auth`) |
| 11 | Service records vote, switches/toggles, denormalises count | service | wired | `includes/Feed/PollService.php:37-130` (UNIQUE-enforced single vote, switch, toggle-off, `do_action buddynext_poll_voted`) |
| 12 | Results returned + bars update reactively | store→ui | wired | `PollController.php:88-96` returns `results`; `store.js:1035-1045` recomputes pct; `post-body.php:246,252,259` reactive bindings |

**First break:** none — journey complete.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|-----------|----------|
| Spec lists "optional end date" for polls; column `bn_poll_options.end_date` exists but is never written, read, or surfaced in composer/REST/card. Polls cannot be time-limited. | low | confirmed-in-code | `includes/Core/Installer.php:455` (column present); no `end_date`/`ends_at` writer in `PostService.php` `insert_poll_options` `:553-578`, no input in `composer.php`, no read in `PollService.php` |

This is an optional, secondary spec attribute — not part of the create→vote→see-results happy path. It does not stop any journey, so the verdict stays `usable-leave-as-is`. The `end_date` column is also semantically misplaced (per-option, not per-poll); if implemented later it likely belongs on `bn_posts` or as a single per-poll value.

No other gaps found. Voting is auth-gated (anonymous users get the read-only card; the vote button only carries a nonce when logged in — `post-card.php:233`), single-vote integrity is enforced at the DB (`one_vote_per_user`) and re-checked in service, and vote switching/toggle-off behave per the documented contract.

App/REST clients: fully served — `GET /posts/{id}/poll` (public), `POST /posts/{id}/vote`, `GET /posts/{id}/my-vote` all exist independently of the web UI (`PollController.php:31-59`).

---

## Minimal refactor plan

(empty — feature is usable; the end-date item is an optional spec attribute, not a journey break)
