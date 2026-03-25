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

## Premium UX Benchmark — Circle.so / Mighty Networks / Bettermode

What makes SaaS community platforms feel premium vs. our current state:

| Pattern | Circle/Mighty | BuddyNext Current | Gap |
|---------|--------------|-------------------|-----|
| Page transitions | SPA — no full reloads | Full page reload every click | Large |
| Post composer | Rich text, @mentions, emoji, drag-drop, inline preview | Plain textarea + file picker | Large |
| Inline comments | Expand under post, threaded, real-time | Click calls JS but doesn't expand visually | Medium — wiring exists |
| Notification UX | Bell → dropdown panel, mark read inline | Bell → full page navigation | Medium |
| Card interactions | Hover lift, like animation, toast confirmations | Flat, no transitions, no feedback | Small — CSS only |
| Action bar | Icon-only with counts, tooltip on hover | Text labels (React, Comment, Share, Save) | Small — CSS only |
| Link previews | Auto-fetch OG image + title + domain | link_meta exists in DB, never rendered | Medium |
| User hover cards | Hover avatar → mini profile popup | Click navigates to full profile page | Medium |
| Empty states | SVG illustrations, personality text | Plain "No posts yet" text | Small |
| Skeleton loading | Shimmer placeholder cards on page load | Blank → instant render (flash) | Small — CSS only |
| Real-time | New posts appear, typing indicators, presence dots | Static — requires page refresh | Large — needs WebSocket |
| Search | Unified overlay, all content types, instant results | Separate page, BN posts only | Large |
| Mobile nav | Bottom tab bar, swipe gestures | Top nav (scrolls away), no gestures | Medium |
| Keyboard shortcuts | / search, n new post, j/k navigate posts | None | Small |
| Dark mode | Smooth toggle, persisted, system-aware | Toggle exists but dark mode incomplete | Medium |
| Onboarding | Guided tour, progress bar, personalized feed | 4-step wizard exists but no guided tour | Medium |

---

## Execution Plan — 6 Phases

### Phase 1 — Core Social UX (P0 fixes — make it work)

Everything a user tries must work. Zero dead clicks.

| # | Task | Plugin | Files |
|---|------|--------|-------|
| 1.1 | **Inline comment expansion** — openComments toggles comment section visible, fetches from `GET /comments?object_type=post&object_id={id}`, renders via `buildCommentNode()`, submit wired to `POST /comments` | BN | `post-card.php`, `feed/store.js`, `bn-feed.css` |
| 1.2 | **Profile Media tab** — fetch `mvs_media` CPT where `post_author = user_id`, render as MVS grid with lightbox | BN + MVS | `profile/view.php` |
| 1.3 | **Profile Replies tab** — fetch `bn_comments` where `user_id = profile_user_id`, show post title + comment excerpt | BN | `profile/view.php` |
| 1.4 | **Profile Likes tab** — fetch `bn_reactions` where `user_id = profile_user_id`, show reacted posts | BN | `profile/view.php` |
| 1.5 | **Space Media tab** — add "Media" tab to space tabs, fetch MVS media where meta `_mvs_space_id = space_id` or uploaded in space context | BN + MVS | `spaces/home.php` |
| 1.6 | **Notification badge count** — style the unread count badge on nav bell icon (query already exists) | BN | `partials/nav.php` |
| 1.7 | **Message unread count** — show badge on Messages nav link | BN + MVS | `partials/nav.php` |

### Phase 2 — Visual Polish (make it feel premium)

CSS-level changes, no structural rewrites. Biggest visual impact for presentation.

| # | Task | Effort | Files |
|---|------|--------|-------|
| 2.1 | **Post card elevation + hover** — `box-shadow: 0 1px 3px rgba(0,0,0,0.04)`, hover: `0 4px 12px rgba(0,0,0,0.08)` + `translateY(-1px)`, `transition: all 0.2s ease` | 30m | `bn-feed.css` |
| 2.2 | **Icon-only action bar** — hide text labels, show icon + count only, tooltip on hover via `title` attr | 30m | `post-card.php`, `bn-feed.css` |
| 2.3 | **Smooth transitions** — add `transition: 0.15s ease` to all interactive elements (buttons, links, cards, inputs) | 30m | `bn-base.css`, `bn-feed.css` |
| 2.4 | **Skeleton loading** — CSS-only shimmer placeholder for post cards, member cards, sidebar widgets shown during page paint | 1h | `bn-feed.css`, `bn-base.css` |
| 2.5 | **Beautiful empty states** — SVG illustration + friendly copy for: empty feed, no search results, no members, empty space, no notifications | 1h | Multiple templates |
| 2.6 | **Toast notifications** — floating bottom-center toast for: "Post published", "Comment added", "Followed user", "Bookmarked". Auto-dismiss after 3s. | 1h | `assets/js/feed/store.js`, `bn-base.css` |
| 2.7 | **Sidebar card refinement** — softer borders, section headers in small caps with letter-spacing, "See all" links more subtle | 30m | `bn-base.css` |
| 2.8 | **Nav bar polish** — slim height (40px), active item bottom-border indicator, proper icon alignment, notification badges | 30m | `partials/nav.php`, `bn-base.css` |

### Phase 3 — Rich Content (make posts interesting)

| # | Task | Effort | Files |
|---|------|--------|-------|
| 3.1 | **Link preview cards** — parse `link_meta` JSON, render OG image + title + description + domain in a card below post text | 2h | `post-card.php`, `bn-feed.css` |
| 3.2 | **@mention rendering** — detect `@username` in post content, linkify to profile URL with hover card trigger | 1h | `post-card.php` |
| 3.3 | **#hashtag rendering** — detect `#tag` in content, linkify to hashtag feed (already partially done) | 30m | `post-card.php` |
| 3.4 | **User hover cards** — CSS popup on avatar/name hover showing mini profile: avatar, name, bio, follower count, follow button | 2h | New partial `partials/hover-card.php`, `bn-feed.css` |
| 3.5 | **Notification dropdown panel** — bell click opens dropdown overlay (not full page nav), show last 5 notifications, "See all" links to full page | 2h | `partials/nav.php`, `assets/js/notifications/store.js` |
| 3.6 | **Photo gallery layout** — 1 photo: full-width, 2: side by side, 3: 1 big + 2 small, 4: 2x2 grid (Instagram pattern) | 1h | `post-card.php`, `bn-feed.css` |

### Phase 4 — Deep Integration (cross-plugin unified experience)

| # | Task | Effort | Plugins |
|---|------|--------|---------|
| 4.1 | **Unified search overlay** — cmd+K / ctrl+K opens search overlay, searches across BN posts + JT discussions + MVS media in tabs | 4h | All 3 |
| 4.2 | **Profile activity timeline** — merge BN posts + JT discussions + MVS uploads into single chronological timeline on profile | 3h | All 3 |
| 4.3 | **Space unified feed** — space feed shows BN posts + JT discussions + MVS media in single stream | 3h | All 3 |
| 4.4 | **Cross-plugin notifications** — JT reply, MVS comment, MVS reaction all appear in BN notification center | 2h | All 3 |
| 4.5 | **Unified user card** — hover card shows BN follower count + JT reputation + MVS media count | 2h | All 3 |

### Phase 5 — Modern Interactions (SPA-like feel without SPA)

| # | Task | Effort | Impact |
|---|------|--------|--------|
| 5.1 | **HTMX partial page swaps** — add `hx-get` + `hx-target="#bn-main"` on nav links so only the content area swaps, nav/sidebar persist. Lightweight (14kb), no build step, works with PHP templates. Falls back to normal navigation gracefully. | 4h | Huge — SPA feel |
| 5.2 | **Infinite scroll with cursor pagination** — load more posts on scroll, preserve scroll position on back | 2h | High |
| 5.3 | **Keyboard shortcuts** — `/` search, `n` new post, `j`/`k` navigate posts, `l` like, `Esc` close modals | 2h | Medium — power users |
| 5.4 | **Mobile bottom tab bar** — on ≤640px, move nav to fixed bottom bar (5 icons: Feed, Spaces, Create+, Notifications, Profile) | 3h | High — mobile UX |
| 5.5 | **Swipe gestures on mobile** — swipe left on post card for quick actions (bookmark, share) | 2h | Medium |
| 5.6 | **Optimistic UI** — like/bookmark/follow update instantly, revert on API failure | 2h | High — feels instant |

### Phase 6 — Premium Features (SaaS differentiators)

| # | Task | Effort | Impact |
|---|------|--------|--------|
| 6.1 | **Rich text composer** — TipTap or ProseMirror editor with inline formatting, @mentions autocomplete, emoji picker, link auto-embed | 8h | Huge |
| 6.2 | **Real-time updates** — Soketi/Pusher WebSocket for live post feed, typing indicators, presence dots | 8h | Huge — feels alive |
| 6.3 | **Guided onboarding tour** — first-login overlay highlighting Feed, Spaces, Profile with step-by-step progression | 3h | Medium |
| 6.4 | **Custom reactions** — admin-configurable reaction set (not just 6 emoji), animated reaction picker | 3h | Medium |
| 6.5 | **Content scheduling** — compose now, publish later with date picker | 2h | Medium |
| 6.6 | **Pin posts to space/feed** — admin/mod pins important announcements | 1h | Medium |
| 6.7 | **Polls with real-time results** — vote → bar animates to new percentage without reload | 2h | High |
| 6.8 | **Thread/discussion mode** — long-form post with structured replies (like Reddit threads) | 4h | High |

---

---

## Container & Width Rules (Single Source of Truth)

Blocks, templates, and widgets follow different width rules depending on where they render:

```
┌─────────────────────────────────────────────────────────────────┐
│ Theme Header (full viewport width — theme controls)             │
├─────────────────────────────────────────────────────────────────┤
│ BuddyNext Nav Bar (.bn-subnav-inner: --bn-container centered)  │
├─────────────────────────────────────────────────────────────────┤
│ Context Nav (.bn-context-nav__inner: --bn-container centered)   │
├─────────────────────────────────────────────────────────────────┤
│                    --bn-container (1100px)                       │
│ ┌──────────────────────────────┐ ┌───────────────────┐          │
│ │ Content (1fr)                │ │ Sidebar (300px)   │          │
│ │                              │ │ Trending Topics   │          │
│ │ Post cards, member grid,     │ │ People to Follow  │          │
│ │ space feed, notifications    │ │ Your Spaces       │          │
│ │                              │ │                   │          │
│ └──────────────────────────────┘ └───────────────────┘          │
├─────────────────────────────────────────────────────────────────┤
│ Theme Footer (full viewport width — theme controls)             │
└─────────────────────────────────────────────────────────────────┘
```

### Width Rules

| Context | Width | CSS | Who controls |
|---------|-------|-----|-------------|
| **Community pages** (feed, members, spaces, notifications, messages, search, hashtags, leaderboard) | `--bn-container` (1100px) with 1fr + 300px sidebar | `.bn-hub-shell` | BuddyNext `bn-base.css` |
| **Profile page** | `--bn-container` (1100px) full-width, no sidebar | `.bn-profile-container` | BuddyNext `bn-base.css` |
| **Jetonomy pages** inside BuddyNext | `--bn-container` override on `.jt-container` | `.bn-jt-content .jt-container` | BuddyNext `bn-base.css` |
| **MVS pages** inside BuddyNext | Hub shell controls width | `.bn-hub-shell > .bn-mvs-content` | BuddyNext `bn-base.css` |
| **Blocks on landing pages** | `width: 100%` — fills theme column | Block has NO max-width | Theme |
| **Blocks in sidebar** | Sidebar width (typically 300-360px) | Block is `width: 100%` | Theme sidebar |
| **Blocks in footer** | Footer column width | Block is `width: 100%` | Theme footer |
| **Settings/edit forms** | 900px max (narrower for readability) | Per-template `.bn-*-form` | Template |

### Block Width Rule (CRITICAL)

**Blocks must NEVER set their own max-width.** They render at `width: 100%` of their parent container. The parent decides the width:
- On a community page: hub shell gives it the 1fr column
- On a landing page: theme's content column gives it whatever width
- In a sidebar: theme sidebar gives it 300px
- In a footer: theme footer column gives it that width

```css
/* CORRECT — block fills parent */
.bn-block-activity-feed { width: 100%; }

/* WRONG — block sets its own width */
.bn-block-activity-feed { max-width: 800px; margin: 0 auto; }
```

---

## Widgets & Blocks Strategy

Site owners need to embed community features on any page — landing pages, sidebars, footers, WooCommerce shops, LMS course pages. Blocks are the primary delivery mechanism.

### Current Inventory

| Plugin | Blocks | Shortcodes | Widgets | Gap |
|--------|--------|------------|---------|-----|
| **BuddyNext** | 17 registered | 0 | 0 | Blocks exist but many are SSR stubs — need real editor previews |
| **WPMediaVerse** | 12 built (src + build) | Has shortcodes | 0 | Full build pipeline, Interactivity API |
| **Jetonomy** | 0 | 0 | 0 | **Critical gap — no way to embed forum content anywhere** |

### BuddyNext Blocks (17) — Audit

| Block | Category | Renders | Editor Preview | Sidebar-safe | Priority |
|-------|----------|---------|----------------|-------------|----------|
| `bn-activity-feed` | Content | Yes (uses shared post-card) | SSR placeholder | No (needs full width) | P1 |
| `bn-post-composer` | Content | Yes | SSR placeholder | No | P1 |
| `bn-member-directory` | Directory | Yes | SSR placeholder | No | P2 |
| `bn-space-directory` | Directory | Yes | SSR placeholder | No | P2 |
| `bn-profile-header` | Profile | Yes | SSR placeholder | No | P2 |
| `bn-member-card` | Widget | Yes | SSR placeholder | **Yes** | P1 — sidebar/footer |
| `bn-space-card` | Widget | Yes | SSR placeholder | **Yes** | P1 — sidebar/footer |
| `bn-follow-button` | Widget | Yes | SSR placeholder | **Yes** | P1 |
| `bn-connection-button` | Widget | Yes | SSR placeholder | **Yes** | P1 |
| `bn-notification-bell` | Widget | Yes | SSR placeholder | **Yes** | P1 — header/nav |
| `bn-search-bar` | Widget | Yes | SSR placeholder | **Yes** | P1 — header/nav |
| `bn-trending-hashtags` | Widget | Yes | SSR placeholder | **Yes** | P1 — sidebar |
| `bn-my-spaces` | Widget | Yes | SSR placeholder | **Yes** | P1 — sidebar |
| `bn-login-form` | Auth | Yes | SSR placeholder | **Yes** | P1 |
| `bn-registration-form` | Auth | Yes | SSR placeholder | No | P2 |
| `bn-profile-fields` | Profile | Yes | SSR placeholder | No | P3 |
| `bn-profile-completion-bar` | Widget | Yes | SSR placeholder | **Yes** | P2 |

### WPMediaVerse Blocks (12) — Already Built

| Block | Category | Sidebar-safe |
|-------|----------|-------------|
| `media-grid` | Content | **Yes** — responsive grid |
| `media-upload` | Content | No |
| `media-player` | Content | **Yes** |
| `media-social` | Widget | **Yes** |
| `media-stats` | Widget | **Yes** |
| `explore-feed` | Content | No |
| `explore-view` | Content | No |
| `dashboard-view` | Content | No |
| `album-viewer` | Content | No |
| `story-viewer` | Content | No |
| `lock-overlay` | Utility | **Yes** |
| `shared-ui` | Utility | — |

### Jetonomy Blocks — NEED TO CREATE

| Block | Use Case | Priority |
|-------|----------|----------|
| `jt-recent-discussions` | Sidebar/page — shows latest 5 discussions with title, reply count, author | P1 |
| `jt-popular-discussions` | Sidebar/page — top discussions by vote score | P1 |
| `jt-space-list` | Sidebar/page — list of forum spaces with post counts | P1 |
| `jt-leaderboard-widget` | Sidebar — top 5 contributors with points | P2 |
| `jt-new-post-button` | Page — CTA button to create new discussion | P2 |
| `jt-tag-cloud` | Sidebar — popular tags | P2 |
| `jt-user-stats` | Sidebar — logged-in user's forum stats (posts, replies, reputation) | P2 |
| `jt-single-space` | Page — embed a specific space's topic list | P3 |
| `jt-search-bar` | Header/sidebar — forum search | P2 |

### Cross-Plugin Widget Blocks — NEW

These combine data from all 3 plugins into unified widgets:

| Block | Source | Use Case | Priority |
|-------|--------|----------|----------|
| `bn-community-feed` | BN + JT + MVS | Unified feed showing posts + discussions + media uploads in one stream | P2 |
| `bn-community-stats` | BN + JT + MVS | Site-wide stats: X members, Y posts, Z discussions, W media items | P1 |
| `bn-online-members` | BN | Show currently active/recent members with avatars | P1 |
| `bn-community-cta` | BN | "Join our community" CTA with member count + space count + sign-up button | P1 — landing pages |
| `bn-recent-activity` | BN + JT | Compact activity stream for sidebars — "Alice posted in Tech Talk", "Bob replied to..." | P1 |

### Widget Placement Strategy

Where site owners will want to place these:

```
Landing Page:
┌─────────────────────────────────────────────┐
│ [bn-community-cta]                          │ — Hero section: "Join 500+ members"
│ [bn-activity-feed scope="explore" limit=3]  │ — Preview of community activity
│ [bn-space-directory limit=6]                │ — Featured spaces
│ [jt-popular-discussions limit=5]            │ — Hot discussions
│ [media-grid limit=8]                        │ — Recent media
└─────────────────────────────────────────────┘

Blog Sidebar:
┌──────────────────────┐
│ [bn-community-stats] │
│ [bn-online-members]  │
│ [bn-trending-hashtags]│
│ [jt-recent-discussions]│
│ [bn-my-spaces]       │
└──────────────────────┘

WooCommerce Shop:
┌──────────────────────┐
│ [bn-community-cta]   │ — "Discuss this product"
│ [jt-space-list]      │ — Product support forums
└──────────────────────┘

LMS Course Page:
┌──────────────────────┐
│ [jt-single-space]    │ — Course discussion embedded
│ [bn-member-card]     │ — Instructor profile card
└──────────────────────┘

Mobile App (Flavor theme / AppBoss):
┌──────────────────────┐
│ Bottom nav icons map  │
│ to BN hub routes      │
│ Blocks render in      │
│ webview containers    │
└──────────────────────┘
```

### Block Development Priority

**Immediate (for presentation):**
1. Jetonomy: `jt-recent-discussions` + `jt-popular-discussions` + `jt-space-list`
2. Cross-plugin: `bn-community-stats` + `bn-community-cta` + `bn-recent-activity`
3. BuddyNext: fix SSR previews for existing 17 blocks (editor shows "BuddyNext: Activity Feed" placeholder, not actual content)

**Post-launch:**
4. Full editor InspectorControls for all blocks (limit, columns, style variant)
5. Block patterns: "Community Landing Page", "Forum Sidebar", "Member Showcase"
6. Full Site Editing: community template parts for block themes

---

## Presentation Readiness Checklist

For a demo as "modern SaaS community alternative":

- [x] Phase 1 complete (all clicks work, zero dead buttons) — DONE 2026-03-25
- [x] Phase 2 mostly complete (hover, transitions, toasts, skeleton, empty states) — DONE 2026-03-25
- [x] Phase 3 complete (notification dropdown, hover cards, @mentions, gallery) — DONE 2026-03-25
- [x] Phase 4 partial (unified search overlay cmd+K, cross-plugin notifications) — DONE 2026-03-25
- [x] Phase 5 partial (mobile bottom nav, optimistic UI, keyboard shortcuts, infinite scroll) — DONE 2026-03-25
- [x] Phase 6 partial (pin posts, poll vote animation) — DONE 2026-03-25
- [ ] Phase 5.1 (htmx partial page swaps — biggest remaining wow factor)
- [ ] 10+ demo users with real avatars and diverse content
- [ ] 3+ spaces with posts, media, and discussions
- [ ] Mobile responsive verified at 390px on all pages
- [ ] Dark mode toggle working on all pages
- [ ] A/A+/A++ scaling verified at all sizes
