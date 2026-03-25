# UX Audit — All 3 Plugins (BuddyNext + Jetonomy + WPMediaVerse)

**Date:** 2026-03-25
**Goal:** Facebook-alternative SaaS community platform — premium, modern, unified

---

## Template Inventory

### BuddyNext (47 templates)
| Template | Page | Status | Key Issues |
|----------|------|--------|------------|
| `feed/home.php` | Activity Feed | Working | Inline `<style>` ~350 lines should move to bn-feed.css |
| `feed/explore.php` | Explore Feed | Working | Same inline style issue |
| `profile/view.php` | User Profile | Working | Media tab shows "Media" label but has no content — just shows Posts tab data |
| `profile/edit.php` | Edit Profile | Untested | max-width: 900px hardcoded |
| `profile/connections.php` | Connections List | Working | — |
| `directory/members.php` | Member Directory | Working | Inline `<style>` ~300 lines |
| `spaces/home.php` | Space Feed | Working | max-width: 1060px hardcoded (should be --bn-container) |
| `spaces/directory.php` | Spaces Directory | Working | — |
| `spaces/members.php` | Space Members | Working | — |
| `spaces/settings.php` | Space Settings | Untested | max-width: 900px hardcoded |
| `spaces/moderation.php` | Space Moderation | Untested | max-width: 1060px hardcoded |
| `notifications/index.php` | Notifications | Working | — |
| `messages/list.php` | Message List | Partial | Thin wrapper — depends on MVS chat components |
| `messages/thread.php` | Message Thread | Partial | 1200+ lines inline CSS — needs MVS chat to work |
| `messages/requests.php` | Message Requests | Partial | max-width: 640px hardcoded |
| `search/results.php` | Search Results | Working | — |
| `hashtags/feed.php` | Hashtag Feed | Working | ~1100 lines — heavy inline CSS |
| `gamification/leaderboard.php` | Leaderboard | Working | — |
| `moderation/queue.php` | Mod Queue | Untested | — |
| `community-admin.php` | Community Admin | Untested | — |
| `auth/login.php` | Login/Register | Working | max-width: 380px (appropriate) |
| `auth/verify.php` | Email Verification | Working | max-width: 440px (appropriate) |
| `onboarding/index.php` | Member Onboarding | Working | — |
| `partials/nav.php` | Community Nav Bar | Working | — |
| `partials/composer.php` | Post Composer | Working | Media upload fixed this session |
| `partials/post-card.php` | Post Card | Working | Has inline comment form + MVS lightbox |
| `partials/sidebar.php` | Community Sidebar | Working | — |
| `partials/follow-button.php` | Follow Button | Working | — |
| `partials/connection-button.php` | Connect Button | Working | — |
| `partials/profile-actions.php` | Profile Edit Bar | Working | — |
| `blocks/*.php` (17 files) | Gutenberg Blocks | Partial | SSR blocks — most render but no editor preview |

### Jetonomy (23 templates)
| Template | Page | Status | Key Issues |
|----------|------|--------|------------|
| `views/home.php` | Forum Home | Working | Container width fixed via BN bridge |
| `views/space.php` | Space (Topic List) | Working | — |
| `views/single-post.php` | Thread View | Working | — |
| `views/category.php` | Category View | Working | — |
| `views/search.php` | Forum Search | Working | — |
| `views/leaderboard.php` | Forum Leaderboard | Working | — |
| `views/notifications.php` | Forum Notifications | Working | — |
| `views/user-profile.php` | Forum User Profile | Working | Post count was 0 — fixed this session |
| `views/edit-profile.php` | Edit Forum Profile | Untested | — |
| `views/new-post.php` | New Discussion | Working | Rich editor with markdown |
| `views/space-members.php` | Space Members | Working | — |
| `views/space-roadmap.php` | Space Roadmap | Partial | Ideas/roadmap kanban |
| `views/moderation.php` | Mod Dashboard | Untested | — |
| `views/invite.php` | Invite Link | Working | — |
| `views/tag.php` | Tag View | Working | — |
| `partials/header.php` | Forum Nav | Working | A/A+/A++ added this session |
| `partials/sidebar.php` | Forum Sidebar | Working | — |
| `partials/post-card.php` | Discussion Card | Working | — |
| `partials/reply-card.php` | Reply Card | Working | — |
| `partials/composer.php` | Reply Composer | Working | Markdown editor |
| `partials/avatar.php` | Avatar Helper | Working | — |
| `partials/breadcrumb.php` | Breadcrumb | Working | — |
| `partials/pagination.php` | Pagination | Working | — |

### WPMediaVerse (15 templates)
| Template | Page | Status | Key Issues |
|----------|------|--------|------------|
| `explore.php` | Media Explore | Working | Grid + lightbox |
| `dashboard.php` | User Dashboard | Working | Upload, albums, collections |
| `media-single.php` | Single Media | Working | Full detail view |
| `album.php` | Album View | Working | — |
| `collection.php` | Collection View | Working | — |
| `messages.php` | Chat (Standalone) | Partial | Hidden when BN active |
| `profile-edit.php` | Profile Edit | Working | — |
| `partials/chat-list.php` | Chat List | Working | — |
| `partials/chat-conversation.php` | Chat Thread | Working | — |
| `partials/chat-composer.php` | Chat Input | Working | — |
| `partials/chat-message.php` | Single Message | Working | — |
| `partials/chat-media-card.php` | Media in Chat | Working | — |
| `partials/chat-new.php` | New Chat | Working | — |
| `partials/chat-panel.php` | Chat Panel | Working | — |
| `partials/dashboard-content.php` | Dashboard Content | Working | Heavy — upload UI, grid, settings |

---

## Critical Issues for Presentation

### P0 — Broken / Non-functional

| # | Issue | Plugin | Impact |
|---|-------|--------|--------|
| 1 | **Profile Media tab is empty** — tab exists but clicking "Media" shows nothing. No query fetches user's media from WPMediaVerse. | BuddyNext | Profile feels incomplete |
| 2 | **Profile Replies tab is empty** — no query fetches user's replies/comments. | BuddyNext | Same |
| 3 | **Profile Likes tab is empty** — no query fetches user's liked/reacted posts. | BuddyNext | Same |
| 4 | **Inline comments don't expand** — clicking "Comment" calls `actions.openComments` but the comment section below the post card doesn't visually expand (CSS `display:none` not toggled). | BuddyNext | Core social feature broken |
| 5 | **Space "Media" tab missing** — setting exists in space settings but no tab renders in space home. | BuddyNext | Spaces feel incomplete |

### P1 — Half-baked / Missing Premium Feel

| # | Issue | Plugin | Impact |
|---|-------|--------|--------|
| 6 | **Massive inline `<style>` blocks** — feed/home.php (~350 lines), explore.php (~500 lines), hashtags/feed.php (~600 lines), profile/view.php (~500 lines), members.php (~300 lines), notifications/index.php (~300 lines). Each template has its own CSS that should be in external files. | BuddyNext | Slow page loads, unmaintainable |
| 7 | **No hover/transition effects on post cards** — cards are flat, no elevation change on hover, no smooth transitions. LinkedIn/Facebook cards lift on hover. | BuddyNext | Feels static, not interactive |
| 8 | **Action bar text labels** — "React", "Comment", "Share", "Save" take too much space. Premium platforms use icon-only with counts. | BuddyNext | Looks dated |
| 9 | **No link preview cards** — `link_meta` JSON exists in DB but no preview rendering (title, image, domain) in post card. | BuddyNext | Text-only link posts look empty |
| 10 | **Avatar diversity** — most users show colored initials circles. No real avatar upload flow tested. | All 3 | Looks like demo data |
| 11 | **No notification badges on nav** — notification count not shown on the bell icon in nav bar. Message count not shown. | BuddyNext | Users don't know they have unread items |
| 12 | **Spaces have no media tab** — even when WPMediaVerse is active, spaces don't show uploaded media in a gallery tab. | BuddyNext | Spaces feel text-only |

### P2 — Container / Layout Inconsistencies

| # | Issue | Template | Fix |
|---|-------|----------|-----|
| 13 | `spaces/home.php` has max-width: 1060px | spaces/home.php:365 | Change to var(--bn-container) |
| 14 | `spaces/moderation.php` has max-width: 1060px | spaces/moderation.php:315 | Same |
| 15 | `spaces/settings.php` has max-width: 900px | spaces/settings.php:303 | Appropriate for settings form |
| 16 | `profile/edit.php` has max-width: 900px | profile/edit.php:164 | Appropriate for edit form |
| 17 | `messages/requests.php` max-width: 640px | messages/requests.php:149 | Appropriate for message list |

### P3 — Integration Gaps

| # | Issue | Plugins | Impact |
|---|-------|---------|--------|
| 18 | **Profile doesn't show MVS media** — no Media tab content fetching from WPMediaVerse `mvs_media` CPT for the profile user. | BN + MVS | Profile incomplete |
| 19 | **Space doesn't show MVS media gallery** — no tab fetching space media uploads. | BN + MVS | Spaces incomplete |
| 20 | **Forum discussions not visible on profile** — Jetonomy posts by user not shown in profile "Discussions" section. Bridge adds count stat but no actual post list. | BN + JT | Cross-plugin incomplete |
| 21 | **No unified search** — BN search indexes BN posts. JT search indexes JT posts. MVS search indexes MVS media. No single search that spans all 3. | All 3 | Users must search 3 places |
| 22 | **Notification badges** — unread notification count exists in DB query (nav.php line ~37) but the badge number isn't styled/visible in the nav. | BuddyNext | Silent notifications |

---

## Priority Execution Plan

### Wave 1 — Fix Broken Features (P0)
1. Profile tabs: wire Media (fetch MVS media), Replies (fetch bn_comments), Likes (fetch bn_reactions)
2. Inline comment expansion — wire openComments to toggle comment list visibility + load comments via REST
3. Space Media tab — fetch MVS media for space

### Wave 2 — Premium Polish (P1)
4. Move all inline `<style>` to external CSS files (bn-feed.css, bn-profile.css, etc.)
5. Post card hover elevation + transitions
6. Icon-only action bar with counts
7. Link preview card rendering from link_meta JSON
8. Notification badge on nav bell icon

### Wave 3 — Deep Integration (P3)
9. Profile Media tab → query mvs_media by author
10. Space Media tab → query mvs_media by space
11. Profile Discussions → query jt_posts by author
12. Unified search bridge across all 3 plugins

### Wave 4 — Container Cleanup (P2)
13. Replace remaining hardcoded max-widths with var(--bn-container)
14. Move remaining inline CSS to external files
