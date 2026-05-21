# BuddyNext — Documentation Guide

**Created:** 2026-03-22
**Purpose:** Defines where every type of documentation lives and who owns it. If you are lost, start here.

---

## The Rule

> Every piece of information has exactly one home. Never duplicate content across files — link to it instead.

If you cannot find where something belongs, check the decision guide at the bottom of this file.

---

## Document Map

### `docs/MASTER_DEVELOPMENT_PLAN.md`

**What it is:** Status tracker. Answers "what have we built and what is left?"

**What goes here:**
- Phase map: which phases exist, their status (✅ / ⚠️ / 🔲)
- Remaining work blocks: concise checklist of tasks with checkboxes
- Links to specs and implementation plans — NOT the detailed content itself
- Code quality standards and the definition of done

**What does NOT go here:**
- Detailed architecture decisions (→ `00-architecture.md`)
- Feature descriptions (→ spec files)
- Step-by-step build instructions (→ plan files)

---

### `docs/specs/INDEX.md`

**What it is:** Table of contents for all spec files.

**What goes here:**
- One row per spec with file link and locked/draft status
- DB tables master list
- Nothing else

---

### `docs/specs/features/NN-feature-name.md`

**What it is:** The authoritative specification for one feature. Answers "what does this feature do and how does it work?"

**What goes here:**
- Feature purpose and scope
- Data model (tables, columns, relationships)
- REST API surface (endpoints, request/response shape)
- Business rules and edge cases
- Developer extension points (filters, actions)
- Integration with other features
- Free vs Pro boundary for this feature

**Rules:**
- One file per feature
- Status is either Draft or Locked
- Locked specs are not changed unless feature scope changes — add a dated note at the top when that happens
- Spec numbers match the phase map in MASTER_DEVELOPMENT_PLAN.md

**Current specs:** `docs/specs/features/00` through `20`, Pro specs `P1–P6`

---

### `docs/specs/features/00-architecture.md`

**What it is:** Cross-cutting engineering decisions that apply to the whole codebase. Answers "how is this plugin structured and why?"

**What goes here:**
- Product hierarchy and addon deferral model
- Bootstrap order
- File organization conventions (Admin layer, Services, REST controllers, Templates)
- Namespace conventions
- Any architectural decision that applies across multiple features

**Rule:** Append new decisions with a date. Never remove old ones — history matters.

---

### `docs/specs/HOOKS.md`

**What it is:** Complete reference of every `buddynext_*` action and filter.

**What goes here:**
- Every hook name, when it fires, what arguments it passes
- Hook name contract — what addons depend on

**Rule:** Update this whenever a hook is added, renamed, or removed. BLOCK 11 exists specifically to align the codebase to this contract.

---

### `docs/superpowers/plans/YYYY-MM-DD-topic.md`

**What it is:** Step-by-step implementation plan for a phase or complex feature. Written before implementation starts.

**What goes here:**
- Goal and scope of the work
- Ordered task list with checkboxes
- Technical decisions specific to this implementation pass
- Test commands to verify completion

**Rules:**
- Created before writing code, not after
- Filename includes date and topic slug: `2026-03-20-phase-1-core-foundation.md`
- Mark as ✅ COMPLETED at the top when done, with the completion date
- Link from MASTER_DEVELOPMENT_PLAN.md with a "Detailed step-by-step:" reference line

**Current plans:**
- `docs/superpowers/plans/2026-03-20-phase-1-core-foundation.md` ✅

---

### `docs/v2 Plans/`

**What it is:** The v2 design system — the only design source for every BN frontend surface. Replaces the prior brainstorm mockups, which have been removed from the repo.

**Layout:**
- `docs/v2 Plans/tokens.css` — canonical token + primitive vocabulary.
- `docs/v2 Plans/style-guide.html` — design-system canon.
- `docs/v2 Plans/v2/*.html` — major-page prototypes (feed, profile, directory, spaces, messages, notifications, search, onboarding, admin chrome, mobile shell, hub index).
- `docs/v2 Plans/PLAN.md` — surface → prototype map + composition rules + uniformity gates + rollout sequence.
- `docs/v2 Plans/REVIEW.md` — engineering review (browser support, accessibility, whitelabel correctness).

**Rules:**
- Design owner modifies the prototypes; engineering doesn't.
- Every template renders against its v2 prototype or composes from v2 primitives — never from any other source.
- Style guide canonical: `docs/v2 Plans/style-guide.html`.

---

## Decision Guide

| You want to document... | Put it in... |
|-------------------------|-------------|
| What a feature does | `docs/specs/features/NN-feature-name.md` |
| How the PHP files are organized | `docs/specs/features/00-architecture.md` |
| A new hook or hook rename | `docs/specs/HOOKS.md` |
| What's left to build | `docs/MASTER_DEVELOPMENT_PLAN.md` — check a box |
| Step-by-step build instructions for a phase | New `docs/superpowers/plans/YYYY-MM-DD-topic.md` |
| What a screen looks like | v2 prototype in `docs/v2 Plans/v2/` (or compose from `docs/v2 Plans/tokens.css` primitives per `docs/v2 Plans/PLAN.md` Part 3) |
| An architectural decision (file structure, convention) | `docs/specs/features/00-architecture.md` — append with date |
| A change to an existing spec | Append a dated note to the spec file, update the spec content |
| Free vs Pro classification | `docs/specs/features/FREE-VS-PRO.md` |

---

## What Never Goes in `docs/`

- Generated code or build artifacts
- Temporary notes or scratch work
- Duplicate content that already lives in another doc — link, don't copy
- `.claude/plans/` files — those are session-scoped scratch, not project docs
