# BuddyNext — 100% Translation-Readiness Plan

Goal: every user-facing string in BuddyNext (free) is translatable and renders through the correct gettext / dictionary path, and a current `.pot` ships in `languages/`. Pro ships zero templates — all frontend rendering is in free, so this plan is free-only. Textdomain: `buddynext`.

Status legend: `[x]` done · `[ ]` pending · `[~]` partial.

---

## 1. Templates — DONE ✅

Audited all 179 free templates; 16 gaps across 10 files fixed and browser-verified (2026-06-21).

- [x] `_n()` plurals: profile-tab-panel, space-discussions-panel, space-settings-panel-members, spaces/directory ×2, partials/sidebar, community-admin
- [x] `ucfirst()` enum slugs → translated label maps: explore-card (SpaceTypeRegistry::label), profile-right-sidebar (role map), spaces/moderation (reason map)
- [x] Concatenation → `sprintf` placeholders: explore-card "Poll · " / "Discussion · "
- [x] Count-aware Nav label mechanism (`NavItem::count_label` → `label_value` → nav-metrics.php); ProfileNav Followers/Connections pluralize, "Following" stays a gerund
- [~] `spaces/members.php` ucfirst — left intentionally (unknown-custom-role fallback; unregistered slugs cannot be translated)

PHP template layer is now translation-clean (zero unwrapped strings / wrong-domain / non-literal args remaining).

---

## 1b. Backend PHP (`includes/`) — DONE ✅

Verified two ways: (1) `vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.WP.I18n` across all 186 `includes/` + 179 `templates/` files → **0 violations** (gettext-call correctness). (2) Four parallel agents swept all 186 files for *fully unwrapped* strings (the sniff can't see those).

Found + fixed (the only real gaps; everything else clean):
- [x] `Admin/AdminHub.php` — 12 admin menu labels were a raw `const` translated via `__($var)` (translates at runtime but does NOT extract to POT). Converted `DEFAULT_SECTIONS` const → `default_sections()` method with `__()` labels; render no longer double-translates. Browser-verified.
- [x] `Admin/RolesTab.php` — 25 strings (group headings + capability labels + role choices) in raw consts. Converted `CATALOG`/`ROLE_CHOICES` consts → methods with `__()` (incl. `__()` as array-key for headings). Browser-verified.
- [x] `Profile/FieldType.php` — 13 field-type picker labels in `BUILTIN_TYPES` const → `builtin_types()` method.
- [x] `Onboarding/SetupWizard.php` — 13 profile-field preset labels (method, wrapped inline; 5 social brand-name labels left raw).
- [x] `Admin/Members/MemberExport.php` — 6 CSV export column headers wrapped.
- [x] `Admin/Members/AvatarSettings.php` — "No cover set" inline-SVG placeholder wrapped.

KEY LESSON: `const` arrays can't hold `__()` and `__($var)` doesn't extract to POT — translatable strings must live in a method/function as literals. Brand/proper nouns (Google, Facebook, MediaVerse, Jetonomy, Learnomy, Listora…) correctly left raw. Demo-seed (`DemoDataService`) and WP-CLI cert strings are dev-only, left raw. block.json strings auto-translate via `register_block_type`.

---

## 2. JS Script Modules — DONE ✅ (all 18 feature stores localized; gamification/shell modules have no user-facing strings)

Script Modules cannot use `wp_set_script_translations()` (no per-module JED loading in WP 6.8). Each feature's strings are injected server-side into the Interactivity state and read in the store. **No build step** — assets/js is native ESM.

### Locked recipe (proven on social/follow)
1. **PHP** (`includes/Core/AssetService.php::inject_interactivity_i18n()`, hooked on `wp_enqueue_scripts`, front-end only):
   ```php
   wp_interactivity_state( 'buddynext/<ns>', array( 'i18n' => array(
       'follow'          => __( 'Follow', 'buddynext' ),
       /* translators: %s: member name */
       'toastNowFollowing' => __( 'Now following @%s', 'buddynext' ),
   ) ) );
   ```
   Interpolated strings use `%s` placeholders + translator comments. One dict can serve every namespace in a file.
2. **JS store** (the feature's `store.js`):
   ```js
   let I18N = {};
   function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
   function fmt( tpl, v ) { return String( null == tpl ? '' : tpl ).replace( '%s', String( v ) ); }
   const s = store( 'buddynext/<ns>', { /* ... */ } );
   I18N = ( s.state && s.state.i18n ) || {};
   // labels:  t( 'follow', 'Follow' )
   // interp:  fmt( t( 'toastNowFollowing', 'Now following @%s' ), name )
   ```
   Keep the English literal as the fallback in every `t()`/`fmt()` call.
3. **Verify** per feature: dict prints in `#wp-script-module-data-@wordpress/interactivity`; rendered labels unchanged; an action that emits an interpolated toast proves the `fmt` path; no console errors; no regression to the action.

### Module waves (string counts from the JS i18n audit)
Order by traffic/value. Each module = one wave (PHP dict block + JS refactor + browser verify).

- [x] **social/follow** (`buddynext/follow-button` +3 ns) — ~30 — DONE/verified
- [x] **feed** (`buddynext/feed`) — ~70 — feed/store.js (reaction labels, comment/report toasts)
- [x] **profile** (`buddynext/profile`) — ~60 — profile/store.js (save/delete/avatar dialogs)
- [x] **members** (`buddynext/members`) — ~40 — members/store.js (follow/connect/block toasts; note this is the directory's follow path)
- [x] **spaces** (`buddynext/spaces`) — ~55 — spaces/store.js (already partly wrapped via local `__i18n()`; standardize on the dict)
- [x] **messages** (`buddynext/messages`) — ~40 — messages/store.js (send/leave/rename/report)
- [x] **moderation** (`buddynext/moderation`) — ~30 — moderation/store.js (remove/suspend/appeal)
- [x] **onboarding** (`buddynext/onboarding`) — ~24 — onboarding/store.js (validation, upload, welcome)
- [x] **notifications** (`buddynext/notifications`) — ~12 — notifications/store.js
- [x] **notification-prefs** (`buddynext/notification-prefs`) — ~13 — notifications/prefs-store.js
- [x] **search** (`buddynext/search`) — ~8 — search/store.js (partly wrapped; standardize)
- [x] **hashtags** (`buddynext/hashtags`) — ~6 — hashtags/store.js
- [x] **space-members** (`buddynext/space-members`) — ~7–11 — space-members/store.js
- [x] **auth** (`buddynext/auth`) — ~7 — auth/store.js (strength labels, show/hide)
- [x] **auth-login** (`buddynext/auth-login`) — ~11 — auth/login-store.js
- [x] **auth-signup** (`buddynext/auth-signup`) — ~12 — auth/signup-store.js
- [x] **auth-verify** (`buddynext/auth-verify`) — ~3 — auth/verify-store.js
- [x] **auth-reset** (`buddynext/auth-reset`) — ~7 — auth/reset-store.js
- [x] **gamification** (`buddynext/gamification`) — 0 strings — verify only, likely no change
- [ ] **blocks editor labels** (`assets/js/blocks.js`, classic but editor-side) — ~18 block labels + ~8 frontend — see classic section
- [ ] shell modules (`shell/navigate`, `shell/nav-init`) — 0 user-facing — verify only

Approx remaining module strings: ~480.

---

## 3. Classic scripts — 18 handles (standard path)

These are classic enqueues (mostly admin) and CAN use the normal path:
1. Add `'wp-i18n'` to the handle's deps array at its enqueue site.
2. `wp_set_script_translations( $handle, 'buddynext' )` after enqueue/register.
3. Wrap strings in JS with `const { __, _n, sprintf } = wp.i18n;` (global, no import) — or `import` is N/A for classic, use the `wp.i18n` global.

Handles (file → enqueuer):
- [ ] `bn-shell-extras` (shell/extras.js — ~13: search/notif empty states) — AssetService
- [ ] `bn-media-lightbox` (media/lightbox.js — ~7) — MediaAssets
- [ ] `bn-shell-font-scale` (shell/font-scale.js — 2 aria) — AssetService
- [ ] `bn-admin-dialogs` (admin/bn-admin-dialogs.js — ~6) — AdminHub
- [ ] `buddynext-admin-settings` (admin/settings.js — ~23) — Settings
- [ ] `bn-admin-members` (admin/members.js — 8) — Members
- [ ] `bn-nav-manager` (admin/nav-manager.js — 10) — NavManager
- [ ] `bn-profile-fields` (admin/profile-fields.js — ~13) — ProfileFieldsManager
- [ ] `bn-avatar-settings` (admin/avatar-settings.js — 6) — AvatarSettings
- [ ] `bn-setup-wizard` (admin/setup-wizard.js — 9) — SetupWizard
- [ ] `bn-email-editor` (admin/email-editor.js — 9) — EmailEditor
- [ ] `bn-admin-spaces` (admin/spaces.js — 7) — Admin/Spaces
- [ ] `bn-admin-palette` (admin/command-palette.js — 7) — AdminHub
- [ ] `bn-admin-bulk-select` (admin/bulk-select.js — 4) — AdminHub
- [ ] `bn-admin-taxonomy` (admin/taxonomy-editor.js — ~8) — taxonomy enqueuer
- [ ] `buddynext-blocks-editor` (blocks.js — ~26 incl. block labels) — BlockRegistrar (also needs `wp-i18n` dep)
- [ ] `bn-cookie-consent` (privacy/consent-banner.js — 0, server-rendered copy) — verify only
- [ ] `bn-pwa-sw` (pwa/sw-register.js — 0) — verify only

Also standardize the few stores already using `window.wp.i18n.__` / local `__i18n()` (spaces, messages, search) onto the module-dict recipe for consistency.

---

## 4. POT generation + load

- [ ] Create `languages/` and generate `languages/buddynext.pot` via `wp i18n make-pot . languages/buddynext.pot --domain=buddynext --exclude=vendor,node_modules,assets/js/*.min.js`.
- [ ] Confirm `load_plugin_textdomain( 'buddynext', ... )` (or relying on WP.org auto-load) is wired; add if missing.
- [ ] Verify the JS-injected strings are captured: `make-pot` scans PHP `__()` in `inject_interactivity_i18n()` (they are real PHP gettext calls, so they land in the POT automatically — this is a benefit of the dictionary approach).
- [ ] Re-run `make-pot` as the final step after all waves.

---

## 5. Verification gate (per wave + final)

- Per module: browser-load a page using the feature, confirm labels render, trigger one interpolated action, check the toast text + no console error.
- Per classic handle: confirm `wp_set_script_translations` present and strings wrapped; smoke the admin screen.
- Final: run WPCS `WordPress.WP.I18n` sniff across `templates/` + `includes/` (no missing-domain/non-literal/missing-translator-comment); regenerate POT; spot-check with a test locale (e.g. set a few translations and confirm they render front + admin).

---

## 6. Parked (non-i18n) — decisions still open

These came out of the Suggestions-column triage and are unrelated to i18n but tracked so they aren't lost:
- [ ] Members-directory "Edit profile" button removal (card 9995778357a) — needs remove vs replace decision; source `templates/parts/member-directory-hero.php:58-65`.
- [ ] Hashtag coverage: index 6 more post types? (card 9995695277) — scope decision; `HashtagListener.php:87` allowlist.
- [ ] 390px polish: notifications tab clip + "Change cover" tap target (card 9995961228).

---

## Effort estimate

~22 module waves (~480 strings) + 18 classic handles (~80 strings) + POT. Largest single concern is feed/profile/spaces (the ~185-string trio). No build, no version bump. Recipe is proven; remaining work is mechanical-but-careful per the recipe, with a browser gate each wave.
