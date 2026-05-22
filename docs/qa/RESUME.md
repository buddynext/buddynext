# Resume here — open work after 2026-05-22

This file is the canonical "where are we" doc. Read it first when picking up BuddyNext work in a fresh session.

## Environment

| Setting | Value |
|---|---|
| Dev site | `http://buddynext-dev.local` (Local by Flywheel) |
| Plugin path | `/Users/vapvarun/dev/repos/buddynext/` (symlinked into the Local site) |
| Pro path | `/Users/vapvarun/dev/repos/buddynext-pro/` |
| Test user | `varundubey` / password `password` (admin role) |
| Free repo + branch | `buddynext/buddynext` `master` |
| Pro repo + branch | `buddynext/buddynext-pro` `main` |
| Free latest tag | `0.3.0-beta1` (do NOT tag without journey coverage — see `docs/qa/HOW-TO-RUN.md`) |
| Pro latest tag | `0.4.0-beta1` |

**Sandbox push gate**: direct push to `master` / `main` is denied for sub-agents. Every dispatched agent pushes to a feature branch; the controller merges + pushes master locally.

## State snapshot (2026-05-22)

20 of 22 PRODUCTION-READINESS.md rows are `prod`. See `docs/qa/SESSION-2026-05-22.md` for the headline outcome per surface.

E2E baseline: **149 passed / 0 failed / 61 fixme-skipped** across desktop / iPad / mobile against `buddynext-dev.local`. Run with `BN_BASE_URL=http://buddynext-dev.local npm run test:e2e`.

## Open tasks (sorted by impact)

### Quick fixes (can land in 1 short wave)

**#47 — Feed e2e: fix 2 spec failures**
- `tests/e2e/feed/comment-threading.spec.ts:75` (edit own comment)
- `tests/e2e/feed/composer-drafts.spec.ts:9` (drafts survive refresh)
- Both verified manually live — spec timing/selector issues, not product bugs.
- Re-run live, adjust assertions, get back to 0 failed on desktop.

**#50 — Notifications follow-ups**
- Header notification dropdown — canonical Interactivity-store-driven component (not the legacy ad-hoc handler in `assets/js/shell/extras.js`).
- Sound MP3 asset — `D2` seam is shipped; need to ship `assets/sounds/notif.mp3` + register URL in `AssetService::localize_shell_data()`.
- Anchor row semantics — wrap `.bn-notif-row` body in `<a href>` so Ctrl+click new-tab + screen reader "link" announcement work. Current JS-only `data-wp-on--click="actions.markRead"` works but is less accessible.

### Larger surfaces still unwalked

- **Moderation** queue + suspend/restore + report review. Code exists; needs the deep-walk loop (see `project_buddynext_production_walks` memory).
- **Admin settings** — every tab in `wp-admin/admin.php?page=buddynext-settings`. Features tab is the only one verified via the matrix.
- **Pro feature completion walks** — AI moderation, AI feed, Stripe membership, Realtime, Push, Whitelabel. Each Pro phase shipped but hasn't had a "polish" pass against live data.

### Environment blockers (need physical action before unblocking)

- **WPMediaverse install on dev site** → flips matrix row 12 from `prod-pending-verify` to `prod`. One-line install: `cp -R ~/dev/repos/wpmediaverse "/Users/vapvarun/Local Sites/buddynext-dev/app/public/wp-content/plugins/" && wp plugin activate wpmediaverse`. Then re-walk `/messages/`.
- **`users_can_register=1` on dev site** → removes the 2 fixme'd auth signup tests. `wp option update users_can_register 1`.

### iPad + mobile e2e adaptation

- Desktop suite green (49/0/21). iPad + mobile each pass 47-50 but need spec-by-spec viewport adaptation if you want all 210 tests passing across all 3 projects. Currently 149/0/61 combined because mobile-specific selectors aren't tuned.

## How to resume

```bash
# 1. Site up
cd "/Users/vapvarun/Local Sites/buddynext-dev"
# (Local by Flywheel — start the site via GUI)

# 2. Verify live
curl -sI http://buddynext-dev.local/ | head -1
# Expect: HTTP/1.1 200 OK

# 3. Pull latest
cd /Users/vapvarun/dev/repos/buddynext/ && git pull --rebase origin master
cd /Users/vapvarun/dev/repos/buddynext-pro/ && git pull --rebase origin main

# 4. Re-run baseline
cd /Users/vapvarun/dev/repos/buddynext/
BN_BASE_URL=http://buddynext-dev.local npm run test:e2e:desktop
# Expect: 49 passed / 0 failed / 21 fixme-skipped

# 5. Pick a task
#    - To resume #47:  cd into tests/e2e/feed/, run the 2 failing specs in isolation, fix assertions
#    - To resume #50:  start with the header dropdown — see assets/js/shell/extras.js for the legacy handler
#    - To walk Moderation: navigate /moderation/ logged in as varundubey, follow the production-walks memory pattern
```

## Hard rules per session

1. **Never run `composer dump-autoload` from inside a git worktree.** It bakes worktree paths into the autoload classmap and breaks the live site when the worktree is removed. Always run from canonical `/Users/vapvarun/dev/repos/buddynext/`. See `buddynext-worktree-trap` memory for the symptom + fix.
2. **No em-dashes / no emoji / no `Co-Authored-By: Claude`** in any commit message or written output.
3. **Sub-agents push to feature branches**, never to `master` or `main`. Controller merges + pushes master.
4. **No tag without journey coverage.** User-defined hard gate.
5. **Direct-to-master is fine** on the controller side (this is the user's policy for these repos).
6. **Don't trust agent "already shipped" claims** without grepping the template + verifying live. Sparse dev data hides features behind `if ($count > 0)` guards (see `buddynext-agent-overclaim-pattern` memory).

## Critical files to know

| Concern | File |
|---|---|
| Per-surface gap inventory | `docs/qa/PRODUCTION-READINESS.md` |
| Journey catalogue | `docs/qa/JOURNEYS.md` |
| E2E runbook | `docs/qa/HOW-TO-RUN.md` |
| This session's outcomes | `docs/qa/SESSION-2026-05-22.md` |
| Notification message catalogue | `docs/specs/NOTIFICATION-MESSAGES.md` |
| Architecture | `docs/specs/MODULAR-ARCHITECTURE.md` |
| Scale rules | `docs/specs/SCALE-CONTRACT.md` |
| REST contract | `docs/specs/REST-FRONTEND-CONTRACT.md` |
| Free Plugin bootstrap | `includes/Core/Plugin.php` (priority 15 on `plugins_loaded`) |
| URL routing | `includes/Core/PageRouter.php` |
| Audit screenshots from this session | `audit-2026-05-22/` |
