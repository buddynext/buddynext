# Journey: Onboarding (Signup Wizard + Community Invites + Setup Wizard)

**Free feature**: `includes/Onboarding/` (OnboardingService, InviteService, SetupWizard, OnboardingListener) + `includes/Auth/AuthController.php` (registration)
**Actions / filters fired**: `buddynext_onboarding_completed` (member wizard finish/skip→finish), `buddynext_setup_complete` (admin setup wizard done), `user_register` (schedules 24h/72h nudge cron), `bn_onboarding_nudge_24h` / `bn_onboarding_nudge_72h` (single cron events), `buddynext_registration_blocked` (filter chain in RegistrationGuard)
**Options written**: `buddynext_reg_mode` (open/invite/approval), `buddynext_email_verify`, `buddynext_setup_step`, `buddynext_setup_complete`, `buddynext_site_name`, `buddynext_brand_color`, `buddynext_default_notif_prefs`, `buddynext_page_{feed,members,profile,spaces}`
**User meta written**: `bn_onboarding_step`, `bn_onboarding_complete`, `bn_profile_slug`, `bn_channel_prefs`, `bn_bio`
**DB tables touched**: `bn_invites`, `bn_space_categories` (setup wizard), `bn_space_members` + `bn_follows` (wizard complete side-effects)
**Estimated time**: 14 min manual

## Site-owner expectation

What a community owner expects onboarding + invites to do out of the box, and what they actually configure:

- **First-run setup wizard** (`?page=buddynext-setup`): an 8-step admin wizard that, on first install, lets the owner set branding, **choose the registration mode** (`open` / `invite` / `approval`), toggle email verification, provision profile-field group presets, set default notification prefs, seed space categories, and create the core community pages. It is the owner's single config surface for "how do people get in".
- **Registration mode** is the owner's main lever: `open` = anyone signs up; `invite` = only invited emails should get in; `approval` = admins review each request. The owner sets this once in the wizard (step 2) or later in **Settings → Registration**.
- **Community invites**: the owner expects to paste/upload a list of emails, have each get a unique tokenised invite link by email, and have those people land in a pre-filled signup. Managed under **Members → Invites** (single create via CSV/bulk) and the `POST /invites/import-csv` REST route.
- **New-member onboarding wizard**: every new member is dropped into a 4-step wizard (Profile → Spaces → People → Notifications) right after registration/verification, then lands on the activity feed. The owner expects this to "just happen" — they don't configure it per-member.
- **Out-of-box, no config**: the member wizard, the 24h/72h nudge emails for members who don't finish, and the default `open` reg mode all work with zero owner setup.

> Reality check (see Known limitations): `buddynext_reg_mode` is a **stored preference that the REST registration path does not yet enforce**, and the invite **token is generated + emailed but never redeemed/validated on signup**. Treat the invite flow below as "token issued and tracked", not "token gates entry".

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: no pre-existing onboarding state required; this journey creates invites and drives the member wizard. To re-run cleanly, reset the wizard meta in Cleanup first.
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it). Admin creds: `admin` / `password`.
- Member users: `member1` / `password`, `member2` / `password`
- `wp` commands run inside LocalWP's Site Shell (no `wp-env` prefix).
- The bulk-invite email needs the `bn.bulk_invite` email template enabled in `wp_bn_email_templates` to actually send mail (verify at Mailpit, http://localhost:10010/). Token creation works regardless.

Resolve the member IDs up front:

```bash
wp user get member1 --field=ID   # → MEMBER1_ID
wp user get member2 --field=ID   # → MEMBER2_ID
```

## Happy-path steps

### Part 1: Owner sets the registration mode (setup wizard / settings)

1. The owner's registration mode is the `buddynext_reg_mode` option. Set it to invite-only the way the setup wizard step 2 does:

   ```bash
   wp option update buddynext_reg_mode invite
   wp option get buddynext_reg_mode
   ```

   - Expected: `invite`. This is the value `SetupWizard::save_settings()` writes on step 2, and the value **Settings → Registration** reads/writes (`includes/Admin/Settings.php:650`).

2. (Optional) Walk the admin setup wizard UI to confirm the surface renders. As admin, open:

   ```
   http://buddynext-dev.local/wp-admin/admin.php?page=buddynext-setup&autologin=1
   ```

   - Expected: the 8-step wizard (Branding → Registration → Profile Fields → Notifications → Spaces → Pages → Addons → Done). Step 2 shows the three reg-mode radios (Open / Invite only / Admin approval) and an "email verification" switch. Reaching step 8 and clicking **Finish setup** sets `buddynext_setup_complete=1` and fires `buddynext_setup_complete`.

3. Confirm setup-wizard completion state (if you finished it):

   ```bash
   wp option get buddynext_setup_complete
   wp option get buddynext_setup_step
   ```

   - Expected: `1` once finished; `buddynext_setup_step` clamps between 1 and 8 (`SetupWizard::TOTAL_STEPS`).

### Part 2: Owner creates an invite token

4. As admin, create a single invite via the service (mirrors the per-row path the Members → Invites CSV upload uses):

   ```bash
   wp eval '$id = (new \BuddyNext\Onboarding\InviteService())->create("invitee@example.test", "Pat"); echo $id;'
   ```

   - Expected: a positive integer invite ID (`INVITE_ID`). A row is inserted into `wp_bn_invites` with `status = pending`, a 64-char hex `token`, and `expires_at` = now + 7 days (`DEFAULT_TTL_DAYS`). If the `bn.bulk_invite` template is enabled, an invite email is dispatched (check Mailpit).

5. Read back the token and expiry:

   ```sql
   SELECT id, email, first_name, token, status, expires_at
   FROM wp_bn_invites
   WHERE email = 'invitee@example.test';
   ```

   - Expected: 1 row, `status = pending`, `token` is 64 hex chars, `expires_at` ~7 days out. The emailed link is `wp_registration_url()` + `?bn_invite={token}`.

6. (Bulk path) Exercise the admin REST CSV import. Build a tiny CSV and POST it as admin:

   ```bash
   printf 'email,first_name\ncsvuser1@example.test,Sam\ncsvuser2@example.test,Lee\n' > /tmp/bn-invites.csv

   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/invites/import-csv \
     -u admin:password \
     -F "csv_file=@/tmp/bn-invites.csv;type=text/csv"
   ```

   - Expected: 200 with `{ "imported": 2, "skipped": 0, "errors": [] }`. Two more `pending` rows appear in `wp_bn_invites`. The header row is skipped; invalid-email rows are counted as `skipped`.

### Part 3: Invitee redeems the invite link (signup)

7. Open the invite link in a logged-out browser (or copy `INVITE_TOKEN` from step 5):

   ```
   http://buddynext-dev.local/wp-login.php?action=register&bn_invite=INVITE_TOKEN
   ```

   Then complete signup through the BuddyNext REST register endpoint (what the signup form posts to):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/auth/register \
     -H "Content-Type: application/json" \
     -d '{
       "email": "invitee@example.test",
       "user_login": "invitee_pat",
       "password": "password123",
       "terms_agreed": true
     }'
   ```

   - Expected: 200 with `{ "success": true, "user_id": N, "redirect_to": "…/onboarding…" }`. The new user is auto-signed-in, a verification token is issued (`VerificationService::create_token`), and `redirect_to` is the onboarding wizard URL (or the verify page if `buddynext_email_verify` is on).
   - **NOTE (gap):** registration succeeds based on WP core `users_can_register`, **not** on `buddynext_reg_mode`, and the `bn_invite` token is **not consumed** here. The invite row stays `pending` (see Known limitations). To simulate the intended redemption, mark it manually:

   ```bash
   wp eval '(new \BuddyNext\Onboarding\InviteService())->mark_registered(INVITE_ID);'
   ```

   ```sql
   SELECT status FROM wp_bn_invites WHERE id = INVITE_ID;  -- expect: registered
   ```

### Part 4: Member completes the onboarding wizard

8. The new member (or `member2` for a clean run) is dropped into the 4-step wizard. Check their starting state:

   ```bash
   wp user meta get member2 bn_onboarding_step      # empty → treated as step 1
   wp user meta get member2 bn_onboarding_complete  # empty → not complete
   ```

   - Expected: both empty. `OnboardingService::get_step()` returns 1 when unset.

9. Save step 1 (Profile — display name + bio) and advance:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/me/onboarding/step \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"step": 1, "data": {"display_name": "Journey Member Two", "bio": "Here for the journey test."}}'
   ```

   - Expected: 200 with `{ "saved": true, "next_step": 2 }`. `display_name` routes through `wp_update_user`; bio is mirrored to `bn_bio` usermeta. `bn_onboarding_step` is now `2`.

10. Finish the wizard with the authoritative completion transaction (persists every step server-side, joins spaces, follows people, saves channels, marks complete):

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/me/onboarding/complete \
      -u member2:password \
      -H "Content-Type: application/json" \
      -d '{
        "display_name": "Journey Member Two",
        "bio": "Here for the journey test.",
        "slug": "journey-member-two",
        "channels": {"email": true, "in_app": true, "push": false},
        "spaces": [],
        "user_ids": [MEMBER1_ID]
      }'
    ```

    - Expected: 200 with `{ "completed": true, "redirect_to": "…/activity…" }`. `bn_onboarding_complete` is set to `1`, `buddynext_onboarding_completed` fires (first call only), the chosen slug is saved to `bn_profile_slug` (if available), channel prefs land in `bn_channel_prefs`, and `member2` now follows `member1` (row in `wp_bn_follows`). The completion hook cancels the pending nudge cron events.

11. Verify completion + side-effects:

    ```bash
    wp user meta get member2 bn_onboarding_complete   # → 1
    wp user meta get member2 bn_profile_slug          # → journey-member-two
    wp user meta get member2 bn_channel_prefs --format=json
    ```

    ```sql
    SELECT follower_id, following_id FROM wp_bn_follows
    WHERE follower_id = MEMBER2_ID AND following_id = MEMBER1_ID;
    ```

    - Expected: `bn_onboarding_complete = 1`; `bn_profile_slug = journey-member-two`; a follow row member2 → member1.

12. Confirm idempotency — re-POST complete:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/me/onboarding/complete \
      -u member2:password -H "Content-Type: application/json" -d '{}'
    ```

    - Expected: 200, `completed: true`, redirect to the activity feed, and **no** second `buddynext_onboarding_completed` fire (guarded by `is_complete()` in both the controller and `finish()`).

### Part 5: Skip path

13. For a fresh user, the wizard can be skipped outright:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/me/onboarding/skip \
      -u member1:password -H "Content-Type: application/json"
    ```

    - Expected: 200 `{ "skipped": true }`. `bn_onboarding_complete = 1` for member1. Note: `skip()` calls `mark_complete()` **without** firing `buddynext_onboarding_completed`, so the nudge cron is **not** cancelled by skip (only `complete` cancels nudges) — see Known limitations.

## Edge cases to also verify

- **Expired invite returns null**: create an already-expired invite and confirm `get_by_token()` rejects it.

  ```bash
  wp eval '$id = (new \BuddyNext\Onboarding\InviteService())->create("expired@example.test", "Ex", -1); echo $id;'
  ```

  ```sql
  SELECT token FROM wp_bn_invites WHERE email = 'expired@example.test';  -- copy TOKEN
  ```

  ```bash
  wp eval 'var_dump((new \BuddyNext\Onboarding\InviteService())->get_by_token("EXPIRED_TOKEN"));'
  ```

  - Expected: `NULL`. `get_by_token()` filters on `status = 'pending' AND expires_at > now`, so a `ttl_days = -1` (past) invite is never returned.

- **Used (registered) invite returns null**: after `mark_registered(INVITE_ID)` (step 7), re-query the token.

  ```bash
  wp eval 'var_dump((new \BuddyNext\Onboarding\InviteService())->get_by_token("INVITE_TOKEN"));'
  ```

  - Expected: `NULL` — a `registered` invite no longer matches the `status = 'pending'` clause, so the same link cannot be reused.

- **Invite-only mode does NOT block open signup (current behaviour / gap)**: with `buddynext_reg_mode = invite` (Part 1) but WP `users_can_register = 1`, attempt a signup with **no** invite token:

  ```bash
  wp option update users_can_register 1
  curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/auth/register \
    -H "Content-Type: application/json" \
    -d '{"email":"walkin@example.test","user_login":"walkin_user","password":"password123","terms_agreed":true}'
  ```

  - **Documented current behaviour:** 200 / account created. The REST register path checks only `users_can_register`, never `buddynext_reg_mode` or any invite token. The *intended* outcome (403 in invite mode without a valid token) is **not implemented** — flagged under Known limitations. To actually block walk-ins today, the owner must set `users_can_register = 0`.

- **Bulk CSV: malformed rows are skipped, not fatal**: import a CSV with a bad email row.

  ```bash
  printf 'email,first_name\nnot-an-email,Bad\ngood@example.test,Good\n' > /tmp/bn-bad.csv
  curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/invites/import-csv \
    -u admin:password -F "csv_file=@/tmp/bn-bad.csv;type=text/csv"
  ```

  - Expected: 200 `{ "imported": 1, "skipped": 1, "errors": [] }`. The invalid email is counted in `skipped`; the valid row creates one `pending` invite.

- **CSV import requires admin**: repeat the import as `member1`.

  ```bash
  curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/invites/import-csv \
    -u member1:password -F "csv_file=@/tmp/bn-invites.csv;type=text/csv"
  ```

  - Expected: 403 `rest_forbidden` — `require_admin()` enforces `manage_options`.

## What this validates

- `InviteService::create()` inserts a `pending` row into `bn_invites` with a 64-char hex token + 7-day expiry and dispatches the `bn.bulk_invite` email.
- `InviteService::get_by_token()` returns only non-expired, `pending` invites (used + expired → `null`).
- `InviteService::mark_registered()` / `mark_bounced()` flip the `status` enum.
- `InviteService::import_from_csv()` skips the header, counts bad-email rows as `skipped`, and creates one invite per valid row.
- `InviteController::import_csv()` enforces `manage_options`, MIME-checks the upload, and returns `{ imported, skipped, errors }`.
- `OnboardingService::save_step()` persists step-1 profile data and advances `bn_onboarding_step`.
- `OnboardingController::complete()` is the durable transaction: saves profile/slug/channels, joins spaces, follows users, then calls `finish()`.
- `OnboardingService::finish()` sets `bn_onboarding_complete=1` and fires `buddynext_onboarding_completed` **once**; `skip()` marks complete **without** the hook.
- `OnboardingListener` schedules `bn_onboarding_nudge_24h` / `_72h` at `user_register` and clears them on `buddynext_onboarding_completed`.
- `SetupWizard::save_settings()` writes only whitelisted options (`site_name`, `brand_color`, `reg_mode`, `email_verify`); `finish()` fires `buddynext_setup_complete`.
- `buddynext_reg_mode` is read by `Settings` and `SocialLogin` (gates social signup to `open` only).

## Verification queries

```sql
-- All invites created in this journey:
SELECT id, email, first_name, status, expires_at, created_at
FROM wp_bn_invites
WHERE email LIKE '%@example.test'
ORDER BY created_at DESC;

-- Pending vs registered vs bounced counts:
SELECT status, COUNT(*) AS n FROM wp_bn_invites GROUP BY status;

-- Member wizard state (resolve user IDs first):
SELECT user_id, meta_key, meta_value
FROM wp_usermeta
WHERE meta_key IN ('bn_onboarding_step','bn_onboarding_complete','bn_profile_slug','bn_channel_prefs','bn_bio')
  AND user_id IN (MEMBER1_ID, MEMBER2_ID);

-- Follow side-effect from wizard complete:
SELECT follower_id, following_id FROM wp_bn_follows
WHERE follower_id = MEMBER2_ID;

-- Owner-side config:
SELECT option_name, option_value FROM wp_options
WHERE option_name IN
  ('buddynext_reg_mode','buddynext_email_verify','buddynext_setup_complete','buddynext_setup_step');
```

```bash
# Confirm nudge cron was scheduled at registration and cleared on complete:
wp cron event list | grep bn_onboarding_nudge
```

## REST surface walked

```
POST /buddynext/v1/auth/register            -- 200 { success, user_id, redirect_to }; gated by users_can_register (NOT reg_mode)
POST /buddynext/v1/me/onboarding/step        -- 200 { saved, next_step }; auth required
POST /buddynext/v1/me/onboarding/complete    -- 200 { completed, redirect_to }; idempotent
POST /buddynext/v1/me/onboarding/skip         -- 200 { skipped }; auth required
POST /buddynext/v1/invites/import-csv         -- 200 { imported, skipped, errors }; manage_options; multipart csv_file
```

Admin (non-REST) surfaces:

```
/wp-admin/admin.php?page=buddynext-setup                        -- 8-step setup wizard (admin_post_buddynext_wizard_step)
/wp-admin/admin.php?page=buddynext-members&tab=invites          -- Members → Invites (admin_post_bn_bulk_invite / bn_resend_invite)
/wp-admin/admin.php?page=buddynext settings → Registration      -- buddynext_reg_mode / buddynext_email_verify
```

## Cleanup

```sql
-- Remove test invites:
DELETE FROM wp_bn_invites WHERE email LIKE '%@example.test';

-- Remove the follow created by wizard complete:
DELETE FROM wp_bn_follows WHERE follower_id = MEMBER2_ID AND following_id = MEMBER1_ID;
```

```bash
# Reset member wizard state so the journey can be re-run:
wp user meta delete member1 bn_onboarding_complete
wp user meta delete member2 bn_onboarding_step
wp user meta delete member2 bn_onboarding_complete
wp user meta delete member2 bn_profile_slug
wp user meta delete member2 bn_channel_prefs
wp user meta delete member2 bn_bio

# Restore default registration posture:
wp option update buddynext_reg_mode open

# Remove test users created during signup:
wp user delete invitee_pat walkin_user csvuser1@example.test csvuser2@example.test --yes --reassign=1 2>/dev/null

# Clear any straggler nudge cron events (optional):
wp cron event delete bn_onboarding_nudge_24h 2>/dev/null
wp cron event delete bn_onboarding_nudge_72h 2>/dev/null

# Reset setup wizard (only if you completed it and want to re-walk):
wp option delete buddynext_setup_complete
wp option delete buddynext_setup_step
```

## Known limitations

- **`buddynext_reg_mode` is stored but not enforced on the REST signup path.** `AuthController::register()` gates only on WP core `users_can_register`; it never reads `buddynext_reg_mode`. So `invite` / `approval` modes do not block or branch REST registration. Only `SocialLogin` honours the option (rejects social signup unless mode is `open`). To actually close registration today, set `users_can_register = 0`.
- **Invite token is generated + emailed but never redeemed.** `InviteService::get_by_token()` and `mark_registered()` exist and are unit-tested, but **no caller** in the registration flow consumes the `bn_invite` query arg, validates the token, pre-fills the email, or flips the invite to `registered`. The redemption loop is incomplete — invites remain `pending` after the invitee signs up. The steps above mark the invite manually to simulate the intended behaviour.
- **No single-invite create UI / REST route.** Invites can only be created in bulk (CSV upload via `InviteManager` admin form or `POST /invites/import-csv`). There is no "invite one email" form field or per-email REST endpoint; the journey uses `wp eval` to create a single invite.
- **No revoke endpoint.** Settings copy mentions "create, resend, and revoke", but `InviteManager` implements only bulk-create and resend. Revoke is a manual `DELETE` on `bn_invites`.
- **`skip()` does not cancel nudge cron.** Only `buddynext_onboarding_completed` (fired by `finish()`, not `skip()`) clears the 24h/72h nudge events. A member who skips the wizard can still receive nudge emails — though the nudge handler re-checks `is_complete()` and bails, so no email actually sends. The scheduled cron rows simply linger.
- **Setup wizard and member wizard share the same completion hook name in code comments but not in fires.** `SetupWizard::finish()` fires `buddynext_setup_complete` (the class docblock incorrectly says `buddynext_onboarding_completed`); the member wizard fires `buddynext_onboarding_completed`. They are distinct.

## Automation notes

- All onboarding + invite REST calls are curl-automatable with basic auth; the CSV import needs multipart (`-F "csv_file=@...;type=text/csv"`).
- The setup wizard is an `admin-post.php` form flow (nonce `buddynext_wizard_step` + `wizard_action`), not REST — script it with a cookie jar + nonce scrape, or just assert option writes via `wp option get` after a manual walk.
- Invite IDs and tokens must be read back from `bn_invites` after create (via SQL or the `wp eval` return value) — do not hardcode.
- The member-wizard journey is sequential and stateful per user: reset `bn_onboarding_*` usermeta (see Cleanup) before re-running, or use a throwaway user per pass.
- To assert the nudge-cron lifecycle without waiting, schedule, then immediately run `wp cron event list | grep bn_onboarding_nudge` before and after the `complete` call.
- The `bn.bulk_invite` and `bn.onboarding_nudge` email sends land in Mailpit (http://localhost:10010/) when the matching template row is enabled — use it to confirm dispatch.
