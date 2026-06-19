# BuddyNext Flow-Audit — Action List (pinpoint)

**Date:** 2026-06-19 · **Source:** `audit/flow-audit-report.md` (wppqa flow-audit, free+Pro pair).
**Result:** the audit is clean — **1 confirmed code issue.** Everything else is either clean or a runtime-confirm advisory (not a task). Re-run after fixing:
```
cd ~/.mcp-servers/wp-plugin-qa-mcp-server && npm run build && \
  node build/flow-audit-cli.js /Users/vapvarun/dev/repos/buddynext /Users/vapvarun/dev/repos/buddynext-pro
```

## Fix (the only confirmed issue)

- **T1 — Consolidate the duplicated cursor decoder.** Identical implementation in two places:
  - `BuddyNext\Feed\FeedService::decode_cursor` — `includes/Feed/FeedService.php:1095`
  - `BuddyNext\Hashtags\HashtagService::decode_feed_cursor` — `includes/Hashtags/HashtagService.php:275`
  **How:** extract one canonical cursor codec (e.g. a small `CursorCodec` helper or a shared trait/Core service) and have both call it; delete the duplicate body. **Done when:** the audit's `dup-function` finding is gone (re-run → 0 errors).

That's it. The deterministic checks are all clean:
- **canonical-usage: 0** — no raw SQL/`$wpdb`/`WP_Query` in templates (service-layer discipline holds).
- **template-contract: 0** — every template's vars are supplied by its loader.
- **rest-js-contract: 0** and **rest-flow broken-read: 0** — JS reads match REST response shapes (no envelope mismatches).

## Advisories — confirm via runtime, NOT tasks

These are signals static analysis cannot confirm (PHP dynamic dispatch `$this->{$var}()`, computed template names `"panel-{$tab}.php"`, hook callbacks, and external consumers — themes/app/other plugins — are invisible to a static graph). Each is ONE advisory line in the report, deliberately not a per-item task pile. Do not bulk-act on them; confirm against a seeded runtime/coverage run before removing anything.

- **Dead code (orphan advisory):** N symbols are unreferenced internally — mix of real dead code (e.g. the reverted `/jobs/` bridge left `inject_jobs_nav_item` etc.) and live code reached dynamically. Confirm with coverage which never execute.
- **Unloaded templates (advisory):** N template parts have no statically-detectable loader — many are loaded by computed name (`space-settings-panel-{$tab}.php`) or `load_part()`. Confirm which never render.
- **Unresolved REST shapes (advisory):** N routes return a hydrated/dynamic shape the static check can't read — not a defect, just uncheckable statically.
- **Undocumented routes (logic-flow advisory):** routes not in `audit/journeys.json` — decide which user-facing ones deserve a journey + cert oracle for go-live coverage.

**To actually clear the advisories:** run the functional cert + a browser/coverage walk of the core journeys on a seeded site — that's the runtime signal these need. Static analysis has done its job; the rest is runtime.
