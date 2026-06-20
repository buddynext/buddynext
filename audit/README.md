# audit/ — BuddyNext Free audit index

Authoritative entry point for the audit/QA evidence. **Read this first** so you
look at the current source instead of guessing across scattered files.

## Layout

| Path | What it is |
|---|---|
| `*.json` (this dir) | **Machine artifacts — do not move.** Read by `includes/Cert/CertRunner.php` and the manifest-first workflow at fixed paths. |
| `reports/` | Current human-readable audit reports. |
| `history/` | Dated point-in-time snapshots. Reference only; not the live state. |

## Tool artifacts (fixed paths — never relocate)

| File | Purpose |
|---|---|
| `manifest.json` | Plugin manifest (hooks/REST/features). Manifest-first source of truth. |
| `manifest.summary.json` | Compact manifest summary. |
| `cert-oracles.json` | Functional-cert oracles consumed by CertRunner. |
| `cert-ledger.json` | Latest cert run result (pass/fail ledger). |
| `flow.json` | Code-flow graph (functions/hooks/call edges). |
| `journeys.json` | Feature → journey routing map (generated). |
| `.flow-audit-baseline.json` | Flow-audit baseline snapshot. |

## Current reports (`reports/`)

| File | Purpose |
|---|---|
| `FEATURE_AUDIT.md` | Feature inventory + status for the free core. |
| `FLOW-AUDIT-TODO.md` | Current pinpoint action list (flow-audit + go-live). |
| `flow-audit-report.md` | Flow-audit findings narrative. |
| `CODE_FLOWS.md` | Documented code flows. |
| `DEV_EXTENSIBILITY.md` | Developer-extensibility (hooks/filters) audit. |
| `ROLE_MATRIX.md` | Capability/role access matrix. |
| `deep-ux-reaudit-brief.md` | Per-screen presentation-grade UX re-audit brief. |

## Related (elsewhere in the repo)

- `docs/conformance/` — per-feature conformance reports (spec → journey → live-walk verdict).
- `docs/journeys/` — per-feature journey walks.
- `docs/qa/` — QA test specs, matrices, how-to-run.
- `docs/standards/` — long-term architecture standards (cron, nav, interactivity, community-OS).
- `docs/specs/` — feature + contract specs.
