# BuddyNext Bugs — Resume Plan (remaining cross-lane cards)

> STATUS: ALL FOUR DONE & shipped to Ready for Testing.
> - 9996403088 message gating — 8595685
> - 9996476016 social 7 settings — 4245363
> - 9996426090 comment @mention — 6684924
> - 9996533162 Reign left-panel overlap — 0d0ee25 (the "panel" is Reign's
>   "Left Panel" MENU LOCATION, not the v4 header. Fix: filter
>   theme_mod_reign_left_panel_gloabl_setting -> false on bn_hub pages in
>   includes/Theme/Appearance.php, so Reign's reign-panel.php bails on BN pages
>   only; reproduced by assigning a menu to the `panel-menu` location).
>
> This plan's queue is cleared. The sections below are kept for reference.


Basecamp project 47683682. Bugs column 9990191646 → Ready for Testing 9990094424.
Repo: this repo (free; live via symlink to the Local site at
/Users/vapvarun/Local Sites/buddynext-dev/app/public).

Per-card workflow: re-verify root cause → fix → verify MYSELF (browser 390px light/dark;
wp eval/DB; Mailpit http://localhost:10010) → commit+push (master) → comment the card with
fix+commit ref → move to Ready for Testing (9990094424). BN owns its own UX.
Local verify: `?autologin=1` (admin user 1; autologin SKIPS when already logged in — to test
another user, log out first or use wp eval). Lint: `php -l` (filter imagick/opcache/Zend),
`node --check`. Do the 4 in order; each is independent.

---

## 1. Card 9996403088 — Message feature / "install plugin" nag when WPMediaVerse absent
ROOT CAUSE: messaging entry points render unconditionally; `templates/messages/native.php`
(~L25-41) shows an end-user "install the plugin" notice. Single gate already exists:
`MessagesData::entry_enabled()` = `dm_enabled() && available()` (includes/Messages/MessagesData.php).
FIX: gate every messaging entry on `MessagesData::entry_enabled()`:
- includes/Nav/UserLinks.php — `#bn-messages` catalogue entry (mirror the existing spaces/DM
  drop-filter pattern in that file; it currently checks only dm_enabled).
- templates/parts/profile-hero.php (~L469 Message button), templates/parts/member-card.php,
  templates/parts/member-directory-grid.php, templates/directory/members.php, templates/spaces/members.php.
- templates/messages/native.php:25-41 — replace the install notice with a member-appropriate
  "Messaging isn't available right now." (no install nag to end users).
VERIFY: WPMediaVerse IS active locally; to test absent state, `wp plugin deactivate wpmediaverse`
(reactivate after) or unit-check entry_enabled() returns false when available() is false.

## 2. Card 9996476016 — Social tab: 7 settings stored but never consumed
None duplicate a FeatureRegistry feature → WIRE all 7 (don't remove). Targets:
- buddynext_default_post_privacy → includes/Feed/PostService.php::create() (privacy hardcoded
  ~L187 'public') + composer default.
- buddynext_allow_polls → reject type=poll in PostService/PollController when off (composer
  already gates the tool).
- buddynext_allow_shares → ShareService REST guard (`$can_share` already reads it, post-card.php:227).
- buddynext_allow_bookmarks → BookmarkService REST guard (`$can_bookmark` post-card.php:228).
- buddynext_enable_link_preview → guard the OG fetch in PostService::create() (~L159-161 / og_meta).
- buddynext_enable_emoji_picker → composer already gates the Insert-emoji button (confirm).
- buddynext_post_edit_window → PostService::update() reject edits older than the window (mins)
  for non-admins.
VERIFY per option: set option → perform action → confirm effect (DB/REST/browser).

## 3. Card 9996533162 — BuddyPanel menu overlaps BN left sidebar
ROOT CAUSE: theme's fixed BuddyPanel vs BN shell left rail / 100vw burst-out, both in
assets/css/bn-shell.css (`.bn-app { left:50%; margin-left:-50vw }` burst + `.bn-app__rail`).
FIX (BN-side): offset BN's burst-out / rail by the BuddyPanel width, or add clearance / z-index,
so they don't overlap. Scope to bn-shell.css. Verify with the Reign/BuddyX BuddyPanel visible,
desktop + 390px, light + dark.

## 4. Card 9996426090 — @mention notification not triggered (COMMENTS only)
ROOT CAUSE (per Varun's card comment): @mention in POSTS works (PostService::create() fires
`buddynext_user_mentioned` → NotificationListener::on_user_mentioned, hardcodes object_type
'post'). COMMENTS have NO mention parsing.
FIX (together):
- includes/Comments/CommentService.php::create() — parse @mentions from the comment body
  (mirror the post-side parser) and fire `buddynext_user_mentioned` with comment context.
- includes/Notifications/NotificationListener.php::on_user_mentioned() — accept an object_type
  param (currently hardcodes 'post') so comment mentions deep-link to the comment's post.
VERIFY: comment "@someuser ..." on a post → that user gets a bn.mention notification; notif URL
resolves (NotificationMessageService::url_for handles bn.mention via post_id).

---

## Session gotchas (don't re-break)
- Feature option leftovers: agents sometimes save '' which is NOT the registered default (true).
  If a feature looks off, `wp option delete <opt>` so get_option falls back to the default.
- PageRouter hub pages set is_singular=true + prime a virtual WP_Post (commit 3189567) — keep it;
  prevents theme body_class() warnings + the page-2 subheader bug.
- Onboarding gate grandfathers members registered before buddynext_onboarding_gate_since — don't
  change to redirect-all.
- SpacePostGuard (includes/Spaces/SpacePostGuard.php, registered in Plugin.php) enforces
  who_can_post (403) + require_post_approval (pending) on buddynext_post_before_save.
- PostService::create() flips status to 'scheduled' for a future scheduled_at (pending wins).
