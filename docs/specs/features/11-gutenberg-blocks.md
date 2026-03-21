# BuddyNext — Gutenberg Blocks

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

All core BuddyNext surfaces delivered as Gutenberg blocks. No shortcodes. No page templates forced on users. Drop any block on any page.

---

## Block Catalog (All Free)

### Social / Feed
| Block | Purpose |
|-------|---------|
| Activity Feed | Home feed, profile feed, space feed, or explore — scope via block setting |
| Post Composer | "What's on your mind" post creation box |
| Trending Hashtags | Trending topics list / cloud |

### People
| Block | Purpose |
|-------|---------|
| Member Directory | Full filterable member grid/list |
| Member Card | Single user card (avatar, name, follow button) — for widgets/sidebars |
| Follow Button | Standalone follow/unfollow button for any user |
| Connection Button | Standalone connect/disconnect button |

### Spaces
| Block | Purpose |
|-------|---------|
| Space Directory | Filterable grid of spaces (by category, membership) |
| Space Card | Single space card — for featured space callouts |
| My Spaces | List of spaces the current user is a member of |

### Profile
| Block | Purpose |
|-------|---------|
| Profile Header | Avatar, cover photo, name, bio, social links, follow/connect button |
| Profile Fields | Render one field group (basic info, work experience, etc.) |
| Profile Completion Bar | Progress bar + prompt cards |

### Utility
| Block | Purpose |
|-------|---------|
| Registration Form | Signup form embeddable on any page |
| Login Form | Login form (wraps wp_login_form) |
| Notification Bell | Bell icon + unread count — for custom header areas |
| Search Bar | Unified search input — opens grouped results |

---

## Block Patterns

Pre-built page layouts using the blocks above:
- Community Home page (feed + space directory sidebar)
- Member Profile page
- Spaces Directory page
- Member Directory page

Patterns are registered, not forced. Admin can use them or build their own.

---

## Dynamic vs. Static

All BuddyNext blocks are **dynamic** (PHP server-rendered) — content is always fresh, no stale static output in the block editor.

---

## Addon Block Extensions

When addons are active, they add their own blocks that slot into BuddyNext pages:
- WPMediaVerse: Media Feed block, Upload block, Media Card block
- Jetonomy: Discussions block, Forum block (via space tab), Hot Topics block (native Jetonomy block — bridge makes it available inside BuddyNext space layouts, auto-scoped to the space's linked forum)
- WBGamification: Leaderboard block, Points block, Badges block, Progress block
- Career Board: Job Listings block, Job Application Form block

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| All features | Blocks are the UI delivery layer — they render feature outputs |
| WordPress Interactivity API | Feed, bell, directory all use Interactivity API for reactivity |
| Theme compatibility | Blocks declare `supports.color`, `supports.typography`, `supports.spacing` — inherit active theme settings in editor + frontend via block `supports` |

---

## Gaps / Open Questions

- None — fully locked
