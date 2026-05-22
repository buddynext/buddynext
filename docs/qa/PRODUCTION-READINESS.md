# Production-Readiness Matrix

> Live walk of `http://buddynext-dev.local` logged in as `varundubey` on 2026-05-22. Screenshots in `audit-2026-05-22/`. Each row scores a surface against the v2 wireframe (`docs/v2 Plans/v2/`) on three axes: every affordance present, every action wired, every state covered (empty / loading / success / error). The standard is presentation-grade UX, not "feature exists".
>
> Legend:
> - `prod` — matches wireframe, every action wired, all states present
> - `gaps` — surface renders but UX has presentation-quality gaps
> - `broken` — affordance missing, action dead, or state crashes

## Score card

| # | Surface | Status | Headline gap |
|---|---|---|---|
| 1 | Activity home (`/activity/`) | prod | Composer ships Image / Poll / Event / Voice / AI helper + chip privacy popover (Public / Followers / Only me). Filter tabs (For you / Following / Spaces / Network) wired to `FeedService::home_feed( …, $filter )` + `/feed/counts`. Post card gains Share button → Repost / Quote / Copy-link modal. Sidebar widgets gain per-row Follow chip, This-week caption, unread-space dot, empty states |
| 2 | Activity explore (`/activity/explore/`) | prod | Composer + filter strip + Share modal + sidebar polish shared with Home via the same partials and stores; skeleton + inline error states ship for both surfaces |
| 3 | Hashtag feed (`/activity/hashtag/wordpress/`) | prod-ish | Strong layout (hero stats, sort tabs, related tags sidebar). Follow button wired. Minor: "Following" appears as a sort option which reads ambiguous next to Latest/Top |
| 4 | Member directory (`/members/`) | prod | Apply button dropped — filter bar is reactive (250 ms debounced search, instant sort + relation tab + member-type pill switches). Member-type pill row above the grid wired to `MemberTypeService::list_public()`. Skeleton / empty / error / retry states all ship. Per-card Follow + Connect run through `assets/js/members/store.js` with optimistic UI, REST `/members/{id}/follow|connect`, rollback + `bnToast` on success/failure. Per-card kebab surfaces Mute / Block / Report wired to the shared modal partials. Page title renders as `Members · {site}` via `document_title_parts`. New REST `GET /buddynext/v1/members` powers reactive re-renders. |
| 5 | Member profile view (`/members/varundubey/`) | prod | Title now "Display Name · Profile" via PageRouter override; hero renders Headline, Location, Website (rel=nofollow ugc), Pronouns, Bio plus brand-coloured Social Link chips; inline Work Experience + Education timeline cards and Community Interests tag cloud render below the hero when populated; non-owner view ships Follow / Connect / Message + More-options menu wired to Mute / Block / Report with toast feedback. |
| 6 | Profile edit (`/members/varundubey/edit/`) | prod | All sections wrapped in a single `<form data-wp-on--submit=actions.saveProfile>`; sticky save bar carries master Save with dirty / saving / saved status pills; display_name is required and shows inline error on blur; website + social URLs validated client + server side with field-level 422 errors and toast on save; beforeunload guard fires when dirty; page title now "Edit Profile · Display Name". |
| 7 | Spaces directory (`/spaces/`) | gaps | Filter form has Apply button (form-submit, not reactive); type filter has only Public/Private chips (no Secret); sort dropdown not styled to v2; no Create-space modal (links to `?bn_action=create` slug instead) |
| 8 | Space home (per space) | not-walked-yet | Pending — need to visit `/spaces/open-discussion/` |
| 9 | Space settings (per space, owner) | not-walked-yet | Pending — `/spaces/open-discussion/settings/` |
| 10 | Notifications (`/notifications/`) | prod | Every type renders human copy via `NotificationMessageService` (30+ types covered, spec at `docs/specs/NOTIFICATION-MESSAGES.md`). REST list response now ships `message`/`url`/`icon`/`tone`/`label`. Empty + error states per filter. Unread badge reactive with 99+ cap and optimistic mark-as-read |
| 11 | Notification prefs | not-walked-yet | Pending — usually `/settings/notifications/` |
| 12 | Messages (`/messages/`) | broken | "Direct messaging requires WPMediaVerse" empty state — but WPMediaVerse IS active per Plugin.php. Bridge detection broken |
| 13 | Onboarding wizard (`/onboarding/`) | gaps | 4 steps (Profile / Interests / Spaces / People) render with Continue/Skip/Back, BUT no visual progress bar (`progressBar: false` from accessibility query) — numbered step list is the only progress signal; should be an actual `.bn-progress` bar |
| 14 | Auth login | not-walked-yet | Need logged-out walk |
| 15 | Auth signup | not-walked-yet | Need logged-out walk |
| 16 | Auth verify-email | not-walked-yet | Need logged-out walk |
| 17 | Search results | not-walked-yet | Pending |
| 18 | Mobile shell (`<768px`) | prod | Bottom-tab nav renders (Feed/Spaces/+/Alerts/Profile), rail hides, sidebar hides, composer adapts. Clean. |
| 19 | Theme chrome | prod | Astra header + footer wrap `.bn-app`, no second BN topbar. Edge-to-edge `.bn-app` via 100vw burst-out works |
| 20 | Zero JS errors | prod | Console clean across every page walked |

## Detailed findings (by feature owner)

### F1 Composer (Feed task #32)

**Wireframe expects 5 tools:** Image · Poll · Event · Voice · AI helper
**Reality:** Image · Poll · Link (3 tools)

Mapping:
- ✅ Image (`actions.pickMedia`)
- ✅ Poll (`actions.openPoll`)
- ❌ Event — missing entirely
- ❌ Voice — missing entirely
- ❌ AI helper — missing (Pro P2.4 implements this for the comment form, not the composer)
- ➕ Link (extra; useful to keep but the wireframe folds it into the URL-detection inside the textarea)

**Privacy selector:** native `<select>` with Public/Followers/Only me. Wireframe shows a premium chip: `Posting to **Everyone** ▾` with chip styling. Native select is jarring next to the v2 tokens. Fix: replace with `data-wp-on--click` chip + popover.

**Composer single-state vs two-state:** wireframe is single-state inline. Reality matches.

**Files:**
- `templates/partials/composer.php` — add Event + Voice + AI tools.
- `assets/js/feed/store.js` — add `actions.openEvent`, `actions.openVoice`, `actions.openAiHelper`.
- `assets/css/bn-feed.css` — chip-style `.bn-composer__privacy` to replace native select.

### F2 Feed filter tabs (Feed task #32)

**Wireframe:** "For you" · "Following" · "Spaces" · "Network" (4 filter tabs at top of feed-stack, with active underline + count chip)
**Reality:** None. Only the hub-level Home / Explore tabs exist (those are rail-level nav, not feed-filter).

This is a fundamental UX gap. The home feed should let the viewer slice their feed; otherwise it's just one undifferentiated stream.

**Files:**
- `templates/feed/home.php` — render `.bn-feed-tabs` row above the post stack, hooked to `data-wp-on--click=actions.setFilter`.
- `includes/Feed/FeedService.php` — extend `home_feed()` to accept `filter` arg: `for-you | following | spaces | network`. SQL clause already supports it via existing follow/space/connection joins; just route on the param.
- `includes/Feed/FeedController.php` — accept `filter` query param.
- `assets/js/feed/store.js` — `setFilter(filter)` action, refetch first page.

### F3 Post-card Share button (Feed task #32)

**Wireframe action row:** Like · Replies · Share · Save (4 actions, with Save floated right)
**Reality:** React · Comment · Save (3 actions). No Share button.

`ShareService` exists in PHP (it powers `/me/shares` REST) but the UI affordance was never added to the action row. The "Share · 1" string I saw on some posts is actually the share-count display on already-shared posts, not a button to share.

**Files:**
- `templates/partials/post-card.php` — add `<button class="bn-post-card__action-btn" data-wp-on--click=actions.openShare>Share</button>` between Comment and Save.
- `assets/js/feed/store.js` — `openShare()` action opens a share modal (repost to feed / quote / copy link).
- `templates/partials/share-modal.php` (new) — three CTAs: Repost / Quote / Copy link.

### F4 Right sidebar widgets (Feed task #32)

Three widgets render: Trending Topics · People to Follow · Your Spaces. All link out. Trending shows hashtag + post count. People to Follow shows avatars + names. Your Spaces shows joined spaces.

**Gaps:**
- Trending Topics: no time-window selector (24h / 7d). Wireframe expects "Trending · This week" badge.
- People to Follow: no Follow CTA on each row, only avatar link to profile. Wireframe shows tiny `+ Follow` button per row.
- Your Spaces: only "OP" initials avatar visible for Open Discussion; no unread indicator dot.

**Files:**
- `templates/shell/right-sidebar.php` (or wherever sidebar widget partials live) — add per-row Follow chip in suggested-follows.
- `includes/Sidebar/WidgetService.php` — extend `suggested_follows()` to return follow_status per row so the chip can show "Follow" vs "Following".

### F5 Notifications message map (Notifications task #36)

**Critical.** Every notification I saw except "Space join request" renders as the fallback string "memberX sent you a notification." This is a wiring gap, not a rendering bug.

The type-to-message map in `templates/notifications/index.php` is missing entries for the notification types actually being fired. The fallback path was introduced in 2026-03-21 (per CLAUDE.md: "added type-based message map") but isn't being matched.

**Fix:**
1. Run a quick `wp db query` to enumerate the distinct `type` values in `bn_notifications` for this user.
2. Update `templates/notifications/index.php` to cover every type with a real message template using `actor_id`, `object_id`, etc.
3. Spec the full message catalogue in `docs/specs/NOTIFICATION-MESSAGES.md` so future types ship with copy from day 1.

Suggested message catalogue (mapped to hooks in `CLAUDE.md`):
- `bn.new_follower` → "{actor} started following you"
- `bn.connection_requested` → "{actor} wants to connect"
- `bn.connection_accepted` → "{actor} accepted your connection request"
- `bn.reaction` → "{actor} reacted {emoji} to your post"
- `bn.comment` → "{actor} commented on your post: '{excerpt}'"
- `bn.mention` → "{actor} mentioned you"
- `bn.space_invite` → "{actor} invited you to {space}"
- `bn.space_join_request` → "{actor} requested to join {space}" ✅ (only one currently working)
- `bn.space_join_approved` → "Your request to join {space} was approved"
- `bn.space_new_post` → "{actor} posted in {space}: '{excerpt}'"
- `bn.bookmark_milestone` → "Your post has been bookmarked {n} times"
- `bn.user_warned` → "A moderator issued a warning: {reason}"
- `bn.suspension` → "Your account has been suspended"
- `bn.user_unsuspended` → "Your account has been restored"
- `bn.strike_warning` → "A community guideline was breached"
- `bn.appeal_resolved` → "Your appeal was {decision}"

Files:
- `templates/notifications/index.php` — replace fallback with exhaustive switch.
- `docs/specs/NOTIFICATION-MESSAGES.md` (new).
- Make sure NotificationController GET `/me/notifications` returns the same shape used by the in-app dropdown so both paths render identically.

### F6 Profile edit save flow (Profile task #35)

**Broken.** No `<form>` element wraps the 25 inputs in profile edit. The only visible submit-style button is "Update URL" (for the @handle slug). The rest of the fields appear to rely on per-field auto-save via a JS store, but there's no master CTA so the user has no confidence anything saved.

**Fix:** add a single `<form data-wp-on--submit=actions.saveProfile>` wrapping every section, with a sticky "Save changes" CTA at the bottom. Per-field auto-save can remain but a master Save is the presentation expectation.

**Files:**
- `templates/profile/edit.php` — wrap with `<form>`; add sticky save bar.
- `assets/js/profile/store.js` — `saveProfile()` action POST `/me/profile`.
- `includes/Profile/ProfileController.php` — verify the bulk update endpoint exists and returns shape `{ saved: true, errors: [] }`.

### F7 Profile view: fields not rendered (Profile task #35)

Edit profile has 25 fields (Location, Website, Pronouns, Bio, Social Links, Work Experience repeater, Education repeater, Community Interests). On the view page, none of those render in the hero or below. Only display name, handle, join date, and 4 stats appear.

**Fix:** `templates/profile/view.php` must render the populated fields below the hero — Location row, Website link, Social Link chips, Work/Education timeline, Community Interests tag cloud.

### F8 Profile actions menu (Social Graph task #33) — CLOSED

On a profile that's NOT your own, the wireframe expects: Follow / Message / Connect / More-options menu (Mute / Block / Report).

Resolved in the 2026-05-21 profile production sweep: profile view ships Follow / Message / Connect + More-options menu wired to Mute / Block / Report with toast feedback and rollback (see PR #35).

The same kebab affordance now also renders on every member card in the directory (`templates/directory/members.php`), so Mute / Block / Report are reachable from the listing as well as from the profile detail page.

### F9 Member directory member-type pills (Hashtags/Search task #37) — CLOSED

Resolved in this sweep. `templates/directory/members.php` now renders a `.bn-md-pill-row` block above the grid, fed by every public type returned by `buddynext_service( 'member_types' )->get_all()`. Each pill is a reactive button — clicking re-fetches the directory through `GET /buddynext/v1/members?member_type={slug}` and updates the browser URL via `history.replaceState`. The `All members` pill resets the filter. Active pill is reflected in CSS via `.is-active` + `aria-pressed="true"`.

Files touched:
- `templates/directory/members.php` — pill row + reactive filter.
- `assets/js/members/store.js` — `selectMemberType()` action + URL sync.
- `assets/css/bn-members.css` — `.bn-md-pill-row` + `.bn-md-pill` styles.
- `includes/Profile/MemberDirectoryController.php` — REST `GET /members?member_type=` filter pass.

### F10 Spaces directory filter form (Spaces task #34)

Filter row uses traditional form-submit with `Apply` button. Wireframe shows reactive filter where chip clicks fire immediately without a submit.

**Files:**
- `templates/spaces/directory.php` — drop Apply button; wire each filter input to `data-wp-on--change=actions.applyFilter`.
- `assets/js/spaces/store.js` — debounced `applyFilter()` action that re-fetches and re-renders the grid.

### F11 DM bridge detection (Messages task — defer)

WPMediaVerse appears active (per Plugin.php class_exists guard) but the template still shows the dependency notice. Possibilities:
1. The `mvs_buddynext_active` filter isn't being hooked despite WPMediaVerse loading.
2. The template's `class_exists` check is for the wrong class name.
3. WPMediaVerse loaded but BN bridge listener didn't register.

Need to inspect `mu-plugins/buddynext-early-router.php` isolation — the early router strips non-BN plugins on front-end routes. WPMediaVerse might be in the strip list when it should be in the whitelist.

**Fix:** add WPMediaVerse + Jetonomy to the `buddynext_isolation_whitelist` default in the mu-plugin (or in the WP options for this site).

### F12 Onboarding progress bar (Onboarding task #38)

Numbered step list shows but no visual `.bn-progress` bar. Wireframe has a filled progress track that grows step-to-step.

**Files:**
- `templates/onboarding/index.php` — add `.bn-progress` element above the step content with `aria-valuenow` reactive to current step.
- `assets/css/bn-onboarding.css` — confirm the `.bn-progress` rule exists and matches v2 tokens.

### F13 Search filter tabs on Explore (Hashtags/Search task #37)

Explore page has search bar with 5 filter tabs (All / People / Posts / Spaces / Media). I didn't test whether clicking them actually changes results. Need a follow-up walk: enter a query, click each tab, assert results change.

If not wired, fix in `assets/js/search/store.js` with `setFacet()` action posting `?facet=people|posts|spaces|media`.

## Walking gaps (re-walk needed)

Pages I haven't audited yet on this pass:
- Space home (`/spaces/open-discussion/`)
- Space settings (owner view)
- Notification prefs page
- Search results page (`/search/?q=foo`)
- Login / Signup / Verify (logged out)
- Single post detail (`/p/{id}/`)
- Comments thread (deep nesting)
- Bookmarks list (`/me/bookmarks/`)
- Moderation queue (site admin)
- Settings → Features tab
- Logout flow

Add to next walk.

## Per-task dispatch plan

After this matrix is committed, dispatch one agent per task #32-#38. Each agent gets:
1. Read this matrix's section for their feature.
2. Read the relevant v2 wireframe.
3. Close every gap listed.
4. Run `bin/check.sh --skip-audit` clean.
5. Re-walk the surface via Playwright after the fix and capture before/after screenshots in `audit-2026-05-22/after-{task}/`.

Hard rules per agent:
- WPCS + PHPStan 5 clean.
- No emoji. No em-dashes. No inline `<style>` / `<script>` in PHP.
- REST-only frontend (no admin-ajax).
- 100% reactive UI via WP Interactivity API stores in `assets/js/{feature}/store.js`.
- Every new affordance must have empty / loading / success / error states.
- Every action must show optimistic UI with rollback on REST 4xx/5xx.
- Toast on every success (`buddynext_toast` global).

## What "production" means here

A surface is `prod` only when:

1. Every wireframe element is present, positioned per v2 tokens.
2. Every visible affordance is wired (button does what its label says).
3. Empty state renders gracefully when there is no data.
4. Loading state renders a skeleton or shimmer.
5. Success state shows a toast or inline confirmation.
6. Error state shows a recoverable message (not a stack trace, not a 500 page).
7. Keyboard accessible (Tab order, Enter/Space activation, focus rings).
8. Reads correctly on desktop (1440), iPad (834), mobile (390).
9. Dark mode legible (token rotation works).
10. No console errors / warnings on load + interaction.

The score in the table above is calibrated against these 10 rules, not "does the page render".
