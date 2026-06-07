# Conformance: Auto / Rule-based Moderation (Pro)

**Feature:** Auto / Rule-based Moderation (repo: buddynext-pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/09-moderation.md` (§ Automated Safeguards) + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/auto-moderation.md`
**Verdict:** usable-leave-as-is (resolved 2026-06-07)

> **Resolution (2026-06-07).** Both gaps closed in buddynext-pro: `bn_mod_rules.rule_type` ENUM widened to all four shipped types (`keyword_block,link_block,rate_limit,threshold_remove`; dropped unused `spam_score`) with an idempotent ALTER in `maybe_alter_tables` for existing installs; and the `suspend` action now targets the offending author (resolved from `bn_posts`/`bn_comments` by object_type) instead of the reported object_id. `includes/Core/Installer.php`, `includes/Moderation/RulesService.php`.
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (→ `admin.php?page=buddynextpro-mod-rules`)

---

## Summary

The core happy path (create a `keyword_block` rule in admin → enforce it on REST post submission → `block` rejects, `flag` publishes + files a report → disable rule → posting succeeds) is wired end-to-end across ui → service → rest → free-hook → db. This is the journey's Parts 1–4 and all of them use `rule_type = keyword_block`, which is valid.

One real defect exists at the DB layer: the production `bn_mod_rules` table is created with `rule_type ENUM('keyword_block','threshold_remove','spam_score')`, but the service / controller / admin UI offer four types — `keyword_block`, `link_block`, `rate_limit`, `threshold_remove`. `link_block` and `rate_limit` are NOT valid ENUM members; `spam_score` (in the ENUM) is never used by code. This breaks two rule types the UI lets an admin create, including the journey's documented "Rate limit rule" edge case.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin opens Moderation Rules page | ui | wired | `buddynext-pro/includes/Admin/ModRulesAdmin.php:107` (slug `buddynextpro-mod-rules`); registered `buddynext-pro/includes/Core/Plugin.php:175` |
| 2 | "Add New Rule" form (keyword_block, severity, priority) | ui | wired | `ModRulesAdmin.php:369` create form; `:413` shared fields; `:564` `extract_config_from_post` builds `{keywords,severity}` |
| 3 | Submit → admin-post handler → create | store | wired | `ModRulesAdmin.php:87` hooks `admin_post_bnpro_create_mod_rule`; `:124` `handle_create` (nonce + `manage_options`) |
| 4 | Insert rule into `bn_mod_rules` | db | wired | `buddynext-pro/includes/Moderation/RulesService.php:64` `create_rule` → `:87` `$wpdb->insert` |
| 5 | Member POSTs content w/ banned word via REST | rest | wired | `buddynext/includes/Feed/PostController.php:115` `create_post` → `PostService::create` |
| 6 | PostService runs safeguard check | service | wired | `buddynext/includes/Feed/PostService.php:116` `get_safeguard()->check(...)` |
| 7 | Free fires `buddynext_safeguard_check` filter | service | wired | `buddynext/includes/Moderation/SafeguardService.php:75` |
| 8 | Pro evaluates keyword rule, returns WP_Error | service | wired | `buddynext-pro/includes/Moderation/SafeguardIntegration.php:57` → `RulesService.php:409` `evaluate_keyword_block` (block→422, flag→202) |
| 9 | severity=block → post rejected (422) | rest | wired | `PostService.php:132` hard-block returns error; `PostController.php:118` preserves status |
| 10 | severity=flag → post publishes + system report filed | db | wired | `PostService.php:126` `is_flag_error`; `:193`+`:560` `report_flagged_post`→`ModerationService::report(0,'post',...)` into `bn_reports`; matcher `:525` |
| 11 | threshold_remove auto-action on report | service | wired | Free fires `buddynext_moderation_auto_actions` at `buddynext/includes/Moderation/ModerationService.php:138`; Pro appends `buddynext-pro/includes/Moderation/AutoActionsIntegration.php:54` → `RulesService.php:298` |
| 12 | Disable / Enable / Edit / Delete rule | store | wired | `ModRulesAdmin.php:209` `handle_toggle`→`RulesService.php:578`; delete `:183`/`:163` |
| 13 | REST CRUD for rules (admin / app client) | rest | wired | `buddynext-pro/includes/Moderation/Controllers/ModRulesController.php:57` routes; `require_admin` `:244` (`manage_options`); registered `Plugin.php:293` |
| 14 | Persist `link_block` / `rate_limit` rule | db | broken | `buddynext-pro/includes/Core/Installer.php:445` ENUM omits both; UI/REST offer them (`ModRulesController.php:267`, `RulesService.php:31`) |

---

## First break

None on the core happy path (Parts 1–4 keyword_block flow is complete). The earliest broken link off the happy path is step 14: persisting a `link_block` or `rate_limit` rule. The production schema `ENUM('keyword_block','threshold_remove','spam_score')` (`Installer.php:445`) rejects/truncates these two rule types the UI and REST schema actively offer. The journey's "Rate limit rule" edge case (`auto-moderation.md:116`) cannot complete on a strict-mode MySQL install.

---

## UX gaps

- **high / confirmed-in-code** — `bn_mod_rules.rule_type` ENUM omits `link_block` and `rate_limit` (and contains unused `spam_score`). Strict-mode MySQL: creating those types from the admin form or REST returns "Failed to create moderation rule." Non-strict MySQL: value truncates to `''`, rule saves but never matches at evaluation (silent dead rule). Evidence: `buddynext-pro/includes/Core/Installer.php:445` vs `RulesService.php:31` + `Controllers/ModRulesController.php:267` + `Admin/ModRulesAdmin.php:432-433`. Tests mask it via `VARCHAR(64)` (`tests/Moderation/RulesServiceTest.php:594`).

- **medium / confirmed-in-code** — `evaluate_post_report` sets the `suspend` action's `user_id` from `$report['object_id']` (the reported object id), not the offending author. For `object_type='post'` this suspends the wrong entity. Off the core path; only reachable with a `threshold_remove` + `action=suspend` rule. Evidence: `buddynext-pro/includes/Moderation/RulesService.php:335-337`.

- **low / confirmed-in-code** — keyword `severity=warn` returns true with no log/report, though the UI describes warn as "allow but log." No log sink is called, so warn is indistinguishable from no rule. Evidence: `RulesService.php:435-438` vs `ModRulesAdmin.php:477`.

---

## Minimal refactor plan

1. Widen `rule_type` in `buddynext-pro/includes/Core/Installer.php:445` to cover all four shipped types — `ENUM('keyword_block','link_block','rate_limit','threshold_remove')` or `VARCHAR(64)` (matching `tests/Moderation/RulesServiceTest.php:594`). VARCHAR is lower-friction and already how tests validate. Add the migration/ALTER so existing installs pick up the column change.
2. Fix the suspend target in `RulesService.php:335-337` — resolve the offending author (post's `user_id`) before populating the `suspend` action descriptor instead of using `object_id` as `user_id`.

(The "warn" logging gap is spec-fidelity polish, not a journey break — leave unless product wants the audit trail.)

---

## Notes for the human's live walk

- Walk the core path first (keyword_block block → flag → disable) on a seeded account, not an empty one.
- To reproduce the high-severity gap: create a `rate_limit` rule via the admin form and observe whether it errors on save (strict MySQL) or saves with empty `rule_type` and never fires (non-strict). Confirm the local DB `sql_mode` before concluding which symptom applies.
