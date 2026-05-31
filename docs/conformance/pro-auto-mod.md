# Conformance: Auto / Rule-based Moderation (Pro)

**Feature:** Auto / Rule-based Moderation
**Repo:** buddynext-pro
**Spec ref:** `buddynext/docs/specs/features/09-moderation.md` (§ Automated Safeguards) + journey `buddynext-pro/docs/journeys/auto-moderation.md`
**Verdict:** partial-needs-wiring
**Date:** 2026-05-31

---

## Summary

Rule CRUD (admin UI + REST), `keyword_block`/`link_block`/`rate_limit` **block** enforcement, and the `threshold_remove` auto-action path are all wired end-to-end and work. The journey's **Part 3 (severity = `flag` → post is created + an auto-report is generated)** is **not implemented**: a `flag`-severity keyword match returns a blocking `WP_Error` that `PostService::create()` rejects, so the post is *not* created and *no* report is written. This is the earliest break in the documented journey.

The `block`, CRUD, toggle, and delete paths are sound — do not touch them.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin opens "Moderation Rules" page (`page=buddynextpro-mod-rules`) | ui | wired | `buddynext-pro/includes/Admin/ModRulesAdmin.php:107` (add_submenu), `:236` (render_content) |
| 2 | Admin submits "Add New Rule" form (keyword_block, severity=block) | ui→store | wired | `ModRulesAdmin.php:372` (form posts to admin-post.php), `:124` handle_create, nonce `:129` |
| 3 | Create persists to `bn_mod_rules` with JSON config | service→db | wired | `ModRulesAdmin.php:138` → `RulesService.php:64` create_rule → `:87` `$wpdb->insert` |
| 4 | Rule appears in list, Enabled | service→ui | wired | `RulesService.php:209` list_rules → `ModRulesAdmin.php:290` render_rule_table |
| 5 | member1 POSTs banned content; Free safeguard fires Pro filter | rest→service | wired | `buddynext/includes/Feed/PostService.php:116` check() → `buddynext/includes/Moderation/SafeguardService.php:75` `apply_filters('buddynext_safeguard_check')` |
| 6 | Pro evaluates keyword rule, severity=block → WP_Error 422 | service | wired | `buddynext-pro/includes/Moderation/SafeguardIntegration.php:57` apply_pro_rules → `RulesService.php:276` evaluate_safeguard → `:427` block branch (422) |
| 7 | PostService aborts; no row in `bn_posts` | service→db | wired | `PostService.php:121-127` returns WP_Error before `$wpdb->insert` at `:144` |
| 8 | (Part 3) Switch severity to `flag`, re-POST → expect **201 + post saved + report row** | service→db | **broken** | `RulesService.php:441-445` flag returns `WP_Error('bnpro_keyword_flagged', status 202)`; `PostService.php:126` rejects any non-`pending_review` error → post NOT saved, no `ModerationService::report()` call exists for flagged content (grep of `includes/Moderation/`) |
| 9 | (Part 4) Disable rule → post succeeds | service→db | wired | `ModRulesAdmin.php:209` handle_toggle → `RulesService.php:197` disable → enabled=0; safeguard query filters `enabled=1` (`RulesService.php:360`) |
| – | threshold_remove auto-action on real report | service | wired (separate path) | `buddynext/includes/Moderation/ModerationService.php:138` `apply_filters('buddynext_moderation_auto_actions')` → `AutoActionsIntegration.php:54` → `RulesService.php:298` evaluate_post_report |
| – | REST surface `buddynext-pro/v1/mod-rules` (admin-only) | rest | wired | `Controllers/ModRulesController.php:31` NS, `:57` routes, `:244` require_admin (manage_options → 403) |

---

## First break

**Step 8 — severity = `flag` keyword rule.** `RulesService::evaluate_keyword_block()` (`buddynext-pro/includes/Moderation/RulesService.php:441-445`) returns a blocking `WP_Error('bnpro_keyword_flagged')` for `flag` severity. `PostService::create()` (`buddynext/includes/Feed/PostService.php:121-127`) only treats the `pending_review` error code as non-fatal (saves as `status=pending`); every other `WP_Error` causes an early `return`, so the post is rejected. There is no code path that turns a flagged keyword into a saved post plus a `bn_reports` row. The journey expects 200/201 + an auto-generated report; the code blocks the post and writes nothing.

The `block` and `warn` severities behave correctly (`block` → 422 reject at `:427`; `warn` → returns true at `:435-438`, post allowed). Only `flag` diverges from the locked journey.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| severity=`flag` keyword rule rejects the post instead of allowing it through and auto-creating a pending report | high | confirmed-in-code | `RulesService.php:441-445` returns blocking WP_Error; `PostService.php:126` rejects it; no `ModerationService::report()` invocation exists for flagged content |
| The `buddynext_safeguard_check` filter contract cannot express "allow + flag" — it returns only `true` (allow) or `WP_Error` (block), so flag-with-report is structurally impossible through this single filter without a Free-side seam | medium | confirmed-in-code | `SafeguardService.php:75` returns the raw filter result; `PostService.php:121` short-circuits on any WP_Error; no side-channel for "save then report" |

The web admin journey for rule management (create/edit/toggle/delete) is fully usable with real nonce-protected UI controls bound to admin-post handlers — no api-only gap there. The REST surface additionally serves app/REST clients and is admin-gated correctly (403 for non-admins).

---

## Minimal refactor plan

The fix lives mostly in Free (the filter contract is too narrow to express "allow + flag"). Keep all existing block/CRUD code.

1. Extend the Free safeguard seam to carry a non-blocking "flag" signal. In `buddynext/includes/Feed/PostService.php:121-128`, branch on a flag error (code ending `_flagged` or data `status === 202`) the same family as `pending_review`: save the post, then file a report — rather than returning the error.
2. On the flag branch, call the existing `ModerationService::report()` insert (`buddynext/includes/Moderation/ModerationService.php:54`) with reporter_id = 0 (system) and reason "Automated moderation flag", landing a `bn_reports` row with `status=pending` (satisfies journey step 9). Pro already returns the recognizable `bnpro_keyword_flagged` / `bnpro_link_flagged` codes (status 202) at `RulesService.php:441-445` / `:494-498` — no Pro change needed beyond confirming that convention.
3. Re-walk journey Parts 2–4: confirm block still rejects, flag now saves+reports, disable restores posting. This also lets the existing `threshold_remove` / `buddynext_moderation_auto_actions` path fire on auto-flags.

Scope is small and reuses existing inserts/handlers; no rewrite of the rules engine or admin UI.

---

## Live-walk URL

http://buddynext-dev.local/wp-admin/ → BuddyNext → Moderation → Rules (`admin.php?page=buddynextpro-mod-rules`)

Walk: create a `keyword_block` rule severity=block, POST a banned word via `/wp-json/buddynext/v1/posts` (expect 422, no post). Then edit severity to `flag` and re-POST — current build rejects the post (the break); after the refactor it should 201 + create a pending `bn_reports` row. Then Disable and confirm the post succeeds.
