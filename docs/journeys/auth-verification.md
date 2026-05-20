# Journey: Auth Verification

**Free feature**: `includes/Auth/` (VerificationService, VerificationListener), `includes/Onboarding/` (OnboardingService, InviteService)
**Actions / filters fired**: `buddynext_send_verification_email`, `buddynext_email_verified`, `buddynext_user_verified`, `buddynext_member_registered`, `buddynext_onboarding_completed`
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

2. Confirm `buddynext_member_registered` fired by checking the activity log:

   ```sql
   SELECT id, user_id, action, created_at
   FROM wp_bn_activity_log
   WHERE user_id = TESTVERIFY_ID AND action = 'register'
   ORDER BY created_at DESC
   LIMIT 1;
   ```

   - Expected: 1 row. (If activity log is not used for registration events, skip this and proceed to Step 3.)

### Part 2: `VerificationService::create_token` generates a token and emails it

3. Manually trigger token creation via WP-CLI (simulating what VerificationListener does on `user_register`):

   ```bash
   wp eval "
   \$svc = buddynext_service('verification');
   \$token = \$svc->create_token(TESTVERIFY_ID, 'email_verify');
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

   - Expected: 1 row. `token` matches `TOKEN`. `expires_at` is approximately 24 hours from now.

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

7. Verify the usermeta `bn_email_verified` is set to `1`:

   ```bash
   wp user meta get TESTVERIFY_ID bn_email_verified
   ```

   - Expected: `1`.

8. Verify the token row has been consumed (deleted or expired):

   ```sql
   SELECT id, token, expires_at
   FROM wp_bn_verify_tokens
   WHERE user_id = TESTVERIFY_ID AND token = 'TOKEN_VALUE_HERE';
   ```

   - Expected: 0 rows (token deleted after successful verification) OR `expires_at` set to a past date (if soft-expiry is used).

9. Confirm `buddynext_user_verified` and `buddynext_email_verified` actions fired. Check the activity log:

   ```sql
   SELECT id, user_id, action, created_at
   FROM wp_bn_activity_log
   WHERE user_id = TESTVERIFY_ID
   ORDER BY created_at DESC
   LIMIT 5;
   ```

   - Expected: entry for `email_verified` or `user_verified` action.

### Part 4: Resend verification flow

10. Trigger a resend via WP-CLI (simulating what a "Resend verification email" button would do):

    ```bash
    wp eval "
    \$svc = buddynext_service('verification');
    \$token = \$svc->create_token(TESTVERIFY_ID, 'email_verify');
    echo 'New token: ' . \$token . PHP_EOL;
    "
    ```

    - Expected: a new token is generated. The old token (if still in the table) is replaced or a new row is added.

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

13. Confirm `bn_email_verified` usermeta remains `1` (verification state is preserved):

    ```bash
    wp user meta get TESTVERIFY_ID bn_email_verified
    ```

    - Expected: still `1`.

## Edge cases to also verify

- **Expired token**: Manually update `expires_at` to a past datetime for a token, then attempt to verify. Expected: `WP_Error` — token expired.
- **Token not found**: Attempt to verify a token string that does not exist in `bn_verify_tokens`. Expected: `WP_Error`.
- **UNIQUE token constraint**: Confirm `bn_verify_tokens.token` has a UNIQUE KEY — two tokens with the same value cannot exist.
- **Email template placeholders**: Check the `email_verify` template body contains `{{verify_url}}` and `{{user_name}}` placeholders and that `EmailSender` correctly substitutes them.
- **Unverified user attempt to post**: Confirm whether BuddyNext gates posting behind email verification. If `buddynext_can('post')` checks `bn_email_verified`, attempt to create a post as `testverify` before verifying. Expected: 403 if gate is active.

## What this validates

- `VerificationService::create_token()` inserts into `bn_verify_tokens` with a 64-char token, `type = email_verify`, and `expires_at = +24h`.
- `VerificationListener` hooks an appropriate WordPress action (likely `user_register`) and fires `buddynext_send_verification_email(int $user_id, string $token)`.
- `VerificationService::verify()` looks up the token in `bn_verify_tokens`, validates it is not expired, sets usermeta `bn_email_verified = 1`, deletes the token row, and fires `buddynext_user_verified(int $user_id)` and `buddynext_email_verified(int $user_id)`.
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
WHERE user_id = TESTVERIFY_ID AND meta_key = 'bn_email_verified';
```

## REST surface walked

```
-- No dedicated REST endpoint for verification in the manifest.
-- Verification is triggered server-side via VerificationService::verify($token).
-- The verify link in the email points to a WordPress page that calls verify() server-side.
-- TODO: confirm the exact URL pattern (e.g. ?bn_verify=TOKEN or a shortcode page).
```

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

- The email verification REST endpoint is not exposed in the manifest (`audit/manifest.json`). Verification may be handled via a WordPress page URL with a query string token, a shortcode, or a direct action in the Onboarding flow. Confirm with `VerificationListener` and `SetupWizard` implementation before automating the click-through step.
- `buddynext_send_verification_email` fires with `(int $user_id, string $token)` — confirmed in manifest. The token passed may or may not be the raw token (could be URL-encoded or HMAC-wrapped). Inspect `VerificationListener` for the exact value before asserting it in Mailpit.

## Automation notes

- Token creation and verification are fully WP-CLI automatable via `wp eval`.
- For browser-level automation (clicking the email link), use a Mailpit API call to fetch the email, extract the `verify_url` from the body, and then navigate to it via Playwright.
- The resend flow should be tested with a fresh token (not the consumed one from the initial verify step).
