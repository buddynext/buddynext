# BuddyNext — Plans Index (single source of truth)

**Updated:** 2026-06-14 · **Release target:** 2026-06-20

Read order for fast execution: **`WORKFLOW.yaml`** (what to do next) →
**`2026-06-14-pro-packaging-lock.md`** (which tier) →
**`native-integration-program.md`** (how to integrate). Everything else is done or reference.

There are no conflicting plans. The one apparent conflict (packaging-lock said apps
render "on their own pages / deep-link"; native-integration-program said "native BN
surfaces, no 2nd-party screens") was **reconciled 2026-06-14**: both now say the same
thing — partner plugins are headless APIs; BuddyNext renders every section natively on
its own BN URL. "Their own page" = its own BN section/URL, never the partner's screen.

---

## 1. LOCKED — source of truth (read these)

| Doc | Role | Status |
|---|---|---|
| `WORKFLOW.yaml` | Fast-execution workflow: tier model + integration law + ordered build queue | **LOCKED 2026-06-14** |
| `2026-06-14-pro-packaging-lock.md` | Free / Pro / Business tier model; which integration is which tier; June 20 checklist | **LOCKED 2026-06-14** |
| `native-integration-program.md` | The HOW for every integration (API-level, native BN UI on BN URLs, no 2nd-party screens). Phases 0 + 3 DONE; 1, 2, 4, 5 pending | **CURRENT / VALID** |

## 2. DONE — executed & committed (history, not action)

**Flow refactor (vertical, flow-first — controllers thin, services own DB, BaseRestController, 100% REST, TDD):**

| Plan | Domain | Status |
|---|---|---|
| `docs/superpowers/plans/2026-06-13-moderation-flow.md` | Moderation | ✅ DONE |
| `docs/superpowers/plans/2026-06-13-feed-comments-flow.md` | Feed + Comments | ✅ DONE |
| `docs/superpowers/plans/2026-06-14-profile-flow.md` | Profile | ✅ DONE |
| `docs/superpowers/plans/2026-06-14-spaces-flow.md` | Spaces | ✅ DONE |
| `docs/superpowers/plans/2026-06-14-socialgraph-flow.md` | Social Graph | ✅ DONE |
| `docs/superpowers/plans/2026-06-14-notifications-flow.md` | Notifications | ✅ DONE |

> Checkboxes in these files were never ticked, but all six flows are implemented,
> tested, and committed. Treat as DONE; do not re-execute.

**Earlier foundation plans (Q1 2026):**

| Plan | Status |
|---|---|
| `docs/superpowers/plans/2026-03-20-phase-1-core-foundation.md` | ✅ DONE |
| `docs/superpowers/plans/2026-03-23-performance-routing.md` | ✅ DONE |
| `docs/superpowers/plans/2026-03-24-code-organization.md` | ✅ DONE |
| `docs/superpowers/plans/2026-03-24-structure-audit-fixes.md` | ✅ DONE |

**Native integration (already shipped):**

| Surface | Where | Status |
|---|---|---|
| Native media in feed | `includes/Media/` | ✅ DONE (reference pattern) |
| Native messaging + group chat | `includes/Messages/`, `assets/js/messages/store.js`, `templates/parts/dm-*.php` | ✅ DONE |
| Asset isolation on BN routes | `includes/Core/AssetIsolation.php` | ✅ DONE (Phase 0) |

## 3. PENDING — the build queue (see `WORKFLOW.yaml` for order/detail)

**Integration model (corrected 2026-06-14):** BuddyNext adds a **community layer ON TOP**
of standalone plugins — profile + activity (+ possible space) — and **never takes over
their screens or touches their core**. The native-rebuild playbook
(`native-integration-program.md`) is a **Tier-1 exception** for core primitives only
(messaging, done). Business apps are all **Tier-2 community layer**.

| # | Task | Tier | Tracked in |
|---|---|---|---|
| 0 | CareerBoardBridge → Pro + correct guard/signatures | Pro | ✅ DONE |
| 1 | **Career Board community layer** — activity on job/resume post + profile jobs/resumes tab | Pro · Tier 2 | `integrations/01-career-board.md` |
| 2 | June 20 gating/positioning lock | — | packaging-lock checklist |
| 3 | BN-native gap UIs (reactions palette, invites, approval-queue tab, announcements) | Free | — |
| 4 | Jetonomy (forums) community layer — confirm Tier-1 vs Tier-2 first | Free (core bridge) | per-integration plan |
| 5 | Learnomy (courses) community layer — profile + activity | Pro · Tier 2 | per-integration plan |
| 6 | WB Listora (directory) community layer — profile + activity | Pro · Tier 2 | per-integration plan |
| 7 | Eventonomy (events) community layer — profile + activity | Pro · Tier 2 | per-integration plan |
| 8 | WPConnectPress (video) community layer — profile + activity | Pro · Tier 2 | per-integration plan |
| 9 | AI / Abilities surface (`wp-abilities/v1`) across the suite | Free read · Pro runtime | packaging-lock AI section |

## 4. REFERENCE — specs & contracts (consult, don't execute)

| Doc | Use |
|---|---|
| `docs/specs/REST-FRONTEND-CONTRACT.md` | 100% REST, no admin-ajax (enforced by `bin/check-rest-boundary.sh`) |
| `docs/specs/SCALE-CONTRACT.md` | LIMIT on lists, cursor not OFFSET, no COUNT(*) in renders, cached counters |
| `docs/specs/REST-INVENTORY.md` | Live REST route inventory (kept in sync per flow) |
| `docs/specs/HOOKS.md` | Hook/event catalog (bridges consume these) |
| `docs/specs/MODULAR-ARCHITECTURE.md` | Layering: controller → service → DB |
| `docs/specs/contracts/` | Per-domain data contracts |
| `docs/specs/features/` | Per-feature specs (33 dirs) |
| `docs/specs/NOTIFICATION-MESSAGES.md` · `TEMPLATE-PARTS.md` · `INDEX.md` · `2026-03-19-buddynext-design.md` | Design/template references |
| `docs/specs/WPMediaVerse-DM-Integration-Requirements.md` · `wpmediaverse-integration-plan.md` · `wpmediaverse-media-integration-master.md` | Folded into native-integration Phase 3 (media + messaging DONE); keep as API reference |
| `plan/frontend-ux-journey.md` | End-to-end UX journey reference |

## 5. HISTORICAL — archive (no action)

| Doc | Note |
|---|---|
| `plan/qa-stress-report.md` | Point-in-time QA stress findings; addressed during flows |
