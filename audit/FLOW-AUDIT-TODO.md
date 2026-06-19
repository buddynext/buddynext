# BuddyNext Flow-Audit & Go-Live — Action List (current, pinpoint)

**Date:** 2026-06-19 · **Source:** `audit/flow-audit-report.md` (wppqa flow-audit) + functional cert + browser walk.
**Status:** ✅ **Static gate GREEN (0 errors)** · ✅ **Functional cert PASSED (0 failed)** · ✅ **Core journeys walked, 0 console errors.** One real defect found and fixed. Remaining items below are pinpoint and labeled by where the work lands.

Re-run the static audit: `cd ~/.mcp-servers/wp-plugin-qa-mcp-server && npm run build && node build/flow-audit-cli.js /Users/vapvarun/dev/repos/buddynext /Users/vapvarun/dev/repos/buddynext-pro`
Re-run the functional cert: `wp buddynext cert` (or the `wppqa_certify` MCP tool, wp_root = the site public dir).

---

## ✅ Done (do not redo)

- **T1 — Duplicate cursor decoder consolidated.** `FeedService::decode_cursor` ↔ `HashtagService::decode_feed_cursor` → one `BuddyNext\Core\CursorCodec` (`includes/Core/CursorCodec.php`). `dup-function: 0`. (commit `1c5238f`)
- **Cert oracles +3** (hashtags, verification, announcements), each proven to flip; remaining 8 surfaces documented in `cert-oracles.json` as not-toggle-cert-able by design. Cert: 0 failed, holes 11→8. (commit `1595903`)
- **Functional cert green** — 57 passed / 0 failed (no route fatals; every gated toggle with an oracle enforces).
- **Browser walk green (0 console errors)** — activity feed, members (49), spaces (7), messages, dark-mode toggle all render + populated on the seeded site.
- **Static checks all clean** — canonical-usage 0 (no raw SQL in templates), template-contract 0, rest-js-contract 0, rest-flow broken-read 0.

---

## 🔲 Open tasks (pinpoint)

- **[verify] Walk the signup → onboarding front door.** The one core journey not yet walked — registration is off (`users_can_register=0`). Temporarily enable registration (or BN `buddynext_reg_mode`), walk register → email verify → onboarding → first feed, light + dark, confirm 0 console errors and verification email lands (Mailpit at http://localhost:10010), then restore the toggle. Highest-value remaining check (first impression).
- **[BN] Wire the audit + cert into the release gate.** Add to `bin/build-release.sh` / pre-tag checklist: run `flow-audit-cli` and `wp buddynext cert`, fail the tag on unbaselined errors (mirrors the contract-audit gate). Keeps regressions out.
- **[MCP] Register `wppqa_flow_audit` as an MCP tool** in `wp-plugin-qa-mcp-server/src/server.ts` (currently CLI-only) so it's one call alongside the other `wppqa_*` checks.
- **[MCP] Resolve calls into `libs/`/`vendor/` as call-targets** (`src/flow/build.ts`) — minor orphan/flow precision gap (design §"Our-code vs third-party"); does not affect correctness of current findings.
- **[MCP] Close minor test gaps** — direct `writeGraph`/`readGraph` tests; rest-flow `shapeUnresolved` branch test; canonical `passed` accounting → files-with-issues; `tplFiles` Set lookup.
- **[push] Push both repos** — BuddyNext `master` and `wp-plugin-qa-mcp-server` `master` (kept local per request).

---

## Advisories — runtime-confirm, NOT code tasks

Static analysis cannot confirm these (dynamic dispatch `$this->{$var}()`, computed template names, hook callbacks, external theme/app consumers). Each is ONE advisory line in the report. The browser walk already confirmed the feed/members/spaces/messages surfaces are live; what remains is coverage to pin the exact dead items before removing anything.

- **Dead code (orphan):** unreferenced symbols incl. real leftovers (e.g. reverted `/jobs/` bridge `inject_jobs_nav_item`). Confirm via coverage which never execute, then delete.
- **Unloaded templates:** parts with no statically-detectable loader (many loaded by computed name / `load_part()`). Confirm which never render.
- **Unresolved REST shapes / undocumented routes:** informational; add journeys + cert oracles for the user-facing routes you want gated for go-live.

## Not a code question (product)
- **Feature parity vs BuddyPress + migration path** — untested by this audit; a product/scope decision, not a defect.
