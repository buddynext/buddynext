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
