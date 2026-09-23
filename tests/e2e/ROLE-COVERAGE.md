# Role-aware journey coverage - the QA-first standard

A buyer does not read our hooks, filters, or REST routes. They open the plugin in
a browser - on a desktop, an iPad, and a phone - and judge it on three things:

1. **Does it work?** The button does what it says; the change sticks.
2. **Does it display well?** No overflow, no broken layout, no misalignment,
   consistent with the rest of the product, at every width.
3. **Are the options even there?** The setting a site owner expects is present
   and reachable, not missing or buried.

Our journeys exist to catch friction in those three, **as an admin (who
configures it) and as a member (who lives in it)**, because that is where the
experience is made or lost. A feature that passes for the admin at 1280px and is
broken, ugly, or optionless for the member at 390px is a feature we shipped
broken.

## How coverage is declared (spec-local)

Every Playwright spec already declares the journey id it proves (`J-NNN`, enforced
by `check-journey-tags.py`). Add two lines to the same leading docblock:

```ts
/**
 * J-77 post edit + delete.
 *
 * Covers: cap-let-members-react-comment-and-reply
 * Roles: member
 * ...
 */
```

- **`Covers:`** one or more `cap-<slug>` ids from `CAPABILITIES.md` (the promise
  this journey proves). Get the exact slug from `check-role-coverage.py` output or
  compute it: lowercase the capability question, non-alphanumerics to `-`, trim,
  first 60 chars, prefix `cap-`.
- **`Roles:`** the role(s) this spec actually walks: `admin`, `member`,
  `moderator`, `anon`. Declared, not parsed - specs pass username variables to
  `loginAs()`, so the role cannot be read from the code.

A `(capability, role)` cell counts as covered only when a real spec declares both.

## The bar for a journey (not just a green assertion)

Write from the person's promise, then verify the **experience**, not the presence
of a control:

- **Effect, not appearance.** Press the control and confirm the world changed -
  the row saved, the email arrived, the other member sees it. A control that
  renders but does nothing must fail.
- **Every viewport.** Exercise 390 (phone), 768 (iPad), 1280 (desktop). Most of
  what we ship is used on a phone. A layout that overflows or hides its actions at
  390 is a bug, not a detail.
- **Both roles where both apply.** The admin who turns it on and shapes it, and
  the member who uses it. Most configurable features need both.
- **Presentation + consistency.** Same patterns, spacing, and states as the rest
  of the product. Empty, loading, and error states are part of the walk.
- **Report friction, not only failures.** If a real person would hesitate, get
  lost, or not find the option, that is a finding worth a card even when nothing
  is technically "broken."

## Running it

```
python3 bin/check-role-coverage.py          # report the admin+member gaps
python3 bin/check-role-coverage.py --gate    # exit 1 if any ESSENTIAL promise
                                             # is not walked as admin AND member
```

Scope of the gate is the ESSENTIAL (daily-use) promises from `CAPABILITIES.md`,
across admin + member. The long tail (edge promises, moderator/anon) is tracked
but does not block a release yet - the gate turns on for the core first, once the
existing journeys are pinned, so it never lands as a wall of red nobody can act on.

## Running the journeys every release (execution, not just coverage)

`check-role-coverage.py` proves a journey EXISTS for each promise+role; it does
not run them. The execution gate is `bin/check-journey-run.sh`, and it is meant
to run on EVERY release across every shipping viewport:

```
BN_JOURNEY_PROJECT=desktop bin/check-journey-run.sh
BN_JOURNEY_PROJECT=ipad    bin/check-journey-run.sh
BN_JOURNEY_PROJECT=mobile  bin/check-journey-run.sh
```

Each run auto-detects the site, the WP path, user 1, and seeds `alice`, then runs
the suite **one test folder at a time** (never the whole suite in one process -
that OOM-reaps on a laptop; observed killed at 309/312) at **workers=1** (memory-
safe and free of the shared-site state collisions that auth/membership option
specs hit at higher concurrency).

**wp boot memory (why the runner raises it).** The specs shell out to `wp` for
fixtures, and a journey site legitimately runs the whole family stack so the
integration bridges (Learnomy, Listora, gamification, MediaVerse, ...) are
exercised. That stack boots at ~230M, well over the common 128M CLI
`memory_limit`, so a bare `wp` call fatals mid-`plugins_loaded` (WordPress only
raises memory later, in `wp_raise_memory_limit()`). The fatal then surfaces
INSIDE a fixture as a "critical error" / DB-connection-looking failure and, under
`--update`, bakes phantom failures into the baseline - a class of crash that was
misread as flaky MySQL for weeks. BuddyNext itself is clean (24M over core); the
cost is the aggregate stack. The runner therefore wraps the php `wp` uses (via
the honored `WP_CLI_PHP` knob, preserving that php's ini/extensions/socket) and
adds `-d memory_limit=$BN_JOURNEY_PHP_MEMORY` (default 512M), then does a
preflight boot probe that SKIPS with a clear fix if `wp` still cannot boot.
Overrides: `BN_JOURNEY_PHP_MEMORY=768M` to raise it, `BN_JOURNEY_NO_MEM_WRAP=1`
to disable the wrap (e.g. a php.ini that already sets enough). It gates each viewport against its own
baseline (`.journey-baseline.json` for desktop, `.journey-baseline-<project>.json`
for the rest): a spec failing that is NOT baselined is a regression (fail); a
baselined spec that now passes must be removed from the baseline (fail). Seed the
per-viewport baselines once with `BN_JOURNEY_PROJECT=<project> bin/check-journey-run.sh --update`.

**Triage rule when a run is red:** a failure is a SPEC bug (selector, missing
plan/entitlement setup, race, wrong URL) far more often than a product bug - the
first full run found 18 real failures and every one was a spec bug. Fix the spec
so it asserts the real effect; only when the product is genuinely broken do you
leave the test red and file a card. Never weaken a test to make it pass.
