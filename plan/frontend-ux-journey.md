# BuddyNext Front-End UX Journey Plan

Element-by-element walkthrough of **every front-end screen × every functionality flow**, written so each flow can be walked, judged against what a real person expects, and fixed in place.

This is a living plan. Element lists were mapped from the templates + Interactivity stores; every **⚠ watch** item is a *candidate* issue to confirm in the browser before fixing (do not treat as a confirmed bug). **✅ confirmed** items were already verified this session.

## Locked scope (this pass)
- **Breadth:** P0 + P1 screens — Profile, Members, Feed, Post, Messages, Notifications, Spaces, Profile-edit, Explore/Search/Hashtag/Bookmarks/Leaderboard, Global shell. (Auth, Onboarding, Moderation deferred to a later pass.)
- **Issue depth:** everything, including micro-polish — chase every ⚠ watch-item, not just confirmed breaks.
- **Pro flows:** in scope — walk Pro-gated surfaces (voice/AI composer, group-chat management, advanced search, push prefs). Requires BuddyNext Pro active locally.
- **Verify matrix:** every in-scope flow at **desktop + 390px**, **light + dark**.
- **Discipline:** one flow at a time, verify per element, fix in place; do not let velocity outrun verification ([[feedback_verify_before_advancing]]).

---

## How to walk each flow (method)

For every flow below:

1. **Entry** — load the entry URL logged-in as the relevant actor (own vs other vs admin vs guest). Use `?autologin=<login>` (log out first; the mu-plugin bails if already logged in).
2. **Element-by-element** — touch each interactive element in reading order. For each: does it do what a first-time user expects? Match the mental model of Facebook / LinkedIn / Instagram / WhatsApp.
3. **States** — empty, loading, error, success; hover / focus / active / visited on every `<a>`/button (themes override these).
4. **Responsive** — desktop **and** 390px. **Light and dark.**
5. **Verdict per element** — pass / fix. Fix in place, then re-walk that element. Do **not** batch-then-verify; verify per element ([[feedback_verify_before_advancing]]).
6. One completed flow beats ten half-walked ones ([[feedback_flow_model_audit]]). Polish is part of the feature, not a follow-up ([[feedback_ux_over_functionality]]).

Global rules in force: no emoji in UI; OKLCH theme tokens only (no AI-gradient palettes); Lucide `buddynext_icon()` for icons; consistency is itself an expectation ([[feedback_design_for_user_expectation]]).

---

## Screen inventory & priority order

| # | Hub / Screen | Entry route | Primary actor(s) | Priority |
|---|---|---|---|---|
| 1 | **Single profile** (hero + 8 tabs) | `/members/{slug}/` | own / other / guest | P0 (in progress) |
| 2 | **Members directory** | `/members/` | any | P0 |
| 3 | **Activity feed** (home) | `/activity/` | member | P0 |
| 4 | **Post card** (shared) + single post | `/p/{id}/` | any | P0 |
| 5 | **Messages** (rail + thread + requests + group) | `/messages/` | member | P0 |
| 6 | **Notifications** (list + prefs) | `/notifications/` | member | P1 |
| 7 | **Spaces** (directory + single + members + settings + moderation) | `/spaces/` | any / admin | P1 |
| 8 | **Profile edit / settings** | `/members/{slug}/edit/` | own | P1 |
| 9 | **Explore / Search / Hashtag / Bookmarks / Leaderboard** | `/activity/explore/` etc. | any | P1 |
| 10 | **Auth** (login / signup / verify) | `/login/` `/signup/` | guest | P2 |
| 11 | **Onboarding** (4-step) | `/onboarding/` | new member | P2 |
| 12 | **Moderation queue** | `/moderation/` | moderator | P2 |
| 13 | **Global shell** (rail / mobile bottom-nav / search overlay / notif dropdown / theme toggle / right sidebar) | all hubs | any | P1 (cross-cutting) |

Walk order: finish **#1 Profile**, then #2 → #5 (the daily-driver surfaces), then #6–#9, then #10–#13.

---

## 1. Single profile — `/members/{slug}/`

Actors: **own** profile, **other** profile, **guest** (logged out). Walk all three.

### Flow 1.1 — Hero identity (read)
Elements in order: cover image → avatar (presence dot) → name → degree badge (`1st/2nd/3rd+` tooltip) → member-type badge → verified check → handle `@user (pronouns) · headline` → bio → meta row (location / website / joined).
- ✅ Renders correctly on own + other (verified this session).
- ⚠ Website link uses `rel="nofollow noopener noreferrer ugc"` — confirm it opens new tab and is keyboard-focusable.
- ⚠ Handle row concatenates `@user · (pronouns) · headline` — confirm it doesn't look cramped when all three present at 390px.

### Flow 1.2 — Hero actions, OTHER profile
Buttons: **Follow** → Following · **Connect** → Pending → (Accept/Decline when received) → Connected · **Message** (link, only when connected) · **Share** (Copy link / Share to feed) · **More** (Mute / Restrict / Block / Report).
- ✅ Follow, Connect, Share, More all work (verified this session).
- ⚠ **Message** only appears when `connection_status === accepted`. Confirm that's the intended gate (a user expects to be able to DM per privacy settings, not only after connecting). Cross-check with `privacy_dm`.
- ⚠ Follow says "Following" for both accepted and pending-on-private — confirm there's a distinct pending state for private accounts.

### Flow 1.3 — Hero actions, OWN profile
Buttons/links: **Edit cover** (→ edit) · **avatar edit badge** (→ edit#avatar) · **Edit profile** (→ edit) · **Share** · **completeness pill "N% complete"** (→ edit).
- ✅ All wired to the edit form (verified this session).
- ✅ **CONFIRMED ISSUE — duplicate completeness indicator.** The hero `N% complete` pill duplicates the sidebar "Profile Strength" ring + 6-task checklist. One metric, two places on one screen. **Fix:** keep ONE canonical module. Proposed: drop the hero pill on desktop (sidebar widget is canonical + actionable); keep a single completeness affordance on mobile where the sidebar drops below the fold.

### Flow 1.4 — Stat strip
Pills: Posts · Followers · Following · Connections · Points · Level · Discussions.
- ✅ **CONFIRMED ISSUE — affordance inconsistency.** Posts/Followers/Following/Connections are clickable (switch tab); Points/Level/Discussions are static `<li>`. They look identical, so the user can't tell what's clickable. **Fix:** give clickable stats a clear interactive affordance (pointer/hover/focus) and render static stats visually distinct (no hover, no pointer).
- ✅ **CONFIRMED ISSUE — Discussions duplicated + dead.** "Discussions" appears as a static stat *and* as a tab. **Fix:** either make the Discussions stat switch to the Discussions tab (consistent with the other clickable stats) or remove the static stat.
- ⚠ Followers/Following/Connections stats are *both* a button (setTab) AND a link to `/followers/` etc. — confirm one canonical affordance; dual is confusing.
- ⚠ Delta chip "+N" — confirmed suppressed when all-new; confirm it never shows a misleading value on seeded data.

### Flow 1.5 — Tab navigation (8 tabs)
Posts (40) · Replies (30) · Media (10) · Likes (23) · Followers (1) · Following (3) · Connections (2) · Discussions (no count).
- ✅ Content tabs (Posts/Replies/Media/Likes/Discussions) render with correct empty states (verified this session).
- ✅ People tabs (Followers/Following/Connections) render as a proper member grid with working actions — **fixed this session** (`bn-members` assets now always enqueued on the profile view; commit `968e84e`).
- ⚠ **Count-badge inconsistency** — every tab shows a count except Discussions. **Fix:** show `(0)` consistently or omit all zero counts consistently.
- ⚠ Followers/Following/Connections tabs are button + href (dual affordance) — same concern as 1.4.
- ⚠ People panels cap at 60 rows with no in-tab filter/pagination — confirm there's a "see all" path (Members directory) and that the cap is communicated, not silent.

### Flow 1.6 — Guest view
- ⚠ Walk the profile logged out: hero actions should become "View profile / Sign in"; confirm no own-only edit affordances leak; confirm private-account gating.

---

## 2. Members directory — `/members/`

### Flow 2.1 — Browse & filter
Elements: relation tabs (All / Following / Connections) · search input (250ms debounce) · member-type select · "Online only" checkbox · sort select (Newest / Alphabetical / Most active / Online) · view-mode grid|list toggle.
- ⚠ **List view toggle exists but may have no list layout** — the card template only ships grid markup. Confirm `.is-list` actually changes layout; if not, either implement list or remove the toggle (dead control).
- ⚠ Search has a text "Searching…" label but no spinner and **no clear (×) button** — add a clear affordance.
- ⚠ Member-type `<select>` lacks the count badges the sidebar shows — inconsistent.
- ⚠ Active vs inactive relation-tab styling — confirm a visible active state + focus ring.

### Flow 2.2 — Card actions
Per card: kebab (Message / Mute / Block / Report) · Follow (Follow/Following/Pending/Blocked) · Connect (Connect/Requested/Connected) · Accept/Decline (when request received).
- ⚠ Follow has 4 labels but only 2 button variants — confirm "Pending"/"Blocked" are visually legible.
- ⚠ Kebab menu focus management / Escape-to-close / focus trap — confirm keyboard accessibility.
- ⚠ Bio truncates silently (no "…") — confirm acceptable.

### Flow 2.3 — States
Loading skeleton · error + Retry · empty ("No members match" + Reset filters) · pagination.
- ⚠ Confirm skeleton/error/empty actually appear (throttle / force-error to see them).

---

## 3. Activity feed — `/activity/`

### Flow 3.1 — Browse feed
Filter tabs (For you / Following / Spaces / Network) with count badges · announcement banner (dismiss) · post list · infinite-scroll loader · end-of-feed message · per-filter empty state.
- ⚠ Home uses infinite scroll but Bookmarks uses paginated "Load more" — inconsistent; pick one model.
- ⚠ Filter-tab count badges may be stale until the filter is switched — confirm they refresh.

### Flow 3.2 — Create a post (composer)
Avatar + textarea · privacy chip row (Public / Followers / Connections / Space members / Private) · tools (image · poll · event · voice[Pro] · AI[Pro]) · Share button · error alert.
- ⚠ **No visible discard/cancel** once text is entered — add a cancel affordance.
- ⚠ Share button "submitting" state — confirm disabled + label change.
- ⚠ Event modal: native date/time, **no timezone hint** — confirm copy clarifies tz.
- ⚠ Voice/AI tools are Pro — confirm they hide cleanly (not dead buttons) when Pro absent.

### Flow 3.3 — Post-card actions (shared component → also #4)
Byline (avatar/name/handle links, timestamp, privacy badge) · "(edited)" · content-warning unveil · body · reaction summary chip · React · Comment · Share · Save · overflow (Edit / Pin / Mute / Report / Delete).
- ⚠ Reaction picker (6 types) — confirm aria state announces; confirm toggling own reaction works.
- ⚠ Save icon == Bookmarks-screen icon — confirm consistent meaning.
- ⚠ Overflow menu items vary by author/permission — confirm no dead/forbidden items show.

### Flow 3.4 — Comment on a post
Comment form · comment list (threaded, max depth 5) · Reply / Edit / Delete / Pin / Report per comment · deleted-comment placeholder.
- ⚠ Max-depth-5 not communicated — confirm reply affordance disappears gracefully at depth.

### Flow 3.5 — Share modal
Repost · Quote · Copy link (toast) · error alert · close.
- ⚠ Error is plain text, no icon — confirm visible on small screens.

---

## 4. Single post — `/p/{id}/`

Breadcrumb (Activity › @author › Post) · expanded post card (auto-opens comments) · full comment thread + reply form · 404/private state ("Back to feed").
- ⚠ Confirm private/unavailable post sends 404 + shows the recovery CTA.
- ⚠ Confirm breadcrumb links resolve.

---

## 5. Messages — `/messages/`

### Flow 5.1 — Rail & navigation
Title + unread badge · search conversations · tabs (All / Unread / Requests) · pinned section · recent section · "New message / Create group" CTA.
- ⚠ Tab count badges (unread / requests) may not be wired — confirm they show.
- ⚠ Search has no clear button / no "no results" hint — add.

### Flow 5.2 — Open conversation & send
Header (back · identity · presence · search-in-thread · delete · more) · message log · composer (input · attach · send · emoji) · request banner (Accept/Decline) when pending.
- ✅ Thread layout, header (dead Search/More removed), bubbles, mobile single-column — cleaned this session.
- ⚠ Composer: confirm "sending" state + failure retry; Send disabled when empty.
- ⚠ Delete conversation — confirm there IS a confirm step (destructive).

### Flow 5.3 — Group chat
Create group (compose modal) · group panel (rename / add / remove / role / leave, Pro) · group-aware bubbles + roster.
- ✅ Create + send + management panel built & verified previously.
- ⚠ Re-walk group panel actions end-to-end for loading/empty states.

### Flow 5.4 — Message requests
Requests tab filter · request rail items · accept/decline from banner.
- ⚠ Accepting from rail may not clear the count until refresh — confirm reactive update.

---

## 6. Notifications — `/notifications/`

### Flow 6.1 — List & filter
Header + "Mark all as read" · filter tabs (All / Unread / Mentions / Comments / Reactions / Spaces) with counts · time-grouped rows (Today / Yesterday / Older) · row (pulse dot · avatar · message · type pill · time · Open / Mark read / Accept-Decline) · empty · error + retry · pagination.
- ⚠ "Mark all as read" — confirm no accidental-trigger risk; reactive badge update.
- ⚠ Row inline actions vary by type — confirm affordance clarity, no dead buttons.

### Flow 6.2 — Preferences — `/notifications/preferences/`
Channel master toggles (in-app / email / push / sound) · activity-type accordion (on-site toggle + email-frequency select per type) · per-space chip-select (All / Mentions / None) · quiet-hours (placeholder) · save state (dirty / saving / saved) · reset-to-defaults (confirm).
- ⚠ Quiet-hours shows "coming soon" — confirm it reads as intentional, not broken.
- ⚠ Per-pref change — confirm save feedback (toast / saved-at).

---

## 7. Spaces — `/spaces/`

### Flow 7.1 — Directory
Title + count · "Create a space" · search · type chips (All / Open / Private / Secret) · sort popover (Popular / Active / Newest / A→Z) · loading skeleton · error + retry · space cards (cover · emblem · name · privacy badge · category · desc · member count · contextual action Join/Requested/Joined/Request/Manage) · empty (cold-start vs filtered) · pagination · sidebar (Categories / Your spaces / Popular).
- ⚠ Card member count doesn't update reactively on join/leave (+ no aria-live) — confirm.
- ⚠ Filtered-empty state doesn't name the active filters — improve.

### Flow 7.2 — Create a space (modal)
Name (req) · slug (auto-derived) · type (req) · category · description (160 max) · per-field errors · global error · Cancel / Create.
- ⚠ No description char counter; submit has no loading state; no success toast (redirects silently) — confirm + improve.

### Flow 7.3 — Single space (feed)
Hero (cover · emblem · name · privacy · stats · Join/Requested/Settings · notif popover · paywall CTA) · tabs (Feed / Members / Media / About / Moderation) with counts · composer (stub → expand) · access gate · pinned post · post cards.
- ⚠ Composer stub is readonly but looks like a normal input — confirm focus affordance.
- ⚠ Relative timestamps go stale; no "load more posts" — confirm acceptable.

### Flow 7.4 — Space members
Header · back · search + role filter · member rows (avatar · name · role badge · joined · admin actions Make-mod / Remove / Ban) · pagination.
- ⚠ Destructive member actions have **no confirm dialog** and no loading state — add.

### Flow 7.5 — Space settings (admin)
Tabs (General / Privacy / Permissions / Members / Moderation / Notifications / Integrations / Branding / Danger) · per-tab forms · sticky save bar · duplicate inline-vs-sticky save buttons.
- ⚠ Two save buttons (inline + sticky) — clarify which is canonical.
- ⚠ Image uploads show no preview before save — add.

### Flow 7.6 — Space moderation
Stats (open reports / pending / warned / total) · tabs (Reports / Pending members / Activity log) · report cards (priority · reporters · offender · strikes · reason · actions Dismiss/Warn/Remove/Kick/Ban) · pending cards (Approve/Decline) · log.
- ⚠ No confirm before warn/remove/kick/ban; no loading state; card doesn't refresh after action — add.

---

## 8. Profile edit / settings — `/members/{slug}/edit/`

Hero edit (change cover · avatar crop modal · display name · headline) · dynamic field groups (flat + repeater, each with privacy-lock select) · privacy section (6 audience selects + 4 toggles) · member-type self-select · connected accounts (link/unlink) · blocked/restricted/muted lists (unblock/…) · appearance (theme + text-size segmented) · notification prefs (4 email toggles + link out) · account (profile-URL slug check · email change · password change + strength · 2FA 4-stage · sign-out-everywhere) · danger zone (delete account, type-DELETE gate) · sticky save bar (dirty/saving/saved).
- ⚠ Real-time validation only on display name (others validate on save) — confirm acceptable.
- ⚠ Avatar crop has no loading state during export/upload — confirm.
- ⚠ Appearance segmented controls show no current-selection state — confirm CSS marks active.
- ⚠ 2FA backup codes: no copy/download button (manual transcription) — add.
- ⚠ Repeater add/remove: confirm dynamically-added entries actually submit.
- ⚠ "Sign out everywhere" + "Delete account" — confirm both have clear, non-accidental gates.

---

## 9. Explore / Search / Hashtag / Bookmarks / Leaderboard

- **Explore** `/activity/explore/`: guest join banner · search · filter chips (All/People/Posts/Spaces/Media + trending tags) · masonry grid · infinite loader · empty. ⚠ confirm chip active states + empty CTA.
- **Search** `/activity/search/?q=`: sticky search · type tabs with counts · date filter · sort · (Pro) advanced filters · per-type result lists · save-search (Pro) · no-results. ⚠ confirm Pro filters degrade silently-but-discoverably.
- **Hashtag** `/activity/hashtag/{slug}/`: header (name · post count · Follow toggle · created) · sort tabs (Latest/Top/Following) · related-tags + top-contributors sidebars · feed. ⚠ confirm Follow toggle announces state (aria-pressed alone insufficient).
- **Bookmarks** `/me/bookmarks/`: header · saved list · Load more · empty · remove-bookmark. ⚠ pagination model inconsistent with home feed.
- **Leaderboard** `/activity/leaderboard/`: hero (rank/points/level + progress) · period tabs (Week/Month/All) · rank rows (rank pill · avatar · points · badges · Follow · "You" pill) · sidebar (badges / streak / next milestone). ⚠ confirm period filter refetches; confirm optional rank-window control isn't a dead element.

---

## 10. Auth — `/login/` `/signup/` `/verify-email/`

- **Login**: email/username · password · remember · forgot-password (WP default) · Sign in · social row · "Create account" link · **2FA panel** (code · Verify · Email-me-a-code). ⚠ no show-password toggle; forgot-password uses off-brand WP flow; 2FA email-code has no cooldown.
- **Signup**: email · username (live availability) · password (strength meter) · terms (req) · challenge (spam) · honeypot (hidden) · Create · social row · "Sign in" link. ⚠ strength meter resets on keystroke; "Checking…" not distinct from result; terms links open new tab mid-form.
- **Verify**: state machine (pending / verified / expired / email-changed-ok / email-changed-fail) · resend (logged-in only) · support mailto. ⚠ **logged-out user on an expired link is a dead end** (can't resend); no resend cooldown.

---

## 11. Onboarding — `/onboarding/` (4 steps)

Stepper · live preview canvas · **Step 1 Profile** (avatar upload · display name · username live-check · bio) · **Step 2 Spaces** (recommended grid · Join/Joined) · **Step 3 People** (suggested · Follow/Following) · **Step 4 Notifications** (channel toggles) · Back / Skip / Continue / Finish.
- ⚠ Join/Follow buttons have no loading state (flicker on slow API).
- ⚠ Username "Taken" doesn't block Continue.
- ⚠ Steps 1–3 saves are fire-and-forget — only the final POST surfaces errors; earlier failures are silent.
- ⚠ Channel-toggle checkboxes missing `aria-labelledby`.

---

## 12. Moderation queue — `/moderation/`

Restricted-access state · header · stats grid (urgent / pending / resolved / total / suspended, aria-live) · filter tabs (All / Urgent / Posts / Comments / DMs / Profiles) + sort · report rows (avatar · offender · verb · reason badge · count · time · strikes · excerpt · reporter stack · actions View/Dismiss/Remove/Warn/Strike/Suspend) · empty · confirm dialog (shared `bnConfirm`).
- ⚠ Destructive actions rely on shared confirm — verify it actually fires; no loading state on buttons.
- ⚠ Severity is color-only (red=urgent) — add text fallback.
- ⚠ "View in context" navigates away — confirm it isn't disorienting.

---

## 13. Global shell (cross-cutting)

- **Left rail**: brand · collapse toggle · nav (Activity / Explore / Members / Spaces / Notifications[badge] / Messages[badge]) · You group (Profile / Bookmarks / Settings) · active = aria-current. ⚠ no arrow-key nav; confirm collapsed state persists + tooltips.
- **Mobile bottom-nav** (≤640px): Feed · Spaces · **Create (+)** · Alerts[badge] · Profile. ⚠ "+" is icon-only/ambiguous — confirm it reads as "create".
- **Search overlay** (`/` shortcut): input · live results (Posts/People/Spaces) · recent. ⚠ no "typing…/no results" feedback; no arrow-key result nav.
- **Notif dropdown** (bell): last 5 · mark-all-read · "See all". ⚠ static after first load; clicking item doesn't close.
- **Theme toggle**: light/dark/auto persisted. ✅ present in header. ✅ **Dark mode works** via the real BuddyX color-mode toggle (`.bx-color-mode-toggle__btn`, cycles System→Light→Dark; sets `data-theme`/`data-bn-theme=dark` → `--bn-canvas:#23262f`, `--bn-ink:#fff`). Verified on the profile in dark. ⚠ confirm density toggle isn't a dead control.
  - **Dark-verify method (important):** flip dark by clicking the real toggle, NOT by `setAttribute('data-bn-theme','dark')` — the synthetic attribute set did not flip the tokens (stale/overwritten read), which briefly looked like a site-wide dark break. It was a test-method false alarm.
- **Right sidebar** widgets (greeting/streak · events · trending · people-to-follow · spaces · per-hub notif sidecards). ⚠ confirm each has a real empty state and no dead links.

---

## Confirmed fixes already shipped this session
- **Profile (#1) — COMPLETE.** People-tabs `bn-members` assets always enqueued (`968e84e`); Followers/Following/Connections → in-page tabs (`4e65f55`); completeness de-dup + stat affordances + Discussions stat/tab + tab badges + JetonomyBridge doc fix (`d5867c8`); guest Follow CTA (`e66dcae`). Walked own/other/guest, desktop + 390px, light + dark. Dark mode confirmed working via the real toggle.
- **Members directory (#2) — COMPLETE.** List view rebuilt as a real compact people-list row (`995ea1c`, was just stretched grid cards); search clear (×) button (`25491d6`). All filters (search/type/online/sort/relation) verified functional, 0 console errors. List-view-dead and dark-broken suspicions both disproved by verifying. Desktop + 390px + light/dark.
- **Activity feed (#3) — WALKED, no fix needed.** Composer is well-built: all 5 tools carry aria-label + title (Image / Poll / Pin date+location / Schedule / Announcement), a Discard-draft button exists, char counter + labeled privacy chip. Renders clean desktop + 390px; React picker opens; 0 console errors. Plan ⚠ items were not real breaks (discard exists; infinite-scroll/tz are minor-by-design). Mature surface, meets expectation.
- **Single post (#4) — WALKED, no fix needed.** Breadcrumb links resolve (Activity → feed, @handle → profile); full post card + reaction summary + toolbar; expanded nested comment thread with per-comment React/Reply/Edit/Delete/Pin; private/deleted post shows a clean recovery state (alert icon + "This post is private or unavailable" + explanation + Back-to-feed CTA). 0 console errors.
- **Messages (#5) — COMPLETE.** Major fix: two-pane now anchored to the viewport (`3add03e`) — composer was ~360px below the fold (had to scroll the page to type), the rail "New message" too; `.bn-dm-card` height anchor had been removed from the markup. `callbacks.fitViewport` measures the real chrome offset + content-column bottom padding (= mobile bottom-nav clearance) into `--bn-msg-chrome`. Composer + New-message pinned in view desktop + 390px. Compose modal verified (DM + group modes, recipient search). Tab-aware rail empty copy (`12207b0`). 0 console errors.
- **Notifications (#6) — WALKED, no fix needed.** List: filter tabs (All/Unread/Mentions/Comments/Reactions/Spaces) navigate with contextual empty states ("No mentions yet…"), time-grouped rows with Open/Mark-read/dismiss, Mark-all-read, rich sidebar (Quick filters / By type / Recent actors). Prefs: channel master switches (incl. Push, Pro) + per-type in-app/email-frequency controls, proper manual "Save changes" with dirty-state indicator + beforeunload unsaved-changes guard. 0 console errors.
- Messages: thread layout, header declutter, mobile single-column, hover-reveal meta.

## Next confirmed work queue (P0, profile)
1. Hero completeness pill duplicates sidebar Profile Strength → de-duplicate (Flow 1.3).
2. Stat-strip affordance: distinguish clickable vs static; make Discussions stat switch to its tab; resolve dual button+link on Followers/Following/Connections (Flows 1.4/1.5).
3. Tab count-badge consistency (Flow 1.5).

Then proceed down the priority order, one flow at a time, verify-per-element.
