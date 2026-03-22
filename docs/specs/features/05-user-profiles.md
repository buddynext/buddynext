# BuddyNext — User Profiles

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Extended user profiles beyond `wp_users`. Structured field groups (flat + repeater), profile completion tracking, and per-field privacy.

---

## Field Architecture

Three tables: `bn_profile_groups` (group definitions) + `bn_profile_fields` (field definitions) + `bn_profile_values` (user values). An `entry_index` integer handles repeater entries — no extra table needed.

Searchable flat fields are denormalized to `wp_usermeta` on save for fast directory filtering.

---

## Built-in Field Groups

| Group | Type | Fields |
|-------|------|--------|
| Basic Info | Flat | Bio, location, website, pronouns |
| Social Links | Flat | Twitter/X, LinkedIn, GitHub, Instagram, YouTube |
| Work Experience | Repeater | Company, title, location, daterange, currently working, description |
| Education | Repeater | Institution, degree, field, daterange, currently attending |
| Skills | Flat | Tag-based multiselect |

Admin can add custom groups (flat or repeater). Developers register groups via filter.

---

## Field Types

text / textarea / url / social (URL + icon) / select / multiselect / date / daterange (with "currently" toggle) / checkbox / number

---

## Privacy Per Field Group

Each group has a default visibility. User can override per repeater entry.

| Level | Who sees it |
|-------|------------|
| `public` | Everyone — indexed by Google |
| `followers` | People who follow you |
| `connections` | Mutual connections only |
| `private` | Just you |

---

## Profile Completion

- % complete from required + recommended fields
- Progress bar on profile
- Prompt cards: "Add your work experience", "Add skills"
- Completion milestone triggers WBGamification award if active

---

## Addon Behavior

### WPMediaVerse
- Standalone: own profile UI (avatar, cover)
- BuddyNext mode: avatar + cover managed through BuddyNext profile. Storage: WP media library (free) or WPMediaVerse cloud when MVS is active.

### Jetonomy
- Standalone: own member profile store
- BuddyNext mode: Jetonomy reads member data via BuddyNext profile API. On migration: Jetonomy member data imported to `bn_profile_values`.

### WBGamification
Points + badge counts shown on profile via bridge. Profile completion milestone awards badge.

### Career Board
"Open to work" checkbox + work experience fields pre-populate Career Board candidate profile.

---

## Data Stored

Three tables:

`bn_profile_groups` — group definitions (label, group_key, type: flat|repeater, visibility)
`bn_profile_fields` — field definitions (type, label, field_key, options JSON, group_id, visibility, is_required, sort_order)
`bn_profile_values` — user values (user_id, field_id, entry_index for repeaters, value)

---

## Integration Points

| Feature | How profiles use it |
|---------|-------------------|
| Social Graph | Profile visibility gated by can_view check |
| Member Directory | Searchable fields → directory filters |
| Search | Profile content indexed in `bn_search_index` |
| WBGamification | Completion awards |
| WPMediaVerse | Avatar + cover storage |

---

## Gaps / Open Questions

- Avatar + cover storage decision: WP media library (standalone) → WPMediaVerse cloud (when MVS active). Bridge handles the upload routing — **confirmed approach**.
