# Conformance — Polls

**Feature:** Polls (post type `poll` within the Activity Feed)
**Repo:** free
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Post Types → `poll`, line 33; Data Stored → `bn_poll_options`, `bn_poll_votes`, lines 112–113)
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Date:** 2026-05-31

## Verdict: usable-leave-as-is

The poll journey (create a poll → it renders in the feed → vote inline → see live results) is wired end to end across UI → store → REST → service → DB for both the web journey and the REST/app client. No usability break proven.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Composer exposes a Poll tool toggle and 2–5 option inputs | ui | wired | `templates/partials/composer.php:176-200,238-241` (poll panel, `actions.togglePoll`) |
| Toggle flips composer to poll type | store | wired | `assets/js/feed/store.js:1445` (`composerType = 'poll'`), `:201` `togglePoll` |
| Submit collects option inputs, validates min 2, sends `type:'poll'` + `options[]` | store | wired | `assets/js/feed/store.js:1514-1528` (collect `.bn-composer__poll-option`, min-2 guard), `:1540` POST `/posts` |
| Create-post endpoint accepts `options` param | rest | wired | `includes/Feed/PostController.php:105` (`'options' => $request->get_param('options')`) |
| PostService validates 2–5 options, writes post + options atomically | service | wired | `includes/Feed/PostService.php:82-96` (validation), `:175-176` + `:620-648` (`insert_poll_options`) |
| Poll + options + votes persisted | db | wired | `bn_posts` (type=poll), `bn_poll_options`, `bn_poll_votes` per spec `02-activity-feed.md:111-113`; writes at `PostService.php:635`, `PollService.php:97-105` |
| Home feed hydrates poll_options for poll rows | service | wired | `templates/feed/home.php:497-502` (calls `post_service->get()`), `PostService.php:683-684,696-718` (`fetch_poll_options`) |
| Post card renders question, options, %, total, voted state | ui | wired | `templates/partials/post-card.php:239-262` (ctx build), `templates/parts/post-body.php:214-264` (option buttons + fill bar + totals) |
| Vote button bound to store action | ui | wired | `templates/parts/post-body.php:239` (`data-wp-on--click="actions.votePoll"`) |
| votePoll POSTs to vote endpoint with nonce, updates results in place | store | wired | `assets/js/feed/store.js:1020-1041` (POST `/posts/{id}/vote`, `X-WP-Nonce`, body `option_id`, re-renders `pollOptions`/totals) |
| Vote endpoint (auth-gated) casts vote, returns results | rest | wired | `includes/Feed/PollController.php:31-39,68-97`; auth `:134-144` |
| PollService records vote, one-vote-per-user, switch & toggle-off, fires action | service | wired | `includes/Feed/PollService.php:37-130` (UNIQUE-backed switch/toggle, `do_action('buddynext_poll_voted')`) |
| Read paths: public results + per-user vote | rest/service | wired | `PollController.php:41-59,105-127`; `PollService.php:138-187` |
| Feed bundle enqueued on activity/explore/profile/space routes | ui | wired | `includes/Core/PageRouter.php:630,654,661,669`; module map `includes/Core/AssetService.php:315` (`@buddynext/feed` → `feed/store`) |

Namespaces line up: post-card markup uses `buddynext/post-card` (`post-card.php:320`) and that store owns `votePoll` (`store.js:628,1020`); composer markup uses `buddynext/post-composer` (`composer.php:83`) and that store owns `togglePoll`/`submit` (`store.js:1242,201,250`).

## First break

none — journey complete.

## UX gaps

None that break the journey. Notes (not gaps):

- Composer `submit()` requires non-empty `content` before sending (`store.js:253` in the post-composer store). For a poll this makes the poll **question** mandatory — consistent with how `post-body.php:216` renders the question from `content`. Reasonable behavior, not a break. Confidence: confirmed-in-code. Severity: low.
- Optional poll **end date** is named in the spec (`poll … optional end date`, `02-activity-feed.md:33`) but there is no end-date input in the composer poll panel and no expiry column wired in `insert_poll_options`. This is a spec-listed *optional* sub-feature, not part of the core create→vote→results happy path, so it does not stop the journey. Confidence: confirmed-in-code. Severity: low.

## Minimal refactor plan

Empty — feature is usable as-is. (If the optional poll end-date is later prioritized, it is additive: one date input in the composer poll panel, an `ends_at` column/handling in `insert_poll_options`, and a closed-state guard in `PollService::vote`. Not required for the locked happy path.)
