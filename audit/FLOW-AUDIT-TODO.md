# BuddyNext Flow-Audit — Remaining Work (plugin-specific)

**Date:** 2026-06-19
**Source:** `audit/flow-audit-report.md` (committed) — produced by the wppqa flow-audit suite over the BuddyNext **free + Pro** pair.
**Scope:** BuddyNext/BuddyNext-Pro code findings only. Tool-level improvements live in the MCP repo's handoff, not here.

## Re-run the audit (after any fix)

```
cd ~/.mcp-servers/wp-plugin-qa-mcp-server && npm run build && \
  node build/flow-audit-cli.js /Users/vapvarun/dev/repos/buddynext /Users/vapvarun/dev/repos/buddynext-pro
```

Current: **6 gate-failing errors** + warning backlog. `template-contract`, `rest-js-contract`, `canonical-usage` are clean (templates carry no raw SQL). Suppressions live in `audit/.flow-audit-baseline.json` (only confirmed false-positives + one intentional stub).

## Wave 0 — Publish · ~2 min
- **B0.1 Push the BuddyNext repo.** `master` has `0064ab2` (report + baseline + `.gitignore` for `audit/flow.json`). `git push`. Keep Pro in lockstep.

---

## Wave 1 — Fix the real defects (go-live blockers) · 6 errors

Verify root cause before changing. The guard dups span the free↔Pro boundary — consolidate to a shared helper and keep free+Pro in lockstep.

- **B1.1 Consolidate `require_logged_in` (4 identical copies).**
  - `BuddyNext\Auth\TwoFactorController::require_auth` — includes/Auth/TwoFactorController.php:117 (free)
  - `BuddyNextPro\Search\Controllers\SavedSearchController::require_logged_in` — includes/Search/Controllers/SavedSearchController.php:302
  - `BuddyNextPro\Realtime\AuthController::require_logged_in` — includes/Realtime/AuthController.php:105
  - `BuddyNextPro\Analytics\Controllers\AnalyticsController::require_logged_in` — includes/Analytics/Controllers/AnalyticsController.php:388
  - → One canonical guard (shared trait / Pro base controller); route the rest through it. Acceptance: `dup:610151f2…` gone on re-run.
- **B1.2 Consolidate `require_admin`/permission-check (3 identical copies).**
  - `BuddyNext\Admin\SlugCheckController::permission_check` — includes/Admin/SlugCheckController.php:82 (free)
  - `BuddyNextPro\Realtime\AuthController::require_admin` — includes/Realtime/AuthController.php:114
  - `BuddyNextPro\Analytics\Controllers\AnalyticsController::require_admin` — includes/Analytics/Controllers/AnalyticsController.php:352
  - Acceptance: `dup:f51c9b83…` gone.
- **B1.3 Consolidate the cursor decoder (2 identical copies).**
  - `BuddyNext\Feed\FeedService::decode_cursor` — includes/Feed/FeedService.php:1095
  - `BuddyNext\Hashtags\HashtagService::decode_feed_cursor` — includes/Hashtags/HashtagService.php:275
  - → One shared cursor helper. Acceptance: `dup:fdbefde7…` gone.
- **B1.4 Verify the 3 `/buddynext/v1/spaces` reads** (`category_id`, `parent_id`, `slug`).
  - JS reads these near the route URL but `includes/Spaces/SpaceController.php`'s response shape doesn't list them. Per key: real envelope gap → add to the response; OR a form-field/other-object read → confirm false positive and add to `audit/.flow-audit-baseline.json` with a reason. Check the spaces JS under `assets/`.

**Wave acceptance:** re-run exits 0 (every error fixed or baselined-with-reason).

---

## Wave 2 — Triage the warning backlog · BuddyNext finding lists (don't gate)

Treat as candidates, not certainties.

- **B2.1 Orphan candidates (1331).** Symbols with no resolved inbound call/registration/load/read. Over-reports because dynamic dispatch (`$obj->m()` of unknown type, `call_user_func`, variable callbacks) isn't resolved (the MCP-side W2.2 will shrink this further). Walk the list: delete genuinely dead methods/functions/templates; baseline the rest by dispatch pattern. Start with the highest-signal subset — top-level procedural functions and templates flagged never-consumed.
- **B2.2 Template-usage never-loaded (16).** Template files on disk with no `buddynext_get_template()` loader. Short, high-signal — delete dead templates or wire the missing loader.
- **B2.3 rest-flow dead-output (362).** Response keys returned that no JS/SSR consumer reads. Spot-check for fields safe to drop or consumers never wired.
- **B2.4 logic-flow coverage (216).** Routes absent from `audit/journeys.json` + journeys with no resolving route. Decide which user-facing routes deserve a journey + cert oracle for go-live coverage.

---

## Wave 3 — Tooling improvements this audit surfaced (code lands in the MCP repo `wp-plugin-qa-mcp-server`)

These make the BuddyNext audit trustworthy enough to finish Wave 1/2. The change happens in the MCP server code, but they are tracked here because the BuddyNext run surfaced them and they gate BuddyNext go-live. (They also benefit every other plugin — generalize when picked up.)

- **B3.1 Improve orphan dynamic-dispatch resolution** — cuts the 1331 (B2.1) before triage. Capture `call_user_func`/`call_user_func_array` array-callables; resolve `$obj->m()` where `$obj` is a typed property/param (light `@var`/constructor-promotion inference). Files: `php-ast/extract-graph.php`, `src/flow/php-graph.ts`.
- **B3.2 Improve `extractJsReads` precision** — the source of the rest-flow broken-read candidates (B1.4) and dead-output noise (B2.3). Scope JS reads to the actual response binding (`const data = await res.json()`) instead of any `obj.prop` near the route URL. File: `src/flow/rest-shapes.ts`.
- **B3.3 Resolve calls into `libs/`/`vendor/`** — `buildGraph` ignores `resolveScanScope().thirdParty`, so our calls into bundled libs drop their edge (minor orphan/flow precision). Ingest third-party symbols as call-target-only nodes. Files: `src/flow/build.ts`, `php-graph.ts`.
- **B3.4 Cap/summarize warning-level findings in the report** — keeps `flow-audit-report.md` reviewable (was ~1.5 MB before the orphan fix). File: `src/flow/report.ts`.
- **B3.5 Close the logged Minor gaps** — `writeGraph`/`readGraph` direct tests; rest-flow `shapeUnresolved` branch test; canonical `passed` accounting + `tplFiles` Set lookup; `extractShapeKeys` nested-array handling. All small.
- **B3.6 Register `wppqa_flow_audit` as an MCP tool + wire the BuddyNext release gate** — expose the runner in `server.ts`, then add the audit to BuddyNext's `bin/build-release.sh` / pre-tag checklist (fail the tag on unbaselined errors, mirroring contract-audit).

## Notes
- The 1331 orphan count already reflects the method-call-graph refinement (2777 → 1331). Do B3.1 before B2.1 triage to shrink it further.
- Suite usage/methodology (how to run, triage, baseline, refine a checker) lives in the MCP repo as generic instructions — not a task backlog.
