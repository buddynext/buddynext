# BuddyNext User Journeys

Canonical end-to-end journey catalogue for BuddyNext Free + Pro. Every Playwright spec under `tests/e2e/` maps to one or more journeys listed here. No tag ships unless this suite is green.

**Source surfaces:** `docs/v2 Plans/v2/` (17 wireframes) - `audit/manifest.summary.json` (115 REST routes, 41 tables) - `audit/ROLE_MATRIX.md` (4-layer permission model).

**Viewport convention:** `desktop` 1440x900, `ipad` 834 (Apple iPad gen 7), `mobile` 390 (iPhone 14). Unless stated, every journey runs across all three.

**Status legend:**
- implemented - spec is wired up and asserts real surface markup
- fixme - written as `test.fixme()` because the feature is Pro-only or not yet exposed in Free
- blocked - dependency is missing (e.g., WPMediaVerse DM bridge inactive)

---

## How to run

```bash
npm ci
npm run test:e2e:install            # one-time: install Playwright browsers
npm run test:e2e                    # full suite, all three projects
npm run test:e2e:desktop            # desktop project only
npm run test:e2e:mobile             # mobile project only
BN_BASE_URL=http://other.local npm run test:e2e
BN_PRO=1 npm run test:e2e           # unmasks Pro fixme journeys
npm run test:e2e:report             # open HTML report
```

See [`HOW-TO-RUN.md`](./HOW-TO-RUN.md) for the longer runbook.

---

## Role: Anon visitor (logged out)

### J-01-anon-explore
- **Role:** Anon
- **Starting state:** Logged out, `GET /`.
- **Steps:**
  1. Visit `/`.
  2. Theme header is rendered (no BN topbar inside `.bn-app`).
  3. Click "Sign in" / login link in the theme header.
- **Acceptance:** Auth page (`/auth/`) loads with `#user_login` field visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-02-anon-redirect-feed
- **Role:** Anon
- **Starting state:** Logged out.
- **Steps:** Hit `/activity/` (the feed hub) directly.
- **Acceptance:** Redirected to the auth page. URL contains `auth` or `wp-login.php`.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-03-anon-spaces-public
- **Role:** Anon
- **Starting state:** Logged out.
- **Steps:** Visit `/spaces/`.
- **Acceptance:** Either renders the public directory listing OR redirects to auth (whichever the site has configured); page returns HTTP 200 either way.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

---

## Role: New member (just signed up)

### J-04-signup
- **Role:** Anon transitioning to New member
- **Starting state:** `/auth/`, logged out.
- **Steps:**
  1. Submit registration form with a fresh `user_login`, `user_email`, `user_pass`.
  2. Wait for success state.
- **Acceptance:** Page shows verification notice OR redirects to onboarding wizard.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-05-email-verify
- **Role:** New member
- **Starting state:** Just signed up; verification token in URL `?bn_verify=<token>`.
- **Steps:** Hit the verify URL.
- **Acceptance:** Page shows "verified" confirmation OR proceeds to onboarding.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - token is generated server-side and cannot be predicted from the test runner. Needs WP-CLI seeded token.

### J-06-onboarding-wizard
- **Role:** New member
- **Starting state:** Logged in, no `bn_onboarded` user meta.
- **Steps:**
  1. Hit `/onboarding/`.
  2. Step 1 - Profile basics (display name, bio). Click Next.
  3. Step 2 - Interests (pick 3+ hashtags). Click Next.
  4. Step 3 - Follow suggestions (pick 3+ members). Click Next.
  5. Step 4 - Join spaces (pick 1+ space). Click Finish.
- **Acceptance:** Redirected to `/activity/`; `bn_onboarded` meta set; feed shows seeded content from followed users.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented (best-guess - wireframe shows 4 panels, exact step IDs assumed)

---

## Role: Existing member (logged in)

### J-07-login
- **Role:** Existing member
- **Starting state:** Logged out, `/auth/`.
- **Steps:** Fill `#user_login` with `varundubey`, fill `#user_pass`, click submit.
- **Acceptance:** Redirected to feed or admin. WP `wordpress_logged_in` cookie set.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-08-login-with-2fa
- **Role:** Existing member
- **Starting state:** Logged out; 2FA enabled on account.
- **Steps:** Login as above, then provide TOTP code on second screen.
- **Acceptance:** Feed loads after second step.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - 2FA is Pro-only (`BN_PRO=1`).

### J-09-password-reset
- **Role:** Existing member who forgot password
- **Starting state:** `/auth/?action=lostpassword`.
- **Steps:** Submit email, expect "check email" confirmation.
- **Acceptance:** Confirmation copy visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-10-logout
- **Role:** Existing member
- **Starting state:** Logged in.
- **Steps:** Hit `/wp-login.php?action=logout&_wpnonce=...` via the theme menu link.
- **Acceptance:** Cookie cleared; `/activity/` now redirects to auth.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-11-feed-home-loads
- **Role:** Existing member
- **Starting state:** Logged in.
- **Steps:** Visit `/activity/`.
- **Acceptance:** `.bn-app` present; `.bn-app__main` contains a feed list; composer visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-12-compose-text-post
- **Role:** Existing member
- **Starting state:** `/activity/`.
- **Steps:** Click composer, type "hello from playwright", click Post.
- **Acceptance:** New post card appears at the top of the feed with the typed content.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-13-compose-link-post
- **Role:** Existing member
- **Steps:** In composer, paste a URL; expect link preview card to appear; submit.
- **Acceptance:** Posted card shows link preview metadata.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented (best-guess - link preview generation depends on async unfurl)

### J-14-compose-poll
- **Role:** Existing member
- **Steps:** Switch composer to poll mode, add question + 2 options, submit.
- **Acceptance:** Poll card visible with both options and a Vote button.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-15-compose-event
- **Role:** Existing member
- **Steps:** Switch composer to event mode, fill title + date, submit.
- **Acceptance:** Event card visible with the event date string.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - event composer surface is wireframed but Free implementation lands in v0.3.

### J-16-react-to-post
- **Role:** Existing member
- **Steps:** Click the like button on the first post card.
- **Acceptance:** Reaction count increments by 1; button toggles to active state.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-17-comment-on-post
- **Role:** Existing member
- **Steps:** Click comment icon on first card; type "great post"; submit.
- **Acceptance:** Comment appears under the post.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-18-bookmark-post
- **Role:** Existing member
- **Steps:** Click bookmark icon on first card.
- **Acceptance:** Button toggles to active.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-19-share-post
- **Role:** Existing member
- **Steps:** Click share icon on first card; popover shows share options.
- **Acceptance:** Popover visible with at least one share-target option.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-20-ai-smart-reply (Pro P2.4)
- **Role:** Existing member, Pro active
- **Steps:** Click an AI smart-reply chip beneath a post.
- **Acceptance:** Chip text is dropped into the comment composer.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - requires `BN_PRO=1`.

### J-21-explore-trending
- **Role:** Existing member
- **Steps:** Visit `/activity/explore/`.
- **Acceptance:** Trending posts list visible; hashtag chips row visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-22-hashtag-follow
- **Role:** Existing member
- **Steps:** Click "Follow" on a hashtag chip in explore.
- **Acceptance:** Button toggles to Following.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-23-hashtag-feed
- **Role:** Existing member
- **Steps:** Visit `/activity/hashtag/playwright/`.
- **Acceptance:** Header shows the hashtag; posts list scoped to that tag.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-24-directory-members
- **Role:** Existing member
- **Steps:** Visit `/members/` (or `/people/`).
- **Acceptance:** Member cards list visible; filter chips visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-25-directory-filter-by-type
- **Role:** Existing member
- **Steps:** Click a member-type filter chip.
- **Acceptance:** Cards list updates; filter chip is active.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-26-directory-search
- **Role:** Existing member
- **Steps:** Type a name into the directory search input; wait for results.
- **Acceptance:** Results list updates with matching cards.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-27-directory-follow-from-card
- **Role:** Existing member
- **Steps:** Click Follow on a member card.
- **Acceptance:** Button toggles to Following.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-28-directory-mute-from-card
- **Role:** Existing member
- **Steps:** Open the more-actions menu on a card; click Mute.
- **Acceptance:** Card UI reflects muted state OR toast appears.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-29-profile-view-own
- **Role:** Existing member
- **Steps:** Visit `/members/varundubey/`.
- **Acceptance:** Hero card, stats row, post tab visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-30-profile-views-widget (Pro P5.3)
- **Role:** Existing member, Pro active
- **Steps:** Visit own profile.
- **Acceptance:** "Who viewed your profile" widget rendered in sidebar.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - Pro only.

### J-31-profile-connection-request
- **Role:** Existing member viewing another user's profile
- **Steps:** Visit another member's profile; click Connect.
- **Acceptance:** Button toggles to Requested.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-32-profile-message-button
- **Role:** Existing member viewing another user's profile
- **Steps:** Click Message on another user's profile.
- **Acceptance:** Navigates to `/messages/?to=<id>` OR opens DM composer.
- **Viewports:** desktop, ipad, mobile
- **Status:** blocked - depends on WPMediaVerse DM bridge being active. Falls through to safe URL assertion.

### J-33-profile-edit-avatar
- **Role:** Existing member
- **Steps:** Visit `/members/varundubey/edit/`; upload avatar.
- **Acceptance:** Avatar preview updates.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-34-profile-edit-bio
- **Role:** Existing member
- **Steps:** Visit profile edit; change bio text; save.
- **Acceptance:** Saved bio visible on view profile.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-35-profile-edit-fields
- **Role:** Existing member
- **Steps:** Edit a custom profile field; save.
- **Acceptance:** Field value displayed on view profile.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-36-profile-theme-picker (Pro)
- **Role:** Existing member, Pro active
- **Steps:** Edit profile; pick a brand theme; save.
- **Acceptance:** Selected theme reflected in profile chrome.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - Pro whitelabel.

### J-37-spaces-directory
- **Role:** Existing member
- **Steps:** Visit `/spaces/`.
- **Acceptance:** Space cards list visible; category filter chips visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-38-spaces-filter-category
- **Role:** Existing member
- **Steps:** Click a category chip.
- **Acceptance:** Cards list updates.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-39-spaces-search
- **Role:** Existing member
- **Steps:** Type a query into the spaces search input.
- **Acceptance:** Results list updates.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-40-space-join-open
- **Role:** Existing member
- **Steps:** Click Join on an open space card.
- **Acceptance:** Button toggles to Joined.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-41-space-request-private
- **Role:** Existing member
- **Steps:** Click Request to join on a private space card.
- **Acceptance:** Button toggles to Requested.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-42-space-home-feed
- **Role:** Existing member, joined space
- **Steps:** Visit `/spaces/general/`.
- **Acceptance:** Space hero + feed visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-43-space-post-in-space
- **Role:** Existing member, joined space
- **Steps:** Compose a post in the space composer.
- **Acceptance:** New card appears in space feed.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-44-space-member-list
- **Role:** Existing member, joined space
- **Steps:** Open space Members tab.
- **Acceptance:** Member list visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-45-dm-list
- **Role:** Existing member
- **Steps:** Visit `/messages/`.
- **Acceptance:** Conversations list visible OR empty-state visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** blocked - WPMediaVerse bridge dependency; assertion falls back to page-loads check.

### J-46-dm-new-thread
- **Role:** Existing member
- **Steps:** Click "New message"; pick a recipient; send text.
- **Acceptance:** Thread shows the sent bubble.
- **Viewports:** desktop, ipad, mobile
- **Status:** blocked - WPMediaVerse dependency.

### J-47-dm-request-accept
- **Role:** Existing member
- **Steps:** Open a pending message request; click Accept.
- **Acceptance:** Request moves out of pending list.
- **Viewports:** desktop, ipad, mobile
- **Status:** blocked - WPMediaVerse dependency.

### J-48-notifications-list
- **Role:** Existing member
- **Steps:** Visit `/notifications/`.
- **Acceptance:** Notifications list OR empty state visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-49-notifications-mark-read
- **Role:** Existing member
- **Steps:** Click an unread notification.
- **Acceptance:** Item moves to read state.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-50-notifications-mark-all-read
- **Role:** Existing member
- **Steps:** Click "Mark all read".
- **Acceptance:** Unread count drops to zero.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-51-notifications-prefs
- **Role:** Existing member
- **Steps:** Visit `/notifications/?tab=settings`.
- **Acceptance:** Per-type preference toggles visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

---

## Role: Space owner / moderator

### J-52-space-settings
- **Role:** Space owner
- **Starting state:** Logged in as owner of "general" space.
- **Steps:** Visit `/spaces/general/?tab=settings`.
- **Acceptance:** Settings form visible (name, description, privacy).
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-53-space-brand-override (Pro P6.2)
- **Role:** Space owner, Pro active
- **Steps:** Open space settings; set brand hue; save.
- **Acceptance:** Space chrome reflects custom hue.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - Pro only.

### J-54-moderator-review-content
- **Role:** Space moderator
- **Steps:** Visit space moderation tab; review a reported post.
- **Acceptance:** Decision controls visible (approve / remove).
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

---

## Role: Site admin

### J-55-admin-mod-queue
- **Role:** Site admin
- **Steps:** Visit `/wp-admin/admin.php?page=buddynext-moderation`.
- **Acceptance:** Queue table visible.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-56-admin-suspend-user
- **Role:** Site admin
- **Steps:** From the moderation queue, suspend a user.
- **Acceptance:** Confirmation visible; user row marked suspended.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-57-admin-restore-user
- **Role:** Site admin
- **Steps:** Restore the previously suspended user.
- **Acceptance:** Row reverts to active.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-58-admin-settings-features
- **Role:** Site admin
- **Steps:** Visit `/wp-admin/admin.php?page=buddynext-settings`; toggle a feature flag; save.
- **Acceptance:** Confirmation visible; feature toggle persists across reload.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-59-admin-custom-domains (Pro P6.3)
- **Role:** Site admin, Pro active
- **Steps:** Visit custom domains admin; add a domain; save.
- **Acceptance:** Domain appears in list with verification pending state.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - Pro only.

### J-60-admin-email-editor
- **Role:** Site admin
- **Steps:** Visit the email editor admin page.
- **Acceptance:** Template list visible; preview iframe renders.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

---

## Pro-only flows

### J-61-pro-whitelabel
- **Role:** Site admin, Pro active
- **Steps:** Change brand hue in pro admin; reload feed.
- **Acceptance:** `--bn-brand-hue` reflects new value.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - Pro.

### J-62-pro-stripe-checkout
- **Role:** Anon or member, Pro active
- **Steps:** Visit the upgrade / tier page; click Subscribe.
- **Acceptance:** Redirected to a Stripe Checkout URL (`checkout.stripe.com`).
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - Pro.

### J-63-pro-ai-moderation
- **Role:** Site admin, Pro active
- **Steps:** Toggle AI moderation in settings; submit a flagged post.
- **Acceptance:** Post is held for review automatically.
- **Viewports:** desktop, ipad, mobile
- **Status:** fixme - Pro.

---

## Shell / chrome regression

### J-64-theme-chrome-above-below
- **Role:** Any logged-in
- **Steps:** Visit any hub.
- **Acceptance:** Theme header is the DOM-ancestor of `.bn-app`; theme footer comes after `.bn-app` in the DOM; no `.bn-topbar` exists inside `.bn-app`.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-65-mobile-bottom-nav
- **Role:** Any logged-in
- **Steps:** Visit any hub on mobile viewport.
- **Acceptance:** `.bn-mobile-nav` is visible; it contains exactly 5 items.
- **Viewports:** mobile only (also ipad-portrait if viewport <768)
- **Status:** implemented

### J-66-rail-collapse-breakpoints
- **Role:** Any logged-in
- **Steps:** Resize viewport from 1440 down through 1024 / 768 / 390.
- **Acceptance:** At desktop, rail labels visible; at <1024 rail collapses to icons; at <768 rail is hidden entirely and mobile nav appears.
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented

### J-67-no-second-bn-topbar
- **Role:** Any logged-in
- **Steps:** Inspect any hub.
- **Acceptance:** `.bn-app .bn-topbar` selector matches zero elements (theme header is the topbar).
- **Viewports:** desktop, ipad, mobile
- **Status:** implemented
