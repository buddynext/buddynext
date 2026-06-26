# Journey: Auth Verification

**Free feature**: `includes/Auth/` (VerificationService, VerificationListener), `includes/Onboarding/` (OnboardingService, InviteService)
**Actions / filters fired**: `buddynext_send_verification_email`, `buddynext_email_verified`, `buddynext_user_verified`, `buddynext_registration_pending` (approval/verify flows), `buddynext_onboarding_completed`. (There is no `buddynext_member_registered` action — registration is not written to `bn_activity_log`.)
**DB tables touched**: `bn_verify_tokens`, `bn_email_log`
**Estimated time**: 8 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- WordPress email delivery configured (Mailpit, WP Mail SMTP, or use WP-CLI to inspect the token directly)
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: New user registers

1. Register a new user directly via WP-CLI (simulates WordPress registration, which fires hooks BuddyNext listens to):

   ```bash
   wp user create testverify testverify@example.com \
     --user_pass=password \
     --first_name=Test \
     --last_name=Verify \
     --role=subscriber
   wp user get testverify --field=ID
   ```

   Note the user ID (referred to as `TESTVERIFY_ID`).

2. Confirm the user row exists (registration itself is not logged to `bn_activity_log`, and there is no `buddynext_member_registered` action — the WordPress core `user_register` action is the signal BuddyNext listens to):

   ```bash
   wp user get TESTVERIFY_ID --field=user_login
   ```

   - Expected: `testverify`. (Approval/verify flows additionally fire `buddynext_registration_pending`; the open flow does not.)

### Part 2: `VerificationService::create_token` generates a token and emails it

3. Manually trigger token creation via WP-CLI (simulating what VerificationListener does on `user_register`):

   ```bash
   wp eval "
   \$svc = buddynext_service('verification');
   \$token = \$svc->create_token(TESTVERIFY_ID);
   echo 'Token: ' . \$token . PHP_EOL;
   "
   ```

   Note the token value (referred to as `TOKEN`).

4. Verify the token row in `bn_verify_tokens`:

   ```sql
   SELECT id, user_id, token, type, expires_at, created_at
   FROM wp_bn_verify_tokens
   WHERE user_id = TESTVERIFY_ID AND type = 'email_verify'
   ORDER BY created_at DESC
   LIMIT 1;
   ```

   - Expected: 1 row. `token` matches `TOKEN`. `expires_at` is approximately 2 days from now (create time + 2 days).

5. Confirm an email was queued (check `bn_email_log` for `type = email_verify`):

   ```sql
   SELECT id, user_id, type, sent_at
   FROM wp_bn_email_log
   WHERE user_id = TESTVERIFY_ID AND type = 'email_verify'
   ORDER BY sent_at DESC
   LIMIT 1;
   ```

   - Expected: 1 row (if EmailSender is called by VerificationListener synchronously on token creation).

   If no row yet, check the email template exists:

   ```sql
   SELECT id, type, subject, enabled
   FROM wp_bn_email_templates
   WHERE type = 'email_verify';
   ```

   - Expected: 1 row, `enabled = 1`.

### Part 3: User clicks verify link

6. Simulate the user clicking the verification link by calling `VerificationService::verify()` via WP-CLI:

   ```bash
   wp eval "
   \$svc = buddynext_service('verification');
   \$result = \$svc->verify('TOKEN_VALUE_HERE');
   var_dump(\$result);
   "
   ```

   Replace `TOKEN_VALUE_HERE` with the actual token from Step 3.

   - Expected: `$result` is `true` (or a WP_User object, depending on implementation). No `WP_Error` returned.

7. Verify the usermeta `buddynext_email_verified` is set to `1`:

   ```bash
   wp user meta get TESTVERIFY_ID buddynext_email_verified
   ```

   - Expected: `1`.

8. Verify the token row has been consumed (deleted or expired):

   ```sql
   SELECT id, token, expires_at
   FROM wp_bn_verify_tokens
   WHERE user_id = TESTVERIFY_ID AND token = 'TOKEN_VALUE_HERE';
   ```

   - Expected: 0 rows (token deleted after successful verification) OR `expires_at` set to a past date (if soft-expiry is used).

9. Confirm `buddynext_user_verified` fired (it is not written to `bn_activity_log`; attach a transient listener and call `verify()` in the same eval, or observe a downstream side-effect such as the welcome email via `RegistrationEmailListener` / the `buddynext_user_verified` webhook):

   ```bash
   wp eval "
   add_action('buddynext_user_verified', function(\$uid){ echo 'user_verified fired for ' . \$uid . PHP_EOL; });
   buddynext_service('verification')->verify('FRESH_TOKEN_HERE');
   "
   ```

   - Expected: `user_verified fired for <id>`. (`buddynext_email_verified` is fired by `VerificationListener`'s `bn_verify` click handler, not by a direct `verify()` call.)

### Part 4: Resend verification flow

10. Trigger a resend via WP-CLI (simulating what a "Resend verification email" button would do):

    ```bash
    wp eval "
    \$svc = buddynext_service('verification');
    \$token = \$svc->create_token(TESTVERIFY_ID);
    echo 'New token: ' . \$token . PHP_EOL;
    "
    ```

    - Expected: a new token row is added. (The production "Resend" button calls `VerificationService::resend()`, which first guards on `is_verified()` — returning a `WP_Error` for an already-verified user or whenever the `buddynext_email_verify` feature is off — then deletes any pending token for the user and calls `create_token()`. This step calls `create_token()` directly so it works regardless of verified state; to exercise `resend()` itself, use an unverified user with the verify feature enabled.)

11. Verify only the most recent token is valid (previous token should be invalidated or a new row exists):

    ```sql
    SELECT id, user_id, token, type, expires_at, created_at
    FROM wp_bn_verify_tokens
    WHERE user_id = TESTVERIFY_ID AND type = 'email_verify'
    ORDER BY created_at DESC;
    ```

    - Expected: the newest row is the active token. Older rows are either absent or have past `expires_at`.

### Part 5: Verify an already-verified user (idempotency)

12. Verify the same token twice (already consumed in Step 6):

    ```bash
    wp eval "
    \$svc = buddynext_service('verification');
    \$result = \$svc->verify('TOKEN_VALUE_HERE');
    var_dump(\$result);
    "
    ```

    - Expected: `false` or `WP_Error` — the token no longer exists / is expired.

13. Confirm `buddynext_email_verified` usermeta remains `1` (verification state is preserved):

    ```bash
    wp user meta get TESTVERIFY_ID buddynext_email_verified
    ```

    - Expected: still `1`.

## Edge cases to also verify

- **Expired token**: Manually update `expires_at` to a past datetime for a token, then attempt to verify. Expected: `WP_Error` — token expired.
- **Token not found**: Attempt to verify a token string that does not exist in `bn_verify_tokens`. Expected: `WP_Error`.
- **UNIQUE token constraint**: Confirm `bn_verify_tokens.token` has a UNIQUE KEY — two tokens with the same value cannot exist.
- **Email template placeholders**: Check the `email_verify` template body contains `{{verify_url}}` and `{{user_name}}` placeholders and that `EmailSender` correctly substitutes them.
- **Unverified user attempt to post**: Confirm whether BuddyNext gates posting behind email verification. If `buddynext_can('post')` checks `buddynext_email_verified`, attempt to create a post as `testverify` before verifying. Expected: 403 if gate is active.

## What this validates

- `VerificationService::create_token(int $user_id)` inserts into `bn_verify_tokens` with a 64-char token, `type = email_verify` (hard-coded, single-arg signature), and `expires_at = create time + 48 hours`, then fires `buddynext_send_verification_email(int $user_id, string $token_url)` — the second arg is the full verify URL (`home_url('/?bn_verify={token}')`), not the raw token.
- `VerificationListener` *listens* to `buddynext_send_verification_email` to dispatch the email, and handles the click-through `bn_verify` query param (calls `verify()`, then fires `buddynext_email_verified(int $user_id)`).
- `VerificationService::verify()` looks up the token in `bn_verify_tokens`, validates it is not expired, sets usermeta `buddynext_email_verified = 1`, deletes the token row, and fires `buddynext_user_verified(int $user_id)` (the `buddynext_email_verified` action is fired by the listener's click handler, not by `verify()` itself).
- `bn_verify_tokens` UNIQUE KEY on `token` prevents collisions.
- `bn_email_log` records the dispatch of the `email_verify` template.

## Verification queries

```sql
-- All verify tokens for the test user:
SELECT id, user_id, token, type, expires_at, created_at
FROM wp_bn_verify_tokens
WHERE user_id = TESTVERIFY_ID;

-- Email log for the test user:
SELECT id, user_id, type, sent_at
FROM wp_bn_email_log
WHERE user_id = TESTVERIFY_ID
ORDER BY sent_at DESC;

-- Usermeta verification flag:
SELECT meta_key, meta_value
FROM wp_usermeta
WHERE user_id = TESTVERIFY_ID AND meta_key = 'buddynext_email_verified';
```

## REST surface walked

Email verification itself completes server-side (the email link hits a WP page that calls `VerificationService::verify($token)`), but the rest of the auth surface IS REST and must be walked — these are the highest-consequence routes in the whole product (a regression here locks members out). All confirmed present in the **live** index on 2026-06-20:

```
POST /buddynext/v1/auth/register             -- create account; 200/201
POST /buddynext/v1/auth/login                -- credential login; 200 (+2FA challenge) / 401
GET  /buddynext/v1/auth/nonce                -- fresh REST nonce; every authed JS call depends on this
POST /buddynext/v1/auth/verify/resend        -- resend verification email; 200
GET  /buddynext/v1/auth/verify/status        -- pending|verified; 200
POST /buddynext/v1/auth/lost-password        -- request reset email; 200
POST /buddynext/v1/auth/reset-password       -- commit new password (key+login); 200
POST /buddynext/v1/auth/2fa                   -- submit 2FA challenge at login; 200/401
POST /buddynext/v1/auth/2fa/email-code        -- send email OTP for login 2FA; 200
POST /buddynext/v1/auth/change-email          -- account email change (auth); 200
POST /buddynext/v1/auth/change-password       -- account password change (auth); 200
POST /buddynext/v1/auth/sign-out-everywhere   -- invalidate other sessions; 200
POST /buddynext/v1/auth/approve/{id}          -- admin approves a pending member; 200
GET    /buddynext/v1/account/2fa              -- 2FA enrollment status; 200
POST   /buddynext/v1/account/2fa/setup        -- begin TOTP enrollment; 200 (secret/QR)
POST   /buddynext/v1/account/2fa/confirm       -- confirm enrollment with a code; 200
POST   /buddynext/v1/account/2fa/disable        -- disable 2FA; 200
POST   /buddynext/v1/account/2fa/backup          -- regenerate backup codes; 200
GET    /buddynext/v1/me/data-export             -- GDPR self-export (gated by Privacy setting); 200/403
DELETE /buddynext/v1/me/account                 -- self-delete account (gated by Privacy setting); 200/403
```

> Re-confirm this list against the live index every run (do NOT trust the manifest or grep — this journey previously claimed "no REST endpoint" while all of the above were live):
> `curl -s http://buddynext.local/wp-json/buddynext/v1 | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes']) if any(k in r for k in ('auth','2fa','account','data-export'))]"`

## Frontend action wiring

*(Item 11. Auth is the surface most users hit first — login, signup, "forgot password" — so these are the highest-traffic controls in the product. Every store sends the captured nonce as `restNonce`/`ctx.restNonce`.)*

| Control | Template (file) | JS store / action | Live route + method | Nonce source |
|---|---|---|---|---|
| Login submit | `templates/auth/login.php`, `blocks/login-form.php` | `assets/js/auth/login-store.js` | `POST /auth/login` | `c.restNonce` |
| Signup submit | `templates/auth/signup.php` | `assets/js/auth/signup-store.js:204` | `POST /auth/register` | `c.restNonce` |
| Forgot-password (request) | `templates/auth/reset.php` (no `?key`) | `assets/js/auth/reset-store.js:82` | `POST /auth/lost-password` | `c.restNonce` |
| Set new password (commit) | `templates/auth/reset.php` (`?key=&login=`) | `reset-store.js:112` | `POST /auth/reset-password` | `c.restNonce` |
| Resend verification | `templates/auth/verify.php` | `auth/verify-store.js:46` | `POST /auth/verify/resend` | `c.restNonce` |
| 2FA setup / confirm / disable / backup | `templates/parts/settings-account-fields.php` (profile edit) | `profile/store.js:2046/2072` etc. | `POST /account/2fa/setup` · `/confirm` · `/disable` · `/backup` | `ctx.restNonce` |
| Change email / password | `templates/parts/settings-account-fields.php` | `profile/store.js:1901/1959` | `POST /auth/change-email` · `/auth/change-password` | `ctx.restNonce` |
| Sign out everywhere | `templates/parts/settings-account-fields.php` | `profile/store.js:2019` | `POST /auth/sign-out-everywhere` | `ctx.restNonce` |
| Export my data / delete account | `templates/parts/settings-account-fields.php` | `profile/store.js:1011/1049` | `GET /me/data-export` · `DELETE /me/account` | `ctx.restNonce` |

**How to verify this run (the lockout-risk paths first):**
1. Live login — `curl -s -X POST http://buddynext.local/wp-json/buddynext/v1/auth/login -H 'Content-Type: application/json' -d '{"user":"alice","password":"password"}'` → expect 200 (or a 2FA challenge). **The field is `user`** — `username`/`user_login` returns `rest_missing_callback_param` (400). A 401 means the route works but the password is wrong (set one: `wp user update alice --user_pass=password`). Only 404/500 is a real failure.
2. Nonce endpoint alive — `curl -s http://buddynext.local/wp-json/buddynext/v1/auth/nonce` → returns a nonce string (every authed button depends on it).
3. Lost-password reachable anonymously — `curl -s -o /dev/null -w '%{http_code}' -X POST .../auth/lost-password -d '{"user_login":"member1"}'` → 200.
4. Template emits the form + nonce — `curl -s http://buddynext.local/login/ | grep -c 'auth-login\|restNonce'`.

## Admin-config → member-effect

*(Item 12. Flip the real setting, re-check the member effect, restore.)*

- **Registration mode** (`buddynext_reg_mode` → mirrors core `users_can_register` via `Settings::sync_core_registration`): set to **invite-only** in admin, then as a logged-out visitor load `/signup/` and confirm open registration is blocked (signup gated / invite required). This is also enforced at the REST layer (1.0.3): `POST /auth/register` without a valid `invite` returns **403 `rest_invite_required`**; a valid `invite` token is consumed via `mark_registered()` on success. Set back to **open** and confirm signup works. This is the single most common owner config and a frequent support theme.
- **GDPR Privacy gates** (`buddynext_allow_data_export`, `buddynext_allow_account_deletion`): turn **OFF** in admin, then as `member1` call `GET /me/data-export` and `DELETE /me/account` → expect **403**; turn ON → expect 200. Legal exposure if the gate is wrong, so verify both directions.
- **Manual approval** (if reg mode requires approval): register a member, confirm they cannot log in until `POST /auth/approve/{id}` runs, then confirm login succeeds.

Restore every option you changed (`wp option delete <key>` → default).

## Cleanup

```sql
-- Remove test user's verify tokens:
DELETE FROM wp_bn_verify_tokens WHERE user_id = TESTVERIFY_ID;

-- Remove email log entries:
DELETE FROM wp_bn_email_log WHERE user_id = TESTVERIFY_ID;
```

```bash
# Remove the test user:
wp user delete TESTVERIFY_ID --yes
```

## Known limitations

- The **token-consuming** verify step is server-side (the email link hits a WP page that calls `VerificationService::verify($token)`), not a REST route — but `GET /auth/verify/status` and `POST /auth/verify/resend` ARE live REST routes (see REST surface). The earlier "no REST endpoint for verification" note was misleading: it conflated the click-through with the whole auth surface, which is fully REST. Confirm the click-through URL with `VerificationListener` before automating it.
- `buddynext_send_verification_email` fires with `(int $user_id, string $token_url)` — the second arg is the already-built verify URL (`home_url('/?bn_verify={token}')`), not the bare token. `VerificationListener::send_verification_email()` consumes it directly to build the email link; assert on the `?bn_verify=` URL in Mailpit.
- Registration mode IS now enforced on REST registration (1.0.3): with reg-mode set to invite-only, `POST /auth/register` without a valid invite returns **403 `rest_invite_required`**. A valid invite is passed via the `invite` param and is consumed (marked used via `mark_registered()`) on successful registration. The earlier note that reg-mode was UI-only no longer applies.

## Automation notes

- Token creation and verification are fully WP-CLI automatable via `wp eval`.
- For browser-level automation (clicking the email link), use a Mailpit API call to fetch the email, extract the `verify_url` from the body, and then navigate to it via Playwright.
- The resend flow should be tested with a fresh token (not the consumed one from the initial verify step).
