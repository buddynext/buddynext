# QA — Onboarding, Invites & Setup Wizard (free)

**Manifest refs:** tables: `bn_invites` (invite tokens), `bn_email_templates` (`bn.bulk_invite` template) · REST routes: `POST buddynext/v1/invites/import-csv` (InviteController) + onboarding step endpoints (`buddynext/v1/onboarding/*`, 3 routes) · services: SetupWizard (admin 8-step), OnboardingService (member 4-step), InviteService, InviteController · capabilities: `manage_options` (wizard + CSV import); member onboarding requires `is_user_logged_in()`
**Cross-ref (no dup):** JOURNEYS J-04 (member onboarding wizard) · J-05 (email verify — fixme, token not predictable from runner) · J-06 (invite-accept registration) · FLOW-TEST-MATRIX M1 (register/verify — not yet walked) · M2 (onboarding — not yet walked) · O9 (onboarding wizard config — not yet walked)
**Admin location:** BuddyNext → Settings → Registration (reg mode, email verify, invites). Setup Wizard is a hidden submenu page (slug `buddynext-setup`), surfaced on first run. Member onboarding is frontend at `/onboarding/`.

---

## 1. Backend settings & options (justify each)

Two state machines write their own options/meta outside SETTINGS_MAP. Registration-tab options are covered canonically in component 11; the onboarding/wizard-specific state is below.

### Setup Wizard state (wp_options, written by `SetupWizard`)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_setup_complete` | (no UI — written by wizard) | unset / `''` | Done flag for the 8-step admin first-run wizard; set in `finish()` | Yes | Confirmed live value: **`1`** (wizard already finished on this site). No admin UI to re-trigger the wizard once complete — owner must clear this option by hand |
| `buddynext_setup_step` | (no UI — written by wizard) | `1` | Current step index (1-8) so the wizard resumes where the owner left off | Yes | Confirmed live value: **`8`** (Done). No "restart wizard" or "jump to step" control |
| `buddynext_page_*` (feed/members/profile/spaces) | written by wizard step 6 | WP page ID | Step 6 `wp_insert_post()`s the community hub pages and stores each ID as `buddynext_page_{key}`, then `flush_rewrite_rules(false)` | Yes | Shared with PageRouter (component 10/11). If wizard step 6 is skipped, hub pages may be absent — verify PageSetup integrity guard backfills them |

**Wizard step map (`TOTAL_STEPS = 8`):** 1 Branding · 2 Registration · 3 Profile Fields · 4 Notifications · 5 Spaces · 6 Pages · 7 Addons · 8 Done. `ALLOWED_SETTINGS` persisted by the wizard: `site_name`, `brand_color`, `reg_mode`, `email_verify`. Step 3 provisions profile group presets (social_links, work_experience, education, skills, interests) via ProfileService. Step 5 writes directly to `bn_space_categories` via `$wpdb->insert()`. `finish()` fires `do_action( 'buddynext_setup_complete' )` (note: the docblock says `buddynext_onboarding_completed` but the actual call is `buddynext_setup_complete` — a doc/code mismatch worth flagging).

### Member onboarding state (user_meta, written by `OnboardingService`)

| Meta key | Location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `bn_onboarding_step` | per-user | unset | Current step (1-4) of the member onboarding flow | Yes | Steps 2-4 persist via their own REST endpoints, not `save_step()` — only step 1 (display_name + bio) is saved here |
| `bn_onboarding_complete` | per-user | unset → `'1'` | Done flag; gate read by `PageRouter` (if set and no `?redo=1`, `/onboarding/` redirects to the activity feed) | Yes | Canonical key is `bn_onboarding_complete` (NOT `bn_onboarding_completed` — earlier template bug, now fixed). `finish()` fires `buddynext_onboarding_completed( $user_id )` once, idempotent |
| `bn_bio` / `description` | per-user | `''` | Bio routed through ProfileService when available, mirrored to both usermeta keys as fallback; capped at 1,000 chars | Yes | Two mirror keys — confirm profile view reads the same one the directory reads (cross-ref component 04) |
| `bn_profile_slug` | per-user | `user-{id}` | Slug set in onboarding step; collision-checked via `PageRouter::is_slug_available()` | Yes | — |
| `bn_channel_prefs` | per-user | `[]` | Notification channels (`in_app`, `email`, `push`, `sound`) merged in onboarding step 4 | Yes | — |

### Member onboarding global gate

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_show_onboarding` | Settings → General (component 11) | `1` | Toggle: show member onboarding wizard to new members **[TOGGLE-BUG]** | Yes | Global toggle bug; no per-role onboarding, no "re-trigger for existing members" beyond `?redo=1` URL |

---

## 2. Frontend functions (function-by-function)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Admin setup wizard | `admin.php?page=buddynext-setup` (hidden submenu) | admin-post `buddynext_wizard_step` | 8-step state machine; `handle_step_submit()` handles `back` / `skip` / `save_exit` / `continue`; step 8 `finish()` sets `buddynext_setup_complete` + fires `buddynext_setup_complete` action. Enqueues `bn-onboarding.css` + `assets/js/admin/setup-wizard.js` |
| Member onboarding flow | `/onboarding/` → `templates/onboarding/index.php` | `buddynext/v1/onboarding/*` (step persist) | 4-step progress (Profile → Spaces → People → Notifications); guest-redirected upstream; if `bn_onboarding_complete` set and no `?redo=1`, redirects to activity feed |
| Onboarding step 1 (profile) | `/onboarding/` step 1 | `OnboardingService::save_step()` → `save_profile()` | display_name via `wp_update_user()`; bio via ProfileService (fallback `bn_bio` + `description` usermeta), capped 1,000 chars |
| Onboarding slug step | `/onboarding/` | `OnboardingService::save_slug()` | Sanitize slug, collision check via `PageRouter::is_slug_available()`, write `bn_profile_slug` |
| Onboarding channels step | `/onboarding/` step 4 | `OnboardingService::save_channels()` | Merge `in_app`/`email`/`push`/`sound` into existing `bn_channel_prefs` array |
| Onboarding skip | `/onboarding/` Skip button | `OnboardingService::skip()` | `mark_complete()` without saving data; sets `bn_onboarding_complete = '1'` |
| Onboarding finish | `/onboarding/` final Continue | `OnboardingService::finish()` | Fires `buddynext_onboarding_completed( $user_id )` on first call only (idempotent); marks complete |
| Bulk invite — CSV import | Admin invites UI | `POST buddynext/v1/invites/import-csv` (InviteController) | Reads `$_FILES['csv_file']`; validates upload error + MIME via `finfo` (text/csv, text/plain, application/csv, vnd.ms-excel); delegates to `InviteService::import_from_csv()`; returns `{imported, skipped, errors[]}` (200). `require_admin`: logged-in (401) + `manage_options` (403) |
| Invite create | Admin invites UI | `InviteService::create()` | Insert into `bn_invites` (email, first_name, 64-char hex token, status `pending`, `expires_at` = +7 days); dispatch invite email (`bn.bulk_invite` template, `{{first_name}}`/`{{site_name}}`/`{{invite_url}}` tokens; URL = `wp_registration_url()` + `?bn_invite={token}`) |
| Invite accept (registration) | `wp_registration_url()?bn_invite={token}` | `InviteService::get_by_token()` → `mark_registered()` | `get_by_token()` returns only non-expired, status `pending` rows (compared vs `current_time('mysql', true)` UTC); registration consumes token, marks registered (J-06) |
| Invite resend | Admin invites UI | `InviteService::resend()` | Generates new token, resets expiry + status to `pending`, dispatches email again |
| Invite revoke | Admin invites UI | `InviteService::revoke()` | `$wpdb->delete()` by ID, returns bool |
| Pending invites list | Admin invites UI | `InviteService::get_pending()` | All pending invites ordered `created_at DESC` |

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-ONB-001 | admin | backend | Fresh install, `buddynext_setup_complete` unset | Visit `admin.php?page=buddynext-setup?autologin=1`; verify wizard step 1 (Branding) renders | 8-step progress bar shown, step 1 active; Site Name + Brand Color fields present; no PHP/JS errors; `bn-onboarding.css` + `setup-wizard.js` loaded | 1440px, 768px, 390px |
| QA-ONB-002 | admin | backend | Wizard step 1 | Enter Site Name `TestHub`, brand color; click Continue | `buddynext_setup_step` advances to 2; `buddynext_site_name` = `TestHub` (`wp option get buddynext_site_name`); step 2 (Registration) renders | 1440px |
| QA-ONB-003 | admin | backend | Wizard mid-flow (step 3) | Click Back | Returns to step 2 with prior values intact; `buddynext_setup_step` decrements | 1440px |
| QA-ONB-004 | admin | backend | Wizard any step | Click Skip on a step | Step skipped, advances without persisting that step's data; `handle_step_submit()` skip branch | 1440px |
| QA-ONB-005 | admin | backend | Wizard step 5 (Spaces) | Add a space category; Continue | Row inserted into `bn_space_categories` (`$wpdb->insert`); verify via `wp db query "SELECT * FROM wp_bn_space_categories"` | 1440px |
| QA-ONB-006 | admin | backend | Wizard step 6 (Pages) | Continue through Pages step | Hub pages `wp_insert_post()`ed; `buddynext_page_{feed,members,profile,spaces}` options set; `flush_rewrite_rules` runs; frontend hub URLs resolve | 1440px |
| QA-ONB-007 | admin | backend | Wizard step 8 (Done) | Click Finish | `buddynext_setup_complete` set; `do_action('buddynext_setup_complete')` fires; revisiting `buddynext-setup` should not force-restart (verify resume/complete behavior) | 1440px, 390px |
| QA-ONB-008 | admin | backend | Mid-wizard | Click Save & Exit | Progress persisted to `buddynext_setup_step`; admin returned to dashboard; re-entering resumes at saved step | 1440px |
| QA-ONB-009 | member | frontend | New member, `bn_onboarding_complete` unset; `?autologin=member1` | Navigate to `/onboarding/` | 4-step progress (Profile/Spaces/People/Notifications); step 1 form (display name + bio); Skip + Continue buttons; renders clean at 390px | 1440px, 390px |
| QA-ONB-010 | member | frontend | Onboarding step 1 | Enter display name + bio (>1,000 chars); Continue | display_name saved via `wp_update_user`; bio truncated to 1,000 chars; `bn_bio` + `description` usermeta written; advances to step 2 | 1440px |
| QA-ONB-011 | member | frontend | Onboarding, slug step | Enter a slug already taken by another member | Collision rejected via `PageRouter::is_slug_available()`; inline error; slug not written | 1440px, 390px |
| QA-ONB-012 | member | frontend | Onboarding step 4 (Notifications) | Toggle channels; Finish | `bn_channel_prefs` merged with `in_app`/`email`/`push`/`sound`; `bn_onboarding_complete = '1'`; `buddynext_onboarding_completed` fires once | 1440px, 390px |
| QA-ONB-013 | member | frontend | `bn_onboarding_complete = '1'` | Navigate to `/onboarding/` (no query) | Redirected to activity feed (PageRouter onboarding-hub guard) | 1440px |
| QA-ONB-014 | member | frontend | `bn_onboarding_complete = '1'` | Navigate to `/onboarding/?redo=1` | Onboarding renders again (redo override); step 1 shown | 1440px, 390px |
| QA-ONB-015 | member | frontend | Onboarding step 1 | Click Skip immediately | `OnboardingService::skip()` → `mark_complete()`; `bn_onboarding_complete = '1'` set with no profile data saved; redirect to feed | 1440px |
| QA-ONB-016 | guest | frontend | Logged out | Navigate directly to `/onboarding/` | Guest redirected to `auth_url()` (login-required hub) before onboarding renders | 1440px, 390px |
| QA-ONB-017 | admin | api | Admin logged in; valid CSV (email, first_name) | `POST buddynext/v1/invites/import-csv` with `csv_file` | 200 `{imported, skipped, errors}`; valid rows inserted into `bn_invites` (status pending, 64-char token, +7d expiry); header row skipped; invalid emails counted in `skipped` | n/a |
| QA-ONB-018 | admin | api | Upload a non-CSV file (e.g. `.exe`) | `POST .../invites/import-csv` | Rejected on `finfo` MIME check; error response; nothing inserted | n/a |
| QA-ONB-019 | non-admin (subscriber) | api | Logged in as subscriber | `POST .../invites/import-csv` | 403 — `require_admin` fails on `manage_options` (401 if logged out) | n/a |
| QA-ONB-020 | new user | frontend | Pending invite exists; valid token | Visit `wp_registration_url()?bn_invite={token}`; complete registration | `get_by_token()` returns the pending non-expired row; account created; invite `mark_registered()` (status updated); J-06 | 1440px, 390px |
| QA-ONB-021 | new user | frontend | Invite token expired (>7 days) | Visit registration URL with the expired token | `get_by_token()` returns nothing (expiry filtered); registration proceeds as normal/blocked per reg mode but invite NOT consumed | 1440px |
| QA-ONB-022 | admin | backend | Pending invite in list | Click Resend, then Revoke another | Resend: new token + reset expiry + status pending + email re-dispatched. Revoke: row deleted from `bn_invites` (`$wpdb->delete`); list refreshes | 1440px, 390px |

---

## 4. Site-owner expectations & suggestions

- **No way to re-run the Setup Wizard from the UI.** Once `buddynext_setup_complete` is set, the only path back into the 8-step wizard is a manual `wp option delete buddynext_setup_complete`. Owners reconfiguring a community (rebrand, new reg mode) expect a "Re-run setup" button. Add one to Settings → General or the BuddyNext dashboard. Priority: medium.

- **Wizard `finish()` action name mismatches its docblock.** `finish()` fires `do_action('buddynext_setup_complete')` while the docblock documents `buddynext_onboarding_completed`. Any integrator hooking the documented name gets nothing. Reconcile doc + code (the action fired is the contract). Priority: medium (developer-facing contract integrity).

- **Member onboarding has no admin preview / config surface (O9 not walked).** The owner can toggle `buddynext_show_onboarding` but cannot preview the 4 steps, reorder them, or choose which steps appear (e.g. skip the "People to follow" step on a brand-new site with no members). A config panel (or at least a preview link) is expected. Priority: medium.

- **`buddynext_show_onboarding` is subject to the global toggle-OFF bug.** An owner who unchecks "show onboarding" finds it silently reverts ON (same `render_toggle_row()` hidden-input bug as component 11). Until that bug is fixed, onboarding cannot be reliably disabled. Priority: high (shared root cause with admin component).

- **Bulk invites lack a built-in CSV template / column documentation in-product.** `import_from_csv()` expects column 0 = email, column 1 = first_name, header skipped — but nothing in the UI tells the owner that. Add a downloadable sample CSV and inline column hints next to the upload control. Priority: low.

- **No bounce / delivery tracking surfaced for invites.** `InviteService` has `mark_bounced()`, but there is no admin view showing which invites bounced vs registered vs still pending. A status column in the pending-invites list (pending / registered / bounced / expired) plus a count would let owners chase non-responders. Priority: low.

- **Invite expiry (7 days) is hardcoded.** `DEFAULT_TTL_DAYS = 7` with no setting. Owners running a slow onboarding cohort may want 14 or 30 days. Expose a `buddynext_invite_ttl_days` option. Priority: low.

- **Bio mirrored to two meta keys (`bn_bio` + `description`) risks drift.** Onboarding writes both as a fallback; confirm profile view and member directory both read the same key, or a member's bio can appear in one surface and not the other. Priority: low (cross-ref component 04).
