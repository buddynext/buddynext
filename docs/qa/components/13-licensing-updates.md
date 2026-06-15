# QA — Licensing & Updates (free)

**Manifest refs:** tables: none (license state in `wp_options` only) · REST routes: none (license calls go direct to `https://wbcomdesigns.com` via `wp_remote_post`) · services: none (wired directly in `buddynext.php`) · SDK: `libs/edd-sl-sdk/` v1.0.3 · capabilities: `manage_options` (License tab, Pro only)
**Cross-ref (no dup):** no JOURNEYS journey covers licensing (add one); no FLOW-TEST-MATRIX row covers licensing; CLAUDE.md Licensing & Updates section is authoritative for design decisions
**Admin location:** No visible License tab in Free; License tab appears only when BuddyNext Pro is active and fires `buddynext_admin_license_tab_content` · EDD SL SDK updates surface via WP Dashboard → Updates (standard WP update system)

---

## 1. Backend settings & options (justify each)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_license_key` | Set programmatically on `admin_init` (never exposed in Free UI) | `'buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55'` | License key sent to `https://wbcomdesigns.com` for update eligibility; preset is a lifetime unlimited-activation Free key | Yes | Key is visible in plaintext via `wp option get buddynext_license_key`; anyone with WP admin or DB access can read it. Acceptable for a preset key but worth noting. |
| `buddynext_preset_activated` | Set on first successful preset activation | `null` → `1` | Guard flag; prevents the `admin_init` auto-activation routine from running more than once | Yes | If the activation POST to wbcomdesigns.com fails (network unavailable), `buddynext_preset_activated` is never written and the routine retries on every `admin_init` — unbounded outbound HTTP calls. No retry cap or `transient`-based cooldown. |
| `buddynext_license_key_allow_tracking` | Set alongside `buddynext_preset_activated` on first successful activation | `null` → `{allowed: true, timestamp: <int>}` | Records whether usage tracking was accepted as part of preset activation | Questionable | Option is written unconditionally with `allowed: true` during auto-activation; the site owner was never asked. No opt-out UI in Free. |
| `buddynext-pro_license_key` | Settings → License tab (Pro only) | `''` | Customer's paid Pro license key; entered manually, persisted by Pro's `admin_init` handler | Yes (Pro concern) | Out of scope for Free QA; documented here for completeness — the Free License tab does not exist. |

### EDD SL SDK registration facts (from `buddynext.php` bottom section)

| Field | Value |
|---|---|
| Registry ID | `buddynext` |
| Store URL | `https://wbcomdesigns.com` |
| Item ID | `1664401` |
| Version | `BUDDYNEXT_VERSION` constant |
| Preset license key | `buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55` |
| SDK hook | `edd_sl_sdk_registry` (fires on `after_setup_theme` priority 1) |
| Auto-activation hook | `admin_init` |
| Activation endpoint | `https://wbcomdesigns.com/?edd_action=activate_license` |
| Activation timeout | 15 seconds |

### Architecture rule (owner decision 2026-06-12, non-negotiable)

**License gates UPDATES ONLY. It must never gate plugin functionality.**
Every QA case that tests a licensed state must assert that all community features continue to work regardless of license validity.

---

## 2. Frontend functions

This component has no public frontend surface. All license activity is:
- Server-side only (`admin_init` hook, `wp_remote_post` to wbcomdesigns.com)
- WP Dashboard → Updates (standard WordPress update pipeline via EDD SL SDK)
- Pro-only License tab admin form (out of scope for Free)

| Function | Surface / route | What it does |
|---|---|---|
| Preset auto-activation | `admin_init` hook in `buddynext.php` | If `buddynext_preset_activated` is absent: writes preset key to `buddynext_license_key`, POSTs to EDD store, sets `buddynext_preset_activated = 1` on `'valid'` response |
| Update check | WP Dashboard → Updates (EDD SL SDK, `after_setup_theme` priority 1) | SDK polls `https://wbcomdesigns.com` for plugin update metadata; injects into WP update system |
| License tab render | `buddynext_admin_license_tab_content` action (Pro only) | Pro hooks this to render activate/deactivate form; Free emits nothing |

---

## 3. QA cases

**Critical rule for all cases below:** after any license manipulation, verify that core community features (post creation, feed, spaces, moderation) still function. License state must never break functionality.

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-LIC-001 | admin | backend | Fresh install | Visit `wp-admin/?autologin=1`; run `wp option get buddynext_preset_activated --path="/Users/varundubey/Local Sites/buddynext/app/public"` | Value = `1`; preset activation ran on first `admin_init` | n/a |
| QA-LIC-002 | admin | backend | `buddynext_preset_activated = 1` (normal state) | `wp option get buddynext_license_key --path="..."` | Returns preset key `buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55` | n/a |
| QA-LIC-003 | admin | backend | Normal state | Navigate to `admin.php?page=buddynext&tab=license?autologin=1` | **Free: no License tab rendered**; tab is absent from the Settings tab list; no 404 or PHP error | 1440px, 390px |
| QA-LIC-004 | admin | backend | Normal state | Navigate to `admin.php?page=buddynext&tab=license` with BuddyNext Pro NOT active | No output for `buddynext_admin_license_tab_content`; tab either absent or shows empty panel — no fatal error | 1440px |
| QA-LIC-005 | admin | backend | Simulate activation failure | Delete `buddynext_preset_activated` option: `wp option delete buddynext_preset_activated --path="..."`; block outbound HTTP via `wp-config.php` constant `define('WP_HTTP_BLOCK_EXTERNAL', true)`; reload any admin page | `admin_init` fires; `wp_remote_post` returns `WP_Error`; `buddynext_preset_activated` is NOT written (loop will retry next request) — **verify no fatal error is thrown** | 1440px |
| QA-LIC-006 | admin | backend | After QA-LIC-005 | With `WP_HTTP_BLOCK_EXTERNAL` still set, reload admin 5 times | Activation POST is attempted on every `admin_init` — confirms absence of retry cap; no fatal, but 5 outbound HTTP attempts logged in Query Monitor | 1440px |
| QA-LIC-007 | admin | backend | Normal state (`buddynext_preset_activated = 1`) | `wp option set buddynext_license_key invalid-key --path="..."`; reload any admin page | `buddynext_preset_activated = 1` prevents the auto-activation routine from running again; invalid key is left in place; **no community feature is broken** | 1440px |
| QA-LIC-008 | admin | frontend | After QA-LIC-007 (invalid license key) | Visit `http://buddynext.local/activity/?autologin=1`; create a post; visit spaces; load member directory | All features work normally; invalid license key never gates functionality | 1440px, 390px |
| QA-LIC-009 | admin | backend | Normal state | `wp option delete buddynext_license_key --path="..."`; reload admin | Auto-activation does NOT re-run (guard = `buddynext_preset_activated = 1`); license key option is absent; **no community feature broken**; Dashboard → Updates may show update as unavailable (acceptable) | 1440px |
| QA-LIC-010 | admin | backend | Normal state | `wp option delete buddynext_preset_activated --path="..."`; `wp option delete buddynext_license_key --path="..."`; reload admin page (with network access available) | Auto-activation re-runs; key written; POST to wbcomdesigns.com; on success `buddynext_preset_activated` re-set to `1` | 1440px |
| QA-LIC-011 | admin | backend | Normal state | Navigate to Dashboard → Updates | BuddyNext appears in "Plugins" section if an update is available; update installs via standard WP mechanism; EDD SL SDK drives this — **no BuddyNext-specific UI required beyond the WP Updates screen** | 1440px |
| QA-LIC-012 | admin | backend | Normal state | `wp option get buddynext_license_key_allow_tracking --path="..."` | Returns serialized `{allowed: true, timestamp: <int>}`; **verify** value was set without user consent (known gap — no opt-out UI) | n/a |
| QA-LIC-013 | subscriber | backend | Subscriber session | Navigate to `admin.php?page=buddynext&tab=license` as subscriber | `manage_options` check blocks access; WP redirects to `wp-login.php` or shows "You don't have permission to access this page" | 1440px |

---

## 4. Site-owner expectations & suggestions

- **No retry cap on failed preset activation.** If `wp_remote_post()` to wbcomdesigns.com fails (store down, DNS failure, `WP_HTTP_BLOCK_EXTERNAL` set), the routine retries on every `admin_init` indefinitely. This adds ~15 seconds of HTTP timeout to every admin page load until the store responds. Add a 6-hour `transient` cooldown after a failed attempt. Priority: high.

- **Usage-tracking opt-in not shown.** `buddynext_license_key_allow_tracking` is written with `allowed: true` during auto-activation without displaying a consent UI. GDPR-compliant plugins ask first. Add an opt-in notice (admin notice dismissed on click) and only write `allowed: true` after explicit consent. Priority: high.

- **License state not visible anywhere in Free.** A site owner wondering whether their install is licensed for updates has no screen to check. Add a one-line "License" row to the Settings → General tab (or a status widget on the admin dashboard) showing "Free — licensed for updates" or "License key invalid — updates unavailable." Priority: medium.

- **No grace-period on invalid key.** If the preset activation fails and the key is invalid, the WP Updates screen simply shows no update available. There is no admin notice explaining why updates stopped. Surfacing an actionable notice ("BuddyNext: update check failed — visit Settings to re-activate your license") would reduce confusion. Priority: medium.

- **`buddynext_license_key` is readable by any WP admin.** The preset key is a lifetime, unlimited-activation key. If it were to be read and used on another site it would still pass the EDD activation check. This is acceptable for a Free preset but worth acknowledging. No fix required; documenting as known. Priority: low.
