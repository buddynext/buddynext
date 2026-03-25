# Unified Icon System — All 3 Plugins

**Date:** 2026-03-25
**Goal:** One icon system, consistent across BuddyNext + Jetonomy + WPMediaVerse

---

## Current State

| Plugin | System | Icons | Helper |
|--------|--------|-------|--------|
| BuddyNext | Lucide SVG files | 55+ in `assets/icons/` | `buddynext_icon('name')` |
| Jetonomy | Lucide SVG files | 17 in `assets/icons/` | `jetonomy_icon('name')` |
| Jetonomy Pro | Fluentui 3D PNG | 8 reactions in `assets/reactions/` | `reaction_icon('slug')` |
| WPMediaVerse | None | Uses inline SVG / dashicons | None |

## Problem
- 3 separate icon systems, inconsistent naming
- BuddyNext has 55 icons, Jetonomy has 17 — overlap but not identical
- WPMediaVerse has no icon system at all
- Reaction icons are a special case (3D/colored vs UI line icons)

## Proposed Architecture

### Layer 1: UI Icons (Lucide)
For buttons, nav, actions, empty states — **line icons, monochrome, stroke-based**.

**Source:** [Lucide](https://lucide.dev/) — MIT license, 1500+ icons, consistent 24x24 grid.

**Approach:** Each plugin bundles its own SVG files from Lucide. When BuddyNext is active, plugins can use `buddynext_icon()` for shared icons.

**Naming convention:** `{action}.svg` — lowercase, hyphenated (e.g., `message-circle.svg`, `chevron-up.svg`)

### Layer 2: Reaction Icons (Fluentui 3D)
For emoji-like reactions — **3D, colorful, premium feel**.

**Source:** [Microsoft Fluentui Emoji](https://github.com/microsoft/fluentui-emoji) — MIT license, 1800+ emoji in 3D/Color/Flat.

**Approach:** Bundle in Jetonomy Pro (reactions extension). BuddyNext can also use same library for its reaction picker.

### Layer 3: Brand/Integration Icons
For plugin logos, service badges — **custom per plugin**.

---

## Action Items

1. **Standardize Lucide icons** — create a shared list of 30 essential icons that ALL plugins use with identical filenames
2. **WPMediaVerse** — add `mvs_icon('name')` helper + copy essential Lucide SVGs
3. **BuddyNext reactions** — switch from custom SVGs to Fluentui 3D PNGs (match JT Pro)
4. **Shared icon reference** — document all icon slugs in CLAUDE.md so they stay consistent

## Essential Icons (shared across all 3)

| Slug | Use Case |
|------|----------|
| `search` | Search bars, empty states |
| `bell` | Notifications |
| `message-circle` | Comments, replies, chat |
| `heart` | Likes, reactions |
| `bookmark` | Save, bookmark |
| `share` | Share actions |
| `users` | Members, people |
| `home` | Home/feed |
| `settings` | Settings, admin |
| `edit` | Edit actions |
| `trash` | Delete actions |
| `flag` | Report/flag |
| `lock` | Private, locked |
| `check-circle` | Success, verified |
| `award` | Badges, achievements |
| `image` | Media, photos |
| `link` | Links, URLs |
| `chevron-up` | Expand, vote up |
| `chevron-down` | Collapse, vote down |
| `chevron-right` | Navigate forward |
| `more-horizontal` | More options menu |
| `x` | Close, dismiss |
| `plus` | Create, add |
| `eye` | Views, visibility |
| `clock` | Time, schedule |
| `pin` | Pin, sticky |
| `quote` | Blockquote |
| `bold` | Bold text |
| `italic` | Italic text |
| `code` | Code block |
