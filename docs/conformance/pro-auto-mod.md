# Conformance: Auto / Rule-based Moderation (Pro)

**Feature:** Auto / Rule-based Moderation (repo: buddynext-pro)
**Spec ref:** `buddynext/docs/specs/features/09-moderation.md` (Automated Safeguards) + journey `buddynext-pro/docs/journeys/auto-moderation.md` + `buddynext/docs/specs/features/FREE-PRO-CONTRACT.md` filters #9/#10
**Verdict:** partial-needs-wiring
**Live-walk URL:** http://buddynext-dev.local/wp-admin/

---

## Summary

Rule CRUD (admin UI + REST), the `block` keyword path, the `link_block` path, the
`rate_limit` path, the `threshold_remove` (post-report) path, enable/disable, and delete
are all fully wired and work end-to-end. One documented happy-path step does not match the
code: journey **Part 3** (severity=`flag`) expects the post to be **created** and a report
auto-inserted into `bn_reports`. The code instead **rejects** the post (HTTP 202 `WP_Error`)
and creates **no report**. This is proven by reading the code, not inferred from absence.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin opens Moderation Rules page (`buddynextpro-mod-rules`) | ui | wired | `buddynext-pro/includes/Admin/ModRulesAdmin.php:107` (add_submenu) + `:92` (AdminHub tab) |
| 2 | "Add New Rule" form posts to admin-post handler | ui | wired | `ModRulesAdmin.php:369` (form) + `:124` (handle_create, nonce+cap) |
| 3 | `create_rule()` validates + inserts into `bn_mod_rules` | service | wired | `buddynext-pro/includes/Moderation/RulesService.php:64`, insert `:87`; table `buddynext-pro/includes/Core/Installer.php:442` |
| 4 | Free fires `buddynext_safeguard_check` on post create | service | wired | `buddynext/includes/Moderation/SafeguardService.php:75`; called from `buddynext/includes/Feed/PostService.php:105` |
| 5 | Pro hooks filter #9, evaluates rules | service | wired | `buddynext-pro/includes/Moderation/SafeguardIntegration.php:34` + registered `Core/Plugin.php:172` |
| 6 | severity=`block` → 422 WP_Error → post rejected | service | wired | `RulesService.php:427` returns 422; `PostService.php:114-116` returns the error |
| 7 | severity=`flag` → post created **and** report inserted | service | **broken** | `RulesService.php:441` returns 202 WP_Error; `PostService.php:110-116` rejects any non-`pending_review` error; no `bn_reports` insert anywhere on this path |
| 8 | Disable rule → toggle handler sets `enabled=0` → post succeeds | service | wired | `ModRulesAdmin.php:209` + `RulesService.php:578`; `get_enabled_rules_for_safeguard()` filters `enabled=1` `:360` |
| 9 | `threshold_remove` via `buddynext_moderation_auto_actions` | service | wired | `buddynext/includes/Moderation/ModerationService.php:138` fires filter; `buddynext-pro/includes/Moderation/AutoActionsIntegration.php:32` + `RulesService.php:298` |
| 10 | REST surface (`buddynext-pro/v1/mod-rules`, admin-gated) | rest | wired | `buddynext-pro/includes/Moderation/Controllers/ModRulesController.php:57` (routes), `:244` (manage_options); registered `Core/Plugin.php:289` |
| 11 | `bn_mod_rules` table exists | db | wired | `buddynext-pro/includes/Core/Installer.php:442` |

---

## First break

**Step 7 — journey Part 3 (severity=`flag`).** The journey states the post should be created
(200/201) and a report auto-generated in `bn_reports` with `status=pending`. In code:

- `RulesService::evaluate_keyword_block()` returns a `WP_Error` (`bnpro_keyword_flagged`,
  status 202) for `flag` severity — `RulesService.php:441-445`.
- That error flows up through `SafeguardIntegration::apply_pro_rules()` unchanged.
- `PostService::create()` treats only the `pending_review` error code as non-fatal; every
  other `WP_Error` (including `bnpro_keyword_flagged`) is returned to the caller, aborting
  the insert — `PostService.php:110-116`.
- There is no code path that inserts into `bn_reports` from a safeguard-filter result. The
  only report-creating path is `ModerationService::report()`, which fires the *separate*
  filter #10 (`buddynext_moderation_auto_actions`) and only runs when a report already
  exists. `flag` never reaches it.

Net effect: with severity=`flag`, the post is **rejected** and **no report is created** —
the opposite of the locked journey's stated outcome. `block` and `disable`/`delete`
(Parts 2 and 4) behave exactly as the journey describes.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| severity=`flag` rejects the post instead of publishing it; the journey expects publish + report | high | confirmed-in-code | `RulesService.php:441` (202 error) vs `PostService.php:110-116` (any non-`pending_review` error rejects) |
| severity=`flag` creates no `bn_reports` row; reviewers never see flagged content | high | confirmed-in-code | No `bn_reports` insert on safeguard path; report insert only in `ModerationService::report()` (`ModerationService.php` `report()`) via filter #10 |
| `warn` severity is silently a no-op (returns true, no log) despite admin help text "allow but log" | low | confirmed-in-code | `RulesService.php:435-438` returns true with a comment that logging is left to callers; no logger invoked; UI text `ModRulesAdmin.php:477` |

The admin CRUD UI, REST surface, `block` enforcement, `rate_limit`, `threshold_remove`, and
toggle/delete are all correctly wired — no gaps there for either the web admin journey or the
REST client.

---

## Minimal refactor plan

Reuse existing code; do not rewrite the working CRUD/REST/`block` paths.

1. Decide the canonical `flag` semantic. The locked journey says: publish the post **and**
   create a pending report. Align the code to that (the journey is the UX intent and the
   FREE-PRO-CONTRACT line 136 also frames Pro as *appending* a "flagged" result, not hard-rejecting).
2. In `PostService::create()` (`buddynext/includes/Feed/PostService.php:110-116`), extend the
   non-fatal handling so a "flagged" result is treated like `pending_review` — let the post
   save (status `published` or `pending` per product call) instead of returning the error.
   The cleanest seam: have the safeguard filter return a structured allow-with-flag result
   rather than a blocking `WP_Error`, so Free can distinguish "block" from "flag". This keeps
   the block path (422) untouched.
3. On that flagged result, call the existing Free report path
   (`ModerationService::report()`) with `object_type=post`, the new post id, an automated
   reason, and reporter id 0 — so the report lands in `bn_reports` with `status=pending` and
   filter #10 still gets a chance to auto-action it. Reuse the existing method; do not write
   a new insert.
4. Update `evaluate_keyword_block()` (`RulesService.php:435-445`) so `flag` returns the
   allow-with-flag signal and `warn` either logs via the existing mod-log writer or the help
   text at `ModRulesAdmin.php:477` is corrected to "allow, no log".
5. Re-walk journey Parts 2, 3, 4 and the edge cases on the live site to confirm `block` still
   rejects, `flag` now publishes + reports, and disable/delete still let the post through.

---

## Notes for the live walk

- Entry: http://buddynext-dev.local/wp-admin/ → BuddyNext menu → Moderation Rules
  (slug `buddynextpro-mod-rules`, also surfaced as the "Rules" tab under the Moderation AdminHub group).
- Local isolation mu-plugin can strip Pro on front-end routes — wp-admin is unaffected, so
  the admin CRUD walk is safe. The REST post-create check should be exercised against an
  authenticated REST client, not a stripped front-end request.
- Seed at least one space and a member before the `block`/`flag` post tests; an empty account
  will look like nothing happens.
