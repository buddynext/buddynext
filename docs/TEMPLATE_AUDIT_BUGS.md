# BuddyNext Template Audit — Bug Report

**Audited:** 2026-03-25
**Total templates:** 27
**Total gaps found:** 45+

---

## P0 — CRITICAL: Dead Buttons (8 missing Interactivity API JS stores)

Templates use `data-wp-interactive="buddynext/{store}"` but NO JavaScript store is registered. Every `data-wp-on--click` button in these templates does NOTHING when clicked.

| # | Store | Template(s) | Dead buttons | Status |
|---|---|---|---|---|
| P0-1 | `buddynext/auth` | `auth/login.php` | Tab switch (Login/Register) — 2 buttons | - [ ] |
| P0-2 | `buddynext/connections` | `profile/connections.php` | Connection actions | - [ ] |
| P0-3 | `buddynext/gamification` | `gamification/leaderboard.php` | Leaderboard interactions | - [ ] |
| P0-4 | `buddynext/moderation` | `moderation/queue.php` | Report queue actions — 7 buttons | - [ ] |
| P0-5 | `buddynext/moderation` | `spaces/moderation.php` | View/Dismiss/Remove/Warn — 4 buttons | - [ ] |
| P0-6 | `buddynext/onboarding` | `onboarding/index.php` | Skip/Next/Avatar/Interests — 14 buttons | - [ ] |
| P0-7 | `buddynext/search` | `search/results.php` | Follow/Space join toggles — 2 buttons | - [ ] |
| P0-8 | `buddynext/space-members` | `spaces/members.php` | Member role/remove actions | - [ ] |

**Fix:** Create 8 JS store files in `assets/js/{feature}/store.js`, register in `AssetService`, implement actions that call the correct REST endpoints.

---

## P1 — HIGH: Wrong `:root` Token Overrides (19 templates)

Every template below has an inline `<style>` block with a `:root` that overrides TokenService with WRONG values:
- `--text-xs: 11px` (canonical: `12px`)
- `--text-sm: 13px` (canonical: `14px`)
- `--text-base: 15px` (canonical: `16px`)
- `--radius-sm: 6px; --radius: 10px; --radius-lg: 14px` (should use `var(--r-sm)`, `var(--r-md)`, `var(--r-lg)`)
- Full `[data-theme="dark"]` block duplicating TokenService dark mode

| # | Template | Status |
|---|---|---|
| P1-1 | `feed/home.php` | - [ ] |
| P1-2 | `feed/explore.php` | - [ ] |
| P1-3 | `hashtags/feed.php` | - [ ] |
| P1-4 | `spaces/home.php` | - [ ] |
| P1-5 | `spaces/directory.php` | - [ ] |
| P1-6 | `spaces/settings.php` | - [ ] |
| P1-7 | `spaces/moderation.php` | - [ ] |
| P1-8 | `spaces/members.php` | - [ ] |
| P1-9 | `profile/view.php` | - [ ] |
| P1-10 | `profile/edit.php` | - [ ] |
| P1-11 | `profile/connections.php` | - [ ] |
| P1-12 | `directory/members.php` | - [ ] |
| P1-13 | `notifications/index.php` | - [ ] |
| P1-14 | `messages/requests.php` | - [ ] |
| P1-15 | `messages/thread.php` | - [ ] |
| P1-16 | `search/results.php` | - [ ] |
| P1-17 | `onboarding/index.php` | - [ ] |
| P1-18 | `gamification/leaderboard.php` | - [ ] |
| P1-19 | `moderation/queue.php` | - [ ] |

**Fix:** Replace each `:root` block with canonical token aliases only (same pattern as `community-admin.php`):
```css
:root {
    --radius-sm: var(--r-sm);
    --radius:    var(--r-md);
    --radius-lg: var(--r-lg);
}
```
Remove ALL hardcoded `--text-*`, `--bg`, `--border`, `--brand`, color, and spacing values.
Remove ALL `[data-theme="dark"]` blocks (TokenService handles dark mode).

---

## P2 — HIGH: Raw Cross-Plugin SQL

| # | Template | Line | Table | Issue | Status |
|---|---|---|---|---|---|
| P2-1 | `hashtags/feed.php` | 909-918 | `wp_jt_posts` | Wrong column `jp.user_id` (should be `author_id`). Raw SQL against Jetonomy tables. Causes DB error. | - [ ] |

**Fix:** Remove entire Jetonomy query block. Replace with `apply_filters('buddynext_hashtag_related_discussions', [], $hashtag_slug)` — bridge provides data (BLOCK HT).

---

## P3 — MEDIUM: Google Fonts @import in Inline Styles

| # | Template | Line | Status |
|---|---|---|---|
| P3-1 | `messages/requests.php` | 135 | - [ ] |
| P3-2 | `messages/thread.php` | 232 | - [ ] |
| P3-3 | `directory/members.php` | 227 | - [ ] |

**Fix:** Remove `@import url('https://fonts.googleapis.com/...')` lines. Fonts loaded via `AssetService` → `bn-fonts` handle.

---

## P4 — MEDIUM: Hub Shell Missing (15 templates lack sidebar + consistent layout)

Only 4 templates use `bn-hub-shell`: `feed/home.php`, `feed/explore.php`, `messages/list.php`, `community-admin.php`.

All other templates render without the hub shell grid, meaning no community sidebar. These should be evaluated per-page — some (like auth/onboarding) intentionally skip the shell.

**Templates that SHOULD have hub shell:**
- `profile/view.php` — user profile with sidebar
- `spaces/home.php` — space feed with sidebar
- `spaces/directory.php` — space listing with sidebar
- `directory/members.php` — member listing with sidebar
- `search/results.php` — search with sidebar
- `hashtags/feed.php` — hashtag feed with sidebar
- `notifications/index.php` — already has its own sidebar
- `gamification/leaderboard.php` — leaderboard with sidebar

**Templates that correctly skip hub shell:**
- `auth/login.php` — pre-login, centered layout
- `auth/verify.php` — verification, centered layout
- `onboarding/index.php` — wizard, full-width
- `spaces/settings.php` — admin panel, own layout
- `spaces/moderation.php` — admin panel, own layout
- `moderation/queue.php` — admin panel, own layout
- `messages/thread.php` — replaced by MVS chat
- `messages/requests.php` — replaced by MVS chat

---

## Execution Plan

**Phase A — Missing JS Stores (P0)**
Fix 8 missing stores. Each store needs:
1. `assets/js/{feature}/store.js` — register store with actions
2. Each action calls the correct BuddyNext REST endpoint
3. Each action updates UI on success (toggle class, update count, show confirmation)
4. Register in `AssetService::register_script_modules()`
5. Browser-verify: click every button, confirm REST call + UI update

**Phase B — Token Cleanup (P1)**
Fix 19 templates. For each:
1. Replace `:root` block with alias-only version
2. Remove `[data-theme="dark"]` block
3. Remove Google Fonts `@import` if present
4. Browser-verify: page looks correct, dark mode works

**Phase C — Cross-Plugin Fix (P2)**
1. Remove raw `jt_posts` query from `hashtags/feed.php`
2. Add `buddynext_hashtag_related_discussions` filter
3. Wire JetonomyBridge to provide data

**Phase D — Hub Shell Rollout (P4)**
Add `bn-hub-shell` + sidebar to 8 additional templates.
