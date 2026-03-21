# BuddyNext — Member Directory + Search

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Two surfaces: a dedicated member directory with filters, and a unified cross-content search with grouped results. Both built on a shared async index for large communities.

---

## Member Directory

### Filters
- Name / username (text)
- Location, skills (from profile fields)
- Space membership
- Connection status (my connections / 2nd degree / everyone)
- Online now
- Any profile field marked searchable auto-appears as filter

### Display
- Card view + list view toggle
- Online indicator, follower count, mutual connection count
- Follow + Connect buttons inline — no page reload
- Sort: Newest / Most active / Alphabetical / Mutual connections
- Cursor-based pagination

---

## Unified Search — Grouped Results

```
Search: "photography"

Members (3)      Spaces (2)         Posts (12)
@jane_photo      Photography Club   "Best lenses..."
@photo_bob       Street Photo       "Golden hour..."
@lens_master     → See all          → See all

Discussions (5)  Jobs (2)
"Canon vs Nikon" Senior Photographer
→ See all        → See all
```

- Top 3-5 results per group
- Groups shown based on active plugins (Jetonomy → discussions, Career Board → jobs, etc.)
- Privacy-aware — only shows content the viewer is allowed to see

---

## Search Architecture

- Dedicated `bn_search_index` table — FULLTEXT indexed (title + content)
- Never queries directly across multiple plugin tables
- Index updates are async via Action Scheduler (never blocks page load)
- On plugin activation: batch re-index all existing content
- Pluggable driver: MySQL FULLTEXT default → ElasticSearch / Algolia / Typesense via filter

---

## Addon Behavior

### WPMediaVerse
BuddyNext mode: media indexed in `bn_search_index` (type: `media`) via bridge.

### Jetonomy
BuddyNext mode: discussions indexed in `bn_search_index` (type: `discussion`) via bridge.

### Career Board
BuddyNext mode: jobs indexed in `bn_search_index` (type: `job`) via bridge.

---

## Data Stored

`bn_search_index` — unified search index: object_type, object_id, title, content, visibility, author_id, space_id

Searchable flat profile fields denormalized to `wp_usermeta` for fast `WP_User_Query` filtering.

---

## Integration Points

| Feature | How directory/search uses it |
|---------|----------------------------|
| Social Graph | Follow/connection filters, exclude blocked users |
| Profile Fields | Searchable fields → directory filters |
| Spaces | Space membership filter |
| Jetonomy | Discussions in unified search via bridge |
| Career Board | Jobs in unified search via bridge |
| WPMediaVerse | Media in unified search via bridge |
| Action Scheduler | All index updates async |

---

## Gaps / Open Questions

- None — fully locked
