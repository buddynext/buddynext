# Functional Certification System ("Certify")

> One system. Self-improving. Works on every plugin. Certifies **functionality**, not code quality.

## The problem this exists to kill

We ship features whose **second half is never wired** — a settings toggle that nothing reads,
a REST route that never enforces its own permission, an admin tab that fatals on save, an icon
that uploads but never appears. On 2026-06-15 QA filed **50+ such cards in one day** and our
"smoke test" stayed green the whole time.

Root cause, proven by inventory:

- Every automated gate we own tests **code shape** — `php -l`, PHPStan, WPCS, `ux-audit.sh`
  (tokens), 106 PHPUnit unit tests. Shape ≠ behavior. A dead toggle is *valid code*.
- The one behavioral suite we own — **43 Playwright specs** — runs in **no gate** (not in
  `ci.yml`, not in `bin/check.sh`; last touched weeks before the bug wave).
- The 13 "QA audit skills" and the `wp-plugin-qa` MCP are **advisory prose** — an LLM is *asked*
  to check, producing non-deterministic results. Prose is not a gate.

**A pass never meant "the product works." It meant "the code is well-formed."** Functionality is
the product; if it's broken, code quality is irrelevant. So the gate must assert behavior.

## What "trustworthy" requires (the five laws)

A verdict is only trustworthy if the check is:

1. **Deterministic** — same code → same verdict. (Code, never prose.)
2. **Behavioral** — asserts runtime *outcomes* against a **running, seeded** site, not code shape.
3. **Spec-derived** — coverage is generated from the **manifest**, so gaps surface as RED, not silence.
4. **Enforced** — blocks merge in CI; cannot be skipped or forgotten.
5. **Coverage-honest** — the report names what it did **not** check (HOLEs). A pass is a statement
   about *completeness*, not about the few things we happened to look at.

## Architecture — one engine, three faces

```
                         audit/manifest.json   (the executable contract / oracle)
                                   │
                          ┌────────▼─────────┐
                          │   CertRunner     │  deterministic engine (PHP, runs INSIDE WP:
                          │   (the engine)   │  DB + hooks + internal REST dispatch + seed data)
                          └────────┬─────────┘
            ┌──────────────────────┼───────────────────────┐
   wp <plugin> cert            Playwright            wp-plugin-qa MCP
   (CLI / CI gate)         (browser behavioral)     (LLM proposes checks,
   deterministic           viewport×theme×auth       NEVER is the check)
```

- **The engine is code.** It is the only thing allowed to say pass/fail.
- **The CLI** (`wp <plugin> cert`) is the gate — runs in `bin/check.sh` and `ci.yml`, exits non-zero.
- **Playwright** is the browser arm (manifest-driven route coverage; catches CSS/JS/viewport/theme).
- **The MCP** is demoted to an *interface*: it runs the engine and reports the ledger; the LLM is
  only permitted to *propose* a new oracle entry for a HOLE — which a human/agent confirms and the
  engine then enforces forever. **LLM proposes, code certifies.** (This is why the old MCP failed:
  it let the LLM *be* the check.)

## The functional checks (what the engine asserts)

Driven by manifest sections (`rest`, `admin_pages`, `services`, `hooks_fired`, feature flags):

| Check | Oracle (manifest) | Asserts (behavior) | Kills bug class |
|---|---|---|---|
| **boot** | `rest.endpoints` (GET, public) + `admin_pages` | dispatch/render → no fatal, no 500, clean debug.log | fatals / white screens |
| **contract** | feature flags + `rest.endpoints` | flag OFF → the gated route's behavior **changes** (403/empty); flag ON → works | **dead toggles** (the #1 class) |
| **boundary** | `rest.endpoints[].permission` | a route declaring auth actually **rejects** the anonymous/over-privileged caller | broken permission gates |
| **wiring** | `services` + `rest` + templates | every data feature is reachable **frontend + backend + API** (the 3-entry-point rule) | half-wired features |
| **notify** | `hooks_fired` (notification/email) | trigger event → notification row written + email CTA URL **resolves** (Mailpit) | notification correctness |
| **visual** (Playwright) | `admin_pages` + hub routes | each page at 390/768/1280 × {default, host theme} × {admin, member, guest} → no overflow, no zero-size avatar, no console error | CSS / mobile / theme |

Each check emits, per manifest entity: `pass` | `fail` | `HOLE` (no oracle yet).

## Self-improvement (why this gets *more* trustworthy over time)

The ledger (`audit/cert-ledger.json`) records every manifest entity and its check status. The gate
fails CI when **any covered check fails**, **coverage regresses** (an entity lost its check), or the
**HOLE count rises**. New manifest entries with no check show as HOLEs immediately.

The loop:

```
bug found (QA card / agent / production)
        │
        ▼
manifest entry exists?  ──no──►  refresh manifest (/wp-plugin-onboard)
        │ yes
        ▼
`wp <plugin> cert learn` (or MCP): record an oracle for the entity
        │   (route to probe + expected-when-off / expected-shape)
        ▼
entity flips HOLE → covered  →  permanent regression check, can never recur
        │
        ▼
HOLE count monotonically shrinks; CI blocks any regression
```

Every bug becomes a permanent check. The system's coverage only ever increases. **A bug can happen
once; after `learn`, it is structurally impossible to ship it again.**

## Cross-plugin (one system, 100+ plugins)

The engine is **plugin-agnostic** — it reads `audit/manifest.json`, which every Wbcom plugin already
keeps (and `/wp-plugin-onboard` generates). Portability model:

- The engine ships as a shared dev package (`wbcom/certify`, Composer dev-dependency) + a per-plugin
  thin `Cert\CertCommand` that registers `wp <slug> cert`.
- The `wp-plugin-qa` MCP wraps `wp <slug> cert --json` for any plugin — one MCP, every plugin.
- Per-plugin oracles live in `audit/cert-oracles.json` (the only plugin-specific part); the checks,
  ledger format, gate logic, and CI wiring are identical everywhere.

## Phases

**P1 — Spine (this PR).** `CertRunner` + `wp buddynext cert {boot,contract}` + ledger + `--json`,
wired into `bin/check.sh` and `ci.yml`; the existing Playwright suite wired into CI. Verified red on a
dead toggle and a fatal route, green when enforced. *This is the proof the model works.*

**P2 — Coverage.** Add `boundary`, `wiring`, `notify` checks; seed via `DemoDataService` so checks
run against realistic data (no empty-account false-greens). Back-fill oracles for the 6 bug classes
from 2026-06-15. Manifest-derived Playwright route matrix (viewport×theme×auth).

**P3 — Self-improvement + MCP.** `cert learn`; ledger regression-gating; rebuild `wp-plugin-qa` MCP
as a thin wrapper over the engine (LLM proposes oracles only). Mutation-test pass to expose
assertion-thin checks.

**P4 — Cross-plugin.** Extract engine to `wbcom/certify`; onboard the next plugin; one MCP for all.

## Files (P1)

- NEW `includes/Cert/CertRunner.php` — the engine (manifest load, boot + contract checks, ledger).
- NEW `includes/Cert/CertCommand.php` — `wp buddynext cert` WP-CLI surface.
- NEW `audit/cert-oracles.json` — feature-flag → probe-route map (the only hand-authored part).
- EDIT `includes/Core/Plugin.php` — register the CLI command (next to `wp buddynext demo`).
- EDIT `bin/check.sh` — add a "Functional certification" section (blocking).
- EDIT `.github/workflows/ci.yml` — add a `functional-cert` job + an `e2e` (Playwright) job.

## Definition of done (redefining "green")

A PR is done when: every setting it touches has a contract oracle (or a logged HOLE), every page it
touches is in `boot`, and `wp buddynext cert` is green. Not when it compiles. Until the build goes
**red on a dead toggle**, we keep shipping them and QA stays the runtime.
